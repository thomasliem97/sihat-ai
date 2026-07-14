"""
Modal MedGemma 1.5 + MY-LoRA + Laravel-facing FastAPI glue.

Deploy (from repo root):
  modal secret create huggingface-secret HF_TOKEN=hf_...
  modal secret create sihat-webhook-secret SIHAT_AI_WEBHOOK_SECRET=...
  modal deploy ai-service/app/modal_app.py

Laravel:
  SIHAT_AI_URL=https://<workspace>--sihat-medgemma-web.modal.run

Adapter: volume sihat-lora at /lora/adapter (or SIHAT_AI_LORA_PATH).
"""

from __future__ import annotations

import base64
import json
import os
from io import BytesIO
from typing import Any

import modal


app = modal.App("sihat-medgemma")

image = (
    modal.Image.debian_slim(python_version="3.11")
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
        "outlines",
    )
    .run_commands("python -m pip uninstall -y hf-xet || true")
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
    .env({"PYTHONPATH": "/root", "SIHAT_AI_BUILD": "labocr-v3-20260714"})
    .add_local_dir(
        "ai-service/app",
        remote_path="/root/app",
        copy=True,
        ignore=["**/__pycache__/**", "**/*.pyc"],
    )
)

MODEL_ID = "google/medgemma-1.5-4b-it"
lora_vol = modal.Volume.from_name("sihat-lora", create_if_missing=True)
webhook_secret = modal.Secret.from_name("sihat-webhook-secret")
hf_secret = modal.Secret.from_name("huggingface-secret")


def _lora_path() -> str:
    env = (os.environ.get("SIHAT_AI_LORA_PATH") or "").strip()
    if env:
        return env
    if os.path.isdir("/lora/adapter"):
        return "/lora/adapter"
    return ""


def _load_image(payload: dict[str, Any]):
    from PIL import Image

    raw: bytes | None = None
    if payload.get("image_b64"):
        raw = base64.b64decode(payload["image_b64"])
    else:
        file_url = payload.get("file_url")
        if not file_url:
            raise ValueError("image_b64 or file_url is required")
        import httpx

        with httpx.Client(timeout=60.0) as client:
            resp = client.get(file_url, headers={"User-Agent": "SihatAI-Modal/1.0"}, follow_redirects=True)
            resp.raise_for_status()
            raw = resp.content

    assert raw is not None
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


# Structured output via Outlines + pure JSON Schema (no Pydantic models).
# Docs: https://dottxt-ai.github.io/outlines/latest/features/core/output_types/
# Wrap dicts with outlines.types.JsonSchema(...).
_SEVERITY = {"type": "string", "enum": ["normal", "borderline", "abnormal", "critical"]}

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
                    "label": {"type": "string"},
                    "description": {"type": "string"},
                    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
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
                    "condition": {"type": "string"},
                    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
                },
                "required": ["condition", "confidence"],
            },
        },
        "overall_confidence": {"type": "number", "minimum": 0, "maximum": 1},
        "bounding_boxes": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "label": {"type": "string"},
                    "x": {"type": "number", "minimum": 0, "maximum": 1},
                    "y": {"type": "number", "minimum": 0, "maximum": 1},
                    "width": {"type": "number", "minimum": 0, "maximum": 1},
                    "height": {"type": "number", "minimum": 0, "maximum": 1},
                    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
                },
                "required": ["label", "x", "y", "width", "height", "confidence"],
            },
        },
    },
    "required": [
        "findings",
        "differential_diagnosis",
        "overall_confidence",
        "bounding_boxes",
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
                    "label": {"type": "string"},
                    "value": {"type": ["number", "string", "null"]},
                    "unit": {"type": ["string", "null"]},
                    "reference": {"type": ["string", "null"]},
                    "severity": _SEVERITY,
                    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
                    "description": {"type": "string"},
                },
                "required": ["label", "severity", "confidence"],
            },
        },
        "biomarkers": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "name": {"type": "string"},
                    "value": {"type": ["number", "string", "null"]},
                    "unit": {"type": ["string", "null"]},
                    "reference_low": {"type": ["number", "null"]},
                    "reference_high": {"type": ["number", "null"]},
                    "status": _SEVERITY,
                },
                "required": ["name", "status"],
            },
        },
        "differential_diagnosis": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "properties": {
                    "condition": {"type": "string"},
                    "confidence": {"type": "number", "minimum": 0, "maximum": 1},
                },
                "required": ["condition", "confidence"],
            },
        },
        "overall_confidence": {"type": "number", "minimum": 0, "maximum": 1},
        "bounding_boxes": {"type": "array", "items": {"type": "object"}},
    },
    "required": [
        "findings",
        "biomarkers",
        "differential_diagnosis",
        "overall_confidence",
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
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
    },
    "required": ["modality", "confidence"],
}


