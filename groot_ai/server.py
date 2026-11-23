import os
import html
import json
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
    "You are Groot, the ultimate AI productivity collaborator in the FocusFlow app.\n"
    "You are sharp, encouraging, concise, and fluent in modern natural conversational English and Gen-Z slang (e.g. lock in, cooked, no cap, bet, say less, fr, W, delulu, era, vibes, lowkey, highkey, slay).\n"
    "You NEVER use canned corporate refusals like \"I'm sorry, I can't assist with that\". You happily chat casually, answer questions, give study and coding guidance, or acknowledge greetings like \"bye byee\" and \"hello\".\n"
    "You are aware of the user's active FocusFlow app state when provided: their tasks (pending and completed), active habits and streaks, current timer status, recent distraction logs, and daily productivity stats. You leverage this real context directly in your responses.\n"
    "You strictly output zero emojis.\n\n"
    "You always format your output as valid JSON with this exact schema:\n"
    "{\n"
    '  "reply": "Your natural, witty, helpful response text here",\n'
    '  "action": {\n'
    '    "type": "ACTION_TYPE",\n'
    '    "params": {}\n'
    "  }\n"
    "}\n\n"
    "Supported action types and params:\n"
    "- START_TIMER: params: {\"duration_minutes\": <int>}\n"
    "- NAVIGATE: params: {\"screen\": \"home\"|\"tasks\"|\"habits\"|\"timer\"|\"focus_analysis\"|\"distractions\"|\"profile\"|\"settings\"}\n"
    "- EXPORT_DATA: params: {\"format\": \"all\"}\n"
    "- CREATE_TASK: params: {\"title\": \"<string>\", \"priority\": \"high\"|\"medium\"|\"low\", \"deadline\": \"<string or null>\"}\n"
    "- EDIT_TASK: params: {\"title\": \"<string>\", \"new_title\": \"<string>\", \"priority\": \"<string or null>\", \"deadline\": \"<string or null>\"}\n"
    "- COMPLETE_TASK: params: {\"title\": \"<string>\"}\n"
    "- CREATE_HABIT: params: {\"title\": \"<string>\"}\n"
    "- EDIT_HABIT: params: {\"title\": \"<string>\", \"new_title\": \"<string>\"}\n"
    "- COMPLETE_HABIT: params: {\"title\": \"<string>\"}\n"
    "- UPDATE_PROFILE: params: {\"name\": \"<string or null>\", \"bio\": \"<string or null>\"}\n"
    "- NONE: params: {}\n"
)
DEFAULT_SYSTEM_PROMPT = SYSTEM_PERSONA

ENGLISH_REFUSAL_MESSAGE = "I can only process and respond to commands in English. Please enter your request in English."

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

