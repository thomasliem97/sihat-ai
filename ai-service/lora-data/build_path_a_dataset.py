#!/usr/bin/env python3
"""
Path A MY-LoRA data builder for SihatAI.

Why this exists:
  - moh.gov.my often returns 403 to scripts
  - acadmed.org.my wraps downloads in captcha (view_file.cfm → captcha)
  So we: (1) fetch society mirrors that work, (2) accept PDFs you drop in,
  (3) always include curated Malaysian bootstrap excerpts.

Usage:
  cd ai-service
  python lora-data/build_path_a_dataset.py

Optional:
  python lora-data/build_path_a_dataset.py --skip-download
  python lora-data/build_path_a_dataset.py --amm-catalog

Drop extra PDFs into lora-data/pdfs/ then re-run.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import urllib.request
from pathlib import Path

try:
    from pypdf import PdfReader
except ImportError:
    print("Install pypdf: pip install pypdf", file=sys.stderr)
    raise

ROOT = Path(__file__).resolve().parent
PDF_DIR = ROOT / "pdfs"
OUT_DIR = ROOT / "out"
SOURCES = ROOT / "sources.json"
BOOTSTRAP = ROOT / "bootstrap_excerpts.json"
AMM_URL = "https://www.acadmed.org.my/index.cfm?menuid=67"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

DISCLAIMER_EN = (
    "This is decision support only and does not replace clinical judgement. "
    "Confirm with a licensed clinician."
)
DISCLAIMER_BM = (
    "Ini hanyalah sokongan keputusan dan tidak menggantikan pertimbangan klinikal. "
    "Sahkan dengan doktor berdaftar."
)


def load_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def download_sources(force: bool = False) -> list[Path]:
    PDF_DIR.mkdir(parents=True, exist_ok=True)
    saved: list[Path] = []
    for src in load_json(SOURCES):
        dest = PDF_DIR / f"{src['id']}.pdf"
        if dest.exists() and dest.stat().st_size > 10_000 and not force:
            print(f"  keep {dest.name}")
            saved.append(dest)
            continue
        print(f"  fetch {src['title']} ...")
        req = urllib.request.Request(src["url"], headers={"User-Agent": UA})
        try:
            with urllib.request.urlopen(req, timeout=120) as resp:
                data = resp.read()
                ctype = resp.headers.get("Content-Type", "")
        except Exception as exc:  # noqa: BLE001 — show host failures clearly
            print(f"  FAIL {src['id']}: {exc}")
            continue
        if b"%PDF" not in data[:16] and "pdf" not in ctype.lower():
            print(f"  FAIL {src['id']}: not a PDF (got {ctype!r}, {len(data)} bytes)")
            continue
        dest.write_bytes(data)
        print(f"  saved {dest.name} ({len(data):,} bytes)")
        saved.append(dest)
    return saved


def extract_pdf_text(path: Path) -> str:
    reader = PdfReader(str(path))
    parts: list[str] = []
    for page in reader.pages:
        text = page.extract_text() or ""
        parts.append(text)
    text = "\n".join(parts)
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def chunk_text(text: str, *, min_chars: int = 280, max_chars: int = 900) -> list[str]:
    # Split on blank lines / numbered headings; fall back to hard wraps.
    raw = re.split(r"\n\s*\n|(?=\n\d+\.\d+)", text)
    chunks: list[str] = []
    buf = ""
    for para in raw:
        para = para.strip()
        if len(para) < 40:
            continue
        # Skip obvious TOC / boilerplate noise
        low = para.lower()
        if low.startswith("table of contents") or "all rights reserved" in low:
            continue
        if len(buf) + len(para) + 1 <= max_chars:
            buf = f"{buf}\n{para}".strip()
            continue
        if len(buf) >= min_chars:
            chunks.append(buf)
        if len(para) <= max_chars:
            buf = para
        else:
            for i in range(0, len(para), max_chars):
                piece = para[i : i + max_chars].strip()
                if len(piece) >= min_chars:
                    chunks.append(piece)
            buf = ""
    if len(buf) >= min_chars:
        chunks.append(buf)
    return chunks


def pairs_from_excerpt(source: str, section: str, en: str, bm: str | None = None) -> list[dict]:
    bm = bm or ""
    label = f"{source} — {section}" if section else source
    pairs = [
        {
            "messages": [
                {
                    "role": "user",
                    "content": (
                        f"Using Malaysian clinical guideline context ({label}), "
                        f"summarise the following for a clinician in clear English. "
                        f"Keep it factual and include a short safety disclaimer.\n\n{en}"
                    ),
                },
                {
                    "role": "assistant",
                    "content": f"{en.strip()}\n\n{DISCLAIMER_EN}",
                },
            ],
            "meta": {"source": source, "section": section, "template": "clinician_en"},
        },
        {
            "messages": [
                {
                    "role": "user",
                    "content": (
                        "Tulis ringkasan pesakit dalam Bahasa Melayu yang mudah faham "
                        f"berdasarkan garis panduan Malaysia ({label}). "
                        "Elak diagnosis muktamad dan sertakan penafian singkat.\n\n"
                        f"{en}"
                    ),
                },
                {
                    "role": "assistant",
                    "content": (
                        (bm.strip() if bm else en.strip())
                        + f"\n\n{DISCLAIMER_BM}"
                    ),
                },
            ],
            "meta": {"source": source, "section": section, "template": "patient_bm"},
        },
        {
            "messages": [
                {
                    "role": "user",
                    "content": (
                        "Produce a SihatAI-style JSON finding object for this guideline snippet. "
                        "Use keys: label, description, confidence, severity, patient_summary_bm, disclaimer.\n\n"
                        f"Source: {label}\nSnippet:\n{en}"
                    ),
                },
                {
                    "role": "assistant",
                    "content": json.dumps(
                        {
                            "label": section or source,
                            "description": en.strip()[:400],
                            "confidence": 0.72,
                            "severity": "borderline",
                            "patient_summary_bm": (bm or en)[:280],
                            "disclaimer": DISCLAIMER_BM,
                            "citation": label,
                        },
                        ensure_ascii=False,
                    ),
                },
            ],
            "meta": {"source": source, "section": section, "template": "json_finding"},
        },
    ]
    return pairs


def pairs_from_pdf_chunk(source: str, chunk: str) -> list[dict]:
    return pairs_from_excerpt(source, "excerpt", chunk, bm=None)


def write_amm_catalog() -> Path:
    """List AMM CPG titles + view_file.cfm ids for manual browser download."""
    req = urllib.request.Request(AMM_URL, headers={"User-Agent": UA})
    html = urllib.request.urlopen(req, timeout=60).read().decode("utf-8", errors="ignore")
    rows = []
    for m in re.finditer(
        r"<tr>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td>(.*?)</td>",
        html,
        flags=re.I | re.S,
    ):
        title = re.sub(r"<[^>]+>", "", m.group(1))
        title = re.sub(r"\s+", " ", title).replace("&nbsp;", " ").strip()
        year = re.sub(r"<[^>]+>", "", m.group(2)).strip()
        cpg_cell = m.group(3)
        file_ids = re.findall(r"view_file\.cfm\?fileid=(\d+)", cpg_cell)
        if not title or not file_ids:
            continue
        rows.append(
            {
                "title": title,
                "year": year,
                "cpg_fileid": file_ids[0],
                "manual_url": f"https://www.acadmed.org.my/view_file.cfm?fileid={file_ids[0]}",
                "note": "Open in browser, pass captcha, save PDF into lora-data/pdfs/",
            }
        )
    out = OUT_DIR / "amm_catalog.json"
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(rows, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"Wrote {len(rows)} AMM entries -> {out}")
    return out


def build(skip_download: bool = False, force_download: bool = False) -> Path:
    PDF_DIR.mkdir(parents=True, exist_ok=True)
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    print("== Path A dataset ==")
    if not skip_download:
        print("Downloading society-mirror PDFs...")
        download_sources(force=force_download)
    else:
        print("Skipping download (--skip-download)")

    examples: list[dict] = []

    print("Bootstrap excerpts...")
    for row in load_json(BOOTSTRAP):
        examples.extend(
            pairs_from_excerpt(
                row["source"],
                row.get("section", ""),
                row["content_en"],
                row.get("content_bm"),
            )
        )

    pdfs = sorted(PDF_DIR.glob("*.pdf"))
    print(f"PDF folder: {len(pdfs)} file(s)")
    for pdf in pdfs:
        print(f"  extract {pdf.name}")
        try:
            text = extract_pdf_text(pdf)
        except Exception as exc:  # noqa: BLE001
            print(f"  FAIL extract {pdf.name}: {exc}")
            continue
        chunks = chunk_text(text)
        # Cap per PDF so one huge CPG doesn't dominate
        for chunk in chunks[:80]:
            examples.extend(pairs_from_pdf_chunk(f"CPG PDF — {pdf.stem}", chunk))
        print(f"  -> {min(len(chunks), 80)} chunks")

    # Deduplicate by template + assistant content (same EN body can yield EN + BM pairs)
    seen: set[str] = set()
    unique: list[dict] = []
    for ex in examples:
        key = f"{ex['meta'].get('template','')}|{ex['messages'][1]['content'][:240]}"
        if key in seen:
            continue
        seen.add(key)
        unique.append(ex)

    out = OUT_DIR / "train.jsonl"
    with out.open("w", encoding="utf-8") as fh:
        for ex in unique:
            fh.write(json.dumps(ex, ensure_ascii=False) + "\n")

    # Split a tiny holdout
    holdout_n = max(1, len(unique) // 10)
    holdout = unique[-holdout_n:]
    train = unique[:-holdout_n] if len(unique) > holdout_n else unique
    train_path = OUT_DIR / "train_split.jsonl"
    val_path = OUT_DIR / "val_split.jsonl"
    with train_path.open("w", encoding="utf-8") as fh:
        for ex in train:
            fh.write(json.dumps(ex, ensure_ascii=False) + "\n")
    with val_path.open("w", encoding="utf-8") as fh:
        for ex in holdout:
            fh.write(json.dumps(ex, ensure_ascii=False) + "\n")

    print(f"\nDone: {len(unique)} examples")
    print(f"  {out}")
    print(f"  {train_path} ({len(train)})")
    print(f"  {val_path} ({len(holdout)})")
    print(
        "\nNext: open AMM catalog for more PDFs:\n"
        "  python lora-data/build_path_a_dataset.py --amm-catalog\n"
        "Then download in your browser (captcha), save into lora-data/pdfs/, re-run."
    )
    return out


def main() -> None:
    parser = argparse.ArgumentParser(description="Build Path A MY-LoRA JSONL from Malaysian CPGs")
    parser.add_argument("--skip-download", action="store_true")
    parser.add_argument("--force-download", action="store_true")
    parser.add_argument("--amm-catalog", action="store_true", help="Only write AMM manual download catalog")
    args = parser.parse_args()
    if args.amm_catalog:
        write_amm_catalog()
        return
    build(skip_download=args.skip_download, force_download=args.force_download)


if __name__ == "__main__":
    main()
