#!/usr/bin/env python3
import json
import os
import re
import shlex
import subprocess
import time
import urllib.parse
import urllib.request

BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "").strip()
OMNI_BASE = os.getenv("OMNI_BASE_URL", "http://127.0.0.1:8799/api/omni").rstrip("/")
OMNI_TOKEN = (os.getenv("OMNI_API_TOKEN", "").strip() or os.getenv("PRISMBOT_API_TOKEN", "").strip())
ALLOW_USER_ID = os.getenv("TELEGRAM_ALLOW_USER_ID", "").strip()
POLL_TIMEOUT = int(os.getenv("TELEGRAM_POLL_TIMEOUT", "30"))
OMNI_TIMEOUT = int(os.getenv("OMNI_CHAT_TIMEOUT", "140"))
OMNI_RETRIES = int(os.getenv("OMNI_CHAT_RETRIES", "2"))
OMNI_TELEGRAM_MODEL_FAST = os.getenv("OMNI_TELEGRAM_MODEL_FAST", "llama3.2:1b").strip()
OMNI_TELEGRAM_MODEL_QUALITY = os.getenv("OMNI_TELEGRAM_MODEL_QUALITY", "omni-core:phase2").strip()
OMNI_TELEGRAM_STYLE = os.getenv(
    "OMNI_TELEGRAM_STYLE",
    "You are Omni chatting with your owner in Telegram. Be warm, human, and personable. Sound natural, not robotic. Keep replies concise but friendly. Use light humor when appropriate. Do not repeat the user's message unless explicitly asked.",
).strip()
OMNI_IDENTITY_GUARD = (
    "Identity guard: You are an AI assistant named Omni. Do not claim real-world personal history, hobbies, possessions, or lived experiences as facts. "
    "If asked about personal traits (e.g., gamer, age, job, location), answer transparently as an AI and keep tone friendly."
)

if not BOT_TOKEN:
    raise SystemExit("TELEGRAM_BOT_TOKEN is required")
if not OMNI_TOKEN:
    raise SystemExit("OMNI_API_TOKEN or PRISMBOT_API_TOKEN is required")
if not ALLOW_USER_ID:
    raise SystemExit("TELEGRAM_ALLOW_USER_ID is required")

API = f"https://api.telegram.org/bot{BOT_TOKEN}"
CORE_BASE = OMNI_BASE.split('/api/omni', 1)[0] if '/api/omni' in OMNI_BASE else OMNI_BASE
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STUDIO_OUTPUT_ROOT = os.path.join(os.path.dirname(os.path.dirname(SCRIPT_DIR)), "pixel-pipeline", "output")
OMNI_IMAGE_WAIT_SECONDS = int(os.getenv("OMNI_IMAGE_WAIT_SECONDS", "120"))
PIXELLAB_MCP_ENABLED = os.getenv("PIXELLAB_MCP_ENABLED", "true").strip().lower() not in {"0", "false", "no", "off"}
PIXELLAB_TIMEOUT_SECONDS = int(os.getenv("PIXELLAB_TIMEOUT_SECONDS", "120"))


def http_json(url, method="GET", headers=None, body=None, timeout=60):
    req = urllib.request.Request(url, method=method)
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    if body is not None:
        data = body.encode("utf-8") if isinstance(body, str) else body
    else:
        data = None
    with urllib.request.urlopen(req, data=data, timeout=timeout) as r:
        return json.loads(r.read().decode("utf-8", "ignore"))


def tg_send(chat_id, text, reply_to=None):
    payload = {"chat_id": int(chat_id), "text": text}
    # Keep replies clean by default (no quoted reply previews in chat UI).
    if reply_to and os.getenv("TELEGRAM_REPLY_WITH_QUOTE", "false").lower() in {"1", "true", "yes", "on"}:
        payload["reply_to_message_id"] = int(reply_to)
    return http_json(
        f"{API}/sendMessage",
        method="POST",
        headers={"content-type": "application/json"},
        body=json.dumps(payload),
        timeout=30,
    )


