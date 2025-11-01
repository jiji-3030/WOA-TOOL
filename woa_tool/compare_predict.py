# ===============================================
# woa_tool/compare_predict.py
# Plain backend output in your format + numericals for ALL detected features
# ===============================================

import os
import sys
import time
import json
import argparse
from typing import Any, Dict, List, Optional, Tuple

import numpy as np

from woa_tool.feature_extraction import extract_image_features
from woa_tool.roi_segment import segment_and_crop


# ---------------------------
# Helpers
# ---------------------------
def zscore_normalize(x: np.ndarray, mu: np.ndarray, sigma: np.ndarray) -> np.ndarray:
    return (x - mu) / (sigma + 1e-6)


def maha_distance(x: np.ndarray, mu: np.ndarray, Sp_inv: np.ndarray) -> float:
    v = x - mu
    return float(np.sqrt(np.einsum("i,ij,j->", v, Sp_inv, v)))


def _arr(cfg: Dict[str, Any], primary: str, *alts: str) -> np.ndarray:
    for k in (primary, *alts):
        if k in cfg:
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
        for name in ("Benign", "benign", "B", "b"):
            if name in cs:
                return cs[name]
    if key == "1":
        for name in ("Malignant", "malignant", "M", "m"):
            if name in cs:
                return cs[name]
    raise KeyError(f"class_stats missing key compatible with {key!r}; have {list(cs.keys())}")


def _parse_label_to_int(val: str) -> int:
    s = str(val).strip().lower()
    if s in {"1", "m", "malignant"}:
        return 1
    if s in {"0", "b", "benign"}:
        return 0
    raise ValueError(f"Unrecognized label: {val!r}")


def _label_str(y: Optional[int]) -> str:
    if y is None:
        return "N/A"
    return "Malignant" if int(y) == 1 else "Benign"


def _lookup_label_from_csv(image_path: str, csv_path: str,
                           image_col: str = "image_path",
                           label_col: str = "Class") -> Optional[int]:
    import pandas as pd
    if not os.path.exists(csv_path):
        return None
    df = pd.read_csv(csv_path)
    m = df[df[image_col] == image_path]
    if len(m) == 0:
        base = os.path.basename(image_path)
        m = df[df[image_col].apply(lambda p: os.path.basename(str(p)) == base)]
    if len(m) == 0:
        return None
    try:
        return _parse_label_to_int(m.iloc[0][label_col])
    except Exception:
        return None


# ---------------------------
# Model loading
# ---------------------------
def load_model(path: str) -> Dict[str, Any]:
    if not os.path.exists(path):
        raise FileNotFoundError(f"Model not found: {path}")
    with open(path, "r", encoding="utf-8") as f:
        cfg = json.load(f)

    feature_names: List[str] = cfg["feature_names"]
    selected_idx: List[int] = cfg.get("selected_idx", list(range(len(feature_names))))
    selected_names: List[str] = cfg.get("selected_names", [feature_names[i] for i in selected_idx])

    mu_train = _arr(cfg, "train_mu", "global_mu")
    sig_train = _arr(cfg, "train_sigma", "global_sigma")

    cs = cfg["class_stats"]
    mu_B = np.array(_get_class(cs, "0", "Benign")["mu"], dtype=float)
    mu_M = np.array(_get_class(cs, "1", "Malignant")["mu"], dtype=float)

    if "Sp_inv" in cfg:
        Sp_inv = np.array(cfg["Sp_inv"], dtype=float)
    else:
        # fallback diagonal pooled variance
        sig_B = np.array(_get_class(cs, "0", "Benign").get("sigma", np.ones_like(mu_B)), dtype=float)
        sig_M = np.array(_get_class(cs, "1", "Malignant").get("sigma", np.ones_like(mu_M)), dtype=float)
        Sp = np.diag((sig_B ** 2 + sig_M ** 2) / 2.0)
        Sp_inv = np.linalg.pinv(Sp)

    tau = float(cfg.get("tau", 1.0))

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
# Predict one model (returns block components)
# ---------------------------
def predict_block(image_path: str, model: Dict[str, Any], top_k: int = 10) -> Tuple[str, int, Dict[str, Any]]:
    t0 = time.time()

    # ROI + features
    crop_path, _ = segment_and_crop(image_path)
    feats = extract_image_features(crop_path)

    # vectorize & normalize
    fnames = model["feature_names"]
    sel = model["selected_idx"]
    sel_names = model["selected_names"]

    x_full = np.array([feats.get(n, 0.0) for n in fnames], dtype=np.float64)
    xz = zscore_normalize(x_full, model["mu_train"], model["sig_train"])
    x = xz[sel]

    # distances & prediction
    mu_B, mu_M = model["mu_B"], model["mu_M"]
    Sp_inv = model["Sp_inv"]
    tau = float(model["tau"])

    dB = maha_distance(x, mu_B, Sp_inv)
    dM = maha_distance(x, mu_M, Sp_inv)
    ratio = (dM + 1e-9) / (dB + 1e-9)

    pred_cls = 1 if ratio <= tau else 0
    pred_label = "Malignant" if pred_cls == 1 else "Benign"

    # contributions
    vB, vM = x - mu_B, x - mu_M
    AvB, AvM = Sp_inv @ vB, Sp_inv @ vM
    eB, eM = vB * AvB, vM * AvM
    delta = eM - eB  # negative => towards malignant; positive => towards benign

    # sort indices by malignant strength (most negative first)
    order_all = np.argsort(delta)  # ascending

    # sign splits (names only for the earlier lists)
    mal_mask = delta < 0
    ben_mask = delta > 0
    names_mal = [sel_names[i] for i in np.where(mal_mask)[0]]
    names_ben = [sel_names[i] for i in np.where(ben_mask)[0]]

    # top malignant contributors
    top_mal = [(sel_names[i], float(delta[i])) for i in order_all[:max(0, top_k)]]

    exec_time = time.time() - t0

    # full per-feature numericals (ALL detected = all selected)
    all_pairs = [(sel_names[i], float(delta[i])) for i in order_all]

    block = {
        "prediction": pred_label,
        "total_detected": len(sel_names),
        "towards_malignant": int(mal_mask.sum()),
        "towards_benign": int(ben_mask.sum()),
        "names_mal": names_mal,
        "names_ben": names_ben,
        "all_names": fnames,
        "exec_time": exec_time,
        "top_mal": top_mal,
        "all_pairs": all_pairs,  # NEW: numericals for all detected features
        "pred_cls": pred_cls,    # internal for correctness check
    }
    return pred_label, pred_cls, block


