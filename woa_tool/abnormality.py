"""
Abnormality inference module for WOA-Tool.
Derives abnormality category, quantitative scores, and tissue characteristics
directly from radiomic z-scores of the input image.
"""

import numpy as np


def _clip01(x):
    """Clamp value between 0 and 1."""
    return float(max(0.0, min(1.0, round(x, 4))))


def infer_abnormality(zscores: dict):
    """
    Infer lesion abnormality subtype (mass vs calcifications) and tissue background.
    """

    if not zscores:
        return "Unknown", {}, "Insufficient data to infer abnormality.", {}

    # === Core radiomic cues ===
    entropy = abs(float(zscores.get("glcm_entropy", 0)))
    contrast = abs(float(zscores.get("glcm_contrast", 0)))
    variance = abs(float(zscores.get("glcm_variance", 0)))
    shape_extent = abs(float(zscores.get("shape_extent", 0)))
    shape_ecc = abs(float(zscores.get("shape_eccentricity", 0)))
    spiculation = abs(float(zscores.get("spic_orient_dispersion", 0)))
    blob_density = abs(float(zscores.get("blob_density", 0)))
    blob_count = abs(float(zscores.get("blob_count", 0)))
    density = abs(float(zscores.get("hist_mean", 0)))

    # === Quantitative indices ===
    texture_disorder = _clip01((entropy + contrast + variance / 1000.0) / 20.0)
    shape_irregularity = _clip01(((1 - shape_extent) + shape_ecc) / 2.0)
    spiculation_index = _clip01(spiculation / 3.0)
    calcification_index = _clip01(min(1.0, blob_density * 50 + blob_count / 10000))
    density_index = _clip01(density / 5.0)

    abn_scores = {
        "texture_disorder": texture_disorder,
        "shape_irregularity": shape_irregularity,
        "spiculation_index": spiculation_index,
        "calcification_index": calcification_index,
        "density_index": density_index,
    }

    # === Determine main abnormality category ===
    if calcification_index > 0.5 and texture_disorder < 0.6:
        # ---- Calcification-type lesion ----
        lesion_type = "Calcifications"
        if calcification_index > 0.8:
            calc_type = "Fine linear / branching"
        elif texture_disorder > 0.4:
            calc_type = "Pleomorphic"
        else:
            calc_type = "Amorphous"

        if blob_density > 0.005:
            calc_dist = "Clustered"
        elif blob_density > 0.002:
            calc_dist = "Regional"
        else:
            calc_dist = "Diffuse"

        abn_label = f"{lesion_type} ({calc_type}, {calc_dist})"

    else:
        # ---- Mass-type lesion ----
        lesion_type = "Mass"
        # Mass shape heuristic
        if shape_extent > 0.8:
            mass_shape = "Round"
        elif shape_ecc < 0.5:
            mass_shape = "Oval"
        else:
            mass_shape = "Irregular"

        # Margin heuristic
        if spiculation_index > 0.6:
            margin = "Spiculated"
        elif shape_irregularity > 0.6:
            margin = "Indistinct"
        elif texture_disorder > 0.5:
            margin = "Microlobulated"
        else:
            margin = "Circumscribed"

        abn_label = f"{lesion_type} ({mass_shape}, {margin})"

    # === Background tissue classification ===
    if density_index < 0.25:
        bg_code, bg_text = "T1", "Almost entirely fatty"
    elif density_index < 0.50:
        bg_code, bg_text = "T2", "Scattered fibroglandular densities"
    elif density_index < 0.75:
        bg_code, bg_text = "T3", "Heterogeneously dense tissue"
    else:
        bg_code, bg_text = "T4", "Extremely dense tissue"

    background = {
        "code": bg_code,
        "text": bg_text,
        "explain": (
            f"Background tissue density inferred from histogram mean "
            f"and radiomic intensity z-scores (density index = {density_index:.2f})."
        )
    }

    # === Overall risk summary ===
    risk_score = np.mean(list(abn_scores.values()))
    if risk_score >= 0.7:
        risk_level = "High"
    elif risk_score >= 0.45:
        risk_level = "Moderate"
    else:
        risk_level = "Low"

    abn_expl = (
        f"Entropy={entropy:.2f}, Contrast={contrast:.2f}, Shape Extent={shape_extent:.2f}, "
        f"Spiculation={spiculation:.2f}, Calcification Index={calcification_index:.2f}. "
        f"Detected {abn_label.lower()} pattern with estimated risk level: {risk_level}."
    )

    return abn_label, abn_scores, abn_expl, background
