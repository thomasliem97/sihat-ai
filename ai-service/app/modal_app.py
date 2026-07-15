"""
Modal MedGemma 1.5 + MY-LoRA + Laravel-facing FastAPI glue.

All MedGemma paths are free-form text. OpenAI Structured Outputs (json_schema) is the
only JSON enforcer (imaging, classify, clinical text, lab).
Structurer model/effort: OPENAI_STRUCTURE_MODEL / OPENAI_STRUCTURE_EFFORT (Modal openai-secret).

Deploy (from repo root):
  modal secret create huggingface-secret HF_TOKEN=hf_...
  modal secret create sihat-webhook-secret SIHAT_AI_WEBHOOK_SECRET=...
  modal secret create openai-secret OPENAI_API_KEY=sk-... OPENAI_STRUCTURE_MODEL=gpt-... OPENAI_STRUCTURE_EFFORT=...
  modal deploy ai-service/app/modal_app.py

Laravel:
  SIHAT_AI_URL=https://<workspace>--sihat-medgemma-web.modal.run

Adapter: volume sihat-lora at /lora/adapter (or SIHAT_AI_LORA_PATH).
"""

from __future__ import annotations

import base64
import json
import os
import re
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
        "openai",
    )
    # hf-xet is intentionally removed: Xet CDN has caused HF 403 SignatureError on Modal.
    # HuggingFace then falls back to regular HTTP (slower, but reliable).
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
    .env({
        "PYTHONPATH": "/root",
        "SIHAT_AI_BUILD": "imaging-v6-20260715",
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
lora_vol = modal.Volume.from_name("sihat-lora", create_if_missing=True)
webhook_secret = modal.Secret.from_name("sihat-webhook-secret")
hf_secret = modal.Secret.from_name("huggingface-secret")
openai_secret = modal.Secret.from_name("openai-secret")

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


# GPT Structured Outputs schemas (OpenAI json_schema). MedGemma never sees these.
_SEVERITY = {"type": "string", "enum": ["normal", "borderline", "abnormal", "critical"]}
_STRING = {"type": "string"}
_NUMBER = {"type": "number"}

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


def _usable_clinical_label(label: Any) -> bool:
    if not isinstance(label, str):
        return False
    trimmed = label.strip()
    if len(trimmed) < 3:
        return False
    return re.search(r"[^\W_]", trimmed, flags=re.UNICODE) is not None


def _normalize_result(raw: dict[str, Any], adapter: str) -> dict[str, Any]:
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

    return {
        "findings": findings,
        "differential_diagnosis": differentials,
        "bounding_boxes": _clamp_boxes(raw.get("bounding_boxes")),
        "biomarkers": raw.get("biomarkers") or [],
        "overall_confidence": float(confidence or 0.5),
        "adapter": adapter,
        "engine": f"medgemma+{_structure_model()}",
        "structurer": _structure_model(),
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


def _structure_imaging(pil: Any, draft: str, *, modality: str) -> dict[str, Any]:
    return _openai_structured(
        name="imaging_result",
        schema=IMAGING_RESULT_SCHEMA,
        instructions=(
            "You are SihatAI's imaging structurer.\n"
            "Inputs: a medical image and a MedGemma draft report (plain text).\n"
            "Output: one JSON object matching the schema.\n"
            "Use the MedGemma draft as the primary clinical source. You may clarify, "
            "normalize wording, calibrate severity/confidence, add differentials, estimate "
            "bounding boxes, and elaborate from the image when the draft is thin.\n"
            f"Modality: {modality}.\n"
            "Severity: normal|borderline|abnormal|critical. "
            "Nodules/masses/consolidations/opacities/effusions/pneumothorax are not normal.\n"
            "Bounding boxes: normalized [0,1] x,y top-left with width/height."
        ),
        user_text="MedGemma draft report:\n\n" + ((draft or "").strip() or "(empty)"),
        pil=pil,
    )


def _structure_clinical(draft: str) -> dict[str, Any]:
    return _openai_structured(
        name="clinical_result",
        schema=IMAGING_RESULT_SCHEMA,
        instructions=(
            "You are SihatAI's clinical-document structurer.\n"
            "Input: a MedGemma free-form extraction note from a de-identified document.\n"
            "Output: JSON matching the schema. Map problems/meds/allergies/plans into findings.\n"
            "bounding_boxes must be []. Prefer stated content; do not invent diagnoses."
        ),
        user_text="MedGemma clinical draft:\n\n" + ((draft or "").strip() or "(empty)"),
    )


def _structure_lab(draft: str, pil: Any | None = None) -> dict[str, Any]:
    return _openai_structured(
        name="lab_result",
        schema=LAB_RESULT_SCHEMA,
        instructions=(
            "You are SihatAI's lab-report structurer.\n"
            "Input: a MedGemma free-form lab extraction note"
            + (" and the lab page image" if pil is not None else "")
            + ".\n"
            "Output: JSON matching the schema with findings + biomarkers.\n"
            "Use exact values from the draft/image. Missing fields use empty strings. "
            "status/severity: normal|borderline|abnormal|critical. "
            "bounding_boxes optional for row locations; else []."
        ),
        user_text="MedGemma lab draft:\n\n" + ((draft or "").strip() or "(empty)"),
        pil=pil,
    )


def _structure_classify(pil: Any, draft: str) -> dict[str, Any]:
    return _openai_structured(
        name="classify_result",
        schema=CLASSIFY_RESULT_SCHEMA,
        instructions=(
            "You are SihatAI's modality router structurer.\n"
            "Inputs: medical image + MedGemma modality note.\n"
            "Output: modality + confidence JSON.\n"
            "Allowed: xray, dermatology, ct, mri, histopath, ophthalmology, other.\n"
            "Use the draft as primary cue; refine from the image if needed."
        ),
        user_text="MedGemma modality draft:\n\n" + ((draft or "").strip() or "(empty)"),
        pil=pil,
    )


def _language_instruction(language: str) -> str:
    lang = (language or "en").strip().lower()
    if lang.startswith("ms") or lang in {"bm", "malay"}:
        return (
            "Write human-readable text in Bahasa Melayu."
        )
    return "Write human-readable text in clear clinical English."


def _safety_rules() -> str:
    return """
Safety and scope:
- Clinical decision-support only. Never state a definitive diagnosis as fact.
- Prefer observable findings over conclusions.
- If quality is poor or evidence is weak, say so clearly.
- Do not invent anatomy, labs, medications, or findings that are not visible or stated.
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


def build_imaging_prompt(modality: str, language: str) -> str:
    return "\n\n".join(
        [
            "You are SihatAI's imaging specialist.",
            _safety_rules(),
            _imaging_review_checklist(modality),
            _language_instruction(language),
            """
Write a concise radiology-style clinical report in plain text (not JSON).
Use sections such as FINDINGS: and IMPRESSION: when helpful.
Normal report punctuation is fine.
""".strip(),
            "Analyze the attached image now. Write the radiology report only.",
        ]
    )


def build_clinical_text_prompt(text: str, language: str) -> str:
    body = text[:10000]
    return "\n\n".join(
        [
            "You are SihatAI's document specialist.",
            _safety_rules(),
            _language_instruction(language),
            """
Read the de-identified clinical document and write a plain-text extraction note (not JSON).
List problems, procedures, medications, allergies, and follow-up plans that are explicitly stated.
""".strip(),
            f"DOCUMENT:\n{body}",
            "Write the extraction note only.",
        ]
    )


def build_lab_text_prompt(text: str, language: str) -> str:
    body = text[:10000]
    return "\n\n".join(
        [
            "You are SihatAI's laboratory extraction specialist.",
            _safety_rules(),
            _language_instruction(language),
            """
Read the lab report text and write a plain-text extraction note (not JSON).
List every readable analyte with value, unit, and reference range when present.
Do not invent numbers. If something is unreadable, say so.
""".strip(),
            f"LAB REPORT TEXT:\n{body}",
            "Write the lab extraction note only.",
        ]
    )


def build_lab_image_prompt(language: str) -> str:
    return "\n\n".join(
        [
            "You are SihatAI's laboratory vision specialist.",
            _safety_rules(),
            _language_instruction(language),
            """
Read the attached lab report page image and write a plain-text extraction note (not JSON).
List every analyte you can read with value/unit/reference. Prefer exact printed strings.
If a digit is unreadable, omit that analyte rather than guessing.
""".strip(),
            "Write the lab extraction note only.",
        ]
    )


def build_classify_prompt() -> str:
    return "\n\n".join(
        [
            "You are SihatAI's modality router.",
            """
Look at the attached medical image and write a short plain-text note (not JSON) about what it is.
Possible modalities: chest/projection x-ray, CT, MRI, histopathology microscopy, dermatology skin photo, ophthalmology fundus/retina, or unclear/other.
If unsure, say so and name your best guess.
""".strip(),
            "Write the modality note only.",
        ]
    )


@app.cls(
    gpu="L4",
    image=image,
    timeout=900,
    scaledown_window=900,
    volumes={"/lora": lora_vol},
    secrets=[hf_secret, openai_secret],
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

    def _generate_text(
        self,
        messages: list[dict[str, Any]],
        *,
        max_new_tokens: int = 1200,
    ) -> str:
        """Free-form MedGemma generation (never JSON-constrained)."""
        import torch

        templated = self.processor.apply_chat_template(
            messages,
            add_generation_prompt=True,
            tokenize=True,
            return_dict=True,
            return_tensors="pt",
        )
        model_device = next(self.model.parameters()).device
        inputs = {
            key: value.to(model_device) if hasattr(value, "to") else value
            for key, value in templated.items()
        }
        input_len = int(inputs["input_ids"].shape[-1])
        with torch.inference_mode():
            generated = self.model.generate(
                **inputs,
                max_new_tokens=max_new_tokens,
                do_sample=False,
            )
        new_tokens = generated[0][input_len:]
        return self.processor.decode(new_tokens, skip_special_tokens=True).strip()

    @modal.method()
    def status(self) -> dict[str, str]:
        return {"adapter": getattr(self, "adapter_id", "none"), "model": MODEL_ID}

    @modal.method()
    def analyze_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        pil = _load_image(payload)
        modality = str(payload.get("modality") or "xray")
        language = str(payload.get("language") or "en")
        draft = self._generate_text(
            [
                {
                    "role": "user",
                    "content": [
                        {"type": "image", "image": pil},
                        {"type": "text", "text": build_imaging_prompt(modality, language)},
                    ],
                }
            ]
        )
        return _normalize_result(
            _structure_imaging(pil, draft, modality=modality),
            getattr(self, "adapter_id", "none"),
        )

    @modal.method()
    def analyze_clinical_text(self, text: str, language: str = "en") -> dict[str, Any]:
        draft = self._generate_text(
            [
                {
                    "role": "user",
                    "content": [
                        {
                            "type": "text",
                            "text": build_clinical_text_prompt(text, language),
                        }
                    ],
                }
            ],
            max_new_tokens=1500,
        )
        return _normalize_result(
            _structure_clinical(draft),
            getattr(self, "adapter_id", "none"),
        )

    @modal.method()
    def analyze_lab_text(self, text: str, language: str = "en") -> dict[str, Any]:
        draft = self._generate_text(
            [
                {
                    "role": "user",
                    "content": [
                        {
                            "type": "text",
                            "text": build_lab_text_prompt(text, language),
                        }
                    ],
                }
            ],
            max_new_tokens=1500,
        )
        return _normalize_result(
            _structure_lab(draft),
            getattr(self, "adapter_id", "none"),
        )

    @modal.method()
    def analyze_lab_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        pil = _load_image(payload)
        language = str(payload.get("language") or "en")
        draft = self._generate_text(
            [
                {
                    "role": "user",
                    "content": [
                        {"type": "image", "image": pil},
                        {"type": "text", "text": build_lab_image_prompt(language)},
                    ],
                }
            ],
            max_new_tokens=1500,
        )
        result = _normalize_result(
            _structure_lab(draft, pil=pil),
            getattr(self, "adapter_id", "none"),
        )
        result["engine"] = f"{result.get('engine', 'medgemma')}+lab-vision"
        return result

    @modal.method()
    def classify(self, payload: dict[str, Any]) -> dict[str, Any]:
        pil = _load_image(payload)
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
            max_new_tokens=256,
        )
        return _normalize_classify(
            _structure_classify(pil, draft),
            getattr(self, "adapter_id", "none"),
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
