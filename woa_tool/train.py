# woa_tool/train.py
import os
import json
import numpy as np
from sklearn.model_selection import StratifiedKFold

from .data_radiomics import load_radiomics_data, RADIOMICS_NPY_DIR
from .algorithms import run_ewoa, run_woa


def _load_lesion_types_for_train():
    """
    OPTIONAL helper: try to recover lesion type (calc vs mass) for each TRAIN row,
    using the radiomics_train.csv from the CBIS-DDSM-R pipeline.

    If the CSV or columns are missing, we just return None and fall back to
    normal stratification on y only.
    """
    try:
        # npy_dir = .../data/CBIS-DDSM-R/npy
        root = os.path.dirname(RADIOMICS_NPY_DIR)          # .../data/CBIS-DDSM-R
        csv_dir = os.path.join(root, "csv")
        train_csv = os.path.join(csv_dir, "radiomics_train.csv")

        if not os.path.isfile(train_csv):
            print("⚠️ Could not find radiomics_train.csv, skipping lesion-type stratification.")
            return None

        import pandas as pd
        df = pd.read_csv(train_csv)

        # Try direct column first
        for col in df.columns:
            if col.strip().lower().replace(" ", "") in ("abnormalitytype", "lesiontype"):
                lesion_col = col
                break
        else:
            lesion_col = None

        if lesion_col:
            lesion_raw = df[lesion_col].astype(str).str.upper()
            lesion_type = np.where(lesion_raw.str.contains("MASS"), 1,
                           np.where(lesion_raw.str.contains("CALC"), 0, 0))
        else:
            # Fallback: infer from image_file_path (Calc- vs Mass- prefix)
            if "image_file_path" not in df.columns:
                print("⚠️ No abnormality type or image_file_path column, skipping lesion-type stratification.")
                return None
            paths = df["image_file_path"].astype(str)
            lesion_type = np.where(paths.str.contains("Mass-"), 1, 0)

        return lesion_type.astype(int)

    except Exception as e:
        print(f"⚠️ Lesion-type stratification disabled due to error: {e}")
        return None


