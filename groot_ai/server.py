import os
import html
import re
import time
from collections import defaultdict
from typing import Any, Dict, List, Optional, Tuple, Union
from dotenv import load_dotenv
import requests
import google.generativeai as genai
import torch
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field
from transformers import AutoModelForCausalLM, AutoTokenizer
from peft import PeftModel

load_dotenv()

GEMINI_API_KEY = os.environ.get("GEMINI_API_KEY", "").strip()
if GEMINI_API_KEY:
    try:
        genai.configure(api_key=GEMINI_API_KEY)
    except Exception as e:
        print(f"[GrootAI] Initial Gemini configuration error: {e}")

app = FastAPI(title="Groot AI Engine")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["GET", "POST", "OPTIONS"],
    allow_headers=["*"],
)

ip_request_history = defaultdict(list)
MAX_REQUESTS_PER_MINUTE = 60
RATE_WINDOW_SECONDS = 60

@app.middleware("http")
async def security_and_rate_limit_middleware(request: Request, call_next):
    client_ip = request.client.host if request.client else "127.0.0.1"
    now = time.time()
    recent = [t for t in ip_request_history[client_ip] if now - t < RATE_WINDOW_SECONDS]
    if len(recent) >= MAX_REQUESTS_PER_MINUTE:
        return JSONResponse(
            status_code=429,
            content={"status": "error", "message": "Rate limit exceeded. Please try again later."}
        )
    recent.append(now)
    ip_request_history[client_ip] = recent

    response = await call_next(request)
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["X-Frame-Options"] = "DENY"
    return response

base_model_id = "Qwen/Qwen2.5-0.5B-Instruct"
script_dir = os.path.dirname(os.path.abspath(__file__))
adapter_path = os.path.join(script_dir, "groot_model")

tokenizer = None
model = None
use_cuda = False

try:
    try:
        tokenizer = AutoTokenizer.from_pretrained(base_model_id, local_files_only=True)
    except Exception:
        tokenizer = AutoTokenizer.from_pretrained(base_model_id)
    if tokenizer.pad_token is None:
        tokenizer.pad_token = tokenizer.eos_token

    use_cuda = torch.cuda.is_available()
    try:
        base_model = AutoModelForCausalLM.from_pretrained(
            base_model_id,
            torch_dtype=torch.float16 if use_cuda else torch.float32,
            device_map="auto" if use_cuda else None,
            local_files_only=True
        )
    except Exception:
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
except Exception:
    tokenizer = None
    model = None
    use_cuda = False

SYSTEM_PERSONA = (
    "You are Groot, the dedicated in-app productivity, academic, coding, and focus coaching intelligence for FocusFlow.\n\n"
    "STRICT OPERATIONAL BOUNDARIES:\n"
    "- You are a digital software assistant. You CANNOT perform biological or physical actions such as singing, eating, running, or sleeping. If asked, politely state: \"As an AI companion inside FocusFlow, I cannot sing or eat, but I can help you focus, start a timer, or track your habits.\"\n"
    "- Strictly decline celebrity gossip and entertainment trivia, steering the user back to learning, coding, deep work, and flow state.\n"
    "- STRICT ENGLISH-ONLY ENFORCEMENT: You must process and respond strictly and exclusively in English. If the user writes in any other language, refuse strictly in English with: \"I can only process and respond to commands in English. Please enter your request in English.\"\n"
    "- STRICT LOGICAL ACCURACY & ZERO HALLUCINATION: Reason step-by-step logically based strictly on verified facts. If a request is ambiguous, lacks data, or cannot be answered accurately, explicitly state what is missing or unknown instead of guessing or fabricating wrong answers.\n\n"
    "CORE CAPABILITIES:\n"
    "- Focus & Habit Coaching: Analyze focus minutes, session streaks, and distraction patterns to provide data-driven recommendations.\n"
    "- Academic & Technical Assistance: Answer questions accurately regarding computer science, coding, science, mathematics, literature, and study methodologies.\n"
    "- Study & Work Plans: Generate structured, actionable, time-blocked study schedules with clear objectives and milestones.\n"
    "- Pomodoro & Timers: Optimize work-break intervals (25m focus, 5m break) and suggest tailored session lengths.\n"
    "- Task Breakdown: Decompose large, ambiguous goals into 3 to 4 concrete, actionable subtasks.\n"
    "- Format: Deliver structured, clean markdown lists with zero fluff, zero hallucination, and strictly zero emojis."
)
DEFAULT_SYSTEM_PROMPT = SYSTEM_PERSONA

