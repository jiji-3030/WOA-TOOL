#!/usr/bin/env python3
"""
cbis_overlay_from_upload.py — FINAL FIXED VERSION

This script:
  ✔ DOES NOT use the uploaded DICOM geometry.
  ✔ Always uses the TRUE CBIS full-resolution mammogram.
  ✔ Correctly finds MASS + CALC ROI masks from ANY manifest folder.
  ✔ Creates overlays identical to overlay_from_radiomics.py.
"""

from __future__ import annotations
import argparse
import os
import sys
import tempfile
import subprocess
import shlex
import glob
import numpy as np
import SimpleITK as sitk

# --------------------------------------------------------------------
# CONFIG
# --------------------------------------------------------------------
HERE = os.path.dirname(os.path.abspath(__file__))
DEFAULT_CBIS_ROOT = os.path.join("/Volumes", "JANICE", "cbis-ddsm-r", "data", "CBIS-DDSM-R")
DEFAULT_RADIOMICS_CSV = os.path.join(DEFAULT_CBIS_ROOT, "csv", "radiomics_test.csv")

# Try overlay renderer
HAS_OVERLAY = False
try:
    from overlay_roi import make_overlay
    HAS_OVERLAY = True
except Exception:
    HAS_OVERLAY = False


# --------------------------------------------------------------------
# Locate the manifest folder that contains THIS case
# --------------------------------------------------------------------
def _find_manifest_root(cbis_root, base_id):
    """
    Search ALL manifest-* folders and return the one that contains base_id_1.
    Works for MASS + CALC + TRAIN + TEST splits.
    """
    manifest_folders = [
        d for d in os.listdir(cbis_root)
        if d.startswith("manifest-") and os.path.isdir(os.path.join(cbis_root, d))
    ]

    if not manifest_folders:
        raise FileNotFoundError("No manifest-* folders found under CBIS root.")

    for folder in manifest_folders:
        manifest_path = os.path.join(cbis_root, folder, "CBIS-DDSM")
        roi_test = os.path.join(manifest_path, base_id + "_1")
        if os.path.isdir(roi_test):
            return manifest_path  # FOUND CORRECT MANIFEST!

    raise FileNotFoundError(
        f"ROI folder {base_id}_1 not found in ANY manifest folder.\n"
        f"Checked: {manifest_folders}"
    )


# --------------------------------------------------------------------
# Find CBIS full-resolution image (NOT the uploaded file)
# --------------------------------------------------------------------
def _find_full_image(cbis_root, base_id):
    img_root = os.path.join(cbis_root, "img", base_id)
    dcm_files = glob.glob(os.path.join(img_root, "**", "*.dcm"), recursive=True)
    if not dcm_files:
        raise FileNotFoundError(f"No CBIS full image found: img/{base_id}")
    return sorted(dcm_files)[0]


# --------------------------------------------------------------------
# Find ROI mask + crop (works for both MASS + CALC)
# --------------------------------------------------------------------
def _find_roi_files(manifest_root, base_id):
    roi_folder = os.path.join(manifest_root, base_id + "_1")
    if not os.path.isdir(roi_folder):
        raise FileNotFoundError(f"ROI folder missing: {roi_folder}")

    dcm_files = glob.glob(os.path.join(roi_folder, "**", "*.dcm"), recursive=True)
    if not dcm_files:
        raise FileNotFoundError(f"No ROI DICOM files under {roi_folder}")

    stats = []
    for fp in dcm_files:
        try:
            arr = sitk.GetArrayFromImage(sitk.ReadImage(fp))[0]
            nonzero = int(np.count_nonzero(arr))
            uniq = int(len(np.unique(arr)))
            stats.append((nonzero, uniq, fp))
        except:
            continue

    if not stats:
        raise RuntimeError("ROI DICOMs unreadable")

    # Mask is binary → unique values <= 2
    mask_candidates = [s for s in stats if s[1] <= 2]
    if not mask_candidates:
        mask_candidates = stats  # fallback

    mask_fp = sorted(mask_candidates, key=lambda x: x[0])[0][2]
    crop_fp = sorted(stats, key=lambda x: x[0], reverse=True)[0][2]

    return crop_fp, mask_fp


