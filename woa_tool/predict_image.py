# woa_tool/predict_image.py

import json
import os
import numpy as np

from radiomics import featureextractor


def _extract_radiomics_from_image(
    image_path: str,
    mask_path: str,
    feature_names,
):
    """
    Run PyRadiomics on (image, mask) and return a dict:
        {feature_name: value}
    restricted to the names expected by the trained model.

    This assumes your PyRadiomics settings match those used when
    you created radiomics_features.csv / radiomics_train.csv.
    """

    if not os.path.isfile(image_path):
        raise FileNotFoundError(f"Image not found: {image_path}")
    if not os.path.isfile(mask_path):
        raise FileNotFoundError(f"Mask not found: {mask_path}")

    # IMPORTANT: tune these settings to match cbis-ddsm-r pipeline
    settings = {
        "binWidth": 25,
        "normalize": False,
        "removeOutliers": None,
        "imageType": {"Original": {}},  # features like "original_glcm_..."
    }

    extractor = featureextractor.RadiomicsFeatureExtractor(**settings)

    # PyRadiomics can accept paths directly (DICOM/PNG/etc.)
    result = extractor.execute(image_path, mask_path)

    feats = {}
    missing = []
    for name in feature_names:
        if name in result:
            feats[name] = float(result[name])
        else:
            missing.append(name)

    if missing:
        raise KeyError(
            f"PyRadiomics did not return some expected features (showing up to 10): "
            f"{missing[:10]}. Make sure your PyRadiomics settings match your training pipeline."
        )

    return feats


def predict_image(
    model_path: str,
    image_path: str,
    mask_path: str,
    tau_override: float | None = None,
):
    """
    Full prediction for a *single* mammogram lesion:

      1. Load trained model (EWOA/WOA) from JSON.
      2. Extract PyRadiomics features from image + ROI mask.
      3. Normalize with global_mu/global_sigma from training.
      4. Select EWOA-chosen features.
      5. Compute diagonal Mahalanobis-like distances and apply τ threshold.

    Returns a dict suitable for your PHP frontend / JSON API.
    """

    # ------------------------------
    # Load model
    # ------------------------------
    with open(model_path, "r") as f:
        model = json.load(f)

    feature_names = model["feature_names"]
    selected_idx = model["selected_idx"]
    selected_names = model["selected_names"]

    global_mu = np.array(model["global_mu"], dtype=float)
    global_sigma = np.array(model["global_sigma"], dtype=float) + 1e-6

    class_stats = model["class_stats"]
    tau_default = float(model.get("tau_default", 1.0))
    tau = float(tau_override) if tau_override is not None else tau_default

    mu_B = np.array(class_stats["0"]["mu"], dtype=float)
    sigma_B = np.array(class_stats["0"]["sigma"], dtype=float) + 1e-6
    mu_M = np.array(class_stats["1"]["mu"], dtype=float)
    sigma_M = np.array(class_stats["1"]["sigma"], dtype=float) + 1e-6

    # ------------------------------
    # Extract radiomics for this image + mask
    # ------------------------------
    feats = _extract_radiomics_from_image(image_path, mask_path, feature_names)

    # Vector in the same feature order used during training
    x_raw = np.array([feats[name] for name in feature_names], dtype=float)

    # Z-score normalization
    x_norm = (x_raw - global_mu) / global_sigma

    # Select EWOA features
    xs = x_norm[selected_idx]

    # ------------------------------
    # Diagonal Mahalanobis distances
    # ------------------------------
    dB = float(np.sum(np.abs(xs - mu_B) / sigma_B))
    dM = float(np.sum(np.abs(xs - mu_M) / sigma_M))
    ratio = dM / (dB + 1e-9)

    pred_idx = 1 if ratio < tau else 0
    pred_label = "Malignant" if pred_idx == 1 else "Benign"

    # Distance-based confidence-like score
    score = float(1.0 / (1.0 + ratio))

    return {
        "prediction": pred_label,
        "class_index": int(pred_idx),
        "distance_benign": dB,
        "distance_malignant": dM,
        "ratio_dM_over_dB": ratio,
        "tau_used": tau,
        "score_like_probability": score,
        "selected_features": selected_names,
        "image_path": image_path,
        "mask_path": mask_path,
    }
