import os, shutil, pandas as pd

# Paths
test_file = "data/test.csv"
test_images_dir = "data/test_images"

# Load your existing test.csv
test_df = pd.read_csv(test_file)
os.makedirs(test_images_dir, exist_ok=True)

# Step 8: Copy test images into test_images/ with unique names
copied = 0
for i, row in test_df.reset_index(drop=True).iterrows():
    src = row["image_path"]
    if not os.path.exists(src):
        continue
    base = os.path.basename(src)
    name, ext = os.path.splitext(base)
    safe_pid = str(row["patient_id"]).replace("/", "_")
    dst = os.path.join(test_images_dir, f"{safe_pid}__{i:04d}__{name}{ext}")
    shutil.copy(src, dst)
    copied += 1

print(f"📂 Copied {copied} test images into {test_images_dir}")

# Optional sanity check
print("✅ Files in test_images:",
      len([f for f in os.listdir(test_images_dir) if f.lower().endswith(('.jpg','.jpeg','.png'))]))
