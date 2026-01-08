# woa_tool/train.py
import os
import json
import numpy as np
import pandas as pd
from sklearn.model_selection import StratifiedKFold

from .data_radiomics import load_radiomics_data, RADIOMICS_NPY_DIR
from .algorithms import run_ewoa, run_woa


def _load_lesion_types_for_train():
    """
    OPTIONAL helper: recover lesion type (calc vs mass) for TRAIN rows.
    Used only for stratification; safe fallback if unavailable.
    """
    try:
        root = os.path.dirname(RADIOMICS_NPY_DIR)
        csv_dir = os.path.join(root, "csv")
        train_csv = os.path.join(csv_dir, "radiomics_train.csv")

        if not os.path.isfile(train_csv):
            print("⚠️ radiomics_train.csv not found, skipping lesion-type stratification.")
            return None

        df = pd.read_csv(train_csv)

        lesion_col = None
        for col in df.columns:
            if col.strip().lower().replace(" ", "") in ("abnormalitytype", "lesiontype"):
                lesion_col = col
                break

        if lesion_col:
            lesion_raw = df[lesion_col].astype(str).str.upper()
            lesion_type = np.where(
                lesion_raw.str.contains("MASS"), 1,
                np.where(lesion_raw.str.contains("CALC"), 0, 0)
            )
        else:
            if "image_file_path" not in df.columns:
                return None
            paths = df["image_file_path"].astype(str)
            lesion_type = np.where(paths.str.contains("Mass-"), 1, 0)

        return lesion_type.astype(int)

    except Exception as e:
        print(f"⚠️ Lesion-type stratification disabled: {e}")
        return None


def train(
    algo="ewoa",
    iters=500,
    pop=80,
    a_strategy="cos",
    obl_freq=5,
    obl_rate=0.15,
    out="models/model_ewoa_radiomics.json",
    runs=30,
    data_mode="radiomics",
):
    """
    Train WOA / EWOA on PyRadiomics CBIS-DDSM-R features.

    IMPORTANT:
    - Performs MULTIPLE INDEPENDENT RUNS
    - Logs SELECTED FEATURE INDICES + NAMES per run
    - Final model is saved from the LAST run (standard practice)
    """

    if data_mode != "radiomics":
        raise ValueError("This train() supports only data_mode='radiomics'.")

    print("📘 Loading PyRadiomics features...")
    X_train, y_train, X_test, y_test, feature_names = load_radiomics_data()
    X, y = X_train, y_train
    dim = X.shape[1]

    # Ensure labels: 0 = Benign, 1 = Malignant
    if np.mean(y) > 0.5:
        y = 1 - y

    lesion_type = _load_lesion_types_for_train()
    if lesion_type is not None and len(lesion_type) == len(y):
        y_strat = y.astype(int) * 10 + lesion_type.astype(int)
        print("📘 Using lesion-type-aware stratification.")
    else:
        y_strat = y
        print("📘 Using label-only stratification.")

    # Normalize TRAIN data
    mu_global = X.mean(axis=0)
    sigma_global = X.std(axis=0) + 1e-6
    X_norm = (X - mu_global) / sigma_global

    skf = StratifiedKFold(n_splits=3, shuffle=True, random_state=42)


    # ================================
    # OBJECTIVE FUNCTION (UNCHANGED)
    # ================================
    def objective(mask):
        selected = [i for i, v in enumerate(mask) if v > 0.5]
        if not selected:
            return 1e6

        fold_errors, fold_B, fold_M = [], [], []

        for tr_idx, va_idx in skf.split(X_norm, y_strat):
            Xtr, Xva = X_norm[tr_idx][:, selected], X_norm[va_idx][:, selected]
            ytr, yva = y[tr_idx], y[va_idx]

            Xb = Xtr[ytr == 0]
            Xm = Xtr[ytr == 1]

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

            taus = np.linspace(0.8, 1.2, 11)
            best_err = np.inf
            best_errB = best_errM = None

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

                W_B, W_M = 1.0, 1.2
                w_err = (W_B * errB + W_M * errM) / (W_B + W_M)

                if w_err < best_err:
                    best_err = w_err
                    best_errB = errB
                    best_errM = errM

            if best_err is not np.inf:
                fold_errors.append(best_err)
                fold_B.append(best_errB)
                fold_M.append(best_errM)

        if not fold_errors:
            return 1e6

        objective.last_B = float(np.mean(fold_B))
        objective.last_M = float(np.mean(fold_M))
        return float(np.mean(fold_errors))

    # ================================
    # MULTI-RUN OPTIMIZATION
    # ================================
    feature_logs = []
    last_model = None

    for run_id in range(1, runs + 1):
        print(f"🔁 Run {run_id}/{runs}")

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
                seed=run_id,
            )
        else:
            best_mask, best_err, hist = run_woa(
                objective,
                dim,
                (-1, 1),
                pop,
                iters,
                seed=run_id,
            )

        selected_idx = [i for i, v in enumerate(best_mask) if v > 0.5]
        selected_names = [feature_names[i] for i in selected_idx]

        feature_logs.append({
            "run_id": run_id,
            "algorithm": algo.upper(),
            "num_selected_features": len(selected_idx),
            "selected_feature_indices": selected_idx,
            "selected_feature_names": selected_names,
        })

        last_model = (best_mask, best_err)

    # ================================
    # SAVE FEATURE SELECTION LOGS
    # ================================
    os.makedirs("results", exist_ok=True)
    pd.DataFrame(feature_logs).to_csv(
        "results/selected_features_per_run.csv",
        index=False
    )
    print("✅ Saved results/selected_features_per_run_woa.csv")

    # ================================
    # SAVE FINAL MODEL (LAST RUN)
    # ================================
    best_mask, best_err = last_model
    selected_idx = [i for i, v in enumerate(best_mask) if v > 0.5]
    selected_names = [feature_names[i] for i in selected_idx]

    Xtr_sel = X_norm[:, selected_idx]
    Xb = Xtr_sel[y == 0]
    Xm = Xtr_sel[y == 1]

    mu_B = Xb.mean(axis=0)
    mu_M = Xm.mean(axis=0)
    sigma_B = Xb.std(axis=0) + 1e-6
    sigma_M = Xm.std(axis=0) + 1e-6

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
        "class_stats": {
            "0": {"mu": mu_B.tolist(), "sigma": sigma_B.tolist()},
            "1": {"mu": mu_M.tolist(), "sigma": sigma_M.tolist()},
        },
        "tau_default": 1.0,
    }

    os.makedirs(os.path.dirname(out), exist_ok=True)
    with open(out, "w") as f:
        json.dump(model, f, indent=2)

    print(f"✅ Final model saved: {out}")
    print(f"🎯 Selected {len(selected_idx)} / {dim} features")

    return model