def _parse_structured(result: Any) -> dict[str, Any]:
    """Outlines returns a JSON string for JsonSchema output types."""
    if isinstance(result, dict):
        return result
    if isinstance(result, str):
        parsed = json.loads(result)
        if not isinstance(parsed, dict):
            raise ValueError("Structured output must be a JSON object")
        return parsed
    if hasattr(result, "model_dump"):
        return result.model_dump()
    if hasattr(result, "dict"):
        return result.dict()
    raise ValueError(f"Unexpected structured result type: {type(result)}")


def _clamp_boxes(boxes: Any) -> list[dict[str, Any]]:
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


def _normalize_result(raw: dict[str, Any], adapter: str) -> dict[str, Any]:
    findings = raw.get("findings") or []
    if not isinstance(findings, list):
        findings = []

    confidence = raw.get("overall_confidence")
    if confidence is None and findings:
        confs = [float(f.get("confidence", 0.5)) for f in findings if isinstance(f, dict)]
        confidence = sum(confs) / len(confs) if confs else 0.5

    return {
        "findings": findings,
        "differential_diagnosis": raw.get("differential_diagnosis") or [],
        "bounding_boxes": _clamp_boxes(raw.get("bounding_boxes")),
        "biomarkers": raw.get("biomarkers") or [],
        "overall_confidence": float(confidence or 0.5),
        "adapter": adapter,
    }


def _language_instruction(language: str) -> str:
    lang = (language or "en").strip().lower()
    if lang.startswith("ms") or lang in {"bm", "malay"}:
        return (
            "Write every human-readable string (labels, descriptions, conditions) in Bahasa Melayu. "
            "Keep JSON keys in English exactly as specified."
        )
    return (
        "Write every human-readable string (labels, descriptions, conditions) in clear clinical English. "
        "Keep JSON keys in English exactly as specified."
    )


def _safety_rules() -> str:
    return """
Safety and scope:
- You are clinical decision-support only. Never state a definitive diagnosis as fact.
- Prefer observable findings over conclusions. Put possible conditions only in differential_diagnosis.
- If image/text quality is poor or evidence is weak, lower confidence and say so in descriptions.
- Do not invent anatomy, labs, medications, or findings that are not visible or stated.
- If nothing abnormal is seen, return findings with severity "normal" and empty or low-confidence differentials.
""".strip()


def _confidence_rules() -> str:
    return """
Confidence and severity calibration:
- confidence / overall_confidence: float from 0.0 to 1.0. Use 0.9+ only when the finding is unmistakable.
- severity:
  - normal: expected / no concerning change
  - borderline: subtle, nonspecific, or uncertain
  - abnormal: clear pathologic or clinically important change
  - critical: potentially urgent (e.g. tension physiology, large hemorrhage, airway threat). Use sparingly.
""".strip()


def _imaging_bbox_rules() -> str:
    return """
Bounding boxes:
- Use normalized image coordinates in [0, 1]: x,y = top-left of the box; width,height extend right/down.
- Provide one box per localized finding when a region can be pointed to.
- Skip boxes for diffuse/global impressions (e.g. "poor inspiration") or when location is unclear.
- Box label should match the related finding label.
- Prefer tight boxes around the abnormality; avoid whole-image boxes unless the finding truly fills the field.
""".strip()


