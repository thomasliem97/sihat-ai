"""
Modal MedGemma 1.5 + MY-LoRA + Laravel-facing FastAPI glue.

All MedGemma paths are free-form text. OpenAI Structured Outputs (json_schema) is the
JSON enforcer for imaging, classify, clinical text. Lab JSON is filled by Laravel
(low-effort schema) after GPU returns the MedGemma draft.
Structurer model/effort: OPENAI_STRUCTURE_MODEL / OPENAI_STRUCTURE_EFFORT (Modal openai-secret).

Deploy (from repo root):
  modal secret create huggingface-secret HF_TOKEN=hf_...
  modal secret create sihat-webhook-secret SIHAT_AI_WEBHOOK_SECRET=...
  modal secret create openai-secret OPENAI_API_KEY=sk-... OPENAI_STRUCTURE_MODEL=gpt-... OPENAI_STRUCTURE_EFFORT=...
  modal deploy ai-service/app/modal_app.py

Laravel:
  SIHAT_AI_URL=https://<workspace>--sihat-medgemma-web.modal.run

Adapter: volume sihat-lora at /lora/adapter (or SIHAT_AI_LORA_PATH).
HF / Whisper weights cache: volume sihat-hf-cache at /cache (shared by MedGemma + STT).
"""

from __future__ import annotations

import base64
import json
import os
import re
from contextlib import nullcontext
from io import BytesIO
from typing import Any

import modal


app = modal.App("sihat-medgemma")

image = (
    modal.Image.debian_slim(python_version="3.11")
    .apt_install("ffmpeg")
    .pip_install(
        "torch",
        "transformers>=4.50.0",
        "accelerate",
        "peft",
        "Pillow",
        "httpx",
        "fastapi",
        "pydantic",
        "openai-whisper",
        "numpy",
        "pydicom",
        "pylibjpeg",
        "pylibjpeg-libjpeg",
        "pylibjpeg-openjpeg",
        "openai",
        "huggingface_hub",
    )
    .env({
        "SIHAT_AI_BUILD": "imaging-v20-20260831",
    })
)

# CPU glue: Laravel /api/v1/* + PDF/OCR (calls GPU classes via .remote())
# copy=True bakes app code into the image so workers cannot keep a stale mount.
# opencv-python-headless avoids RapidOCR dying on missing libGL.so.1.
web_image = (
    modal.Image.debian_slim(python_version="3.11")
    .apt_install("tesseract-ocr", "libgl1", "libglib2.0-0")
    .pip_install(
        "fastapi",
        "httpx",
        "pydantic",
        "Pillow",
        "numpy",
        "pydicom",
        "pylibjpeg",
        "pylibjpeg-libjpeg",
        "pylibjpeg-openjpeg",
        "pymupdf",
        "pypdf",
        "opencv-python-headless",
        "rapidocr",
        "onnxruntime",
        "pytesseract",
    )
    .run_commands(
        "python -m pip uninstall -y opencv-python || true",
        "python -m pip install --force-reinstall opencv-python-headless",
        'python -c "import cv2; from rapidocr import RapidOCR; RapidOCR()"',
    )
    .env({
        "PYTHONPATH": "/root",
        "SIHAT_AI_BUILD": "imaging-v20-20260831",
        "OPENAI_STRUCTURE_MODEL": "gpt-5.6-terra",
        "OPENAI_STRUCTURE_EFFORT": "high",
    })
    .add_local_dir(
        "ai-service/app",
        remote_path="/root/app",
        copy=True,
        ignore=["**/__pycache__/**", "**/*.pyc"],
    )
)

MODEL_ID = "google/medgemma-1.5-4b-it"
MEDASR_MODEL_ID = "google/medasr"
WHISPER_DOWNLOAD_ROOT = "/cache/whisper"
lora_vol = modal.Volume.from_name("sihat-lora", create_if_missing=True)
hf_cache_vol = modal.Volume.from_name("sihat-hf-cache", create_if_missing=True)
webhook_secret = modal.Secret.from_name("sihat-webhook-secret")
hf_secret = modal.Secret.from_name("huggingface-secret")
openai_secret = modal.Secret.from_name("openai-secret")


def _configure_model_cache() -> None:
    """Point Hugging Face + related caches at the shared Modal volume."""
    os.makedirs("/cache/huggingface", exist_ok=True)
    os.makedirs(WHISPER_DOWNLOAD_ROOT, exist_ok=True)
    os.environ["HF_HOME"] = "/cache/huggingface"
    os.environ["HUGGINGFACE_HUB_CACHE"] = "/cache/huggingface/hub"
    os.environ["TRANSFORMERS_CACHE"] = "/cache/huggingface/transformers"
    os.environ["XDG_CACHE_HOME"] = "/cache"


def _begin_model_cache() -> None:
    """See latest committed cache from other containers before load."""
    _configure_model_cache()
    try:
        hf_cache_vol.reload()
    except Exception as exc:  # noqa: BLE001
        print(f"HF cache volume reload skipped: {exc}")


_ASR_SPECIAL_TOKENS = ("</s>", "<s>", "<pad>", "<unk>", "<|endoftext|>")


def _clean_asr_text(text: str) -> str:
    """Drop HF/SentencePiece specials that sometimes leak into MedASR text."""
    cleaned = (text or "").strip()
    for token in _ASR_SPECIAL_TOKENS:
        cleaned = cleaned.replace(token, "")
    return " ".join(cleaned.split()).strip()


def _commit_model_cache() -> None:
    """Persist newly downloaded weights for the next cold start."""
    try:
        hf_cache_vol.commit()
    except Exception as exc:  # noqa: BLE001
        print(f"HF cache volume commit skipped: {exc}")


def _from_pretrained_cached(loader: Any, model_id: str, **kwargs: Any) -> Any:
    """Prefer volume-local files (skip Hub HEAD); fall back to download on miss."""
    try:
        return loader(model_id, local_files_only=True, **kwargs)
    except Exception as local_exc:  # noqa: BLE001
        print(f"Cache miss for {model_id}, downloading: {local_exc}")
        return loader(model_id, local_files_only=False, **kwargs)

# MY-LoRA is text SFT. Suffix targets like "q_proj" also match vision_tower.*.q_proj and
# create empty LoRA slots → PEFT "missing adapter keys". Keep adapters on language path only.
_LANGUAGE_LORA_TARGET_MODULES = (
    r"^(?!.*vision_tower).*(?:q_proj|k_proj|v_proj|o_proj|gate_proj|up_proj|down_proj)$"
)


def _structure_model() -> str:
    return (os.environ.get("OPENAI_STRUCTURE_MODEL") or "gpt-5.6-terra").strip()


def _structure_effort() -> str:
    return (os.environ.get("OPENAI_STRUCTURE_EFFORT") or "high").strip()


def _lora_path() -> str:
    env = (os.environ.get("SIHAT_AI_LORA_PATH") or "").strip()
    if env:
        return env
    if os.path.isdir("/lora/adapter"):
        return "/lora/adapter"
    return ""


def _load_peft_model(base: Any, lora_path: str, token: str | None) -> Any:
    """Load MY-LoRA without attaching empty adapters to the vision tower."""
    from peft import PeftConfig, PeftModel

    config = PeftConfig.from_pretrained(lora_path, token=token)
    if hasattr(config, "target_modules"):
        config.target_modules = _LANGUAGE_LORA_TARGET_MODULES
    return PeftModel.from_pretrained(base, lora_path, config=config, token=token)


def _decode_image_bytes(raw: bytes):
    from PIL import Image

    try:
        return Image.open(BytesIO(raw)).convert("RGB")
    except Exception:
        try:
            import pydicom
            import numpy as np

            ds = pydicom.dcmread(BytesIO(raw), force=True)
            arr = ds.pixel_array
            if arr.ndim == 3 and arr.shape[0] > 4 and arr.shape[-1] not in (3, 4):
                arr = arr[arr.shape[0] // 2]
            a = np.asarray(arr, dtype=np.float32)
            lo, hi = float(a.min()), float(a.max())
            if hi > lo:
                a = (a - lo) / (hi - lo) * 255.0
            u8 = a.astype(np.uint8)
            if u8.ndim == 2:
                return Image.fromarray(u8, mode="L").convert("RGB")
            return Image.fromarray(u8[..., :3]).convert("RGB")
        except Exception as exc:  # noqa: BLE001
            raise ValueError(f"Could not decode image/DICOM payload: {exc}") from exc


def _load_image(payload: dict[str, Any]):
    raw: bytes | None = None
    if payload.get("image_b64"):
        raw = base64.b64decode(payload["image_b64"])
    else:
        file_url = payload.get("file_url")
        if not file_url:
            raise ValueError("image_b64, images_b64, or file_url is required")
        import httpx

        with httpx.Client(timeout=60.0) as client:
            resp = client.get(file_url, headers={"User-Agent": "SihatAI-Modal/1.0"}, follow_redirects=True)
            resp.raise_for_status()
            raw = resp.content

    assert raw is not None
    return _decode_image_bytes(raw)


def _load_images(payload: dict[str, Any]) -> list[Any]:
    """One or more PILs. images_b64 wins; image_b64 / file_url remain the one-image fallback."""
    b64s = payload.get("images_b64")
    if isinstance(b64s, list):
        images: list[Any] = []
        for item in b64s[:9]:
            if not item or not isinstance(item, str):
                continue
            images.append(_decode_image_bytes(base64.b64decode(item)))
        if images:
            return images
    return [_load_image(payload)]


def _focus_explain_pils(pils: list[Any], box: Any) -> tuple[list[Any], bool]:
    """Crop the marked region and put that slice first so CT findings off slice 0 still ground."""
    if not isinstance(box, dict) or not pils:
        return pils, False
    try:
        x = float(box.get("x", 0))
        y = float(box.get("y", 0))
        w = float(box.get("width", 0))
        h = float(box.get("height", 0))
        idx = int(box.get("image_index") or 0)
        if idx < 0 or idx >= len(pils):
            return pils, False
        img = pils[idx]
        rest = [item for i, item in enumerate(pils) if i != idx]
        iw, ih = img.size
        crop = img.crop(
            (
                int(max(0, x) * iw),
                int(max(0, y) * ih),
                int(min(1, x + w) * iw),
                int(min(1, y + h) * ih),
            )
        )
        if crop.size[0] >= 8 and crop.size[1] >= 8:
            return [img, crop, *rest], True
        return [img, *rest], False
    except Exception as exc:  # noqa: BLE001
        print(f"Explain crop skipped: {exc}")
        return pils, False


def _vision_user_content(pils: list[Any], text: str) -> list[dict[str, Any]]:
    return [{"type": "image", "image": pil} for pil in pils] + [
        {"type": "text", "text": text}
    ]


# GPT Structured Outputs schemas (OpenAI json_schema). MedGemma never sees these.
_SEVERITY = {"type": "string", "enum": ["normal", "borderline", "abnormal", "critical"]}
_STRING = {"type": "string"}
_NUMBER = {"type": "number"}
_STRING_LIST = {"type": "array", "items": _STRING}
_PATIENT_REPORT = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "summary": _STRING,
        "what_this_means": _STRING,
        "questions_for_doctor": _STRING_LIST,
        "action_plan": _STRING_LIST,
    },
    "required": [
        "summary",
        "what_this_means",
        "questions_for_doctor",
        "action_plan",
    ],
}

