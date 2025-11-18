import numpy as np
import json
import os

ROOT = "/Volumes/JILLYBEAN/cbis-ddsm-r/data/CBIS-DDSM-R/npy"

def load_radiomics_data():
    X_train = np.load(os.path.join(ROOT, "X_train.npy"))
    y_train = np.load(os.path.join(ROOT, "y_train.npy"))
    X_test  = np.load(os.path.join(ROOT, "X_test.npy"))
    y_test  = np.load(os.path.join(ROOT, "y_test.npy"))

    with open(os.path.join(ROOT, "feature_names.json")) as f:
        feature_names = json.load(f)

    return X_train, y_train, X_test, y_test, feature_names
