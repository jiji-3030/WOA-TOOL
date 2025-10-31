import os, time, json, argparse, numpy as np
from typing import Any, Dict, Tuple, List, Optional
from woa_tool.feature_extraction import extract_image_features
from woa_tool.roi_segment import segment_and_crop

# ---------- basic helpers ----------
def _safe_cholesky(A: np.ndarray, jitter: float = 1e-9, max_tries: int = 6) -> np.ndarray:
    A = np.asarray(A, dtype=float)
    I = np.eye(A.shape[0], dtype=float)
    for k in range(max_tries):
        try:
            return np.linalg.cholesky(A + (jitter * (10**k)) * I)
        except np.linalg.LinAlgError:
            continue
    A_sym = 0.5 * (A + A.T)
    return np.linalg.cholesky(A_sym + (jitter * (10**(max_tries))) * I)

def zscore_normalize(x: np.ndarray, mu: np.ndarray, sigma: np.ndarray) -> np.ndarray:
    return (x - mu) / (sigma + 1e-6)

def maha_distance(x: np.ndarray, mu: np.ndarray, Sp_inv: np.ndarray) -> float:
    v = x - mu
    return float(np.sqrt(np.einsum("i,ij,j->", v, Sp_inv, v)))

def _arr(cfg: Dict[str, Any], primary: str, *alts: str, required: bool = True) -> np.ndarray:
    val = None
    if primary in cfg:
        val = cfg[primary]
    else:
        for k in alts:
            if k in cfg:
                val = cfg[k]
                break
    if val is None:
        if required:
            raise KeyError(f"Missing required key(s): {([primary] + list(alts))}")
        return None
    return np.array(val, dtype=float)

def _get_class_stats(cs: Dict[str, Any], key: str, *alts: str) -> Dict[str, Any]:
    if key in cs:
        return cs[key]
    for k in alts:
        if k in cs:
            return cs[k]
    for k in (key, *alts):
        try:
            nk = int(k)
            if nk in cs:
                return cs[nk]
        except Exception:
            pass
    label_map = {"0": ("Benign", "benign", "B", "b"),
                 "1": ("Malignant", "malignant", "M", "m")}
    for name in label_map.get(key, ()):
        if name in cs:
            return cs[name]
    raise KeyError(f"class_stats missing compatible key for {key!r}; have {list(cs.keys())}")

def load_model(model_path: str) -> Dict[str, Any]:
    if not os.path.exists(model_path):
        raise FileNotFoundError(f"Model not found: {model_path}")
    with open(model_path, "r") as f:
        cfg = json.load(f)
    feature_names: List[str] = cfg["feature_names"]
    selected_idx: List[int] = cfg.get("selected_idx", list(range(len(feature_names))))
    selected_names: List[str] = cfg.get("selected_names", [feature_names[i] for i in selected_idx])
    mu_train = _arr(cfg, "train_mu", "global_mu")
    sg_train = _arr(cfg, "train_sigma", "global_sigma")
    cs = cfg["class_stats"]
    cs0 = _get_class_stats(cs, "0", "Benign")
    cs1 = _get_class_stats(cs, "1", "Malignant")
    mu_B = np.array(cs0["mu"], dtype=float)
    mu_M = np.array(cs1["mu"], dtype=float)
    if "Sp_inv" in cfg:
        Sp_inv = np.array(cfg["Sp_inv"], dtype=float)
    else:
        sig_B = np.array(cs0.get("sigma", np.ones_like(mu_B)), dtype=float)
        sig_M = np.array(cs1.get("sigma", np.ones_like(mu_M)), dtype=float)
        Sp = np.diag((sig_B ** 2 + sig_M ** 2) / 2.0)
        Sp_inv = np.linalg.pinv(Sp)
    tau = float(cfg.get("tau", 1.0))
    return {
        "feature_names": feature_names,
        "selected_idx": np.array(selected_idx, dtype=int),
        "selected_names": selected_names,
        "train_mu": mu_train,
        "train_sigma": sg_train,
        "mu_B": mu_B,
        "mu_M": mu_M,
        "Sp_inv": Sp_inv,
        "tau": tau,
    }

