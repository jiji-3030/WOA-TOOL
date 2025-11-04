"""
Abnormality inference module for WOA-Tool.
Derives abnormality category, quantitative scores, and tissue characteristics
directly from radiomic z-scores of the input image.
"""

import os
import math
import numpy as np


def _clip01(x: float) -> float:
    """Clamp value between 0 and 1 with 4-decimal rounding for stability."""
    return float(max(0.0, min(1.0, round(x, 4))))


def _sigmoid01_from_z(z: float) -> float:
    """Map a z-score to [0,1] via the normal CDF."""
    # 0.5 * (1 + erf(z / sqrt(2)))
    return 0.5 * (1.0 + math.erf(z / math.sqrt(2.0)))


# ---------- Tunable thresholds (env-overridable) ----------
TH_CALC = float(os.getenv("TH_CALC", "0.55"))                 # gate to declare Calcifications
TH_CALC_STRONG = float(os.getenv("TH_CALC_STRONG", "0.80"))   # "Fine linear / branching"
TH_PLEOMORPHIC = float(os.getenv("TH_PLEOMORPHIC", "0.60"))   # Pleomorphic if texture_disorder is high
TH_TEXT_DIS = float(os.getenv("TH_TEXT_DIS", "0.60"))         # texture ceiling for Calcifications

# Distribution thresholds based on z-score of blob_density:
TH_DIST_CLUSTER = float(os.getenv("TH_DIST_CLUSTER", "1.0"))  # ≥ ~84th percentile → Clustered
TH_DIST_REGIONAL = float(os.getenv("TH_DIST_REGIONAL", "0.3"))# ≥ ~62nd percentile → Regional

# Mass heuristics
TH_SPIC = float(os.getenv("TH_SPIC", "0.60"))
TH_SHAPE_IRR = float(os.getenv("TH_SHAPE_IRR", "0.60"))
TH_TEXT_DIS_MASS = float(os.getenv("TH_TEXT_DIS_MASS", "0.50"))


def infer_abnormality(zscores: dict):
    """
    Infer lesion abnormality subtype (Mass vs Calcifications) and tissue background.
    All inputs are z-scores. Returns: (abn_label, abn_scores, abn_expl, background)
    """
    if not zscores:
        return "Unknown", {}, "Insufficient data to infer abnormality.", {}

    # === Core radiomic cues (use magnitudes for disorder-ish terms) ===
    entropy = abs(float(zscores.get("glcm_entropy", 0.0)))
    contrast = abs(float(zscores.get("glcm_contrast", 0.0)))
    variance = abs(float(zscores.get("glcm_variance", 0.0)))
    shape_extent = abs(float(zscores.get("shape_extent", 0.0)))
    shape_ecc = abs(float(zscores.get("shape_eccentricity", 0.0)))
    spiculation = abs(float(zscores.get("spic_orient_dispersion", 0.0)))

    # For calcifications, use signed z-scores and map to probabilities
    blob_density_z = float(zscores.get("blob_density", 0.0))
    blob_count_z = float(zscores.get("blob_count", 0.0))

    # Background density uses magnitude of hist_mean z
    density_z = abs(float(zscores.get("hist_mean", 0.0)))

    # === Quantitative indices (z-score–aware) ===
    texture_disorder = _clip01((entropy + contrast + variance / 1000.0) / 20.0)
    shape_irregularity = _clip01(((1 - shape_extent) + shape_ecc) / 2.0)
    spiculation_index = _clip01(spiculation / 3.0)

    # Calcification likelihood from z-scores via normal CDFs (bounded, smooth)
    calc_density_p = _sigmoid01_from_z(blob_density_z)  # higher z => denser/smaller bright foci
    calc_count_p = _sigmoid01_from_z(blob_count_z)      # higher z => more foci
    calcification_index = _clip01(0.7 * calc_density_p + 0.3 * calc_count_p)

    density_index = _clip01(density_z / 5.0)

    abn_scores = {
        "texture_disorder": texture_disorder,
        "shape_irregularity": shape_irregularity,
        "spiculation_index": spiculation_index,
        "calcification_index": calcification_index,
        "density_index": density_index,
    }

    # === Determine main abnormality category ===
    if calcification_index > TH_CALC and texture_disorder < TH_TEXT_DIS:
        # ---- Calcification-type lesion ----
        lesion_type = "Calcifications"

        # Type
        if calcification_index >= TH_CALC_STRONG:
            calc_type = "Fine linear / branching"
        elif texture_disorder >= TH_PLEOMORPHIC:
            calc_type = "Pleomorphic"
        else:
            calc_type = "Amorphous"

        # Distribution from blob_density z-score
        if blob_density_z >= TH_DIST_CLUSTER:
            calc_dist = "Clustered"
        elif blob_density_z >= TH_DIST_REGIONAL:
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
        if spiculation_index > TH_SPIC:
            margin = "Spiculated"
        elif shape_irregularity > TH_SHAPE_IRR:
            margin = "Indistinct"
        elif texture_disorder > TH_TEXT_DIS_MASS:
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
        ),
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


