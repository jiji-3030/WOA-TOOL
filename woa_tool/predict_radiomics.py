# woa_tool/predict_radiomics.py
import json
import numpy as np
import pandas as pd


def predict_radiomics(model_path: str, csv_row_path: str, tau_override: float = None):
    """
    Predict benign vs malignant from a single PyRadiomics CSV row.

    Assumes:
      - Model JSON from woa_tool.train.train (radiomics mode)
      - CSV row has the same feature columns as used in training (feature_names list)
      - 'label' and other metadata columns are ignored for prediction
    """

    # ------------------------------
    # Load model
    # ------------------------------
    with open(model_path, "r") as f:
        model = json.load(f)

    selected_idx = model["selected_idx"]
    selected_names = model["selected_names"]
    all_names = model["feature_names"]

    global_mu = np.array(model["global_mu"])
    global_sigma = np.array(model["global_sigma"]) + 1e-6

    class_stats = model.get("class_stats", None)
    if class_stats is None:
        raise RuntimeError("Model JSON has no 'class_stats'. Retrain with the new train.py.")

    mu_B = np.array(class_stats["0"]["mu"])
    mu_M = np.array(class_stats["1"]["mu"])
    sigma_B = np.array(class_stats["0"]["sigma"])
    sigma_M = np.array(class_stats["1"]["sigma"])

    tau_default = model.get("tau_default", 1.0)
    tau = tau_override if tau_override is not None else tau_default

    # ------------------------------
    # Load radiomics row (1 lesion)
    # ------------------------------
    df = pd.read_csv(csv_row_path)

    if len(df) != 1:
        print(f"⚠️ CSV has {len(df)} rows; using the first row only.")

    row = df.iloc[0]

    # Extract in correct feature order
    x = np.array([row[name] for name in all_names], dtype=float)

    # Z-score normalize
    x_norm = (x - global_mu) / global_sigma

    xs = x_norm[selected_idx]

    # ------------------------------
    # Compute Mahalanobis-like distances
    # (here approximated as per-feature standardized L1)
    # ------------------------------
    dB = np.sum(np.abs(xs - mu_B) / (sigma_B + 1e-6))
    dM = np.sum(np.abs(xs - mu_M) / (sigma_M + 1e-6))

    ratio = dM / (dB + 1e-9)

    pred = 1 if ratio < tau else 0

    # Simple logistic mapping to probability of malignancy
    # Lower ratio (<< tau) → p ≈ 1, higher ratio (>> tau) → p ≈ 0
    k = 5.0
    p_malignant = 1.0 / (1.0 + np.exp(k * (ratio - tau)))

    return {
        "prediction": "Malignant" if pred == 1 else "Benign",
        "class_index": int(pred),
        "tau_used": float(tau),
        "distance_benign": float(dB),
        "distance_malignant": float(dM),
        "ratio": float(ratio),
        "prob_malignant": float(p_malignant),
        "selected_features": selected_names,
    }