# ---------- label utils ----------
def _parse_label(val: str) -> int:
    s = str(val).strip().lower()
    if s in {"1", "m", "malignant"}: return 1
    if s in {"0", "b", "benign"}:    return 0
    raise ValueError(f"Unrecognized label: {val!r} (expected Benign/Malignant, B/M, or 0/1)")

def _lookup_label_from_csv(image_path: str, csv_path: str,
                           image_col: str = "image_path",
                           label_col: str = "Class") -> Optional[int]:
    import pandas as pd
    if not os.path.exists(csv_path):
        raise FileNotFoundError(f"CSV not found: {csv_path}")
    df = pd.read_csv(csv_path)
    m = df[df[image_col] == image_path]
    if len(m) == 0:
        base = os.path.basename(image_path)
        m = df[df[image_col].apply(lambda p: os.path.basename(str(p)) == base)]
    if len(m) == 0:
        return None
    try:
        return _parse_label(m.iloc[0][label_col])
    except Exception:
        return None

def _outcome(pred: int, truth: Optional[int]) -> Tuple[Optional[bool], str]:
    if truth is None: return None, "N/A"
    if pred == truth:
        return True, "TP" if pred == 1 else "TN"
    else:
        return False, "FP" if pred == 1 else "FN"

# ---------- core prediction (with full contributions for printing) ----------
def predict_single(image_path: str, model: Dict[str, Any],
                   tau_override: float | None = None,
                   truth_label: Optional[int] = None) -> Dict[str, Any]:
    t0 = time.time()

    crop_path, _ = segment_and_crop(image_path)
    feats = extract_image_features(crop_path)

    fnames = model["feature_names"]
    sel = model["selected_idx"]
    x_full = np.array([feats.get(n, 0.0) for n in fnames], dtype=np.float64)

    xz = zscore_normalize(x_full, model["train_mu"], model["train_sigma"])
    x = xz[sel]

    mu_B, mu_M = model["mu_B"], model["mu_M"]
    Sp_inv = model["Sp_inv"]
    tau = float(model["tau"])

    if tau_override is None:
        env_tau = os.getenv("TAU_OVERRIDE", None)
        if env_tau is not None:
            try: tau_override = float(env_tau)
            except Exception: tau_override = None
    if tau_override is not None:
        tau = float(tau_override)

    d_B = maha_distance(x, mu_B, Sp_inv)
    d_M = maha_distance(x, mu_M, Sp_inv)
    ratio = float((d_M + 1e-9) / (d_B + 1e-9))
    pred_cls = 1 if ratio <= tau else 0
    pred_label = "Malignant" if pred_cls == 1 else "Benign"

    # exact per-feature energy contributions (v^T A v = sum v_i * (A v)_i)
    vB = x - mu_B; vM = x - mu_M
    AvB = Sp_inv @ vB; AvM = Sp_inv @ vM
    eB = vB * AvB
    eM = vM * AvM
    delta_e = eM - eB  # negative => towards malignant; positive => towards benign

    names_sel = model["selected_names"]
    # For safety if selected_names length mismatches
    if len(names_sel) != len(delta_e):
        # fallback to building from feature_names + selected_idx
        names_sel = [model["feature_names"][i] for i in model["selected_idx"]]

    # counts & name splits
    mal_mask = delta_e < 0
    ben_mask = delta_e > 0
    names_mal = [names_sel[i] for i in np.where(mal_mask)[0]]
    names_ben = [names_sel[i] for i in np.where(ben_mask)[0]]

    # top malignant contributors (most negative deltas)
    order_mal = np.argsort(delta_e)  # ascending: most negative first
    top_mal = [(names_sel[i], float(delta_e[i])) for i in order_mal[:10]]

    correct, outcome = _outcome(pred_cls, truth_label)
    exec_time = time.time() - t0
    return {
        "pred_label": pred_label,
        "pred_cls": pred_cls,
        "tau": float(tau),
        "ratio": float(ratio),
        "dB": float(d_B),
        "dM": float(d_M),
        "correct": correct,
        "outcome": outcome,
        "exec_time": exec_time,
        "feature_names_all": fnames,
        "selected_names": names_sel,
        "delta_e": delta_e,            # full vector (selected features)
        "names_mal": names_mal,
        "names_ben": names_ben,
        "top_mal": top_mal,
    }