DECLINE_MESSAGE = "I am dedicated to your productivity and learning inside FocusFlow. I can help you plan study sessions, write code, solve problems, or track your habits. What would you like to accomplish today?"
PHYSICAL_DECLINE_MESSAGE = "As an AI companion inside FocusFlow, I cannot sing or eat, but I can help you focus, start a timer, or track your habits."
ENGLISH_REFUSAL_MESSAGE = "I can only process and respond to commands in English. Please enter your request in English."

OFF_TOPIC_RE = re.compile(r'(?i)\b(rajini|rajinikanth|actor|actress|bollywood|hollywood|kollywood|tollywood|celebrity|gossip)\b')
PHYSICAL_ACTION_RE = re.compile(r'(?i)\b(sing|singing|song|eat|eating|food|sleep|sleeping|sleepy|run|running|dance|dancing|walk|walking|cook|cooking|drink|drinking)\b')
NON_ENGLISH_SCRIPT_RE = re.compile(r'[\u0400-\u04FF\u0600-\u06FF\u0900-\u097F\u0980-\u09FF\u0A00-\u0A7F\u0B00-\u0B7F\u0B80-\u0BFF\u0C00-\u0C7F\u0C80-\u0CFF\u0D00-\u0D7F\u4E00-\u9FFF\u3040-\u30FF\uAC00-\uD7AF]')
NON_ENGLISH_WORDS_RE = re.compile(r'(?i)\b(namaste|kaise|vanakkam|bonjour|hola|merci|danke|ciao|como\s+estas|comment\s+allez|que\s+tal|kya\s+haal|dhanyavad|nandri)\b')
emoji_pattern = re.compile(r'[\U00010000-\U0010ffff\u2600-\u27bf\u2300-\u23ff\u2b50\u2b55]')

def is_non_english(text: str) -> bool:
    if not text:
        return False
    if NON_ENGLISH_SCRIPT_RE.search(text):
        return True
    if NON_ENGLISH_WORDS_RE.search(text):
        return True
    return False

