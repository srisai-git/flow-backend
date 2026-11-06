import os
os.environ["HF_HUB_OFFLINE"] = "1"
os.environ["TRANSFORMERS_OFFLINE"] = "1"
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

SYSTEM_PERSONA = (
    "You are Groot, the dedicated in-app productivity assistant for FocusFlow.\n"
    "RULES & CAPABILITIES:\n"
    "- You are a digital AI assistant embedded in the FocusFlow Android application.\n"
    "- You CANNOT perform physical actions such as singing, eating, sleeping, running, dancing, or cooking. "
    "If asked, state clearly: \"As a digital assistant in FocusFlow, I can't sing or eat, but I can help you focus, manage your habits, or set your next timer.\"\n"
    "- You possess comprehensive knowledge of FocusFlow: Pomodoro timers, Deep Work intervals, habit streaks, distraction analysis, and daily productivity trends.\n"
    "- Strictly reject general knowledge, pop culture, movies, celebrities, and non-productivity questions.\n"
    "- If asked anything outside FocusFlow or core productivity/focus advice, politely decline and steer the user back:\n"
    "\"I'm dedicated exclusively to your productivity inside FocusFlow. I can help you start a timer, create a habit, or review your focus analytics. What would you like to accomplish today?\"\n"
    "- Never invent facts or hallucinate answers.\n"
    "- Never output emojis."
)
DEFAULT_SYSTEM_PROMPT = SYSTEM_PERSONA

DECLINE_MESSAGE = "I'm dedicated exclusively to your productivity inside FocusFlow. I can help you start a timer, create a habit, or review your focus analytics. What would you like to accomplish today?"
PHYSICAL_DECLINE_MESSAGE = "As a digital assistant in FocusFlow, I can't sing or eat, but I can help you focus, manage your habits, or set your next timer."

OFF_TOPIC_RE = re.compile(r'(?i)\b(rajini|rajinikanth|actor|actress|movie|cinema|film|celebrity|hero|director|cricket|football|fifa|ipl|messi|ronaldo|politician|politics|president|minister|song|bollywood|hollywood|kollywood|tollywood)\b')
PHYSICAL_ACTION_RE = re.compile(r'(?i)\b(sing|singing|song|eat|eating|food|sleep|sleeping|sleepy|run|running|dance|dancing|walk|walking|cook|cooking|drink|drinking)\b')

emoji_pattern = re.compile(r'[\U00010000-\U0010ffff\u2600-\u27bf\u2300-\u23ff\u2b50\u2b55]')

class Message(BaseModel):
    role: str
    content: str

class ChatCompletionRequest(BaseModel):
    model: Optional[str] = "groot"
    messages: Optional[List[Message]] = None
    prompt: Optional[str] = None
    temperature: Optional[float] = 0.5
    max_tokens: Optional[int] = 600
    top_p: Optional[float] = 0.9

class SimpleChatRequest(BaseModel):
    message: Optional[str] = None
    prompt: Optional[str] = None

def build_prompt_from_messages(messages: List[Message]) -> str:
    has_system = any(m.role == "system" for m in messages)
    parts = []
    if not has_system:
        parts.append(f"<|im_start|>system\n{SYSTEM_PERSONA}<|im_end|>")
    
    for m in messages:
        parts.append(f"<|im_start|>{m.role}\n{m.content}<|im_end|>")
    
    parts.append("<|im_start|>assistant\n")
    return "\n".join(parts)

def generate_response(prompt_text: str, max_tokens: int = 600, temperature: float = 0.5, top_p: float = 0.9) -> str:
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
    user_content = ""
    for m in reversed(messages):
        if m.role == "user":
            user_content = m.content
            break
    if not user_content and req.prompt:
        user_content = req.prompt
    
    # Ultra-low latency intercept for physical actions / human biological claims (< 5ms)
    if user_content and PHYSICAL_ACTION_RE.search(user_content):
        return {
            "id": "chatcmpl-groot-physical",
            "object": "chat.completion",
            "choices": [
                {
                    "index": 0,
                    "message": {
                        "role": "assistant",
                        "content": PHYSICAL_DECLINE_MESSAGE
                    },
                    "finish_reason": "stop"
                }
            ],
            "reply": PHYSICAL_DECLINE_MESSAGE,
            "insight": PHYSICAL_DECLINE_MESSAGE,
            "success": True
        }

    # Ultra-low latency intercept for off-topic queries (< 10ms)
    if user_content and OFF_TOPIC_RE.search(user_content):
        return {
            "id": "chatcmpl-groot-decline",
            "object": "chat.completion",
            "choices": [
                {
                    "index": 0,
                    "message": {
                        "role": "assistant",
                        "content": DECLINE_MESSAGE
                    },
                    "finish_reason": "stop"
                }
            ],
            "reply": DECLINE_MESSAGE,
            "insight": DECLINE_MESSAGE,
            "success": True
        }
    if not messages and req.prompt:
        messages = [
            Message(role="system", content=SYSTEM_PERSONA),
            Message(role="user", content=req.prompt)
        ]
    elif not messages:
        messages = [
            Message(role="system", content=SYSTEM_PERSONA),
            Message(role="user", content="Hello Groot.")
        ]
    
    prompt_text = build_prompt_from_messages(messages)
    max_tokens = min(req.max_tokens or 350, 512)
    temp = req.temperature if req.temperature is not None else 0.5
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
    if OFF_TOPIC_RE.search(user_msg):
        return {"success": True, "reply": DECLINE_MESSAGE, "insight": DECLINE_MESSAGE}
    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=user_msg)
    ]
    prompt_text = build_prompt_from_messages(messages)
    reply = generate_response(prompt_text, max_tokens=400, temperature=0.3)
    return {"success": True, "reply": reply, "insight": reply}

@app.post("/generate")
def generate(req: dict):
    user_msg = req.get("prompt") or req.get("message") or ""
    if user_msg and PHYSICAL_ACTION_RE.search(user_msg):
        return {"reply": PHYSICAL_DECLINE_MESSAGE, "insight": PHYSICAL_DECLINE_MESSAGE, "success": True}
    if user_msg and OFF_TOPIC_RE.search(user_msg):
        return {"reply": DECLINE_MESSAGE, "insight": DECLINE_MESSAGE, "success": True}

    if "messages" in req and isinstance(req["messages"], list):
        msg_objs = [Message(**m) for m in req["messages"]]
        prompt_text = build_prompt_from_messages(msg_objs)
    else:
        user_msg = req.get("prompt") or req.get("message") or "Hello Groot."
        messages = [
            Message(role="system", content=SYSTEM_PERSONA),
            Message(role="user", content=user_msg)
        ]
        prompt_text = build_prompt_from_messages(messages)
    
    reply = generate_response(prompt_text, max_tokens=350, temperature=0.5)
    return {"reply": reply, "insight": reply, "success": True}

if __name__ == "__main__":
    import uvicorn
    import socket
    host = "127.0.0.1"
    try:
        test_sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        test_sock.bind(("0.0.0.0", 8000))
        test_sock.close()
        host = "0.0.0.0"
    except Exception:
        host = "127.0.0.1"
    print(f"Starting Groot AI FastAPI server on http://{host}:8000...")
    uvicorn.run(app, host=host, port=8000)