def _imaging_review_checklist(modality: str) -> str:
    modality = (modality or "unknown").lower()
    checklists = {
        "xray": """
Modality focus: frontal/lateral chest radiograph.
Review systematically:
1) technical quality (rotation, inspiration, exposure)
2) airways and mediastinum / cardiomediastinal contour
3) lungs and pleura (opacity, consolidation, pneumothorax, effusion)
4) bones and soft tissues
5) devices / lines if present
""",
        "ct": """
Modality focus: CT multi-slice montage (mid-volume representative slices).
Review systematically:
1) scan coverage and obvious artifacts
2) lung parenchyma / airways (if chest) or organ parenchyma in view
3) vessels and mediastinum/retroperitoneum as visible
4) bones and soft tissues
5) focal lesions: size, density/attenuation cues, location
""",
        "mri": """
Modality focus: MRI multi-slice montage.
Review systematically:
1) sequence/quality limitations visible in the montage
2) anatomy in view and laterality
3) signal abnormalities, mass effect, edema, hemorrhage cues
4) enhancement patterns only if clearly depicted
5) incidental but clinically relevant findings
""",
        "histopath": """
Modality focus: histopathology patch montage.
Review systematically:
1) staining/quality adequacy
2) architecture (glandular, nested, diffuse, infiltrative)
3) cytology (nuclear atypia, mitoses, necrosis if visible)
4) stroma / inflammation / invasion cues
5) differential tissue patterns, not a final pathologic diagnosis
""",
        "dermatology": """
Modality focus: clinical dermatology photo.
Review systematically:
1) lesion morphology (macule/papule/plaque/nodule/ulcer)
2) color, borders, symmetry, scale/crust
3) distribution and surrounding skin
4) ABCDE-style melanoma concern cues when pigmented
5) infectious/inflammatory vs neoplastic possibilities as differentials only
""",
        "ophthalmology": """
Modality focus: fundus / retinal photo.
Review systematically:
1) optic disc (margins, cupping, pallor)
2) macula
3) vessels (caliber, crossings, hemorrhages)
4) periphery / background retina as visible
5) media opacity that limits interpretation
""",
    }
    return checklists.get(
        modality,
        """
Modality focus: general medical image.
Review systematically for quality, anatomy in view, focal abnormalities, and devices.
""",
    ).strip()


def _imaging_output_contract() -> str:
    return """
Output contract (JSON object only; decoding already enforces schema):
{
  "findings": [
    {
      "label": "short finding name",
      "description": "what you see + location + pertinent negatives if useful",
      "confidence": 0.0,
      "severity": "normal|borderline|abnormal|critical"
    }
  ],
  "differential_diagnosis": [
    {"condition": "possible condition", "confidence": 0.0}
  ],
  "overall_confidence": 0.0,
  "bounding_boxes": [
    {"label": "matching finding label", "x": 0.0, "y": 0.0, "width": 0.0, "height": 0.0, "confidence": 0.0}
  ]
}

Content rules:
- findings: 1 to 8 items. Separate distinct abnormalities. Do not dump prose into one finding.
- differential_diagnosis: 0 to 5 plausible conditions ranked by likelihood; not a confirmed diagnosis list.
- overall_confidence: your confidence in the whole assessment, not the max of single findings.
- Prefer precise radiology/clinical wording over vague phrases like "abnormality noted".
""".strip()


def build_imaging_prompt(modality: str, language: str) -> str:
    return "\n\n".join(
        [
            "You are SihatAI's imaging specialist: a careful clinical decision-support model for Malaysian care workflows.",
            _safety_rules(),
            _imaging_review_checklist(modality),
            _confidence_rules(),
            _imaging_bbox_rules(),
            _language_instruction(language),
            _imaging_output_contract(),
            "Analyze the attached image now. Return only the JSON object.",
        ]
    )


def build_clinical_text_prompt(text: str, language: str) -> str:
    body = text[:10000]
    return "\n\n".join(
        [
            "You are SihatAI's document specialist extracting structured clinical signals from a de-identified note.",
            _safety_rules(),
            _confidence_rules(),
            _language_instruction(language),
            """
Task:
- Extract key problems, procedures, medications, allergies, and follow-up plans that are explicitly stated.
- Put each discrete item in findings (label + description). Use severity based on clinical urgency implied by the text.
- differential_diagnosis may list stated working diagnoses or differentials from the note; do not invent new disease labels.
- Set bounding_boxes to [].
- If the document is empty or unusable, return findings=[] with low overall_confidence.
""".strip(),
            _imaging_output_contract(),
            f"DOCUMENT:\n{body}",
            "Return only the JSON object.",
        ]
    )


