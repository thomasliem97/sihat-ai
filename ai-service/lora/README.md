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

Laravel talks to Modal ASGI:

```bash
modal deploy ai-service/app/modal_app.py
# SIHAT_AI_URL=https://<ws>--sihat-medgemma-web.modal.run
```

Loads LoRA from volume `sihat-lora` at `/lora/adapter` on the GPU class.

## Cleanup

`adapter/checkpoints/` is resume-only (~400MB+). Safe to delete for a local backup that only needs the final adapter.