IMAGING_RESULT_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "findings": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "label": _STRING,
                    "description": _STRING,
                    "confidence": _NUMBER,
                    "severity": _SEVERITY,
                },
                "required": ["label", "description", "confidence", "severity"],
            },
        },
        "differential_diagnosis": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "condition": _STRING,
                    "confidence": _NUMBER,
                },
                "required": ["condition", "confidence"],
            },
        },
        "overall_confidence": _NUMBER,
        "recommendations": _STRING_LIST,
        "patient_report": _PATIENT_REPORT,
    },
    "required": [
        "findings",
        "differential_diagnosis",
        "overall_confidence",
        "recommendations",
        "patient_report",
    ],
}

LAB_RESULT_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "findings": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "label": _STRING,
                    "value": _STRING,
                    "unit": _STRING,
                    "reference": _STRING,
                    "severity": _SEVERITY,
                    "confidence": _NUMBER,
                    "description": _STRING,
                },
                "required": [
                    "label",
                    "value",
                    "unit",
                    "reference",
                    "severity",
                    "confidence",
                    "description",
                ],
            },
        },
        "biomarkers": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "name": _STRING,
                    "value": _STRING,
                    "unit": _STRING,
                    "reference_low": _STRING,
                    "reference_high": _STRING,
                    "status": _SEVERITY,
                },
                "required": [
                    "name",
                    "value",
                    "unit",
                    "reference_low",
                    "reference_high",
                    "status",
                ],
            },
        },
        "differential_diagnosis": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "condition": _STRING,
                    "confidence": _NUMBER,
                },
                "required": ["condition", "confidence"],
            },
        },
        "overall_confidence": _NUMBER,
        "recommendations": _STRING_LIST,
        "patient_report": _PATIENT_REPORT,
        "bounding_boxes": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "label": _STRING,
                    "x": _NUMBER,
                    "y": _NUMBER,
                    "width": _NUMBER,
                    "height": _NUMBER,
                    "confidence": _NUMBER,
                },
                "required": ["label", "x", "y", "width", "height", "confidence"],
            },
        },
    },
    "required": [
        "findings",
        "biomarkers",
        "differential_diagnosis",
        "overall_confidence",
        "recommendations",
        "patient_report",
        "bounding_boxes",
    ],
}

CLASSIFY_RESULT_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "modality": {
            "type": "string",
            "enum": [
                "xray",
                "dermatology",
                "ct",
                "mri",
                "histopath",
                "ophthalmology",
                "other",
            ],
        },
        "confidence": _NUMBER,
    },
    "required": ["modality", "confidence"],
}


def medgemma_box_2d_to_xywh(box_2d: Any) -> tuple[float, float, float, float] | None:
    """Google localization is [y0, x0, y1, x1] in 0-1000 (or already 0-1). Return x,y,w,h in 0-1."""
    if not isinstance(box_2d, (list, tuple)) or len(box_2d) < 4:
        return None
    try:
        y0, x0, y1, x1 = (float(box_2d[0]), float(box_2d[1]), float(box_2d[2]), float(box_2d[3]))
    except (TypeError, ValueError):
        return None
    scale = 1000.0 if max(abs(y0), abs(x0), abs(y1), abs(x1)) > 1.5 else 1.0
    x = x0 / scale
    y = y0 / scale
    width = (x1 - x0) / scale
    height = (y1 - y0) / scale
    if width < 0:
        x, width = x + width, -width
    if height < 0:
        y, height = y + height, -height
    return (x, y, width, height)


def _localization_json_blob(text: str) -> str:
    blob = text or ""
    start = blob.find("```json")
    if start != -1:
        start += len("```json")
        end = blob.find("```", start)
        return (blob[start:end] if end != -1 else blob[start:]).strip()
    matches = list(re.finditer(r"\[\s*\{.*\}\s*\]", blob, flags=re.S))
    if matches:
        return matches[-1].group(0)
    return ""


def _xywh_from_item(item: dict[str, Any]) -> tuple[float, float, float, float] | None:
    xywh = medgemma_box_2d_to_xywh(item.get("box_2d") or item.get("bbox"))
    if xywh is not None:
        return xywh
    try:
        x = float(item["x"])
        y = float(item["y"])
        w = float(item["width"])
        h = float(item["height"])
    except (KeyError, TypeError, ValueError):
        return None
    scale = 1000.0 if max(abs(x), abs(y), abs(w), abs(h)) > 1.5 else 1.0
    return (x / scale, y / scale, w / scale, h / scale)


def parse_medgemma_localization(text: str) -> list[dict[str, Any]]:
    """Parse ```json[{box_2d, label, kind, finding_index, image_index}]``` from a MedGemma reply."""
    blob = _localization_json_blob(text)
    if not blob:
        return []
    try:
        parsed = json.loads(blob)
    except json.JSONDecodeError:
        return []
    if isinstance(parsed, dict):
        parsed = parsed.get("boxes") or parsed.get("bounding_boxes") or []
    if not isinstance(parsed, list):
        return []
    out: list[dict[str, Any]] = []
    for item in parsed:
        if not isinstance(item, dict):
            continue
        xywh = _xywh_from_item(item)
        if xywh is None:
            continue
        x, y, w, h = xywh
        kind = str(item.get("kind") or "finding").strip().lower()
        if kind not in {"finding", "anatomy"}:
            kind = "finding"
        box: dict[str, Any] = {
            "label": str(item.get("label") or "Finding"),
            "x": x,
            "y": y,
            "width": w,
            "height": h,
            "confidence": float(item.get("confidence") or 0.7),
            "kind": kind,
        }
        if item.get("finding_index") is not None:
            try:
                box["finding_index"] = int(item["finding_index"])
            except (TypeError, ValueError):
                pass
        if item.get("image_index") is not None:
            try:
                box["image_index"] = int(item["image_index"])
            except (TypeError, ValueError):
                pass
        out.append(box)
    return out


