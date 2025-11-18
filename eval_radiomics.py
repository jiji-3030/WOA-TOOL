# eval_radiomics.py
import json
import numpy as np
from woa_tool.data_radiomics import load_radiomics_data


def evaluate(model_path):
    print("📘 Loading radiomics dataset…")
    X_train, y_train, X_test, y_test, feature_names = load_radiomics_data()

    print("📘 Loading trained model…")
    with open(model_path) as f:
        model = json.load(f)

    selected = model["selected_idx"]
    mu = np.array(model["global_mu"])[selected]
    sigma = np.array(model["global_sigma"])[selected]

    # Select features
    Xtr = X_train[:, selected]
    Xte = X_test[:, selected]

    # Normalize using TRAIN stats stored in JSON
    Xtr = (Xtr - mu) / (sigma + 1e-6)
    Xte = (Xte - mu) / (sigma + 1e-6)

    # Fit Mahalanobis model using TRAIN data only
    print("📘 Fitting Mahalanobis class means/covariance…")
    mu_b = Xtr[y_train == 0].mean(axis=0)
    mu_m = Xtr[y_train == 1].mean(axis=0)

    Sb = np.cov(Xtr[y_train == 0].T) + 1e-6 * np.eye(len(selected))
    Sm = np.cov(Xtr[y_train == 1].T) + 1e-6 * np.eye(len(selected))

    Sp_inv = np.linalg.pinv(0.5 * (Sb + Sm))

    def maha(x, mu):
        d = x - mu
        return float(np.sqrt(d @ Sp_inv @ d))

    # Sweep tau on training set
    taus = [0.90, 0.95, 0.98, 1.00, 1.02, 1.05, 1.08, 1.10]
    best_tau = None
    best_bal_acc = -1

    print("📘 Selecting optimal τ on training set…")
    for T in taus:
        tp = tn = fp = fn = 0

        for xi, yi in zip(Xtr, y_train):
            d_b = maha(xi, mu_b)
            d_m = maha(xi, mu_m)
            pred = 1 if (d_m / (d_b + 1e-9)) < T else 0

            if pred == 1 and yi == 1: tp += 1
            elif pred == 0 and yi == 0: tn += 1
            elif pred == 1 and yi == 0: fp += 1
            else: fn += 1

        sens = tp / (tp + fn + 1e-6)
        spec = tn / (tn + fp + 1e-6)
        bal_acc = 0.5 * (sens + spec)

        if bal_acc > best_bal_acc:
            best_bal_acc = bal_acc
            best_tau = T

    print(f"✅ Best τ = {best_tau}")
    print(f"   Balanced Accuracy on TRAIN = {best_bal_acc:.4f}")

    # Evaluate on TEST using best τ
    print("\n📘 Evaluating on TEST set…")
    tp = tn = fp = fn = 0

    for xi, yi in zip(Xte, y_test):
        d_b = maha(xi, mu_b)
        d_m = maha(xi, mu_m)
        pred = 1 if (d_m / (d_b + 1e-9)) < best_tau else 0

        if pred == 1 and yi == 1: tp += 1
        elif pred == 0 and yi == 0: tn += 1
        elif pred == 1 and yi == 0: fp += 1
        else: fn += 1

    # Metrics
    sens = tp / (tp + fn + 1e-6)
    spec = tn / (tn + fp + 1e-6)
    acc = (tp + tn) / (tp + tn + fp + fn + 1e-6)
    prec = tp / (tp + fp + 1e-6)
    f1 = 2 * prec * sens / (prec + sens + 1e-6)
    bal_acc = 0.5 * (sens + spec)

    print("\n==== 🧪 TEST SET RESULTS ====")
    print(f"TP = {tp}, FP = {fp}")
    print(f"FN = {fn}, TN = {tn}\n")

    print(f"Accuracy:          {acc:.4f}")
    print(f"Sensitivity(Recall): {sens:.4f}")
    print(f"Specificity:       {spec:.4f}")
    print(f"Precision:         {prec:.4f}")
    print(f"F1-Score:          {f1:.4f}")
    print(f"Balanced Accuracy: {bal_acc:.4f}")
    print("=====================================\n")


if __name__ == "__main__":
    evaluate("models/model_ewoa_radiomics.json")
 