def build_lab_text_prompt(text: str, language: str) -> str:
    body = text[:10000]
    return "\n\n".join(
        [
            "You are SihatAI's laboratory extraction specialist.",
            _safety_rules(),
            _confidence_rules(),
            _language_instruction(language),
            """
Task:
- Extract every readable analyte/biomarker with value, unit, and reference range when present.
- Do not invent numbers. Use null for missing value/unit/reference fields.
- Mirror each biomarker into findings with matching severity.
- status/severity mapping:
  - normal: within reference
  - borderline: near limits or weakly out of range
  - abnormal: clearly out of range
  - critical: panic-level if explicitly marked or extreme vs typical adult ranges when range missing
- differential_diagnosis usually [] unless the report itself states interpretive conditions.
- bounding_boxes must be [].
""".strip(),
            """
Output contract:
{
  "findings": [{"label":"Hemoglobin","value":12.1,"unit":"g/dL","reference":"12-15","severity":"normal","confidence":0.9,"description":"..."}],
  "biomarkers": [{"name":"Hemoglobin","value":12.1,"unit":"g/dL","reference_low":12.0,"reference_high":15.0,"status":"normal"}],
  "differential_diagnosis": [],
  "overall_confidence": 0.0,
  "bounding_boxes": []
}
""".strip(),
            f"LAB REPORT TEXT:\n{body}",
            "Return only the JSON object.",
        ]
    )


def build_lab_image_prompt(language: str) -> str:
    return "\n\n".join(
        [
            "You are SihatAI's laboratory vision extraction specialist reading a lab report page image.",
            _safety_rules(),
            _confidence_rules(),
            _language_instruction(language),
            """
Task:
- OCR-read the page carefully. Extract every analyte you can read with value/unit/reference.
- Prefer exact printed strings. If a digit is unreadable, omit that analyte rather than guessing.
- Populate both biomarkers and findings consistently.
- bounding_boxes: optional boxes around analyte rows if clearly localizable; otherwise [].
""".strip(),
            """
Output contract:
{
  "findings": [{"label":"WBC","value":11.2,"unit":"x10^9/L","reference":"4-10","severity":"abnormal","confidence":0.8,"description":"..."}],
  "biomarkers": [{"name":"WBC","value":11.2,"unit":"x10^9/L","reference_low":4.0,"reference_high":10.0,"status":"abnormal"}],
  "differential_diagnosis": [],
  "overall_confidence": 0.0,
  "bounding_boxes": []
}
""".strip(),
            "Analyze the attached lab report image now. Return only the JSON object.",
        ]
    )


def build_classify_prompt() -> str:
    return "\n\n".join(
        [
            "You are SihatAI's modality router.",
            """
Classify the attached medical image into exactly one modality:
- xray: radiographic projection (especially chest X-ray)
- ct: computed tomography slices/montage
- mri: magnetic resonance slices/montage
- histopath: microscopy / pathology slide patches
- dermatology: clinical skin photograph
- ophthalmology: fundus / retinal photograph
- other: not clearly any of the above

Rules:
- Choose the most specific matching class.
- confidence reflects certainty of the modality call only (not disease severity).
- If ambiguous between classes, pick the best guess and lower confidence below 0.6.
""".strip(),
            """
Output contract:
{"modality":"xray","confidence":0.0}
""".strip(),
            "Return only the JSON object.",
        ]
    )


def _ensure_pil_format(image: Any) -> Any:
    """Outlines Image assets expect PIL images with a format attribute set."""
    try:
        from PIL import Image as PILImage
    except ImportError:  # pragma: no cover
        return image

    if isinstance(image, PILImage.Image) and not getattr(image, "format", None):
        image.format = "PNG"
    return image


def _to_outlines_chat(messages: list[dict[str, Any]]):
    """Convert HF-style multimodal messages into Outlines Chat input."""
    from outlines.inputs import Chat, Image as OutlinesImage
    from PIL import Image as PILImage

    converted: list[dict[str, Any]] = []
    for message in messages:
        role = str(message.get("role") or "user")
        content = message.get("content")

        if isinstance(content, str):
            converted.append({"role": role, "content": content})
            continue

        if not isinstance(content, list):
            continue

        parts: list[Any] = []
        for part in content:
            if not isinstance(part, dict):
                continue
            kind = part.get("type")
            if kind == "text":
                parts.append({"type": "text", "text": str(part.get("text") or "")})
            elif kind == "image":
                image = _ensure_pil_format(part.get("image"))
                if isinstance(image, PILImage.Image):
                    parts.append({"type": "image", "image": OutlinesImage(image)})
                elif image is not None:
                    parts.append({"type": "image", "image": OutlinesImage(image)})
            elif isinstance(part.get("image"), PILImage.Image):
                image = _ensure_pil_format(part["image"])
                parts.append({"type": "image", "image": OutlinesImage(image)})

        if parts:
            converted.append({"role": role, "content": parts})

    if not converted:
        raise ValueError("No valid chat messages for Outlines generation")

    return Chat(converted)


