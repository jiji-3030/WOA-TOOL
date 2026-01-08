# woa_tool/predict_radiomics.py
import json
import numpy as np
import pandas as pd


def predict_radiomics(model_path: str, csv_row_path: str, row_index: int = 0, top_k: int = 10):
    """
    Predict benign vs malignant from a single PyRadiomics CSV row
    AND compute per-feature contributions (which features support malignant vs benign).

    Args:
        model_path: path to models/model_ewoa_radiomics.json
        csv_row_path: CSV with at least one row of radiomics features
        row_index: which row to use (default: first)
        top_k: number of top contributing features to return
    """

    # ------------------------------
    # Load model
    # ------------------------------
    with open(model_path, "r") as f:
        model = json.load(f)

    selected_idx   = model["selected_idx"]
    selected_names = model["selected_names"]
    all_names      = model["feature_names"]

    global_mu    = np.array(model["global_mu"])
    global_sigma = np.array(model["global_sigma"]) + 1e-6

    class_stats = model.get("class_stats", None)
    if class_stats is None:
        raise ValueError("Model JSON missing 'class_stats'; retrain with latest train.py")

    mu_B = np.array(class_stats["0"]["mu"])
    mu_M = np.array(class_stats["1"]["mu"])
    sigma_B = np.array(class_stats["0"]["sigma"]) + 1e-6
    sigma_M = np.array(class_stats["1"]["sigma"]) + 1e-6

    tau_default = float(model.get("tau_default", 1.0))

    # ------------------------------
    # Load radiomics row
    # ------------------------------
    df = pd.read_csv(csv_row_path)
    if row_index < 0 or row_index >= len(df):
        raise IndexError(f"row_index {row_index} out of range for CSV with {len(df)} rows")

    row = df.iloc[row_index]

    # Extract features in correct order
    x_full = np.array([row[name] for name in all_names], dtype=float)

    # Z-score normalize with global stats
    x_norm = (x_full - global_mu) / global_sigma

    xs = x_norm[selected_idx]

    # ------------------------------
    # Distances and prediction
    # ------------------------------
    # Mahalanobis-like: using per-feature sigma_B/M from TRAIN class stats
    # (diagonal approximation)
    dB = float(np.sum(np.abs(xs - mu_B) / sigma_B))
    dM = float(np.sum(np.abs(xs - mu_M) / sigma_M))

    ratio = dM / (dB + 1e-9)
    pred = 1 if ratio < tau_default else 0

    # Score mapped to (0,1), lower ratio → higher malignancy score
    score = 1.0 / (1.0 + ratio)

    # ------------------------------
    # Per-feature contributions
    # ------------------------------
    # For each feature: how much closer to malignant vs benign center?
    zB = np.abs(xs - mu_B) / sigma_B
    zM = np.abs(xs - mu_M) / sigma_M

    contrib = zB - zM  # >0 means closer to malignant, <0 closer to benign

    contrib_list = []
    for name, c, zb, zm in zip(selected_names, contrib, zB, zM):
        contrib_list.append({
            "feature": name,
            "contribution": float(c),
            "towards": "Malignant" if c > 0 else "Benign",
            "z_benign": float(zb),
            "z_malignant": float(zm),
        })

    # Sort by absolute contribution
    contrib_list_sorted = sorted(contrib_list, key=lambda d: abs(d["contribution"]), reverse=True)
    top_features = contrib_list_sorted[:top_k]

    return {
        "prediction": "Malignant" if pred == 1 else "Benign",
        "class_index": int(pred),
        "distance_benign": dB,
        "distance_malignant": dM,
        "ratio_dM_over_dB": ratio,
        "tau_used": tau_default,
        "score_like_probability": score,
        "row_index": int(row_index),

        # 🔹 ALL selected-feature contributions (FULL, per image)
        "all_feature_contributions": contrib_list_sorted,

        # 🔹 Top-K (for UI / interpretability)
        "top_feature_contributions": top_features,

        # 🔹 Model-level selected features
        "selected_features": selected_names,
    }