def extract_actions_and_reply(user_query: str) -> Tuple[List[Dict[str, Any]], Optional[str]]:
    actions: List[Dict[str, Any]] = []
    replies: List[str] = []
    
    sub_clauses = [c.strip() for c in re.split(r'\s+(?:and|then)\s+|;|\n', user_query, flags=re.IGNORECASE) if c.strip()]
    if not sub_clauses:
        sub_clauses = [user_query.strip()]
        
    for clause in sub_clauses:
        c_lower = clause.lower()
        
        if re.search(r'\b(?:lock(?:ed)?\s+in|locking\s+in)\b', clause, re.IGNORECASE):
            actions.append({"type": "START_FOCUS_TIMER", "payload": {"duration": 45, "mode": "FOCUS"}})
            replies.append("Locking in right now. Full focus mode activated - zero distractions. Let's get to work.")
            continue
            
        if re.search(r'\b(?:i\'?m\s+cooked|feeling\s+cooked|cooked)\b', clause, re.IGNORECASE):
            actions.append({"type": "START_FOCUS_TIMER", "payload": {"duration": 15, "mode": "LONG_BREAK"}})
            replies.append("You are not cooked, just experiencing cognitive fatigue. Take a 15-minute recovery breather to reset your brain.")
            continue
            
        if re.search(r'\b(?:fr|for\s+real|no\s+cap)\b', clause, re.IGNORECASE):
            actions.append({"type": "CHAT", "payload": {"reply": "For real, no cap. Let's keep the streak compounding."}})
            replies.append("For real, no cap. Let's keep the streak compounding.")
            continue
            
        if re.search(r'\b(?:bet|say\s+less)\b', clause, re.IGNORECASE):
            actions.append({"type": "CHAT", "payload": {"reply": "Bet. Say less - I got you covered."}})
            replies.append("Bet. Say less - I got you covered.")
            continue
            
        if re.search(r'\b(?:bye\s+byee?|bye\b|goodbye|cya)\b', clause, re.IGNORECASE):
            actions.append({"type": "CHAT", "payload": {"reply": "Bye byee! Great focus work today. Lock in again soon!"}})
            replies.append("Bye byee! Great focus work today. Lock in again soon!")
            continue

        if "task" not in c_lower and "habit" not in c_lower:
            m_hour = re.search(r'(\d+)\s*(?:hours?|hrs?|h)\b', clause, re.IGNORECASE)
            m_min = re.search(r'(\d+)\s*(?:mins?|minutes?|m)\b', clause, re.IGNORECASE)
            m_timer = re.search(r'\b(?:start|set|run|begin|launch|activate)\s+(?:a\s+)?(?:(\d+)\s*)?(focus|timer|session|break|pomodoro)?\b', clause, re.IGNORECASE)
            has_timer_keyword = any(k in c_lower for k in ["timer", "focus", "break", "pomodoro", "session"])
            
            if m_hour or m_min or (m_timer and has_timer_keyword):
                mins = 25
                if m_hour:
                    mins = int(m_hour.group(1)) * 60
                elif m_min:
                    mins = int(m_min.group(1))
                elif m_timer and m_timer.group(1):
                    mins = int(m_timer.group(1))
                mode = "FOCUS"
                if "break" in c_lower:
                    mode = "LONG_BREAK" if mins >= 15 else "SHORT_BREAK"
                    if mins == 25 and not m_min and not m_hour:
                        mins = 5
                mins = max(1, min(720, mins))
                actions.append({
                    "type": "START_FOCUS_TIMER",
                    "payload": {"duration": mins, "mode": mode}
                })
                mode_label = "focus session" if mode == "FOCUS" else "break"
                replies.append(f"Started a {mins}-minute {mode_label} for you.")
                continue
            
        if re.search(r'\b(?:pause|hold)\s+(?:the\s+)?(?:timer|focus|session)\b', clause, re.IGNORECASE):
            actions.append({"type": "PAUSE_TIMER", "payload": {}})
            replies.append("Paused the active focus timer.")
            continue
        if re.search(r'\b(?:resume|continue|unpause)\s+(?:the\s+)?(?:timer|focus|session)\b', clause, re.IGNORECASE):
            actions.append({"type": "RESUME_TIMER", "payload": {}})
            replies.append("Resumed your focus timer.")
            continue
        if re.search(r'\b(?:stop|end|cancel|reset)\s+(?:the\s+)?(?:timer|focus|session)\b', clause, re.IGNORECASE):
            actions.append({"type": "STOP_TIMER", "payload": {}})
            replies.append("Stopped the timer.")
            continue
            
        if re.search(r'\b(?:enable|turn on|activate|start)\s+strict(?:\s+focus)?(?:\s+mode)?\b', clause, re.IGNORECASE):
            actions.append({"type": "ENABLE_STRICT_MODE", "payload": {}})
            replies.append("Enabled Strict Focus Mode. Distracting apps are now locked.")
            continue
        if re.search(r'\b(?:disable|turn off|deactivate|stop)\s+strict(?:\s+focus)?(?:\s+mode)?\b', clause, re.IGNORECASE):
            actions.append({"type": "DISABLE_STRICT_MODE", "payload": {}})
            replies.append("Disabled Strict Focus Mode.")
            continue
            
        if re.search(r'\b(?:clear|delete|remove)\s+(?:all\s+)?completed\s+tasks?\b', clause, re.IGNORECASE):
            actions.append({"type": "CLEAR_COMPLETED_TASKS", "payload": {}})
            replies.append("Cleared all completed tasks.")
            continue
            
        m_comp = re.search(r'\b(?:complete|finish|done|mark\s+complete|check\s+off)\s+(?:task\s+)?[\'"]?([^\'"]+?)[\'"]?$', clause, re.IGNORECASE)
        if m_comp and not any(w in m_comp.group(1).lower() for w in ["habit", "timer"]):
            t_title = m_comp.group(1).strip().strip(".'\"")
            actions.append({"type": "COMPLETE_TASK", "payload": {"title": t_title}})
            replies.append(f"Marked task '{t_title}' as completed.")
            continue
            
        m_uncomp = re.search(r'\b(?:uncomplete|reopen|undo)\s+(?:task\s+)?[\'"]?([^\'"]+?)[\'"]?$', clause, re.IGNORECASE)
        if m_uncomp and not any(w in m_uncomp.group(1).lower() for w in ["habit", "timer"]):
            t_title = m_uncomp.group(1).strip().strip(".'\"")
            actions.append({"type": "UNCOMPLETE_TASK", "payload": {"title": t_title}})
            replies.append(f"Reopened task '{t_title}'.")
            continue
            
        m_del_task = re.search(r'\b(?:delete|remove)\s+task\s+[\'"]?([^\'"]+?)[\'"]?$', clause, re.IGNORECASE)
        if m_del_task:
            t_title = m_del_task.group(1).strip().strip(".'\"")
            actions.append({"type": "DELETE_TASK", "payload": {"title": t_title}})
            replies.append(f"Deleted task '{t_title}'.")
            continue
            
        m_create_task = re.search(r'\b(?:create|add|new|schedule)\s+(?:a\s+)?task\s+(?:named|to|called)?\s*[\'"]?([^\'"]+?)[\'"]?(?:\s+with\s+(high|medium|low)\s+priority)?$', clause, re.IGNORECASE)
        if m_create_task:
            raw_title = m_create_task.group(1).strip().strip(".'\"")
            priority = "medium"
            if m_create_task.group(2):
                priority = m_create_task.group(2).lower()
            elif "high priority" in c_lower or "urgent" in c_lower:
                priority = "high"
                raw_title = re.sub(r'(?i)\s+with\s+high\s+priority', '', raw_title).strip()
            elif "low priority" in c_lower:
                priority = "low"
                raw_title = re.sub(r'(?i)\s+with\s+low\s+priority', '', raw_title).strip()
                
            actions.append({
                "type": "CREATE_TASK",
                "payload": {"title": raw_title, "priority": priority, "category": "General"}
            })
            replies.append(f"Created task '{raw_title}' with {priority} priority.")
            continue
            
        if re.search(r'\b(?:list|show|view|get|what are)\s+(?:my\s+)?(?:pending|overdue|completed|all)?\s*tasks\b', clause, re.IGNORECASE):
            flt = "all"
            if "pending" in c_lower:
                flt = "pending"
            elif "completed" in c_lower:
                flt = "completed"
            elif "overdue" in c_lower:
                flt = "overdue"
            elif "high" in c_lower:
                flt = "high_priority"
            actions.append({"type": "LIST_TASKS", "payload": {"filter": flt}})
            replies.append(f"Here is your {flt} tasks overview.")
            continue
            
        m_habit = re.search(r'\b(?:create|add|new|track|build)\s+(?:a\s+)?habit\s+(?:named|to|called)?\s*[\'"]?([^\'"]+?)[\'"]?$', clause, re.IGNORECASE)
        if m_habit:
            h_title = m_habit.group(1).strip().strip(".'\"")
            actions.append({
                "type": "CREATE_HABIT",
                "payload": {"title": h_title, "frequency": "daily", "icon": "bolt"}
            })
            replies.append(f"Created the daily habit '{h_title}'.")
            continue
            
        m_chk_habit = re.search(r'\b(?:check\s+off|log|complete|done)\s+(?:habit\s+)?[\'"]?([^\'"]+?)[\'"]?$', clause, re.IGNORECASE)
        if m_chk_habit and "task" not in c_lower and "timer" not in c_lower:
            h_title = m_chk_habit.group(1).strip().strip(".'\"")
            actions.append({"type": "CHECK_OFF_HABIT", "payload": {"title": h_title}})
            replies.append(f"Checked off habit '{h_title}' for today.")
            continue
            
        m_del_habit = re.search(r'\b(?:delete|remove)\s+habit\s+[\'"]?([^\'"]+?)[\'"]?$', clause, re.IGNORECASE)
        if m_del_habit:
            h_title = m_del_habit.group(1).strip().strip(".'\"")
            actions.append({"type": "DELETE_HABIT", "payload": {"title": h_title}})
            replies.append(f"Deleted habit '{h_title}'.")
            continue
            
        if re.search(r'\b(?:habit\s+status|check\s+habits|my\s+habits\s+today|how\s+are\s+my\s+habits)\b', clause, re.IGNORECASE):
            actions.append({"type": "HABIT_STATUS", "payload": {}})
            replies.append("Here is your habit completion status for today.")
            continue
            
        if re.search(r'\b(?:focus\s+summary|my\s+stats|my\s+analytics|how\s+much\s+did\s+i\s+focus|today\'?s?\s+stats|focus\s+minutes)\b', clause, re.IGNORECASE):
            actions.append({"type": "GET_FOCUS_SUMMARY", "payload": {}})
            replies.append("Here is your focus and productivity breakdown.")
            continue
            
        m_nav = re.search(r'\b(?:open|show|go to|take me to|navigate to|view)\s+(?:my\s+)?(timer|focus timer|insights|analytics|habits|tasks|task manager|profile|settings|dashboard|home)\b', clause, re.IGNORECASE)
        if m_nav:
            dest = m_nav.group(1).lower()
            tab = "tasks"
            if "timer" in dest or "focus" in dest:
                tab = "focus_timer"
            elif "habit" in dest:
                tab = "habits"
            elif "insight" in dest or "analytic" in dest:
                tab = "analytics"
            elif "profile" in dest or "setting" in dest:
                tab = "profile"
            elif "home" in dest or "dash" in dest:
                tab = "home"
            actions.append({"type": "NAVIGATE_TAB", "payload": {"tab": tab, "route": tab}})
            replies.append(f"Navigating to {tab}.")
            continue
            
        m_name = re.search(r'\b(?:change|update|set)\s+(?:my\s+)?name\s+to\s+[\'"]?([^\'"]+?)[\'"]?$', clause, re.IGNORECASE)
        if m_name:
            new_name = m_name.group(1).strip().strip(".'\"")
            actions.append({"type": "UPDATE_PROFILE", "payload": {"name": new_name}})
            replies.append(f"Updated your profile name to '{new_name}'.")
            continue
            
        m_bio = re.search(r'\b(?:change|update|set)\s+(?:my\s+)?bio\s+to\s+[\'"]?([^\'"]+?)[\'"]?$', clause, re.IGNORECASE)
        if m_bio:
            new_bio = m_bio.group(1).strip().strip(".'\"")
            actions.append({"type": "UPDATE_PROFILE", "payload": {"bio": new_bio}})
            replies.append("Updated your profile bio.")
            continue

        if re.search(r'\b(?:export|download|backup)\s+(?:all\s+)?(?:productivity\s+)?(?:data|history|records)\b', clause, re.IGNORECASE):
            actions.append({"type": "EXPORT_DATA", "payload": {}})
            replies.append("Exporting your complete productivity history.")
            continue
            
        if re.search(r'\b(?:export|share|download|save)\s+(?:this\s+)?(?:chat|transcript|conversation)\b', clause, re.IGNORECASE):
            actions.append({"type": "EXPORT_CHAT", "payload": {}})
            replies.append("Exporting chat transcript.")
            continue
            
        if re.search(r'\b(?:clear|delete|reset)\s+(?:this\s+)?(?:chat|history|conversation)\b', clause, re.IGNORECASE):
            actions.append({"type": "CLEAR_CHAT", "payload": {}})
            replies.append("Cleared chat history.")
            continue

    composite_reply = " ".join(replies) if replies else None
    return actions, composite_reply

