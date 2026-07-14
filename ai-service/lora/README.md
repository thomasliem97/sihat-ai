# SihatAI MY-LoRA

```
lora/
  adapter/          # final PEFT weights (serve loads this)
  data/             # train_split.jsonl / val_split.jsonl
  modal_train.py    # Modal L4 QLoRA
  train_my_lora.py  # local/Colab QLoRA
```

## Train (Modal)

```bash
# from repo root
modal run ai-service/lora/modal_train.py
modal volume get sihat-lora adapter ai-service/lora
```

## Serve

Redeploy `ai-service/app/modal_app.py` — it mounts `sihat-lora` at `/lora` and loads `/lora/adapter` by default.

Optional: set `SIHAT_AI_LORA_PATH` to a HF repo id or absolute path.

## Cleanup

`adapter/checkpoints/` is resume-only (~400MB+). Safe to delete for a local backup that only needs the final adapter.
