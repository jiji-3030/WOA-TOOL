# === woa_tool/eval_existing_model.py (compat-ready) ===
import os, json, argparse
import numpy as np
import pandas as pd

from woa_tool.feature_extraction import extract_image_features

# ROI is optional; only imported when flag is used
try:
    from woa_tool.roi_segment import segment_and_crop
    _HAS_ROI = True
except Exception:
    _HAS_ROI = False

EPS = 1e-6

def zscore(x, mu, sigma):
    return (x - mu) / (sigma + EPS)

def maha_many(X, mu, Sp_inv):
    dif = X - mu
    return np.sqrt(np.einsum("ni,ij,nj->n", dif, Sp_inv, dif))

def normalize_label(v):
    if isinstance(v, str):
        s = v.strip().lower()
        if s in {"0","b","benign"}: return 0
        if s in {"1","m","malignant"}: return 1
    if v in [0,1]:
        return int(v)
    return None

def _get_class_block(cs, key):
    """Robust class block getter for '0'/'1'/0/1/'Benign'/'Malignant'."""
    if key in cs: return cs[key]
    # try common aliases
    aliases = {
        "0": ["Benign", "benign", 0, "B", "b"],
        "1": ["Malignant", "malignant", 1, "M", "m"],
    }
    for alt in aliases.get(str(key), []):
        if alt in cs: return cs[alt]
    raise KeyError(f"class_stats missing key compatible with {key!r}; have {list(cs.keys())}")

def build_fallback_Sp_inv(model):
    """
    Build a diagonal inverse pooled covariance when full Sp_inv is absent.
    Prefer per-class sigmas from class_stats; otherwise fall back to identity.
    """
    cs = model.get("class_stats")
    if not cs:
        d = len(model.get("selected_idx", [])) or len(model.get("feature_names", []))
        return np.eye(d, dtype=np.float64)

    def _get_sigma(k):
        block = _get_class_block(cs, k)
        sig = np.asarray(block.get("sigma", []), dtype=np.float64)
        if sig.size == 0:
            raise KeyError("sigma missing in class_stats")
        return sig

    try:
        sB = _get_sigma("0")
        sM = _get_sigma("1")
        pooled_var = 0.5 * (sB**2 + sM**2) + EPS
        return np.diag(1.0 / pooled_var)
    except Exception:
        d = len(model.get("selected_idx", [])) or len(model.get("feature_names", []))
        return np.eye(d, dtype=np.float64)


def pick_col(df, candidates):
    cols = {c.lower(): c for c in df.columns}
    for name in candidates:
        if name in cols: return cols[name]
    return None

def load_model(model_path):
    with open(model_path, "r", encoding="utf-8") as f:
        cfg = json.load(f)

    feature_names = cfg["feature_names"]

    # --- Selected indices (prefer standardizer.sel_idx, else selected_idx, else names) ---
    sel_idx = None
    std = cfg.get("standardizer")
    if isinstance(std, dict) and "sel_idx" in std:
        sel_idx = np.array(std["sel_idx"], dtype=int)

    if sel_idx is None:
        if "selected_idx" in cfg and cfg["selected_idx"] is not None:
            sel_idx = np.array(cfg["selected_idx"], dtype=int)
        elif cfg.get("selected_names"):
            name_to_idx = {n: i for i, n in enumerate(feature_names)}
            sel_idx = np.array([name_to_idx[n] for n in cfg["selected_names"]], dtype=int)
        else:
            sel_idx = np.arange(len(feature_names), dtype=int)

    # --- Normalization (prefer standardizer.mu_all/sig_all) ---
    if isinstance(std, dict) and "mu_all" in std and "sig_all" in std:
        mu = np.array(std["mu_all"], dtype=np.float64)
        sg = np.array(std["sig_all"], dtype=np.float64)
    elif "global_mu" in cfg and "global_sigma" in cfg:
        mu = np.array(cfg["global_mu"], dtype=np.float64)
        sg = np.array(cfg["global_sigma"], dtype=np.float64)
    elif "train_mu" in cfg and "train_sigma" in cfg:
        mu = np.array(cfg["train_mu"], dtype=np.float64)
        sg = np.array(cfg["train_sigma"], dtype=np.float64)
        print("ℹ️  Using legacy train_mu/train_sigma keys (compat mode).")
    else:
        raise KeyError("Model missing normalization stats (standardizer.mu_all/sig_all or global_/train_).")

    # --- Class means (prefer class_means_z, else class_stats[*].mu) ---
    if "class_means_z" in cfg:
        cmz = cfg["class_means_z"]
        mu_b = np.array(cmz["0"], dtype=np.float64)
        mu_m = np.array(cmz["1"], dtype=np.float64)
    else:
        cs = cfg.get("class_stats", {})
        mu_b = np.array(_get_class_block(cs, "0")["mu"], dtype=np.float64)
        mu_m = np.array(_get_class_block(cs, "1")["mu"], dtype=np.float64)

    # --- Covariance inverse (prefer full Sp_inv) ---
    if "Sp_inv" in cfg and cfg["Sp_inv"] is not None:
        Sp_inv = np.array(cfg["Sp_inv"], dtype=np.float64)
        using_fallback = False
    else:
        tmp = {"feature_names": feature_names, "selected_idx": sel_idx.tolist(), "class_stats": cfg.get("class_stats")}
        Sp_inv = build_fallback_Sp_inv(tmp)
        using_fallback = True

    # --- τ ---
    if cfg.get("tau") is not None:
        tau = float(cfg["tau"])
    elif cfg.get("tau_train") is not None:
        tau = float(cfg["tau_train"])
    else:
        tau = 1.0

    return {
        "feature_names": feature_names,
        "selected_idx": sel_idx,
        "mu": mu,
        "sg": sg,
        "mu_b": mu_b,
        "mu_m": mu_m,
        "Sp_inv": Sp_inv,
        "tau": tau,
        "using_fallback": using_fallback,
    }