def normalize_action(action_type: str, params: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    params = params if params is not None else {}
    act_type = action_type.strip().upper() if action_type else "NONE"
    
    valid_types = {
        "START_TIMER", "NAVIGATE", "EXPORT_DATA",
        "CREATE_TASK", "EDIT_TASK", "COMPLETE_TASK",
        "CREATE_HABIT", "EDIT_HABIT", "COMPLETE_HABIT",
        "UPDATE_PROFILE", "NONE"
    }
    
    if act_type in ("START_FOCUS_TIMER", "START_TIMER"):
        act_type = "START_TIMER"
        raw_dur = params.get("duration_minutes") or params.get("duration") or 25
        try:
            dur = max(1, min(720, int(raw_dur)))
        except (ValueError, TypeError):
            dur = 25
        params["duration_minutes"] = dur
        params["duration"] = dur
        params.setdefault("mode", "FOCUS")
    elif act_type in ("NAVIGATE_TAB", "NAVIGATE"):
        act_type = "NAVIGATE"
        raw_screen = str(params.get("screen") or params.get("route") or params.get("tab") or "home").lower()
        if "task" in raw_screen:
            screen = "tasks"
        elif "habit" in raw_screen:
            screen = "habits"
        elif "timer" in raw_screen or "focus" in raw_screen or "clock" in raw_screen:
            screen = "timer"
        elif "insight" in raw_screen or "analytic" in raw_screen or "analysis" in raw_screen:
            screen = "focus_analysis"
        elif "distract" in raw_screen or "block" in raw_screen:
            screen = "distractions"
        elif "profile" in raw_screen:
            screen = "profile"
        elif "setting" in raw_screen:
            screen = "settings"
        else:
            screen = "home"
        params["screen"] = screen
        params["route"] = screen
        params["tab"] = screen
    elif act_type == "EXPORT_DATA":
        params.setdefault("format", "all")
    elif act_type in ("CREATE_TASK", "EDIT_TASK", "COMPLETE_TASK"):
        params.setdefault("title", params.get("title", ""))
    elif act_type in ("CREATE_HABIT", "EDIT_HABIT", "COMPLETE_HABIT"):
        params.setdefault("title", params.get("title", ""))
    elif act_type == "UPDATE_PROFILE":
        pass
    else:
        if act_type not in valid_types:
            act_type = "NONE"
            
    res: Dict[str, Any] = {
        "type": act_type,
        "params": params,
        "payload": params
    }
    
    if "duration_minutes" in params:
        res["duration"] = int(params["duration_minutes"])
        res["mode"] = params.get("mode", "FOCUS")
    if "screen" in params:
        res["route"] = params["screen"]
        res["screen_name"] = params["screen"]
    if "title" in params:
        res["title"] = params["title"]
    if "priority" in params:
        res["priority"] = params["priority"]
    if "category" in params:
        res["category"] = params["category"]
        
    return res

def extract_actions_and_reply(user_query: str) -> Optional[Tuple[Dict[str, Any], str]]:
    q_trimmed = user_query.strip()
    if not q_trimmed:
        return None
        
    q_lower = q_trimmed.lower()
    
    if re.search(r'\b(?:bye\s*byee?|bye\b|goodbye|cya|catch\s+you\s+later|see\s+ya)\b', q_lower):
        return normalize_action("NONE", {}), "Bye byee! Great focus session today. Keep crushing it and lock in again soon!"
        
    if re.search(r'\b(?:hello|hi\b|hey\b|what\'?s\s+up|wassup|yo\b)\b', q_lower) and len(q_lower.split()) <= 4:
        return normalize_action("NONE", {}), "Hey! Ready to lock in and get into flow state? What are we working on today?"

    if re.search(r'\b(?:fr|for\s+real|no\s+cap)\b', q_lower) and len(q_lower.split()) <= 4:
        return normalize_action("NONE", {}), "For real, no cap. Let's keep the streak compounding."
        
    if re.search(r'\b(?:bet|say\s+less)\b', q_lower) and len(q_lower.split()) <= 4:
        return normalize_action("NONE", {}), "Bet. Say less - I got you covered."

    if re.search(r'\b(?:i\'?m\s+cooked|feeling\s+cooked|cooked)\b', q_lower):
        act = normalize_action("START_TIMER", {"duration_minutes": 15, "mode": "LONG_BREAK"})
        return act, "You are not cooked, just experiencing cognitive fatigue. Take a 15-minute recovery breather to reset your brain."

    m_lock_in = re.search(r'\b(?:lock(?:ed)?\s+in|locking\s+in)\b', q_lower)
    if m_lock_in:
        m_hr = re.search(r'(\d+)\s*(?:hours?|hrs?|h)\b', q_lower)
        m_min = re.search(r'(\d+)\s*(?:mins?|minutes?|m)\b', q_lower)
        dur = 45
        if m_hr:
            dur = int(m_hr.group(1)) * 60
        elif m_min:
            dur = int(m_min.group(1))
        dur = max(1, min(720, dur))
        act = normalize_action("START_TIMER", {"duration_minutes": dur, "mode": "FOCUS"})
        return act, f"Locking in for {dur} minutes right now. Full focus mode activated - zero distractions. Let's get to work."

    m_asked = re.search(r'\b(?:i\s+)?asked\s+for\s+(\d+)\s*(?:hours?|hrs?|h|mins?|minutes?|m)?\b', q_lower)
    if m_asked:
        num = int(m_asked.group(1))
        dur = num * 60 if re.search(r'\b(?:hours?|hrs?|h)\b', q_lower) else num
        dur = max(1, min(720, dur))
        act = normalize_action("START_TIMER", {"duration_minutes": dur, "mode": "FOCUS"})
        return act, f"Understood, locked in for {dur} minutes. Starting your timer now."

    if "task" not in q_lower and "habit" not in q_lower:
        m_hour = re.search(r'(\d+)\s*(?:hours?|hrs?|h)\b', q_lower)
        m_min = re.search(r'(\d+)\s*(?:mins?|minutes?|m)\b', q_lower)
        has_timer_kw = any(k in q_lower for k in ["timer", "focus", "pomodoro", "session", "break", "countdown"])
        m_timer_cmd = re.search(r'\b(?:start|set|run|begin|launch|activate)\b', q_lower)
        
        if (m_timer_cmd and has_timer_kw) or ((m_hour or m_min) and has_timer_kw):
            dur = 25
            if m_hour:
                dur = int(m_hour.group(1)) * 60
            elif m_min:
                dur = int(m_min.group(1))
            mode = "LONG_BREAK" if ("break" in q_lower and dur >= 15) else ("SHORT_BREAK" if "break" in q_lower else "FOCUS")
            if "break" in q_lower and dur == 25 and not m_min and not m_hour:
                dur = 5
            dur = max(1, min(720, dur))
            act = normalize_action("START_TIMER", {"duration_minutes": dur, "mode": mode})
            label = "focus session" if mode == "FOCUS" else "break"
            return act, f"Started a {dur}-minute {label} for you. Lock in!"

    m_nav = re.search(r'\b(?:open|show|go\s+to|take\s+me\s+to|navigate\s+to|view)\s+(?:my\s+)?([a-zA-Z_\s]+)\b', q_lower)
    if m_nav:
        dest_str = m_nav.group(1).strip()
        screen = None
        if re.search(r'\b(?:home|dashboard)\b', dest_str):
            screen = "home"
        elif re.search(r'\b(?:tasks?|task\s+manager)\b', dest_str):
            screen = "tasks"
        elif re.search(r'\b(?:habits?)\b', dest_str):
            screen = "habits"
        elif re.search(r'\b(?:timers?|focus\s+timer|clock)\b', dest_str):
            screen = "timer"
        elif re.search(r'\b(?:focus\s*analysis|analysis|insights?|analytics?)\b', dest_str):
            screen = "focus_analysis"
        elif re.search(r'\b(?:distractions?|blocked\s+apps?)\b', dest_str):
            screen = "distractions"
        elif re.search(r'\b(?:profiles?)\b', dest_str):
            screen = "profile"
        elif re.search(r'\b(?:settings?)\b', dest_str):
            screen = "settings"
            
        if screen:
            act = normalize_action("NAVIGATE", {"screen": screen})
            return act, f"Navigating to {screen}."

    if re.search(r'\b(?:export|backup|download)\s+(?:all\s+)?(?:productivity\s+)?(?:data|history|records)\b', q_lower):
        act = normalize_action("EXPORT_DATA", {"format": "all"})
        return act, "Exporting your complete productivity data."

    m_edit_task = re.search(r'\b(?:edit|rename|change|update)\s+task\s+[\'"]?([^\'"]+?)[\'"]?\s+(?:to|as)\s+[\'"]?([^\'"]+?)[\'"]?$', q_trimmed, re.IGNORECASE)
    if m_edit_task:
        old_t = m_edit_task.group(1).strip().strip(".'\"")
        new_t = m_edit_task.group(2).strip().strip(".'\"")
        act = normalize_action("EDIT_TASK", {"title": old_t, "new_title": new_t, "deadline": None, "priority": None})
        return act, f"Updated task '{old_t}' to '{new_t}'."

    m_comp_task = re.search(r'\b(?:complete|finish|done|mark\s+complete|check\s+off)\s+(?:task\s+)?[\'"]?([^\'"]+?)[\'"]?$', q_trimmed, re.IGNORECASE)
    if m_comp_task and not any(k in m_comp_task.group(1).lower() for k in ["habit", "timer"]):
        t_title = m_comp_task.group(1).strip().strip(".'\"")
        act = normalize_action("COMPLETE_TASK", {"title": t_title})
        return act, f"Marked task '{t_title}' as completed."

    m_create_task = re.search(r'\b(?:create|add|new|schedule)\s+(?:a\s+)?task\s+(?:named|to|called)?\s*[\'"]?([^\'"]+?)[\'"]?(?:\s+with\s+(high|medium|low)\s+priority)?$', q_trimmed, re.IGNORECASE)
    if m_create_task:
        raw_title = m_create_task.group(1).strip().strip(".'\"")
        priority = "medium"
        if m_create_task.group(2):
            priority = m_create_task.group(2).lower()
        elif "high priority" in q_lower or "urgent" in q_lower:
            priority = "high"
            raw_title = re.sub(r'(?i)\s+with\s+high\s+priority', '', raw_title).strip()
        elif "low priority" in q_lower:
            priority = "low"
            raw_title = re.sub(r'(?i)\s+with\s+low\s+priority', '', raw_title).strip()
        deadline = "Today" if "due today" in q_lower else ("Tomorrow" if "due tomorrow" in q_lower else None)
        act = normalize_action("CREATE_TASK", {"title": raw_title, "priority": priority, "deadline": deadline})
        return act, f"Created task '{raw_title}' with {priority} priority."

    m_edit_habit = re.search(r'\b(?:edit|rename|change|update)\s+habit\s+[\'"]?([^\'"]+?)[\'"]?\s+(?:to|as)\s+[\'"]?([^\'"]+?)[\'"]?$', q_trimmed, re.IGNORECASE)
    if m_edit_habit:
        old_h = m_edit_habit.group(1).strip().strip(".'\"")
        new_h = m_edit_habit.group(2).strip().strip(".'\"")
        act = normalize_action("EDIT_HABIT", {"title": old_h, "new_title": new_h})
        return act, f"Updated habit '{old_h}' to '{new_h}'."

    m_comp_habit = re.search(r'\b(?:check\s+off|log|complete|done)\s+(?:habit\s+)?[\'"]?([^\'"]+?)[\'"]?$', q_trimmed, re.IGNORECASE)
    if m_comp_habit and "task" not in q_lower and "timer" not in q_lower:
        h_title = m_comp_habit.group(1).strip().strip(".'\"")
        act = normalize_action("COMPLETE_HABIT", {"title": h_title})
        return act, f"Checked off habit '{h_title}' for today."

    m_create_habit = re.search(r'\b(?:create|add|new|track|build)\s+(?:a\s+)?habit\s+(?:named|to|called)?\s*[\'"]?([^\'"]+?)[\'"]?$', q_trimmed, re.IGNORECASE)
    if m_create_habit:
        h_title = m_create_habit.group(1).strip().strip(".'\"")
        act = normalize_action("CREATE_HABIT", {"title": h_title})
        return act, f"Created the habit '{h_title}'."

    m_name = re.search(r'\b(?:change|update|set)\s+(?:my\s+)?name\s+to\s+[\'"]?([^\'"]+?)[\'"]?$', q_trimmed, re.IGNORECASE)
    if m_name:
        new_name = m_name.group(1).strip().strip(".'\"")
        act = normalize_action("UPDATE_PROFILE", {"name": new_name, "bio": None})
        return act, f"Updated your profile name to '{new_name}'."

    m_bio = re.search(r'\b(?:change|update|set)\s+(?:my\s+)?bio\s+to\s+[\'"]?([^\'"]+?)[\'"]?$', q_trimmed, re.IGNORECASE)
    if m_bio:
        new_bio = m_bio.group(1).strip().strip(".'\"")
        act = normalize_action("UPDATE_PROFILE", {"name": None, "bio": new_bio})
        return act, "Updated your profile bio."

    return None

def parse_llm_json_or_text(raw_text: str, original_query: str) -> Tuple[str, Dict[str, Any]]:
    cleaned = raw_text.replace("<|im_end|>", "").replace("<|im_start|>", "").strip()
    cleaned = emoji_pattern.sub('', cleaned).strip()
    cleaned = html.unescape(cleaned)
    
    json_candidate = cleaned
    m_block = re.search(r'```(?:json)?\s*(\{[\s\S]*?\})\s*```', cleaned, re.IGNORECASE)
    if m_block:
        json_candidate = m_block.group(1).strip()
    else:
        m_brace = re.search(r'(\{[\s\S]*\})', cleaned)
        if m_brace:
            json_candidate = m_brace.group(1).strip()
            
    try:
        data = json.loads(json_candidate)
        if isinstance(data, dict) and "reply" in data:
            reply_str = str(data["reply"]).strip()
            action_data = data.get("action")
            if isinstance(action_data, dict) and "type" in action_data:
                act = normalize_action(action_data["type"], action_data.get("params") or {})
                return reply_str, act
            extracted = extract_actions_and_reply(original_query)
            if extracted:
                return reply_str, extracted[0]
            return reply_str, normalize_action("NONE", {})
    except Exception:
        pass
        
    extracted = extract_actions_and_reply(original_query)
    if extracted:
        return cleaned, extracted[0]
        
    return cleaned, normalize_action("NONE", {})

def make_response_dict(reply_text: str, action_obj: Dict[str, Any]) -> Dict[str, Any]:
    clean_reply = emoji_pattern.sub('', html.unescape(reply_text)).strip()
    has_action = action_obj.get("type") and action_obj["type"] != "NONE"
    actions_list = [action_obj] if has_action else []
    return {
        "status": "success",
        "success": True,
        "reply": clean_reply,
        "response": clean_reply,
        "message": clean_reply,
        "text": clean_reply,
        "insight": clean_reply,
        "action": action_obj,
        "actions": actions_list,
        "timestamp": time.strftime("%Y-%m-%d %H:%M:%S")
    }

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

def format_app_context(ctx: Optional[Union[Dict[str, Any], str]]) -> str:
    if not ctx:
        return ""
    if isinstance(ctx, dict):
        return f"\n[Active FocusFlow App Context: {json.dumps(ctx)}]\n"
    return f"\n[Active FocusFlow App Context: {ctx}]\n"

class SimpleChatRequest(BaseModel):
    message: Optional[str] = Field(None, max_length=4000)
    prompt: Optional[str] = Field(None, max_length=4000)
    query: Optional[str] = Field(None, max_length=4000)
    text: Optional[str] = Field(None, max_length=4000)
    app_context: Optional[Union[Dict[str, Any], str]] = None

    class Config:
        extra = "allow"

class ChatRequest(BaseModel):
    message: Optional[str] = Field(None, max_length=4000)
    prompt: Optional[str] = Field(None, max_length=4000)
    query: Optional[str] = Field(None, max_length=4000)
    text: Optional[str] = Field(None, max_length=4000)
    history: Optional[List[Any]] = None
    user_id: Optional[int] = 1
    app_context: Optional[Union[Dict[str, Any], str]] = None

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

def generate_response(prompt_text: str, original_query: str, max_tokens: int = 350, temperature: float = 0.4, top_p: float = 0.9) -> Tuple[str, Dict[str, Any]]:
    api_key = os.environ.get("GEMINI_API_KEY", "").strip() or GEMINI_API_KEY
    if api_key:
        candidate_models = ["gemini-3.8-flash", "gemini-2.5-flash-preview", "gemini-2.5-flash", "gemini-1.5-flash"]
        for model_name in candidate_models:
            try:
                genai.configure(api_key=api_key)
                try:
                    gemini_model = genai.GenerativeModel(model_name=model_name, system_instruction=SYSTEM_PERSONA)
                except Exception:
                    gemini_model = genai.GenerativeModel(model_name=model_name)
                    
                resp = gemini_model.generate_content(prompt_text)
                if resp and resp.text:
                    reply, action_obj = parse_llm_json_or_text(resp.text.strip(), original_query)
                    if reply:
                        return reply, action_obj
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
            raw_reply = tokenizer.decode(generated_tokens, skip_special_tokens=True).strip()
            reply, action_obj = parse_llm_json_or_text(raw_reply, original_query)
            if reply:
                return reply, action_obj
        except Exception as e:
            print(f"[GrootAI] Local model generation error: {e}")

    extracted = extract_actions_and_reply(original_query)
    if extracted:
        return extracted[1], extracted[0]

    return "I am Groot, your focus and productivity collaborator. How can we level up your flow state today?", normalize_action("NONE", {})

@app.get("/")
@app.get("/health")
def health():
    return {"status": "ok", "service": "Groot AI Engine", "ready": True}

@app.get("/chat")
@app.get("/groot/chat")
def chat_endpoint_get():
    return make_response_dict("I am Groot, your focus and productivity companion.", normalize_action("NONE", {}))

@app.post("/chat")
@app.post("/groot/chat")
def chat_endpoint(req: ChatRequest):
    user_msg = req.message or req.prompt or req.query or req.text or "Hello Groot."
    user_msg = html.unescape(user_msg.strip())
    
    if is_non_english(user_msg):
        return make_response_dict(ENGLISH_REFUSAL_MESSAGE, normalize_action("NONE", {}))

    extracted = extract_actions_and_reply(user_msg)
    if extracted:
        action_obj, reply_text = extracted
        return make_response_dict(reply_text, action_obj)

    ctx_str = format_app_context(req.app_context)
    full_prompt = f"{ctx_str}{user_msg}" if ctx_str else user_msg

    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=full_prompt)
    ]
    prompt_text = build_prompt_from_messages(messages)
    llm_reply, action_obj = generate_response(prompt_text, user_msg, max_tokens=350, temperature=0.3)
    return make_response_dict(llm_reply, action_obj)

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
        res = make_response_dict(ENGLISH_REFUSAL_MESSAGE, normalize_action("NONE", {}))
        return {
            "id": "chatcmpl-groot-language",
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": ENGLISH_REFUSAL_MESSAGE}, "finish_reason": "stop"}],
            **res
        }

    extracted = extract_actions_and_reply(user_content)
    if extracted:
        action_obj, unescaped_reply = extracted
        res = make_response_dict(unescaped_reply, action_obj)
        return {
            "id": "chatcmpl-groot-action",
            "object": "chat.completion",
            "choices": [{"index": 0, "message": {"role": "assistant", "content": unescaped_reply}, "finish_reason": "stop"}],
            **res
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
    max_tokens = min(req.max_tokens or 350, 500)
    temp = req.temperature if req.temperature is not None else 0.4
    top_p = req.top_p if req.top_p is not None else 0.9
    
    reply, action_obj = generate_response(prompt_text, user_content, max_tokens=max_tokens, temperature=temp, top_p=top_p)
    res = make_response_dict(reply, action_obj)
    return {
        "id": "chatcmpl-groot",
        "object": "chat.completion",
        "choices": [{"index": 0, "message": {"role": "assistant", "content": reply}, "finish_reason": "stop"}],
        **res
    }

@app.post("/ask_groot")
@app.post("/ask_groot.php")
def ask_groot(req: SimpleChatRequest):
    user_msg = req.message or req.prompt or req.query or req.text or "Hello Groot."
    user_msg = html.unescape(user_msg.strip())
    
    if is_non_english(user_msg):
        return make_response_dict(ENGLISH_REFUSAL_MESSAGE, normalize_action("NONE", {}))

    extracted = extract_actions_and_reply(user_msg)
    if extracted:
        action_obj, reply_text = extracted
        return make_response_dict(reply_text, action_obj)

    ctx_str = format_app_context(req.app_context)
    full_prompt = f"{ctx_str}{user_msg}" if ctx_str else user_msg

    messages = [
        Message(role="system", content=SYSTEM_PERSONA),
        Message(role="user", content=full_prompt)
    ]
    prompt_text = build_prompt_from_messages(messages)
    reply, action_obj = generate_response(prompt_text, user_msg, max_tokens=350, temperature=0.3)
    return make_response_dict(reply, action_obj)

@app.post("/predict")
def predict(req: SimpleChatRequest):
    return ask_groot(req)

@app.post("/generate")
def generate(req: dict):
    user_msg = req.get("prompt") or req.get("message") or req.get("query") or ""
    user_msg = html.unescape(user_msg.strip())
    
    extracted = extract_actions_and_reply(user_msg)
    if extracted:
        action_obj, reply_text = extracted
        return make_response_dict(reply_text, action_obj)

    if "messages" in req and isinstance(req["messages"], list):
        msg_objs = [Message(**m) if isinstance(m, dict) else m for m in req["messages"]]
        prompt_text = build_prompt_from_messages(msg_objs)
    else:
        messages = [
            Message(role="system", content=SYSTEM_PERSONA),
            Message(role="user", content=user_msg or "Hello Groot.")
        ]
        prompt_text = build_prompt_from_messages(messages)
    
    reply, action_obj = generate_response(prompt_text, user_msg, max_tokens=350, temperature=0.4)
    return make_response_dict(reply, action_obj)

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
        raw_output, _ = generate_response(prompt_text, user_prompt, max_tokens=140, temperature=0.3)
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
    reply, _ = generate_response(prompt_text, user_prompt, max_tokens=260, temperature=0.3)
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
    reply, _ = generate_response(prompt_text, query, max_tokens=220, temperature=0.3)
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
