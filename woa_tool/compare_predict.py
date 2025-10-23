import os, time, json, numpy as np
from woa_tool.feature_extraction import extract_image_features
from evaluate_error_rate import maha_distance, zscore_normalize


def load_model(path):
    """Load model JSON from file."""
    if not os.path.exists(path):
        raise FileNotFoundError(f"Model not found: {path}")
    with open(path, "r") as f:
        return json.load(f)


def predict_single(image_path, model):
    """Run one model (WOA or EWOA) on a single image and return results."""
    t0 = time.time()

    # === Load and prepare ===
    feats = extract_image_features(image_path)
    feature_names = model["feature_names"]
    selected_idx = model.get("selected_idx", list(range(len(feature_names))))
    global_mu = np.array(model["global_mu"], dtype=float)
    global_sigma = np.array(model["global_sigma"], dtype=float)

    # Full vector → normalize → select subset
    x = np.array([feats.get(n, 0.0) for n in feature_names], dtype=np.float32)
    x = zscore_normalize(x, global_mu, global_sigma)
    x = x[selected_idx]

    mu_B = np.array(model["class_stats"]["0"]["mu"], dtype=float)
    mu_M = np.array(model["class_stats"]["1"]["mu"], dtype=float)
    sig_B = np.array(model["class_stats"]["0"]["sigma"], dtype=float)
    sig_M = np.array(model["class_stats"]["1"]["sigma"], dtype=float)

    # Shared covariance (ΣP = (ΣB + ΣM) / 2)
    Sp = np.diag((sig_B ** 2 + sig_M ** 2) / 2)
    Sp_inv = np.linalg.pinv(Sp)

    # === Compute distances and ratio ===
    d_B = maha_distance(x, mu_B, Sp_inv)
    d_M = maha_distance(x, mu_M, Sp_inv)
    ratio = float((d_M + 1e-9) / (d_B + 1e-9))

    # Classification decision
    pred = "Malignant" if ratio < 1.0 else "Benign"

    # Confidence metric (inverse of ratio)
    confidence = float(np.clip(1.0 / ratio if pred == "Malignant" else ratio, 0, 2))

    # Top influential features (|μ_M - μ_B| / σ)
    diffs = np.abs((mu_M - mu_B) / (sig_M + 1e-6))
    top_idx = np.argsort(diffs)[::-1][:5]
    # handle selected_names safely
    if "selected_names" in model:
        names = model["selected_names"]
    else:
        names = [feature_names[i] for i in selected_idx]
    top_feats = [names[i] for i in top_idx if i < len(names)]

    exec_time = time.time() - t0
    return {
        "Prediction": pred,
        "Confidence": round(confidence, 3),
        "Distance Ratio": round(ratio, 4),
        "Top Features": top_feats,
        "Execution Time": round(exec_time, 3),
    }


def compare_models(image_path, ewoa_model, woa_model):
    """Compare EWOA and WOA models on a single image."""
    start_total = time.time()

    # Load both models
    mE = load_model(ewoa_model)
    mW = load_model(woa_model)

    # Predict with each model
    resultE = predict_single(image_path, mE)
    resultW = predict_single(image_path, mW)

    total_time = time.time() - start_total

    results = {
        "EWOA": resultE,
        "WOA": resultW,
        "Total Runtime": round(total_time, 3),
    }

    # ✅ Print only valid JSON (no logs before this)
    print(json.dumps(results, indent=2))
    return results


if __name__ == "__main__":
    import argparse, sys
    parser = argparse.ArgumentParser(description="Compare EWOA vs WOA predictions on one image.")
    parser.add_argument("--image", required=True, help="Path to the image (TIFF/JPG/PNG)")
    parser.add_argument("--ewoa", required=True, help="EWOA model path")
    parser.add_argument("--woa", required=True, help="WOA model path")
    args = parser.parse_args()

    try:
        compare_models(args.image, args.ewoa, args.woa)
    except Exception as e:
        # print clean error to stderr
        print(f"❌ Error: {str(e)}", file=sys.stderr)
        sys.exit(1)
