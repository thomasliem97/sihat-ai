#!/usr/bin/env python3
"""
QLoRA SFT for MY-LoRA on google/medgemma-1.5-4b-it (text Path A).

Prereqs:
  1. Accept HF license: https://huggingface.co/google/medgemma-1.5-4b-it
  2. HF_TOKEN in env (or `huggingface-cli login`)
  3. CUDA GPU (Kaggle T4 / Colab / Modal / local)

Run (from ai-service/lora/ on a GPU machine):
  pip install -U "transformers>=4.50" peft trl bitsandbytes datasets accelerate
  python train_my_lora.py

Outputs adapter weights to ./adapter/ (adapter_config.json + *.safetensors).
"""

from __future__ import annotations

import os
from pathlib import Path

import torch
from datasets import load_dataset
from peft import LoraConfig
from transformers import AutoModelForCausalLM, AutoTokenizer, BitsAndBytesConfig
from trl import SFTConfig, SFTTrainer

MODEL_ID = "google/medgemma-1.5-4b-it"
ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
TRAIN_FILE = DATA / "train_split.jsonl"
VAL_FILE = DATA / "val_split.jsonl"
OUT_DIR = ROOT / "adapter"


def main() -> None:
    if not TRAIN_FILE.exists():
        raise SystemExit(f"Missing {TRAIN_FILE}")
    if not torch.cuda.is_available():
        raise SystemExit("CUDA GPU required for QLoRA. Use Kaggle/Colab/Modal.")

    # T4: fp16. L4/A100: can switch to bfloat16 if preferred.
    use_bf16 = torch.cuda.is_bf16_supported() and "T4" not in torch.cuda.get_device_name(0)
    compute_dtype = torch.bfloat16 if use_bf16 else torch.float16

    token = os.environ.get("HF_TOKEN") or os.environ.get("HUGGINGFACE_HUB_TOKEN")

    bnb = BitsAndBytesConfig(
        load_in_4bit=True,
        bnb_4bit_quant_type="nf4",
        bnb_4bit_use_double_quant=True,
        bnb_4bit_compute_dtype=compute_dtype,
    )

    tokenizer = AutoTokenizer.from_pretrained(MODEL_ID, token=token)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    model = AutoModelForCausalLM.from_pretrained(
        MODEL_ID,
        quantization_config=bnb,
        device_map="auto",
        token=token,
        attn_implementation="eager",
    )
    model.gradient_checkpointing_enable()
    model.config.use_cache = False

    train_ds = load_dataset("json", data_files=str(TRAIN_FILE), split="train")
    val_ds = load_dataset("json", data_files=str(VAL_FILE), split="train")
    drop = [c for c in train_ds.column_names if c != "messages"]
    if drop:
        train_ds = train_ds.remove_columns(drop)
        val_ds = val_ds.remove_columns([c for c in val_ds.column_names if c != "messages"])

    lora = LoraConfig(
        r=16,
        lora_alpha=32,
        lora_dropout=0.05,
        bias="none",
        task_type="CAUSAL_LM",
        # Text/language path only. Short names like "q_proj" also match vision_tower and
        # create empty LoRA slots on MedGemma VLMs.
        target_modules=(
            r"^(?!.*vision_tower).*(?:q_proj|k_proj|v_proj|o_proj|gate_proj|up_proj|down_proj)$"
        ),
    )

    args = SFTConfig(
        output_dir=str(OUT_DIR),
        num_train_epochs=1,
        per_device_train_batch_size=1,
        gradient_accumulation_steps=8,
        learning_rate=2e-4,
        logging_steps=20,
        save_steps=200,
        eval_strategy="steps",
        eval_steps=200,
        fp16=not use_bf16,
        bf16=use_bf16,
        max_length=1024,
        report_to="none",
    )

    trainer = SFTTrainer(
        model=model,
        args=args,
        train_dataset=train_ds,
        eval_dataset=val_ds,
        processing_class=tokenizer,
        peft_config=lora,
    )

    trainer.train()
    trainer.model.save_pretrained(OUT_DIR)
    tokenizer.save_pretrained(OUT_DIR)
    print(f"Adapter saved -> {OUT_DIR}")
    print("Next: sync to Modal volume sihat-lora:/adapter (or set SIHAT_AI_LORA_PATH) and redeploy.")


if __name__ == "__main__":
    main()