# --------------------------------------------------------------------
# Resample ROI mask to match full breast image geometry
# --------------------------------------------------------------------
def _resample(mask_img, ref_img):
    rf = sitk.ResampleImageFilter()
    rf.SetReferenceImage(ref_img)
    rf.SetInterpolator(sitk.sitkNearestNeighbor)
    rf.SetOutputPixelType(sitk.sitkUInt8)
    return rf.Execute(mask_img)


# --------------------------------------------------------------------
# Auto-detect base_id using match_radiomics_row
# --------------------------------------------------------------------
def detect_base_id(radiomics_csv, uploaded):
    import csv

    tmp = tempfile.NamedTemporaryFile(delete=False, suffix=".csv")
    tmp.close()

    cmd = (
        f"{shlex.quote(sys.executable)} -m woa_tool.match_radiomics_row "
        f"--uploaded {shlex.quote(uploaded)} "
        f"--radiomics {shlex.quote(radiomics_csv)} "
        f"--out {shlex.quote(tmp.name)}"
    )

    proc = subprocess.run(cmd, shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

    if proc.returncode != 0:
        raise RuntimeError("match_radiomics_row failed → cannot detect base_id")

    # Read result
    with open(tmp.name, "r", newline="") as f:
        reader = csv.reader(f)
        header = next(reader, [])
        row = next(reader, None)

    os.remove(tmp.name)

    if not row:
        raise RuntimeError("match_radiomics_row produced empty output")

    # Extract base_id from image_path
    img_idx = None
    for i, h in enumerate(header):
        if h.lower() in ("image_file_path", "image"):
            img_idx = i
            break
    if img_idx is None:
        img_idx = len(row) - 1

    image_rel = row[img_idx]
    return image_rel.split("/")[0]


# --------------------------------------------------------------------
# MAIN
# --------------------------------------------------------------------
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--uploaded", required=True)
    parser.add_argument("--radiomics", default=DEFAULT_RADIOMICS_CSV)
    parser.add_argument("--base-id", default=None)
    parser.add_argument("--out", required=True)
    parser.add_argument("--preview-only", action="store_true")
    args = parser.parse_args()

    uploaded = args.uploaded
    out = args.out
    cbis_root = DEFAULT_CBIS_ROOT

    # -------------------------
    # PREVIEW MODE
    # -------------------------
    if args.preview_only:
        img = sitk.ReadImage(uploaded)
        arr = sitk.GetArrayFromImage(img)[0]
        mn, mx = arr.min(), arr.max()
        arr_norm = ((arr - mn) / (mx - mn or 1) * 255).astype(np.uint8)

        from PIL import Image
        Image.fromarray(arr_norm).save(out)
        print(out)
        return

    # -------------------------
    # DETECT base_id
    # -------------------------
    base_id = args.base_id or detect_base_id(args.radiomics, uploaded)

    # -------------------------
    # LOAD TRUE CBIS FULL IMAGE
    # -------------------------
    full_path = _find_full_image(cbis_root, base_id)
    full_img = sitk.ReadImage(full_path)

    # -------------------------
    # FIND CORRECT MANIFEST WITH ROI
    # -------------------------
    manifest_root = _find_manifest_root(cbis_root, base_id)

    # -------------------------
    # FIND ROI MASK
    # -------------------------
    crop_fp, mask_fp = _find_roi_files(manifest_root, base_id)
    mask_img = sitk.ReadImage(mask_fp)
    mask_rs = _resample(mask_img, full_img)

    # -------------------------
    # GENERATE OVERLAY
    # -------------------------
    if HAS_OVERLAY:
        tmp_mask = out + ".tmp_mask.dcm"
        sitk.WriteImage(mask_rs, tmp_mask)
        make_overlay(full_path, tmp_mask, out)
        os.remove(tmp_mask)
    else:
        # fallback renderer
        arr = sitk.GetArrayFromImage(full_img)[0].astype(float)
        mn, mx = arr.min(), arr.max()
        base = (arr - mn) / (mx - mn or 1)
        mask = sitk.GetArrayFromImage(mask_rs)[0] > 0

        rgb = np.stack([base, base, base], axis=-1)
        rgb[mask, 0] = 1.0
        rgb[mask, 1] *= 0.3
        rgb[mask, 2] *= 0.3

        from matplotlib import pyplot as plt
        plt.imshow((rgb * 255).astype(np.uint8))
        plt.axis("off")
        plt.savefig(out, bbox_inches="tight", pad_inches=0)
        plt.close()

    print(out)


if __name__ == "__main__":
    main()
