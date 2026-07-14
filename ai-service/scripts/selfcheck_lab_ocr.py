#!/usr/bin/env python3
"""Runnable check: image-only lab PDFs must OCR into real biomarkers.

  cd ai-service && python scripts/selfcheck_lab_ocr.py
"""

from __future__ import annotations

import sys
from pathlib import Path

SERVICE_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(SERVICE_ROOT))

from app.api import _regex_parse_lab  # noqa: E402
from app.lab_ocr import extract_lab_text  # noqa: E402


def main() -> None:
    from app.lab_ocr import lab_page_jpegs_b64, ocr_image_bytes

    lab_dir = SERVICE_ROOT.parent / "docs" / "testing-dataset" / "lab-report"
    pdfs = sorted(lab_dir.glob("*.pdf"))
    assert pdfs, f"no lab PDFs in {lab_dir}"

    for pdf in pdfs:
        data = pdf.read_bytes()
        text, meta = extract_lab_text(data)
        assert meta.get("source") == "ocr", (pdf.name, meta)
        assert "Haemoglobin" in text or "Hemoglobin" in text or "ALT" in text or "Creatinine" in text, (
            pdf.name,
            text[:400],
        )
        parsed = _regex_parse_lab(text)
        assert parsed["biomarkers"], (pdf.name, text[:500], parsed)
        names = {b["name"] for b in parsed["biomarkers"]}
        print(f"OK {pdf.name} engine={meta.get('ocr_engine')} biomarkers={sorted(names)}")

    # Photo-of-report path: JPEG bytes must OCR without requiring a PDF.
    pages = lab_page_jpegs_b64(pdfs[0].read_bytes(), max_pages=1)
    assert pages, "expected JPEG page for vision/photo path"
    import base64

    jpeg = base64.b64decode(pages[0])
    img_text, img_meta = ocr_image_bytes(jpeg)
    assert img_text.strip(), img_meta
    assert extract_lab_text(jpeg)[0].strip(), "image magic path empty"
    print(f"OK jpeg-photo-path engine={img_meta.get('ocr_engine')} chars={len(img_text)}")

    print("ALL LAB OCR SELFCHECKS PASSED")


if __name__ == "__main__":
    main()