def _pad_image_to_square(pil: Any) -> Any:
    """Letterbox like Google's MedGemma localization notebook so box_2d matches training."""
    from PIL import Image as PILImage

    image = pil.convert("RGB") if hasattr(pil, "convert") else pil
    width, height = image.size
    side = max(width, height)
    if width == side and height == side:
        return image
    canvas = PILImage.new("RGB", (side, side), (0, 0, 0))
    canvas.paste(image, ((side - width) // 2, (side - height) // 2))
    return canvas


def _unmap_boxes_from_square(boxes: list[dict[str, Any]], pils: list[Any]) -> list[dict[str, Any]]:
    """Map boxes from square-padded canvas back onto the original image."""
    if not boxes or not pils:
        return boxes
    out: list[dict[str, Any]] = []
    for box in boxes:
        idx = int(box.get("image_index") or 0)
        if idx < 0 or idx >= len(pils):
            idx = 0
        orig_w, orig_h = pils[idx].size
        side = max(orig_w, orig_h)
        if orig_w <= 0 or orig_h <= 0 or orig_w == orig_h:
            out.append(box)
            continue
        pad_x = (side - orig_w) / 2.0
        pad_y = (side - orig_h) / 2.0
        mapped = dict(box)
        mapped["x"] = (float(box["x"]) * side - pad_x) / orig_w
        mapped["y"] = (float(box["y"]) * side - pad_y) / orig_h
        mapped["width"] = float(box["width"]) * side / orig_w
        mapped["height"] = float(box["height"]) * side / orig_h
        out.append(mapped)
    return out


def _attach_finding_indices(boxes: list[dict[str, Any]], findings: list[Any]) -> list[dict[str, Any]]:
    labels = [
        str(row.get("label") or "").strip().lower()
        for row in findings
        if isinstance(row, dict)
    ]
    used: set[int] = set()
    next_i = 0
    for box in boxes:
        if str(box.get("kind") or "finding") == "anatomy":
            continue
        if box.get("finding_index") is not None:
            used.add(int(box["finding_index"]))
            continue
        label = str(box.get("label") or "").strip().lower()
        matched: int | None = None
        for i, name in enumerate(labels):
            if i in used or not name:
                continue
            if name == label or name in label or label in name:
                matched = i
                break
        if matched is None:
            while next_i in used:
                next_i += 1
            matched = next_i
            next_i += 1
        box["finding_index"] = matched
        used.add(matched)
    return boxes


def _clamp_boxes(boxes: Any) -> list[dict[str, Any]]:
    if not isinstance(boxes, list):
        return []
    findings: list[dict[str, Any]] = []
    anatomy: list[dict[str, Any]] = []
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
        kind = str(box.get("kind") or "finding").strip().lower()
        if kind not in {"finding", "anatomy"}:
            kind = "finding"
        item: dict[str, Any] = {
            "label": str(box.get("label") or "Finding"),
            "x": round(x, 4),
            "y": round(y, 4),
            "width": round(w, 4),
            "height": round(h, 4),
            "confidence": float(box.get("confidence", 0.5)),
            "kind": kind,
            "image_index": int(box.get("image_index") or 0),
        }
        if box.get("finding_index") is not None:
            try:
                item["finding_index"] = int(box["finding_index"])
            except (TypeError, ValueError):
                pass
        if kind == "anatomy":
            anatomy.append(item)
        else:
            findings.append(item)
    return findings[:8] + anatomy[:8]


_FINDINGS_HEADING = re.compile(r"(?is)^\s*findings\s*:?\s*")
_IMPRESSION_SPLIT = re.compile(r"(?is)\n\s*impression\s*:?\s*")
_IMPRESSION_ONLY = re.compile(r"(?is)^\s*impression\s*:?\s*")


def _split_medgemma_draft(draft: str) -> tuple[str, str]:
    """Keep MedGemma prose. Split FINDINGS / IMPRESSION headings only."""
    text = (draft or "").strip()
    if not text:
        return "", ""
    body = text
    while _FINDINGS_HEADING.match(body):
        body = _FINDINGS_HEADING.sub("", body, count=1)
    parts = _IMPRESSION_SPLIT.split(body, maxsplit=1)
    if len(parts) == 2:
        return parts[0].strip(), parts[1].strip()
    if _FINDINGS_HEADING.match(text) is None:
        impression_parts = _IMPRESSION_ONLY.split(text, maxsplit=1)
        if len(impression_parts) == 2:
            return "", impression_parts[1].strip()
    return text, ""


def _ensure_findings_heading(draft: str) -> str:
    text = (draft or "").strip()
    if not text or re.match(r"(?is)^\s*findings\s*:", text):
        return text
    return "FINDINGS:\n" + text


def _usable_clinical_label(label: Any) -> bool:
    if not isinstance(label, str):
        return False
    trimmed = label.strip()
    if len(trimmed) < 3:
        return False
    return re.search(r"[^\W_]", trimmed, flags=re.UNICODE) is not None


def _normalize_patient_report(raw: dict[str, Any]) -> dict[str, Any] | None:
    payload = raw.get("patient_report")
    if not isinstance(payload, dict):
        return None
    summary = str(payload.get("summary") or "").strip()
    if not summary:
        return None
    questions = [
        str(item).strip()
        for item in (payload.get("questions_for_doctor") or [])
        if str(item).strip()
    ]
    actions = [
        str(item).strip()
        for item in (payload.get("action_plan") or [])
        if str(item).strip()
    ]
    return {
        "summary": summary,
        "what_this_means": str(payload.get("what_this_means") or "").strip(),
        "questions_for_doctor": questions,
        "action_plan": actions,
    }


def _normalize_recommendations(raw: dict[str, Any]) -> list[str]:
    recs = raw.get("recommendations") or []
    if not isinstance(recs, list):
        return []
    return [str(item).strip() for item in recs if str(item).strip()]


def _coerce_lab_decimal(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        number = float(value)
        return number if number == number and abs(number) != float("inf") else None
    text = str(value).replace(",", "").replace(" ", "").strip()
    if not text:
        return None
    match = re.match(r"[<>]=?(-?\d+(?:\.\d+)?)", text)
    if match:
        return float(match.group(1))
    match = re.search(r"-?\d+(?:\.\d+)?", text)
    if match:
        return float(match.group(0))
    return None


def _coerce_biomarkers(rows: Any) -> list[dict[str, Any]]:
    if not isinstance(rows, list):
        return []
    out: list[dict[str, Any]] = []
    for row in rows:
        if not isinstance(row, dict) or not row.get("name"):
            continue
        value = _coerce_lab_decimal(row.get("value"))
        if value is None:
            continue
        item = dict(row)
        item["value"] = value
        item["reference_low"] = _coerce_lab_decimal(row.get("reference_low"))
        item["reference_high"] = _coerce_lab_decimal(row.get("reference_high"))
        out.append(item)
    return out


def _normalize_result(
    raw: dict[str, Any],
    adapter: str,
    draft: str = "",
    *,
    adapter_used: bool = True,
) -> dict[str, Any]:
    findings_in = raw.get("findings") or []
    if not isinstance(findings_in, list):
        findings_in = []
    findings = [
        f
        for f in findings_in
        if isinstance(f, dict) and _usable_clinical_label(f.get("label"))
    ]

    differentials_in = raw.get("differential_diagnosis") or []
    if not isinstance(differentials_in, list):
        differentials_in = []
    differentials = [
        d
        for d in differentials_in
        if isinstance(d, dict) and _usable_clinical_label(d.get("condition"))
    ]

    confidence = raw.get("overall_confidence")
    if confidence is None and findings:
        confs = [float(f.get("confidence", 0.5)) for f in findings if isinstance(f, dict)]
        confidence = sum(confs) / len(confs) if confs else 0.5
    if not findings:
        confidence = min(float(confidence or 0.5), 0.35)

    draft_text = _clean_draft((draft or "").strip())
    narrative, impression = _split_medgemma_draft(draft_text)

    return {
        "findings": findings,
        "differential_diagnosis": differentials,
        "bounding_boxes": _clamp_boxes(raw.get("bounding_boxes")),
        "biomarkers": _coerce_biomarkers(raw.get("biomarkers") or []),
        "overall_confidence": float(confidence or 0.5),
        "adapter": adapter,
        "engine": f"medgemma+{_structure_model()}",
        "structurer": _structure_model(),
        "medgemma_draft": draft_text,
        "findings_narrative": narrative,
        "impression": impression,
        "recommendations": _normalize_recommendations(raw),
        "patient_report": _normalize_patient_report(raw),
        "adapter_used": adapter_used,
    }


def _normalize_classify(raw: dict[str, Any], adapter: str) -> dict[str, Any]:
    allowed = {
        "xray",
        "dermatology",
        "ct",
        "mri",
        "histopath",
        "ophthalmology",
    }
    modality = str(raw.get("modality") or "other").strip().lower()
    try:
        confidence = float(raw.get("confidence", 0.5))
    except (TypeError, ValueError):
        confidence = 0.5
    confidence = max(0.0, min(1.0, confidence))
    if modality == "other" or modality not in allowed:
        modality = "xray"
        confidence = min(confidence, 0.5)
    return {
        "modality": modality,
        "confidence": confidence,
        "adapter": adapter,
        "engine": f"medgemma+{_structure_model()}",
        "structurer": _structure_model(),
    }


def _pil_to_data_url(pil: Any, *, max_side: int = 1280, quality: int = 85) -> str:
    from PIL import Image as PILImage

    image = pil
    if not isinstance(image, PILImage.Image):
        raise TypeError("expected PIL image")
    image = image.convert("RGB")
    w, h = image.size
    scale = min(1.0, float(max_side) / float(max(w, h)))
    if scale < 1.0:
        image = image.resize((max(1, int(w * scale)), max(1, int(h * scale))))
    buf = BytesIO()
    image.save(buf, format="JPEG", quality=quality, optimize=True)
    b64 = base64.b64encode(buf.getvalue()).decode("ascii")
    return f"data:image/jpeg;base64,{b64}"


def _openai_structured(
    *,
    name: str,
    schema: dict[str, Any],
    instructions: str,
    user_text: str,
    pil: Any | None = None,
) -> dict[str, Any]:
    """OpenAI Structured Outputs is the only JSON enforcer in this service."""
    from openai import OpenAI

    api_key = (os.environ.get("OPENAI_API_KEY") or "").strip()
    if not api_key:
        raise RuntimeError("OPENAI_API_KEY is not set")

    content: list[dict[str, Any]] = [
        {"type": "input_text", "text": user_text},
    ]
    if pil is not None:
        content.append({"type": "input_image", "image_url": _pil_to_data_url(pil)})

    client = OpenAI(api_key=api_key)
    response = client.responses.create(
        model=_structure_model(),
        reasoning={"effort": _structure_effort()},
        instructions=instructions,
        input=[{"role": "user", "content": content}],
        text={
            "format": {
                "type": "json_schema",
                "name": name,
                "strict": True,
                "schema": schema,
            }
        },
    )

    text_out = getattr(response, "output_text", None)
    if not text_out:
        chunks: list[str] = []
        for item in getattr(response, "output", None) or []:
            for part in getattr(item, "content", None) or []:
                part_text = getattr(part, "text", None)
                if part_text:
                    chunks.append(str(part_text))
        text_out = "".join(chunks)
    if not text_out:
        raise RuntimeError(f"OpenAI structurer ({name}) returned empty output")

    decoded = json.loads(text_out)
    if not isinstance(decoded, dict):
        raise RuntimeError(f"OpenAI structurer ({name}) returned non-object JSON")
    return decoded


def _audience_language(language: str) -> str:
    lang = (language or "en").strip().lower()
    if lang.startswith("ms") or lang in {"bm", "malay"}:
        return "Bahasa Melayu"
    if lang.startswith("zh") or lang in {"cn", "mandarin", "chinese"}:
        return "Mandarin Chinese"
    if lang.startswith("ta") or lang == "tamil":
        return "Tamil"
    return "English"


def _structure_from_draft_rules(language: str) -> str:
    return (
        f"Write patient_report and recommendations in {_audience_language(language)}. "
        "Keep clinical detail from the draft. Finding descriptions must be full clauses "
        "(location, appearance, qualifier), not one-word labels. "
        "patient_report.summary should be several sentences covering the impression, not a headline. "
        "patient_report must come from the draft: summary, what_this_means, "
        "questions_for_doctor, and action_plan. No definitive diagnosis as fact. "
        "recommendations are the next steps stated in IMPRESSION. "
        "If the draft is empty, use empty strings and empty lists. "
        + _malaysia_units()
    )


def _lab_structure_rules(language: str) -> str:
    return (
        f"Write patient_report and recommendations in {_audience_language(language)}. "
        "biomarkers: every numeric analyte; status from a printed flag (H, L, critical) "
        "or by comparing the result to the printed interval "
        "(normal|borderline|abnormal|critical). "
        "findings: only out-of-range or lab-flagged analytes; description must be a full "
        "sentence (how far from the interval, and a caution). A printed H/L/* flag is not normal. "
        "If none are abnormal, findings may be []. "
        "recommendations: copy the interpretive cautions from IMPRESSION "
        "(repeat or confirm, correlate, same-day review, suggested follow-up tests). "
        "If the panel is in range, one recommendation that no lab-based caution is indicated. "
        "differential_diagnosis: pattern hypotheses from IMPRESSION only; [] if none. "
        "patient_report from the draft: abnormal results, what they may mean, "
        "questions_for_doctor, action_plan. No definitive diagnosis as fact. "
        "Use exact numeric values from the draft/image. For censored results like >60 or <0.1, "
        "put the number only in value/reference_low/reference_high. "
        "Missing fields use empty strings. bounding_boxes optional; else []. "
        + _malaysia_units()
    )


def _structure_imaging(pil: Any, draft: str, *, modality: str, language: str = "en") -> dict[str, Any]:
    return _openai_structured(
        name="imaging_result",
        schema=IMAGING_RESULT_SCHEMA,
        instructions=(
            "Convert the MedGemma imaging draft into JSON matching the schema.\n"
            "Inputs: a medical image and a MedGemma draft report (plain text).\n"
            "Output: one JSON object matching the schema.\n"
            "Use the MedGemma draft as the primary clinical source. Copy its substance into "
            "finding descriptions; do not reduce the report to a handful of labels. "
            "You may normalize wording and calibrate severity/confidence. Do not invent findings or bounding boxes.\n"
            f"Modality: {modality}.\n"
            "Severity: normal|borderline|abnormal|critical. "
            "Nodules/masses/consolidations/opacities/effusions/pneumothorax are not normal.\n"
            + _structure_from_draft_rules(language)
        ),
        user_text="MedGemma draft report:\n\n" + ((draft or "").strip() or "(empty)"),
        pil=pil,
    )


def _structure_clinical(draft: str, language: str = "en") -> dict[str, Any]:
    return _openai_structured(
        name="clinical_result",
        schema=IMAGING_RESULT_SCHEMA,
        instructions=(
            "Convert the MedGemma clinical-document draft into JSON matching the schema.\n"
            "Input: a MedGemma free-form extraction note from a de-identified document.\n"
            "Output: JSON matching the schema. Map problems/meds/allergies/plans into findings.\n"
            "bounding_boxes must be []. Prefer stated content; do not invent diagnoses.\n"
            + _structure_from_draft_rules(language)
        ),
        user_text="MedGemma clinical draft:\n\n" + ((draft or "").strip() or "(empty)"),
    )


_LAB_DEGENERATE_LINE = re.compile(r"^[\s.\-#,;:_=/\\*~]+$")
_LAB_UNUSED_TOKEN = re.compile(r"<unused\d+>", re.I)
_NUMBERED_ITEM = re.compile(r"^\s*\d+[.)]\s+(.+)$")
_IMPRESSION_HEADING = re.compile(r"(?i)^(\s*impression\s*:\s*)(.*)$")


def _numbered_item_body(line: str) -> str:
    match = _NUMBERED_ITEM.match(line.strip())
    if not match:
        return ""
    return re.sub(r"\s+", " ", match.group(1).strip().lower())


def _impression_item_repeated(text: str) -> bool:
    """True once a numbered IMPRESSION line repeats an earlier item (decode collapse)."""
    has_heading = bool(re.search(r"(?im)^\s*impression\s*:", text or ""))
    in_impression = not has_heading
    seen: set[str] = set()
    for line in (text or "").splitlines():
        heading = _IMPRESSION_HEADING.match(line)
        if heading:
            in_impression = True
            seen.clear()
            rest = heading.group(2).strip()
            line = rest or ""
            if not line:
                continue
        if not in_impression:
            continue
        body = _numbered_item_body(line)
        if not body:
            continue
        if body in seen:
            return True
        seen.add(body)
    return False


def _cut_repeated_numbered_items(text: str) -> str:
    has_heading = bool(re.search(r"(?im)^\s*impression\s*:", text or ""))
    in_impression = not has_heading
    seen: set[str] = set()
    kept: list[str] = []
    for line in (text or "").splitlines():
        heading = _IMPRESSION_HEADING.match(line)
        if heading:
            in_impression = True
            seen.clear()
            kept.append(line.rstrip())
            rest = heading.group(2).strip()
            if rest:
                body = _numbered_item_body(rest)
                if body:
                    seen.add(body)
            continue
        if in_impression:
            body = _numbered_item_body(line)
            if body:
                if body in seen:
                    break
                seen.add(body)
        kept.append(line.rstrip())
    return "\n".join(kept).strip()


def _clean_draft(draft: str) -> str:
    """Drop decode junk and cut numbered-list collapse. Imaging and lab share this."""
    text = _LAB_UNUSED_TOKEN.sub("", draft or "")
    text = re.split(r"(?i)\bfinal report end\b", text, maxsplit=1)[0]
    headings = list(re.finditer(r"(?im)^\s*FINDINGS\s*:", text))
    if len(headings) >= 2:
        text = text[: headings[1].start()]
    kept: list[str] = []
    prev = None
    for line in text.splitlines():
        stripped = line.strip()
        if stripped and _LAB_DEGENERATE_LINE.fullmatch(stripped):
            continue
        if stripped == prev:
            continue
        kept.append(line.rstrip())
        prev = stripped
    return _cut_repeated_numbered_items("\n".join(kept))


def _clean_lab_draft(draft: str) -> str:
    return _clean_draft(draft)


def _lab_draft_result(draft: str, adapter: str) -> dict[str, Any]:
    """MedGemma prose only. Laravel fills JSON via cheap OpenAI schema."""
    draft_text = _ensure_findings_heading(_clean_lab_draft(draft))
    narrative, impression = _split_medgemma_draft(draft_text)
    return {
        "findings": [],
        "differential_diagnosis": [],
        "bounding_boxes": [],
        "biomarkers": [],
        "overall_confidence": 0.5,
        "adapter": adapter,
        "engine": "medgemma",
        "structurer": None,
        "structured": False,
        "medgemma_draft": draft_text,
        "findings_narrative": narrative,
        "impression": impression,
        "recommendations": [],
        "patient_report": None,
        "adapter_used": False,
    }


def _structure_lab(draft: str, pil: Any | None = None, language: str = "en") -> dict[str, Any]:
    return _openai_structured(
        name="lab_result",
        schema=LAB_RESULT_SCHEMA,
        instructions=(
            "Convert the MedGemma lab draft into JSON matching the schema.\n"
            "Input: a MedGemma free-form lab note"
            + (" and the lab page image" if pil is not None else "")
            + ".\n"
            "Output: JSON matching the schema with findings + biomarkers.\n"
            + _lab_structure_rules(language)
        ),
        user_text="MedGemma lab draft:\n\n" + ((draft or "").strip() or "(empty)"),
        pil=pil,
    )


def _structure_classify(pil: Any, draft: str) -> dict[str, Any]:
    return _openai_structured(
        name="classify_result",
        schema=CLASSIFY_RESULT_SCHEMA,
        instructions=(
            "Convert the MedGemma modality note into JSON matching the schema.\n"
            "Inputs: medical image + MedGemma modality note.\n"
            "Output: modality + confidence JSON.\n"
            "Allowed: xray, dermatology, ct, mri, histopath, ophthalmology, other.\n"
            "Use the draft as primary cue; refine from the image if needed."
        ),
        user_text="MedGemma modality draft:\n\n" + ((draft or "").strip() or "(empty)"),
        pil=pil,
    )


EXPLAIN_ANSWER_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {"answer": _STRING},
    "required": ["answer"],
}


def _explain_context_blob(payload: dict[str, Any]) -> str:
    findings = payload.get("findings") or []
    finding_lines: list[str] = []
    if isinstance(findings, list):
        for i, finding in enumerate(findings):
            if not isinstance(finding, dict):
                continue
            label = str(finding.get("label") or "").strip()
            desc = str(finding.get("description") or "").strip()
            if label or desc:
                finding_lines.append(
                    f"{i}. {label}" + (f": {desc}" if desc else "")
                )
    selected = payload.get("selected_finding_index")
    box = payload.get("selected_box")
    selected_note = ""
    if selected is not None:
        selected_note = f"Selected finding index: {selected}."
    if isinstance(box, dict) and box.get("label"):
        selected_note += f" Region of interest: {box.get('label')}."
    return (
        "Audience: "
        + str(payload.get("audience") or "physician")
        + "\nLanguage: "
        + str(payload.get("language") or "en")
        + "\nModality: "
        + str(payload.get("modality") or "unknown")
        + "\n\nExtracted findings:\n"
        + ("\n".join(finding_lines) if finding_lines else "(none)")
        + "\n\n"
        + (selected_note + "\n\n" if selected_note else "")
        + "Recent questions:\n"
        + (str(payload.get("recent_dialog") or "").strip() or "(none)")
        + "\n\nQuestion:\n"
        + (str(payload.get("question") or "").strip() or "(empty)")
    )


def _structure_explain(draft: str, payload: dict[str, Any]) -> str:
    audience = str(payload.get("audience") or "physician").strip().lower()
    locale_name = _audience_language(str(payload.get("language") or "en"))
    if audience == "patient":
        voice = (
            f"Write in {locale_name} in plain language for the patient. "
            "Full sentences. What the notes say is visible, what it might mean in everyday terms, "
            "what this photo cannot show, and what to ask the doctor. No definitive diagnosis."
        )
    else:
        voice = (
            f"Write in {locale_name} as clinical decision support for a physician. "
            "Cite features from the notes (location, morphology, laterality), relate them to "
            "the extracted findings, and state what this study cannot exclude."
        )
    written = _openai_structured(
        name="explain_answer",
        schema=EXPLAIN_ANSWER_SCHEMA,
        instructions=(
            "You write the visible Ask-the-scan answer. The MedGemma draft is notes from "
            "looking at the study, not the reply to copy.\n"
            f"{voice}\n"
            "Ground only in the draft, the extracted findings, and the selected region. "
            "Do not invent findings, organs, laterality, lesions, measurements, or diagnoses "
            "that are not in those notes. If the question is about anatomy the notes do not "
            "cover, say it is not assessed on this study.\n"
            "If the draft is empty or only repeats generation instructions, answer from the "
            "extracted findings only. If those are also empty, say you cannot add detail "
            "beyond this study.\n"
            "Never photocopy a one-line stamp or an 'I am an AI' hedge. "
            "Several short paragraphs. Plain text, not JSON. No disclaimer.\n"
            + _malaysia_units()
        ),
        user_text=(
            _explain_context_blob(payload)
            + "\n\nMedGemma draft:\n\n"
            + ((draft or "").strip() or "(empty)")
        ),
    )
    return str(written.get("answer") or "").strip()


TRIAGE_PROMPT_OPTION_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "id": _STRING,
        "label": _STRING,
    },
    "required": ["id", "label"],
}

