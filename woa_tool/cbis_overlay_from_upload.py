#!/usr/bin/env python3
"""
cbis_overlay_from_upload.py

Given:
  --image   : path to an uploaded CBIS-DDSM mammogram (DICOM)
  --cbis-root : path to CBIS-DDSM-R data root (the one that has img/ and manifest-*/CBIS-DDSM)
  --out-dir: where to save the overlay PNG

This script will:

  1. Infer the CBIS base_id (e.g., "Calc-Test_P_00038_LEFT_CC") from
     the uploaded DICOM (metadata or path).
  2. Locate the corresponding ROI mask in the CBIS-DDSM-R manifest.
  3. Generate an overlay PNG (full mammogram + highlighted ROI).
  4. Print a JSON object describing the result.

Usage (example):

  python3 cbis_overlay_from_upload.py \\
      --image "/Volumes/JILLYBEAN/WOA-TOOL/php/test_uploads/whatever.dcm" \\
      --cbis-root "/Volumes/JILLYBEAN/cbis-ddsm-r/data/CBIS-DDSM-R" \\
      --out-dir "/Volumes/JILLYBEAN/WOA-TOOL/php/overlays"

"""

import argparse
import json
import os
import glob
import re
from typing import Optional, Tuple

import numpy as np
import SimpleITK as sitk


# ---------------------------------------------------------
# Helpers: CBIS layout
# ---------------------------------------------------------

def find_manifest_root(cbis_root: str) -> str:
    """
    Find the CBIS-DDSM manifest folder under cbis_root:

      cbis_root/
        img/
        manifest-xxxxxx/CBIS-DDSM/...

    Returns:
      /.../CBIS-DDSM
    """
    candidates = [
        d for d in os.listdir(cbis_root)
        if d.startswith("manifest-") and os.path.isdir(os.path.join(cbis_root, d))
    ]
    if not candidates:
        raise FileNotFoundError(
            f"No manifest-* directory found under {cbis_root}. "
            "Check that CBIS-DDSM-R is correctly downloaded."
        )
    manifest_dir = sorted(candidates)[0]
    return os.path.join(cbis_root, manifest_dir, "CBIS-DDSM")


def choose_cropped_and_mask_from_roi_series(roi_series_dir: str) -> Tuple[Optional[str], str]:
    """
    Inspect DICOMs inside ROI series dir and decide:

      - mask_path: the ROI mask (few nonzero pixels, low unique levels)
      - cropped_path: the cropped ROI (many nonzero pixels, richer gray-levels)

    Returns:
      (cropped_path or None, mask_path)
    """
    dcm_files = sorted(
        glob.glob(os.path.join(roi_series_dir, "**", "*.dcm"), recursive=True)
    )
    if not dcm_files:
        raise FileNotFoundError(f"No DICOMs found in ROI series: {roi_series_dir}")

    if len(dcm_files) == 1:
        # Only mask available
        return None, dcm_files[0]

    stats = []
    for fp in dcm_files:
        img = sitk.ReadImage(fp)
        arr = sitk.GetArrayFromImage(img)[0]
        nz = int(np.count_nonzero(arr))
        uniq = int(len(np.unique(arr)))
        stats.append((nz, uniq, fp))

    # Mask: minimal non-zero + minimal unique levels
    stats_sorted = sorted(stats, key=lambda t: (t[0], t[1]))
    mask_path = stats_sorted[0][2]

    # Cropped: maximal non-zero (for your reference, not required for the overlay)
    cropped_path = sorted(stats, key=lambda t: t[0], reverse=True)[0][2]

    return cropped_path, mask_path


def find_cropped_and_mask_for_base_id(manifest_root: str, base_id: str) -> Tuple[Optional[str], str]:
    """
    Under manifest_root = .../CBIS-DDSM, find the patient folder:

      manifest_root/<base_id>_1/...

    Then detect the ROI series and return (cropped_image, mask_image).
    """
    patient_root = os.path.join(manifest_root, base_id + "_1")
    if not os.path.isdir(patient_root):
        raise FileNotFoundError(f"Patient root does not exist: {patient_root}")

    series_dirs = [
        os.path.join(patient_root, d)
        for d in os.listdir(patient_root)
        if os.path.isdir(os.path.join(patient_root, d))
    ]
    if not series_dirs:
        raise FileNotFoundError(f"No series directories found under {patient_root}")

    roi_dirs = [d for d in series_dirs if "roi" in os.path.basename(d).lower()]
    if roi_dirs:
        roi_dir = roi_dirs[0]
    else:
        roi_dir = series_dirs[0]

    return choose_cropped_and_mask_from_roi_series(roi_dir)


# ---------------------------------------------------------
# Base ID inference from uploaded DICOM
# ---------------------------------------------------------

CBIS_BASEID_REGEX = re.compile(
    r"(Calc-(?:Training|Test)_P_\d+_[A-Z]+_(?:CC|MLO))|"
    r"(Mass-(?:Training|Test)_P_\d+_[A-Z]+_(?:CC|MLO))"
)


