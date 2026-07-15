# SihatAI AI service (Modal)

```
ai-service/
  app/           # Modal ASGI + GPU (modal_app.py, api.py, dicom, lab_ocr)
  lora/          # MY-LoRA data, train scripts, local adapter backup
  scripts/       # optional local self-checks
```

## Config (not in this folder)

| What | Where |
|---|---|
| Laravel → AI URL | root `.env` → `SIHAT_AI_URL` |
| Webhook HMAC | root `.env` + Modal secret `sihat-webhook-secret` |
| HF token | Modal secret `huggingface-secret` |
| OpenAI structurer | Modal secret `openai-secret` |
| LoRA weights | Modal volume `sihat-lora` (`/lora/adapter`) |
| Python deps | `pip_install(...)` in `app/modal_app.py` / `lora/modal_train.py` |

All MedGemma paths (imaging, classify, clinical text, lab text/image) are free-form text.
JSON is enforced only by the GPT structurer via OpenAI Structured Outputs (`json_schema`)

```bash
modal deploy ai-service/app/modal_app.py
```
