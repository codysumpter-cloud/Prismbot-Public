#!/usr/bin/env python3
import base64
import io
import hashlib
import os
import random
import tempfile
import threading
import subprocess
from typing import Optional

from fastapi import FastAPI
from pydantic import BaseModel
from PIL import Image, ImageDraw, ImageFilter

APP_VERSION = "0.3.0"
IMAGE_ENGINE = os.getenv('PRISMBOT_IMAGE_ENGINE', 'auto').strip().lower()  # auto|diffusers|procedural
DIFFUSERS_MODEL_ID = os.getenv('PRISMBOT_DIFFUSERS_MODEL', 'segmind/tiny-sd')
DIFFUSERS_AUTO_ENABLE = os.getenv('PRISMBOT_DIFFUSERS_AUTO', '0').strip().lower() in ('1', 'true', 'yes', 'on')

# Guardrails and quality profiles
DIFFUSERS_PROFILE = os.getenv('PRISMBOT_DIFFUSERS_PROFILE', 'lite').strip().lower()  # lite|real
DIFFUSERS_LITE_MAX_SIDE = int(os.getenv('PRISMBOT_DIFFUSERS_LITE_MAX_SIDE', '512'))
DIFFUSERS_REAL_MAX_SIDE = int(os.getenv('PRISMBOT_DIFFUSERS_REAL_MAX_SIDE', '768'))
DIFFUSERS_LITE_MAX_STEPS = int(os.getenv('PRISMBOT_DIFFUSERS_LITE_MAX_STEPS', '14'))
DIFFUSERS_REAL_MAX_STEPS = int(os.getenv('PRISMBOT_DIFFUSERS_REAL_MAX_STEPS', '24'))
DIFFUSERS_CPU_MEM_CAP_MB = int(os.getenv('PRISMBOT_DIFFUSERS_CPU_MEM_CAP_MB', '3072'))
DIFFUSERS_MIN_FREE_MB = int(os.getenv('PRISMBOT_DIFFUSERS_MIN_FREE_MB', '768'))
DIFFUSERS_GUARDED_REAL = os.getenv('PRISMBOT_DIFFUSERS_GUARDED_REAL', '1').strip().lower() in ('1', 'true', 'yes', 'on')
DIFFUSERS_QUEUE_CONCURRENCY = max(1, int(os.getenv('PRISMBOT_DIFFUSERS_QUEUE_CONCURRENCY', '1')))

DIFFUSERS_LOCK = threading.Semaphore(DIFFUSERS_QUEUE_CONCURRENCY)

app = FastAPI(title="PrismBot Local Image Backend", version=APP_VERSION)


class Txt2ImgRequest(BaseModel):
    prompt: str
    negative_prompt: Optional[str] = ""
    width: int = 512
    height: int = 512
    steps: int = 24
    cfg_scale: float = 7.0
    sampler_name: Optional[str] = "DPM++ 2M Karras"
    pixel_mode: Optional[str] = "standard"  # standard|hq
    strict_hq: bool = False
    palette_lock: bool = False
    palette_size: int = 16
    nearest_neighbor: bool = True
    anti_mush: bool = False
    coherence_checks: bool = False
    coherence_threshold: float = 0.35


class Img2ImgRequest(BaseModel):
    prompt: str
    init_images: list[str]
    negative_prompt: Optional[str] = ""
    denoising_strength: float = 0.55
    steps: int = 24
    cfg_scale: float = 7.0
    pixel_mode: Optional[str] = "standard"
    strict_hq: bool = False
    palette_lock: bool = False
    palette_size: int = 16
    nearest_neighbor: bool = True
    anti_mush: bool = False
    coherence_checks: bool = False
    coherence_threshold: float = 0.35


class AudioTranscribeRequest(BaseModel):
    audio_base64: str
    filename: Optional[str] = 'audio.wav'
    language: Optional[str] = 'en'


