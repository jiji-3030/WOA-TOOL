import os
import shutil
import pandas as pd

TEST_CSV = "data/test.csv"
DEST_DIR = "data/test_images"

def main():
    if not os.path.exists(TEST_CSV):
        raise SystemExit(f"❌ Missing {TEST_CSV}")

    os.makedirs(DEST_DIR, exist_ok=True)

    df = pd.read_csv(TEST_CSV)
    if not {"patient_id", "Class", "image_path"}.issubset(df.columns):
        raise SystemExit("❌ test.csv must have columns: patient_id, Class, image_path")

    copied = 0
    skipped = []
    missing = []

    seen = set(os.listdir(DEST_DIR))  # existing files in case you already populated the folder

    for _, row in df.iterrows():
        src = str(row["image_path"])
        base = os.path.basename(src)

        if not os.path.exists(src):
            missing.append(src)
            continue

        dst = os.path.join(DEST_DIR, base)

        # If a file with the same basename already exists, skip to avoid collisions
        if base in seen:
            skipped.append(base)
            continue

        shutil.copy2(src, dst)
        seen.add(base)
        copied += 1

    print(f"📂 Destination: {DEST_DIR}")
    print(f"✅ Copied: {copied}")
    print(f"⚠️ Skipped (duplicate basenames): {len(skipped)}")
    if skipped:
        print("   e.g.", skipped[:10], ("..." if len(skipped) > 10 else ""))

    print(f"❗ Missing source files: {len(missing)}")
    if missing:
        print("   e.g.", missing[:10], ("..." if len(missing) > 10 else ""))

if __name__ == "__main__":
    main()