# ---------- pretty printer (exactly your requested format) ----------
def _print_requested_block(title: str, res: Dict[str, Any]):
    # Totals: “features detected” = count of selected features we evaluated
    total_detected = len(res["selected_names"])
    total_towards_mal = int((res["delta_e"] < 0).sum())
    total_towards_ben = int((res["delta_e"] > 0).sum())

    print(f"{title}:")
    print(f"total number of features detected: {total_detected}")
    print(f"total number of \"towards malignant\": {total_towards_mal}")
    print(f"total number of \"towards benign\": {total_towards_ben}")
    print(f"name of malignant features: {', '.join(res['names_mal']) if res['names_mal'] else '(none)'}")
    print(f"name of benign features: {', '.join(res['names_ben']) if res['names_ben'] else '(none)'}")
    print(f"name of all detected features: {', '.join(res['feature_names_all']) if res['feature_names_all'] else '(none)'}")
    print(f"Exec time: {res['exec_time']:.4f}s")
    print("top features contributing to malignant prediction:")
    if res["top_mal"]:
        for name, val in res["top_mal"]:
            print(f"  - {name}: {val:.6f}")
    else:
        print("  (none)")
    print("")  # spacer

# ---------- high-level compare ----------
def compare_models(image_path: str, ewoa_model_path: Optional[str], woa_model_path: Optional[str],
                   tau_override: float | None = None,
                   truth_label: Optional[int] = None) -> None:
    feats = extract_image_features(image_path)  # extract once (kept here to ensure side effects if any)
    # We’ll re-extract inside predict_single (for simplicity), but we still
    # did it once so that file existence errors happen early.

    if woa_model_path:
        mW = load_model(woa_model_path)
        resW = predict_single(image_path, mW, tau_override=tau_override, truth_label=truth_label)
        _print_requested_block("woa", resW)

    if ewoa_model_path:
        mE = load_model(ewoa_model_path)
        resE = predict_single(image_path, mE, tau_override=tau_override, truth_label=truth_label)
        _print_requested_block("ewoa", resE)

# ---------- CLI ----------
if __name__ == "__main__":
    p = argparse.ArgumentParser(description="Compare EWOA vs WOA predictions on one image (pretty print).")
    p.add_argument("--image", required=True, help="Path to the image (TIFF/JPG/PNG)")
    p.add_argument("--ewoa", default=None, help="Path to EWOA model JSON")
    p.add_argument("--woa",  default=None, help="Path to WOA model JSON")
    p.add_argument("--tau-override", type=float, default=None,
                   help="Optional τ to apply to BOTH models (else use each model's own τ). Or set env TAU_OVERRIDE=...")
    p.add_argument("--label", default=None,
                   help="Ground-truth label for the image (Benign/Malignant, B/M, or 0/1).")
    p.add_argument("--csv", default=None, help="Optional CSV to lookup truth (e.g., data/test.csv).")
    p.add_argument("--csv-image-col", default="image_path", help="CSV column with image paths (default: image_path)")
    p.add_argument("--csv-label-col", default="Class", help="CSV column with labels (default: Class)")
    args = p.parse_args()

    # Resolve truth label (optional; only used to tag TP/TN/FP/FN internally)
    truth: Optional[int] = None
    try:
        if args.label is not None:
            truth = _parse_label(args.label)
        elif args.csv is not None:
            truth = _lookup_label_from_csv(args.image, args.csv,
                                           image_col=args.csv_image_col,
                                           label_col=args.csv_label_col)
    except Exception:
        truth = None

    if not args.woa and not args.ewoa:
        raise SystemExit("Please provide at least one model via --woa and/or --ewoa")

    compare_models(args.image, args.ewoa, args.woa, tau_override=args.tau_override, truth_label=truth)
