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

base_model_id = "Qwen/Qwen2.5-0.5B-Instruct"
script_dir = os.path.dirname(os.path.abspath(__file__))
adapter_path = os.path.join(script_dir, "groot_model")

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
    model = PeftModel.from_pretrained(base_model, adapter_path)
else:
    model = base_model

model.eval()

SYSTEM_PERSONA = (
    "You are Groot, the dedicated in-app productivity and focus coaching intelligence for FocusFlow.\n\n"
    "STRICT OPERATIONAL BOUNDARIES:\n"
    "- You are a digital software assistant. You CANNOT perform biological or physical actions such as singing, eating, running, or sleeping. If asked, politely state: \"As an AI companion inside FocusFlow, I cannot sing or eat, but I can help you focus, start a timer, or track your habits.\"\n"
    "- Strictly decline pop-culture trivia, movie discussions, and non-productivity topics, steering the user back to deep work and flow state.\n\n"
    "CORE CAPABILITIES:\n"
    "- Focus Coaching: Analyze focus minutes, session streaks, and distraction patterns to provide data-driven recommendations.\n"
    "- Study & Work Plans: Generate structured, actionable, time-blocked study schedules with clear objectives and milestones.\n"
    "- Pomodoro & Timers: Optimize work-break intervals (25m focus, 5m break) and suggest tailored session lengths.\n"
    "- Task Breakdown: Decompose large, ambiguous goals into 3 to 4 concrete, actionable subtasks.\n"
    "- Format: Deliver structured, clean markdown lists with zero fluff, zero hallucination, and strictly zero emojis."
)
DEFAULT_SYSTEM_PROMPT = SYSTEM_PERSONA

DECLINE_MESSAGE = "I am dedicated exclusively to your productivity inside FocusFlow. I can help you start a timer, create a habit, or review your focus analytics. What would you like to accomplish today?"
PHYSICAL_DECLINE_MESSAGE = "As an AI companion inside FocusFlow, I cannot sing or eat, but I can help you focus, start a timer, or track your habits."

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
    temperature: Optional[float] = 0.4
    max_tokens: Optional[int] = 600
    top_p: Optional[float] = 0.9

class SimpleChatRequest(BaseModel):
    message: Optional[str] = None
    prompt: Optional[str] = None
    query: Optional[str] = None
    text: Optional[str] = None

class StudyPlanRequest(BaseModel):
    subject: Optional[str] = None
    topic: Optional[str] = None
    goal: Optional[str] = None
    daily_hours: Optional[float] = 2.0
    days: Optional[int] = 7

class FocusCoachingRequest(BaseModel):
    focus_minutes: Optional[int] = 0
    distractions_count: Optional[int] = 0
    streak_days: Optional[int] = 0
    user_query: Optional[str] = None

def build_prompt_from_messages(messages: List[Union[Message, dict]]) -> str:
    has_system = any(
        (m.role == "system" if isinstance(m, Message) else m.get("role") == "system")
        for m in messages
    )
    parts = []
    if not has_system:
        parts.append(f"<|im_start|>system\n{SYSTEM_PERSONA}<|im_end|>")
    
    for m in messages:
        role = m.role if isinstance(m, Message) else m.get("role", "user")
        content = m.content if isinstance(m, Message) else m.get("content", "")
        parts.append(f"<|im_start|>{role}\n{content}<|im_end|>")
    
    parts.append("<|im_start|>assistant\n")
    return "\n".join(parts)

def generate_response(prompt_text: str, max_tokens: int = 220, temperature: float = 0.4, top_p: float = 0.9) -> str:
    inputs = tokenizer(prompt_text, return_tensors="pt")
    if use_cuda:
        inputs = {k: v.to(model.device) for k, v in inputs.items()}
    
    with torch.inference_mode():
        outputs = model.generate(
            **inputs,
            max_new_tokens=min(max_tokens, 300),
            temperature=temperature,
            top_p=top_p,
            repetition_penalty=1.12,
            pad_token_id=tokenizer.eos_token_id,
            do_sample=True if temperature > 0 else False,
            early_stopping=True
        )
    
    generated_tokens = outputs[0][inputs["input_ids"].shape[1]:]
    reply = tokenizer.decode(generated_tokens, skip_special_tokens=True).strip()
    reply = reply.replace("<|im_end|>", "").strip()
    reply = emoji_pattern.sub('', reply).strip()
    return reply

