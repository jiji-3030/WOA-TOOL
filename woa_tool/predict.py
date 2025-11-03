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
    """Determine τ with sensible precedence (arg -> env -> sidecar -> tau_train -> tau -> 1.0)."""
    if tau_override is not None:
        return float(tau_override)
    if "TAU_OVERRIDE" in os.environ:
        try:
            return float(os.environ["TAU_OVERRIDE"])
        except Exception:
            pass
    sidecar = model_path + ".tau"
    if os.path.isfile(sidecar):
        try:
            with open(sidecar, "r") as f:
                return float(f.read().strip())
        except Exception:
            pass
    if "tau_train" in cfg and cfg["tau_train"] is not None:
        try:
            return float(cfg["tau_train"])
        except Exception:
            pass
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


def _np_from(cfg: dict, *keys: str, required: bool = False, cast=float) -> Optional[np.ndarray]:
    """Fetch a numpy array from cfg using the first available key in keys."""
    for k in keys:
        if k in cfg and cfg[k] is not None:
            try:
                return np.array(cfg[k], dtype=cast)
            except Exception:
                pass
    if required:
        raise KeyError(f"Missing required key(s): {keys}")
    return None


def _load_centers_and_cov(cfg: dict, d_sel: int) -> Tuple[np.ndarray, np.ndarray, np.ndarray]:
    """
    Return (mu_B, mu_M, Sp_inv) in the standardized + selected space.
    Priority:
      1) class_stats with mu (+sigma) and/or Sp_inv
      2) explicit alternates (rare)
      3) identity + zero centers (warn) so prediction still runs
    """
    cs = cfg.get("class_stats")
    if isinstance(cs, dict):
        mu_B = None
        mu_M = None
        for kB in ("0", "Benign", 0):
            if kB in cs and "mu" in cs[kB]:
                mu_B = np.array(cs[kB]["mu"], dtype=float); break
        for kM in ("1", "Malignant", 1):
            if kM in cs and "mu" in cs[kM]:
                mu_M = np.array(cs[kM]["mu"], dtype=float); break

        if "Sp_inv" in cfg:
            Sp_inv = np.array(cfg["Sp_inv"], dtype=float)
        else:
            # try to build diag pooled from per-class sigma if provided
            sigB = None; sigM = None
            for kB in ("0", "Benign", 0):
                if kB in cs and "sigma" in cs[kB]:
                    sigB = np.array(cs[kB]["sigma"], dtype=float); break
            for kM in ("1", "Malignant", 1):
                if kM in cs and "sigma" in cs[kM]:
                    sigM = np.array(cs[kM]["sigma"], dtype=float); break
            if sigB is not None and sigM is not None:
                var_pooled = 0.5 * (sigB**2 + sigM**2) + 1e-6
                Sp_inv = np.diag(1.0 / var_pooled)
            else:
                Sp_inv = np.eye(d_sel, dtype=float)

        if mu_B is not None and mu_M is not None:
            return mu_B, mu_M, Sp_inv

    # explicit alternates if present
    mu_b_alt = _np_from(cfg, "mu_b_all", "mu_B")
    mu_m_alt = _np_from(cfg, "mu_m_all", "mu_M")
    Sp_inv_alt = _np_from(cfg, "S_inv_diag_all", "Sp_inv")
    if mu_b_alt is not None and mu_m_alt is not None:
        if Sp_inv_alt is None:
            Sp_inv_alt = np.eye(d_sel, dtype=float)
        return mu_b_alt, mu_m_alt, Sp_inv_alt

    # last resort
    print("⚠️  Warning: model JSON lacks class centers/covariance; "
          "using zero centers and identity covariance in selected z-space.", flush=True)
    return np.zeros(d_sel, dtype=float), np.zeros(d_sel, dtype=float), np.eye(d_sel, dtype=float)


# -----------------------
# Main prediction
# -----------------------
def predict(model_path: str, image_path: str, tau_override: Optional[float] = None) -> Dict:
    """
    Predict class and infer abnormality for a new mammogram image using the
    Mahalanobis ratio classifier.

    Decision rule:
        malignant (1) if d_M <= τ * d_B  else benign (0)

    Output format matches your original (includes tau, ratio_decision, explanation)
    PLUS the extra fields you requested from compare_predict.py.
    """
    # --- Load model ---
    with open(model_path, "r", encoding="utf-8") as f:
        cfg = json.load(f)

    feature_names: List[str] = cfg["feature_names"]
    selected_idx: List[int] = cfg.get("selected_idx", list(range(len(feature_names))))
    selected_names: List[str] = cfg.get("selected_names", [feature_names[i] for i in selected_idx])

    # training normalization stats (prefer new train.py keys)
    train_mu  = _np_from(cfg, "global_mu", "train_mu", required=True)
    train_sigma = _np_from(cfg, "global_sigma", "train_sigma", required=True)

    # centers/covariance in standardized + selected space
    d_sel = len(selected_idx)
    mu_B, mu_M, Sp_inv = _load_centers_and_cov(cfg, d_sel)

    # resolve τ automatically (arg → env → sidecar → tau_train → tau → default)
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

    # --- Per-feature contribution direction using quadratic-form deltas ---
    # delta = eM - eB; negative => towards malignant; positive => towards benign
    AvB, AvM = Sp_inv @ vB, Sp_inv @ vM
    eB, eM = vB * AvB, vM * AvM
    delta = eM - eB

    names_mal = [selected_names[i] for i in np.where(delta < 0)[0]]
    names_ben = [selected_names[i] for i in np.where(delta > 0)[0]]

    total_detected = len(selected_names)
    total_mal = int((delta < 0).sum())
    total_ben = int((delta > 0).sum())

    # --- Per-feature contribution (size-weighted, for top list) ---
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
            "class": [f"Mahalanobis ratio: dM <= {tau:.3f} * dB \u2192 {pred_label}"],
            "abnormality_summary": str(abn_expl),
        },
        "zscores": zscores_dict,
        "top_feature_contributors": top_features,

        # === Added fields (from comparison.py style) ===
        "total number of features detected": total_detected,
        "total number of \"towards malignant\"": total_mal,
        "total number of \"towards benign\"": total_ben,
        "name of malignant features": ", ".join(names_mal) if names_mal else "(none)",
        "name of benign features": ", ".join(names_ben) if names_ben else "(none)",
        "name of all detected features": ", ".join(selected_names) if selected_names else "(none)",
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
