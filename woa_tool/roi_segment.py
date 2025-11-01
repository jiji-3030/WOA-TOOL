# woa_tool/roi_segment.py
from __future__ import annotations
import os, hashlib
from dataclasses import dataclass
from typing import Tuple

import numpy as np
from skimage import io, color, util, morphology, measure, exposure
from skimage.transform import resize
from skimage.filters import threshold_otsu

@dataclass
class CropMeta:
    bbox: Tuple[int, int, int, int]   # (minr, minc, maxr, maxc) in original image coords
    scale_used: float                 # if we downscaled first
    pad: int
    ok: bool

def _safe_load_gray(path: str) -> np.ndarray:
    img = io.imread(path)
    if img.ndim == 3 and img.shape[-1] == 4:
        img = img[..., :3]
    if img.ndim == 3:
        img = color.rgb2gray(img)
    return util.img_as_float32(img)

def _stable_name(path: str) -> str:
    return hashlib.sha1(path.encode("utf-8")).hexdigest()

def segment_and_crop(image_path: str,
                     out_dir: str = "data/cache/crops",
                     downscale_max: int = 1600,
                     min_region: int = 800,    # reject tiny specks
                     pad: int = 16) -> tuple[str, CropMeta]:
    """
    Returns a path to a saved crop (PNG) that focuses on the largest bright region (Otsu),
    plus metadata. If segmentation fails, we fall back to CLAHE + center crop.
    """
    os.makedirs(out_dir, exist_ok=True)
    base = _stable_name(image_path)
    out_path = os.path.join(out_dir, f"{base}.png")

    img = _safe_load_gray(image_path)
    h, w = img.shape
    scale_used = 1.0
    if max(h, w) > downscale_max:
        s = downscale_max / float(max(h, w))
        img = resize(img, (int(h*s), int(w*s)), order=1, anti_aliasing=True, preserve_range=True).astype(np.float32)
        scale_used = s

    # gentle contrast normalization so thresholding is more reliable
    img_eq = exposure.equalize_adapthist(img, clip_limit=0.01)

    ok = False
    bbox = (0, 0, img.shape[0], img.shape[1])
    try:
        thr = threshold_otsu(img_eq)
        mask = img_eq > thr
        mask = morphology.remove_small_objects(mask, min_size=min_region)
        mask = morphology.binary_closing(mask, morphology.disk(3))

        lab = measure.label(mask)
        regs = measure.regionprops(lab)
        if regs:
            r = max(regs, key=lambda rr: rr.area)
            minr, minc, maxr, maxc = r.bbox
            # pad (in resized coordinates)
            minr = max(0, minr - pad)
            minc = max(0, minc - pad)
            maxr = min(img.shape[0], maxr + pad)
            maxc = min(img.shape[1], maxc + pad)
            crop = img[minr:maxr, minc:maxc]
            ok = crop.size > 0
            if ok:
                io.imsave(out_path, util.img_as_ubyte(crop), check_contrast=False)
                # map bbox back to original image coordinates
                inv = 1.0 / scale_used
                bbox = (int(minr*inv), int(minc*inv), int(maxr*inv), int(maxc*inv))
        if not ok:
            raise RuntimeError("No valid region")
    except Exception:
        # Fallback: center crop with mild CLAHE (keeps things deterministic)
        side = int(0.6 * min(img.shape))
        rs, cs = img.shape
        r0 = max(0, rs//2 - side//2)
        c0 = max(0, cs//2 - side//2)
        crop = img[r0:r0+side, c0:c0+side]
        io.imsave(out_path, util.img_as_ubyte(crop), check_contrast=False)
        inv = 1.0 / scale_used
        bbox = (int(r0*inv), int(c0*inv), int((r0+side)*inv), int((c0+side)*inv))
        ok = True

    meta = CropMeta(bbox=bbox, scale_used=scale_used, pad=pad, ok=ok)
    return out_path, meta