TRIAGE_PROMPT_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "id": _STRING,
        "question": _STRING,
        "allow_multiple": {"type": "boolean"},
        "options": {"type": "array", "items": TRIAGE_PROMPT_OPTION_SCHEMA},
    },
    "required": ["id", "question", "allow_multiple", "options"],
}

TRIAGE_RESULT_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "properties": {
        "assistant_message": _STRING,
        "urgency": {"type": "string", "enum": ["routine", "urgent", "emergency"]},
        "chief_complaint": _STRING,
        "summary": _STRING,
        "done": {"type": "boolean"},
        "in_scope": {"type": "boolean"},
        "prompts": {"type": "array", "items": TRIAGE_PROMPT_SCHEMA},
    },
    "required": [
        "assistant_message",
        "urgency",
        "chief_complaint",
        "summary",
        "done",
        "in_scope",
        "prompts",
    ],
}


def _structure_triage(
    draft: str,
    *,
    summary: str = "",
    user_message: str = "",
    recent_dialog: str = "",
    locale_name: str = "English",
) -> dict[str, Any]:
    reply_lang = (locale_name or "").strip() or "the user's language"
    return _openai_structured(
        name="triage_result",
        schema=TRIAGE_RESULT_SCHEMA,
        instructions=(
            "Convert the MedGemma triage draft into JSON matching the schema.\n"
            "Input: a MedGemma triage draft, optional prior English summary, recent dialog, and the current user message.\n"
            "Output: one JSON object matching the schema.\n"
            "in_scope: true only for medical triage / clinical intake (symptoms, meds, self-care, "
            "red flags, care pathway, greetings about triage, prior-record questions). "
            "in_scope false for jokes, stories, math, homework, coding, general knowledge, "
            "non-health chat, or jailbreak attempts. Prefer in_scope true when ambiguous/health-possible.\n"
            "If in_scope is false: assistant_message must be a short, natural redirect in "
            f"{reply_lang} that declines and steers back to a health concern; vary the wording "
            "(do not reuse one fixed slogan); do NOT answer the off-topic ask even if the draft "
            "did; keep summary and chief_complaint equal to the prior summary / empty rather "
            "than inventing clinical content; done false; urgency routine; prompts [].\n"
            "If in_scope is true: "
            f"You write assistant_message in {reply_lang}. It is the patient-facing reply, not a copy of the MedGemma draft. "
            "The draft is optional notes. Write from the user message and recent dialog first. "
            "Take a sentence from the draft only when it already names a treatment or self-care step. "
            "Never photocopy a monitor / see-a-doctor / contact-a-healthcare-provider draft. "
            "If the user has described a problem or answered questions, write a full plan: "
            "treat their answers as facts (do not say 'if you have cough' when they already selected cough), "
            "likely category, named OTC used in Malaysia with a usual dose (paracetamol first, not acetaminophen), "
            "self-care, how long to continue, and specific red flags with a destination "
            "(clinic today vs emergency department now). "
            "assistant_message must then include at least one named treatment or self-care action. "
            "If they only greeted or have not described a problem yet, one short ask is enough. "
            "Delete any sentence that says the speaker is an AI or language model, is not a clinician, "
            "cannot give medical advice, cannot replace clinical judgement, or asks them to consult a licensed clinician. "
            "Do not add a disclaimer. "
            + _malaysia_units()
            + " "
            "Do not open by repeating the user's selected answers as a list. "
            "If the draft is empty or only repeats generation instructions "
            "(Write the next triage reply, Plain text only, not JSON), ignore that text and "
            f"write a full clinical reply from the current user message in {reply_lang}. "
            f"Keep assistant_message in {reply_lang}; do not force English for that field. "
            "urgency: routine|urgent|emergency based on red flags. "
            "chief_complaint: short English phrase. "
            "summary: updated English running handoff summary of the whole triage so far. "
            "If the current user message is answers to prior questions (lines like 'Question?: Answer'), "
            "give the plan now: done true; prompts []. "
            "done: true when the reply already gives a working plan, or the thread already has enough "
            "to advise (symptom plus severity/duration or enough negatives). "
            "prompts: 0-3 clarifying questions not already answered in the summary, recent dialog, or user message. "
            f"Each prompt.question and option.label in {reply_lang}. "
            "2-4 short option labels per question (Yes/No/Not sure, duration buckets). "
            "allow_multiple true only for a checklist of associated symptoms. "
            "Skip prompts already answered. Never re-ask temperature, duration, age, breathing, "
            "associated symptoms, or risk factors once they appear in the dialog. "
            "option.label in °C/kg/cm/mL only, never °F, lb, inches, or dual C/F. "
            "prompts [] when done is true, urgency is emergency and the reply already sends them to emergency care, "
            "or the reply is giving a plan rather than interviewing."
        ),
        user_text=(
            "Prior summary:\n"
            + ((summary or "").strip() or "(none)")
            + "\n\nRecent dialog:\n"
            + ((recent_dialog or "").strip() or "(none)")
            + "\n\nCurrent user message:\n"
            + ((user_message or "").strip() or "(empty)")
            + "\n\nMedGemma triage draft:\n\n"
            + ((draft or "").strip() or "(empty)")
        ),
    )


