# MY-LoRA (SihatAI)

QLoRA adapter on [`google/medgemma-1.5-4b-it`](https://huggingface.co/google/medgemma-1.5-4b-it) for Malaysian clinical phrasing (BM + MOH CPG-style Path A).

| | |
|---|---|
| Base | `google/medgemma-1.5-4b-it` |
| Method | QLoRA (r=16, α=32), SFT via TRL |
| Train steps | 800 |
| Data | `../data/train_split.jsonl` |
| Load path | this folder (`adapter_config.json` + `adapter_model.safetensors`) |

## Load

```python
from peft import PeftModel
from transformers import AutoModelForCausalLM  # or ImageTextToText for multimodal serve

base = AutoModelForCausalLM.from_pretrained("google/medgemma-1.5-4b-it", ...)
model = PeftModel.from_pretrained(base, "ai-service/lora/adapter")
```

On Modal serve, the same files are on volume `sihat-lora` at `/lora/adapter`.

## Not for clinical decisions alone

Decision support only — verify against guidelines and clinical judgment.
