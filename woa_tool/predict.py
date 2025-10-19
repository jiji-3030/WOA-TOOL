# woa_tool/predict.py
import os, json
import numpy as np
from typing import Dict, List
from .feature_extraction import extract_image_features
from .abnormality import infer_abnormality

def predict(model_path: str, image_path: str) -> Dict:
    # === Load trained model ===
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

    # === Validate input ===
    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"❌ Image not found: {image_path}")

    # === Extract features from image ===
    feats_raw = extract_image_features(image_path)
    x_full = np.array([feats_raw.get(f, 0.0) for f in feature_names], dtype=float)

    # Select only features chosen by WOA/EWOA
    sel = np.array(selected_idx, dtype=int) if len(selected_idx) else np.arange(len(feature_names))
    x = x_full[sel]

    # === Distance-based classifier ===
    dists = {cls: np.linalg.norm((x - mu) / (sigma + 1e-6))
             for cls, (mu, sigma) in class_stats.items()}

    inv = {class_labels[cls]: 1.0 / (d + 1e-6) for cls, d in dists.items()}
    Z = sum(inv.values()) + 1e-9
    probs = {k: float(v / Z) for k, v in inv.items()}
    final_pred = max(probs, key=probs.get)

    # === Standardized feature values (z-scores) ===
    zvec = (x_full - gmu) / (gsg + 1e-6)
    z = {name: float(zvec[i]) for i, name in enumerate(feature_names)}

    # === Abnormality analysis ===
    abn_label, abn_probs, abn_expl = infer_abnormality(z)

    return {
        "final_prediction": final_pred,
        "probabilities": probs,
        "abnormality_type": abn_label,
        "abnormality_scores": abn_probs,
        "explanation": {
            "class": [f"Top features contributing to {final_pred} classification"],
            "abnormality": abn_expl
        },
        "zscores": z,
        "selected_features_used": cfg.get("selected_names", []),
        "algorithm": cfg.get("algorithm", "?"),
        "meta": {
            "iters": cfg.get("iters"),
            "pop": cfg.get("pop"),
            "cv_error": cfg.get("error"),
            "error_breakdown": cfg.get("error_breakdown", {})
        }
    }