def train(
    algo="ewoa",
    iters=500,
    pop=80,
    a_strategy="cos",
    obl_freq=5,
    obl_rate=0.15,
    out="models/model_ewoa_radiomics.json",
    folds=5,
    data_mode="radiomics",
):
    """
    Train EWOA/WOA on PyRadiomics CBIS-DDSM-R features.

    data_mode:
      - 'radiomics' → use CBIS-DDSM-R PyRadiomics NPYs (X_train, y_train, ...)
    """

    # ================================
    # LOAD DATA
    # ================================
    if data_mode != "radiomics":
        raise ValueError("This train() is currently configured ONLY for data_mode='radiomics'.")

    print("📘 Training using PyRadiomics features...")
    X_train, y_train, X_test, y_test, feature_names = load_radiomics_data()
    X, y = X_train, y_train
    dim = X.shape[1]

    # Ensure labels are 0/1 with 0=Benign, 1=Malignant
    if np.mean(y) > 0.5:
        y = 1 - y

    # Optional lesion-type-aware stratification
    lesion_type = _load_lesion_types_for_train()
    if lesion_type is not None and len(lesion_type) == len(y):
        # encode combined class = label*10 + lesion_type (0..3)
        y_strat = y.astype(int) * 10 + lesion_type.astype(int)
        print("📘 Using lesion-type-aware stratified CV (label + lesion type).")
    else:
        y_strat = y
        print("📘 Using standard stratified CV on labels only.")

    # ================================
    # NORMALIZATION (TRAIN ONLY)
    # ================================
    mu_global = X.mean(axis=0)
    sigma_global = X.std(axis=0) + 1e-6
    X_norm = (X - mu_global) / sigma_global

    skf = StratifiedKFold(n_splits=folds, shuffle=True, random_state=42)

    # ================================
    # OBJECTIVE FUNCTION
    # ================================
    def objective(mask):
        selected = [i for i, v in enumerate(mask) if v > 0.5]
        if not selected:
            return 1e6  # punish empty subsets

        fold_errors, fold_B, fold_M = [], [], []

        for tr_idx, va_idx in skf.split(X_norm, y_strat):
            Xtr, Xva = X_norm[tr_idx][:, selected], X_norm[va_idx][:, selected]
            ytr, yva = y[tr_idx], y[va_idx]

            Xb = Xtr[ytr == 0]
            Xm = Xtr[ytr == 1]

            # In case a fold is missing one class (rare but possible)
            if len(Xb) < 2 or len(Xm) < 2:
                continue

            mu_b = Xb.mean(axis=0)
            mu_m = Xm.mean(axis=0)

            Sb = np.cov(Xb.T) + 1e-6 * np.eye(len(selected))
            Sm = np.cov(Xm.T) + 1e-6 * np.eye(len(selected))
            Sp_inv = np.linalg.pinv(0.5 * (Sb + Sm))

            def maha(x, mu):
                d = x - mu
                return float(np.sqrt(d @ Sp_inv @ d))

            # Fine-grained τ search
            taus = np.linspace(0.7, 1.5, 41)  # 0.70, 0.72, ..., 1.50
            best_err = np.inf
            best_errB = None
            best_errM = None

            for T in taus:
                eB = eM = 0
                for xi, yi in zip(Xva, yva):
                    d_b = maha(xi, mu_b)
                    d_m = maha(xi, mu_m)
                    pred = 1 if (d_m / (d_b + 1e-9)) < T else 0
                    if pred != yi:
                        if yi == 0:
                            eB += 1
                        else:
                            eM += 1

                errB = eB / (np.sum(yva == 0) + 1e-6)
                errM = eM / (np.sum(yva == 1) + 1e-6)

                # Slightly prioritize malignant error, but not too aggressively
                W_B, W_M = 1.0, 1.2
                w_err = (W_B * errB + W_M * errM) / (W_B + W_M)

                if w_err < best_err:
                    best_err = w_err
                    best_errB = errB
                    best_errM = errM

            if best_err is np.inf:
                continue

            fold_errors.append(best_err)
            fold_B.append(best_errB)
            fold_M.append(best_errM)

        if not fold_errors:
            return 1e6

        objective.last_B = float(np.mean(fold_B))
        objective.last_M = float(np.mean(fold_M))
        return float(np.mean(fold_errors))

    # ================================
    # RUN OPTIMIZER (EWOA / WOA)
    # ================================
    if algo.lower() == "ewoa":
        best_mask, best_err, hist = run_ewoa(
            objective,
            dim,
            (-1, 1),
            pop_size=pop,
            iters=iters,
            a_strategy=a_strategy,
            obl_freq=obl_freq,
            obl_rate=obl_rate,
        )
    else:
        best_mask, best_err, hist = run_woa(objective, dim, (-1, 1), pop, iters)

    # ================================
    # FINAL CLASS STATS FOR PREDICTION
    # ================================
    selected_idx = [i for i, v in enumerate(best_mask) if v > 0.5]
    selected_names = [feature_names[i] for i in selected_idx]

    Xtr_sel = X_norm[:, selected_idx]
    Xb = Xtr_sel[y == 0]
    Xm = Xtr_sel[y == 1]

    mu_B = Xb.mean(axis=0)
    mu_M = Xm.mean(axis=0)
    sigma_B = Xb.std(axis=0) + 1e-6
    sigma_M = Xm.std(axis=0) + 1e-6

    class_stats = {
        "0": {"mu": mu_B.tolist(), "sigma": sigma_B.tolist()},
        "1": {"mu": mu_M.tolist(), "sigma": sigma_M.tolist()},
    }

    # ================================
    # SAVE MODEL
    # ================================
    model = {
        "algo": algo,
        "iters": iters,
        "pop": pop,
        "a_strategy": a_strategy,
        "obl_freq": obl_freq,
        "obl_rate": obl_rate,
        "selected_idx": selected_idx,
        "selected_names": selected_names,
        "cv_error": float(best_err),
        "cv_error_B": float(getattr(objective, "last_B", np.nan)),
        "cv_error_M": float(getattr(objective, "last_M", np.nan)),
        "global_mu": mu_global.tolist(),
        "global_sigma": sigma_global.tolist(),
        "feature_names": feature_names,
        "class_stats": class_stats,
        # default τ used by predict_radiomics (you can override in code or CLI later)
        "tau_default": 1.0,
    }

    os.makedirs(os.path.dirname(out), exist_ok=True)
    with open(out, "w") as f:
        json.dump(model, f, indent=2)

    print(f"✅ Model saved: {out}")
    print(f"🎯 Selected {len(selected_idx)} / {dim} features")

    return model
