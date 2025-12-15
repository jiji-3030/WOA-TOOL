#!/usr/bin/env python3
"""
FINAL FIXED VERSION FOR BOTH CALC + MASS

Matching priority:
1. PatientID (0010|0010)
2. StudyInstanceUID (0020|000d)
3. SeriesInstanceUID (0020|000e)
4. SOPInstanceUID (0008|0018)
"""

import argparse
import sys
import os
import csv
import SimpleITK as sitk


def extract_ids(dcm_path):
    """Extract PatientID + UIDs from metadata."""
    img = sitk.ReadImage(dcm_path)

    ids = set()

    # PatientID (VERY IMPORTANT)
    if img.HasMetaDataKey("0010|0010"):
        pid = img.GetMetaData("0010|0010").strip()
        if pid:
            ids.add(pid)

    # StudyInstanceUID
    if img.HasMetaDataKey("0020|000d"):
        ids.add(img.GetMetaData("0020|000d"))

    # SeriesInstanceUID
    if img.HasMetaDataKey("0020|000e"):
        ids.add(img.GetMetaData("0020|000e"))

    # SOPInstanceUID
    if img.HasMetaDataKey("0008|0018"):
        ids.add(img.GetMetaData("0008|0018"))

    # Remove empty
    return {x for x in ids if x and len(x) > 5}


def match_row(radiomics_csv, candidate_ids):
    """Find radiomics row by any matching ID."""
    with open(radiomics_csv, "r", encoding="utf-8") as f:
        reader = csv.reader(f)
        header = next(reader)

        # find image_file_path column
        img_idx = None
        for i, c in enumerate(header):
            if c.lower() in ("image_file_path", "image_file", "image"):
                img_idx = i
                break

        if img_idx is None:
            raise RuntimeError("No image path column found")

        # Iterate rows
        for row in reader:
            img_path = row[img_idx]

            for cid in candidate_ids:
                if cid in img_path:
                    return header, row

    return None, None


def write_csv(out_path, header, row):
    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    with open(out_path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerow(row)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--uploaded", required=True)
    ap.add_argument("--radiomics", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()

    if not os.path.isfile(args.uploaded):
        print("Uploaded DICOM not found", file=sys.stderr)
        sys.exit(2)

    if not os.path.isfile(args.radiomics):
        print("Radiomics CSV not found", file=sys.stderr)
        sys.exit(2)

    # Extract IDs from metadata
    candidate_ids = extract_ids(args.uploaded)

    if not candidate_ids:
        print("No PatientID or UID metadata found", file=sys.stderr)
        sys.exit(2)

    # Match row
    header, row = match_row(args.radiomics, candidate_ids)

    if row is None:
        print("No match found in radiomics CSV", file=sys.stderr)
        sys.exit(2)

    # Write
    write_csv(args.out, header, row)

    # Print detected base_id for caller
    img_idx = None
    for i, c in enumerate(header):
        if c.lower().startswith("image"):
            img_idx = i
            break

    base_id = row[img_idx].split("/")[0]
    print(base_id)

    sys.exit(0)


if __name__ == "__main__":
    main()