class ZoomquiltRequest(BaseModel):
    prompt: str
    width: int = 512
    height: int = 512
    layers: int = 8
    anchor_motif: Optional[str] = ""
    negative_prompt: Optional[str] = ""
    steps: int = 24
    cfg_scale: float = 7.0
    pixel_mode: Optional[str] = "hq"
    strict_hq: bool = True
    palette_lock: bool = True
    palette_size: int = 16
    nearest_neighbor: bool = True
    anti_mush: bool = True
    coherence_checks: bool = True
    coherence_threshold: float = 0.3


def clamp(v, lo, hi):
    return max(lo, min(hi, int(v)))


def prompt_seed(prompt: str) -> int:
    h = hashlib.sha256((prompt or "").encode("utf-8")).hexdigest()
    return int(h[:16], 16)


def palette_from_seed(seed: int):
    rng = random.Random(seed)
    base = []
    for _ in range(6):
        base.append((rng.randint(20, 230), rng.randint(20, 230), rng.randint(20, 230), 255))
    return base


def get_mem_snapshot():
    total_mb = 0
    avail_mb = 0
    method = 'none'

    try:
      import psutil
      vm = psutil.virtual_memory()
      total_mb = int(vm.total / (1024 * 1024))
      avail_mb = int(vm.available / (1024 * 1024))
      method = 'psutil'
    except Exception:
      try:
        info = {}
        with open('/proc/meminfo', 'r', encoding='utf-8') as f:
          for line in f:
            parts = line.split(':', 1)
            if len(parts) != 2:
              continue
            key = parts[0].strip()
            val = parts[1].strip().split(' ')[0]
            if val.isdigit():
              info[key] = int(val)
        total_kb = info.get('MemTotal', 0)
        avail_kb = info.get('MemAvailable', info.get('MemFree', 0))
        total_mb = int(total_kb / 1024)
        avail_mb = int(avail_kb / 1024)
        method = 'procfs'
      except Exception:
        total_mb = 0
        avail_mb = 0
        method = 'none'

    return {
      'method': method,
      'totalMb': total_mb,
      'availableMb': avail_mb,
      'usedMb': max(0, total_mb - avail_mb),
    }


def resolve_diffusers_limits(profile: str):
    p = 'real' if profile == 'real' else 'lite'
    max_side = DIFFUSERS_REAL_MAX_SIDE if p == 'real' else DIFFUSERS_LITE_MAX_SIDE
    max_steps = DIFFUSERS_REAL_MAX_STEPS if p == 'real' else DIFFUSERS_LITE_MAX_STEPS
    return p, max_side, max_steps


