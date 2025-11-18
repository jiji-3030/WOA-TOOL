# woa_tool/data_radiomics.py
import os
import json
import numpy as np

# Point this to the NPY directory from cbis-ddsm-r
RADIOMICS_NPY_DIR = "/Volumes/JILLYBEAN/cbis-ddsm-r/data/CBIS-DDSM-R/npy"


def load_radiomics_data(npy_dir: str = RADIOMICS_NPY_DIR):
    """
    Load PyRadiomics-based CBIS-DDSM-R features prepared earlier.

    Returns:
        X_train, y_train, X_test, y_test, feature_names
    """
    X_train = np.load(os.path.join(npy_dir, "X_train.npy"))
    y_train = np.load(os.path.join(npy_dir, "y_train.npy"))
    X_test = np.load(os.path.join(npy_dir, "X_test.npy"))
    y_test = np.load(os.path.join(npy_dir, "y_test.npy"))

    with open(os.path.join(npy_dir, "feature_names.json")) as f:
        feature_names = json.load(f)

    return X_train, y_train, X_test, y_test, feature_names
