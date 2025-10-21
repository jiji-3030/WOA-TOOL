# woa_tool/train.py
from __future__ import annotations
import argparse, json, os
import numpy as np
from sklearn.model_selection import StratifiedKFold

from .algorithms import run_woa, run_ewoa


def train(
    processed_dir: str,
    algo: str = "ewoa",
    iters: int = 100,
    pop: int = 30,
    out: str = "models/model.json",
    folds: int = 5,
    a_strategy: str = "linear",
    obl_freq: int = 0,
    obl_rate: float = 0.0
):
    """
    Train a WOA/EWOA-based feature selection model using the preprocessed dataset.
    """

    # ---- Load preprocessed data ----
    X = np.load(os.path.join(processed_dir, "X_train.npy"))
    y = np.load(os.path.join(processed_dir, "y_train.npy"))

    with open(os.path.join(processed_dir, "feature_names.json")) as f:
        feature_names = json.load(f)

    # === Global normalization (helps feature scaling) ===
    X = (X - X.mean(axis=0)) / (X.std(axis=0) + 1e-6)
    global_mu = X.mean(axis=0)
    global_sigma = X.std(axis=0) + 1e-6

    skf = StratifiedKFold(n_splits=folds, shuffle=True, random_state=42)

    # === Define fitness function ===
    def objective(mask):
        """Evaluate a binary feature mask via stratified k-fold CV."""
        selected = [i for i, v in enumerate(mask) if v > 0.5]
        if not selected:
            return 1e6  # discourage empty feature sets

        fold_errors, fold_errors_B, fold_errors_M = [], [], []

        # --- prepare feature-group dictionary for diversity reward ---
        groups = {
            "glcm":  [i for i, n in enumerate(feature_names) if n.startswith("glcm_")],
            "hist":  [i for i, n in enumerate(feature_names) if n.startswith("hist_")],
            "shape": [i for i, n in enumerate(feature_names)
                    if n.startswith("shape_") or n.startswith("asym_")],
            "edge":  [i for i, n in enumerate(feature_names)
                    if "edge" in n or "grad" in n],
            "blob":  [i for i, n in enumerate(feature_names) if n.startswith("blob_")],
        }

        for tr, va in skf.split(X, y):
            Xtr, Xva = X[tr][:, selected], X[va][:, selected]
            ytr, yva = y[tr], y[va]

            # --- class means ---
            mu_b = Xtr[ytr == 0].mean(axis=0)
            mu_m = Xtr[ytr == 1].mean(axis=0)

            # --- shared variance for both classes (robust normalization) ---
            sig_shared = Xtr.std(axis=0) + 1e-6

            errors_B = errors_M = 0
            total_B = np.sum(yva == 0)
            total_M = np.sum(yva == 1)

            for xi, yi in zip(Xva, yva):
                d_b = np.linalg.norm((xi - mu_b) / sig_shared)
                d_m = np.linalg.norm((xi - mu_m) / sig_shared)
                pred = 0 if d_b < d_m else 1
                if pred != yi:
                    if yi == 0:
                        errors_B += 1
                    else:
                        errors_M += 1

            err_B = errors_B / (total_B + 1e-6)
            err_M = errors_M / (total_M + 1e-6)

            # --- weighted balanced error (slight benign emphasis) ---
            W_B, W_M = 1.1, 1.0
            weighted_err = (W_B * err_B + W_M * err_M) / (W_B + W_M)

            # --- subset-size regularization (target ~12 features) ---
            k = len(selected)
            k_target, alpha = 15, 0.010
            size_penalty = alpha * abs(k - k_target) / max(1, X.shape[1])
            weighted_err += size_penalty

            # --- diversity bonus (reward mixed feature domains) ---
            sel = set(selected)
            present = sum(any(i in sel for i in groups[g]) for g in groups)
            div_bonus = -0.01 * max(0, present - 2)   # reduce error slightly if ≥3 groups present
            weighted_err = max(0.0, weighted_err + div_bonus)

            fold_errors.append(weighted_err)
            fold_errors_B.append(err_B)
            fold_errors_M.append(err_M)

        # --- summary for reporting ---
        objective.last_B = float(np.mean(fold_errors_B))
        objective.last_M = float(np.mean(fold_errors_M))
        return float(np.mean(fold_errors))

    dim = X.shape[1]
    bounds = (np.min(X, axis=0), np.max(X, axis=0))

    # ---- Run selected algorithm ----
    if algo == "woa":
        best_mask, best_fit, _ = run_woa(objective, dim, bounds, pop, iters)
    else:
        best_mask, best_fit, _ = run_ewoa(
            objective, dim, bounds, pop, iters,
            a_strategy=a_strategy,
            obl_freq=obl_freq,
            obl_rate=obl_rate
        )

    selected_idx = [i for i, v in enumerate(best_mask) if v > 0.5]
    selected_names = [feature_names[i] for i in selected_idx]

    # ---- Compute per-class stats ----
    class_stats = {}
    for cls in [0, 1]:
        Xc = X[y == cls][:, selected_idx]
        mu = Xc.mean(axis=0)
        sigma = Xc.std(axis=0) + 1e-6
        class_stats[cls] = {"mu": mu.tolist(), "sigma": sigma.tolist()}

    # ---- Save model ----
    model = {
        "feature_names": feature_names,
        "selected_idx": selected_idx,
        "selected_names": selected_names,
        "algorithm": algo,
        "iters": iters,
        "pop": pop,
        "error": float(best_fit),
        "error_breakdown": {
            "Benign": float(getattr(objective, "last_B", 0.0)),
            "Malignant": float(getattr(objective, "last_M", 0.0))
        },
        "class_labels": {0: "Benign", 1: "Malignant"},
        "class_stats": class_stats,
        "global_mu": global_mu.tolist(),
        "global_sigma": global_sigma.tolist()
    }

    os.makedirs(os.path.dirname(out), exist_ok=True)
    with open(out, "w") as f:
        json.dump(model, f, indent=2)

    print(f"✅ Model saved to {out}")
    print(f"Features: {dim}, Selected: {len(selected_idx)}")
    print(f"CV Error: {best_fit:.4f} (Benign err={objective.last_B:.4f}, Malignant err={objective.last_M:.4f})")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--processed", default="data/processed")
    parser.add_argument("--algo", choices=["woa", "ewoa"], default="ewoa")
    parser.add_argument("--iters", type=int, default=100)
    parser.add_argument("--pop", type=int, default=30)
    parser.add_argument("--out", default="models/model.json")
    parser.add_argument("--folds", type=int, default=5)
    parser.add_argument("--a-strategy", default="linear")
    parser.add_argument("--obl-freq", type=int, default=0)
    parser.add_argument("--obl-rate", type=float, default=0.0)
    args = parser.parse_args()

    train(
        processed_dir=args.processed,
        algo=args.algo,
        iters=args.iters,
        pop=args.pop,
        out=args.out,
        folds=args.folds,
        a_strategy=args.a_strategy,
        obl_freq=args.obl_freq,
        obl_rate=args.obl_rate
    )
