#!/usr/bin/env python3
"""Runnable check: DICOM decode must yield RGB + JPEG. Fails non-zero if broken.

  cd ai-service && python scripts/selfcheck_dicom.py
"""

from __future__ import annotations

import sys
from io import BytesIO
from pathlib import Path

SERVICE_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(SERVICE_ROOT))

from app.dicom import decode_dicom_frames, dicom_to_jpeg_bytes, looks_like_dicom  # noqa: E402


def _synthetic_ct() -> bytes:
    import numpy as np
    import pydicom
    from pydicom.dataset import Dataset, FileDataset
    from pydicom.uid import ExplicitVRLittleEndian, SecondaryCaptureImageStorage, generate_uid

    file_meta = Dataset()
    file_meta.MediaStorageSOPClassUID = SecondaryCaptureImageStorage
    file_meta.MediaStorageSOPInstanceUID = generate_uid()
    file_meta.TransferSyntaxUID = ExplicitVRLittleEndian
    ds = FileDataset("x.dcm", {}, file_meta=file_meta, preamble=b"\0" * 128)
    ds.SOPClassUID = file_meta.MediaStorageSOPClassUID
    ds.SOPInstanceUID = file_meta.MediaStorageSOPInstanceUID
    ds.Modality = "CT"
    ds.SamplesPerPixel = 1
    ds.PhotometricInterpretation = "MONOCHROME2"
    ds.Rows = 32
    ds.Columns = 32
    ds.BitsAllocated = 16
    ds.BitsStored = 16
    ds.HighBit = 15
    ds.PixelRepresentation = 0
    ds.PixelData = (np.arange(32 * 32, dtype=np.uint16) * 50).tobytes()
    buf = BytesIO()
    ds.save_as(buf)
    return buf.getvalue()


def main() -> None:
    samples = list((SERVICE_ROOT.parent / "docs" / "testing-dataset").rglob("*.dcm"))
    payloads: list[tuple[str, bytes]] = [(p.name, p.read_bytes()) for p in samples]
    payloads.append(("synthetic-ct.dcm", _synthetic_ct()))

    assert payloads, "no DICOM payloads to check"

    for name, data in payloads:
        assert looks_like_dicom(data, name, "application/dicom"), name
        images, meta = decode_dicom_frames(data)
        assert images and images[0].mode == "RGB", (name, meta)
        jpeg, _ = dicom_to_jpeg_bytes(data)
        assert jpeg[:2] == b"\xff\xd8", name
        print(f"OK {name} slices={meta.get('slice_count')} tag={meta.get('modality_tag')} jpeg={len(jpeg)}")

    from app.api import _build_volume_montage

    for name, data in payloads[:3] if len(payloads) >= 3 else payloads:
        b64, meta = _build_volume_montage(data, name, "application/dicom")
        assert b64, (name, meta)
        assert meta.get("error") is None, (name, meta)
        import base64

        jpeg = base64.b64decode(b64)
        assert jpeg[:2] == b"\xff\xd8", (name, jpeg[:16], meta)
        print(f"OK montage {name} meta_slices={meta.get('slice_count')}")

    print("ALL DICOM SELFCHECKS PASSED")


if __name__ == "__main__":
    main()
