import subprocess
import json
import csv
import os
import sys

# --------------------------------------------------
# CONFIG
# --------------------------------------------------
N_RUNS = 2  # number of independent runs

# --------------------------------------------------
# Project root (WOA-TOOL)
# --------------------------------------------------
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# --------------------------------------------------
# Paths (ABSOLUTE & SAFE)
# --------------------------------------------------
IMAGE_LIST = os.path.join(BASE_DIR, "data/image_list_from_radiomics.txt")

# radiomics CSV (external dataset repo)
RADIOMICS = "/Volumes/JANICE/cbis-ddsm-r/data/CBIS-DDSM-R/csv/radiomics_test.csv"

WOA_MODEL = os.path.join(BASE_DIR, "models/model_woa_radiomics.json")
EWOA_MODEL = os.path.join(BASE_DIR, "models/model_ewoa_radiomics.json")

COMPARE_SCRIPT = os.path.join(
    BASE_DIR, "woa_tool", "compare_predict_radiomics.py"
)

OUT_CSV = os.path.join(BASE_DIR, "results/batch_predictions_per_run.csv")
os.makedirs(os.path.dirname(OUT_CSV), exist_ok=True)

# --------------------------------------------------
# CSV fields (RUN-AWARE)
# --------------------------------------------------
FIELDNAMES = [
    "run_id",

    "image_name",
    "image_path",
    "ground_truth",

    "woa_prediction",
    "woa_time",
    "woa_selected_features_model",
    "woa_selected_features_image",
    "woa_correct",

    "ewoa_prediction",
    "ewoa_time",
    "ewoa_selected_features_model",
    "ewoa_selected_features_image",
    "ewoa_correct"
]

# --------------------------------------------------
# Batch execution
# --------------------------------------------------
with open(OUT_CSV, "w", newline="") as f:
    writer = csv.DictWriter(f, fieldnames=FIELDNAMES)
    writer.writeheader()

    for run_id in range(1, N_RUNS + 1):
        print(f"\n🚀 STARTING RUN {run_id}/{N_RUNS}")

        # Environment (for reproducibility)
        env = os.environ.copy()
        env["PYTHONPATH"] = BASE_DIR
        env["WOA_RUN_ID"] = str(run_id)  # seed hook

        with open(IMAGE_LIST) as imgs:
            for i, img in enumerate(imgs, 1):
                img = img.strip()
                if not img:
                    continue

                # Skip macOS AppleDouble files
                if os.path.basename(img).startswith("._"):
                    continue

                print(f"[Run {run_id}] [{i}] Processing {img}")

                cmd = [
                    sys.executable,
                    COMPARE_SCRIPT,
                    "--image", img,
                    "--radiomics", RADIOMICS,
                    "--woa", WOA_MODEL,
                    "--ewoa", EWOA_MODEL
                ]

                proc = subprocess.run(
                    cmd,
                    capture_output=True,
                    text=True,
                    env=env
                )

                if proc.returncode != 0:
                    print("⚠️ Skipped (error or missing radiomics)")
                    if proc.stderr.strip():
                        print(proc.stderr.strip())
                    continue

                try:
                    payload = json.loads(proc.stdout)
                    if not payload.get("ok"):
                        print("⚠️ Prediction failed:", payload)
                        continue
                    result = payload["result"]
                except json.JSONDecodeError:
                    print("⚠️ Invalid JSON output")
                    print(proc.stdout)
                    continue

                row = {
                    "run_id": run_id,

                    "image_name": os.path.basename(img),
                    "image_path": img,
                    "ground_truth": result["Ground Truth"],

                    "woa_prediction": result["WOA"]["Prediction"],
                    "woa_time": result["WOA"]["Execution Time"],
                    "woa_selected_features_model": result["WOA"].get("Selected Features (Model)"),
                    "woa_selected_features_image": result["WOA"].get("Selected Features (Image)"),
                    "woa_correct": result["WOA"]["Correct Classification"],

                    "ewoa_prediction": result["EWOA"]["Prediction"],
                    "ewoa_time": result["EWOA"]["Execution Time"],
                    "ewoa_selected_features_model": result["EWOA"].get("Selected Features (Model)"),
                    "ewoa_selected_features_image": result["EWOA"].get("Selected Features (Image)"),
                    "ewoa_correct": result["EWOA"]["Correct Classification"]
                }

                writer.writerow(row)

print("\n✅ Batch prediction complete.")
print(f"📄 Results saved to: {OUT_CSV}")
