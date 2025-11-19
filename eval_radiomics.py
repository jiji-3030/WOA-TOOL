# eval_radiomics.py
import json
import numpy as np
from woa_tool.data_radiomics import load_radiomics_data

# ==========================
# CLASS WEIGHTS (EDIT HERE)
# ==========================
# Cost of a False Negative (missed malignant)
C_FN = 1.0

# Cost of a False Positive (benign called malignant)
C_FP = 2.0

# τ grid to test
TAU_GRID = [0.90, 0.95, 0.98, 1.00, 1.02, 1.05, 1.08, 1.10, 1.12]


def evaluate(model_path: str = "models/model_ewoa_radiomics.json"):
    print("📘 Loading radiomics dataset…")
    X_train, y_train, X_test, y_test, feature_names = load_radiomics_data()

    print("📘 Loading trained model…")
    with open(model_path) as f:
        model = json.load(f)

    selected = model["selected_idx"]
    mu_global = np.array(model["global_mu"])
    sigma_global = np.array(model["global_sigma"]) + 1e-6

    # Select features
    Xtr = X_train[:, selected]
    Xte = X_test[:, selected]

    # Normalize using TRAIN stats stored in JSON
    Xtr = (Xtr - mu_global[selected]) / (sigma_global[selected])
    Xte = (Xte - mu_global[selected]) / (sigma_global[selected])

    # Fit Mahalanobis model using TRAIN data only
    print("📘 Fitting Mahalanobis class means/covariance (TRAIN)…")
    Xb = Xtr[y_train == 0]
    Xm = Xtr[y_train == 1]

    mu_b = Xb.mean(axis=0)
    mu_m = Xm.mean(axis=0)

    Sb = np.cov(Xb.T) + 1e-6 * np.eye(len(selected))
    Sm = np.cov(Xm.T) + 1e-6 * np.eye(len(selected))
    Sp_inv = np.linalg.pinv(0.5 * (Sb + Sm))

    def maha(x, mu):
        d = x - mu
        return float(np.sqrt(d @ Sp_inv @ d))

    # ================================
    # BASELINE AT τ = 1.0 (TRAIN)
    # ================================
    print("\n📘 Baseline at τ = 1.00 on TRAIN…")
    tp = tn = fp = fn = 0
    for xi, yi in zip(Xtr, y_train):
        d_b = maha(xi, mu_b)
        d_m = maha(xi, mu_m)
        pred = 1 if (d_m / (d_b + 1e-9)) < 1.0 else 0

        if pred == 1 and yi == 1:
            tp += 1
        elif pred == 0 and yi == 0:
            tn += 1
        elif pred == 1 and yi == 0:
            fp += 1
        else:
            fn += 1

    sens_base = tp / (tp + fn + 1e-6)
    spec_base = tn / (tn + fp + 1e-6)
    bal_base = 0.5 * (sens_base + spec_base)

    print(f"   Sensitivity (TRAIN): {sens_base:.4f}")
    print(f"   Specificity (TRAIN): {spec_base:.4f}")
    print(f"   Balanced Acc (TRAIN): {bal_base:.4f}")

    # ================================
    # CLASS-WEIGHTED τ SWEEP (TRAIN)
    # ================================
    print("\n📘 Sweeping τ on TRAIN with class-weighted cost…")
    print(f"   Costs: C_FN={C_FN}, C_FP={C_FP}")
    print("   τ   sens     spec     FN_rate  FP_rate  cost")

    best_tau = None
    best_cost = float("inf")
    best_stats = None

    for T in TAU_GRID:
        tp = tn = fp = fn = 0
        for xi, yi in zip(Xtr, y_train):
            d_b = maha(xi, mu_b)
            d_m = maha(xi, mu_m)
            pred = 1 if (d_m / (d_b + 1e-9)) < T else 0

            if pred == 1 and yi == 1:
                tp += 1
            elif pred == 0 and yi == 0:
                tn += 1
            elif pred == 1 and yi == 0:
                fp += 1
            else:
                fn += 1

        sens = tp / (tp + fn + 1e-6)
        spec = tn / (tn + fp + 1e-6)
        fn_rate = fn / (tp + fn + 1e-6)
        fp_rate = fp / (tn + fp + 1e-6)

        cost = C_FN * fn_rate + C_FP * fp_rate

        print(f"   {T:>4.2f} {sens:7.4f} {spec:7.4f} {fn_rate:8.4f} {fp_rate:8.4f} {cost:7.4f}")

        if cost < best_cost:
            best_cost = cost
            best_tau = T
            best_stats = (sens, spec, fn_rate, fp_rate)

    print(f"\n✅ Chosen τ (min cost on TRAIN): {best_tau:.2f}")
    print(f"   TRAIN sens={best_stats[0]:.4f}, spec={best_stats[1]:.4f}, "
          f"FN_rate={best_stats[2]:.4f}, FP_rate={best_stats[3]:.4f}, cost={best_cost:.4f}")

    # ================================
    # EVALUATE ON TEST WITH BEST τ
    # ================================
    print("\n📘 Evaluating on TEST with chosen τ…")
    tp = tn = fp = fn = 0
    for xi, yi in zip(Xte, y_test):
        d_b = maha(xi, mu_b)
        d_m = maha(xi, mu_m)
        pred = 1 if (d_m / (d_b + 1e-9)) < best_tau else 0

        if pred == 1 and yi == 1:
            tp += 1
        elif pred == 0 and yi == 0:
            tn += 1
        elif pred == 1 and yi == 0:
            fp += 1
        else:
            fn += 1

    sens = tp / (tp + fn + 1e-6)
    spec = tn / (tn + fp + 1e-6)
    acc = (tp + tn) / (tp + tn + fp + fn + 1e-6)
    prec = tp / (tp + fp + 1e-6)
    f1 = 2 * prec * sens / (prec + sens + 1e-6)
    bal_acc = 0.5 * (sens + spec)

    print("\n==== 🧪 TEST SET RESULTS (τ class-weighted) ====")
    print(f"τ = {best_tau:.2f}")
    print(f"TP = {tp}, FP = {fp}")
    print(f"FN = {fn}, TN = {tn}\n")
    print(f"Accuracy:          {acc:.4f}")
    print(f"Sensitivity(Recall): {sens:.4f}")
    print(f"Specificity:       {spec:.4f}")
    print(f"Precision:         {prec:.4f}")
    print(f"F1-Score:          {f1:.4f}")
    print(f"Balanced Accuracy: {bal_acc:.4f}")
    print("=====================================\n")

    print(f"👉 If you like these results, update 'tau_default' in your model JSON to {best_tau:.2f}.")


if __name__ == "__main__":
    evaluate()