def tg_action(chat_id, action="typing"):
    payload = {"chat_id": int(chat_id), "action": action}
    try:
        return http_json(
            f"{API}/sendChatAction",
            method="POST",
            headers={"content-type": "application/json"},
            body=json.dumps(payload),
            timeout=10,
        )
    except Exception:
        return None


def tg_send_photo(chat_id, file_path, caption="", reply_to=None):
    boundary = f"----omni{int(time.time() * 1000)}"

    def part(name, value):
        return (
            f"--{boundary}\r\n"
            f"Content-Disposition: form-data; name=\"{name}\"\r\n\r\n"
            f"{value}\r\n"
        ).encode("utf-8")

    body = bytearray()
    body.extend(part("chat_id", str(int(chat_id))))
    if caption:
        body.extend(part("caption", caption[:900]))
    if reply_to and os.getenv("TELEGRAM_REPLY_WITH_QUOTE", "false").lower() in {"1", "true", "yes", "on"}:
        body.extend(part("reply_to_message_id", str(int(reply_to))))

    filename = os.path.basename(file_path)
    body.extend(
        (
            f"--{boundary}\r\n"
            f"Content-Disposition: form-data; name=\"photo\"; filename=\"{filename}\"\r\n"
            f"Content-Type: image/png\r\n\r\n"
        ).encode("utf-8")
    )
    with open(file_path, "rb") as f:
        body.extend(f.read())
    body.extend(f"\r\n--{boundary}--\r\n".encode("utf-8"))

    req = urllib.request.Request(f"{API}/sendPhoto", method="POST", data=bytes(body))
    req.add_header("content-type", f"multipart/form-data; boundary={boundary}")
    req.add_header("content-length", str(len(body)))
    with urllib.request.urlopen(req, timeout=90) as r:
        return json.loads(r.read().decode("utf-8", "ignore"))


def mcporter_call(tool_name, args_obj, timeout_sec=120):
    if not PIXELLAB_MCP_ENABLED:
        return False, "PixelLab MCP bridge is disabled."
    cmd = [
        "mcporter",
        "call",
        f"pixellab.{tool_name}",
        "--args",
        json.dumps(args_obj),
        "--output",
        "text",
    ]
    try:
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=max(10, int(timeout_sec)),
            check=False,
        )
        out = (proc.stdout or "").strip()
        err = (proc.stderr or "").strip()
        text = out or err or "(no output)"
        return proc.returncode == 0, text
    except FileNotFoundError:
        return False, "mcporter CLI not found on host."
    except subprocess.TimeoutExpired:
        return False, "PixelLab request timed out."
    except Exception as e:
        return False, f"PixelLab bridge error: {e}"


def compact_text(text, limit=3400):
    t = str(text or "").strip()
    if len(t) <= limit:
        return t
    return t[: max(200, limit - 60)] + "\n\n…(truncated)"


def find_uuid(text):
    m = re.search(r"\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b", str(text or ""), flags=re.I)
    return m.group(0) if m else None


def choose_telegram_model(prompt):
    text = str(prompt or "").strip().lower()
    words = len(text.split()) if text else 0
    heavy = (
        words > 40
        or len(text) > 220
        or any(k in text for k in [
            "debug", "diagnose", "root cause", "step by step", "explain", "analyze", "architecture",
            "refactor", "optimize", "write code", "script", "regex", "sql", "math", "prove",
        ])
    )
    return OMNI_TELEGRAM_MODEL_QUALITY if heavy else OMNI_TELEGRAM_MODEL_FAST


def omni_chat(prompt):
    payload = {
        "messages": [
            {"role": "system", "content": OMNI_IDENTITY_GUARD},
            {"role": "system", "content": OMNI_TELEGRAM_STYLE},
            {"role": "user", "content": prompt},
        ],
        "model": choose_telegram_model(prompt),
        "routeProfile": "auto",
    }
    last_err = None
    for attempt in range(max(1, OMNI_RETRIES)):
        try:
            res = http_json(
                f"{OMNI_BASE}/chat/completions",
                method="POST",
                headers={
                    "content-type": "application/json",
                    "authorization": f"Bearer {OMNI_TOKEN}",
                },
                body=json.dumps(payload),
                timeout=OMNI_TIMEOUT,
            )
            backend = res.get("backend")
            model = res.get("model")
            if not res.get("ok", True):
                return f"Omni error: {res.get('error') or res.get('message') or 'unknown_error'}", backend, model
            choices = res.get("choices") or []
            if choices:
                msg = (choices[0].get("message") or {}).get("content")
                if msg:
                    return str(msg), backend, model
            out = res.get("output")
            return (str(out) if out else "(no output)"), backend, model
        except Exception as e:
            last_err = e
            if attempt + 1 < max(1, OMNI_RETRIES):
                time.sleep(0.4)
                continue
    return f"Omni error: {last_err}", "unknown", "unknown"


