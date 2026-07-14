"""Decode DICOM bytes to RGB PIL images for MedGemma / montage.

ponytail: single-frame + multi-frame mid-slice window; not a full PACS viewer.
Ceiling: compressed transfer syntaxes need pylibjpeg plugins; uncompressed works with pydicom+numpy only.
"""

from __future__ import annotations

import logging
from io import BytesIO
from typing import Any

logger = logging.getLogger("sihat-ai.dicom")


def looks_like_dicom(data: bytes, filename: str = "", mime: str = "") -> bool:
    name = (filename or "").lower()
    mime_l = (mime or "").lower()
    if name.endswith(".dcm") or "dicom" in mime_l:
        return True
    if len(data) >= 132 and data[128:132] == b"DICM":
        return True
    # Some Implicit VR files omit the preamble
    return b"DICM" in data[:512]


def decode_dicom_frames(
    data: bytes,
    *,
    max_frames: int = 8,
) -> tuple[list[Any], dict[str, Any]]:
    """
    Return RGB PIL Images + meta.

    Raises RuntimeError if pydicom missing or pixel data cannot be decoded.
    """
    try:
        import numpy as np
        import pydicom
        from PIL import Image
    except ImportError as exc:
        raise RuntimeError(
            "DICOM decode requires pydicom, numpy, and Pillow "
            "(pip install -r requirements.txt from ai-service/)"
        ) from exc

    meta: dict[str, Any] = {
        "source": "dicom",
        "modality_tag": None,
        "transfer_syntax": None,
        "slice_count": 0,
        "used_slices": [],
        "note": "ponytail: mid-slice DICOM decode (max 8); not a full 3D viewer",
    }

    ds = pydicom.dcmread(BytesIO(data), force=True)
    meta["modality_tag"] = str(getattr(ds, "Modality", "") or "") or None
    try:
        meta["transfer_syntax"] = str(ds.file_meta.TransferSyntaxUID)
    except Exception:  # noqa: BLE001
        meta["transfer_syntax"] = None

    try:
        arr = ds.pixel_array
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError(f"DICOM pixel decode failed: {exc}") from exc

    frames = _normalize_frames(arr)
    meta["slice_count"] = len(frames)
    if not frames:
        raise RuntimeError("DICOM contained no pixel frames")

    start = max(0, len(frames) // 2 - max_frames // 2)
    chosen_idx = list(range(start, min(start + max_frames, len(frames))))
    meta["used_slices"] = chosen_idx

    images: list[Any] = []
    for i in chosen_idx:
        rgb = _frame_to_rgb(frames[i], ds)
        images.append(Image.fromarray(rgb, mode="RGB"))

    return images, meta


def dicom_to_jpeg_bytes(data: bytes, *, quality: int = 90) -> tuple[bytes, dict[str, Any]]:
    """Decode DICOM and encode a single JPEG (montage if multi-frame)."""
    from io import BytesIO

    images, meta = decode_dicom_frames(data)
    if len(images) == 1:
        buf = BytesIO()
        images[0].save(buf, format="JPEG", quality=quality)
        return buf.getvalue(), meta

    # Lazy import montage helper from caller context avoided — simple horizontal strip
    widths, heights = zip(*(im.size for im in images), strict=True)
    montage_w = sum(widths)
    montage_h = max(heights)
    from PIL import Image

    canvas = Image.new("RGB", (montage_w, montage_h), (0, 0, 0))
    x = 0
    for im in images:
        canvas.paste(im, (x, 0))
        x += im.size[0]
    buf = BytesIO()
    canvas.save(buf, format="JPEG", quality=quality)
    meta["montage"] = "row"
    return buf.getvalue(), meta


def _normalize_frames(arr: Any) -> list[Any]:
    import numpy as np

    a = np.asarray(arr)
    if a.ndim == 2:
        return [a]
    if a.ndim == 3:
        # (frames, H, W) or (H, W, channels)
        if a.shape[-1] in (3, 4) and a.shape[0] > 4:
            # ambiguous; prefer frames-first when first dim is smallish
            if a.shape[0] <= 512 and a.shape[0] < a.shape[1]:
                return [a[i] for i in range(a.shape[0])]
            return [a]
        if a.shape[-1] in (3, 4):
            return [a]
        return [a[i] for i in range(a.shape[0])]
    if a.ndim == 4:
        # (frames, H, W, C)
        return [a[i] for i in range(a.shape[0])]
    return [a.reshape(a.shape[-2], a.shape[-1])]


def _frame_to_rgb(frame: Any, ds: Any) -> Any:
    import numpy as np

    a = np.asarray(frame)
    if a.ndim == 3 and a.shape[-1] >= 3:
        rgb = a[..., :3]
        if rgb.dtype != np.uint8:
            rgb = _scale_to_uint8(rgb)
        return np.ascontiguousarray(rgb)

    img = a.astype(np.float32)
    center = _first_float(getattr(ds, "WindowCenter", None))
    width = _first_float(getattr(ds, "WindowWidth", None))
    if center is not None and width is not None and width > 0:
        lo = center - width / 2.0
        hi = center + width / 2.0
        img = np.clip(img, lo, hi)

    photo = str(getattr(ds, "PhotometricInterpretation", "MONOCHROME2") or "MONOCHROME2")
    scaled = _scale_to_uint8(img)
    if photo.upper() == "MONOCHROME1":
        scaled = 255 - scaled
    return np.stack([scaled, scaled, scaled], axis=-1)


def _first_float(value: Any) -> float | None:
    if value is None:
        return None
    try:
        if hasattr(value, "__iter__") and not isinstance(value, (str, bytes)):
            value = value[0]
        return float(value)
    except Exception:  # noqa: BLE001
        return None


def _scale_to_uint8(img: Any) -> Any:
    import numpy as np

    a = np.asarray(img, dtype=np.float32)
    lo = float(np.min(a))
    hi = float(np.max(a))
    if hi <= lo:
        return np.zeros(a.shape, dtype=np.uint8)
    return ((a - lo) / (hi - lo) * 255.0).astype(np.uint8)
