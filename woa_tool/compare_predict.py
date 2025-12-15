# ===============================================
# woa_tool/compare_predict.py
# Radiomics-only WOA vs EWOA comparison
# Matches format of old compare_predict output
# ===============================================

import argparse
import json
import time
import numpy as np
import pandas as pd


# ------------------------------------------------
# Load model (same logic as predict_radiomics)
# ------------------------------------------------
def load_radiomics_model(path: str):
    with open(path, "r") as f:
        model = json.load(f)

    selected_idx = model["selected_idx"]
    selected_names = model["selected_names"]
    feature_names = model["feature_names"]

    global_mu = np.array(model["global_mu"])
    global_sigma = np.array(model["global_sigma"]) + 1e-6

    class_stats = model["class_stats"]
    mu_B = np.array(class_stats["0"]["mu"], dtype=float)
    mu_M = np.array(class_stats["1"]["mu"], dtype=float)
    sigma_B = np.array(class_stats["0"]["sigma"], dtype=float) + 1e-6
    sigma_M = np.array(class_stats["1"]["sigma"], dtype=float) + 1e-6

    tau = float(model.get("tau_default", 1.0))

    return {
        "feature_names": feature_names,
        "selected_idx": np.array(selected_idx),
        "selected_names": selected_names,
        "global_mu": global_mu,
        "global_sigma": global_sigma,
        "mu_B": mu_B,
        "mu_M": mu_M,
        "sigma_B": sigma_B,
        "sigma_M": sigma_M,
        "tau": tau,
    }


# ------------------------------------------------
# Predict using radiomics row
# ------------------------------------------------
def predict_from_row(row, model, top_k=10):
    fn = model["feature_names"]
    idx = model["selected_idx"]
    sel_names = model["selected_names"]

    # Ordered feature vector
    x_full = np.array([row[name] for name in fn], dtype=float)

    # Normalize
    x_norm = (x_full - model["global_mu"]) / model["global_sigma"]
    xs = x_norm[idx]

    # Distances
    dB = float(np.sum(np.abs(xs - model["mu_B"]) / model["sigma_B"]))
    dM = float(np.sum(np.abs(xs - model["mu_M"]) / model["sigma_M"]))

    ratio = dM / (dB + 1e-9)
    pred_cls = 1 if ratio < model["tau"] else 0
    pred_label = "Malignant" if pred_cls == 1 else "Benign"

    # contributions
    zB = np.abs(xs - model["mu_B"]) / model["sigma_B"]
    zM = np.abs(xs - model["mu_M"]) / model["sigma_M"]
    contrib = zB - zM

    contrib_list = []
    for name, c in zip(sel_names, contrib):
        contrib_list.append((name, float(c)))

    # sort: negative = malignant first
    order = sorted(contrib_list, key=lambda x: x[1])

    top_mal = order[:top_k]      # most negative (malignant)
    all_pairs = order            # entire sorted list

    return {
        "prediction": pred_label,
        "pred_cls": pred_cls,
        "total_detected": len(sel_names),
        "towards_malignant": sum(1 for _, v in contrib_list if v < 0),
        "towards_benign": sum(1 for _, v in contrib_list if v > 0),
        "names_mal": [n for n, v in contrib_list if v < 0],
        "names_ben": [n for n, v in contrib_list if v > 0],
        "all_names": sel_names,
        "top_mal": top_mal,
        "all_pairs": all_pairs,
        "exec_time": None,  # added later
    }


# ------------------------------------------------
# Print in SAME FORMAT as old compare_predict
# ------------------------------------------------
def print_block(label, block, correct):
    print(f"{label}:")
    print(f"\"Prediction\": \"{block['prediction']}\",")

    print("total number of features detected:")
    print(block["total_detected"])

    print("total number of \"towards malignant\":")
    print(block["towards_malignant"])

    print("total number of \"towards benign\":")
    print(block["towards_benign"])

    print("name of malignant features:")
    print(", ".join(block["names_mal"]) or "(none)")

    print("name of benign features:")
    print(", ".join(block["names_ben"]) or "(none)")

    print("name of all detected features:")
    print(", ".join(block["all_names"]) or "(none)")

    print("Exec time:")
    print(f"{block['exec_time']:.4f}s")

    print("top features contributing to malignant:")
    if block["top_mal"]:
        for n, v in block["top_mal"]:
            print(f"  - {n}: {v:.6f}")
    else:
        print("  (none)")

    print("")
    print("all detected features with numericals (negative=towards malignant, positive=towards benign):")
    for n, v in block["all_pairs"]:
        print(f"  - {n}: {v:.6f}")

    print("")


# ------------------------------------------------
# MAIN
# ------------------------------------------------
if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("--csv", required=True)
    p.add_argument("--row-index", type=int, default=0)
    p.add_argument("--woa", help="WOA model JSON")
    p.add_argument("--ewoa", help="EWOA model JSON")
    p.add_argument("--top-k", type=int, default=10)
    p.add_argument("--label-col", default="Class")
    args = p.parse_args()

    df = pd.read_csv(args.csv)
    if args.row_index < 0 or args.row_index >= len(df):
        print(f"Row index {args.row_index} out of range.")
        exit(1)

    row = df.iloc[args.row_index]

    # Ground truth
    truth_raw = row.get(args.label_col, None)
    if str(truth_raw).lower() in ("1", "m", "malignant"):
        truth = 1
    elif str(truth_raw).lower() in ("0", "b", "benign"):
        truth = 0
    else:
        truth = None

    # ---- WOA ----
    if args.woa:
        model = load_radiomics_model(args.woa)
        t0 = time.time()
        block = predict_from_row(row, model, top_k=args.top_k)
        block["exec_time"] = time.time() - t0
        correct = None if truth is None else (block["pred_cls"] == truth)
        print_block("Woa", block, correct)

    # ---- EWOA ----
    if args.ewoa:
        model = load_radiomics_model(args.ewoa)
        t0 = time.time()
        block = predict_from_row(row, model, top_k=args.top_k)
        block["exec_time"] = time.time() - t0
        correct = None if truth is None else (block["pred_cls"] == truth)
        print_block("Ewoa", block, correct)

    # Final correct classification output
    if truth is None:
        print("\"Correct Classification\": \"N/A\"")
    else:
        print("\"Correct Classification\": \"Malignant\"" if truth == 1 else "\"Correct Classification\": \"Benign\"")
