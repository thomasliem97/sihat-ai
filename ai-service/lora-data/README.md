# Path A — MY-LoRA text data (Malaysian CPG)

## Why you can’t “just scrape” MOH/AMM

| Source | What happens |
|--------|----------------|
| `moh.gov.my` PDFs | Often **403** to scripts / non-browser clients |
| `acadmed.org.my` | Links are `view_file.cfm?fileid=…` → **captcha** page |
| Society mirrors (MEMS, MTS) | Direct PDFs usually work — we use these first |

So the workflow is: **auto-fetch mirrors + curated excerpts + optional browser drops**.

## One-command build

```bash
cd ai-service
python lora-data/build_path_a_dataset.py
```

Outputs:

- `out/train.jsonl` — all examples
- `out/train_split.jsonl` / `out/val_split.jsonl` — ~90/10 split

## Add more guidelines (browser)

1. List AMM titles + download links:

```bash
python lora-data/build_path_a_dataset.py --amm-catalog
```

2. Open a `manual_url` from `out/amm_catalog.json` in Chrome.
3. Complete captcha → save the PDF into `lora-data/pdfs/` (any filename).
4. Re-run the build script.

Priority topics for SihatAI: dengue, TB, CAP/pneumonia, asthma, EVALI/vape, thalassaemia, derm/acne.

Also useful: [MyMaHTAS CPG list](https://mymahtas.moh.gov.my/index.php/docman-list/publications/cpg-list) and [MTS guidelines](https://mts.org.my/guidelines.html).

## What the JSONL looks like

Each line is a chat pair (clinician EN / patient BM / SihatAI JSON finding) grounded on a CPG excerpt.

## License note

CPGs are MOH/society publications for clinical guidance. Use for **research / local fine-tuning demo** only; do not redistribute full PDFs in a public HF dataset without checking rights. Prefer publishing **adapter weights** + citing sources, not re-hosting guideline PDFs.