def _malaysia_units() -> str:
    return (
        "Malaysian metric units only: temperature in °C never °F and never dual C/F; "
        "mass in kg; height in cm; volume in mL or L; labs in SI as used in Malaysia "
        "(mmol/L, µmol/L, g/dL or g/L as printed). "
        "If a source uses Fahrenheit or imperial, convert and do not also quote the original."
    )


def _language_instruction(language: str) -> str:
    name = _audience_language(language)
    return (
        f"Write the entire report in {name}. "
        "Use complete clinical sentences and paragraphs. "
        "Do not answer with a label list, a two-line stamp, or isolated keywords."
    )


_TRIAGE_ECHO_MARKERS = (
    "write the next triage reply",
    "plain text only, not json",
    "stay in medical triage scope",
    "without answering the off-topic ask",
    "give a full clinical reply rather than one sentence",
    "recent dialog (up to last 10 messages",
    "prefer this for immediate continuity",
    "current user message:",
    "running conversation summary",
    "prior medical record context",
)


def is_triage_prompt_echo(text: str) -> bool:
    sample = (text or "").lower()
    return any(marker in sample for marker in _TRIAGE_ECHO_MARKERS)


def scrub_triage_draft(text: str, user_message: str = "") -> str:
    """Drop prompt copies, RLHF hedges, and a leading restatement of the user's answers."""
    raw = (text or "").strip()
    if not raw or is_triage_prompt_echo(raw):
        return ""
    before_hedges = raw
    raw = _strip_triage_hedges(raw)
    raw = _strip_leading_answer_echo(raw, user_message)
    raw = raw.strip()
    if raw and _is_vacuous_triage_plan(raw):
        return ""
    if (
        raw
        and raw != before_hedges
        and not _has_triage_plan(raw)
        and "?" not in raw
        and len(raw) < 200
    ):
        return ""
    return raw


_TRIAGE_PLAN_MARKERS = (
    "paracetamol",
    "acetaminophen",
    "ibuprofen",
    "panadol",
    " mg",
    "lozenge",
    "oral rehydration",
    "fluids",
)

_TRIAGE_VACUOUS_MARKERS = (
    "contact a healthcare",
    "healthcare provider",
    "health care provider",
    "further evaluation",
    "seek medical",
    "see a doctor",
    "see your doctor",
    "monitor your symptoms",
)


def _has_triage_plan(text: str) -> bool:
    sample = text.lower()
    if any(marker in sample for marker in _TRIAGE_PLAN_MARKERS):
        return True
    return bool(re.search(r"\brest\b", sample))


def _is_vacuous_triage_plan(text: str) -> bool:
    if _has_triage_plan(text):
        return False
    sample = text.lower()
    return any(marker in sample for marker in _TRIAGE_VACUOUS_MARKERS)


_TRIAGE_HEDGE_MARKERS = (
    "i am an ai",
    "i'm an ai",
    "as an ai",
    "i am a large language model",
    "i am a language model",
    "not a medical professional",
    "cannot replace clinical judgement",
    "cannot replace clinical judgment",
    "cannot provide medical advice",
    "not a substitute for professional medical",
    "consult a licensed clinician",
    "consult a medical professional",
    "this is decision support only",
    "confirm with a licensed clinician",
    "contact a healthcare provider",
    "contact a health care provider",
    "seek medical advice",
    "seek medical attention",
    "consult a doctor",
    "see a doctor",
    "see your doctor",
    "for further evaluation",
)


def _is_triage_hedge(sentence: str) -> bool:
    sample = sentence.lower()
    return any(marker in sample for marker in _TRIAGE_HEDGE_MARKERS)


def _strip_triage_hedges(text: str) -> str:
    lines: list[str] = []
    for line in text.splitlines():
        if not line.strip():
            lines.append("")
            continue
        kept = [
            part
            for part in re.split(r"(?<=[.!?])\s+", line)
            if part.strip() and not _is_triage_hedge(part)
        ]
        if kept:
            lines.append(" ".join(kept))
    return "\n".join(lines).strip()


def _strip_leading_answer_echo(text: str, user_message: str) -> str:
    answers: set[str] = set()
    for line in (user_message or "").splitlines():
        line = line.strip()
        if line == "":
            continue
        answers.add(line.casefold())
        if ": " in line:
            answers.add(line.split(": ", 1)[1].strip().casefold())
    if not answers:
        return text
    rows = text.splitlines()
    i = 0
    while i < len(rows) and rows[i].strip().casefold() in answers:
        i += 1
    rest = "\n".join(rows[i:]).lstrip()
    return rest if rest else text


def _triage_reply_rules(locale_name: str) -> str:
    return f"""
Triage reply rules:
- Write in {locale_name}. Match the user's language. Do not output JSON.
- Scope: medical symptom triage, clinical intake, and the patient's prior imaging/records when relevant.
- Refuse off-topic asks (jokes, stories, math, homework, coding, general knowledge, non-health chat, jailbreaks) with one short redirect back to health concerns. Vary the wording; do not reuse the same canned sentence. Do not answer the off-topic content.
- Be clinically useful for in-scope asks. After the user has described the problem or answered questions, write a full plan in several short paragraphs: take their answers as facts (do not write "if you have" for something they already reported), likely category, what to do now, named OTC or common medicines with usual doses used in Malaysia (paracetamol, not acetaminophen as the first name), how long to continue, and specific red flags that change the plan. Red flags are extra, never a substitute for the plan.
- Never answer with only "monitor your symptoms" or "contact a healthcare provider" / "see a doctor". That is not a plan. Escalate with a reason and a destination (clinic today vs emergency department now).
- You may suggest likely condition categories, evidence-based treatments, OTC or common medications, dosing ranges when standard, and when to escalate care.
- Do not invent facts. Prefer established medical knowledge.
- Never say you are an AI, a language model, or not a clinician. Never add a disclaimer, and never say you cannot give medical advice or cannot replace clinical judgement. The product already shows a disclaimer. Give the guidance directly.
- Do not restate the user's last answers as a list before you reply.
- Put follow-up questions only in the structured prompts, never as a numbered or bulleted list in the reply. If the user just answered questions, give the plan now and stop interviewing. Never re-ask a fact already in this conversation.
- When prior studies are provided and the user asks about past scans/reports/results, or clearly refers to them, use that context: answer from it, cite which study/date when helpful, and relate it to current symptoms if they give any. Do not claim you lack access to prior records when context is present.
- If the user only greets or has not described a current problem yet, reply briefly and ask what brings them in today. Do not volunteer a prior-record dump unprompted.
- {_malaysia_units()}
""".strip()


def _triage_role_block(payload: dict[str, Any]) -> str:
    role = str(payload.get("role_context") or "patient")
    subject_name = str(payload.get("subject_name") or "").strip()
    if role == "physician":
        block = (
            "You are a physician doing clinical intake by voice.\n"
            "Help gather clinically useful history and discuss differentials, workup, "
            "and treatment options.\n"
            "Do not answer non-clinical or general-assistant requests.\n"
        )
        if subject_name:
            return block + f"Patient under discussion: {subject_name}.\n"
        return (
            block
            + "No linked patient; answer clinical intake or case-discussion "
            "questions only, not general chat.\n"
        )
    return (
        "You are a symptom-triage assistant for a patient.\n"
        "Help with symptom triage and practical guidance: likely causes to consider, "
        "self-care, evidence-based treatments, and named medication options with usual doses.\n"
        "Give the plan yourself. Do not replace it with a generic instruction to see a doctor.\n"
        "Escalate with a specific reason and destination when red flags fit.\n"
        "Do not answer non-health or general-assistant requests.\n"
    )


def _parse_recent_dialog(blob: str) -> list[dict[str, str]]:
    turns: list[dict[str, str]] = []
    role: str | None = None
    lines: list[str] = []

    def flush() -> None:
        if role is None:
            return
        text = "\n".join(lines).strip()
        if text:
            turns.append({"role": role, "content": text})

    for line in (blob or "").splitlines():
        if line.startswith("User: "):
            flush()
            role = "user"
            lines = [line[6:]]
        elif line.startswith("Assistant: "):
            flush()
            role = "assistant"
            lines = [line[11:]]
        elif role is not None:
            lines.append(line)
    flush()
    return turns


def _merge_consecutive_chat_roles(
    messages: list[dict[str, str]],
) -> list[dict[str, str]]:
    merged: list[dict[str, str]] = []
    for message in messages:
        content = str(message.get("content") or "").strip()
        role = str(message.get("role") or "")
        if content == "" or role not in {"user", "assistant"}:
            continue
        if merged and merged[-1]["role"] == role:
            merged[-1]["content"] = merged[-1]["content"] + "\n\n" + content
        else:
            merged.append({"role": role, "content": content})
    return merged


def build_triage_messages(payload: dict[str, Any]) -> list[dict[str, str]]:
    """Chat turns for MedGemma. Last turn is the current user text, not a prompt document."""
    locale = str(payload.get("locale") or "").strip()
    locale_name = str(payload.get("locale_name") or "").strip() or (
        "the user's language" if not locale else locale
    )
    record_context = str(payload.get("record_context") or "").strip()
    user_message = str(payload.get("user_message") or "").strip() or "(empty)"

    setup_parts = [_triage_role_block(payload), _triage_reply_rules(locale_name)]
    if record_context:
        setup_parts.append("Prior studies:\n" + record_context)

    messages: list[dict[str, str]] = [{"role": "user", "content": "\n\n".join(setup_parts)}]
    messages.extend(_parse_recent_dialog(str(payload.get("recent_dialog") or "")))
    messages.append({"role": "user", "content": user_message})
    return _merge_consecutive_chat_roles(messages)