# Optional helper if you want structured subtype without string parsing in predict.py
def parse_subtype_from_label(abn_label: str):
    """
    Convert a label like 'Calcifications (Fine linear / branching, Clustered)'
    or 'Mass (Irregular, Spiculated)' into a dict:
      {"category":"Calcifications","details":{"type":"Fine linear / branching","distribution":"Clustered"}}
    """
    subtype = {"category": None, "details": {}}
    if not isinstance(abn_label, str):
        return subtype
    if "Mass" in abn_label:
        subtype["category"] = "Mass"
    elif "Calcifications" in abn_label:
        subtype["category"] = "Calcifications"
    try:
        inner = abn_label.split("(")[1].split(")")[0]
        parts = [p.strip() for p in inner.split(",")]
        if subtype["category"] == "Mass" and len(parts) == 2:
            subtype["details"]["shape"], subtype["details"]["margin"] = parts
        elif subtype["category"] == "Calcifications" and len(parts) == 2:
            subtype["details"]["type"], subtype["details"]["distribution"] = parts
    except Exception:
        pass
    return subtype

def infer_abnormality_debug(zscores: dict):
    """
    Drop-in debug wrapper: returns (label, scores, expl, background, debug_dict).
    Does not change the behavior of infer_abnormality; just re-computes internals for transparency.
    """
    if not zscores:
        return "Unknown", {}, "Insufficient data to infer abnormality.", {}, {
            "reason": "empty zscores"
        }

    # Recompute the same features used inside infer_abnormality
    import math

    def _clip01(x: float) -> float:
        return float(max(0.0, min(1.0, round(x, 4))))

    def _sigmoid01_from_z(z: float) -> float:
        return 0.5 * (1.0 + math.erf(z / math.sqrt(2.0)))

    entropy = abs(float(zscores.get("glcm_entropy", 0.0)))
    contrast = abs(float(zscores.get("glcm_contrast", 0.0)))
    variance = abs(float(zscores.get("glcm_variance", 0.0)))
    shape_extent = abs(float(zscores.get("shape_extent", 0.0)))
    shape_ecc = abs(float(zscores.get("shape_eccentricity", 0.0)))
    spiculation = abs(float(zscores.get("spic_orient_dispersion", 0.0)))
    blob_density_z = float(zscores.get("blob_density", 0.0))
    blob_count_z = float(zscores.get("blob_count", 0.0))
    density_z = abs(float(zscores.get("hist_mean", 0.0)))

    texture_disorder = _clip01((entropy + contrast + variance / 1000.0) / 20.0)
    shape_irregularity = _clip01(((1 - shape_extent) + shape_ecc) / 2.0)
    spiculation_index = _clip01(spiculation / 3.0)

    calc_density_p = _sigmoid01_from_z(blob_density_z)
    calc_count_p = _sigmoid01_from_z(blob_count_z)
    calcification_index = _clip01(0.7 * calc_density_p + 0.3 * calc_count_p)

    density_index = _clip01(density_z / 5.0)

    # Call the real function to keep the single source of truth for the label
    abn_label, abn_scores, abn_expl, background = infer_abnormality(zscores)

    debug = {
        "inputs": {
            "entropy_z": entropy,
            "contrast_z": contrast,
            "variance_z": variance,
            "shape_extent_abs_z": shape_extent,
            "shape_ecc_abs_z": shape_ecc,
            "spiculation_abs_z": spiculation,
            "blob_density_z": blob_density_z,
            "blob_count_z": blob_count_z,
            "hist_mean_abs_z": density_z,
        },
        "indices": {
            "texture_disorder": texture_disorder,
            "shape_irregularity": shape_irregularity,
            "spiculation_index": spiculation_index,
            "calcification_index": calcification_index,
            "density_index": density_index,
        },
        "thresholds": {
            "TH_CALC": float(os.getenv("TH_CALC", "0.55")),
            "TH_CALC_STRONG": float(os.getenv("TH_CALC_STRONG", "0.80")),
            "TH_PLEOMORPHIC": float(os.getenv("TH_PLEOMORPHIC", "0.60")),
            "TH_TEXT_DIS": float(os.getenv("TH_TEXT_DIS", "0.60")),
            "TH_DIST_CLUSTER": float(os.getenv("TH_DIST_CLUSTER", "1.0")),
            "TH_DIST_REGIONAL": float(os.getenv("TH_DIST_REGIONAL", "0.3")),
            "TH_SPIC": float(os.getenv("TH_SPIC", "0.60")),
            "TH_SHAPE_IRR": float(os.getenv("TH_SHAPE_IRR", "0.60")),
            "TH_TEXT_DIS_MASS": float(os.getenv("TH_TEXT_DIS_MASS", "0.50")),
        },
        "label": abn_label,
        "path_logic": (
            "Calcifications" if (calcification_index > float(os.getenv('TH_CALC', '0.55'))
                                 and texture_disorder < float(os.getenv('TH_TEXT_DIS', '0.60')))
            else "Mass"
        )
    }
    return abn_label, abn_scores, abn_expl, background, debug