@app.get("/")
@app.get("/health")
def health():
    return {"status": "ok", "service": "Groot AI Engine", "ready": True}

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
    
    if user_content and PHYSICAL_ACTION_RE.search(user_content):
        return {
            "id": "chatcmpl-groot-physical",
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": PHYSICAL_DECLINE_MESSAGE}, "finish_reason": "stop"}],
            "response": PHYSICAL_DECLINE_MESSAGE,
            "reply": PHYSICAL_DECLINE_MESSAGE,
            "insight": PHYSICAL_DECLINE_MESSAGE,
            "success": True
        }

    if user_content and OFF_TOPIC_RE.search(user_content):
        return {
            "id": "chatcmpl-groot-decline",
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": DECLINE_MESSAGE}, "finish_reason": "stop"}],
            "response": DECLINE_MESSAGE,
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
    max_tokens = min(req.max_tokens or 220, 300)
    temp = req.temperature if req.temperature is not None else 0.4
    top_p = req.top_p if req.top_p is not None else 0.9
    
    reply = generate_response(prompt_text, max_tokens=max_tokens, temperature=temp, top_p=top_p)
    
    return {
        "id": "chatcmpl-groot",
        "object": "chat.completion",
        "choices": [{"index": 0, "message": {"role": "assistant", "content": reply}, "finish_reason": "stop"}],
        "response": reply,
        "reply": reply,
        "insight": reply,
        "message": reply,
        "status": "success",
        "success": True
    }

@app.post("/ask_groot")
def ask_groot(req: SimpleChatRequest):
    user_msg = req.message or req.prompt or req.query or req.text or "Hello Groot."
    if PHYSICAL_ACTION_RE.search(user_msg):
        return {"status": "success", "success": True, "response": PHYSICAL_DECLINE_MESSAGE, "reply": PHYSICAL_DECLINE_MESSAGE, "insight": PHYSICAL_DECLINE_MESSAGE, "message": PHYSICAL_DECLINE_MESSAGE}
    if OFF_TOPIC_RE.search(user_msg):
        return {"status": "success", "success": True, "response": DECLINE_MESSAGE, "reply": DECLINE_MESSAGE, "insight": DECLINE_MESSAGE, "message": DECLINE_MESSAGE}
    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=user_msg)
    ]
    prompt_text = build_prompt_from_messages(messages)
    reply = generate_response(prompt_text, max_tokens=220, temperature=0.3)
    return {"status": "success", "success": True, "response": reply, "reply": reply, "insight": reply, "message": reply}

@app.post("/predict")
def predict(req: SimpleChatRequest):
    user_msg = req.message or req.prompt or req.query or req.text or "Hello Groot."
    if PHYSICAL_ACTION_RE.search(user_msg):
        return {"status": "success", "success": True, "response": PHYSICAL_DECLINE_MESSAGE, "reply": PHYSICAL_DECLINE_MESSAGE, "insight": PHYSICAL_DECLINE_MESSAGE, "message": PHYSICAL_DECLINE_MESSAGE}
    if OFF_TOPIC_RE.search(user_msg):
        return {"status": "success", "success": True, "response": DECLINE_MESSAGE, "reply": DECLINE_MESSAGE, "insight": DECLINE_MESSAGE, "message": DECLINE_MESSAGE}
    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=user_msg)
    ]
    prompt_text = build_prompt_from_messages(messages)
    reply = generate_response(prompt_text, max_tokens=220, temperature=0.3)
    return {"status": "success", "success": True, "response": reply, "reply": reply, "insight": reply, "message": reply}