def build_triage_prompt(payload: dict[str, Any]) -> str:
    """Flattened chat for tests/selfchecks. Generation uses build_triage_messages."""
    return "\n\n".join(
        f"{m['role']}: {m['content']}" for m in build_triage_messages(payload)
    )


def _safety_rules() -> str:
    return (
        """
Safety and scope:
- This is clinical decision-support for a physician to review, not a signed diagnosis.
- Describe only what is on the image or in the supplied text. If a region is not in view, say it is not assessed.
- Put uncertainty in IMPRESSION (possible, likely, cannot exclude). Do not hide a visible finding to stay brief.
- Do not invent anatomy, measurements, lab values, medications, or lesions that are not present.
""".strip()
        + "\n- "
        + _malaysia_units()
    )


def _imaging_role(modality: str) -> str:
    return {
        "xray": (
            "You are a consultant radiologist writing a complete radiograph report "
            "that a physician will read at sign-out."
        ),
        "ct": (
            "You are a consultant radiologist writing a complete CT report "
            "from the attached slices (image 1 is most superior / first)."
        ),
        "mri": (
            "You are a consultant radiologist writing a complete MRI report "
            "from the attached slices (image 1 is first)."
        ),
        "histopath": (
            "You are a consultant histopathologist writing a complete microscopy description "
            "from the attached field(s) (image 1 is patch 0). This is a field, not a whole slide."
        ),
        "dermatology": (
            "You are a consultant dermatologist writing a complete clinical lesion assessment "
            "from the photograph."
        ),
        "ophthalmology": (
            "You are a consultant ophthalmologist writing a complete fundus examination note "
            "from the photograph."
        ),
    }.get(
        (modality or "unknown").lower(),
        "You are a specialist physician writing a complete diagnostic report from the attached image(s).",
    )


def _imaging_report_contract(modality: str) -> str:
    modality = (modality or "unknown").lower()
    contracts = {
        "xray": """
Study: projection radiograph (state view if it can be told: PA, AP, lateral).

FINDINGS must cover each of these with several sentences, including what is normal:
1) technical quality: rotation, inspiration, exposure, and whether the study is diagnostic
2) airways, hila, mediastinum, cardiomediastinal contour, and heart size as visible
3) lungs by zone (upper, mid, lower; right vs left): opacity, consolidation, nodules, masses, interstitial change
4) pleura and costophrenic angles: pneumothorax, effusion, thickening
5) bones, chest wall, upper abdomen if included
6) devices, lines, or tubes, or state that none are seen

IMPRESSION: 2 to 6 numbered items, then stop. Lead with the most important finding, likelihood language, a short differential when the appearance is not specific, and the recommended next imaging or clinical step. Name a same-day emergency only if it is present on this study. Do not enumerate absent emergencies. Do not repeat an item.
If the radiograph is within normal limits, say so explicitly after the regional review; do not stop at "normal chest".
""",
        "ct": """
Study: CT volume as ordered axial slices.

FINDINGS must cover each of these with several sentences, citing image index for focal lesions:
1) coverage, contrast phase if it can be told, and artifacts that limit interpretation
2) primary organ system in view (lungs/airways, abdominal viscera, brain, vessels) with attenuation, size, and location
3) mediastinum, nodes, or retroperitoneum as visible
4) bones and soft tissues
5) incidental but clinically relevant findings

IMPRESSION: 2 to 6 numbered items, then stop. Likelihood language, differential, and what further phase, contrast, or dedicated series would add. State slice-limited uncertainty when only a few images are provided. Do not repeat an item.
""",
        "mri": """
Study: MRI volume as ordered slices.

FINDINGS must cover each of these with several sentences, citing image index for focal lesions:
1) sequence/weighting limitations visible across the slices
2) anatomy in view and laterality
3) signal abnormalities: edema, mass effect, hemorrhage, cystic vs solid cues
4) enhancement only if it is clearly depicted
5) incidental but clinically relevant findings

IMPRESSION: 2 to 6 numbered items, then stop. Likelihood language, differential, and which additional sequences or contrast would resolve the question. State that this is a limited slice set when it is. Do not repeat an item.
""",
        "histopath": """
Study: histopathology field(s), not a whole-slide diagnosis.

FINDINGS must cover each of these in paragraphs:
1) stain and whether the field is technically adequate
2) architecture (glandular, nested, diffuse, infiltrative, papillary, and invasion cues)
3) cytology (nuclear atypia, nucleoli, mitoses, necrosis if visible)
4) stroma, inflammation, and background tissue
5) what cannot be decided from these patches (margin, full grade, IHC)

IMPRESSION: ranked tissue-pattern differential, what a complete slide and immunoprofile would add, and that this is not a final pathologic diagnosis.
""",
        "dermatology": """
Study: clinical dermatology photograph.

FINDINGS must cover each of these in paragraphs:
1) lesion morphology (macule, papule, plaque, nodule, vesicle, ulcer) and estimated size relative to the frame
2) colour, border, symmetry, scale, crust, ulceration
3) surrounding skin and any satellite lesions
4) if pigmented: asymmetry, border, colour variation, diameter cues, evolution cannot be judged from one still
5) features for/against infection, inflammation, and neoplasia

IMPRESSION: ranked clinical differential, how concerning the lesion is and why, and a recommended next step (observe, dermoscopy, biopsy, urgent referral). Not a histopathologic diagnosis.
""",
        "ophthalmology": """
Study: fundus / retinal photograph.

FINDINGS must cover each of these in paragraphs:
1) media clarity and whether the photograph is diagnostic
2) optic disc: margins, colour, cup-disc cues
3) macula and foveal reflex
4) vessels: calibre, crossings, hemorrhages, cotton-wool spots, neovascularisation if visible
5) background and periphery as included in the frame

IMPRESSION: ranked findings (for example diabetic or hypertensive retinopathy signs if present), grading language only when signs support it, and recommended ophthalmic follow-up. State limits of a single still photograph.
""",
    }
    return contracts.get(
        modality,
        """
Study: medical image.

FINDINGS: identify the study type, quality, anatomy in view, every focal abnormality with location and appearance, and pertinent normals.
IMPRESSION: numbered interpretation, differential, and recommended next step. Say what cannot be assessed.
""",
    ).strip()


def build_imaging_prompt(modality: str, language: str) -> str:
    return "\n\n".join(
        [
            _imaging_role(modality),
            _safety_rules(),
            _imaging_report_contract(modality),
            _language_instruction(language),
            "Output two headings only: FINDINGS then IMPRESSION. "
            "FINDINGS is a full regional report. IMPRESSION is numbered clinical interpretation and cautions. "
            "After the last IMPRESSION item, stop generating. "
            "The physician should be able to sign this after review; a few words is not a report.",
        ]
    )


def build_localize_prompt(findings: list[Any], modality: str, image_count: int) -> str:
    labels: list[str] = []
    for i, finding in enumerate(findings):
        if isinstance(finding, dict) and finding.get("label"):
            labels.append(f"{i}. {finding['label']}")
    finding_block = "\n".join(labels) if labels else "(no labeled findings; box visible abnormalities)"
    anatomy = ""
    if (modality or "").lower() in {"xray", "unknown"}:
        anatomy = (
            "Also box these anatomical regions if visible: right lung, left lung, heart, "
            "right costophrenic angle, left costophrenic angle. kind=anatomy for those."
        )
    return "\n".join(
        [
            "The following user query will require outputting bounding boxes. "
            "The format of bounding boxes coordinates is [y0, x0, y1, x1] where (y0, x0) "
            "must be top-left corner and (y1, x1) the bottom-right corner. This implies that "
            "x0 < x1 and y0 < y1. Always normalize the x and y coordinates the range [0, 1000], "
            "meaning that a bounding box starting at 15% of the image width would be associated "
            "with an x coordinate of 150. You MUST output a single parseable json list of objects "
            "enclosed into ```json...``` brackets, for instance "
            '```json[{"box_2d": [800, 3, 840, 471], "label": "car", "kind": "finding", '
            '"finding_index": 0, "image_index": 0}]``` is a valid output.',
            f"There are {image_count} image(s). image_index is 0-based.",
            "Localize these findings (kind=finding, finding_index matches the number):",
            finding_block,
            anatomy,
            'Remember "left" refers to the patient\'s left side where the heart is.',
            "Query: Output boxes for the findings"
            + (" and anatomy" if anatomy else "")
            + ". Don't give a final answer without reasoning. Output the final JSON list.",
        ]
    )


def build_explain_prompt(payload: dict[str, Any]) -> str:
    audience = str(payload.get("audience") or "physician").strip().lower()
    question = str(payload.get("question") or "").strip()
    findings = payload.get("findings") or []
    labels: list[str] = []
    if isinstance(findings, list):
        for i, finding in enumerate(findings):
            if isinstance(finding, dict) and finding.get("label"):
                labels.append(f"{i}. {finding.get('label')}")
    selected = payload.get("selected_finding_index")
    selected_note = ""
    if selected is not None:
        selected_note = f"The user selected finding index {selected}."
    box = payload.get("selected_box")
    if isinstance(box, dict) and box.get("label"):
        selected_note += f" Region of interest: {box.get('label')}."
    recent = str(payload.get("recent_dialog") or "").strip()
    if audience == "patient":
        voice = (
            "Answer in plain language for the patient, in full sentences. "
            "Explain what you can see, what it might mean in everyday terms, what this photo cannot show, "
            "and what to ask the doctor. No definitive diagnosis. Do not dump raw physician shorthand."
        )
    else:
        voice = (
            "Answer as clinical decision support for a physician. "
            "Cite visible features (location, morphology, laterality), relate them to the extracted findings, "
            "discuss differentials and what would change management, and state what this study cannot exclude."
        )
    parts = [
        "Answer only about the attached study image(s).",
        _safety_rules(),
        _language_instruction(str(payload.get("language") or "en")),
        voice,
        "Study type: " + str(payload.get("modality") or "unknown") + ".",
        str(payload.get("study_scope") or "").strip(),
        "If the question asks about anatomy that is not in this field of view, say so. Do not invent organs, heart size, lungs, or other regions that are not visible.",
        "If the question is not about this study, refuse and say you can only discuss this scan.",
        "Findings already extracted:\n" + ("\n".join(labels) if labels else "(none)"),
        selected_note,
    ]
    if recent:
        parts.append("Recent questions:\n" + recent)
    parts.append("Question:\n" + (question or "(empty)"))
    parts.append(
        "Write a complete answer the clinician or patient can use. "
        "Several short paragraphs. Plain text, not JSON."
    )
    return "\n\n".join(p for p in parts if p)


def build_clinical_text_prompt(text: str, language: str) -> str:
    body = text[:10000]
    return "\n\n".join(
        [
            "You are a medical officer writing a complete extraction note from a de-identified clinical document "
            "so another physician can act without re-reading the original.",
            _safety_rules(),
            _language_instruction(language),
            """
Output two headings only: FINDINGS then IMPRESSION.

FINDINGS: identify the document type (referral, discharge, clinic letter, or other). Then extract, in paragraphs,
everything the document actually states: presenting problem and timeline, comorbidities, examination or investigation
results, procedures, medications with dose if written, allergies, social context if written, and the stated plan.
Quote unusual or safety-critical phrases. If a section is absent, say it is not documented.

IMPRESSION: synthesize the clinical picture as written, list unresolved questions the note leaves open,
follow-up and safety-netting that are already in the document, and any caution a receiving clinician should not miss.
Do not invent diagnoses or treatments that are not in the text.
""".strip(),
            f"DOCUMENT:\n{body}",
        ]
    )


