import os
import torch
from fastapi import FastAPI
from pydantic import BaseModel
from transformers import AutoModelForCausalLM, AutoTokenizer
from peft import PeftModel

app = FastAPI(title="Groot AI Engine")

# Base model identifier and path to your fine-tuned weights
base_model_id = "Qwen/Qwen2.5-0.5B-Instruct"
script_dir = os.path.dirname(os.path.abspath(__file__))
adapter_path = os.path.join(script_dir, "groot_model")

print("Loading base tokenizer and model...")
tokenizer = AutoTokenizer.from_pretrained(base_model_id)
if tokenizer.pad_token is None:
    tokenizer.pad_token = tokenizer.eos_token

use_cuda = torch.cuda.is_available()
base_model = AutoModelForCausalLM.from_pretrained(
    base_model_id,
    dtype=torch.float16 if use_cuda else torch.float32,
    device_map="auto" if use_cuda else None
)

print("Attaching trained Groot adapter...")
model = PeftModel.from_pretrained(base_model, adapter_path)
model.eval()
print("Groot AI Engine is online and ready!")

class ChatRequest(BaseModel):
    message: str

@app.get("/")
def health():
    return {"status": "ok", "service": "Groot AI Engine"}

@app.post("/ask_groot")
def ask_groot(req: ChatRequest):
    prompt = (
        f"<|im_start|>system\n"
        f"You are Groot, an intelligent, concise productivity assistant in the Flow app.<|im_end|>\n"
        f"<|im_start|>user\n{req.message}<|im_end|>\n"
        f"<|im_start|>assistant\n"
    )
    
    inputs = tokenizer(prompt, return_tensors="pt")
    if use_cuda:
        inputs = {k: v.to(model.device) for k, v in inputs.items()}
    
    with torch.no_grad():
        outputs = model.generate(
            **inputs,
            max_new_tokens=128,
            temperature=0.7,
            top_p=0.9,
            repetition_penalty=1.1,
            pad_token_id=tokenizer.eos_token_id
        )
    
    # Strip prompt prefix from output tokens
    generated_tokens = outputs[0][inputs["input_ids"].shape[1]:]
    reply = tokenizer.decode(generated_tokens, skip_special_tokens=True).strip()
    
    # Remove any trailing ChatML end tags if present
    reply = reply.replace("<|im_end|>", "").strip()
    
    return {"success": True, "reply": reply, "insight": reply}

@app.post("/generate")
def generate(req: dict):
    msg = req.get("message") or req.get("prompt") or ""
    return ask_groot(ChatRequest(message=msg))

@app.post("/v1/chat/completions")
def chat_completions(req: dict):
    messages = req.get("messages", [])
    user_msg = ""
    for m in messages:
        if m.get("role") == "user":
            user_msg = m.get("content", "")
    if not user_msg:
        user_msg = req.get("prompt", "")
    res = ask_groot(ChatRequest(message=user_msg))
    return {
        "id": "chatcmpl-groot",
        "object": "chat.completion",
        "choices": [
            {
                "index": 0,
                "message": {
                    "role": "assistant",
                    "content": res["reply"]
                },
                "finish_reason": "stop"
            }
        ],
        "insight": res["reply"],
        "reply": res["reply"]
    }
