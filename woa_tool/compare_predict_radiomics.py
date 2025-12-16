# ==========================================================
# compare_predict_radiomics.py
# FINAL CLEAN VERSION (FRONTEND + BATCH SAFE)
# ==========================================================

import os
import sys
import time
import json
import argparse
import subprocess
import tempfile
import numpy as np
import pandas as pd
import pydicom
from PIL import Image
import random   # ← 🔴 MISSING IMPORT (REQUIRED)

from woa_tool.predict_radiomics import predict_radiomics


run_id = int(os.environ.get("WOA_RUN_ID", 0))

random.seed(run_id)
np.random.seed(run_id)

# ----------------------------------------------------------
# Generate PNG preview from DICOM (NO OpenCV)
# ----------------------------------------------------------
def generate_dicom_preview(dcm_path, preview_dir):
    os.makedirs(preview_dir, exist_ok=True)

    ds = pydicom.dcmread(dcm_path)
    img = ds.pixel_array.astype(np.float32)

    # Normalize to 0–255
    img -= img.min()
    img /= (img.max() + 1e-6)
    img = (img * 255).astype(np.uint8)

    out_name = os.path.basename(dcm_path).replace(".dcm", ".png")
    out_path = os.path.join(preview_dir, out_name)

    Image.fromarray(img).save(out_path)
    return out_path


# ----------------------------------------------------------
# Robust ground-truth extractor (CBIS-DDSM safe)
# ----------------------------------------------------------
def extract_ground_truth(df):
    GT_COLUMNS = [
        "label", "Label",
        "class", "Class",
        "pathology", "diagnosis",
        "malignancy", "benign_malignant"
    ]

    for col in GT_COLUMNS:
        if col in df.columns:
            val = df.iloc[0][col]
            try:
                if int(val) == 0:
                    return "Benign"
                elif int(val) == 1:
                    return "Malignant"
            except Exception:
                v = str(val).lower()
                if "benign" in v:
                    return "Benign"
                if "malignant" in v:
                    return "Malignant"

    return "N/A"


# ----------------------------------------------------------
# Normalize predictor output for frontend & batch
# ----------------------------------------------------------
def build_block(pred, exec_time):
    """
    MODEL-LEVEL feature count  : number of features selected by WOA/EWOA during training
    IMAGE-LEVEL feature count  : number of WOA/EWOA-selected features whose
                                 contribution magnitude exceeds a relative threshold
                                 for this image
    """

    # --------------------------------------------------
    # FULL per-feature contributions (from predict_radiomics)
    # --------------------------------------------------
    all_contribs = pred.get("all_feature_contributions", [])

    if not all_contribs:
        # Safety fallback (should not happen if predictor is patched)
        image_level_count = 0
        pairs = []
        malignant = []
        benign = []
    else:
        # (feature, contribution) pairs
        pairs = [(f["feature"], f["contribution"]) for f in all_contribs]

        malignant = [(n, v) for n, v in pairs if v < 0]
        benign = [(n, v) for n, v in pairs if v > 0]

        # --------------------------------------------------
        # IMAGE-LEVEL SELECTION via RELATIVE THRESHOLD
        # --------------------------------------------------
        abs_vals = np.array([abs(f["contribution"]) for f in all_contribs])

        # α = 10% of strongest contributing feature (standard, defensible)
        alpha = 0.10
        threshold = alpha * abs_vals.max()

        strong_features = [
            f for f in all_contribs
            if abs(f["contribution"]) >= threshold
        ]

        image_level_count = len(strong_features)

    # --------------------------------------------------
    # MODEL-LEVEL FEATURE COUNT (training-time)
    # --------------------------------------------------
    selected_features = pred.get("selected_features", [])

    return {
        "Prediction": pred["prediction"],
        "Execution Time": f"{exec_time:.4f}",

        # MODEL-LEVEL (constant per model)
        "Selected Features (Model)": len(selected_features),

        # IMAGE-LEVEL (varies per image — THIS IS THE KEY)
        "Selected Features (Image)": image_level_count,

        "Total detected": str(len(pairs)),
        "Total malignant": str(len(malignant)),
        "Total benign": str(len(benign)),

        "Malignant features": ", ".join(n for n, _ in malignant),
        "Benign features": ", ".join(n for n, _ in benign),

        "All features names": ", ".join(selected_features),

        # UI / interpretation: still show Top-10 strongest features
        "Top Contributors": [
            [f["feature"], round(f["contribution"], 6)]
            for f in sorted(
                all_contribs,
                key=lambda x: abs(x["contribution"]),
                reverse=True
            )[:10]
        ],
    }

