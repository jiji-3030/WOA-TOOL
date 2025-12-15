# ==========================================================
# compare_predict_radiomics.py
# FINAL STABLE VERSION (NO CV2, FRONTEND SAFE)
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

from woa_tool.predict_radiomics import predict_radiomics


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
# Normalize predictor output for frontend
# ----------------------------------------------------------
def build_block(pred, exec_time):
    pairs = [(f["feature"], f["contribution"])
             for f in pred["top_feature_contributions"]]

    malignant = [(n, v) for n, v in pairs if v < 0]
    benign = [(n, v) for n, v in pairs if v > 0]

    return {
        "Prediction": pred["prediction"],
        "Execution Time": f"{exec_time:.4f}",
        "Total detected": str(len(pairs)),
        "Total malignant": str(len(malignant)),
        "Total benign": str(len(benign)),
        "Malignant features": ", ".join(n for n, _ in malignant),
        "Benign features": ", ".join(n for n, _ in benign),
        "All features names": ", ".join(pred["selected_features"]),
        "Top Contributors": [[n, round(v, 6)] for n, v in malignant[:10]],
        "All Detected Features": [[n, round(v, 6)] for n, v in pairs],
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
    # Generate preview (CRITICAL FIX)
    # ------------------------------------------------------
    preview_src = None

    if args.image.lower().endswith(".dcm"):
        upload_dir = os.path.dirname(args.image)                     # php/test_uploads
        preview_dir = os.path.join(upload_dir, "previews")           # php/test_uploads/previews
        preview_abs = generate_dicom_preview(args.image, preview_dir)

        # 🔑 Browser-safe relative path
        preview_src = f"test_uploads/previews/{os.path.basename(preview_abs)}"

    # ------------------------------------------------------
    # FINAL JSON (frontend-safe)
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


if __name__ == "__main__":
    main()
