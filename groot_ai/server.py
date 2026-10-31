import os
import re
from typing import List, Optional, Union
import torch
from fastapi import FastAPI
from pydantic import BaseModel
from transformers import AutoModelForCausalLM, AutoTokenizer
from peft import PeftModel

app = FastAPI(title="Groot AI Engine")

# Base model identifier and path to fine-tuned weights
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
    torch_dtype=torch.float16 if use_cuda else torch.float32,
    device_map="auto" if use_cuda else None
)

if os.path.exists(adapter_path):
    print("Attaching trained Groot adapter...")
    model = PeftModel.from_pretrained(base_model, adapter_path)
else:
    print("Using base model directly (adapter directory not found)...")
    model = base_model

model.eval()
print("Groot AI Engine is online and ready!")

DEFAULT_SYSTEM_PROMPT = (
    "You are Groot, an intelligent, articulate, adaptive AI collaborator and focus coach within the FocusFlow application. "
    "Your goal is to provide insightful, clear, and direct answers to any query—including coding, software engineering, math, "
    "writing, general questions, and productivity guidance. If you do not know the answer or lack verified context, "
    "clearly state that you do not know rather than guessing. Maintain a grounded, helpful tone. Never use emojis."
)

emoji_pattern = re.compile(r'[\U00010000-\U0010ffff\u2600-\u27bf\u2300-\u23ff\u2b50\u2b55]')

class Message(BaseModel):
    role: str
    content: str

class ChatCompletionRequest(BaseModel):
    model: Optional[str] = "groot"
    messages: Optional[List[Message]] = None
    prompt: Optional[str] = None
    temperature: Optional[float] = 0.7
    max_tokens: Optional[int] = 512
    top_p: Optional[float] = 0.9

class SimpleChatRequest(BaseModel):
    message: Optional[str] = None
    prompt: Optional[str] = None

def build_prompt_from_messages(messages: List[Message]) -> str:
    has_system = any(m.role == "system" for m in messages)
    parts = []
    if not has_system:
        parts.append(f"<|im_start|>system\n{DEFAULT_SYSTEM_PROMPT}<|im_end|>")
    
    for m in messages:
        parts.append(f"<|im_start|>{m.role}\n{m.content}<|im_end|>")
    
    parts.append("<|im_start|>assistant\n")
    return "\n".join(parts)

def generate_response(prompt_text: str, max_tokens: int = 512, temperature: float = 0.7, top_p: float = 0.9) -> str:
    inputs = tokenizer(prompt_text, return_tensors="pt")
    if use_cuda:
        inputs = {k: v.to(model.device) for k, v in inputs.items()}
    
    with torch.no_grad():
        outputs = model.generate(
            **inputs,
            max_new_tokens=max_tokens,
            temperature=temperature,
            top_p=top_p,
            repetition_penalty=1.1,
            pad_token_id=tokenizer.eos_token_id,
            do_sample=True if temperature > 0 else False
        )
    
    generated_tokens = outputs[0][inputs["input_ids"].shape[1]:]
    reply = tokenizer.decode(generated_tokens, skip_special_tokens=True).strip()
    reply = reply.replace("<|im_end|>", "").strip()
    
    # Strictly purge any emojis
    reply = emoji_pattern.sub('', reply).strip()
    return reply

@app.get("/")
def health():
    return {"status": "ok", "service": "Groot AI Engine"}

@app.post("/v1/chat/completions")
def chat_completions(req: ChatCompletionRequest):
    messages = req.messages or []
    if not messages and req.prompt:
        messages = [
            Message(role="system", content=DEFAULT_SYSTEM_PROMPT),
            Message(role="user", content=req.prompt)
        ]
    elif not messages:
        messages = [
            Message(role="system", content=DEFAULT_SYSTEM_PROMPT),
            Message(role="user", content="Hello Groot.")
        ]
    
    prompt_text = build_prompt_from_messages(messages)
    max_tokens = req.max_tokens or 512
    temp = req.temperature if req.temperature is not None else 0.7
    top_p = req.top_p if req.top_p is not None else 0.9
    
    reply = generate_response(prompt_text, max_tokens=max_tokens, temperature=temp, top_p=top_p)
    
    return {
        "id": "chatcmpl-groot",
        "object": "chat.completion",
        "choices": [
            {
                "index": 0,
                "message": {
                    "role": "assistant",
                    "content": reply
                },
                "finish_reason": "stop"
            }
        ],
        "reply": reply,
        "insight": reply,
        "success": True
    }

@app.post("/ask_groot")
def ask_groot(req: SimpleChatRequest):
    user_msg = req.message or req.prompt or "Hello Groot."
    messages = [
        Message(role="system", content=DEFAULT_SYSTEM_PROMPT),
        Message(role="user", content=user_msg)
    ]
    prompt_text = build_prompt_from_messages(messages)
    reply = generate_response(prompt_text, max_tokens=512)
    return {"success": True, "reply": reply, "insight": reply}

@app.post("/generate")
def generate(req: dict):
    if "messages" in req and isinstance(req["messages"], list):
        msg_objs = [Message(**m) for m in req["messages"]]
        prompt_text = build_prompt_from_messages(msg_objs)
    else:
        user_msg = req.get("prompt") or req.get("message") or "Hello Groot."
        messages = [
            Message(role="system", content=DEFAULT_SYSTEM_PROMPT),
            Message(role="user", content=user_msg)
        ]
        prompt_text = build_prompt_from_messages(messages)
    
    reply = generate_response(prompt_text, max_tokens=512)
    return {"reply": reply, "insight": reply, "success": True}

if __name__ == "__main__":
    import uvicorn
    print("Starting Groot AI FastAPI server on http://0.0.0.0:8000...")
    uvicorn.run(app, host="0.0.0.0", port=8000)