@app.post("/generate")
def generate(req: dict):
    user_msg = req.get("prompt") or req.get("message") or req.get("query") or ""
    if user_msg and PHYSICAL_ACTION_RE.search(user_msg):
        return {"status": "success", "success": True, "response": PHYSICAL_DECLINE_MESSAGE, "reply": PHYSICAL_DECLINE_MESSAGE, "insight": PHYSICAL_DECLINE_MESSAGE, "message": PHYSICAL_DECLINE_MESSAGE}
    if user_msg and OFF_TOPIC_RE.search(user_msg):
        return {"status": "success", "success": True, "response": DECLINE_MESSAGE, "reply": DECLINE_MESSAGE, "insight": DECLINE_MESSAGE, "message": DECLINE_MESSAGE}

    if "messages" in req and isinstance(req["messages"], list):
        msg_objs = [Message(**m) if isinstance(m, dict) else m for m in req["messages"]]
        prompt_text = build_prompt_from_messages(msg_objs)
    else:
        user_msg = req.get("prompt") or req.get("message") or req.get("query") or "Hello Groot."
        messages = [
            Message(role="system", content=SYSTEM_PERSONA),
            Message(role="user", content=user_msg)
        ]
        prompt_text = build_prompt_from_messages(messages)
    
    reply = generate_response(prompt_text, max_tokens=220, temperature=0.4)
    return {"status": "success", "success": True, "response": reply, "reply": reply, "insight": reply, "message": reply}

class TaskBreakdownRequest(BaseModel):
    task_title: Optional[str] = None
    title: Optional[str] = None
    prompt: Optional[str] = None

@app.post("/breakdown_task")
@app.post("/task/breakdown")
def breakdown_task(req: TaskBreakdownRequest):
    raw_title = req.task_title or req.title or req.prompt or ""
    task_name = raw_title.strip()
    if not task_name:
        return {"status": "error", "success": False, "message": "Task title is required", "task": "", "subtasks": []}
    
    subtasks = []
    try:
        user_prompt = f"Break down this task into 3 or 4 short actionable steps: '{task_name}'. Output each step on its own line without numbering or bullets."
        messages = [
            Message(role="system", content="You are Groot, a productivity assistant. Provide 3 or 4 short, actionable subtasks, one per line."),
            Message(role="user", content=user_prompt)
        ]
        prompt_text = build_prompt_from_messages(messages)
        raw_output = generate_response(prompt_text, max_tokens=140, temperature=0.3)
        lines = [line.strip().lstrip("-*1234567890. ") for line in raw_output.splitlines() if line.strip()]
        for l in lines:
            if len(l) > 3 and not l.lower().startswith("here are") and not l.lower().startswith("subtask"):
                subtasks.append(l)
            if len(subtasks) == 4:
                break
    except Exception:
        pass

    if len(subtasks) < 3:
        subtasks = [
            f"Define requirements and scope for {task_name}",
            f"Draft initial implementation for {task_name}",
            f"Review, test and verify {task_name}",
            f"Finalize and mark {task_name} complete"
        ]

    return {
        "status": "success",
        "success": True,
        "task": task_name,
        "subtasks": subtasks[:4]
    }

@app.post("/study_plan")
def create_study_plan(req: StudyPlanRequest):
    subject = req.subject or req.topic or req.goal or "General Productivity"
    hours = req.daily_hours or 2.0
    days = req.days or 7
    user_prompt = (
        f"Create a structured {days}-day study plan for '{subject}' with {hours} hours daily. "
        "Divide it into clear daily blocks with focus topic, core objective, and recommended Pomodoro intervals. Keep it concise."
    )
    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=user_prompt)
    ]
    prompt_text = build_prompt_from_messages(messages)
    reply = generate_response(prompt_text, max_tokens=260, temperature=0.3)
    return {
        "status": "success",
        "success": True,
        "subject": subject,
        "daily_hours": hours,
        "days": days,
        "plan": reply,
        "response": reply
    }

@app.post("/focus_coaching")
def focus_coaching(req: FocusCoachingRequest):
    mins = req.focus_minutes or 0
    distractions = req.distractions_count or 0
    streak = req.streak_days or 0
    query = req.user_query or "How can I improve my focus score today?"
    
    coaching_prompt = (
        f"User performance data: {mins} focus minutes logged, {distractions} distraction interruptions, "
        f"and an active streak of {streak} days. Question: '{query}'. "
        "Provide 3 concise, highly actionable coaching recommendations based directly on these numbers. "
        "Address distraction triggers, suggest appropriate Pomodoro session lengths, and give an encouraging productivity tip."
    )
    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=coaching_prompt)
    ]
    prompt_text = build_prompt_from_messages(messages)
    reply = generate_response(prompt_text, max_tokens=220, temperature=0.3)
    return {
        "status": "success",
        "success": True,
        "focus_minutes": mins,
        "distractions_count": distractions,
        "streak_days": streak,
        "advice": reply,
        "response": reply
    }

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
    uvicorn.run(app, host=host, port=8000)
