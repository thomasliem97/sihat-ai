#!/usr/bin/env python3
"""Runnable check: lab PDFs must yield real biomarkers (text layer or OCR).

  cd ai-service && python scripts/selfcheck_lab_ocr.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

SERVICE_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(SERVICE_ROOT))

from app.api import _coerce_biomarkers, _compact_lab_for_draft, _regex_parse_lab  # noqa: E402
from app.lab_ocr import extract_lab_text  # noqa: E402


def _assert_parser_snippets() -> None:
    pathlab = _regex_parse_lab(
        "PATHLAB (37363-K)\nHAEMOGLOBIN\n         13.3       G/DL     11.5-16.5\n"
        "POTASSIUM\n         4.2       MMOL/L    3.5-5.1\n"
        "ALKALINE PHOSPHATASE\n        84       U/L      40-129\n"
        "CREATININE\n        69       UMOL/L    60-110\n"
    )
    by_name = {b["name"]: b["value"] for b in pathlab["biomarkers"]}
    assert by_name.get("Hemoglobin") == 13.3, by_name
    assert by_name.get("Potassium") == 4.2, by_name
    assert by_name.get("ALP") == 84.0, by_name
    assert by_name.get("Creatinine") == 69.0, by_name

    censored = _regex_parse_lab(
        "eGFR\n         >60       ML/MIN    >60\nHAEMOGLOBIN\n         13.3       G/DL     11.5-16.5\n"
    )
    censored_names = {b["name"]: b["value"] for b in censored["biomarkers"]}
    assert censored_names.get("eGFR") == 60.0, censored_names
    assert censored_names.get("Hemoglobin") == 13.3, censored_names

    coerced = _coerce_biomarkers(
        [
            {"name": "eGFR", "value": ">60", "unit": "mL/min", "reference_low": ">60", "status": "normal"},
            {"name": "CRP", "value": "ND", "unit": "mg/L", "status": "normal"},
        ]
    )
    assert coerced == [
        {
            "name": "eGFR",
            "value": 60.0,
            "unit": "mL/min",
            "reference_low": 60.0,
            "reference_high": None,
            "status": "normal",
        }
    ]

    lal = _regex_parse_lab("Hemoglobin  11.50 - 15.00 g/dL12.60\nCreatinine\nAlkaline picrate kinetic\n0.78")
    lal_names = {b["name"]: b["value"] for b in lal["biomarkers"]}
    assert lal_names.get("Hemoglobin") == 12.6, lal_names
    assert "ALP" not in lal_names, lal_names

    low_hb = _regex_parse_lab(
        "HAEMOGLOBIN\n         8.0       G/DL     11.5-16.5\nPOTASSIUM\n         4.2       MMOL/L    3.5-5.1\n"
    )
    low_findings = {f["label"]: f["severity"] for f in low_hb["findings"]}
    assert low_findings.get("Hemoglobin") == "critical", low_findings
    assert "Potassium" not in low_findings, low_findings
    assert any("same-day" in rec for rec in low_hb["recommendations"]), low_hb["recommendations"]

    in_range = _regex_parse_lab("HAEMOGLOBIN\n         13.3       G/DL     11.5-16.5\n")
    assert in_range["findings"] == [], in_range["findings"]
    assert any("no lab-based caution" in rec.lower() for rec in in_range["recommendations"]), in_range

    brief = _compact_lab_for_draft(
        "HAEMATOLOGY\n"
        "ESR: 40 MM/HR (outside range 0-20)\n"
        "RBC: 4.6 X10^12/L (within range 3.9-5.2)\n"
        "HAEMOGLOBIN: 13.5 G/DL (within range 11.5-15.0)\n"
        "DIABETES SCREEN\n"
        "GLUCOSE: 5.1 MMOL/L (within range 3.9-6.0)\n"
        "LDL: 2.9 mmol/L  <2.6  H\n"
    )
    assert "ESR" in brief and "40" in brief, brief
    assert "13.5" not in brief, brief
    assert "HAEMATOLOGY" in brief, brief
    assert "2.9" in brief, brief
    assert "assume within normal limits" in brief

    src = (SERVICE_ROOT / "app" / "modal_app.py").read_text(encoding="utf-8")
    api_src = (SERVICE_ROOT / "app" / "api.py").read_text(encoding="utf-8")
    lab_block = src[src.index("def _lab_draft_instructions") : src.index("def build_classify_prompt")]
    assert "SihatAI" not in lab_block
    assert "Do not list in-range analytes" in lab_block
    assert "Do not reprint the full analyte table" in lab_block
    assert "assume within normal limits" in lab_block
    assert "LAB BRIEF" in lab_block
    assert "No values outside the printed" not in lab_block
    assert "chemical pathologist" in lab_block
    assert "shotgun" in lab_block
    assert "no_repeat_ngram_size=10" not in src
    assert "def _clean_lab_draft" in src
    assert "def _clean_draft" in src
    assert "def _impression_item_repeated" in src
    assert "stop_on_impression_cycle=True" in src
    assert "max_new_tokens=2800" not in src
    assert "tension physiology, large pneumothorax, mediastinal emergency" not in src
    assert "Do not enumerate absent emergencies" in src
    assert "Do not repeat an item" in src
    assert "After the last IMPRESSION item, stop generating" in src
    ns: dict = {"re": re}
    exec(src[src.index("_LAB_DEGENERATE_LINE") : src.index("def _lab_draft_result")], ns)
    looped = (
        "FINDINGS:\n1) Lungs: nodular opacities.\n\nIMPRESSION:\n"
        "1) Nodular opacities. Recommend CT chest.\n"
        "2) No mediastinal emergency.\n"
        "3) No tension physiology.\n"
        "4) No large pneumothorax.\n"
        "5) No mediastinal emergency.\n"
        "6) No tension physiology.\n"
    )
    cleaned = ns["_clean_draft"](looped)
    assert "Recommend CT chest" in cleaned, cleaned
    assert cleaned.lower().count("no mediastinal emergency") == 1, cleaned
    assert ns["_impression_item_repeated"](looped)
    assert not ns["_impression_item_repeated"](cleaned)
    assert "def _compact_lab_for_draft" in api_src
    assert "Write a concise answer only" not in src
    assert "consultant dermatologist" in src
    assert "consultant histopathologist" in src
    assert "two-line stamp" in src
    for start, end in (
        ("def build_imaging_prompt", "def build_localize_prompt"),
        ("def build_explain_prompt", "def build_clinical_text_prompt"),
        ("def build_triage_messages", "def _safety_rules"),
    ):
        block = src[src.index(start) : src.index(end)]
        assert "SihatAI" not in block, start
    print("OK parser snippets")


def main() -> None:
    from app.lab_ocr import lab_page_jpegs_b64, ocr_image_bytes

    _assert_parser_snippets()

    lab_dir = SERVICE_ROOT.parent / "docs" / "testing-dataset" / "lab-report"
    pdfs = sorted(lab_dir.glob("*.pdf"))
    assert pdfs, f"no lab PDFs in {lab_dir}"

    for pdf in pdfs:
        data = pdf.read_bytes()
        text, meta = extract_lab_text(data)
        assert meta.get("source") in {"ocr", "text_layer"}, (pdf.name, meta)
        upper = text.upper()
        assert any(
            token in upper
            for token in ("HAEMOGLOBIN", "HEMOGLOBIN", "HEMOGLOBINA", "HGB", "ALT", "CREATININE", "WBC")
        ), (pdf.name, text[:400])
        parsed = _regex_parse_lab(text)
        assert parsed["biomarkers"], (pdf.name, text[:500], parsed)
        names = {b["name"] for b in parsed["biomarkers"]}
        print(f"OK {pdf.name} source={meta.get('source')} biomarkers={sorted(names)}")

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
