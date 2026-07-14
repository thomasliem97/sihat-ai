"""OCR image-only lab PDFs for biomarker extraction."""

from __future__ import annotations

import logging
from functools import lru_cache
from typing import Any

logger = logging.getLogger("sihat-ai.lab-ocr")

_MIN_TEXT_LAYER = 40


def extract_lab_text(data: bytes) -> tuple[str, dict[str, Any]]:
    """Prefer embedded text; fall back to page-render OCR."""
    meta: dict[str, Any] = {"source": None, "pages": 0, "ocr_engine": None}

    text = _embedded_text(data)
    if len(text.strip()) >= _MIN_TEXT_LAYER:
        meta["source"] = "text_layer"
        meta["pages"] = text.count("\f") + 1 if text else 0
        return text.strip(), meta

    ocr_text, ocr_meta = ocr_pdf_bytes(data)
    meta.update(ocr_meta)
    if ocr_text.strip():
        meta["source"] = "ocr"
        return ocr_text.strip(), meta

    meta["source"] = "empty"
    return "", meta


def ocr_pdf_bytes(data: bytes, *, max_pages: int = 4, scale: float = 2.0) -> tuple[str, dict[str, Any]]:
    meta: dict[str, Any] = {"pages": 0, "ocr_engine": None}
    try:
        import fitz  # PyMuPDF
    except ImportError as exc:
        raise RuntimeError("pymupdf required for lab PDF OCR") from exc

    doc = fitz.open(stream=data, filetype="pdf")
    meta["pages"] = min(doc.page_count, max_pages)
    parts: list[str] = []
    engine = None
    for i in range(meta["pages"]):
        pix = doc[i].get_pixmap(matrix=fitz.Matrix(scale, scale), alpha=False)
        import numpy as np

        img = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, pix.n)
        if pix.n == 4:
            img = img[:, :, :3]
        page_text, engine = _ocr_image(img)
        if page_text.strip():
            parts.append(page_text.strip())
    doc.close()
    meta["ocr_engine"] = engine
    return "\n".join(parts), meta


def _embedded_text(data: bytes) -> str:
    # pypdf
    try:
        from io import BytesIO

        from pypdf import PdfReader

        reader = PdfReader(BytesIO(data))
        parts = [(page.extract_text() or "") for page in reader.pages]
        text = "\n".join(parts).strip()
        if text:
            return text
    except Exception as exc:  # noqa: BLE001
        logger.info("pypdf extract skipped: %s", exc)

    # pymupdf text layer
    try:
        import fitz

        doc = fitz.open(stream=data, filetype="pdf")
        parts = [page.get_text() for page in doc]
        doc.close()
        return "\n".join(parts).strip()
    except Exception as exc:  # noqa: BLE001
        logger.info("pymupdf text layer skipped: %s", exc)

    return ""


def _ocr_image(img: Any) -> tuple[str, str | None]:
    text = _ocr_rapid(img)
    if text.strip():
        return text, "rapidocr"
    text = _ocr_tesseract(img)
    if text.strip():
        return text, "tesseract"
    return "", None


@lru_cache(maxsize=1)
def _rapid_engine() -> Any | None:
    try:
        from rapidocr import RapidOCR

        return RapidOCR()
    except Exception as exc:  # noqa: BLE001
        logger.info("RapidOCR unavailable: %s", exc)
        return None


def _ocr_rapid(img: Any) -> str:
    engine = _rapid_engine()
    if engine is None:
        return ""
    try:
        result = engine(img)
    except Exception as exc:  # noqa: BLE001
        logger.warning("RapidOCR failed: %s", exc)
        return ""

    txts = getattr(result, "txts", None)
    if txts:
        return "\n".join(str(t) for t in txts if t)

    if isinstance(result, (list, tuple)) and result:
        first = result[0]
        if isinstance(first, (list, tuple)) and len(first) >= 2 and isinstance(first[1], str):
            return "\n".join(str(row[1]) for row in first if row and len(row) >= 2)
        if isinstance(result, tuple) and len(result) >= 1 and result[0]:
            rows = result[0]
            return "\n".join(str(row[1]) for row in rows if row and len(row) >= 2)
    return ""


def _ocr_tesseract(img: Any) -> str:
    try:
        import pytesseract
        from PIL import Image
    except Exception:
        return ""
    try:
        pil = Image.fromarray(img)
        return pytesseract.image_to_string(pil) or ""
    except Exception as exc:  # noqa: BLE001
        logger.info("tesseract OCR skipped: %s", exc)
        return ""
