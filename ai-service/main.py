"""
SihatAI FastAPI microservice — Modal MedGemma inference + signed Laravel webhook.
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
from pathlib import Path
from typing import Any

import httpx
from fastapi import BackgroundTasks, FastAPI, HTTPException
from pydantic import BaseModel, Field

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("sihat-ai")

# Load ai-service/.env then repo-root .env (Laravel) so webhook secret matches without manual export
try:
    from dotenv import load_dotenv

    _here = Path(__file__).resolve().parent
    load_dotenv(_here / ".env")
    load_dotenv(_here.parent / ".env")
except ImportError:
    pass

app = FastAPI(title="SihatAI AI Service", version="0.2.0")


def _env(name: str, default: str = "") -> str:
    return (os.getenv(name) or default).strip()


def _webhook_secret() -> str:
    return _env("SIHAT_AI_WEBHOOK_SECRET")


def _modal_url() -> str:
    return _env("SIHAT_AI_MODAL_URL").rstrip("/")


def _use_local_mock() -> bool:
    return _env("SIHAT_AI_LOCAL_MOCK", "false").lower() in {"1", "true", "yes"}


# Back-compat names used below
WEBHOOK_SECRET = _webhook_secret()
MODAL_URL = _modal_url()
USE_LOCAL_MOCK = _use_local_mock()


class Modality(str, Enum):
    xray = "xray"
    ct = "ct"
    mri = "mri"
    histopath = "histopath"
    dermatology = "dermatology"
    ophthalmology = "ophthalmology"
    lab_pdf = "lab_pdf"
    unknown = "unknown"


class AnalyzeRequest(BaseModel):
    job_id: str
    record_id: int
    modality: Modality = Modality.unknown
    file_path: str = ""
    file_url: str | None = None
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
    return {
        "status": "ok",
        "service": "sihat-ai",
        "modal": "configured" if _modal_url() else "missing",
        "webhook_secret": "set" if secret else "missing",
        "adapter": f"configured:{lora}" if lora else "none",
    }


@app.post("/api/v1/transcribe")
def transcribe_audio(payload: dict[str, Any]) -> dict[str, Any]:
    """STT proxy — audio_b64 required."""
    audio_b64 = payload.get("audio_b64")
    if not audio_b64:
        raise HTTPException(status_code=422, detail="audio_b64 required")

    if _modal_url() and not _use_local_mock():
        try:
            return _call_modal(
                "/transcribe",
                {
                    "audio_b64": audio_b64,
                    "language": payload.get("language"),
                },
            )
        except Exception as exc:  # noqa: BLE001
            logger.warning("Modal STT failed: %s", exc)

    # Local fallback for demos without Modal
    return {
        "transcript": payload.get("hint") or "",
        "engine": "fallback-empty",
        "error": "STT unavailable; provide transcript text",
    }


@app.post("/api/v1/analyze", response_model=AnalyzeResponse)
def analyze(request: AnalyzeRequest, background_tasks: BackgroundTasks) -> AnalyzeResponse:
    background_tasks.add_task(_run_pipeline, request)
    return AnalyzeResponse(
        job_id=request.job_id,
        status="accepted",
        message="Analysis job queued",
    )


@app.post("/api/v1/classify")
def classify_modality(payload: dict[str, Any]) -> dict[str, Any]:
    """Thin modality classify helper (used when Laravel route_confidence is low)."""
    mime = (payload.get("mime_type") or "").lower()
    name = (payload.get("original_filename") or "").lower()

    if "pdf" in mime or name.endswith(".pdf"):
        return {"modality": "lab_pdf", "confidence": 0.95}

    if _modal_url() and not _use_local_mock() and payload.get("image_b64"):
        try:
            result = _call_modal(
                "/classify",
                {
                    "image_b64": payload["image_b64"],
                    "mime_type": mime,
                },
            )
            return {
                "modality": result.get("modality", "xray"),
                "confidence": float(result.get("confidence", 0.7)),
            }
        except Exception as exc:  # noqa: BLE001
            logger.warning("Modal classify failed: %s", exc)

    if any(k in name for k in ("derm", "skin", "lesion")):
        return {"modality": "dermatology", "confidence": 0.8}
    if any(k in name for k in ("xray", "cxr", "chest")):
        return {"modality": "xray", "confidence": 0.85}

    return {"modality": "xray", "confidence": 0.55}


def _run_pipeline(request: AnalyzeRequest) -> None:
    try:
        modality = request.modality
        route_confidence = request.route_confidence
        name = (request.original_filename or "").lower()

        if modality == Modality.unknown:
            if any(k in name for k in ("histo", "pathology", "wsi", "slide")):
                modality = Modality.histopath
                route_confidence = 0.85
            elif any(k in name for k in ("ct", "computed")):
                modality = Modality.ct
                route_confidence = 0.8
            elif any(k in name for k in ("mri", "mr_")):
                modality = Modality.mri
                route_confidence = 0.8

        should_classify = modality == Modality.unknown or (
            modality in {Modality.xray, Modality.dermatology, Modality.ct, Modality.mri}
            and (route_confidence is None or route_confidence < 0.7)
            and "pdf" not in (request.mime_type or "").lower()
            and not name.endswith(".zip")
        )

        if should_classify:
            import base64

            data = _download_bytes(request)
            classified = classify_modality(
                {
                    "image_b64": base64.b64encode(data).decode("ascii") if data else None,
                    "mime_type": request.mime_type,
                    "original_filename": request.original_filename,
                }
            )
            modality = Modality(classified["modality"])
            route_confidence = float(classified["confidence"])

        if modality == Modality.lab_pdf:
            result = _analyze_lab(request)
        elif modality == Modality.histopath:
            result = _analyze_histopath(request)
        elif modality in {Modality.ct, Modality.mri}:
            result = _analyze_volume(request, modality.value)
        elif modality == Modality.dermatology:
            result = _analyze_vision(request, "dermatology")
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
    """ponytail: mid-slice montage (max 8); not a full 3D DICOM viewer."""
    data = _download_bytes(request)
    name = (request.original_filename or "").lower()
    mime = (request.mime_type or "").lower()

    montage_b64, volume_meta = _build_volume_montage(data, name, mime)

    if _modal_url() and not _use_local_mock() and montage_b64:
        result = _call_modal(
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

    result = _mock_result(Modality(kind) if kind in Modality.__members__ else Modality.ct)
    result["volume_meta"] = volume_meta
    return result


def _analyze_histopath(request: AnalyzeRequest) -> dict[str, Any]:
    """ponytail: fixed center-region grid patches; not OpenSlide pyramid."""
    data = _download_bytes(request)
    montage_b64, patch_meta = _build_histopath_patches(data)

    if _modal_url() and not _use_local_mock() and montage_b64:
        result = _call_modal(
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

    result = _mock_result(Modality.histopath)
    result["patch_meta"] = patch_meta
    return result


def _build_volume_montage(
    data: bytes, filename: str, mime: str
) -> tuple[str | None, dict[str, Any]]:
    """Extract up to 8 mid slices from zip of images or pass-through single image."""
    import base64
    from io import BytesIO

    meta: dict[str, Any] = {
        "slice_count": 0,
        "used_slices": [],
        "note": "ponytail: mid-slice montage (max 8); not a full 3D viewer",
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
                    if n.lower().endswith((".png", ".jpg", ".jpeg", ".bmp", ".tif", ".tiff"))
                    and not n.startswith("__")
                )
                meta["slice_count"] = len(names)
                if not names:
                    return None, meta
                # Mid-volume window of up to 8
                start = max(0, len(names) // 2 - 4)
                chosen = names[start : start + 8]
                meta["used_slices"] = list(range(start, start + len(chosen)))
                for n in chosen:
                    with zf.open(n) as fh:
                        images.append(Image.open(BytesIO(fh.read())).convert("RGB"))
        except Exception as exc:  # noqa: BLE001
            logger.warning("Zip volume extract failed: %s", exc)
            return None, meta
    else:
        try:
            img = Image.open(BytesIO(data)).convert("RGB")
            images = [img]
            meta["slice_count"] = 1
            meta["used_slices"] = [0]
        except Exception:
            # Raw bytes (e.g. single-frame dcm without decoder) — pass through as-is when possible
            return (base64.b64encode(data).decode("ascii") if data else None, meta)

    if not images:
        return None, meta

    montage = _grid_montage(images, cols=min(4, len(images)))
    buf = BytesIO()
    montage.save(buf, format="JPEG", quality=85)
    return base64.b64encode(buf.getvalue()).decode("ascii"), meta


def _build_histopath_patches(data: bytes) -> tuple[str | None, dict[str, Any]]:
    import base64
    from io import BytesIO

    meta: dict[str, Any] = {
        "grid": "3x3",
        "patch_count": 9,
        "note": "ponytail: fixed center-region grid; not OpenSlide pyramid",
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
    # Center crop 60% then 3x3 grid
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
    # Normalize tile size
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
    modal_url = _modal_url()
    if modal_url and not _use_local_mock():
        data = _download_bytes(request)
        if not data:
            raise RuntimeError("Could not download study file for Modal vision analysis")

        import base64

        return _call_modal(
            "/analyze",
            {
                "image_b64": base64.b64encode(data).decode("ascii"),
                "modality": kind,
                "language": request.language,
                "job_id": request.job_id,
                "record_id": request.record_id,
            },
        )

    return _mock_result(Modality(kind) if kind in Modality.__members__ else Modality.xray)


def _analyze_lab(request: AnalyzeRequest) -> dict[str, Any]:
    text = _extract_pdf_text(request)
    text = _scrub_phi(text)

    if _modal_url() and not _use_local_mock() and text.strip():
        try:
            return _call_modal(
                "/analyze_lab",
                {
                    "text": text[:12000],
                    "language": request.language,
                    "job_id": request.job_id,
                    "record_id": request.record_id,
                },
            )
        except Exception as exc:  # noqa: BLE001
            logger.warning("Modal lab analyze failed, using regex parse: %s", exc)

    parsed = _regex_parse_lab(text)
    if parsed["biomarkers"]:
        return parsed

    return _mock_result(Modality.lab_pdf)


def _extract_pdf_text(request: AnalyzeRequest) -> str:
    data = _download_bytes(request)
    if not data:
        return ""

    # Prefer pypdf when installed
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

    # Lightweight OCR via pymupdf text layer
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
    if request.file_url:
        with httpx.Client(timeout=60.0) as client:
            resp = client.get(request.file_url)
            resp.raise_for_status()
            return resp.content

    if request.file_path and Path(request.file_path).exists():
        return Path(request.file_path).read_bytes()

    return b""


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
    """ponytail: naive name/value/unit extractor; Modal path preferred for messy PDFs."""
    biomarkers: list[dict[str, Any]] = []
    findings: list[dict[str, Any]] = []

    patterns = [
        (r"Hemoglobin|Hb\b", "Hemoglobin", "g/dL", 12.0, 16.0),
        (r"Platelet|PLT", "Platelet count", "×10³/µL", 150.0, 400.0),
        (r"WBC|White\s*blood", "WBC", "×10³/µL", 4.0, 11.0),
        (r"Creatinine", "Creatinine", "µmol/L", 44.0, 80.0),
        (r"Glucose|FBS|RBS", "Glucose", "mmol/L", 4.0, 5.6),
    ]

    for pattern, name, unit, low, high in patterns:
        match = re.search(
            rf"(?i)({pattern})[^\d]{{0,40}}(\d+(?:\.\d+)?)",
            text,
        )
        if not match:
            continue
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
                "value": value,
                "unit": unit,
                "reference": f"{low}-{high}",
                "severity": status if status != "normal" else "normal",
                "confidence": 0.9,
            }
        )

    confidence = 0.9 if biomarkers else 0.4
    return {
        "findings": findings,
        "biomarkers": biomarkers,
        "bounding_boxes": [],
        "overall_confidence": confidence,
    }


def _call_modal(path: str, payload: dict[str, Any]) -> dict[str, Any]:
    # Single Modal web endpoint; path maps to payload["route"]
    modal_url = _modal_url()
    if not modal_url:
        raise RuntimeError("SIHAT_AI_MODAL_URL is not set")

    route = path.lstrip("/").replace("-", "_")
    body = {**payload, "route": route}
    with httpx.Client(timeout=300.0) as client:
        resp = client.post(modal_url, json=body)
        data = resp.json() if resp.content else {}
        if resp.status_code >= 400:
            detail = data.get("error") or data.get("traceback") or resp.text
            raise RuntimeError(f"Modal HTTP {resp.status_code}: {detail}")
        if not isinstance(data, dict):
            raise RuntimeError("Modal returned non-object JSON")
        if data.get("error") and not data.get("findings") and route not in {"status", "transcribe", "stt", "classify"}:
            raise RuntimeError(f"Modal error: {data.get('error')}")
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
            "Set it in ai-service/.env or the process environment."
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


def _mock_result(modality: Modality) -> dict[str, Any]:
    if modality == Modality.lab_pdf:
        return {
            "findings": [
                {
                    "label": "Hemoglobin",
                    "value": 9.2,
                    "unit": "g/dL",
                    "severity": "abnormal",
                    "confidence": 0.95,
                    "reference": "12.0-16.0",
                },
                {
                    "label": "Platelet count",
                    "value": 85,
                    "unit": "×10³/µL",
                    "severity": "abnormal",
                    "confidence": 0.93,
                    "reference": "150-400",
                },
            ],
            "biomarkers": [
                {
                    "name": "Hemoglobin",
                    "value": 9.2,
                    "unit": "g/dL",
                    "reference_low": 12.0,
                    "reference_high": 16.0,
                    "status": "abnormal",
                },
                {
                    "name": "Platelet count",
                    "value": 85,
                    "unit": "×10³/µL",
                    "reference_low": 150,
                    "reference_high": 400,
                    "status": "abnormal",
                },
            ],
            "bounding_boxes": [],
            "overall_confidence": 0.92,
        }

    if modality == Modality.dermatology:
        return {
            "findings": [
                {
                    "label": "Melanocytic nevus",
                    "description": "Pigmented lesion with regular borders; no overt melanoma features on visual review.",
                    "confidence": 0.86,
                    "severity": "normal",
                }
            ],
            "bounding_boxes": [],
            "overall_confidence": 0.86,
            "differential_diagnosis": [
                {"condition": "Benign melanocytic nevus", "confidence": 0.8},
                {"condition": "Seborrheic keratosis", "confidence": 0.35},
            ],
        }

    if modality == Modality.xray:
        return {
            "findings": [
                {
                    "label": "Right lower lobe opacity",
                    "description": "Patchy airspace opacity in the right lower lobe.",
                    "confidence": 0.87,
                    "severity": "abnormal",
                }
            ],
            "bounding_boxes": [
                {
                    "label": "Right lower lobe opacity",
                    "x": 0.52,
                    "y": 0.55,
                    "width": 0.28,
                    "height": 0.22,
                    "confidence": 0.87,
                }
            ],
            "overall_confidence": 0.84,
            "differential_diagnosis": [
                {"condition": "Community-acquired pneumonia", "confidence": 0.78},
                {"condition": "Pulmonary tuberculosis", "confidence": 0.62},
            ],
            "adapter": "none",
        }

    if modality in {Modality.ct, Modality.mri}:
        label = "Ground-glass opacity" if modality == Modality.ct else "T2 hyperintensity"
        return {
            "findings": [
                {
                    "label": label,
                    "description": "Finding reviewed on mid-volume montage (demo volume path).",
                    "confidence": 0.81,
                    "severity": "abnormal",
                }
            ],
            "bounding_boxes": [
                {"label": label, "x": 0.4, "y": 0.35, "width": 0.2, "height": 0.2, "confidence": 0.81}
            ],
            "volume_meta": {
                "slice_count": 24,
                "used_slices": [8, 9, 10, 11, 12, 13, 14, 15],
                "note": "ponytail: mid-slice montage (max 8); not a full 3D viewer",
            },
            "overall_confidence": 0.81,
            "adapter": "none",
        }

    if modality == Modality.histopath:
        return {
            "findings": [
                {
                    "label": "Atypical glandular architecture",
                    "description": "Aggregated from center-region patches (demo WSI path).",
                    "confidence": 0.79,
                    "severity": "abnormal",
                    "patch": "1,1",
                },
                {
                    "label": "Inflammatory infiltrate",
                    "description": "Lymphocytic infiltrate noted on peripheral patch.",
                    "confidence": 0.74,
                    "severity": "borderline",
                    "patch": "0,2",
                },
            ],
            "bounding_boxes": [],
            "patch_meta": {
                "grid": "3x3",
                "patch_count": 9,
                "note": "ponytail: fixed center-region grid; not OpenSlide pyramid",
                "patches": [
                    {"id": "1,1", "finding": "Atypical glandular architecture"},
                    {"id": "0,2", "finding": "Inflammatory infiltrate"},
                ],
            },
            "overall_confidence": 0.78,
            "adapter": "none",
        }

    return {
        "findings": [
            {
                "label": "No acute abnormality",
                "description": "No acute abnormality detected on preliminary review.",
                "confidence": 0.75,
                "severity": "normal",
            }
        ],
        "bounding_boxes": [],
        "overall_confidence": 0.75,
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("main:app", host="127.0.0.1", port=8005, reload=True)
