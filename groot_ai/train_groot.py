import os
import torch
from datasets import load_dataset
from transformers import AutoModelForCausalLM, AutoTokenizer
from trl import SFTTrainer, SFTConfig
from peft import LoraConfig

def main():
    model_id = "Qwen/Qwen2.5-0.5B-Instruct"
    
    print("1. Loading Tokenizer and Base Model...")
    tokenizer = AutoTokenizer.from_pretrained(model_id)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    use_cuda = torch.cuda.is_available()
    print(f"Hardware detection: {'NVIDIA CUDA GPU' if use_cuda else 'CPU'}")

    model = AutoModelForCausalLM.from_pretrained(
        model_id,
        torch_dtype=torch.float16 if use_cuda else torch.float32,
        device_map="auto" if use_cuda else None
    )

    print("2. Formatting dataset...")
    script_dir = os.path.dirname(os.path.abspath(__file__))
    dataset_path = os.path.join(script_dir, "groot_dataset.jsonl")

    def format_chat(example):
        text = (
            f"<|im_start|>system\n"
            f"You are Groot, an intelligent, concise productivity assistant in the Flow app.<|im_end|>\n"
            f"<|im_start|>user\n{example['instruction']}<|im_end|>\n"
            f"<|im_start|>assistant\n{example['response']}<|im_end|>"
        )
        return {"text": text}

    raw_data = load_dataset("json", data_files=dataset_path)["train"]
    formatted_data = raw_data.map(format_chat)

    peft_config = LoraConfig(
        r=8,
        lora_alpha=16,
        target_modules=["q_proj", "v_proj"],
        task_type="CAUSAL_LM",
        lora_dropout=0.05
    )

    # In TRL 1.16+, 'max_seq_length' is 'max_length'
    training_args = SFTConfig(
        output_dir=os.path.join(script_dir, "groot_checkpoints"),
        dataset_text_field="text",
        max_length=256,
        per_device_train_batch_size=1,
        gradient_accumulation_steps=2,
        num_train_epochs=3,
        learning_rate=2e-4,
        logging_steps=1,
        save_strategy="no",
        fp16=use_cuda,
        use_cpu=not use_cuda
    )

    print("3. Training Groot on your custom data...")
    trainer = SFTTrainer(
        model=model,
        train_dataset=formatted_data,
        peft_config=peft_config,
        args=training_args,
    )

    trainer.train()

    save_dir = os.path.join(script_dir, "groot_model")
    print(f"4. Saving fine-tuned Groot to {save_dir}...")
    trainer.model.save_pretrained(save_dir)
    tokenizer.save_pretrained(save_dir)
    print("Done! Groot fine-tuning completed successfully.")

if __name__ == "__main__":
    main()