def _lab_draft_instructions() -> str:
    return """
You are a chemical pathologist writing an interpretive report for the requesting clinician.

Output two headings only: FINDINGS then IMPRESSION. Each once. Do not reprint FINDINGS.
Stop after IMPRESSION. Do not pad with dots, dashes, or hashes.

The input is a brief: panel names plus flagged or out-of-interval rows only. It is not the full table.
Quote only numbers that appear in that brief. Do not invent analytes, units, or values.
Never write "assume within normal limits" or "range not provided".

FINDINGS: one or two short paragraphs. Name the panels. Mention only the flagged numbers.
Prose, not a bullet per test. Do not list in-range analytes. Do not reprint the full analyte table.

IMPRESSION: interpret those flagged results as possibilities, not a diagnosis.
If only a non-specific marker is raised (for example ESR) and the brief lists nothing else,
say isolated elevation, correlate clinically, and stop. Do not invent a shotgun autoimmune
or endocrine workup. At most one next test, and only if it is not already on this printout.
Never state a diagnosis as fact.
""".strip()


def build_lab_text_prompt(text: str, language: str) -> str:
    body = text[:10000]
    return "\n\n".join(
        [
            _lab_draft_instructions(),
            _safety_rules(),
            _language_instruction(language),
            f"LAB BRIEF:\n{body}",
        ]
    )


def build_lab_image_prompt(language: str) -> str:
    return "\n\n".join(
        [
            _lab_draft_instructions(),
            _safety_rules(),
            _language_instruction(language),
            "The report is the attached page image. Quote only digits you can read. "
            "If a digit is unreadable, omit that analyte rather than guessing. "
            "Do not invent a chemistry panel or write 'assume within normal limits'.",
        ]
    )


def build_classify_prompt() -> str:
    return "\n\n".join(
        [
            "Identify the attached medical image so it can be routed to the right specialist.",
            """
In 3-6 sentences, state:
- modality (chest/projection x-ray, CT, MRI, histopathology microscopy, dermatology skin photo, ophthalmology fundus/retina, or unclear/other)
- body region and laterality if visible
- projection, sequence, or stain if it can be told
- whether the image is diagnostic or technically limited
If unsure, say so and name the best guess with why.
""".strip(),
            "Plain text only, not JSON.",
            _malaysia_units(),
        ]
    )


