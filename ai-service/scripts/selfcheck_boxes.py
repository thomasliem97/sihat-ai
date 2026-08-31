#!/usr/bin/env python3
"""Runnable check: MedGemma [y0,x0,y1,x1]/1000 → app x,y,w,h in 0-1.

Keep in sync with medgemma_box_2d_to_xywh / parse_medgemma_localization in modal_app.py.

  cd ai-service && python scripts/selfcheck_boxes.py
"""

from __future__ import annotations

import json
import re
from typing import Any


def medgemma_box_2d_to_xywh(box_2d: Any) -> tuple[float, float, float, float] | None:
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
            "kind": kind,
        }
        if item.get("finding_index") is not None:
            box["finding_index"] = int(item["finding_index"])
        if item.get("image_index") is not None:
            box["image_index"] = int(item["image_index"])
        out.append(box)
    return out


def unmap_boxes_from_square(boxes: list[dict[str, Any]], pils: list[Any]) -> list[dict[str, Any]]:
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


class _FakePil:
    def __init__(self, name: str, size: tuple[int, int] = (100, 100)) -> None:
        self.name = name
        self.size = size

    def crop(self, box: tuple[int, int, int, int]) -> "_FakePil":
        return _FakePil("crop", (max(1, box[2] - box[0]), max(1, box[3] - box[1])))


def focus_explain_pils(pils: list[Any], box: Any) -> tuple[list[Any], bool]:
    """Keep in sync with _focus_explain_pils in modal_app.py."""
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
    except Exception:  # noqa: BLE001
        return pils, False


def main() -> None:
    xywh = medgemma_box_2d_to_xywh([560, 80, 860, 420])
    assert xywh is not None
    x, y, w, h = xywh
    assert abs(x - 0.08) < 1e-6, x
    assert abs(y - 0.56) < 1e-6, y
    assert abs(w - 0.34) < 1e-6, w
    assert abs(h - 0.3) < 1e-6, h

    already_norm = medgemma_box_2d_to_xywh([0.1, 0.2, 0.5, 0.8])
    assert already_norm is not None
    assert abs(already_norm[0] - 0.2) < 1e-6
    assert abs(already_norm[1] - 0.1) < 1e-6

    text = (
        "Reasoning...\n```json\n"
        '[{"box_2d": [560, 80, 860, 420], "label": "Opacity", "kind": "finding", '
        '"finding_index": 0, "image_index": 0}, '
        '{"box_2d": [380, 320, 740, 700], "label": "Heart", "kind": "anatomy"}]\n'
        "```"
    )
    boxes = parse_medgemma_localization(text)
    assert len(boxes) == 2, boxes
    assert boxes[0]["kind"] == "finding" and boxes[0]["finding_index"] == 0
    assert boxes[0]["image_index"] == 0
    assert boxes[1]["kind"] == "anatomy"

    after_brackets = parse_medgemma_localization(
        'Finding [0] is likely infection.\nFinal Answer: ```json[{"box_2d": [140, 110, 340, 470], "label": "right clavicle"}]```'
    )
    assert len(after_brackets) == 1, after_brackets
    assert after_brackets[0]["label"] == "right clavicle"

    xywh_item = parse_medgemma_localization(
        '[{"label": "Opacity", "x": 0.1, "y": 0.2, "width": 0.3, "height": 0.4}]'
    )
    assert len(xywh_item) == 1
    assert abs(xywh_item[0]["x"] - 0.1) < 1e-6
    assert abs(xywh_item[0]["width"] - 0.3) < 1e-6

    portrait = [_FakePil("cxr", (100, 200))]
    unmapped = unmap_boxes_from_square(
        [{"x": 0.25, "y": 0.1, "width": 0.5, "height": 0.4, "image_index": 0}],
        portrait,
    )
    assert abs(unmapped[0]["x"] - 0.0) < 1e-6, unmapped
    assert abs(unmapped[0]["y"] - 0.1) < 1e-6, unmapped
    assert abs(unmapped[0]["width"] - 1.0) < 1e-6, unmapped
    assert abs(unmapped[0]["height"] - 0.4) < 1e-6, unmapped

    slices = [_FakePil(str(i)) for i in range(8)]
    focused, cropped = focus_explain_pils(
        slices,
        {"x": 0.1, "y": 0.1, "width": 0.2, "height": 0.2, "image_index": 3},
    )
    assert cropped
    assert focused[0] is slices[3]
    assert focused[1].name == "crop"
    assert len(focused) == 9
    skipped, skipped_crop = focus_explain_pils(
        slices,
        {"x": 0.1, "y": 0.1, "width": 0.2, "height": 0.2, "image_index": 99},
    )
    assert not skipped_crop
    assert skipped is slices
    print("ALL BOX SELFCHECKS PASSED")


if __name__ == "__main__":
    main()
