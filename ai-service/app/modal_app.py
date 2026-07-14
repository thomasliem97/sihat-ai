"""
Modal MedGemma 1.5 + optional MY-LoRA + STT for SihatAI.

Deploy:
  modal secret create huggingface-secret HF_TOKEN=hf_...
  modal deploy ai-service/app/modal_app.py

Env (Modal secret or app env):
  SIHAT_AI_LORA_PATH=  # HF adapter repo id; empty = base only
"""

from __future__ import annotations

import base64
import json
import os
import re
import traceback
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
    )
)

MODEL_ID = "google/medgemma-1.5-4b-it"


def _lora_path() -> str:
    return (os.environ.get("SIHAT_AI_LORA_PATH") or "").strip()


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
            resp = client.get(file_url)
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


def _extract_json(text: str) -> dict[str, Any]:
    text = text.strip()
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        match = re.search(r"\{[\s\S]*\}", text)
        if match:
            try:
                return json.loads(match.group(0))
            except json.JSONDecodeError:
                pass
    return {
        "findings": [
            {
                "label": "Model narrative",
                "description": text[:800],
                "confidence": 0.6,
                "severity": "borderline",
            }
        ],
        "overall_confidence": 0.6,
        "differential_diagnosis": [],
        "bounding_boxes": [],
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


@app.cls(
    gpu="L4",
    image=image,
    timeout=600,
    scaledown_window=120,
    secrets=[modal.Secret.from_name("huggingface-secret")],
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

    def _generate(self, messages: list[dict[str, Any]], max_new_tokens: int = 1200) -> str:
        import torch

        inputs = self.processor.apply_chat_template(
            messages,
            add_generation_prompt=True,
            tokenize=True,
            return_dict=True,
            return_tensors="pt",
        ).to(self.model.device, dtype=torch.bfloat16)

        input_len = inputs["input_ids"].shape[-1]
        with torch.inference_mode():
            generation = self.model.generate(
                **inputs,
                max_new_tokens=max_new_tokens,
                do_sample=False,
            )
            generation = generation[0][input_len:]

        return self.processor.decode(generation, skip_special_tokens=True)

    @modal.method()
    def status(self) -> dict[str, str]:
        return {"adapter": getattr(self, "adapter_id", "none"), "model": MODEL_ID}

    @modal.method()
    def analyze_image(self, payload: dict[str, Any]) -> dict[str, Any]:
        pil = _load_image(payload)
        modality = payload.get("modality", "xray")
        language = payload.get("language", "en")

        if modality == "dermatology":
            task = (
                "You are a clinical decision-support assistant reviewing a dermatology photo. "
                "Describe visible lesion features. Do not give a definitive diagnosis."
            )
        elif modality == "xray":
            task = (
                "You are a clinical decision-support assistant reviewing a chest X-ray. "
                "Describe radiographic findings. Do not give a definitive diagnosis."
            )
        elif modality in {"ct", "mri"}:
            task = (
                f"You are a clinical decision-support assistant reviewing a {modality.upper()} "
                "multi-slice montage (mid-volume slices). Describe notable findings. "
                "Do not give a definitive diagnosis."
            )
        elif modality == "histopath":
            task = (
                "You are a clinical decision-support assistant reviewing a histopathology "
                "patch montage (tiled center-region patches). Describe tissue patterns. "
                "Do not give a definitive diagnosis."
            )
        elif modality == "ophthalmology":
            task = (
                "You are a clinical decision-support assistant reviewing an ophthalmology "
                "image (fundus / retinal photo). Describe visible findings. "
                "Do not give a definitive diagnosis."
            )
        else:
            task = (
                "You are a clinical decision-support assistant reviewing a medical image. "
                "Describe notable findings. Do not give a definitive diagnosis."
            )

        schema = (
            "Return ONLY valid JSON with keys: findings (array of "
            "{label, description, confidence 0-1, severity one of normal|borderline|abnormal|critical}), "
            "differential_diagnosis (array of {condition, confidence}), "
            "overall_confidence (0-1), "
            "bounding_boxes (array of {label, x, y, width, height, confidence} with all coords normalized 0-1 "
            "relative to image; include one box per localized finding when possible)."
        )

        messages = [
            {
                "role": "user",
                "content": [
                    {"type": "image", "image": pil},
                    {
                        "type": "text",
                        "text": f"{task}\nReport language preference: {language}.\n{schema}",
                    },
                ],
            }
        ]

        decoded = self._generate(messages)
        return _normalize_result(_extract_json(decoded), getattr(self, "adapter_id", "none"))

    @modal.method()
    def analyze_clinical_text(self, text: str, language: str = "en") -> dict[str, Any]:
        schema = (
            "Return ONLY valid JSON with keys: findings (array of "
            "{label, description, severity, confidence}), "
            "overall_confidence (0-1), bounding_boxes ([]), differential_diagnosis ([])."
        )
        messages = [
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": (
                            "Summarize this de-identified clinical document "
                            "(discharge summary / clinic note). Extract key diagnoses, "
                            "medications, and follow-up plans. "
                            f"Language preference: {language}.\n{schema}\n\nDOCUMENT:\n{text[:10000]}"
                        ),
                    }
                ],
            }
        ]
        decoded = self._generate(messages, max_new_tokens=1500)
        return _normalize_result(_extract_json(decoded), getattr(self, "adapter_id", "none"))

    @modal.method()
    def analyze_lab_text(self, text: str, language: str = "en") -> dict[str, Any]:
        schema = (
            "Return ONLY valid JSON with keys: findings (array of "
            "{label, value, unit, reference, severity, confidence}), "
            "biomarkers (array of {name, value, unit, reference_low, reference_high, "
            "status one of normal|borderline|abnormal|critical}), "
            "overall_confidence (0-1), bounding_boxes ([]), differential_diagnosis ([])."
        )
        messages = [
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": (
                            "Extract structured lab biomarkers from this de-identified lab report text. "
                            f"Language preference: {language}.\n{schema}\n\nREPORT:\n{text[:10000]}"
                        ),
                    }
                ],
            }
        ]
        decoded = self._generate(messages, max_new_tokens=1500)
        return _normalize_result(_extract_json(decoded), getattr(self, "adapter_id", "none"))

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
                        "text": (
                            "Classify this medical image. Return ONLY JSON: "
                            '{"modality":"xray|dermatology|ct|mri|histopath|ophthalmology|other",'
                            '"confidence":0-1}'
                        ),
                    },
                ],
            }
        ]
        decoded = self._generate(messages, max_new_tokens=128)
        raw = _extract_json(decoded)
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
    scaledown_window=120,
    secrets=[modal.Secret.from_name("huggingface-secret")],
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


@app.function(image=image, timeout=600)
@modal.fastapi_endpoint(method="POST")
def fastapi_app(payload: dict[str, Any]) -> dict[str, Any]:
    try:
        route = str(payload.get("route") or "analyze")

        if route in {"status", "health"}:
            return MedGemmaModel().status.remote()

        if route in {"transcribe", "stt"}:
            return SttModel().transcribe.remote(
                payload["audio_b64"],
                payload.get("language"),
            )

        model = MedGemmaModel()

        if route in {"classify"}:
            return model.classify.remote(payload)

        if route in {"analyze_lab", "analyze-lab"}:
            return model.analyze_lab_text.remote(
                payload.get("text", ""),
                payload.get("language", "en"),
            )

        if route in {"analyze_clinical", "analyze-clinical"}:
            return model.analyze_clinical_text.remote(
                payload.get("text", ""),
                payload.get("language", "en"),
            )

        return model.analyze_image.remote(payload)
    except Exception as exc:  # noqa: BLE001
        return {
            "error": str(exc),
            "traceback": traceback.format_exc()[-2000:],
            "findings": [],
            "overall_confidence": 0.0,
        }