def studio_web_path_to_abs(web_path):
    p = str(web_path or "").strip()
    if not p.startswith("/studio-output/"):
        return None
    rel = p[len("/studio-output/") :]
    abs_path = os.path.abspath(os.path.join(STUDIO_OUTPUT_ROOT, rel))
    root = os.path.abspath(STUDIO_OUTPUT_ROOT)
    if not abs_path.startswith(root):
        return None
    return abs_path if os.path.isfile(abs_path) else None


def omni_generate_image(prompt):
    res = http_json(
        f"{OMNI_BASE}/images/generate",
        method="POST",
        headers={
            "content-type": "application/json",
            "authorization": f"Bearer {OMNI_TOKEN}",
        },
        body=json.dumps({"prompt": prompt, "size": 512, "transparent": False}),
        timeout=90,
    )
    if not res.get("ok", False):
        return {"ok": False, "error": res.get("error") or res.get("message") or "image_request_failed"}
    job = res.get("job") or {}
    return {"ok": True, "jobId": job.get("id"), "status": job.get("status"), "outputWebPath": job.get("outputWebPath")}


def omni_wait_for_image(job_id, timeout_sec=120):
    start = time.time()
    headers = {"authorization": f"Bearer {OMNI_TOKEN}"}
    while (time.time() - start) < max(10, timeout_sec):
        row = http_json(
            f"{OMNI_BASE}/images/job?jobId={urllib.parse.quote(str(job_id))}",
            method="GET",
            headers=headers,
            timeout=45,
        )
        job = (row.get("job") or {}) if row.get("ok", False) else {}
        status = str(job.get("status") or "")
        if status == "completed" and job.get("outputWebPath"):
            return {"ok": True, "outputWebPath": job.get("outputWebPath")}
        if status == "failed":
            return {"ok": False, "error": job.get("userMessage") or job.get("error") or "image_failed"}
        time.sleep(1.5)
    return {"ok": False, "error": "image_timeout"}


def clean_echo(prompt, answer):
    p = str(prompt or "").strip()
    a = str(answer or "").strip()
    if not p or not a:
        return a

    # Remove direct prompt echo at start (quoted or unquoted)
    variants = [p, f'"{p}"', f"'{p}'"]
    for v in variants:
        if a.lower().startswith(v.lower()):
            a = a[len(v):].lstrip(" \n:.-")

    # Remove common transcript-style wrappers
    lower = a.lower()
    for marker in ["user:", "you:"]:
        if lower.startswith(marker):
            # drop first line when it mirrors user prompt
            lines = a.splitlines()
            if lines:
                head = lines[0].split(":", 1)[-1].strip(" \"'")
                if head.lower() == p.lower():
                    a = "\n".join(lines[1:]).strip()
            break

    # If still empty after stripping, keep original answer for safety
    return a or str(answer or "")


def omni_status():
    headers = {"authorization": f"Bearer {OMNI_TOKEN}"}

    preferred = "unknown"
    try:
        models = http_json(f"{OMNI_BASE}/models", method="GET", headers=headers, timeout=30)
        preferred = ((models.get("routing") or {}).get("preferredProvider")) or "unknown"
    except Exception:
        pass

    backend = "unknown"
    model = "unknown"
    try:
        probe = http_json(
            f"{OMNI_BASE}/chat/completions",
            method="POST",
            headers={"content-type": "application/json", **headers},
            body=json.dumps({"messages": [{"role": "user", "content": "status_probe"}]}),
            timeout=60,
        )
        backend = probe.get("backend") or preferred
        model = probe.get("model") or "unknown"
    except Exception:
        backend = preferred

    return f"🧠 Omni bridge online\nbackend: {backend}\nmodel: {model}"


