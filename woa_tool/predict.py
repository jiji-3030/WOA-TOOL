import os, json
import numpy as np
from typing import Dict, List
from .feature_extraction import extract_image_features
from .abnormality import infer_abnormality


def predict(model_path: str, image_path: str) -> Dict:
    """Predict class and infer abnormality for a new mammogram image."""

    # === Load model ===
    with open(model_path, "r") as f:
        cfg = json.load(f)

    feature_names: List[str] = cfg["feature_names"]
    selected_idx: List[int] = cfg.get("selected_idx", list(range(len(feature_names))))
    class_stats = {
        int(cls): (
            np.array(stats["mu"], dtype=float),
            np.array(stats["sigma"], dtype=float)
        )
        for cls, stats in cfg["class_stats"].items()
    }
    class_labels = {int(k): v for k, v in cfg["class_labels"].items()}

    gmu = np.array(cfg["global_mu"], dtype=float)
    gsg = np.array(cfg["global_sigma"], dtype=float)

    # === Validate image path ===
    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"❌ Image not found: {image_path}")

    # === Extract features ===
    feats_raw = extract_image_features(image_path)
    x_full = np.array([feats_raw.get(f, 0.0) for f in feature_names], dtype=float)
    sel = np.array(selected_idx, dtype=int) if len(selected_idx) else np.arange(len(feature_names))
    x = x_full[sel]

    # === Distance-based classifier (shared variance) ===
    sig_shared = gsg[sel] + 1e-6
    dists = {cls: np.linalg.norm((x - mu) / sig_shared) for cls, (mu, sigma) in class_stats.items()}
    inv = {class_labels[cls]: 1.0 / (d + 1e-6) for cls, d in dists.items()}
    Z = sum(inv.values()) + 1e-9
    probs = {k: float(v / Z) for k, v in inv.items()}
    final_pred = max(probs, key=probs.get)

    # === Compute z-scores ===
    zvec = (x_full - gmu) / (gsg + 1e-6)
    z = {name: float(zvec[i]) for i, name in enumerate(feature_names)}

    # === Infer abnormality and background ===
    abn_label, abn_scores, abn_expl, background = infer_abnormality(z)

    # === Structured lesion subtype parsing ===
    lesion_subtype = None
    if "Mass" in abn_label:
        lesion_subtype = {"category": "Mass", "details": {}}
        try:
            inner = abn_label.split("(")[1].split(")")[0]
            shape, margin = [s.strip() for s in inner.split(",")]
            lesion_subtype["details"]["shape"] = shape
            lesion_subtype["details"]["margin"] = margin
        except Exception:
            pass

    elif "Calcifications" in abn_label:
        lesion_subtype = {"category": "Calcifications", "details": {}}
        try:
            inner = abn_label.split("(")[1].split(")")[0]
            calc_type, distribution = [s.strip() for s in inner.split(",")]
            lesion_subtype["details"]["type"] = calc_type
            lesion_subtype["details"]["distribution"] = distribution
        except Exception:
            pass

    # === Per-feature contribution analysis ===
    pred_cls = [k for k, v in class_labels.items() if v == final_pred][0]
    mu_pred, sigma_pred = class_stats[pred_cls]

    contrib_raw = np.abs((x - mu_pred) / (sigma_pred + 1e-6))
    contrib_norm = contrib_raw / (np.sum(contrib_raw) + 1e-9)
    feature_contrib = {
        feature_names[sel[j]]: float(contrib_norm[j])
        for j in range(len(sel))
    }
    top_features = sorted(feature_contrib.items(), key=lambda kv: kv[1], reverse=True)[:5]

    # === Return full structured output ===
    result = {
        "final_prediction": final_pred,
        "probabilities": probs,
        "abnormality_type": abn_label,
        "abnormality_scores": abn_scores if isinstance(abn_scores, dict) else {},
        "background_tissue": background,
        "explanation": {
            "class": [f"Top features contributing to {final_pred} classification"],
            "abnormality_summary": str(abn_expl)
        },
        "zscores": z,
        "top_feature_contributors": top_features
    }

    if lesion_subtype:
        result["lesion_subtype"] = lesion_subtype

    return result