def choose_runtime_profile():
    profile = DIFFUSERS_PROFILE if DIFFUSERS_PROFILE in ('lite', 'real') else 'lite'
    mem = get_mem_snapshot()

    # Guarded real mode: only allow if machine has enough headroom.
    if profile == 'real' and DIFFUSERS_GUARDED_REAL:
      if mem['availableMb'] and mem['availableMb'] < max(DIFFUSERS_MIN_FREE_MB, DIFFUSERS_CPU_MEM_CAP_MB // 3):
        return 'lite', mem, 'memory_guard_downgrade_to_lite'

    return profile, mem, None


def should_force_procedural(mem):
    if not mem or not mem.get('availableMb'):
      return False
    if mem['availableMb'] < DIFFUSERS_MIN_FREE_MB:
      return True
    if mem.get('usedMb', 0) > 0 and DIFFUSERS_CPU_MEM_CAP_MB > 0 and mem['usedMb'] > DIFFUSERS_CPU_MEM_CAP_MB:
      return True
    return False


def normalize_diffusers_request(width: int, height: int, steps: int):
    profile, mem, profile_reason = choose_runtime_profile()
    _, max_side, max_steps = resolve_diffusers_limits(profile)

    width = clamp(width, 128, max_side)
    height = clamp(height, 128, max_side)
    # keep image area bounded for OOM safety
    max_pixels = max_side * max_side
    pixels = width * height
    if pixels > max_pixels:
      scale = (max_pixels / float(pixels)) ** 0.5
      width = max(128, int(width * scale))
      height = max(128, int(height * scale))

    steps = clamp(steps, 1, max_steps)

    force_procedural = should_force_procedural(mem)
    reason = profile_reason
    if force_procedural and not reason:
      reason = 'memory_guard_force_procedural'

    return {
      'profile': profile,
      'width': width,
      'height': height,
      'steps': steps,
      'mem': mem,
      'forceProcedural': force_procedural,
      'reason': reason,
    }


def generate_pixel_scene(prompt: str, width: int, height: int) -> Image.Image:
    seed = prompt_seed(prompt)
    rng = random.Random(seed)

    width = clamp(width, 128, 1024)
    height = clamp(height, 128, 1024)

    pal = palette_from_seed(seed)
    bg = pal[0]
    img = Image.new("RGBA", (width, height), bg)
    d = ImageDraw.Draw(img)

    bands = max(4, min(14, width // 64))
    for i in range(bands):
      color = pal[(i + 1) % len(pal)]
      y0 = int(i * (height / bands))
      y1 = int((i + 1) * (height / bands))
      d.rectangle([0, y0, width, y1], fill=(color[0], color[1], color[2], 230))

    px = max(4, min(16, width // 64))
    for _ in range(max(40, (width * height) // 9000)):
      x = rng.randrange(0, width)
      y = rng.randrange(0, height)
      w = rng.randrange(px, px * 5)
      h = rng.randrange(px, px * 5)
      c = pal[rng.randrange(0, len(pal))]
      d.rectangle([x, y, min(width - 1, x + w), min(height - 1, y + h)], fill=c)

    cx, cy = width // 2, int(height * 0.62)
    body_w, body_h = width // 6, height // 4
    c = pal[2]
    d.rectangle([cx - body_w // 2, cy - body_h, cx + body_w // 2, cy], fill=c)
    d.rectangle([cx - body_w, cy - body_h // 2, cx + body_w, cy - body_h // 3], fill=pal[3])

    down = img.resize((max(32, width // 8), max(32, height // 8)), resample=Image.Resampling.NEAREST)
    out = down.resize((width, height), resample=Image.Resampling.NEAREST)
    return out


def quantize_palette(img: Image.Image, palette_size: int = 16) -> Image.Image:
    size = max(4, min(64, int(palette_size or 16)))
    quant = img.convert('RGB').quantize(colors=size, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
    return quant.convert('RGBA')


def nearest_neighbor_discipline(img: Image.Image) -> Image.Image:
    w, h = img.size
    down_w = max(32, w // 6)
    down_h = max(32, h // 6)
    return img.resize((down_w, down_h), resample=Image.Resampling.NEAREST).resize((w, h), resample=Image.Resampling.NEAREST)


def anti_mush_cleanup(img: Image.Image, palette_size: int = 16) -> Image.Image:
    cleaned = img.filter(ImageFilter.MedianFilter(size=3))
    cleaned = quantize_palette(cleaned, palette_size=palette_size)
    return cleaned


def silhouette_score(img: Image.Image) -> float:
    alpha = img.getchannel('A')
    hist = alpha.histogram()
    total = max(1, sum(hist))
    opaque = sum(hist[180:])
    return float(opaque) / float(total)


def tile_coherence_score(img: Image.Image, tile: int = 16) -> float:
    rgba = img.convert('RGBA')
    w, h = rgba.size
    tile = max(8, min(32, tile))
    if w < tile * 2 or h < tile * 2:
      return 1.0
    samples = []
    for y in range(0, h - tile + 1, tile):
      for x in range(0, w - tile + 1, tile):
        patch = rgba.crop((x, y, x + tile, y + tile)).convert('RGB')
        hist = patch.histogram()
        strength = sum(hist[::16])
        samples.append(float(strength))
    if len(samples) < 2:
      return 1.0
    avg = sum(samples) / len(samples)
    if avg <= 0:
      return 0.0
    spread = sum(abs(v - avg) for v in samples) / len(samples)
    return max(0.0, min(1.0, 1.0 - (spread / avg)))


def run_pixel_post(img: Image.Image, req) -> tuple[Image.Image, dict]:
    out = img.convert('RGBA')
    report = {
      'palette_lock': bool(req.palette_lock),
      'nearest_neighbor': bool(req.nearest_neighbor),
      'anti_mush': bool(req.anti_mush),
      'coherence_checks': bool(req.coherence_checks),
    }

    if req.nearest_neighbor:
      out = nearest_neighbor_discipline(out)
    if req.palette_lock:
      out = quantize_palette(out, req.palette_size)
    if req.anti_mush:
      out = anti_mush_cleanup(out, req.palette_size)

    if req.coherence_checks:
      sil = silhouette_score(out)
      coh = tile_coherence_score(out)
      report['silhouette_score'] = round(sil, 4)
      report['tile_coherence_score'] = round(coh, 4)
      report['coherence_threshold'] = float(req.coherence_threshold)
      report['coherence_ok'] = sil >= float(req.coherence_threshold) and coh >= float(req.coherence_threshold)

    return out, report


def decode_b64_image(s: str) -> Image.Image:
    raw = s
    if raw.startswith('data:image'):
      i = raw.find(',')
      if i > -1:
        raw = raw[i + 1:]
    data = base64.b64decode(raw)
    return Image.open(io.BytesIO(data)).convert('RGBA')


def apply_prompt_edit(base: Image.Image, prompt: str) -> Image.Image:
    p = (prompt or '').lower()
    img = base.copy()
    d = ImageDraw.Draw(img)
    w, h = img.size

    if 'glow' in p or 'aura' in p:
      for r in range(5, max(10, min(w, h) // 3), 8):
        col = (80, 180, 255, max(10, 140 - r))
        d.ellipse([w//2-r, h//2-r, w//2+r, h//2+r], outline=col, width=2)

    if 'outline' in p:
      d.rectangle([1, 1, w-2, h-2], outline=(255, 255, 255, 200), width=2)

    if 'dark' in p or 'night' in p:
      overlay = Image.new('RGBA', (w, h), (10, 20, 50, 80))
      img = Image.alpha_composite(img, overlay)

    if 'bright' in p or 'light' in p:
      overlay = Image.new('RGBA', (w, h), (255, 255, 255, 35))
      img = Image.alpha_composite(img, overlay)

    down = img.resize((max(32, w // 8), max(32, h // 8)), resample=Image.Resampling.NEAREST)
    return down.resize((w, h), resample=Image.Resampling.NEAREST)


def get_diffusers_pipes():
    if not hasattr(get_diffusers_pipes, '_cache'):
      get_diffusers_pipes._cache = None

    if get_diffusers_pipes._cache is not None:
      return get_diffusers_pipes._cache

    try:
      import torch
      from diffusers import AutoPipelineForText2Image, AutoPipelineForImage2Image

      dtype = torch.float32
      txt = AutoPipelineForText2Image.from_pretrained(DIFFUSERS_MODEL_ID, torch_dtype=dtype)
      img = AutoPipelineForImage2Image.from_pretrained(DIFFUSERS_MODEL_ID, torch_dtype=dtype)

      txt = txt.to('cpu')
      img = img.to('cpu')
      get_diffusers_pipes._cache = (txt, img, None)
      return get_diffusers_pipes._cache
    except Exception as e:
      get_diffusers_pipes._cache = (None, None, str(e))
      return get_diffusers_pipes._cache


def generate_with_diffusers(prompt: str, negative_prompt: str, width: int, height: int, steps: int, cfg_scale: float):
    txt, _img, err = get_diffusers_pipes()
    if err or txt is None:
      return None, err or 'diffusers_unavailable'
    try:
      with DIFFUSERS_LOCK:
        result = txt(
          prompt=prompt,
          negative_prompt=negative_prompt or None,
          num_inference_steps=max(1, min(steps, 30)),
          guidance_scale=max(1.0, min(cfg_scale, 12.0)),
          width=width,
          height=height,
        )
      image = result.images[0].convert('RGBA')
      return image, None
    except Exception as e:
      return None, str(e)


def edit_with_diffusers(base: Image.Image, prompt: str, negative_prompt: str, steps: int, cfg_scale: float, denoising_strength: float):
    _txt, img, err = get_diffusers_pipes()
    if err or img is None:
      return None, err or 'diffusers_unavailable'
    try:
      with DIFFUSERS_LOCK:
        result = img(
          prompt=prompt,
          negative_prompt=negative_prompt or None,
          image=base.convert('RGB'),
          num_inference_steps=max(1, min(steps, 30)),
          guidance_scale=max(1.0, min(cfg_scale, 12.0)),
          strength=max(0.1, min(denoising_strength, 0.95)),
        )
      image = result.images[0].convert('RGBA')
      return image, None
    except Exception as e:
      return None, str(e)


def should_use_diffusers():
    if IMAGE_ENGINE == 'procedural':
      return False
    if IMAGE_ENGINE == 'diffusers':
      return True
    if IMAGE_ENGINE == 'auto' and not DIFFUSERS_AUTO_ENABLE:
      return False
    txt, _img, err = get_diffusers_pipes()
    return txt is not None and err is None


def get_whisper_model():
    try:
      from faster_whisper import WhisperModel
    except Exception:
      return None, 'faster_whisper_not_installed'

    model_name = os.getenv('PRISMBOT_WHISPER_MODEL', 'tiny')
    compute_type = os.getenv('PRISMBOT_WHISPER_COMPUTE', 'int8')

    if not hasattr(get_whisper_model, '_cache'):
      get_whisper_model._cache = {}

    key = f"{model_name}:{compute_type}"
    if key not in get_whisper_model._cache:
      get_whisper_model._cache[key] = WhisperModel(model_name, device='cpu', compute_type=compute_type)

    return get_whisper_model._cache[key], None


def decode_audio_to_tempfile(audio_b64: str, filename: str) -> str:
    raw = audio_b64
    if raw.startswith('data:'):
      i = raw.find(',')
      if i > -1:
        raw = raw[i + 1:]
    data = base64.b64decode(raw)
    suffix = os.path.splitext(filename or 'audio.wav')[1] or '.wav'
    fd, temp_path = tempfile.mkstemp(prefix='prismbot-audio-', suffix=suffix)
    with os.fdopen(fd, 'wb') as f:
      f.write(data)
    return temp_path


@app.get('/health')
def health():
    if IMAGE_ENGINE == 'diffusers' or (IMAGE_ENGINE == 'auto' and DIFFUSERS_AUTO_ENABLE):
      txt, _img, diff_err = get_diffusers_pipes()
      use_diff = txt is not None and diff_err is None
      diff_ready = diff_err is None
    else:
      use_diff = False
      diff_ready = False
      diff_err = None

    runtime_profile, mem, _ = choose_runtime_profile()
    _, max_side, max_steps = resolve_diffusers_limits(runtime_profile)

    return {
      "ok": True,
      "backend": "local-image-engine",
      "version": APP_VERSION,
      "imageEngine": "diffusers" if use_diff else "procedural",
      "engineMode": IMAGE_ENGINE,
      "diffusersAuto": DIFFUSERS_AUTO_ENABLE,
      "diffusersModel": DIFFUSERS_MODEL_ID,
      "diffusersReady": diff_ready,
      "diffusersError": diff_err,
      "diffusersProfile": runtime_profile,
      "guardrails": {
        "maxSide": max_side,
        "maxSteps": max_steps,
        "minFreeMb": DIFFUSERS_MIN_FREE_MB,
        "cpuMemCapMb": DIFFUSERS_CPU_MEM_CAP_MB,
        "guardedReal": DIFFUSERS_GUARDED_REAL,
        "queueConcurrency": DIFFUSERS_QUEUE_CONCURRENCY,
      },
      "memory": mem,
    }


@app.post('/sdapi/v1/txt2img')
def txt2img(req: Txt2ImgRequest):
    normalized = normalize_diffusers_request(req.width, req.height, req.steps)
    width = normalized['width']
    height = normalized['height']
    steps = normalized['steps']
    pixel_mode = 'hq' if str(req.pixel_mode or '').lower() == 'hq' else 'standard'

    if should_use_diffusers() and not normalized['forceProcedural']:
      img, err = generate_with_diffusers(
        prompt=req.prompt,
        negative_prompt=req.negative_prompt or '',
        width=width,
        height=height,
        steps=steps,
        cfg_scale=req.cfg_scale,
      )
      if img is None:
        if req.strict_hq:
          return {"images": [], "error": "hq_strict_failed", "detail": f"diffusers_unavailable:{err}"}
        img = generate_pixel_scene(req.prompt, width, height)
        info = f"prismbot-local-procedural-fallback:{err}"
      else:
        info = f"prismbot-local-diffusers:{normalized['profile']}"
    else:
      guard_note = normalized.get('reason')
      if req.strict_hq:
        return {"images": [], "error": "hq_strict_failed", "detail": f"procedural_blocked:{guard_note or 'diffusers_not_ready'}"}
      img = generate_pixel_scene(req.prompt, width, height)
      info = f"prismbot-local-procedural:{guard_note or 'safe-default'}"

    img, pixel_report = run_pixel_post(img, req)

    bio = io.BytesIO()
    img.save(bio, format='PNG')
    b64 = base64.b64encode(bio.getvalue()).decode('ascii')
    return {
      "images": [b64],
      "parameters": {
        **req.model_dump(),
        "width": width,
        "height": height,
        "steps": steps,
      },
      "pixelReport": pixel_report,
      "info": info
    }


@app.post('/sdapi/v1/img2img')
def img2img(req: Img2ImgRequest):
    if not req.init_images:
      return {"images": [], "error": "init_images_required"}

    base = decode_b64_image(req.init_images[0])
    normalized = normalize_diffusers_request(base.size[0], base.size[1], req.steps)
    steps = normalized['steps']
    pixel_mode = 'hq' if str(req.pixel_mode or '').lower() == 'hq' else 'standard'

    if should_use_diffusers() and not normalized['forceProcedural']:
      edited, err = edit_with_diffusers(
        base=base,
        prompt=req.prompt,
        negative_prompt=req.negative_prompt or '',
        steps=steps,
        cfg_scale=req.cfg_scale,
        denoising_strength=req.denoising_strength,
      )
      if edited is None:
        if req.strict_hq:
          return {"images": [], "error": "hq_strict_failed", "detail": f"img2img_diffusers_unavailable:{err}"}
        edited = apply_prompt_edit(base, req.prompt)
        info = f"prismbot-local-procedural-img2img-fallback:{err}"
      else:
        info = f"prismbot-local-diffusers-img2img:{normalized['profile']}"
    else:
      guard_note = normalized.get('reason')
      if req.strict_hq:
        return {"images": [], "error": "hq_strict_failed", "detail": f"img2img_procedural_blocked:{guard_note or 'diffusers_not_ready'}"}
      edited = apply_prompt_edit(base, req.prompt)
      info = f"prismbot-local-procedural-img2img:{guard_note or 'safe-default'}"

    edited, pixel_report = run_pixel_post(edited, req)

    bio = io.BytesIO()
    edited.save(bio, format='PNG')
    b64 = base64.b64encode(bio.getvalue()).decode('ascii')
    return {
      "images": [b64],
      "parameters": {
        **req.model_dump(),
        "steps": steps,
      },
      "pixelReport": pixel_report,
      "info": info
    }


@app.post('/pixel/zoomquilt')
def pixel_zoomquilt(req: ZoomquiltRequest):
    layers = max(3, min(24, int(req.layers or 8)))
    width = clamp(req.width, 128, 1024)
    height = clamp(req.height, 128, 1024)

    run_id = f"zoomquilt-{hashlib.sha1((req.prompt + str(random.random())).encode('utf-8')).hexdigest()[:10]}"
    out_root = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'output', 'generated', run_id))
    os.makedirs(out_root, exist_ok=True)

    frames = []
    prev = None
    for i in range(layers):
      depth_note = f" depth layer {i+1}/{layers}"
      motif = f" anchor motif: {req.anchor_motif}" if req.anchor_motif else ''
      continuity = ' maintain silhouette and color identity with previous layer'
      layer_prompt = f"{req.prompt}.{motif}{continuity}{depth_note}"

      t2i_req = Txt2ImgRequest(
        prompt=layer_prompt,
        negative_prompt=req.negative_prompt or '',
        width=width,
        height=height,
        steps=req.steps,
        cfg_scale=req.cfg_scale,
        pixel_mode=req.pixel_mode,
        strict_hq=req.strict_hq,
        palette_lock=req.palette_lock,
        palette_size=req.palette_size,
        nearest_neighbor=req.nearest_neighbor,
        anti_mush=req.anti_mush,
        coherence_checks=req.coherence_checks,
        coherence_threshold=req.coherence_threshold,
      )
      generated = txt2img(t2i_req)
      if generated.get('error'):
        return {"ok": False, "error": generated.get('error'), "detail": generated.get('detail'), "runId": run_id}

      img = decode_b64_image(generated['images'][0])
      if prev is not None:
        prev_small = prev.resize((max(32, width // 2), max(32, height // 2)), resample=Image.Resampling.NEAREST)
        prev_zoom = prev_small.resize((width, height), resample=Image.Resampling.NEAREST)
        img = Image.blend(prev_zoom, img, 0.68)
        img, _ = run_pixel_post(img, t2i_req)

      frame_name = f"frame_{i:03d}.png"
      frame_path = os.path.join(out_root, frame_name)
      img.save(frame_path, format='PNG')
      frames.append(frame_path)
      prev = img

    preview_path = os.path.join(out_root, 'preview.mp4')
    ff = subprocess.run([
      'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
      '-framerate', '12', '-i', os.path.join(out_root, 'frame_%03d.png'),
      '-vf', 'format=yuv420p', preview_path
    ], capture_output=True, text=True)

    if ff.returncode != 0:
      return {
        "ok": False,
        "error": "preview_render_failed",
        "detail": (ff.stderr or ff.stdout or '').strip(),
        "runId": run_id,
        "frames": frames,
      }

    return {
      "ok": True,
      "runId": run_id,
      "frames": frames,
      "preview": preview_path,
      "layers": layers,
      "anchorMotif": req.anchor_motif or None,
    }


@app.post('/audio/transcribe')
def audio_transcribe(req: AudioTranscribeRequest):
    model, err = get_whisper_model()
    if err:
      return {"error": err, "text": "", "backend": "local-whisper-unavailable"}

    temp_path = decode_audio_to_tempfile(req.audio_base64, req.filename or 'audio.wav')
    try:
      segments, info = model.transcribe(temp_path, language=req.language or 'en', vad_filter=True)
      text = ' '.join((s.text or '').strip() for s in segments).strip()
      return {
        "text": text,
        "confidence": 0.75,
        "language": getattr(info, 'language', req.language or 'en'),
        "backend": "local-whisper",
      }
    except Exception as e:
      return {"error": str(e), "text": "", "backend": "local-whisper"}
    finally:
      try:
        os.remove(temp_path)
      except Exception:
        pass
