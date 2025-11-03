# woa_tool/predict.py

import os
import json
import numpy as np
from typing import Dict, List, Optional, Tuple

from .feature_extraction import extract_image_features
from .abnormality import infer_abnormality


# -----------------------
# Utilities
# -----------------------
def _resolve_tau(model_path: str, cfg: dict, tau_override: Optional[float]) -> float:
    """Determine τ with sensible precedence."""
    # 1) explicit arg
    if tau_override is not None:
        return float(tau_override)
    # 2) environment variable
    if "TAU_OVERRIDE" in os.environ:
        try:
            return float(os.environ["TAU_OVERRIDE"])
        except Exception:
            pass
    # 3) sidecar file "<model>.tau"
    sidecar = model_path + ".tau"
    if os.path.isfile(sidecar):
        try:
            with open(sidecar, "r") as f:
                return float(f.read().strip())
        except Exception:
            pass
    # 4) model JSON
    try:
        return float(cfg.get("tau", 1.0))
    except Exception:
        return 1.0


def _mahalanobis_sq(v: np.ndarray, Sp_inv: np.ndarray) -> float:
    # v^T Σ^{-1} v (stable einsum)
    return float(np.einsum("i,ij,j->", v, Sp_inv, v))


def _standardize_and_select(
    x_full: np.ndarray,
    train_mu: np.ndarray,
    train_sigma: np.ndarray,
    selected_idx: np.ndarray,
) -> Tuple[np.ndarray, np.ndarray]:
    """Return (z_full, z_selected) where z = (x - mu) / sigma."""
    z_full = (x_full - train_mu) / (train_sigma + 1e-6)
    z_sel = z_full[selected_idx]
    return z_full, z_sel


# -----------------------
# Main prediction
# -----------------------
def predict(model_path: str, image_path: str, tau_override: Optional[float] = None) -> Dict:
    """
    Predict class and infer abnormality for a new mammogram image using the
    Mahalanobis ratio classifier trained in train_and_eval.py.

    Decision rule:
        malignant (1) if d_M <= τ * d_B  else benign (0)
    where distances are Mahalanobis in the standardized, selected feature space.
    """
    # --- Load model ---
    with open(model_path, "r") as f:
        cfg = json.load(f)

    feature_names: List[str] = cfg["feature_names"]
    selected_idx: List[int] = cfg.get("selected_idx", list(range(len(feature_names))))
    selected_names: List[str] = cfg.get("selected_names", [feature_names[i] for i in selected_idx])

    # training normalization stats (global)
    train_mu = np.array(cfg["train_mu"], dtype=float)
    train_sigma = np.array(cfg["train_sigma"], dtype=float)

    # class stats in standardized space (selected features)
    mu_B = np.array(cfg["class_stats"]["0"]["mu"], dtype=float)
    mu_M = np.array(cfg["class_stats"]["1"]["mu"], dtype=float)

    # pooled inverse covariance in standardized space
    Sp_inv = np.array(cfg["Sp_inv"], dtype=float)

    # resolve τ automatically (arg → env → sidecar → JSON → default)
    tau = _resolve_tau(model_path, cfg, tau_override)

    # --- Validate image path ---
    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"❌ Image not found: {image_path}")

    # --- Extract features, standardize, and select the same subset used in training ---
    feats_raw = extract_image_features(image_path)
    x_full = np.array([feats_raw.get(f, 0.0) for f in feature_names], dtype=float)
    z_full, z_sel = _standardize_and_select(
        x_full=x_full,
        train_mu=train_mu,
        train_sigma=train_sigma,
        selected_idx=np.array(selected_idx, dtype=int),
    )

    # --- Distances in selected standardized space ---
    vB = z_sel - mu_B
    vM = z_sel - mu_M
    dB = float(np.sqrt(max(_mahalanobis_sq(vB, Sp_inv), 0.0)))
    dM = float(np.sqrt(max(_mahalanobis_sq(vM, Sp_inv), 0.0)))
    ratio = (dM + 1e-9) / (dB + 1e-9)

    # --- Hard decision (identical to training/eval) ---
    pred_class = 1 if (dM <= tau * dB) else 0
    pred_label = "Malignant" if pred_class == 1 else "Benign"

    # Soft-ish probabilities from inverse distances (UI convenience only)
    invB = 1.0 / (dB + 1e-6)
    invM = 1.0 / (dM + 1e-6)
    Z = invB + invM
    probs = {"Benign": float(invB / Z), "Malignant": float(invM / Z)}

    # --- z-scores for abnormality inference (full vector, not just selected) ---
    zscores_dict = {name: float(z_full[i]) for i, name in enumerate(feature_names)}

    # --- Infer abnormality and background tissue ---
    abn_label, abn_scores, abn_expl, background = infer_abnormality(zscores_dict)

    # --- Per-feature contribution (heuristic) ---
    mu_ref = mu_M if pred_class == 1 else mu_B
    contrib_raw = np.abs(z_sel - mu_ref)
    contrib_norm = contrib_raw / (np.sum(contrib_raw) + 1e-9)
    top_features = sorted(
        ((selected_names[j], float(contrib_norm[j])) for j in range(len(selected_names))),
        key=lambda kv: kv[1],
        reverse=True
    )[:5]

    # --- Structured lesion subtype (if parseable from abn_label) ---
    result: Dict = {
        "final_prediction": pred_label,
        "probabilities": probs,
        "distance_to_benign": dB,
        "distance_to_malignant": dM,
        "tau": float(tau),
        "ratio_decision": f"Malignant if dM <= {tau:.3f} * dB else Benign",
        "abnormality_type": abn_label,
        "abnormality_scores": abn_scores if isinstance(abn_scores, dict) else {},
        "background_tissue": background,
        "explanation": {
            "class": [f"Mahalanobis ratio: dM <= {tau:.3f} * dB → {pred_label}"],
            "abnormality_summary": str(abn_expl),
        },
        "zscores": zscores_dict,
        "top_feature_contributors": top_features,
    }

    if "Mass" in abn_label or "Calcifications" in abn_label:
        lesion_subtype = {"category": None, "details": {}}
        if "Mass" in abn_label:
            lesion_subtype["category"] = "Mass"
        elif "Calcifications" in abn_label:
            lesion_subtype["category"] = "Calcifications"
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