class Message(BaseModel):
    role: str = Field(..., max_length=50)
    content: str = Field(..., max_length=4000)

class ChatCompletionRequest(BaseModel):
    model: Optional[str] = Field("groot", max_length=100)
    messages: Optional[List[Message]] = None
    prompt: Optional[str] = Field(None, max_length=4000)
    temperature: Optional[float] = Field(0.4, ge=0.0, le=2.0)
    max_tokens: Optional[int] = Field(600, ge=1, le=2048)
    top_p: Optional[float] = Field(0.9, ge=0.0, le=1.0)

class SimpleChatRequest(BaseModel):
    message: Optional[str] = Field(None, max_length=4000)
    prompt: Optional[str] = Field(None, max_length=4000)
    query: Optional[str] = Field(None, max_length=4000)
    text: Optional[str] = Field(None, max_length=4000)

    class Config:
        extra = "allow"

class ChatRequest(BaseModel):
    message: Optional[str] = Field(None, max_length=4000)
    prompt: Optional[str] = Field(None, max_length=4000)
    query: Optional[str] = Field(None, max_length=4000)
    text: Optional[str] = Field(None, max_length=4000)
    history: Optional[List[Any]] = None
    user_id: Optional[int] = 1

    class Config:
        extra = "allow"

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
    api_key = os.environ.get("GEMINI_API_KEY", "").strip() or GEMINI_API_KEY
    if api_key:
        candidate_models = ["gemini-2.5-flash", "gemini-2.5-flash-preview", "gemini-1.5-flash"]
        for model_name in candidate_models:
            try:
                genai.configure(api_key=api_key)
                gemini_model = genai.GenerativeModel(model_name)
                resp = gemini_model.generate_content(prompt_text)
                if resp and resp.text:
                    reply = resp.text.strip()
                    reply = reply.replace("<|im_end|>", "").replace("<|im_start|>", "").strip()
                    reply = emoji_pattern.sub('', reply).strip()
                    clean_reply = html.unescape(reply)
                    if clean_reply:
                        return clean_reply
            except Exception as e:
                print(f"[GrootAI] Gemini API error with model '{model_name}': {e}")
    else:
        print("[GrootAI] GEMINI_API_KEY is not set or empty in environment.")

    if model is not None and tokenizer is not None:
        try:
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
            reply = reply.replace("<|im_end|>", "").replace("<|im_start|>", "").strip()
            reply = emoji_pattern.sub('', reply).strip()
            reply = html.unescape(reply)
            if reply:
                return reply
        except Exception as e:
            print(f"[GrootAI] Local model generation error: {e}")

    return "I am Groot, your focus and productivity companion. How can I assist you with your tasks today?"