# ---------------------------
# Print block in requested format (+ numericals)
# ---------------------------
def print_block(title: str, block: Dict[str, Any], correct: Optional[bool]) -> None:
    # Title capitalized exactly as requested
    print(f"{title}:")
    print(f"\"Prediction\": \"{block['prediction']}\",")
    # print(f"\"Correct\": {str(correct).lower() if isinstance(correct, bool) else 'null'},")

    print("total number of features detected:")
    print(block["total_detected"])
    print("total number of \"towards malignant\":")
    print(block["towards_malignant"])
    print("total number of \"towards benign\":")
    print(block["towards_benign"])

    print("name of malignant features:")
    print(", ".join(block["names_mal"]) if block["names_mal"] else "(none)")
    print("name of benign features:")
    print(", ".join(block["names_ben"]) if block["names_ben"] else "(none)")
    print("name of all detected features:")
    print(", ".join(block["all_names"]) if block["all_names"] else "(none)")

    print("Exec time:")
    print(f"{block['exec_time']:.4f}s")

    print("top features contributing to malignant:")
    if block["top_mal"]:
        for n, v in block["top_mal"]:
            print(f"  - {n}: {v:.6f}")
    else:
        print("  (none)")

    # NEW: numericals for ALL detected/selected features
    print("")
    print("all detected features with numericals (negative=towards malignant, positive=towards benign):")
    for n, v in block["all_pairs"]:
        print(f"  - {n}: {v:.6f}")

    print("")  # spacer after each model


# ---------------------------
# Main
# ---------------------------
if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Compare EWOA vs WOA (backend plain text + numericals).")
    parser.add_argument("--image", required=True, help="Path to the image file")
    parser.add_argument("--ewoa", default=None, help="Path to EWOA model JSON")
    parser.add_argument("--woa", default=None, help="Path to WOA model JSON")
    parser.add_argument("--csv", default=None, help="Optional CSV for truth lookup (e.g., data/test.csv)")
    parser.add_argument("--csv-image-col", default="image_path")
    parser.add_argument("--csv-label-col", default="Class")
    parser.add_argument("--label", default=None, help="Optional explicit label (Benign/Malignant, B/M, 0/1)")
    parser.add_argument("--top-k", type=int, default=10, help="How many top malignant features to show")
    args = parser.parse_args()

    if not args.woa and not args.ewoa:
        print("Please provide at least one model via --woa and/or --ewoa", file=sys.stderr)
        sys.exit(2)

    # Resolve truth (if available)
    truth: Optional[int] = None
    try:
        if args.label is not None:
            truth = _parse_label_to_int(args.label)
        elif args.csv is not None:
            truth = _lookup_label_from_csv(args.image, args.csv,
                                           image_col=args.csv_image_col,
                                           label_col=args.csv_label_col)
    except Exception:
        truth = None

    # For final line:
    truth_str = _label_str(truth)

    # WOA first (per your example), then EWOA
    if args.woa:
        pred_label_woa, pred_cls_woa, block_woa = predict_block(args.image, load_model(args.woa), top_k=args.top_k)
        correct_woa = (None if truth is None else (pred_cls_woa == truth))
        print_block("Woa", block_woa, correct_woa)

    if args.ewoa:
        pred_label_ewoa, pred_cls_ewoa, block_ewoa = predict_block(args.image, load_model(args.ewoa), top_k=args.top_k)
        correct_ewoa = (None if truth is None else (pred_cls_ewoa == truth))
        print_block("Ewoa", block_ewoa, correct_ewoa)

    # Final line: Correct Classification = ground truth (if available)
    print(f"\"Correct Classification\": \"{truth_str}\"")
