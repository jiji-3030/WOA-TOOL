#!/usr/bin/env python3
"""
describe_lesion.py — FINAL FIXED VERSION
Correct metadata path + correct matching + returns only the fields needed.
"""

import os
import json
import argparse
import pandas as pd


# ------------------------------------------------------------
# CORRECT ABSOLUTE METADATA PATH
# ------------------------------------------------------------
METADATA_DIR = "/Volumes/JANICE/cbis-ddsm-r/metadata"

MASS_FILES = [
    "mass_case_description_train_set.csv",
    "mass_case_description_test_set.csv",
]

CALC_FILES = [
    "calc_case_description_train_set.csv",
    "calc_case_description_test_set.csv",
]


def load_metadata(path):
    df = pd.read_csv(path, dtype=str, encoding="utf-8")
    df.columns = [c.strip().lower().replace(" ", "_") for c in df.columns]
    return df


def normalize_int(x):
    try:
        return int(x)
    except Exception:
        return None


def extract_folder(path_str):
    """Extract leading folder: Calc-Test_P_00038_LEFT_CC/... -> Calc-Test_P_00038_LEFT_CC"""
    if isinstance(path_str, str) and "/" in path_str:
        return path_str.split("/")[0]
    return path_str


# ------------------------------------------------------------
# METADATA LOOKUP
# ------------------------------------------------------------
def find_metadata(base_id):
    # --------------- MASS --------------------
    for fname in MASS_FILES:
        fpath = os.path.join(METADATA_DIR, fname)
        if not os.path.exists(fpath):
            continue

        df = load_metadata(fpath)
        df["folder"] = df["image_file_path"].apply(extract_folder)

        row = df[df["folder"] == base_id]
        if not row.empty:
            r = row.iloc[0]
            return {
                "abnormality_type": "mass",
                "mass_shape": r.get("mass_shape"),
                "mass_margins": r.get("mass_margins"),
                "assessment": normalize_int(r.get("assessment")),
                "subtlety": normalize_int(r.get("subtlety")),
            }

    # --------------- CALC --------------------
    for fname in CALC_FILES:
        fpath = os.path.join(METADATA_DIR, fname)
        if not os.path.exists(fpath):
            continue

        df = load_metadata(fpath)
        df["folder"] = df["image_file_path"].apply(extract_folder)

        row = df[df["folder"] == base_id]
        if not row.empty:
            r = row.iloc[0]
            return {
                "abnormality_type": "calcification",
                "calc_type": r.get("calc_type"),
                "calc_distribution": r.get("calc_distribution"),
                "assessment": normalize_int(r.get("assessment")),
                "subtlety": normalize_int(r.get("subtlety")),
            }

    return None


# ------------------------------------------------------------
# NARRATIVE GENERATOR
# ------------------------------------------------------------
def make_narrative(md):
    if md is None:
        return ""

    if md["abnormality_type"] == "mass":
        shape = (md.get("mass_shape") or "").replace("_", " ").lower()
        margins = (md.get("mass_margins") or "").replace("_", " ").lower()
        return f"A mass with {shape} shape and {margins} margins."

    if md["abnormality_type"] == "calcification":
        ctype = (md.get("calc_type") or "").replace("_", " ").lower()
        dist = (md.get("calc_distribution") or "").replace("_", " ").lower()
        return f"Calcifications with {ctype} morphology in a {dist} distribution."

    return ""


# ------------------------------------------------------------
# MAIN
# ------------------------------------------------------------
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-id", required=True)
    parser.add_argument("--radiomics", required=False)
    args = parser.parse_args()

    base_id = args.base_id

    md = find_metadata(base_id)

    out = {
        "base_id": base_id,
        "metadata": md,  # ONLY your requested metadata fields
        "narrative": make_narrative(md),
        "birads": md["assessment"] if md else None,
    }

    print(json.dumps(out, ensure_ascii=False))


if __name__ == "__main__":
    main()
