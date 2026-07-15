"""
SihatAI FastAPI — Laravel /api/v1/* contract (runs on Modal ASGI).

GPU work goes through MedGemmaModel / SttModel via modal.Cls.from_name.
Analyze jobs are spawned as run_analyze_job → signed webhook back to Laravel.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
import re
import tempfile
from enum import Enum
from typing import Any

import httpx
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("sihat-ai")

app = FastAPI(title="SihatAI AI Service", version="0.2.0")


def _env(name: str, default: str = "") -> str:
    return (os.getenv(name) or default).strip()


def _webhook_secret() -> str:
    return _env("SIHAT_AI_WEBHOOK_SECRET")


class Modality(str, Enum):
    xray = "xray"
    ct = "ct"
    mri = "mri"
    histopath = "histopath"
    dermatology = "dermatology"
    ophthalmology = "ophthalmology"
    lab_pdf = "lab_pdf"
    clinical_document = "clinical_document"
    unknown = "unknown"


class AnalyzeRequest(BaseModel):
    job_id: str
    record_id: int
    modality: Modality = Modality.unknown
    file_path: str = ""
    file_url: str | None = None
    file_b64: str | None = None
    language: str = "en"
    webhook_url: str | None = None
    mime_type: str = "application/octet-stream"
    original_filename: str = "file"
    route_confidence: float | None = None


class AnalyzeResponse(BaseModel):
    job_id: str
    status: str
    message: str


@app.get("/health")
def health() -> dict[str, str]:
    secret = _webhook_secret()
    lora = _env("SIHAT_AI_LORA_PATH")
    if not lora and os.path.isdir("/lora/adapter"):
        lora = "/lora/adapter"
    return {
        "status": "ok",
        "service": "sihat-ai",
        "inference": "modal",
        "build": _env("SIHAT_AI_BUILD") or "imaging-v5-20260715",
        "webhook_secret": "set" if secret else "missing",
        "adapter": f"configured:{lora}" if lora else "gpu-volume",
        "structurer": _env("OPENAI_STRUCTURE_MODEL") or "gpt-5.6-terra",
        "structure_effort": _env("OPENAI_STRUCTURE_EFFORT") or "high",
    }


@app.post("/api/v1/transcribe")
def transcribe_audio(payload: dict[str, Any]) -> dict[str, Any]:
    """STT — audio_b64 required."""
    audio_b64 = payload.get("audio_b64")
    if not audio_b64:
        raise HTTPException(status_code=422, detail="audio_b64 required")

    try:
        return _invoke(
            "/transcribe",
            {
                "audio_b64": audio_b64,
                "language": payload.get("language"),
            },
        )
    except Exception as exc:  # noqa: BLE001
        logger.warning("STT failed: %s", exc)
        raise HTTPException(status_code=502, detail=f"STT failed: {exc}") from exc


@app.post("/api/v1/analyze", response_model=AnalyzeResponse)
def analyze(request: AnalyzeRequest) -> AnalyzeResponse:
    import modal

    modal.Function.from_name("sihat-medgemma", "run_analyze_job").spawn(
        request.model_dump(mode="json")
    )
    return AnalyzeResponse(
        job_id=request.job_id,
        status="accepted",
        message="Analysis job queued",
    )



_CLASSIFY_MODALITIES = {
    "xray",
    "dermatology",
    "ct",
    "mri",
    "histopath",
    "ophthalmology",
    "lab_pdf",
    "clinical_document",
}


def _filename_modality_hints(name: str) -> dict[str, Any] | None:
    """Specific-first keyword routing; kept in sync with Laravel detectModality."""
    name = name.lower()
    if any(k in name for k in ("fundus", "retina", "ophthal", "cataract", "glaucoma", "eyepacs", "oct")):
        return {"modality": "ophthalmology", "confidence": 0.85}
    if any(k in name for k in ("derm", "skin", "lesion", "melanoma", "nevus", "isic", "dermos")):
        return {"modality": "dermatology", "confidence": 0.85}
    if any(k in name for k in ("histo", "pathology", "pathmnist", "wsi", "slide", "biopsy", "seminoma", "pcam")):
        return {"modality": "histopath", "confidence": 0.85}
    if any(k in name for k in ("hrct", "computed tomography", "computed_tomography")) or "ct" in name:
        return {"modality": "ct", "confidence": 0.85}
    if "mri" in name or "mr_" in name:
        return {"modality": "mri", "confidence": 0.85}
    if name.endswith(".zip") and ("ct" in name or "mri" in name):
        return {"modality": "mri" if "mri" in name else "ct", "confidence": 0.8}
    if any(k in name for k in ("xray", "x-ray", "cxr", "chest", "radiograph")):
        return {"modality": "xray", "confidence": 0.85}
    return None


@app.post("/api/v1/classify")
def classify_modality(payload: dict[str, Any]) -> dict[str, Any]:
    """Modality classify helper (filename hints, then MedGemma when confidence is low)."""
    mime = (payload.get("mime_type") or "").lower()
    name = (payload.get("original_filename") or "").lower()

    if "pdf" in mime or name.endswith(".pdf"):
        if any(
            k in name
            for k in ("discharge", "summary", "clinic", "consult", "progress", "note", "referral", "letter")
        ):
            return {"modality": "clinical_document", "confidence": 0.9}
        return {"modality": "lab_pdf", "confidence": 0.95}

    hinted = _filename_modality_hints(name)
    if hinted and float(hinted["confidence"]) >= 0.7:
        return hinted

    if payload.get("image_b64"):
        try:
            result = _invoke(
                "/classify",
                {
                    "image_b64": payload["image_b64"],
                    "mime_type": mime,
                },
            )
            modality = str(result.get("modality", "xray")).lower()
            if modality not in _CLASSIFY_MODALITIES:
                modality = "xray"
            confidence = float(result.get("confidence", 0.7))
            if modality == "xray" and str(result.get("modality", "")).lower() in {"other", ""}:
                confidence = min(confidence, 0.5)
            return {"modality": modality, "confidence": confidence}
        except Exception as exc:  # noqa: BLE001
            logger.warning("Classify failed: %s", exc)

    if hinted:
        return hinted

    return {"modality": "xray", "confidence": 0.55}


def _run_pipeline(request: AnalyzeRequest) -> None:
    try:
        modality = request.modality
        route_confidence = request.route_confidence
        name = (request.original_filename or "").lower()

        if modality == Modality.unknown:
            hinted = _filename_modality_hints(name)
            if hinted:
                modality = Modality(hinted["modality"])
                route_confidence = float(hinted["confidence"])

        should_classify = modality == Modality.unknown or (
            modality
            in {
                Modality.xray,
                Modality.dermatology,
                Modality.ct,
                Modality.mri,
                Modality.histopath,
                Modality.ophthalmology,
            }
            and (route_confidence is None or route_confidence < 0.7)
            and "pdf" not in (request.mime_type or "").lower()
            and not name.endswith(".zip")
        )

        if should_classify:
            import base64

            data = _download_bytes(request)
            image_b64 = None
            if data:
                try:
                    image_b64 = _vision_image_b64(
                        data,
                        request.original_filename or "",
                        request.mime_type or "",
                    )
                except Exception as exc:  # noqa: BLE001
                    logger.warning("Classify image prep failed: %s", exc)
                    image_b64 = base64.b64encode(data).decode("ascii")
            classified = classify_modality(
                {
                    "image_b64": image_b64,
                    "mime_type": request.mime_type,
                    "original_filename": request.original_filename,
                }
            )
            modality = Modality(classified["modality"])
            route_confidence = float(classified["confidence"])

        if modality == Modality.lab_pdf:
            result = _analyze_lab(request)
        elif modality == Modality.clinical_document:
            result = _analyze_clinical_document(request)
        elif modality == Modality.histopath:
            result = _analyze_histopath(request)
        elif modality in {Modality.ct, Modality.mri}:
            result = _analyze_volume(request, modality.value)
        elif modality == Modality.dermatology:
            result = _analyze_vision(request, "dermatology")
        elif modality == Modality.ophthalmology:
            result = _analyze_vision(request, "ophthalmology")
        elif modality in {Modality.xray, Modality.unknown}:
            result = _analyze_vision(request, "xray" if modality == Modality.unknown else modality.value)
        else:
            result = _analyze_vision(request, "imaging")

        _post_webhook(
            request,
            status="completed",
            result=result,
            detected_modality=modality.value,
            route_confidence=route_confidence,
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("Pipeline failed for job %s", request.job_id)
        _post_webhook(request, status="failed", error=str(exc))


def _analyze_volume(request: AnalyzeRequest, kind: str) -> dict[str, Any]:
    data = _download_bytes(request)
    name = (request.original_filename or "").lower()
    mime = (request.mime_type or "").lower()

    montage_b64, volume_meta = _build_volume_montage(data, name, mime)

    if not montage_b64:
        raise RuntimeError("Could not build volume montage for analysis")

    result = _invoke(
        "/analyze",
        {
            "image_b64": montage_b64,
            "modality": kind,
            "language": request.language,
            "job_id": request.job_id,
            "record_id": request.record_id,
        },
    )
    result["volume_meta"] = volume_meta
    return result


def _analyze_histopath(request: AnalyzeRequest) -> dict[str, Any]:
    data = _download_bytes(request)
    montage_b64, patch_meta = _build_histopath_patches(data)

    if not montage_b64:
        raise RuntimeError("Could not build histopath patches for analysis")

    result = _invoke(
        "/analyze",
        {
            "image_b64": montage_b64,
            "modality": "histopath",
            "language": request.language,
            "job_id": request.job_id,
            "record_id": request.record_id,
        },
    )
    result["patch_meta"] = patch_meta
    return result


def _build_volume_montage(
    data: bytes, filename: str, mime: str
) -> tuple[str | None, dict[str, Any]]:
    """Extract up to 8 mid slices from zip of images, DICOM, or pass-through single image."""
    import base64
    from io import BytesIO

    meta: dict[str, Any] = {
        "slice_count": 0,
        "used_slices": [],
        "note": "Mid-slice montage (max 8)",
    }

    try:
        from PIL import Image
    except ImportError:
        return (base64.b64encode(data).decode("ascii") if data else None, meta)

    images: list[Any] = []

    if filename.endswith(".zip") or "zip" in mime:
        import zipfile

        try:
            with zipfile.ZipFile(BytesIO(data)) as zf:
                names = sorted(
                    n
                    for n in zf.namelist()
                    if n.lower().endswith(
                        (".png", ".jpg", ".jpeg", ".bmp", ".tif", ".tiff", ".dcm")
                    )
                    and not n.startswith("__")
                )
                meta["slice_count"] = len(names)
                if not names:
                    return None, meta
                start = max(0, len(names) // 2 - 4)
                chosen = names[start : start + 8]
                meta["used_slices"] = list(range(start, start + len(chosen)))
                for n in chosen:
                    with zf.open(n) as fh:
                        raw = fh.read()
                        if n.lower().endswith(".dcm"):
                            from app.dicom import decode_dicom_frames

                            frames, dmeta = decode_dicom_frames(raw, max_frames=1)
                            images.extend(frames)
                            meta["dicom"] = dmeta
                        else:
                            images.append(Image.open(BytesIO(raw)).convert("RGB"))
        except Exception as exc:  # noqa: BLE001
            logger.warning("Zip volume extract failed: %s", exc)
            return None, meta
    else:
        from app.dicom import decode_dicom_frames, looks_like_dicom

        if looks_like_dicom(data, filename, mime):
            try:
                images, dmeta = decode_dicom_frames(data, max_frames=8)
                meta.update(dmeta)
            except Exception as exc:  # noqa: BLE001
                logger.warning("DICOM decode failed: %s", exc)
                meta["error"] = str(exc)
                return None, meta
        else:
            try:
                img = Image.open(BytesIO(data)).convert("RGB")
                images = [img]
                meta["slice_count"] = 1
                meta["used_slices"] = [0]
            except Exception as exc:  # noqa: BLE001
                logger.warning("Image open failed: %s", exc)
                return None, meta

    if not images:
        return None, meta

    montage = _grid_montage(images, cols=min(4, len(images)))
    buf = BytesIO()
    montage.save(buf, format="JPEG", quality=85)
    return base64.b64encode(buf.getvalue()).decode("ascii"), meta


def _vision_image_b64(data: bytes, filename: str = "", mime: str = "") -> str:
    """Encode study bytes as JPEG base64; decode DICOM first when needed."""
    import base64
    from io import BytesIO

    from app.dicom import dicom_to_jpeg_bytes, looks_like_dicom

    if looks_like_dicom(data, filename, mime):
        jpeg, _meta = dicom_to_jpeg_bytes(data)
        return base64.b64encode(jpeg).decode("ascii")

    try:
        from PIL import Image

        img = Image.open(BytesIO(data)).convert("RGB")
        buf = BytesIO()
        img.save(buf, format="JPEG", quality=90)
        return base64.b64encode(buf.getvalue()).decode("ascii")
    except Exception:  # noqa: BLE001
        return base64.b64encode(data).decode("ascii")


def _build_histopath_patches(data: bytes) -> tuple[str | None, dict[str, Any]]:
    import base64
    from io import BytesIO

    meta: dict[str, Any] = {
        "grid": "3x3",
        "patch_count": 9,
        "note": "3x3 center grid",
        "patches": [],
    }

    try:
        from PIL import Image
    except ImportError:
        return (base64.b64encode(data).decode("ascii") if data else None, meta)

    try:
        img = Image.open(BytesIO(data)).convert("RGB")
    except Exception:
        return (base64.b64encode(data).decode("ascii") if data else None, meta)

    w, h = img.size
    cw, ch = int(w * 0.6), int(h * 0.6)
    left, top = (w - cw) // 2, (h - ch) // 2
    crop = img.crop((left, top, left + cw, top + ch))
    pw, ph = cw // 3, ch // 3
    patches = []
    for row in range(3):
        for col in range(3):
            pid = f"{row},{col}"
            patch = crop.crop((col * pw, row * ph, (col + 1) * pw, (row + 1) * ph))
            patches.append(patch)
            meta["patches"].append({"id": pid, "row": row, "col": col})

    montage = _grid_montage(patches, cols=3)
    buf = BytesIO()
    montage.save(buf, format="JPEG", quality=85)
    return base64.b64encode(buf.getvalue()).decode("ascii"), meta


def _grid_montage(images: list[Any], cols: int = 4) -> Any:
    from PIL import Image

    if not images:
        raise ValueError("no images")
    tw = min(img.width for img in images)
    th = min(img.height for img in images)
    tiles = [img.resize((tw, th)) for img in images]
    rows = (len(tiles) + cols - 1) // cols
    canvas = Image.new("RGB", (cols * tw, rows * th), (16, 16, 16))
    for i, tile in enumerate(tiles):
        r, c = divmod(i, cols)
        canvas.paste(tile, (c * tw, r * th))
    return canvas


def _analyze_vision(request: AnalyzeRequest, kind: str) -> dict[str, Any]:
    data = _download_bytes(request)
    if not data:
        raise RuntimeError("Could not download study file for vision analysis")

    return _invoke(
        "/analyze",
        {
            "image_b64": _vision_image_b64(
                data,
                request.original_filename or "",
                request.mime_type or "",
            ),
            "modality": kind,
            "language": request.language,
            "job_id": request.job_id,
            "record_id": request.record_id,
        },
    )


def _analyze_lab(request: AnalyzeRequest) -> dict[str, Any]:
    data = _download_bytes(request)
    text, ocr_meta = ("", {})
    if data:
        try:
            from app.lab_ocr import extract_lab_text

            text, ocr_meta = extract_lab_text(data)
        except Exception as exc:  # noqa: BLE001
            logger.warning("Lab text/OCR extract failed: %s", exc)
            text = _extract_pdf_text(request)
    else:
        text = _extract_pdf_text(request)

    text = _scrub_phi(text)

    # Scanned PDF / photo with no OCR engines (or empty OCR): MedGemma vision.
    if not text.strip() and data:
        vision = _analyze_lab_vision(request, data, ocr_meta)
        if vision is not None:
            return vision
        detail = ""
        if isinstance(ocr_meta, dict) and ocr_meta:
            detail = f" (ocr={ocr_meta.get('ocr_engine') or 'none'} kind={ocr_meta.get('kind')} err={ocr_meta.get('ocr_error')})"
        build = _env("SIHAT_AI_BUILD") or "unknown"
        raise RuntimeError(f"No lab text extracted from PDF{detail} [build={build}]")

    if not text.strip():
        raise RuntimeError("No lab text extracted from PDF (empty file)")

    try:
        result = _invoke(
            "/analyze_lab",
            {
                "text": text[:12000],
                "language": request.language,
                "job_id": request.job_id,
                "record_id": request.record_id,
            },
        )
        if ocr_meta:
            result["lab_text_meta"] = ocr_meta
        return result
    except Exception as exc:  # noqa: BLE001
        logger.warning("Lab analyze failed, using regex parse: %s", exc)

    parsed = _regex_parse_lab(text)
    if ocr_meta:
        parsed["lab_text_meta"] = ocr_meta
    if parsed["biomarkers"]:
        parsed["engine"] = "regex-parse"
        return parsed

    raise RuntimeError(f"Lab analysis failed after Modal error: {exc}")


def _analyze_lab_vision(
    request: AnalyzeRequest,
    data: bytes,
    ocr_meta: dict[str, Any],
) -> dict[str, Any] | None:
    """When OCR is empty, read lab values from rendered page image(s)."""
    try:
        from app.lab_ocr import lab_page_jpegs_b64

        pages = lab_page_jpegs_b64(data, max_pages=2)
    except Exception as exc:  # noqa: BLE001
        logger.warning("Lab vision page render failed: %s", exc)
        return None

    if not pages:
        return None

    last_err: Exception | None = None
    for i, page_b64 in enumerate(pages):
        try:
            result = _invoke(
                "/analyze_lab_image",
                {
                    "image_b64": page_b64,
                    "language": request.language,
                    "job_id": request.job_id,
                    "record_id": request.record_id,
                },
            )
            meta = dict(ocr_meta) if isinstance(ocr_meta, dict) else {}
            meta["source"] = "vision"
            meta["vision_page"] = i
            result["lab_text_meta"] = meta
            if result.get("biomarkers") or result.get("findings"):
                return result
        except Exception as exc:  # noqa: BLE001
            last_err = exc
            logger.warning("Lab vision analyze page %s failed: %s", i, exc)

    if last_err:
        logger.warning("Lab vision fallback exhausted: %s", last_err)
    return None


def _analyze_clinical_document(request: AnalyzeRequest) -> dict[str, Any]:
    """Discharge / clinic note PDF: prose findings, not biomarker schema."""
    data = _download_bytes(request)
    text = ""
    if data:
        try:
            from app.lab_ocr import extract_lab_text

            text, _meta = extract_lab_text(data)
        except Exception as exc:  # noqa: BLE001
            logger.warning("Clinical document extract failed: %s", exc)
            text = _extract_pdf_text(request)
    else:
        text = _extract_pdf_text(request)

    text = _scrub_phi(text)
    if not text.strip():
        raise RuntimeError("No text extracted from clinical document")

    try:
        return _invoke(
            "/analyze_clinical",
            {
                "text": text[:12000],
                "language": request.language,
                "job_id": request.job_id,
                "record_id": request.record_id,
            },
        )
    except Exception as exc:  # noqa: BLE001
        logger.warning("Clinical document analyze failed: %s", exc)
        raise RuntimeError(f"Clinical document analysis failed: {exc}") from exc


def _extract_pdf_text(request: AnalyzeRequest) -> str:
    data = _download_bytes(request)
    if not data:
        return ""

    try:
        from app.lab_ocr import extract_lab_text

        text, _meta = extract_lab_text(data)
        if text.strip():
            return text
    except Exception as exc:  # noqa: BLE001
        logger.info("lab_ocr extract skipped: %s", exc)

    try:
        from pypdf import PdfReader  # type: ignore

        with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as tmp:
            tmp.write(data)
            tmp_path = tmp.name

        reader = PdfReader(tmp_path)
        pages = []
        for page in reader.pages:
            pages.append(page.extract_text() or "")
        Path(tmp_path).unlink(missing_ok=True)
        text = "\n".join(pages).strip()
        if text:
            return text
    except Exception as exc:  # noqa: BLE001
        logger.info("pypdf extract skipped: %s", exc)

    try:
        import fitz  # type: ignore

        doc = fitz.open(stream=data, filetype="pdf")
        parts = [page.get_text() for page in doc]
        doc.close()
        return "\n".join(parts).strip()
    except Exception as exc:  # noqa: BLE001
        logger.info("pymupdf extract skipped: %s", exc)

    return data.decode("utf-8", errors="ignore")


def _download_bytes(request: AnalyzeRequest) -> bytes:
    if request.file_b64:
        import base64

        try:
            return base64.b64decode(request.file_b64, validate=False)
        except Exception as exc:  # noqa: BLE001
            raise RuntimeError(f"Invalid file_b64: {exc}") from exc

    if request.file_url:
        # trycloudflare / similar tunnels block Modal datacenter IPs → 403
        host = (request.file_url or "").lower()
        if "trycloudflare.com" in host or "ngrok" in host:
            raise RuntimeError(
                "file_b64 required: refusing to fetch study via tunnel URL "
                f"({host.split('/')[2] if '//' in host else host})"
            )
        with httpx.Client(
            timeout=60.0,
            headers={"User-Agent": "SihatAI-Modal/1.0"},
            follow_redirects=True,
        ) as client:
            resp = client.get(request.file_url)
            resp.raise_for_status()
            return resp.content

    if request.file_path:
        from pathlib import Path

        path = Path(request.file_path)
        if path.exists():
            return path.read_bytes()

    raise RuntimeError("No file_b64 (or reachable file_url) provided for analysis")


def _scrub_phi(text: str) -> str:
    patterns = [
        r"\b\d{6}-\d{2}-\d{4}\b",
        r"\b\d{3}-\d{4}\s?\d{4}\b",
        r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b",
        r"\bMRN[:\s#]*\d+\b",
    ]
    scrubbed = text
    for pattern in patterns:
        scrubbed = re.sub(pattern, "[REDACTED]", scrubbed, flags=re.IGNORECASE)
    return scrubbed


def _regex_parse_lab(text: str) -> dict[str, Any]:
    """Extract biomarkers with regex; allow label/value on nearby lines."""
    biomarkers: list[dict[str, Any]] = []
    findings: list[dict[str, Any]] = []

    patterns = [
        (r"Haemoglobin|Hemoglobin|Hb\b|Hgb\b", "Hemoglobin", "g/dL", 12.0, 16.0),
        (r"Platelets?|PLT", "Platelet count", "×10³/µL", 150.0, 400.0),
        (r"WBC|White\s*blood", "WBC", "×10³/µL", 4.0, 11.0),
        (r"RBC|Red\s*blood", "RBC", "×10¹²/L", 4.0, 5.2),
        (r"Creatinine", "Creatinine", "µmol/L", 44.0, 90.0),
        (r"Glucose|FBS|RBS", "Glucose", "mmol/L", 3.9, 5.6),
        (r"HbA1c|A1c", "HbA1c", "%", 0.0, 5.7),
        (r"\bALT\b|Alanine", "ALT", "U/L", 0.0, 41.0),
        (r"\bAST\b|Aspartate", "AST", "U/L", 0.0, 40.0),
        (r"\bALP\b|Alkaline", "ALP", "U/L", 40.0, 129.0),
        (r"\bGGT\b", "GGT", "U/L", 0.0, 60.0),
        (r"Sodium|\bNa\b", "Sodium", "mmol/L", 135.0, 145.0),
        (r"Potassium|\bK\b", "Potassium", "mmol/L", 3.5, 5.1),
    ]

    seen: set[str] = set()
    for pattern, name, unit, low, high in patterns:
        match = re.search(
            rf"(?is)({pattern})[^\d]{{0,80}}(\d+(?:\.\d+)?)",
            text,
        )
        if not match or name in seen:
            continue
        seen.add(name)
        value = float(match.group(2))
        status = "normal"
        if value < low or value > high:
            status = "critical" if (value < low * 0.7 or value > high * 1.4) else "abnormal"
        biomarkers.append(
            {
                "name": name,
                "value": value,
                "unit": unit,
                "reference_low": low,
                "reference_high": high,
                "status": status,
            }
        )
        findings.append(
            {
                "label": name,
                "description": f"{name} {value} {unit} (ref {low}-{high})",
                "confidence": 0.75,
                "severity": status if status != "normal" else "normal",
            }
        )

    return {
        "modality": Modality.lab_pdf.value,
        "findings": findings,
        "biomarkers": biomarkers,
        "differential_diagnosis": [],
        "overall_confidence": 0.72 if biomarkers else 0.4,
        "abstain": len(biomarkers) == 0,
        "engine": "regex-lab",
    }


def _invoke(path: str, payload: dict[str, Any]) -> dict[str, Any]:
    """Call GPU Modal classes (MedGemma / STT)."""
    import modal

    route = path.lstrip("/").replace("-", "_")
    app_name = "sihat-medgemma"

    if route in {"transcribe", "stt"}:
        stt = modal.Cls.from_name(app_name, "SttModel")()
        data = stt.transcribe.remote(payload["audio_b64"], payload.get("language"))
    else:
        model = modal.Cls.from_name(app_name, "MedGemmaModel")()
        if route == "analyze":
            data = model.analyze_image.remote(payload)
        elif route in {"analyze_lab", "analyze-lab"}:
            data = model.analyze_lab_text.remote(
                payload.get("text", ""),
                payload.get("language", "en"),
            )
        elif route in {"analyze_lab_image", "analyze-lab-image"}:
            data = model.analyze_lab_image.remote(payload)
        elif route in {"analyze_clinical", "analyze-clinical"}:
            data = model.analyze_clinical_text.remote(
                payload.get("text", ""),
                payload.get("language", "en"),
            )
        elif route == "classify":
            data = model.classify.remote(payload)
        elif route in {"status", "health"}:
            data = model.status.remote()
        else:
            raise RuntimeError(f"Unknown inference route: {route}")

    if not isinstance(data, dict):
        raise RuntimeError("Inference returned non-object JSON")
    if data.get("error") and not data.get("findings") and route not in {
        "status",
        "transcribe",
        "stt",
        "classify",
    }:
        raise RuntimeError(f"Inference error: {data.get('error')}")
    if "bounding_boxes" in data:
        data["bounding_boxes"] = _clamp_boxes_local(data.get("bounding_boxes"))
    return data


def _clamp_boxes_local(boxes: Any) -> list[dict[str, Any]]:
    if not isinstance(boxes, list):
        return []
    out: list[dict[str, Any]] = []
    for box in boxes:
        if not isinstance(box, dict):
            continue
        try:
            x = max(0.0, min(1.0, float(box.get("x", 0))))
            y = max(0.0, min(1.0, float(box.get("y", 0))))
            w = max(0.0, min(1.0 - x, float(box.get("width", 0))))
            h = max(0.0, min(1.0 - y, float(box.get("height", 0))))
        except (TypeError, ValueError):
            continue
        if w < 0.01 or h < 0.01:
            continue
        out.append(
            {
                "label": str(box.get("label") or "Finding"),
                "x": round(x, 4),
                "y": round(y, 4),
                "width": round(w, 4),
                "height": round(h, 4),
                "confidence": float(box.get("confidence", 0.5)),
            }
        )
    return out[:8]


def _post_webhook(
    request: AnalyzeRequest,
    *,
    status: str,
    result: dict[str, Any] | None = None,
    error: str | None = None,
    detected_modality: str | None = None,
    route_confidence: float | None = None,
) -> None:
    if not request.webhook_url:
        logger.warning("No webhook_url for job %s — result discarded", request.job_id)
        return

    body: dict[str, Any] = {
        "job_id": request.job_id,
        "status": status,
        "result": result,
        "error": error,
    }
    if detected_modality:
        body["detected_modality"] = detected_modality
    if route_confidence is not None:
        body["route_confidence"] = route_confidence

    raw = json.dumps(body, separators=(",", ":"), default=str)
    headers = {"Content-Type": "application/json"}
    secret = _webhook_secret()
    if not secret:
        logger.error(
            "SIHAT_AI_WEBHOOK_SECRET is empty — Laravel will reject the webhook. "
            "Set Modal secret sihat-webhook-secret."
        )
    else:
        headers["X-Sihat-Signature"] = hmac.new(
            secret.encode(),
            raw.encode(),
            hashlib.sha256,
        ).hexdigest()

    with httpx.Client(timeout=60.0) as client:
        resp = client.post(request.webhook_url, content=raw, headers=headers)
        if resp.status_code >= 400:
            logger.error("Webhook failed %s: %s", resp.status_code, resp.text)


