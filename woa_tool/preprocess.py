# woa_tool/preprocess.py
import os
import numpy as np
import pandas as pd
from .feature_extraction import extract_image_features
import json

OUT_DIR = "data/processed"
os.makedirs(OUT_DIR, exist_ok=True)

label_map = {"B": 0, "M": 1}   # Benign = 0, Malignant = 1

def load_dataset(csv_path):
    df = pd.read_csv(csv_path)
    X, y, ids = [], [], []
    feature_names = None

    for _, row in df.iterrows():
        label = str(row["Class"]).strip()
        if label not in label_map:
            continue

        img_path = row["image_path"]
        if not os.path.exists(img_path):
            print(f"⚠️ Missing image: {img_path}")
            continue

        feats = extract_image_features(img_path)
        if feature_names is None:
            feature_names = list(feats.keys())

        X.append([feats[f] for f in feature_names])
        y.append(label_map[label])
        ids.append(row["patient_id"])

    return np.array(X, dtype=float), np.array(y, dtype=int), ids, feature_names


def run():
    print("🔄 Loading training set...")
    X_train, y_train, ids_train, feat_names = load_dataset("data/train.csv")
    np.save(os.path.join(OUT_DIR, "X_train.npy"), X_train)
    np.save(os.path.join(OUT_DIR, "y_train.npy"), y_train)
    np.save(os.path.join(OUT_DIR, "ids_train.npy"), np.array(ids_train))

    print("🔄 Loading test set...")
    X_test, y_test, ids_test, _ = load_dataset("data/test.csv")
    np.save(os.path.join(OUT_DIR, "X_test.npy"), X_test)
    np.save(os.path.join(OUT_DIR, "y_test.npy"), y_test)
    np.save(os.path.join(OUT_DIR, "ids_test.npy"), np.array(ids_test))

    with open(os.path.join(OUT_DIR, "feature_names.json"), "w") as f:
        json.dump(feat_names, f, indent=2)

    print("✅ Preprocessing complete.")
    print(f"Train: {X_train.shape}, Test: {X_test.shape}")
    print(f"Features: {feat_names}")


if __name__ == "__main__":
    run()
