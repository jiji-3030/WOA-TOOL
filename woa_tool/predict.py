# woa_tool/predict.py
# EWOA single-model predictor aligned to compare_predict.py logic, robust to older compare versions.

import os
import json
import numpy as np
from typing import Dict, List, Optional, Tuple, Any

from .feature_extraction import extract_image_features
from .abnormality import infer_abnormality

# ROI (used to mirror compare_predict ROI gating via ROI_INFER)
try:
    from .roi_segment import segment_and_crop
except Exception:
    segment_and_crop = None

# Import the canonical logic from compare_predict so outputs match exactly
from .compare_predict import load_model as _cp_load_model, predict_block as _cp_predict_block


# ---------------------------
# Helpers (same math as compare_predict)
# ---------------------------
def zscore_normalize(x: np.ndarray, mu: np.ndarray, sigma: np.ndarray) -> np.ndarray:
    return (x - mu) / (sigma + 1e-6)


def maha_distance(x: np.ndarray, mu: np.ndarray, Sp_inv: np.ndarray) -> float:
    v = x - mu
    return float(np.sqrt(np.einsum("i,ij,j->", v, Sp_inv, v)))


# ---------------------------
# Main prediction (delegates core logic to compare_predict)
# ---------------------------
def predict(model_path: str, image_path: str, tau_override: Optional[float] = None) -> Dict[str, Any]:
    # 1) Load model using the EXACT loader used by compare_predict
    model = _cp_load_model(model_path)

    # Allow explicit τ override (compare_predict does not; this is a CLI convenience only)
    if tau_override is not None:
        model = {**model, "tau": float(tau_override)}

    # 2) Run the EXACT same prediction block used by compare_predict
    #    Returns label, class id, and a dict with deltas/feature lists
    pred_label, pred_cls, block = _cp_predict_block(image_path, model, top_k=10)

    # 3) Recompute distances/probabilities & zscores using the SAME ROI policy as compare_predict
    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"❌ Image not found: {image_path}")

    # Mirror compare_predict ROI gating (OFF by default; enable with ROI_INFER=1 / true)
    use_roi = os.getenv("ROI_INFER", "0").strip() in {"1", "true", "True"}
    crop_path = image_path
    roi_used = False
    if use_roi and segment_and_crop is not None:
        try:
            _crop_path, _ = segment_and_crop(image_path)
            if isinstance(_crop_path, str) and os.path.exists(_crop_path):
                crop_path = _crop_path
                roi_used = True
        except Exception:
            crop_path = image_path
            roi_used = False

    feats = extract_image_features(crop_path)
    fnames: List[str] = model["feature_names"]
    sel = model["selected_idx"]

    x_full = np.array([feats.get(n, 0.0) for n in fnames], dtype=np.float64)
    xz = zscore_normalize(x_full, model["mu_train"], model["sig_train"])
    x = xz[sel]

    dB = maha_distance(x, model["mu_B"], model["Sp_inv"])
    dM = maha_distance(x, model["mu_M"], model["Sp_inv"])
    tau = float(model["tau"])

    invB, invM = 1.0 / (dB + 1e-6), 1.0 / (dM + 1e-6)
    Z = invB + invM

    # 4) Abnormality (independent of classifier)
    zscores_dict = {name: float(xz[i]) for i, name in enumerate(fnames)}
    abn_label, abn_scores, abn_expl, background = infer_abnormality(zscores_dict)

    # 5) Build JSON using fields DIRECTLY from compare_predict’s block.
    #    Use .get(...) to be compatible with older compare_predict versions that lack 'neutral' or names_neu.
    total_detected = int(block.get("total_detected", len(block.get("all_names", []))))
    towards_malignant = int(block.get("towards_malignant", 0))
    towards_benign = int(block.get("towards_benign", 0))
    neutral = int(block.get("neutral", 0))
    names_mal = block.get("names_mal", [])
    names_ben = block.get("names_ben", [])
    names_neu = block.get("names_neu", [])
    all_names = block.get("all_names", [])

    top_mal = block.get("top_mal", [])         # list[(name, delta)]
    all_pairs = block.get("all_pairs", [])     # list[(name, delta)]

    # Derive TOP BENIGN list from all_pairs (positive deltas = towards benign)
    top_ben = sorted(
        [(n, v) for (n, v) in all_pairs if v > 0.0],
        key=lambda kv: kv[1],
        reverse=True
    )[:10]
    top_ben_text = [f"  - {n}: {v:.6f}" for (n, v) in top_ben]

    result: Dict[str, Any] = {
        "final_prediction": pred_label,
        "probabilities": {"Benign": float(invB / Z), "Malignant": float(invM / Z)},
        "distance_to_benign": dB,
        "distance_to_malignant": dM,
        "tau": tau,
        "ratio_decision": f"Malignant if dM <= {tau:.3f} * dB else Benign",

        # --- 1:1 compare_predict-style counts/names ---
        "total number of features detected": total_detected,
        "total number of \"towards malignant\"": towards_malignant,
        "total number of \"towards benign\"": towards_benign,
        "total number of \"neutral\"": neutral,
        "name of malignant features": ", ".join(names_mal) if names_mal else "(none)",
        "name of benign features": ", ".join(names_ben) if names_ben else "(none)",
        "name of neutral features": ", ".join(names_neu) if names_neu else "(none)",
        "name of all detected features": ", ".join(all_names) if all_names else "(none)",

        # Pretty text blocks that match compare_predict’s printout (malignant) + our benign addition
        "top_features_text": [f"  - {n}: {v:.6f}" for (n, v) in top_mal],
        "top_benign_text": top_ben_text,
        "all_detected_text": [f"  - {n}: {v:.6f}" for (n, v) in all_pairs],

        # Structured deltas (machine-friendly)
        "top_malignant_delta": top_mal,
        "top_benign_delta": top_ben,
        "all_detected_delta": all_pairs,

        # Abnormality
        "abnormality_type": abn_label,
        "abnormality_scores": abn_scores if isinstance(abn_scores, dict) else {},
        "background_tissue": background,
        "explanation": {
            "class": [f"Mahalanobis ratio: dM <= {tau:.3f} * dB \u2192 {pred_label}"],
            "abnormality_summary": str(abn_expl),
        },
        "zscores": zscores_dict,

        # ROI debug (handy for UI / parity checks)
        "roi": {"used": bool(roi_used), "path": crop_path},
    }

    # Optional structured lesion subtype
    if "Mass" in abn_label or "Calcifications" in abn_label:
        lesion_subtype = {"category": ("Mass" if "Mass" in abn_label else "Calcifications"), "details": {}}
        try:
            inner = abn_label.split("(")[1].split(")")[0]
            parts = [p.strip() for p in inner.split(",")]
            if lesion_subtype["category"] == "Mass" and len(parts) == 2:
                lesion_subtype["details"]["shape"], lesion_subtype["details"]["margin"] = parts
            elif lesion_subtype["category"] == "Calcifications" and len(parts) == 2:
                lesion_subtype["details"]["type"], lesion_subtype["details"]["distribution"] = parts
        except Exception:
            pass
        result["lesion_subtype"] = lesion_subtype

    return result
