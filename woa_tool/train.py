# woa_tool/train.py
from __future__ import annotations
import argparse, json, os
import numpy as np
import pandas as pd
from sklearn.model_selection import StratifiedKFold

from .feature_extraction import extract_image_features
from .algorithms import run_woa, run_ewoa

def train(data_csv, images_dir, algo="ewoa", iters=100, pop=30, out="models/model.json", folds=5):
    df = pd.read_csv(data_csv)

    X, y, image_ids = [], [], []
    feature_names = None

    # ---- load features ----
    for _, row in df.iterrows():
        image_id = row["ImageID"]
        label = str(row["Class"]).strip()
        if label not in ["B", "M"]:
            continue  # skip Normal entirely

        image_path = os.path.join(images_dir, f"{image_id}.tif")
        if not os.path.exists(image_path):
            continue

        feats = extract_image_features(image_path)
        if feature_names is None:
            feature_names = list(feats.keys())
        X.append([feats[f] for f in feature_names])
        y.append(0 if label == "B" else 1)  # 0=Benign, 1=Malignant
        image_ids.append(image_id)

    X = np.array(X, dtype=float)
    y = np.array(y, dtype=int)

    # ---- global scaling (for z-scores / abnormalities) ----
    global_mu = X.mean(axis=0)
    global_sigma = X.std(axis=0) + 1e-6

    # ---- cross-validation objective ----
    skf = StratifiedKFold(n_splits=folds, shuffle=True, random_state=42)

    def objective(mask):
        selected = [i for i, v in enumerate(mask) if v > 0.5]
        if not selected:
            return 1e6

        fold_errors = []
        fold_errors_B, fold_errors_M = [], []

        for tr, va in skf.split(X, y):
            Xtr, Xva = X[tr][:, selected], X[va][:, selected]
            ytr, yva = y[tr], y[va]

            mu_b, sig_b = Xtr[ytr == 0].mean(axis=0), Xtr[ytr == 0].std(axis=0) + 1e-6
            mu_m, sig_m = Xtr[ytr == 1].mean(axis=0), Xtr[ytr == 1].std(axis=0) + 1e-6

            errors, errors_B, errors_M = 0, 0, 0
            total_B, total_M = 0, 0

            for xi, yi in zip(Xva, yva):
                d_b = np.linalg.norm((xi - mu_b) / sig_b)
                d_m = np.linalg.norm((xi - mu_m) / sig_m)
                pred = 0 if d_b < d_m else 1
                if pred != yi:
                    errors += 1
                    if yi == 0:
                        errors_B += 1
                    else:
                        errors_M += 1
                if yi == 0:
                    total_B += 1
                else:
                    total_M += 1

            fold_errors.append(errors / len(yva))
            if total_B > 0:
                fold_errors_B.append(errors_B / total_B)
            if total_M > 0:
                fold_errors_M.append(errors_M / total_M)

        # store per-class breakdown in attributes for later inspection
        objective.last_B = np.mean(fold_errors_B) if fold_errors_B else 0.0
        objective.last_M = np.mean(fold_errors_M) if fold_errors_M else 0.0
        return np.mean(fold_errors)

    dim = X.shape[1]
    bounds = (np.min(X, axis=0), np.max(X, axis=0))

    if algo == "woa":
        best_mask, best_fit, _ = run_woa(objective, dim, bounds, pop, iters)
    else:
        best_mask, best_fit, _ = run_ewoa(objective, dim, bounds, pop, iters)

    selected_idx = [i for i, v in enumerate(best_mask) if v > 0.5]
    selected_names = [feature_names[i] for i in selected_idx]

    # ---- class stats (final centroids) ----
    class_stats = {}
    for cls in [0, 1]:
        Xc = X[y == cls][:, selected_idx]
        mu = Xc.mean(axis=0)
        sigma = Xc.std(axis=0) + 1e-6
        class_stats[cls] = {"mu": mu.tolist(), "sigma": sigma.tolist()}

    # ---- save model ----
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
    parser.add_argument("--data", required=True)
    parser.add_argument("--images", required=True)
    parser.add_argument("--algo", choices=["woa","ewoa"], default="ewoa")
    parser.add_argument("--iters", type=int, default=100)
    parser.add_argument("--pop", type=int, default=30)
    parser.add_argument("--out", default="models/model.json")
    parser.add_argument("--folds", type=int, default=5)
    args = parser.parse_args()

    train(args.data, args.images, args.algo, args.iters, args.pop, args.out, args.folds)