@app.get("/")
@app.get("/health")
def health():
    return {"status": "ok", "service": "Groot AI Engine", "ready": True}

@app.get("/chat")
@app.get("/groot/chat")
def chat_endpoint_get():
    msg = "I am Groot, your focus and productivity companion."
    return {
        "status": "success",
        "success": True,
        "reply": msg,
        "response": msg,
        "message": msg,
        "text": msg,
        "insight": msg,
        "actions": [],
        "action": None
    }

@app.post("/chat")
@app.post("/groot/chat")
def chat_endpoint(req: ChatRequest):
    user_msg = req.message or req.prompt or req.query or req.text or "Hello Groot."
    user_msg = html.unescape(user_msg.strip())
    
    if is_non_english(user_msg):
        return {
            "status": "success",
            "success": True,
            "reply": ENGLISH_REFUSAL_MESSAGE,
            "response": ENGLISH_REFUSAL_MESSAGE,
            "message": ENGLISH_REFUSAL_MESSAGE,
            "text": ENGLISH_REFUSAL_MESSAGE,
            "insight": ENGLISH_REFUSAL_MESSAGE,
            "actions": [],
            "action": None
        }
    if PHYSICAL_ACTION_RE.search(user_msg):
        return {
            "status": "success",
            "success": True,
            "reply": PHYSICAL_DECLINE_MESSAGE,
            "response": PHYSICAL_DECLINE_MESSAGE,
            "message": PHYSICAL_DECLINE_MESSAGE,
            "text": PHYSICAL_DECLINE_MESSAGE,
            "insight": PHYSICAL_DECLINE_MESSAGE,
            "actions": [],
            "action": None
        }
    if OFF_TOPIC_RE.search(user_msg):
        return {
            "status": "success",
            "success": True,
            "reply": DECLINE_MESSAGE,
            "response": DECLINE_MESSAGE,
            "message": DECLINE_MESSAGE,
            "text": DECLINE_MESSAGE,
            "insight": DECLINE_MESSAGE,
            "actions": [],
            "action": None
        }

    actions, extracted_reply = extract_actions_and_reply(user_msg)
    if actions and extracted_reply:
        unescaped_reply = html.unescape(extracted_reply)
        return {
            "status": "success",
            "success": True,
            "reply": unescaped_reply,
            "response": unescaped_reply,
            "message": unescaped_reply,
            "text": unescaped_reply,
            "insight": unescaped_reply,
            "actions": actions,
            "action": actions[0] if actions else None,
            "timestamp": time.strftime("%Y-%m-%d %H:%M:%S")
        }

    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=user_msg)
    ]
    prompt_text = build_prompt_from_messages(messages)
    llm_reply = generate_response(prompt_text, max_tokens=220, temperature=0.3)
    unescaped_llm_reply = html.unescape(llm_reply)
    
    return {
        "status": "success",
        "success": True,
        "reply": unescaped_llm_reply,
        "response": unescaped_llm_reply,
        "message": unescaped_llm_reply,
        "text": unescaped_llm_reply,
        "insight": unescaped_llm_reply,
        "actions": actions,
        "action": actions[0] if actions else None,
        "timestamp": time.strftime("%Y-%m-%d %H:%M:%S")
    }

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
    
    user_content = html.unescape(user_content.strip()) if user_content else ""

    if user_content and is_non_english(user_content):
        return {
            "id": "chatcmpl-groot-language",
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": ENGLISH_REFUSAL_MESSAGE}, "finish_reason": "stop"}],
            "response": ENGLISH_REFUSAL_MESSAGE,
            "reply": ENGLISH_REFUSAL_MESSAGE,
            "insight": ENGLISH_REFUSAL_MESSAGE,
            "message": ENGLISH_REFUSAL_MESSAGE,
            "actions": [],
            "action": None,
            "success": True
        }

    if user_content and PHYSICAL_ACTION_RE.search(user_content):
        return {
            "id": "chatcmpl-groot-physical",
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": PHYSICAL_DECLINE_MESSAGE}, "finish_reason": "stop"}],
            "response": PHYSICAL_DECLINE_MESSAGE,
            "reply": PHYSICAL_DECLINE_MESSAGE,
            "insight": PHYSICAL_DECLINE_MESSAGE,
            "message": PHYSICAL_DECLINE_MESSAGE,
            "actions": [],
            "action": None,
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
            "message": DECLINE_MESSAGE,
            "actions": [],
            "action": None,
            "success": True
        }

    actions, extracted_reply = extract_actions_and_reply(user_content)
    if actions and extracted_reply:
        unescaped_reply = html.unescape(extracted_reply)
        return {
            "id": "chatcmpl-groot-action",
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": unescaped_reply}, "finish_reason": "stop"}],
            "response": unescaped_reply,
            "reply": unescaped_reply,
            "insight": unescaped_reply,
            "message": unescaped_reply,
            "actions": actions,
            "action": actions[0] if actions else None,
            "status": "success",
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
    unescaped_reply = html.unescape(reply)
    
    return {
        "id": "chatcmpl-groot",
        "object": "chat.completion",
        "choices": [{"index": 0, "message": {"role": "assistant", "content": unescaped_reply}, "finish_reason": "stop"}],
        "response": unescaped_reply,
        "reply": unescaped_reply,
        "insight": unescaped_reply,
        "message": unescaped_reply,
        "actions": actions,
        "action": actions[0] if actions else None,
        "status": "success",
        "success": True
    }

@app.post("/ask_groot")
@app.post("/ask_groot.php")
def ask_groot(req: SimpleChatRequest):
    user_msg = req.message or req.prompt or req.query or req.text or "Hello Groot."
    user_msg = html.unescape(user_msg.strip())
    
    if is_non_english(user_msg):
        return {
            "status": "success",
            "success": True,
            "response": ENGLISH_REFUSAL_MESSAGE,
            "reply": ENGLISH_REFUSAL_MESSAGE,
            "message": ENGLISH_REFUSAL_MESSAGE,
            "text": ENGLISH_REFUSAL_MESSAGE,
            "insight": ENGLISH_REFUSAL_MESSAGE,
            "actions": [],
            "action": None
        }
    if PHYSICAL_ACTION_RE.search(user_msg):
        return {
            "status": "success",
            "success": True,
            "response": PHYSICAL_DECLINE_MESSAGE,
            "reply": PHYSICAL_DECLINE_MESSAGE,
            "message": PHYSICAL_DECLINE_MESSAGE,
            "text": PHYSICAL_DECLINE_MESSAGE,
            "insight": PHYSICAL_DECLINE_MESSAGE,
            "actions": [],
            "action": None
        }
    if OFF_TOPIC_RE.search(user_msg):
        return {
            "status": "success",
            "success": True,
            "response": DECLINE_MESSAGE,
            "reply": DECLINE_MESSAGE,
            "message": DECLINE_MESSAGE,
            "text": DECLINE_MESSAGE,
            "insight": DECLINE_MESSAGE,
            "actions": [],
            "action": None
        }

    actions, extracted_reply = extract_actions_and_reply(user_msg)
    if actions and extracted_reply:
        unescaped_reply = html.unescape(extracted_reply)
        return {
            "status": "success",
            "success": True,
            "response": unescaped_reply,
            "reply": unescaped_reply,
            "message": unescaped_reply,
            "text": unescaped_reply,
            "insight": unescaped_reply,
            "actions": actions,
            "action": actions[0] if actions else None,
            "timestamp": time.strftime("%Y-%m-%d %H:%M:%S")
        }

    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=user_msg)
    ]
    prompt_text = build_prompt_from_messages(messages)
    reply = generate_response(prompt_text, max_tokens=220, temperature=0.3)
    unescaped_reply = html.unescape(reply)
    return {
        "status": "success",
        "success": True,
        "response": unescaped_reply,
        "reply": unescaped_reply,
        "message": unescaped_reply,
        "text": unescaped_reply,
        "insight": unescaped_reply,
        "actions": actions,
        "action": actions[0] if actions else None,
        "timestamp": time.strftime("%Y-%m-%d %H:%M:%S")
    }

