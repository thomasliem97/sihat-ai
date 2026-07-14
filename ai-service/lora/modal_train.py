#!/usr/bin/env python3
"""
Train MY-LoRA (QLoRA) on Modal — fast path for SihatAI Path A.

Usage (from repo root):
  modal volume create sihat-lora   # once
  modal run ai-service/lora/modal_train.py

Download adapter when done:
  modal volume get sihat-lora adapter ai-service/lora

Serve loads from Modal volume sihat-lora:/adapter (see app/modal_app.py).
"""

from __future__ import annotations

import modal

APP_NAME = "sihat-lora-train"
MODEL_ID = "google/medgemma-1.5-4b-it"
# Fast GPU; change to "A100" if you want even faster and accept higher cost
GPU = "L4"
# Cap steps for a usable adapter sooner (~1 partial epoch). Set 0 for full 1 epoch.
MAX_STEPS = 800

app = modal.App(APP_NAME)

volume = modal.Volume.from_name("sihat-lora", create_if_missing=True)
# Persist HF downloads across runs (avoids re-pulling ~GBs after a hang)
hf_cache = modal.Volume.from_name("sihat-hf-cache", create_if_missing=True)

image = (
    modal.Image.debian_slim(python_version="3.11")
    .env(
        {
            "HF_HOME": "/hf-cache",
            "HF_HUB_CACHE": "/hf-cache/hub",
        }
    )
    .pip_install(
        "torch",
        "transformers>=4.50.0,<5",
        "accelerate",
        "peft",
        "trl",
        "bitsandbytes",
        "datasets",
        "sentencepiece",
        "protobuf",
        "huggingface_hub",
    )
    # hf-xet hangs / 403 SignatureError on MedGemma CDN; plain HTTP works
    .run_commands("python -m pip uninstall -y hf-xet || true")
)

# Local JSONL → baked into image for reliable upload
image = image.add_local_dir(
    "ai-service/lora/data",
    remote_path="/data",
)


@app.function(
    image=image,
    gpu=GPU,
    timeout=6 * 60 * 60,
    memory=32768,
    volumes={"/vol": volume, "/hf-cache": hf_cache},
    secrets=[modal.Secret.from_name("huggingface-secret")],
)
def train() -> dict:
    import os
    import sys
    from pathlib import Path

    import torch
    from datasets import load_dataset
    from huggingface_hub import snapshot_download
    from peft import LoraConfig
    from transformers import AutoModelForCausalLM, AutoTokenizer, BitsAndBytesConfig
    from trl import SFTConfig, SFTTrainer

    def log(msg: str) -> None:
        print(msg, flush=True)
        sys.stdout.flush()

    train_file = Path("/data/train_split.jsonl")
    out_dir = Path("/vol/adapter")
    out_dir.mkdir(parents=True, exist_ok=True)

    if not train_file.exists():
        raise FileNotFoundError(f"Missing {train_file}")

    token = os.environ.get("HF_TOKEN") or os.environ.get("HUGGINGFACE_HUB_TOKEN")
    use_bf16 = torch.cuda.is_bf16_supported()
    compute_dtype = torch.bfloat16 if use_bf16 else torch.float16

    log(f"GPU: {torch.cuda.get_device_name(0)}")
    log(f"dtype: {compute_dtype}, max_steps={MAX_STEPS or 'full epoch'}")
    log(f"HF token present: {bool(token)}")

    # Explicit download first so we see progress / fail fast (not silent xet hang)
    log(f"Downloading {MODEL_ID} into HF cache…")
    local_model = snapshot_download(MODEL_ID, token=token)
    log(f"Model cached at {local_model}")
    hf_cache.commit()

    bnb = BitsAndBytesConfig(
        load_in_4bit=True,
        bnb_4bit_quant_type="nf4",
        bnb_4bit_use_double_quant=True,
        bnb_4bit_compute_dtype=compute_dtype,
    )

    log("Loading tokenizer…")
    tokenizer = AutoTokenizer.from_pretrained(local_model, token=token)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    log("Loading 4-bit model onto GPU…")
    model = AutoModelForCausalLM.from_pretrained(
        local_model,
        quantization_config=bnb,
        device_map="auto",
        token=token,
        attn_implementation="eager",
    )
    model.gradient_checkpointing_enable()
    model.config.use_cache = False
    log("Model loaded.")

    train_ds = load_dataset("json", data_files=str(train_file), split="train")
    drop = [c for c in train_ds.column_names if c != "messages"]
    if drop:
        train_ds = train_ds.remove_columns(drop)

    log(f"train={len(train_ds)}")

    lora = LoraConfig(
        r=16,
        lora_alpha=32,
        lora_dropout=0.05,
        bias="none",
        task_type="CAUSAL_LM",
        target_modules=[
            "q_proj",
            "k_proj",
            "v_proj",
            "o_proj",
            "gate_proj",
            "up_proj",
            "down_proj",
        ],
    )

    sft_kwargs: dict = {
        "output_dir": str(out_dir / "checkpoints"),
        "num_train_epochs": 1,
        "per_device_train_batch_size": 2,
        "gradient_accumulation_steps": 4,  # effective batch 8
        "learning_rate": 2e-4,
        "logging_steps": 10,
        "save_steps": 400,
        "eval_strategy": "no",  # L4 OOMs on full-seq eval logits
        "fp16": not use_bf16,
        "bf16": use_bf16,
        "max_length": 1024,
        "report_to": "none",
        "save_total_limit": 2,
        # chunked_nll breaks MedGemma/Gemma3 (no last_hidden_state on CausalLM output)
        "loss_type": "nll",
    }
    if MAX_STEPS and MAX_STEPS > 0:
        sft_kwargs["max_steps"] = MAX_STEPS

    args = SFTConfig(**sft_kwargs)

    trainer = SFTTrainer(
        model=model,
        args=args,
        train_dataset=train_ds,
        processing_class=tokenizer,
        peft_config=lora,
    )

    result = trainer.train()
    trainer.model.save_pretrained(out_dir)
    tokenizer.save_pretrained(out_dir)
    volume.commit()

    metrics = {k: float(v) for k, v in (result.metrics or {}).items() if isinstance(v, (int, float))}
    print(f"Saved adapter to Modal volume sihat-lora:/adapter  metrics={metrics}")
    return {"ok": True, "metrics": metrics, "volume": "sihat-lora:/adapter"}


@app.local_entrypoint()
def main() -> None:
    print(f"Starting MY-LoRA QLoRA on Modal ({GPU}), max_steps={MAX_STEPS or 'full'}…")
    info = train.remote()
    print(info)
    print(
        "\nDownload with:\n"
        "  modal volume get sihat-lora adapter ai-service/lora\n"
    )