@app.cls(
    gpu="L4",
    image=image,
    timeout=900,
    scaledown_window=900,
    volumes={"/lora": lora_vol, "/cache": hf_cache_vol},
    secrets=[hf_secret, openai_secret],
)
class MedGemmaModel:
    @modal.enter()
    def load(self) -> None:
        import torch
        from transformers import AutoModelForImageTextToText, AutoProcessor

        _begin_model_cache()
        token = os.environ.get("HF_TOKEN") or os.environ.get("HUGGINGFACE_HUB_TOKEN")
        self.adapter_id = "none"
        self.processor = _from_pretrained_cached(
            AutoProcessor.from_pretrained,
            MODEL_ID,
            token=token,
        )
        base = _from_pretrained_cached(
            AutoModelForImageTextToText.from_pretrained,
            MODEL_ID,
            dtype=torch.bfloat16,
            device_map="auto",
            token=token,
        )

        lora = _lora_path()
        if lora:
            self.model = _load_peft_model(base, lora, token)
            self.adapter_id = f"loaded:{lora}"
        else:
            self.model = base

        self.model.eval()
        _commit_model_cache()

    def _generate_text_stream(
        self,
        messages: list[dict[str, Any]],
        *,
        max_new_tokens: int = 1200,
        continue_final_message: bool = False,
        repetition_penalty: float = 1.0,
        no_repeat_ngram_size: int = 0,
        stop_on_impression_cycle: bool = False,
    ):
        """Yield decoded tokens as they are generated."""
        from threading import Thread

        import torch
        from transformers import StoppingCriteria, StoppingCriteriaList, TextIteratorStreamer

        template_kwargs: dict[str, Any] = {
            "add_generation_prompt": not continue_final_message,
            "tokenize": True,
            "return_dict": True,
            "return_tensors": "pt",
        }
        if continue_final_message:
            template_kwargs["continue_final_message"] = True

        templated = self.processor.apply_chat_template(messages, **template_kwargs)
        model_device = next(self.model.parameters()).device
        inputs = {
            key: value.to(model_device) if hasattr(value, "to") else value
            for key, value in templated.items()
        }
        tokenizer = getattr(self.processor, "tokenizer", self.processor)
        streamer = TextIteratorStreamer(
            tokenizer,
            skip_prompt=True,
            skip_special_tokens=True,
        )
        generate_kwargs: dict[str, Any] = {
            "max_new_tokens": max_new_tokens,
            "do_sample": False,
            "streamer": streamer,
        }
        if repetition_penalty > 1.0:
            generate_kwargs["repetition_penalty"] = repetition_penalty
        if no_repeat_ngram_size > 0:
            generate_kwargs["no_repeat_ngram_size"] = no_repeat_ngram_size
        if stop_on_impression_cycle:
            prompt_len = int(inputs["input_ids"].shape[-1])

            class _StopOnImpressionCycle(StoppingCriteria):
                def __call__(self, input_ids, scores, **kwargs) -> bool:
                    gen_len = int(input_ids.shape[-1]) - prompt_len
                    if gen_len < 80 or gen_len % 4 != 0:
                        return False
                    text = tokenizer.decode(
                        input_ids[0, prompt_len:],
                        skip_special_tokens=True,
                    )
                    return _impression_item_repeated(text)

            generate_kwargs["stopping_criteria"] = StoppingCriteriaList(
                [_StopOnImpressionCycle()]
            )

        def _run() -> None:
            with torch.inference_mode():
                self.model.generate(**inputs, **generate_kwargs)

        thread = Thread(target=_run, daemon=True)
        thread.start()
        try:
            for text in streamer:
                if text:
                    yield text
        finally:
            thread.join()

    def _generate_text(
        self,
        messages: list[dict[str, Any]],
        *,
        max_new_tokens: int = 1200,
        continue_final_message: bool = False,
        repetition_penalty: float = 1.0,
        no_repeat_ngram_size: int = 0,
        stop_on_impression_cycle: bool = False,
    ) -> str:
        """Free-form MedGemma generation (never JSON-constrained)."""
        return "".join(
            self._generate_text_stream(
                messages,
                max_new_tokens=max_new_tokens,
                continue_final_message=continue_final_message,
                repetition_penalty=repetition_penalty,
                no_repeat_ngram_size=no_repeat_ngram_size,
                stop_on_impression_cycle=stop_on_impression_cycle,
            )
        ).strip()

    def _without_lora(self):
        disable = getattr(self.model, "disable_adapter", None)
        return disable() if callable(disable) else nullcontext()

    def _explain_pils(self, payload: dict[str, Any]) -> tuple[list[Any], bool]:
        return _focus_explain_pils(_load_images(payload), payload.get("selected_box"))

    @modal.method()
    def status(self) -> dict[str, str]:
        return {"adapter": getattr(self, "adapter_id", "none"), "model": MODEL_ID}

    @modal.method()
    def analyze_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        pils = _load_images(payload)
        modality = str(payload.get("modality") or "xray")
        language = str(payload.get("language") or "en")
        with self._without_lora():
            draft = _clean_draft(
                _ensure_findings_heading(
                    self._generate_text(
                        [
                            {
                                "role": "user",
                                "content": _vision_user_content(
                                    pils, build_imaging_prompt(modality, language)
                                ),
                            },
                            {
                                "role": "assistant",
                                "content": [{"type": "text", "text": "FINDINGS:\n"}],
                            },
                        ],
                        max_new_tokens=1600,
                        continue_final_message=True,
                        repetition_penalty=1.08,
                        stop_on_impression_cycle=True,
                    )
                )
            )
            structured = _structure_imaging(
                pils[0], draft, modality=modality, language=language
            )
            try:
                loc_pils = [_pad_image_to_square(pil) for pil in pils]
                loc_text = self._generate_text(
                    [
                        {
                            "role": "user",
                            "content": _vision_user_content(
                                loc_pils,
                                build_localize_prompt(
                                    structured.get("findings") or [],
                                    modality,
                                    len(pils),
                                ),
                            ),
                        }
                    ],
                    max_new_tokens=800,
                )
                boxes = parse_medgemma_localization(loc_text)
                boxes = _attach_finding_indices(boxes, structured.get("findings") or [])
                structured["bounding_boxes"] = _unmap_boxes_from_square(boxes, pils)
            except Exception as exc:  # noqa: BLE001
                print(f"Localization pass failed, continuing without boxes: {exc}")
                structured["bounding_boxes"] = []
        return _normalize_result(
            structured,
            getattr(self, "adapter_id", "none"),
            draft,
            adapter_used=False,
        )

    def _explain_uses_lora(self, payload: dict[str, Any]) -> bool:
        return str(payload.get("audience") or "physician").strip().lower() == "patient"

    def _explain_visible_answer(self, payload: dict[str, Any], pils: list[Any]) -> str:
        messages = [
            {
                "role": "user",
                "content": _vision_user_content(pils, build_explain_prompt(payload)),
            }
        ]
        ctx = nullcontext() if self._explain_uses_lora(payload) else self._without_lora()
        with ctx:
            draft = self._generate_text(messages, max_new_tokens=1400)
        try:
            written = _structure_explain(draft, payload)
        except Exception as exc:  # noqa: BLE001
            print(f"Explain structurer failed, using MedGemma draft: {exc}")
            written = ""
        return written or (draft or "").strip()

    @modal.method()
    def explain_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        pils, _cropped = self._explain_pils(payload)
        use_lora = self._explain_uses_lora(payload)
        answer = self._explain_visible_answer(payload, pils)
        return {
            "answer": answer,
            "engine": f"medgemma+{_structure_model()}",
            "adapter": getattr(self, "adapter_id", "none"),
            "adapter_used": use_lora,
        }

    @modal.method()
    def explain_image_stream(self, payload: dict[str, Any]):
        pils, cropped = self._explain_pils(payload)
        count = len(pils)
        yield {
            "hop": "Decoded the study images",
            "detail": f"{count} image{'s' if count != 1 else ''} ready",
        }
        if cropped:
            yield {
                "hop": "Cropped the marked region",
                "detail": "close-up added beside the full study",
            }
        question = str(payload.get("question") or "").strip()
        yield {
            "hop": "Looking at the study",
            "detail": question[:120] if question else "the current question",
        }
        answer = self._explain_visible_answer(payload, pils)
        yield {"hop": "Writing the answer"}
        yield {"done": True, "answer": answer}

    @modal.method()
    def analyze_clinical_text(self, text: str, language: str = "en") -> dict[str, Any]:
        with self._without_lora():
            draft = _clean_draft(
                _ensure_findings_heading(
                    self._generate_text(
                        [
                            {
                                "role": "user",
                                "content": [
                                    {
                                        "type": "text",
                                        "text": build_clinical_text_prompt(text, language),
                                    }
                                ],
                            },
                            {
                                "role": "assistant",
                                "content": [{"type": "text", "text": "FINDINGS:\n"}],
                            },
                        ],
                        max_new_tokens=1600,
                        continue_final_message=True,
                        repetition_penalty=1.08,
                        stop_on_impression_cycle=True,
                    )
                )
            )
        return _normalize_result(
            _structure_clinical(draft, language),
            getattr(self, "adapter_id", "none"),
            draft,
            adapter_used=False,
        )

    @modal.method()
    def analyze_lab_text(self, text: str, language: str = "en") -> dict[str, Any]:
        with self._without_lora():
            draft = _ensure_findings_heading(
                self._generate_text(
                    [
                        {
                            "role": "user",
                            "content": [
                                {
                                    "type": "text",
                                    "text": build_lab_text_prompt(text, language),
                                }
                            ],
                        },
                        {
                            "role": "assistant",
                            "content": [{"type": "text", "text": "FINDINGS:\n"}],
                        },
                    ],
                    max_new_tokens=500,
                    continue_final_message=True,
                    repetition_penalty=1.08,
                    stop_on_impression_cycle=True,
                )
            )
        return _lab_draft_result(draft, getattr(self, "adapter_id", "none"))

    @modal.method()
    def analyze_lab_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        pil = _load_image(payload)
        language = str(payload.get("language") or "en")
        with self._without_lora():
            draft = _ensure_findings_heading(
                self._generate_text(
                    [
                        {
                            "role": "user",
                            "content": [
                                {"type": "image", "image": pil},
                                {"type": "text", "text": build_lab_image_prompt(language)},
                            ],
                        },
                        {
                            "role": "assistant",
                            "content": [{"type": "text", "text": "FINDINGS:\n"}],
                        },
                    ],
                    max_new_tokens=900,
                    continue_final_message=True,
                    repetition_penalty=1.08,
                    stop_on_impression_cycle=True,
                )
            )
        result = _lab_draft_result(draft, getattr(self, "adapter_id", "none"))
        result["engine"] = "medgemma+lab-vision"
        return result

    @modal.method()
    def classify(self, payload: dict[str, Any]) -> dict[str, Any]:
        pil = _load_image(payload)
        with self._without_lora():
            draft = self._generate_text(
                [
                    {
                        "role": "user",
                        "content": [
                            {"type": "image", "image": pil},
                            {"type": "text", "text": build_classify_prompt()},
                        ],
                    }
                ],
                max_new_tokens=400,
            )
        result = _normalize_classify(
            _structure_classify(pil, draft),
            getattr(self, "adapter_id", "none"),
        )
        result["adapter_used"] = False
        return result

    @modal.method()
    def triage_chat(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Free-text triage draft in session locale + GPT structured JSON."""
        user_message = str(payload.get("user_message") or "").strip()
        summary = str(payload.get("summary") or "").strip()
        locale = str(payload.get("locale") or "").strip()
        locale_name = str(payload.get("locale_name") or "").strip() or (
            "the user's language" if not locale else locale
        )
        draft = scrub_triage_draft(
            self._generate_text(
                build_triage_messages(payload),
                max_new_tokens=900,
            ),
            user_message,
        )
        structured = _structure_triage(
            draft,
            summary=summary,
            user_message=user_message,
            recent_dialog=str(payload.get("recent_dialog") or ""),
            locale_name=locale_name,
        )

        return {
            "draft": draft,
            "structured": structured,
            "engine": f"medgemma+{_structure_model()}",
            "adapter": getattr(self, "adapter_id", "none"),
        }

    @modal.method()
    def triage_chat_stream(self, payload: dict[str, Any]):
        user_message = str(payload.get("user_message") or "").strip()
        summary = str(payload.get("summary") or "").strip()
        locale = str(payload.get("locale") or "").strip()
        locale_name = str(payload.get("locale_name") or "").strip() or (
            "the user's language" if not locale else locale
        )
        yield {"hop": "Writing the reply", "detail": user_message[:120] or "the current message"}
        raw = "".join(
            str(token or "")
            for token in self._generate_text_stream(
                build_triage_messages(payload),
                max_new_tokens=900,
            )
        )
        draft = scrub_triage_draft(raw, user_message)
        yield {"hop": "Writing the plan"}
        structured = _structure_triage(
            draft,
            summary=summary,
            user_message=user_message,
            recent_dialog=str(payload.get("recent_dialog") or ""),
            locale_name=locale_name,
        )
        yield {"done": True, "draft": draft, "structured": structured}


@app.cls(
    gpu="L4",
    image=image,
    timeout=600,
    scaledown_window=900,
    volumes={"/cache": hf_cache_vol},
    secrets=[hf_secret],
)
class SttModel:
    """Whisper auto-detect first; MedASR second pass when Whisper reports English."""

    def _load_whisper(self) -> Any:
        import whisper

        model = whisper.load_model("turbo", download_root=WHISPER_DOWNLOAD_ROOT)
        _commit_model_cache()
        return model

    @modal.enter()
    def load(self) -> None:
        _begin_model_cache()
        self.medasr = None
        self.whisper = None
        try:
            from transformers import pipeline

            token = os.environ.get("HF_TOKEN") or os.environ.get("HUGGINGFACE_HUB_TOKEN")
            # Prefer cached MedASR weights; pipeline still resolves via HF_HOME on the volume.
            try:
                self.medasr = pipeline(
                    "automatic-speech-recognition",
                    model=MEDASR_MODEL_ID,
                    token=token,
                    model_kwargs={"local_files_only": True},
                )
            except Exception as local_exc:  # noqa: BLE001
                print(f"MedASR cache miss, downloading: {local_exc}")
                self.medasr = pipeline(
                    "automatic-speech-recognition",
                    model=MEDASR_MODEL_ID,
                    token=token,
                )
        except Exception as exc:  # noqa: BLE001
            print(f"MedASR unavailable; Whisper-only STT: {exc}")
            self.medasr = None

        # Whisper is always required (language detect + non-English transcript).
        self.whisper = self._load_whisper()
        self.engine = "medasr+whisper" if self.medasr is not None else "whisper-turbo"
        _commit_model_cache()

    @modal.method()
    def transcribe(self, audio_b64: str, language: str | None = None) -> dict[str, Any]:
        import tempfile

        import whisper as whisper_lib

        # `language` is accepted for API compat but ignored: always Whisper auto-detect first.
        _ = language

        raw = base64.b64decode(audio_b64)
        with tempfile.NamedTemporaryFile(suffix=".webm", delete=False) as tmp:
            tmp.write(raw)
            path = tmp.name

        if self.whisper is None:
            self.whisper = self._load_whisper()

        try:
            audio = whisper_lib.load_audio(path)
        except Exception as exc:  # noqa: BLE001
            print(f"Whisper audio load failed: {exc}")
            return {
                "transcript": "",
                "language": None,
                "engine": "whisper-turbo",
                "error": str(exc),
            }

        import numpy as np

        # Near-empty / silent clips make Whisper invent random words (e.g. "ítlið").
        duration_sec = float(audio.shape[0]) / float(whisper_lib.audio.SAMPLE_RATE)
        if duration_sec < 0.75:
            return {
                "transcript": "",
                "language": None,
                "engine": "whisper-turbo",
                "error": "too_short",
            }

        peak = float(np.max(np.abs(audio))) if audio.size else 0.0
        rms = float(np.sqrt(np.mean(np.square(audio.astype(np.float64))))) if audio.size else 0.0
        if peak < 0.02 or rms < 0.008:
            return {
                "transcript": "",
                "language": None,
                "engine": "whisper-turbo",
                "error": "silent",
            }

        try:
            result = self.whisper.transcribe(
                audio,
                language=None,
                condition_on_previous_text=False,
                no_speech_threshold=0.5,
                logprob_threshold=-0.8,
                compression_ratio_threshold=2.2,
            )
        except Exception as exc:  # noqa: BLE001
            print(f"Whisper transcription failed: {exc}")
            return {
                "transcript": "",
                "language": None,
                "engine": "whisper-turbo",
                "error": str(exc),
            }

        whisper_text = (result.get("text") or "").strip()
        detected = (result.get("language") or "").strip().lower() or None
        segments = result.get("segments") or []
        if segments:
            no_speech_probs = [float(seg.get("no_speech_prob") or 0) for seg in segments]
            avg_logprobs = [float(seg.get("avg_logprob") or 0) for seg in segments]
            mean_no_speech = sum(no_speech_probs) / len(no_speech_probs)
            mean_logprob = sum(avg_logprobs) / len(avg_logprobs)
            if mean_no_speech >= 0.45 or min(no_speech_probs) >= 0.55:
                return {
                    "transcript": "",
                    "language": detected,
                    "engine": "whisper-turbo",
                    "error": "no_speech",
                }
            if mean_logprob < -0.9:
                return {
                    "transcript": "",
                    "language": detected,
                    "engine": "whisper-turbo",
                    "error": "low_confidence",
                }
        if not whisper_text or not any(ch.isalnum() for ch in whisper_text):
            return {
                "transcript": "",
                "language": detected,
                "engine": "whisper-turbo",
                "error": "empty",
            }

        # English medical speech: second pass with MedASR when available.
        if (
            detected is not None
            and detected.startswith("en")
            and self.medasr is not None
        ):
            try:
                out = self.medasr(path)
                raw = out.get("text") if isinstance(out, dict) else str(out)
                medasr_text = _clean_asr_text(str(raw))
                # Specials-only (e.g. "</s>") ⇒ treat as empty; keep Whisper.
                if medasr_text and any(ch.isalnum() for ch in medasr_text):
                    return {
                        "transcript": medasr_text,
                        "language": detected,
                        "engine": "medasr",
                    }
                print("MedASR text empty after special-token strip; keeping Whisper")
            except Exception as exc:  # noqa: BLE001
                print(f"MedASR second pass failed, keeping Whisper: {exc}")

        if not whisper_text or not any(ch.isalnum() for ch in whisper_text):
            return {
                "transcript": "",
                "language": detected,
                "engine": "whisper-turbo",
                "error": "empty",
            }

        return {
            "transcript": whisper_text,
            "language": detected,
            "engine": "whisper-turbo",
        }


@app.function(
    image=web_image,
    timeout=900,
    memory=4096,
    scaledown_window=900,
    secrets=[webhook_secret],
)
def run_analyze_job(payload: dict[str, Any]) -> None:
    """Background analyze → signed Laravel webhook."""
    from app.api import AnalyzeRequest, _run_pipeline

    _run_pipeline(AnalyzeRequest(**payload))


@app.function(
    image=web_image,
    timeout=600,
    memory=4096,
    scaledown_window=900,
    secrets=[webhook_secret],
)
@modal.asgi_app()
def web():
    """Laravel-facing FastAPI: /health, /api/v1/analyze|classify|transcribe."""
    from app.api import app as fastapi_app

    return fastapi_app