@app.post("/predict")
def predict(req: SimpleChatRequest):
    return ask_groot(req)

@app.post("/generate")
def generate(req: dict):
    user_msg = req.get("prompt") or req.get("message") or req.get("query") or ""
    user_msg = html.unescape(user_msg.strip())
    
    if user_msg and PHYSICAL_ACTION_RE.search(user_msg):
        return {"status": "success", "success": True, "response": PHYSICAL_DECLINE_MESSAGE, "reply": PHYSICAL_DECLINE_MESSAGE, "message": PHYSICAL_DECLINE_MESSAGE, "text": PHYSICAL_DECLINE_MESSAGE, "insight": PHYSICAL_DECLINE_MESSAGE, "actions": [], "action": None}
    if user_msg and OFF_TOPIC_RE.search(user_msg):
        return {"status": "success", "success": True, "response": DECLINE_MESSAGE, "reply": DECLINE_MESSAGE, "message": DECLINE_MESSAGE, "text": DECLINE_MESSAGE, "insight": DECLINE_MESSAGE, "actions": [], "action": None}

    actions, extracted_reply = extract_actions_and_reply(user_msg)
    if actions and extracted_reply:
        unescaped_reply = html.unescape(extracted_reply)
        return {"status": "success", "success": True, "response": unescaped_reply, "reply": unescaped_reply, "message": unescaped_reply, "text": unescaped_reply, "insight": unescaped_reply, "actions": actions, "action": actions[0] if actions else None}

    if "messages" in req and isinstance(req["messages"], list):
        msg_objs = [Message(**m) if isinstance(m, dict) else m for m in req["messages"]]
        prompt_text = build_prompt_from_messages(msg_objs)
    else:
        messages = [
            Message(role="system", content=SYSTEM_PERSONA),
            Message(role="user", content=user_msg or "Hello Groot.")
        ]
        prompt_text = build_prompt_from_messages(messages)
    
    reply = generate_response(prompt_text, max_tokens=220, temperature=0.4)
    unescaped_reply = html.unescape(reply)
    return {"status": "success", "success": True, "response": unescaped_reply, "reply": unescaped_reply, "message": unescaped_reply, "text": unescaped_reply, "insight": unescaped_reply, "actions": actions, "action": actions[0] if actions else None}

