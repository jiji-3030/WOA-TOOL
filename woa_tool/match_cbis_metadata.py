# match_cbis_metadata.py
import os
import pandas as pd

# Expected metadata CSV locations
CBIS_META_ROOT = "/Volumes/JANICE/cbis-ddsm-r/metadata"

MASS_FILES = [
    os.path.join(CBIS_META_ROOT, "mass_case_description_train_set.csv"),
    os.path.join(CBIS_META_ROOT, "mass_case_description_test_set.csv"),
]

CALC_FILES = [
    os.path.join(CBIS_META_ROOT, "calc_case_description_train_set.csv"),
    os.path.join(CBIS_META_ROOT, "calc_case_description_test_set.csv"),
]


def load_metadata():
    meta = []

    # load mass metadata
    for f in MASS_FILES:
        if os.path.exists(f):
            df = pd.read_csv(f)
            df["type"] = "mass"
            meta.append(df)

    # load calc metadata
    for f in CALC_FILES:
        if os.path.exists(f):
            df = pd.read_csv(f)
            df["type"] = "calc"
            meta.append(df)

    if not meta:
        raise FileNotFoundError("No metadata CSV files found.")

    return pd.concat(meta, ignore_index=True)


META = load_metadata()


def match_case_metadata(base_id: str):
    """
    base_id example: 'Calc-Test_P_00038_LEFT_CC'
    metadata stores: 'image file path' WITHOUT extension
    """

    row = META[META["image file path"] == base_id]

    if row.empty:
        return None

    r = row.iloc[0]

    if r["type"] == "mass":
        return {
            "lesion_type": "mass",
            "shape": r.get("mass_shape", ""),
            "margin": r.get("mass_margins", ""),
            "assessment": r.get("assessment", ""),
            "subtlety": r.get("subtlety", ""),
            "pathology": r.get("pathology", ""),
        }

    if r["type"] == "calc":
        return {
            "lesion_type": "calcification",
            "type": r.get("calc_type", ""),
            "distribution": r.get("calc_distribution", ""),
            "assessment": r.get("assessment", ""),
            "subtlety": r.get("subtlety", ""),
            "pathology": r.get("pathology", ""),
        }

    return None
