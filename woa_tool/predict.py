# woa_tool/predict.py
# EWOA single-model predictor aligned to compare_predict.py logic, with optional tau_override for CLI

import os
import json
import numpy as np
from typing import Dict, List, Optional, Tuple, Any

from .feature_extraction import extract_image_features
from .abnormality import infer_abnormality

# ROI optional (OFF by default to match training), same gate as compare_predict
try:
    from .roi_segment import segment_and_crop
except Exception:
    segment_and_crop = None


# ---------------------------
# Helpers (same math/precedence as compare_predict.py)
# ---------------------------
def zscore_normalize(x: np.ndarray, mu: np.ndarray, sigma: np.ndarray) -> np.ndarray:
    return (x - mu) / (sigma + 1e-6)


def maha_distance(x: np.ndarray, mu: np.ndarray, Sp_inv: np.ndarray) -> float:
    v = x - mu
    return float(np.sqrt(np.einsum("i,ij,j->", v, Sp_inv, v)))


def _arr(cfg: Dict[str, Any], primary: str, *alts: str) -> np.ndarray:
    for k in (primary, *alts):
        if k in cfg and cfg[k] is not None:
            return np.array(cfg[k], dtype=float)
    raise KeyError(f"Missing required key(s): {([primary] + list(alts))}")


def _get_class(cs: Dict[str, Any], key: str, *alts: str) -> Dict[str, Any]:
    if key in cs:
        return cs[key]
    for k in alts:
        if k in cs:
            return cs[k]
    # try numeric
    try:
        ikey = int(key)
        if ikey in cs:
            return cs[ikey]
    except Exception:
        pass
    # common aliases
    if key == "0":
        for name in ("Benign", "benign", "B", "b", 0):
            if name in cs:
                return cs[name]
    if key == "1":
        for name in ("Malignant", "malignant", "M", "m", 1):
            if name in cs:
                return cs[name]
    raise KeyError(f"class_stats missing key compatible with {key!r}; have {list(cs.keys())}")


# ---------------------------
# Model loading (matched to compare_predict.load_model)
# ---------------------------
def load_model(path: str) -> Dict[str, Any]:
    if not os.path.exists(path):
        raise FileNotFoundError(f"Model not found: {path}")
    with open(path, "r", encoding="utf-8") as f:
        cfg = json.load(f)

    feature_names: List[str] = cfg["feature_names"]
    selected_idx: List[int] = cfg.get("selected_idx", list(range(len(feature_names))))
    selected_names: List[str] = cfg.get("selected_names", [feature_names[i] for i in selected_idx])

    # prefer global_*, fallback to train_* (exactly like compare_predict)
    mu_train = _arr(cfg, "global_mu", "train_mu")
    sig_train = _arr(cfg, "global_sigma", "train_sigma")

    cs = cfg["class_stats"]
    mu_B = np.array(_get_class(cs, "0", "Benign")["mu"], dtype=float)
    mu_M = np.array(_get_class(cs, "1", "Malignant")["mu"], dtype=float)

    if "Sp_inv" in cfg and cfg["Sp_inv"] is not None:
        Sp_inv = np.array(cfg["Sp_inv"], dtype=float)
    else:
        # fallback diagonal pooled variance from per-class sigmas (same as compare_predict)
        sig_B = np.array(_get_class(cs, "0", "Benign").get("sigma", np.ones_like(mu_B)), dtype=float)
        sig_M = np.array(_get_class(cs, "1", "Malignant").get("sigma", np.ones_like(mu_M)), dtype=float)
        var_pooled = 0.5 * (sig_B**2 + sig_M**2) + 1e-6
        Sp_inv = np.diag(1.0 / var_pooled)

    # τ from model; if absent, fallback to tau_train; else 1.0 (compare_predict behavior)
    tau = cfg.get("tau", None)
    if tau is None and "tau_train" in cfg and cfg["tau_train"] is not None:
        tau = float(cfg["tau_train"])
    if tau is None:
        tau = 1.0
    tau = float(tau)

    return {
        "feature_names": feature_names,
        "selected_idx": np.array(selected_idx, dtype=int),
        "selected_names": selected_names,
        "mu_train": mu_train,
        "sig_train": sig_train,
        "mu_B": mu_B,
        "mu_M": mu_M,
        "Sp_inv": Sp_inv,
        "tau": tau,
    }