@app.cls(
    gpu="L4",
    image=image,
    timeout=600,
    scaledown_window=900,
    volumes={"/lora": lora_vol},
    secrets=[hf_secret],
)
class MedGemmaModel:
    @modal.enter()
    def load(self) -> None:
        import torch
        from transformers import AutoModelForImageTextToText, AutoProcessor

        token = os.environ.get("HF_TOKEN") or os.environ.get("HUGGINGFACE_HUB_TOKEN")
        self.adapter_id = "none"
        self.processor = AutoProcessor.from_pretrained(MODEL_ID, token=token)
        base = AutoModelForImageTextToText.from_pretrained(
            MODEL_ID,
            torch_dtype=torch.bfloat16,
            device_map="auto",
            token=token,
        )

        lora = _lora_path()
        if lora:
            from peft import PeftModel

            self.model = PeftModel.from_pretrained(base, lora, token=token)
            self.adapter_id = f"loaded:{lora}"
        else:
            self.model = base

        self.model.eval()
        self._outlines = None

    def _outlines_model(self):
        import outlines

        if self._outlines is None:
            # Wrap already-loaded MedGemma (+ optional LoRA) for constrained decoding.
            # https://dottxt-ai.github.io/outlines/latest/guide/vlm/
            self._outlines = outlines.from_transformers(self.model, self.processor)
        return self._outlines

    def _generate_structured(
        self,
        messages: list[dict[str, Any]],
        schema: dict[str, Any],
        *,
        max_new_tokens: int = 1200,
    ) -> dict[str, Any]:
        import outlines
        from outlines.types import JsonSchema

        model = self._outlines_model()
        output_type = JsonSchema(schema)
        chat = _to_outlines_chat(messages)

        # Outlines >=1: Generator + Chat multimodal input.
        # Docs: https://dottxt-ai.github.io/outlines/latest/features/models/transformers_multimodal/
        if hasattr(outlines, "Generator"):
            generator = outlines.Generator(model, output_type)
            try:
                result = generator(chat, max_new_tokens=max_new_tokens)
            except TypeError:
                result = generator(chat)
            return _parse_structured(result)

        from outlines import generate, samplers

        generator = generate.json(model, output_type, sampler=samplers.greedy())
        return _parse_structured(generator(chat))

    @modal.method()
    def status(self) -> dict[str, str]:
        return {"adapter": getattr(self, "adapter_id", "none"), "model": MODEL_ID}

    @modal.method()
    def analyze_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        pil = _load_image(payload)
        modality = str(payload.get("modality") or "xray")
        language = str(payload.get("language") or "en")

        messages = [
            {
                "role": "user",
                "content": [
                    {"type": "image", "image": pil},
                    {
                        "type": "text",
                        "text": build_imaging_prompt(modality, language),
                    },
                ],
            }
        ]

        decoded = self._generate_structured(
            messages,
            IMAGING_RESULT_SCHEMA,
        )
        return _normalize_result(decoded, getattr(self, "adapter_id", "none"))

    @modal.method()
    def analyze_clinical_text(self, text: str, language: str = "en") -> dict[str, Any]:
        messages = [
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": build_clinical_text_prompt(text, language),
                    }
                ],
            }
        ]
        decoded = self._generate_structured(
            messages,
            IMAGING_RESULT_SCHEMA,
            max_new_tokens=1500,
        )
        return _normalize_result(decoded, getattr(self, "adapter_id", "none"))

    @modal.method()
    def analyze_lab_text(self, text: str, language: str = "en") -> dict[str, Any]:
        messages = [
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": build_lab_text_prompt(text, language),
                    }
                ],
            }
        ]
        decoded = self._generate_structured(
            messages,
            LAB_RESULT_SCHEMA,
            max_new_tokens=1500,
        )
        return _normalize_result(decoded, getattr(self, "adapter_id", "none"))

    @modal.method()
    def analyze_lab_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Vision fallback when PDF/image OCR yields no text."""
        pil = _load_image(payload)
        language = str(payload.get("language") or "en")
        messages = [
            {
                "role": "user",
                "content": [
                    {"type": "image", "image": pil},
                    {
                        "type": "text",
                        "text": build_lab_image_prompt(language),
                    },
                ],
            }
        ]
        decoded = self._generate_structured(
            messages,
            LAB_RESULT_SCHEMA,
            max_new_tokens=1500,
        )
        result = _normalize_result(decoded, getattr(self, "adapter_id", "none"))
        result["engine"] = f"{result.get('engine', 'medgemma')}+lab-vision"
        return result

    @modal.method()
    def classify(self, payload: dict[str, Any]) -> dict[str, Any]:
        pil = _load_image(payload)
        messages = [
            {
                "role": "user",
                "content": [
                    {"type": "image", "image": pil},
                    {
                        "type": "text",
                        "text": build_classify_prompt(),
                    },
                ],
            }
        ]
        decoded = self._generate_structured(
            messages,
            CLASSIFY_RESULT_SCHEMA,
            max_new_tokens=128,
        )
        raw = decoded
        modality = str(raw.get("modality", "other")).lower()
        confidence = float(raw.get("confidence", 0.7))
        allowed = {
            "xray",
            "dermatology",
            "ct",
            "mri",
            "histopath",
            "ophthalmology",
        }
        if modality == "other" or modality not in allowed:
            modality = "xray"
            confidence = min(confidence, 0.5)
        return {
            "modality": modality,
            "confidence": confidence,
            "adapter": getattr(self, "adapter_id", "none"),
        }


@app.cls(
    gpu="T4",
    image=image,
    timeout=300,
    scaledown_window=900,
    secrets=[hf_secret],
)
class SttModel:
    """MedASR primary; Whisper fallback when license/load fails."""

    @modal.enter()
    def load(self) -> None:
        self.engine = "whisper-base"
        self.medasr = None
        self.whisper = None
        try:
            from transformers import pipeline

            self.medasr = pipeline(
                "automatic-speech-recognition",
                model="google/medasr",
            )
            self.engine = "medasr"
        except Exception as exc:  # noqa: BLE001
            print(f"MedASR unavailable, falling back to Whisper: {exc}")
            import whisper

            self.whisper = whisper.load_model("base")
            self.engine = "whisper-base"

    @modal.method()
    def transcribe(self, audio_b64: str, language: str | None = None) -> dict[str, Any]:
        import tempfile

        raw = base64.b64decode(audio_b64)
        with tempfile.NamedTemporaryFile(suffix=".webm", delete=False) as tmp:
            tmp.write(raw)
            path = tmp.name

        if self.engine == "medasr" and self.medasr is not None:
            try:
                out = self.medasr(path)
                text = (out.get("text") if isinstance(out, dict) else str(out)).strip()
                return {
                    "transcript": text,
                    "language": language,
                    "engine": "medasr",
                }
            except Exception as exc:  # noqa: BLE001
                print(f"MedASR inference failed, Whisper fallback: {exc}")
                if self.whisper is None:
                    import whisper

                    self.whisper = whisper.load_model("base")

        assert self.whisper is not None
        result = self.whisper.transcribe(path, language=language or None)
        text = (result.get("text") or "").strip()
        return {
            "transcript": text,
            "language": result.get("language"),
            "engine": "whisper-base",
        }


@app.function(
    image=web_image,
    timeout=120,
    memory=4096,
)
def probe_lab_ocr(file_b64: str) -> dict[str, Any]:
    """One-shot remote check: OCR the given PDF/image bytes on the web image."""
    import base64
    import inspect
    import os

    from app import api as api_mod
    from app.lab_ocr import extract_lab_text

    data = base64.b64decode(file_b64)
    text, meta = extract_lab_text(data)
    src = inspect.getsource(api_mod._analyze_lab)
    return {
        "build": os.environ.get("SIHAT_AI_BUILD"),
        "meta": meta,
        "text_len": len(text or ""),
        "text_preview": (text or "")[:240],
        "has_vision_fallback": "_analyze_lab_vision" in src,
        "cv2_ok": True,
    }


@app.function(
    image=web_image,
    timeout=900,
    memory=4096,
    scaledown_window=120,
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
    scaledown_window=120,
    secrets=[webhook_secret],
)
@modal.asgi_app()
def web():
    """Laravel-facing FastAPI: /health, /api/v1/analyze|classify|transcribe."""
    from app.api import app as fastapi_app

    return fastapi_app