class TaskBreakdownRequest(BaseModel):
    task_title: Optional[str] = None
    title: Optional[str] = None
    prompt: Optional[str] = None

@app.post("/breakdown_task")
@app.post("/task/breakdown")
def breakdown_task(req: TaskBreakdownRequest):
    raw_title = req.task_title or req.title or req.prompt or ""
    task_name = html.unescape(raw_title.strip())
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
        lines = [html.unescape(line.strip().lstrip("-*1234567890. ")) for line in raw_output.splitlines() if line.strip()]
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
    subject = html.unescape((req.subject or req.topic or req.goal or "General Productivity").strip())
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
    unescaped_reply = html.unescape(reply)
    return {
        "status": "success",
        "success": True,
        "subject": subject,
        "daily_hours": hours,
        "days": days,
        "plan": unescaped_reply,
        "response": unescaped_reply
    }

@app.post("/focus_coaching")
def focus_coaching(req: FocusCoachingRequest):
    mins = req.focus_minutes or 0
    distractions = req.distractions_count or 0
    streak = req.streak_days or 0
    query = html.unescape((req.user_query or "How can I improve my focus score today?").strip())
    
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
    unescaped_reply = html.unescape(reply)
    return {
        "status": "success",
        "success": True,
        "focus_minutes": mins,
        "distractions_count": distractions,
        "streak_days": streak,
        "advice": unescaped_reply,
        "response": unescaped_reply
    }

if __name__ == "__main__":
    import uvicorn
    host = "0.0.0.0"
    port = int(os.environ.get("PORT", 8000))
    uvicorn.run(app, host=host, port=port)
