import pandas as pd
import os

CSV = "/Volumes/JANICE/cbis-ddsm-r/data/CBIS-DDSM-R/csv/radiomics_test.csv"

# 🔑 IMPORTANT: include /img
IMG_ROOT = "/Volumes/JANICE/cbis-ddsm-r/data/CBIS-DDSM-R/img"

OUT = "/Volumes/JANICE/WOA-TOOL/data/image_list_from_radiomics.txt"

df = pd.read_csv(CSV)

if "image_file_path" not in df.columns:
    raise RuntimeError("❌ image_file_path column not found")

paths = df["image_file_path"].dropna().astype(str).unique()

written = 0
missing = 0

with open(OUT, "w") as f:
    for p in paths:
        # Build absolute path
        full = os.path.join(IMG_ROOT, p)

        if os.path.exists(full) and not os.path.basename(full).startswith("._"):
            f.write(full + "\n")
            written += 1
        else:
            missing += 1

print(f"✅ Wrote {written} valid radiomics-linked image paths")
print(f"⚠️ Missing paths (not found on disk): {missing}")
print(f"📄 Output file: {OUT}")
