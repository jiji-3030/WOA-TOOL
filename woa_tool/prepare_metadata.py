# woa_tool/prepare_metadata.py

import pandas as pd
from sklearn.model_selection import train_test_split

input_file = "data/Info.txt"
metadata_file = "data/metadata.csv"
train_file = "data/train.csv"
test_file = "data/test.csv"

labels = {}

with open(input_file, "r") as f:
    for line in f:
        parts = line.strip().split()
        if len(parts) < 2:
            continue
        image_id = parts[0]
        cls = parts[3] if len(parts) > 3 else "N"

        # First time seeing this image
        if image_id not in labels:
            labels[image_id] = cls
        else:
            # Priority: Malignant > Benign > Normal
            if cls == "M":
                labels[image_id] = "M"
            elif cls == "B" and labels[image_id] == "N":
                labels[image_id] = "B"
            # If already M, do nothing

# Convert to DataFrame
df = pd.DataFrame(list(labels.items()), columns=["ImageID", "Class"])

# 🔴 Skip Normal cases entirely
df = df[df["Class"].isin(["B", "M"])].reset_index(drop=True)

# Save metadata.csv (only B/M images)
df.to_csv(metadata_file, index=False)
print(f"Saved {metadata_file} with {len(df)} images (Benign/Malignant only).")

# Train/Test split 80/20, stratified by class
train_df, test_df = train_test_split(
    df,
    test_size=0.2,
    stratify=df["Class"],
    random_state=42
)

train_df.to_csv(train_file, index=False)
test_df.to_csv(test_file, index=False)

print(f"Training set: {len(train_df)} images → {train_file}")
print(f"Testing set: {len(test_df)} images → {test_file}")