def compute_correct_classification(gt, pred):
    gt = str(gt).lower()
    pred = str(pred).lower()

    if gt == "malignant" and pred == "malignant":
        return "True Positive"
    if gt == "benign" and pred == "benign":
        return "True Negative"
    if gt == "benign" and pred == "malignant":
        return "False Positive"
    if gt == "malignant" and pred == "benign":
        return "False Negative"
    return "N/A"


# ----------------------------------------------------------
# MAIN
# ----------------------------------------------------------
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--image", required=True)
    parser.add_argument("--radiomics", required=True)
    parser.add_argument("--woa", required=True)
    parser.add_argument("--ewoa", required=True)
    parser.add_argument("--top-k", type=int, default=10)
    args = parser.parse_args()

    if not os.path.exists(args.image):
        print(json.dumps({"ok": False, "error": "Uploaded image not found"}))
        sys.exit(1)

    # ------------------------------------------------------
    # Match uploaded DICOM → radiomics row
    # ------------------------------------------------------
    tmp_csv = tempfile.NamedTemporaryFile(delete=False, suffix=".csv").name

    cmd = [
        sys.executable,
        "-m", "woa_tool.match_radiomics_row",
        "--uploaded", args.image,
        "--radiomics", args.radiomics,
        "--out", tmp_csv
    ]

    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        print(json.dumps({"ok": False, "error": proc.stderr}))
        sys.exit(1)

    df = pd.read_csv(tmp_csv)

    # ------------------------------------------------------
    # Ground truth
    # ------------------------------------------------------
    ground_truth = extract_ground_truth(df)

    # ------------------------------------------------------
    # Predictions
    # ------------------------------------------------------
    t0 = time.perf_counter()
    pred_woa = predict_radiomics(args.woa, tmp_csv, 0, args.top_k)
    woa_time = time.perf_counter() - t0

    t0 = time.perf_counter()
    pred_ewoa = predict_radiomics(args.ewoa, tmp_csv, 0, args.top_k)
    ewoa_time = time.perf_counter() - t0

    woa_block = build_block(pred_woa, woa_time)
    ewoa_block = build_block(pred_ewoa, ewoa_time)

    woa_block["Correct Classification"] = compute_correct_classification(
        ground_truth, woa_block["Prediction"]
    )
    ewoa_block["Correct Classification"] = compute_correct_classification(
        ground_truth, ewoa_block["Prediction"]
    )

    # ------------------------------------------------------
    # Generate preview
    # ------------------------------------------------------
    preview_src = None
    if args.image.lower().endswith(".dcm"):
        upload_dir = os.path.dirname(args.image)
        preview_dir = os.path.join(upload_dir, "previews")
        preview_abs = generate_dicom_preview(args.image, preview_dir)
        preview_src = f"test_uploads/previews/{os.path.basename(preview_abs)}"

    # ------------------------------------------------------
    # FINAL JSON
    # ------------------------------------------------------
    output = {
        "ok": True,
        "result": {
            "Ground Truth": ground_truth,
            "WOA": woa_block,
            "EWOA": ewoa_block,
            "image_src": preview_src
        },
        "image": args.image
    }

    print(json.dumps(output))
    print(f"[DEBUG] compare_predict_radiomics using run_id seed = {run_id}", file=sys.stderr)



if __name__ == "__main__":
    main()
