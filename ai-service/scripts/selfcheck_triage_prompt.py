#!/usr/bin/env python3
"""Triage generation must be chat turns, not a prompt document the model can copy.

    cd ai-service && python scripts/selfcheck_triage_prompt.py
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = (ROOT / "app" / "modal_app.py").read_text(encoding="utf-8")


def main() -> None:
    start = SRC.index("def build_triage_messages")
    end = SRC.index("def _safety_rules")
    block = SRC[start:end]

    for banned in (
        "Current user message",
        "Recent dialog (up to last 10 messages",
        "prefer this for immediate continuity",
        "Running conversation summary",
        "Write the next triage reply",
        "Plain text only, not JSON",
    ):
        assert banned not in block, banned

    assert "build_triage_messages(payload)" in SRC
    assert 'content": build_triage_prompt' not in SRC
    assert "content': build_triage_prompt" not in SRC
    assert "Never say you are an AI" in SRC
    assert "def _malaysia_units" in SRC
    assert SRC.count("_malaysia_units()") >= 5
    safety = SRC[SRC.index("def _safety_rules") : SRC.index("def _imaging_role")]
    assert "_malaysia_units()" in safety
    assert "That is not a plan" in SRC
    assert "preserves useful clinical content from the draft" not in SRC
    assert "You write assistant_message" in SRC
    assert "def _structure_explain" in SRC
    assert "Do not invent findings, organs, laterality, lesions" in SRC
    print("OK triage generation uses chat turns")


if __name__ == "__main__":
    main()