# ---------------------------
# Main prediction (aligned math + ROI gate)
# ---------------------------
def predict(model_path: str, image_path: str, tau_override: Optional[float] = None) -> Dict[str, Any]:
    model = load_model(model_path)

    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"❌ Image not found: {image_path}")

    # ROI optional (OFF by default to match training & compare_predict)
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

    # --- Extract features, vectorize in model order ---
    feats = extract_image_features(crop_path)
    fnames = model["feature_names"]
    sel = model["selected_idx"]
    sel_names = model["selected_names"]

    x_full = np.array([feats.get(n, 0.0) for n in fnames], dtype=np.float64)

    # --- Normalize and select (compare_predict precedence for mu/sigma keys) ---
    xz = zscore_normalize(x_full, model["mu_train"], model["sig_train"])
    x = xz[sel]

    # --- Distances & prediction (exact rule) ---
    mu_B, mu_M = model["mu_B"], model["mu_M"]
    Sp_inv = model["Sp_inv"]
    tau = float(model["tau"])
    if tau_override is not None:
        # Allow explicit override from CLI; otherwise keep compare_predict behavior
        tau = float(tau_override)

    dB = maha_distance(x, mu_B, Sp_inv)
    dM = maha_distance(x, mu_M, Sp_inv)
    ratio = (dM + 1e-9) / (dB + 1e-9)

    pred_cls = 1 if ratio <= tau else 0
    pred_label = "Malignant" if pred_cls == 1 else "Benign"

    # --- Contributions (delta = eM - eB), EXACTLY like compare_predict ---
    vB, vM = x - mu_B, x - mu_M
    AvB, AvM = Sp_inv @ vB, Sp_inv @ vM
    eB, eM = vB * AvB, vM * AvM
    delta = eM - eB

    # same neutral tolerance
    atol = 1e-12
    mal_mask = delta < -atol
    ben_mask = delta > +atol
    neu_mask = np.isfinite(delta) & ~mal_mask & ~ben_mask

    names_mal = [sel_names[i] for i in np.where(mal_mask)[0]]
    names_ben = [sel_names[i] for i in np.where(ben_mask)[0]]
    names_neu = [sel_names[i] for i in np.where(neu_mask)[0]]

    total_detected = len(sel_names)
    total_mal = int(mal_mask.sum())
    total_ben = int(ben_mask.sum())
    total_neu = int(neu_mask.sum())

    # order for numericals (most malignant first)
    order_all = np.argsort(delta)  # ascending: malignant (negative) -> benign (positive)
    all_pairs_delta = [(sel_names[i], float(delta[i])) for i in order_all]

    # top-K malignant contributors by delta (same meaning/ordering as compare_predict)
    TOP_K = 10
    top_malignant_delta = [(sel_names[i], float(delta[i])) for i in order_all[:max(0, TOP_K)]]


    # --- UI-friendly "top contributors" (kept from your JSON; does not affect decision)
    mu_ref = mu_M if pred_cls == 1 else mu_B
    contrib_raw = np.abs(x - mu_ref)
    contrib_norm = contrib_raw / (np.sum(contrib_raw) + 1e-9)
    top_features = sorted(
        ((sel_names[j], float(contrib_norm[j])) for j in range(len(sel_names))),
        key=lambda kv: kv[1],
        reverse=True
    )[:5]

    # --- z-scores for abnormality inference (full vector) ---
    zscores_dict = {name: float(xz[i]) for i, name in enumerate(fnames)}
    abn_label, abn_scores, abn_expl, background = infer_abnormality(zscores_dict)

    # --- JSON output (classification logic identical to compare_predict now) ---
    result: Dict[str, Any] = {
        "final_prediction": pred_label,

        # distance-based pseudo-probabilities (for UI visualization only)
        "probabilities": {
            "Benign": float((1.0 / (dB + 1e-6)) / (1.0 / (dB + 1e-6) + 1.0 / (dM + 1e-6))),
            "Malignant": float((1.0 / (dM + 1e-6)) / (1.0 / (dB + 1e-6) + 1.0 / (dM + 1e-6))),
        },

        "distance_to_benign": dB,
        "distance_to_malignant": dM,
        "tau": float(tau),
        "ratio_decision": f"Malignant if dM <= {tau:.3f} * dB else Benign",

        # ---------------------------------------------------------------------
        # compare_predict-style counts/names (on selected feature subset)
        # ---------------------------------------------------------------------
        "total number of features detected": total_detected,
        "total number of \"towards malignant\"": total_mal,
        "total number of \"towards benign\"": total_ben,
        "total number of \"neutral\"": total_neu,
        "name of malignant features": ", ".join(names_mal) if names_mal else "(none)",
        "name of benign features": ", ".join(names_ben) if names_ben else "(none)",
        "name of neutral features": ", ".join(names_neu) if names_neu else "(none)",
        "name of all detected features": ", ".join(sel_names) if sel_names else "(none)",

        # ---------------------------------------------------------------------
        # Abnormality and background inference (for the dashboard / report)
        # ---------------------------------------------------------------------
        "abnormality_type": abn_label,
        "abnormality_scores": abn_scores if isinstance(abn_scores, dict) else {},
        "background_tissue": background,
        "explanation": {
            "class": [f"Mahalanobis ratio: dM <= {tau:.3f} * dB \u2192 {pred_label}"],
            "abnormality_summary": str(abn_expl),
        },

        # full z-score vector for per-feature display
        "zscores": zscores_dict,

        # UI-friendly normalized top features (unchanged from before)
        "top_feature_contributors": top_features,

        # ---------------------------------------------------------------------
        # Parity + extended debugging outputs
        # ---------------------------------------------------------------------
        # delta numericals (same meaning and ordering as compare_predict)
        "top_malignant_delta": top_malignant_delta,    # list of (name, delta)
        "all_detected_delta": all_pairs_delta,         # full list sorted by delta (asc)
        # ROI info
        "roi": {"used": bool(use_roi and roi_used), "path": crop_path},
    }

    # optional structured lesion subtype (unchanged)
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
