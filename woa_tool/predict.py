# woa_tool/predict.py
from __future__ import annotations
import os, json
import numpy as np
from typing import Dict, List, Tuple
from .feature_extraction import extract_image_features

# --- helpers ------------------------------------------------------------------

def _softmax(scores: Dict[str, float]) -> Dict[str, float]:
    vals = np.array(list(scores.values()), dtype=float)
    vals = vals - np.max(vals)   # stability
    exps = np.exp(vals)
    s = exps.sum() + 1e-9
    probs = exps / s
    return {k: float(v) for k, v in zip(scores.keys(), probs)}

# --- abnormality & tissue rules -----------------------------------------------

def infer_tissue(z: Dict[str, float], raw: Dict[str, float]) -> Tuple[str, str]:
    """Background density estimation from intensity"""
    mean = raw.get("density_index", 0.0)
    if mean < 0.30:
        return "F", f"Low mean intensity ({mean:.2f}) suggests Fatty tissue"
    elif mean < 0.50:
        return "G", f"Moderate mean intensity ({mean:.2f}) suggests Fatty-glandular tissue"
    else:
        return "D", f"High mean intensity ({mean:.2f}) suggests Dense-glandular tissue"

def infer_abnormality(z: Dict[str, float]) -> Tuple[str, Dict[str, float], List[str]]:
    """Compute abnormality scores (CALC, CIRC, SPIC, ARCH, ASYM)."""
    zbden   = z.get("blob_density", 0.0)
    zblcnt  = z.get("blob_count", 0.0)
    zsharp  = z.get("sharp_lap_var", 0.0)
    zedgeS  = z.get("edge_sobel_std", 0.0)
    zcohSd  = z.get("grad_coherence_std", 0.0)
    zcohMn  = z.get("grad_coherence_mean", 0.0)
    zring   = z.get("spic_edge_ring_ratio", 0.0)
    zdisp   = z.get("spic_orient_dispersion", 0.0)
    zcirc   = z.get("shape_circularity", 0.0)
    zasymM  = z.get("asym_absdiff_mean", 0.0)
    zasymS  = z.get("asym_absdiff_std", 0.0)
    zasymD  = z.get("asym_mean_diff", 0.0)

    # linear scoring
    calc = max(0.0, 1.5*zbden + 0.7*zblcnt + 0.8*zsharp + 0.5*zedgeS)
    spic = max(0.0, 1.2*zring + 1.0*zdisp + 0.7*zcohSd + 0.5*zedgeS)
    circ = max(0.0, 1.2*zcirc - 0.6*zring - 0.6*zcohSd - 0.5*zedgeS)
    arch = max(0.0, 0.8*zcohMn + 0.8*zcohSd + 0.4*zedgeS)
    asym = max(0.0, 1.0*zasymM + 0.8*zasymS + 0.5*abs(zasymD))

    scores = {"CALC": calc, "SPIC": spic, "CIRC": circ, "ARCH": arch, "ASYM": asym}
    probs = _softmax(scores)
    top = max(scores, key=scores.get)

    # clinical phrasing
    if top == "CALC":
        expl = ["Microcalcification clusters detected", "Sharp fine granular structures observed"]
    elif top == "SPIC":
        expl = ["Spiculated margins detected", "Radial edge irregularity"]
    elif top == "CIRC":
        expl = ["Round/compact lesion detected", "Margins appear smooth"]
    elif top == "ARCH":
        expl = ["Architectural distortion in parenchyma", "Texture coherence irregularity"]
    elif top == "ASYM":
        expl = ["Strong left-right asymmetry observed"]
    else:
        expl = ["No strong abnormality-specific signal"]

    label_map = {
        "CALC": "CALC (Calcification)",
        "CIRC": "CIRC (Circumscribed Mass)",
        "SPIC": "SPIC (Spiculated Mass)",
        "ARCH": "ARCH (Architectural Distortion)",
        "ASYM": "ASYM (Asymmetry)"
    }
    return label_map.get(top, top), probs, expl

# --- main predict API ---------------------------------------------------------

def predict(model_path: str, image_id_or_path: str) -> Dict:
    cfg = json.load(open(model_path))
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

    # resolve path
    if os.path.isfile(image_id_or_path):
        image_path = image_id_or_path
    else:
        image_path = os.path.join("data", "images", f"{image_id_or_path}.tif")

    feats_raw = extract_image_features(image_path)
    x_full = np.array([feats_raw.get(f, 0.0) for f in feature_names], dtype=float)

    # select
    sel = np.array(selected_idx, dtype=int) if len(selected_idx) else np.arange(len(feature_names))
    x = x_full[sel]

    # centroid classifier (Benign vs Malignant only)
    dists = {cls: np.linalg.norm((x - mu) / (sigma + 1e-6))
             for cls, (mu, sigma) in class_stats.items()}
    inv = {class_labels[cls]: 1.0 / (d + 1e-6) for cls, d in dists.items()}
    Z = sum(inv.values()) + 1e-9
    probs = {k: float(v / Z) for k, v in inv.items()}
    final_pred = max(probs, key=probs.get)

    # abnormality inference
    zvec = (x_full - gmu) / (gsg + 1e-6)
    z = {name: float(zvec[i]) for i, name in enumerate(feature_names)}
    abn_label, abn_probs, abn_expl = infer_abnormality(z)
    tissue_code, tissue_expl = infer_tissue(z, feats_raw)

    return {
        "final_prediction": final_pred,
        "probabilities": probs,
        "abnormality_type": abn_label,
        "abnormality_scores": abn_probs,
        "background_tissue": {
            "code": tissue_code,
            "text": {"F":"Fatty","G":"Fatty-glandular","D":"Dense-glandular"}.get(tissue_code, "?"),
            "explain": tissue_expl
        },
        "explanation": {
            "class": [f"Top features contributing to {final_pred} classification"],
            "abnormality": abn_expl
        },
        "selected_features_used": cfg.get("selected_names", [])
    }