def infer_base_id_from_dicom(image_path: str) -> Optional[str]:
    """
    Try to recover CBIS base_id from DICOM metadata or path.

    Strategy (best-effort):

      1. Read DICOM with SimpleITK (no pixels, just header).
      2. Scan all metadata values; look for a substring that matches the
         known CBIS naming pattern via regex (Calc-... or Mass-...).
      3. If nothing is found, try to guess from the file path itself.

    Returns:
      base_id (e.g., "Calc-Test_P_00038_LEFT_CC") or None.
    """
    try:
        img = sitk.ReadImage(image_path)
        keys = img.GetMetaDataKeys()
        for k in keys:
            v = img.GetMetaData(k)
            m = CBIS_BASEID_REGEX.search(v)
            if m:
                # first non-None group
                for g in m.groups():
                    if g:
                        return g.strip()
    except Exception:
        # Fall through to path-based heuristic
        pass

    # Path-based heuristic: sometimes the upload still has a path segment
    # containing the base_id (e.g., user uploaded from a CBIS folder copy).
    path_parts = os.path.normpath(image_path).split(os.sep)
    for part in path_parts:
        m = CBIS_BASEID_REGEX.search(part)
        if m:
            for g in m.groups():
                if g:
                    return g.strip()

    return None


# ---------------------------------------------------------
# Overlay generator
# ---------------------------------------------------------

def generate_overlay_for_upload(
    image_path: str,
    cbis_root: str,
    out_dir: str,
) -> Tuple[bool, str, Optional[str], Optional[str]]:
    """
    Main logic:

      - infer base_id from uploaded DICOM
      - locate mask in CBIS-DDSM-R
      - create overlay PNG on top of the *uploaded* image

    Returns:
      (ok, message, overlay_png_path or None, base_id or None)
    """
    if not os.path.isfile(image_path):
        return False, f"Image not found: {image_path}", None, None

    cbis_root = os.path.abspath(cbis_root)
    if not os.path.isdir(cbis_root):
        return False, f"CBIS root not found: {cbis_root}", None, None

    base_id = infer_base_id_from_dicom(image_path)
    if not base_id:
        return False, "Could not infer CBIS base_id from DICOM metadata/path.", None, None

    try:
        manifest_root = find_manifest_root(cbis_root)
        _, mask_path = find_cropped_and_mask_for_base_id(manifest_root, base_id)
    except Exception as e:
        return False, f"Failed to locate ROI mask: {e}", None, base_id

    # Read uploaded image + mask and check shapes
    try:
        img_itk = sitk.ReadImage(image_path)
        mask_itk = sitk.ReadImage(mask_path)

        img_arr = sitk.GetArrayFromImage(img_itk)[0]
        mask_arr = sitk.GetArrayFromImage(mask_itk)[0]

        if img_arr.shape != mask_arr.shape:
            # This usually means the uploaded file is a CROPPED image (ROI),
            # but the mask is in FULL image space.
            return False, (
                f"Image and mask shapes differ: image={img_arr.shape}, mask={mask_arr.shape}. "
                "This usually means the uploaded file is a cropped ROI rather than the full mammogram. "
                "Please upload the FULL mammogram DICOM that matches the CBIS ROI mask."
            ), None, base_id

    except Exception as e:
        return False, f"Failed to read image/mask: {e}", None, base_id

    # Prepare RGB overlay array
    img_norm = img_arr.astype(np.float32)
    img_norm -= img_norm.min()
    if img_norm.max() > 0:
        img_norm /= img_norm.max()
    img_uint8 = (img_norm * 255.0).astype(np.uint8)

    # Create 3-channel RGB
    rgb = np.stack([img_uint8] * 3, axis=-1)  # (H, W, 3)

    # Simple red overlay wherever mask > 0
    mask_bin = mask_arr > 0
    # Blend: red = max, green/blue slightly dimmed
    rgb[mask_bin, 0] = 255          # R
    rgb[mask_bin, 1] = rgb[mask_bin, 1] // 3   # G
    rgb[mask_bin, 2] = rgb[mask_bin, 2] // 3   # B

    # Save as PNG via SimpleITK (convert back to ITK image)
    rgb_itk = sitk.GetImageFromArray(rgb)
    # Copy spacing/origin/direction from original for consistency (optional)
    rgb_itk.SetSpacing(img_itk.GetSpacing())
    rgb_itk.SetOrigin(img_itk.GetOrigin())
    rgb_itk.SetDirection(img_itk.GetDirection())

    os.makedirs(out_dir, exist_ok=True)
    base_name = os.path.splitext(os.path.basename(image_path))[0]
    out_png = os.path.join(out_dir, f"{base_name}_overlay.png")
    sitk.WriteImage(rgb_itk, out_png)

    return True, "Overlay created successfully.", out_png, base_id


# ---------------------------------------------------------
# CLI entry
# ---------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Generate CBIS-DDSM ROI overlay for an uploaded DICOM."
    )
    parser.add_argument("--image", required=True, help="Path to uploaded mammogram DICOM")
    parser.add_argument("--cbis-root", required=True,
                        help="CBIS-DDSM-R root (the folder containing img/ and manifest-*/CBIS-DDSM/)")
    parser.add_argument("--out-dir", required=True,
                        help="Directory where overlay PNG should be written")
    args = parser.parse_args()

    ok, msg, overlay_path, base_id = generate_overlay_for_upload(
        image_path=args.image,
        cbis_root=args.cbis_root,
        out_dir=args.out_dir,
    )

    print(json.dumps({
        "ok": ok,
        "message": msg,
        "uploaded_image": os.path.abspath(args.image),
        "cbis_root": os.path.abspath(args.cbis_root),
        "base_id": base_id,
        "overlay_path": (os.path.abspath(overlay_path) if overlay_path else None),
    }, indent=2))

    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