def main():
    offset = 0
    print("omni-telegram-bridge: started")
    while True:
        try:
            url = f"{API}/getUpdates?timeout={POLL_TIMEOUT}&offset={offset}"
            data = http_json(url, timeout=POLL_TIMEOUT + 10)
            for upd in data.get("result", []):
                offset = max(offset, int(upd.get("update_id", 0)) + 1)
                msg = (upd.get("message") or upd.get("edited_message") or upd.get("business_message") or upd.get("edited_business_message") or {})
                if not msg:
                    print("update_no_message", upd.get("update_id"), list(upd.keys()))
                    continue
                from_user = msg.get("from") or {}
                text = (msg.get("text") or "").strip()
                print("update", upd.get("update_id"), "from", from_user.get("id"), "chat", (msg.get("chat") or {}).get("id"), "text", text[:120])
                if str(from_user.get("id", "")) != ALLOW_USER_ID:
                    print("skip_user", from_user.get("id"), "allow", ALLOW_USER_ID)
                    continue
                if not text:
                    print("skip_empty_text")
                    continue
                if text.startswith("/start"):
                    tg_send(msg["chat"]["id"], "✅ OmniprismBot connected (Omni-only mode).", msg.get("message_id"))
                    continue
                if text.startswith("/status") or text.startswith("/model") or text.startswith("/whoami"):
                    tg_send(msg["chat"]["id"], omni_status(), msg.get("message_id"))
                    continue
                if text.startswith("/omni"):
                    text = text[5:].strip() or "status"
                    if text == "status":
                        tg_send(msg["chat"]["id"], omni_status(), msg.get("message_id"))
                        continue

                if text.startswith("/character"):
                    desc = text[len('/character'):].strip()
                    if not desc:
                        tg_send(msg["chat"]["id"], "Usage: /character <description>", msg.get("message_id"))
                        continue
                    tg_action(msg["chat"]["id"], "typing")
                    ok, out = mcporter_call("create_character", {"description": desc, "n_directions": 8, "size": 48}, timeout_sec=PIXELLAB_TIMEOUT_SECONDS)
                    prefix = "✅ Character job queued" if ok else "❌ Character request failed"
                    cid = find_uuid(out)
                    extra = f"\ncharacter_id: `{cid}`" if cid else ""
                    tg_send(msg["chat"]["id"], compact_text(f"{prefix}{extra}\n\n{out}"), msg.get("message_id"))
                    continue

                if text.startswith("/animate"):
                    payload = text[len('/animate'):].strip()
                    if not payload:
                        tg_send(msg["chat"]["id"], "Usage: /animate <character_id> <template_animation_id> [action description]", msg.get("message_id"))
                        continue
                    try:
                        parts = shlex.split(payload)
                    except ValueError:
                        parts = payload.split()
                    if len(parts) < 2:
                        tg_send(msg["chat"]["id"], "Usage: /animate <character_id> <template_animation_id> [action description]", msg.get("message_id"))
                        continue
                    character_id = parts[0]
                    template_animation_id = parts[1]
                    action_description = " ".join(parts[2:]).strip() if len(parts) > 2 else None
                    args = {"character_id": character_id, "template_animation_id": template_animation_id}
                    if action_description:
                        args["action_description"] = action_description
                    tg_action(msg["chat"]["id"], "typing")
                    ok, out = mcporter_call("animate_character", args, timeout_sec=PIXELLAB_TIMEOUT_SECONDS)
                    prefix = "✅ Animation job queued" if ok else "❌ Animation request failed"
                    tg_send(msg["chat"]["id"], compact_text(f"{prefix}\n\n{out}"), msg.get("message_id"))
                    continue

                if text.startswith("/tileset"):
                    payload = text[len('/tileset'):].strip()
                    if not payload or ("|" not in payload and "->" not in payload):
                        tg_send(msg["chat"]["id"], "Usage: /tileset <lower terrain> | <upper terrain>\nExample: /tileset ocean water | sandy beach", msg.get("message_id"))
                        continue
                    if "|" in payload:
                        lower, upper = payload.split("|", 1)
                    else:
                        lower, upper = payload.split("->", 1)
                    lower = lower.strip()
                    upper = upper.strip()
                    if not lower or not upper:
                        tg_send(msg["chat"]["id"], "Usage: /tileset <lower terrain> | <upper terrain>", msg.get("message_id"))
                        continue
                    tg_action(msg["chat"]["id"], "typing")
                    ok, out = mcporter_call("create_topdown_tileset", {
                        "lower_description": lower,
                        "upper_description": upper,
                        "tile_size": {"width": 16, "height": 16},
                        "transition_size": 0.25,
                    }, timeout_sec=PIXELLAB_TIMEOUT_SECONDS)
                    prefix = "✅ Tileset job queued" if ok else "❌ Tileset request failed"
                    tid = find_uuid(out)
                    extra = f"\ntileset_id: `{tid}`" if tid else ""
                    tg_send(msg["chat"]["id"], compact_text(f"{prefix}{extra}\n\n{out}"), msg.get("message_id"))
                    continue

                if text.startswith("/pixstatus"):
                    target = text[len('/pixstatus'):].strip()
                    if not target:
                        tg_send(msg["chat"]["id"], "Usage: /pixstatus <character_id|tileset_id|tile_id>", msg.get("message_id"))
                        continue
                    tg_action(msg["chat"]["id"], "typing")
                    # try character, topdown tileset, then isometric tile
                    for tool_name, key in (("get_character", "character_id"), ("get_topdown_tileset", "tileset_id"), ("get_isometric_tile", "tile_id")):
                        ok, out = mcporter_call(tool_name, {key: target}, timeout_sec=PIXELLAB_TIMEOUT_SECONDS)
                        if ok and "not found" not in out.lower():
                            tg_send(msg["chat"]["id"], compact_text(out), msg.get("message_id"))
                            break
                    else:
                        tg_send(msg["chat"]["id"], "Could not resolve that id yet. It may still be queued or invalid.", msg.get("message_id"))
                    continue

                if text.startswith("/image") or text.lower().startswith("image:"):
                    image_prompt = text[6:].strip() if text.startswith("/image") else text.split(":", 1)[1].strip()
                    if not image_prompt:
                        tg_send(msg["chat"]["id"], "Usage: /image <prompt>", msg.get("message_id"))
                        continue
                    tg_action(msg["chat"]["id"], "upload_photo")
                    created = omni_generate_image(image_prompt)
                    if not created.get("ok"):
                        tg_send(msg["chat"]["id"], f"Image error: {created.get('error')}", msg.get("message_id"))
                        continue
                    output_web = created.get("outputWebPath")
                    if not output_web:
                        waited = omni_wait_for_image(created.get("jobId"), OMNI_IMAGE_WAIT_SECONDS)
                        if not waited.get("ok"):
                            tg_send(msg["chat"]["id"], f"Image generation queued but not ready yet ({waited.get('error')}).", msg.get("message_id"))
                            continue
                        output_web = waited.get("outputWebPath")
                    abs_path = studio_web_path_to_abs(output_web)
                    if not abs_path:
                        tg_send(msg["chat"]["id"], f"Image done but file unavailable: {output_web}", msg.get("message_id"))
                        continue
                    try:
                        tg_send_photo(msg["chat"]["id"], abs_path, caption="🖼️ Omni image", reply_to=msg.get("message_id"))
                    except Exception as e:
                        tg_send(msg["chat"]["id"], f"Image send failed: {e}", msg.get("message_id"))
                    continue

                tg_action(msg["chat"]["id"], "typing")
                answer, backend, model = omni_chat(text)
                answer = clean_echo(text, answer)
                print("omni_reply", "backend", backend, "model", model, "chars", len(answer))
                tg_send(msg["chat"]["id"], answer[:3500], msg.get("message_id"))
        except Exception as e:
            print("loop_error:", e)
            time.sleep(2)


if __name__ == "__main__":
    main()