def main():
    ap = argparse.ArgumentParser(description="Evaluate an existing EWOA/WOA Mahalanobis model on a CSV of images.")
    ap.add_argument("--csv", required=True, help="CSV with image paths and optional labels")
    ap.add_argument("--model", required=True, help="Path to saved model JSON")
    ap.add_argument("--out", default="", help="Optional CSV to save per-image predictions")
    ap.add_argument("--image-col", default="", help="Column name for image path (auto-detect if empty)")
    ap.add_argument("--label-col", default="", help="Column name for labels (auto-detect if empty)")
    ap.add_argument("--use-roi", action="store_true", help="Use ROI segmentation like predict()")
    args = ap.parse_args()

    model = load_model(args.model)

    feat_names = model["feature_names"]
    sel_idx    = model["selected_idx"]
    mu         = model["mu"]
    sg         = model["sg"]
    mu_b       = model["mu_b"]
    mu_m       = model["mu_m"]
    Sp_inv     = model["Sp_inv"]
    tau        = float(model["tau"])
    using_fallback = model["using_fallback"]

    df = pd.read_csv(args.csv)

    # Column detection
    path_col = args.image_col or pick_col(df, ["image_path", "path"])
    if not path_col:
        raise ValueError("CSV must include 'image_path' or 'path' column (or pass --image-col).")
    label_col = args.label_col or pick_col(df, ["class", "label"])  # optional

    # Build feature matrix (order must match model['feature_names'])
    paths = df[path_col].astype(str).tolist()
    labels = None
    if label_col:
        labels = df[label_col].apply(normalize_label).to_numpy()

    feats, ok = [], []
    for p in paths:
        try:
            img_path = p
            if args.use_roi and _HAS_ROI:
                try:
                    crop_path, _ = segment_and_crop(p)
                    if isinstance(crop_path, str) and os.path.isfile(crop_path):
                        img_path = crop_path
                except Exception:
                    pass
            d = extract_image_features(img_path)
            feats.append([d.get(f, 0.0) for f in feat_names])
            ok.append(True)
        except Exception as e:
            print(f"⚠️  Feature extraction failed for {p}: {e}")
            feats.append([0.0]*len(feat_names))
            ok.append(False)

    X  = np.array(feats, dtype=np.float64)
    Xn = zscore(X, mu, sg)
    Xs = Xn[:, sel_idx]

    # Distances & decision rule: malignant if dM <= tau * dB
    dB = maha_many(Xs, mu_b, Sp_inv)
    dM = maha_many(Xs, mu_m, Sp_inv)
    ratio = dM / (dB + EPS)
    preds = (ratio <= tau).astype(int)  # 1 = Malignant

    # Metrics if labels available
    if labels is not None and np.isfinite(labels).all():
        y = labels.astype(int)
        tn = int(((preds==0)&(y==0)).sum())
        fp = int(((preds==1)&(y==0)).sum())
        fn = int(((preds==0)&(y==1)).sum())
        tp = int(((preds==1)&(y==1)).sum())
        spec = tn / max(1, tn+fp)   # Benign accuracy
        sens = tp / max(1, tp+fn)   # Malignant accuracy
        acc  = (tp+tn) / max(1, len(y))
        bal  = 0.5*(spec+sens)

        if using_fallback:
            print("ℹ️  Using diagonal pooled covariance fallback (model had no 'Sp_inv'). "
                  "Numbers may differ from training-time calibration.")

        print("\n📊 Evaluation Results")
        print(f"Accuracy (overall)  : {acc:.3f}")
        print(f"Specificity (Benign): {spec:.3f}")
        print(f"Sensitivity (Malig.): {sens:.3f}")
        print(f"Balanced Accuracy   : {bal:.3f}")
        print("\nConfusion Matrix [rows=true, cols=pred]  (B=0, M=1)")
        print("            Pred_B  Pred_M")
        print(f"True_B:        {tn:5d}  {fp:5d}")
        print(f"True_M:        {fn:5d}  {tp:5d}")
    else:
        print("ℹ️  No usable labels found — skipping confusion matrix and metrics.")

    # Optional dump
    out = df.copy()
    out["ok"] = ok
    out["distance_to_benign"] = dB
    out["distance_to_malignant"] = dM
    out["ratio_dM_over_dB"] = ratio
    out["pred_label"] = preds
    out["pred_name"] = out["pred_label"].map({0:"Benign", 1:"Malignant"})
    if args.out:
        os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
        out.to_csv(args.out, index=False)
        print(f"💾 Saved per-image predictions to {args.out}")

if __name__ == "__main__":
    main()
