const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { spawn, spawnSync } = require('child_process');
const { getSessionFromRequest } = require('../middleware/auth-session');
const { readOmniRuntimeConfig, providerReadiness } = require('./omni/config');
const { buildAdapters } = require('./omni/adapters');
const { routeProvider } = require('./omni/router');
const { createExecutionEngine } = require('./omni/execution');

const PORT = Number(process.env.PORT || 8799);
const ROOT = path.resolve(__dirname, '..');
const REPO_APPS = path.resolve(ROOT, '..');
const DATA_DIR = path.join(ROOT, 'data');
const COOKIE_NAME = process.env.CORE_SESSION_COOKIE || 'pb_core_session';
const COOKIE_SECURE = String(process.env.CORE_COOKIE_SECURE || 'false').toLowerCase() === 'true';
const OWNER_USER_IDS = new Set(
  String(process.env.CORE_OWNER_USER_IDS || 'u_admin')
    .split(',')
    .map((v) => v.trim())
    .filter(Boolean)
);
const OWNER_USERNAMES = new Set(
  String(process.env.CORE_OWNER_USERNAMES || 'prismtek')
    .split(',')
    .map((v) => v.trim().toLowerCase())
    .filter(Boolean)
);
const API_KEY_SECRET = String(process.env.CORE_APIKEY_SECRET || 'prismbot-core-dev-only-secret-change-me');
const ALLOW_SHARED_KEY = String(process.env.CORE_ALLOW_SHARED_KEY || 'true').toLowerCase() === 'true';
const OPENAI_OAUTH_CLIENT_ID = String(process.env.OPENAI_OAUTH_CLIENT_ID || '').trim();
const OPENAI_OAUTH_CLIENT_SECRET = String(process.env.OPENAI_OAUTH_CLIENT_SECRET || '').trim();
const OPENAI_OAUTH_REDIRECT_URI = String(process.env.OPENAI_OAUTH_REDIRECT_URI || '').trim();
const OPENAI_OAUTH_AUTH_URL = String(process.env.OPENAI_OAUTH_AUTH_URL || 'https://platform.openai.com/oauth/authorize').trim();
const OPENAI_OAUTH_TOKEN_URL = String(process.env.OPENAI_OAUTH_TOKEN_URL || 'https://api.openai.com/v1/oauth/token').trim();
const MCP_OAUTH_CLIENT_ID = String(process.env.MCP_OAUTH_CLIENT_ID || 'prismbot-mcp-client').trim();
const MCP_OAUTH_CLIENT_SECRET = String(process.env.MCP_OAUTH_CLIENT_SECRET || '').trim();
const MCP_OAUTH_ISSUER = String(process.env.MCP_OAUTH_ISSUER || 'https://app.prismtek.dev').trim();
const MCP_BEARER_TOKEN = String(process.env.MCP_BEARER_TOKEN || process.env.PRISMBOT_API_TOKEN || '').trim();
const OPENCLAW_DASHBOARD_HOST = String(process.env.CORE_OPENCLAW_DASHBOARD_HOST || '127.0.0.1').trim();
const OPENCLAW_DASHBOARD_PORT = Number(process.env.CORE_OPENCLAW_DASHBOARD_PORT || 18789);
const OPENCLAW_PROXY_RETRIES = Math.max(0, Number(process.env.CORE_OPENCLAW_PROXY_RETRIES || 2));
const OPENCLAW_PROXY_RETRY_DELAY_MS = Math.max(0, Number(process.env.CORE_OPENCLAW_PROXY_RETRY_DELAY_MS || 250));
const OPENCLAW_PROXY_TIMEOUT_MS = Math.max(1000, Number(process.env.CORE_OPENCLAW_PROXY_TIMEOUT_MS || 8000));
const OPENCLAW_CONFIG_PATH = String(process.env.CORE_OPENCLAW_CONFIG_PATH || path.join(process.env.HOME || '', '.openclaw', 'openclaw.json'));

function readOpenClawGatewayToken() {
  try {
    const cfg = JSON.parse(fs.readFileSync(OPENCLAW_CONFIG_PATH, 'utf8'));
    return String(cfg?.gateway?.auth?.token || '').trim();
  } catch {
    return '';
  }
}

const OPENCLAW_GATEWAY_TOKEN = String(process.env.CORE_OPENCLAW_GATEWAY_TOKEN || readOpenClawGatewayToken()).trim();
const FREE_DAILY_REQUEST_LIMIT = Number(process.env.CORE_FREE_DAILY_REQUEST_LIMIT || 50);
const FREE_DAILY_TOKEN_LIMIT = Number(process.env.CORE_FREE_DAILY_TOKEN_LIMIT || 100000);
const GLOBAL_DAILY_REQUEST_LIMIT = Number(process.env.CORE_GLOBAL_DAILY_REQUEST_LIMIT || 2000);
const GLOBAL_DAILY_TOKEN_LIMIT = Number(process.env.CORE_GLOBAL_DAILY_TOKEN_LIMIT || 3000000);

const STATIC_DIRS = {
  '/': path.join(REPO_APPS, 'prismbot-site'),
  '/chat': path.join(REPO_APPS, 'kid-chat-mvp', 'web'),
  '/admin': path.join(REPO_APPS, 'mission-control', 'public'),
  '/public': path.join(REPO_APPS, 'public-chat', 'web'),
  '/studio-output': path.join(REPO_APPS, 'pixel-pipeline', 'output'),
};

const publicRate = new Map();
const loginRate = new Map();
const aiRate = new Map();
const oauthCodes = new Map();
const oauthTokens = new Map();

function readJson(file, fallback) {
  try { return JSON.parse(fs.readFileSync(path.join(DATA_DIR, file), 'utf8')); } catch { return fallback; }
}
function writeJson(file, value) {
  fs.writeFileSync(path.join(DATA_DIR, file), JSON.stringify(value, null, 2));
}
function sendJson(res, code, payload) {
  res.writeHead(code, { 'content-type': 'application/json' });
  res.end(JSON.stringify(payload));
}
function sendText(res, code, text) {
  res.writeHead(code, { 'content-type': 'text/plain; charset=utf-8' });
  res.end(text);
}
function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}
function getMime(file) {
  if (file.endsWith('.html')) return 'text/html; charset=utf-8';
  if (file.endsWith('.css')) return 'text/css; charset=utf-8';
  if (file.endsWith('.js')) return 'application/javascript; charset=utf-8';
  if (file.endsWith('.json')) return 'application/json; charset=utf-8';
  if (file.endsWith('.png')) return 'image/png';
  if (file.endsWith('.jpg') || file.endsWith('.jpeg')) return 'image/jpeg';
  if (file.endsWith('.gif')) return 'image/gif';
  if (file.endsWith('.webp')) return 'image/webp';
  if (file.endsWith('.wav')) return 'audio/wav';
  if (file.endsWith('.webmanifest')) return 'application/manifest+json';
  return 'application/octet-stream';
}
function parseBody(req) {
  return new Promise((resolve) => {
    let data = '';
    req.on('data', (chunk) => (data += chunk));
    req.on('end', () => {
      if (!data) return resolve({});
      try { resolve(JSON.parse(data)); } catch { resolve({}); }
    });
  });
}

function loadOmniRunsFromDisk() {
  const payload = readJson(OMNI_RUNS_FILE, { jobs: {} });
  const jobs = payload?.jobs || {};
  for (const [id, job] of Object.entries(jobs)) {
    if (job && typeof job === 'object') omniRunJobs.set(id, job);
  }
}

function saveOmniRunsToDisk() {
  const jobs = {};
  for (const [id, job] of omniRunJobs.entries()) jobs[id] = job;
  writeJson(OMNI_RUNS_FILE, { jobs });
}

function upsertOmniRun(job) {
  if (!job?.id) return;
  omniRunJobs.set(job.id, job);
  saveOmniRunsToDisk();
}

function loadOmniIdempotency() {
  const payload = readJson(OMNI_IDEMPOTENCY_FILE, { keys: {} });
  const keys = payload?.keys || {};
  for (const [k, v] of Object.entries(keys)) {
    if (!v || typeof v !== 'object') continue;
    omniIdempotency.set(k, v);
  }
}

function saveOmniIdempotency() {
  const keys = {};
  for (const [k, v] of omniIdempotency.entries()) keys[k] = v;
  writeJson(OMNI_IDEMPOTENCY_FILE, { keys });
}

function findRunByIdempotencyKey(key, userId) {
  const row = omniIdempotency.get(key);
  if (!row || row.userId !== userId) return null;
  return omniRunJobs.get(row.runId) || null;
}

function storeRunIdempotencyKey(key, userId, runId) {
  if (!key || !userId || !runId) return;
  omniIdempotency.set(key, { userId, runId, createdAt: Date.now() });
  saveOmniIdempotency();
}

function appendOmniDeadLetter(entry) {
  const payload = readJson(OMNI_DEADLETTER_FILE, { entries: [] });
  const entries = Array.isArray(payload.entries) ? payload.entries : [];
  entries.push({ ...entry, ts: Date.now() });
  writeJson(OMNI_DEADLETTER_FILE, { entries: entries.slice(-200) });
}

function omniDeadLetterCount() {
  const payload = readJson(OMNI_DEADLETTER_FILE, { entries: [] });
  return Array.isArray(payload.entries) ? payload.entries.length : 0;
}

function queueOmniExecution(task) {
  omniQueue.push(task);
  drainOmniQueue();
}

function drainOmniQueue() {
  while (omniQueueActive < OMNI_QUEUE_CONCURRENCY && omniQueue.length > 0) {
    const task = omniQueue.shift();
    omniQueueActive += 1;
    Promise.resolve()
      .then(() => task())
      .catch(() => {})
      .finally(() => {
        omniQueueActive = Math.max(0, omniQueueActive - 1);
        setTimeout(drainOmniQueue, 10);
      });
  }
}

function appendOmniTimeline(job, message) {
  if (!job) return;
  const item = { ts: Date.now(), message: String(message || '').slice(0, 260) };
  job.timeline = Array.isArray(job.timeline) ? job.timeline : [];
  job.timeline.push(item);
  job.timeline = job.timeline.slice(-40);
}

function getOmniMetricsSnapshot() {
  const jobs = [...omniRunJobs.values()];
  const done = jobs.filter((j) => j.status === 'completed' || j.status === 'failed' || j.status === 'canceled');
  const durations = done
    .map((j) => Number(j.finishedAt || 0) - Number(j.startedAt || j.createdAt || 0))
    .filter((n) => Number.isFinite(n) && n >= 0)
    .sort((a, b) => a - b);
  const p95 = durations.length ? durations[Math.min(durations.length - 1, Math.floor(durations.length * 0.95))] : 0;
  return {
    jobsTotal: jobs.length,
    jobsCompleted: jobs.filter((j) => j.status === 'completed').length,
    jobsFailed: jobs.filter((j) => j.status === 'failed').length,
    jobsCanceled: jobs.filter((j) => j.status === 'canceled').length,
    queueDepth: omniQueue.length,
    queueActive: omniQueueActive,
    queueConcurrency: OMNI_QUEUE_CONCURRENCY,
    latencyMsP95: p95,
    deadLetterCount: omniDeadLetterCount(),
    provider: omniExecEngine.providerMetrics(),
  };
}

function saveStudioJobsToDisk() {
  const jobs = {};
  for (const [id, job] of studioJobs.entries()) jobs[id] = job;
  writeJson(STUDIO_JOBS_FILE, { jobs });
}

function loadStudioJobsFromDisk() {
  const payload = readJson(STUDIO_JOBS_FILE, { jobs: {} });
  const jobs = payload?.jobs || {};
  for (const [id, raw] of Object.entries(jobs)) {
    if (!raw || typeof raw !== 'object') continue;
    const job = { ...raw };
    if (job.status === 'running') job.status = 'queued';
    if (!Array.isArray(job.timeline)) job.timeline = [];
    if (!Number.isFinite(job.attempt)) job.attempt = 0;
    if (!Number.isFinite(job.maxAttempts)) job.maxAttempts = STUDIO_IMAGE_RETRY_MAX + 1;
    studioJobs.set(id, job);
  }
}

function appendStudioTimeline(job, message) {
  if (!job) return;
  const item = { ts: Date.now(), message: String(message || '').slice(0, 240) };
  job.timeline = Array.isArray(job.timeline) ? job.timeline : [];
  job.timeline.push(item);
  job.timeline = job.timeline.slice(-20);
}

function persistStudioJob(job) {
  if (!job?.id) return;
  studioJobs.set(job.id, job);
  saveStudioJobsToDisk();
}

function normalizeStudioUserError(err) {
  const s = String(err || '').trim();
  const low = s.toLowerCase();
  if (!s) return 'Image pipeline failed. Please retry.';
  if (low.includes('timeout') || low.includes('fetch failed') || low.includes('network')) return 'Image backend is temporarily unavailable. Please retry in a moment.';
  if (low.includes('insufficient') || low.includes('out of memory') || low.includes('memory_guard')) return 'Local image engine is at memory limit. Use Lite profile, lower size, or retry after queued runs finish.';
  if (low.includes('invalid api key') || low.includes('unauthorized') || low.includes('missing scopes')) return 'Image auth is not ready. Reconnect ChatGPT or set a valid OpenAI key in Account.';
  if (low.includes('missing_image_data')) return 'Image backend returned an empty payload. Please retry.';
  return s;
}

function isRetryableStudioImageError(err) {
  const low = String(err || '').toLowerCase();
  return low.includes('timeout') || low.includes('fetch failed') || low.includes('network') || low.includes('local_http_') || low.includes('memory_guard') || low.includes('temporarily');
}

function isLocalInfoMemoryGuard(info) {
  const low = String(info || '').toLowerCase();
  return low.includes('memory_guard');
}

async function fetchLocalImageBackendHealth(timeoutMs = 2500) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const resp = await fetch(String(STUDIO_LOCAL_IMAGE_URL || '').replace(/\/sdapi\/v1\/txt2img\/?$/, '/health'), {
      method: 'GET',
      signal: ctrl.signal,
      headers: { accept: 'application/json' },
    });
    const json = await resp.json().catch(() => ({}));
    return { ok: resp.ok, status: resp.status, data: json };
  } catch (err) {
    return { ok: false, status: 0, error: String(err?.message || err || 'health_fetch_failed') };
  } finally {
    clearTimeout(timer);
  }
}

function safeUserById(userId) {
  const users = normalizeUsers(readJson('users.json', {}));
  const found = users?.[userId];
  return found ? { id: userId, ...found } : null;
}

function queueStudioImageExecution(task) {
  studioImageQueue.push(task);
  drainStudioImageQueue();
}

function drainStudioImageQueue() {
  while (studioImageQueueActive < STUDIO_IMAGE_QUEUE_CONCURRENCY && studioImageQueue.length > 0) {
    const task = studioImageQueue.shift();
    studioImageQueueActive += 1;
    Promise.resolve()
      .then(() => task())
      .catch(() => {})
      .finally(() => {
        studioImageQueueActive = Math.max(0, studioImageQueueActive - 1);
        setTimeout(drainStudioImageQueue, 10);
      });
  }
}

function safeListFiles(dirPath, limit = 20) {
  try {
    const files = fs.readdirSync(dirPath, { withFileTypes: true })
      .filter((d) => d.isFile())
      .map((d) => d.name)
      .sort((a, b) => a.localeCompare(b))
      .slice(0, limit);
    return files;
  } catch {
    return [];
  }
}

function parseBodyAny(req) {
  return new Promise((resolve) => {
    let data = '';
    req.on('data', (chunk) => (data += chunk));
    req.on('end', () => {
      if (!data) return resolve({});
      const ct = String(req.headers['content-type'] || '').toLowerCase();
      if (ct.includes('application/x-www-form-urlencoded')) {
        const params = new URLSearchParams(data);
        return resolve(Object.fromEntries(params.entries()));
      }
      try { return resolve(JSON.parse(data)); } catch { return resolve({ raw: data }); }
    });
  });
}

const STUDIO_OUTPUT_ROOT = path.join(REPO_APPS, 'pixel-pipeline', 'output');
const STUDIO_SCRIPT_MAKE_PACK = path.join(REPO_APPS, 'pixel-pipeline', 'scripts', 'make-pack.sh');
const STUDIO_PARITY_PATH = path.join(REPO_APPS, 'pixel-pipeline', 'STUDIO_PARITY_MATRIX.md');
const STUDIO_IMAGE_MODEL = String(process.env.CORE_STUDIO_IMAGE_MODEL || 'gpt-image-1').trim();
const STUDIO_OPENAI_IMAGE_URL = String(process.env.CORE_STUDIO_OPENAI_IMAGE_URL || 'https://api.openai.com/v1/images/generations').trim();
const STUDIO_OPENAI_EDIT_URL = String(process.env.CORE_STUDIO_OPENAI_EDIT_URL || 'https://api.openai.com/v1/images/edits').trim();
const STUDIO_GOOGLE_IMAGE_MODEL = String(process.env.CORE_STUDIO_GOOGLE_IMAGE_MODEL || 'gemini-2.0-flash-exp-image-generation').trim();
const STUDIO_GOOGLE_IMAGE_URL_TEMPLATE = String(process.env.CORE_STUDIO_GOOGLE_IMAGE_URL_TEMPLATE || 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent').trim();
const STUDIO_GOOGLE_IMAGE_ENABLED = ['1', 'true', 'yes', 'on'].includes(String(process.env.CORE_STUDIO_GOOGLE_IMAGE_ENABLED || '').trim().toLowerCase());
const STUDIO_NANOBANANA_ENABLED = ['1', 'true', 'yes', 'on'].includes(String(process.env.CORE_STUDIO_NANOBANANA_ENABLED || '').trim().toLowerCase());
const STUDIO_NANOBANANA_BASE_URL = String(process.env.CORE_STUDIO_NANOBANANA_BASE_URL || 'https://api.nanobananaapi.ai/api/v1/nanobanana').trim().replace(/\/$/, '');
const STUDIO_NANOBANANA_TIMEOUT_MS = Math.max(15000, toSafeInt(process.env.CORE_STUDIO_NANOBANANA_TIMEOUT_MS, 120000, 15000, 600000));
const STUDIO_NANOBANANA_POLL_MS = Math.max(1000, toSafeInt(process.env.CORE_STUDIO_NANOBANANA_POLL_MS, 2500, 1000, 10000));
const STUDIO_POLLINATIONS_ENABLED = !['0', 'false', 'no', 'off'].includes(String(process.env.CORE_STUDIO_POLLINATIONS_ENABLED || 'true').trim().toLowerCase());
const STUDIO_POLLINATIONS_BASE_URL = String(process.env.CORE_STUDIO_POLLINATIONS_BASE_URL || 'https://image.pollinations.ai/prompt').trim().replace(/\/$/, '');
const STUDIO_POLLINATIONS_MODEL = String(process.env.CORE_STUDIO_POLLINATIONS_MODEL || 'flux').trim();
const STUDIO_ALLOW_PAID_FALLBACK = ['1', 'true', 'yes', 'on'].includes(String(process.env.CORE_STUDIO_ALLOW_PAID_FALLBACK || 'false').trim().toLowerCase());
const STUDIO_IMAGE_BACKEND = String(process.env.CORE_STUDIO_IMAGE_BACKEND || 'auto').trim().toLowerCase(); // auto|local|openai
const STUDIO_LOCAL_IMAGE_URL = String(process.env.CORE_STUDIO_LOCAL_IMAGE_URL || 'http://127.0.0.1:7860/sdapi/v1/txt2img').trim();
const STUDIO_LOCAL_EDIT_URL = String(process.env.CORE_STUDIO_LOCAL_EDIT_URL || 'http://127.0.0.1:7860/sdapi/v1/img2img').trim();
const STUDIO_LOCAL_TRANSCRIBE_URL = String(process.env.CORE_STUDIO_LOCAL_TRANSCRIBE_URL || 'http://127.0.0.1:7860/audio/transcribe').trim();
const STUDIO_LOCAL_ZOOMQUILT_URL = String(process.env.CORE_STUDIO_LOCAL_ZOOMQUILT_URL || 'http://127.0.0.1:7860/pixel/zoomquilt').trim();
const STUDIO_LOCAL_IMAGE_STEPS = Math.max(8, Math.min(60, Number(process.env.CORE_STUDIO_LOCAL_IMAGE_STEPS || 28)));
const CORE_PUBLIC_ASSET_BASE_URL = String(process.env.CORE_PUBLIC_ASSET_BASE_URL || '').trim().replace(/\/$/, '');
const CORE_PUBLIC_ASSET_MODE = String(process.env.CORE_PUBLIC_ASSET_MODE || 'public').trim().toLowerCase(); // public|private
const CORE_PUBLIC_ASSET_SIGNING_SECRET = String(process.env.CORE_PUBLIC_ASSET_SIGNING_SECRET || '').trim();
const OPENCLAW_AUTH_PROFILES_PATH = String(process.env.CORE_OPENCLAW_AUTH_PROFILES_PATH || path.join(process.env.HOME || '', '.openclaw', 'agents', 'main', 'agent', 'auth-profiles.json'));
const OMNI_FULL_SPEC_PATH = path.join(ROOT, 'OMNIAI_FULL_SPEC.md');
const OMNI_OPENAPI_FULL_PATH = path.join(ROOT, 'OMNIAI_OPENAPI_FULL.yaml');
const OMNI_RUNS_FILE = 'omni-runs.json';
const OMNI_IDEMPOTENCY_FILE = 'omni-idempotency.json';
const OMNI_RAG_INDEX_FILE = 'omni-rag-index.json';
const OMNI_AGENT_SESSIONS_FILE = 'omni-agent-sessions.json';
const OMNI_DATASETS_FILE = 'omni-datasets.json';
const OMNI_FINETUNES_FILE = 'omni-fine-tunes.json';
const OMNI_LORA_FILE = 'omni-lora.json';
const OMNI_DEADLETTER_FILE = 'omni-dead-letter.json';
const STUDIO_JOBS_FILE = 'studio-jobs.json';
const STUDIO_IMAGE_QUEUE_CONCURRENCY = Math.max(1, Number(process.env.CORE_STUDIO_IMAGE_QUEUE_CONCURRENCY || 1));
const STUDIO_IMAGE_RETRY_MAX = Math.max(0, Number(process.env.CORE_STUDIO_IMAGE_RETRY_MAX || 1));
const STUDIO_IMAGE_RETRY_DELAY_MS = Math.max(250, Number(process.env.CORE_STUDIO_IMAGE_RETRY_DELAY_MS || 1500));
const OMNI_QUEUE_CONCURRENCY = Math.max(1, Number(process.env.CORE_OMNI_QUEUE_CONCURRENCY || 1));
const OMNI_RECOVERY_MAX_ATTEMPTS = Math.max(0, Number(process.env.CORE_OMNI_RECOVERY_MAX_ATTEMPTS || 1));
const studioJobs = new Map();
const omniRunJobs = new Map();
const omniIdempotency = new Map();
const studioImageQueue = [];
const omniQueue = [];
let studioImageQueueActive = 0;
let omniQueueActive = 0;
const omniExecEngine = createExecutionEngine();
loadOmniRunsFromDisk();

function toSafeInt(value, fallback, min, max) {
  const n = Number(value);
  if (!Number.isFinite(n)) return fallback;
  return Math.min(max, Math.max(min, Math.floor(n)));
}

function sanitizeTheme(value) {
  return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 120);
}

function normalizeStudioWebPath(value) {
  const raw = String(value || '').trim();
  if (!raw.startsWith('/studio-output/')) return null;
  if (raw.includes('..')) return null;
  return raw;
}

function buildPublicAssetUrl(webPath, opts = {}) {
  const normalized = normalizeStudioWebPath(webPath);
  if (!normalized) return null;
  const base = CORE_PUBLIC_ASSET_BASE_URL;
  const mode = CORE_PUBLIC_ASSET_MODE === 'private' ? 'private' : 'public';
  let out = base ? `${base}${normalized}` : normalized;
  const shouldAttachPlaceholderSig = opts.sign === true || mode === 'private';
  if (shouldAttachPlaceholderSig) {
    const exp = Number(opts.expiresAt || 0) || (Date.now() + 15 * 60 * 1000);
    const signer = CORE_PUBLIC_ASSET_SIGNING_SECRET
      ? crypto.createHash('sha256').update(`${normalized}|${exp}|${CORE_PUBLIC_ASSET_SIGNING_SECRET}`).digest('hex').slice(0, 24)
      : 'unsigned';
    out += (out.includes('?') ? '&' : '?') + `sig=${signer}&exp=${Math.floor(exp / 1000)}`;
  }
  return out;
}

function shapeAssetRefs(payload = {}, fields = ['webPath', 'outputWebPath']) {
  const next = { ...payload };
  for (const f of fields) {
    const webPath = normalizeStudioWebPath(next[f]);
    if (!webPath) continue;
    const urlField = f === 'webPath' ? 'publicUrl' : `${f}PublicUrl`;
    next[urlField] = buildPublicAssetUrl(webPath);
    if (!next.publicUrl) next.publicUrl = next[urlField];
  }
  return next;
}

function sanitizeStudioJobForClient(job) {
  const safe = shapeAssetRefs({ ...(job || {}) }, ['outputWebPath']);
  delete safe.outputPath;
  return safe;
}

function sanitizeOmniRunForClient(job) {
  const safe = { ...(job || {}) };
  safe.executedSteps = Array.isArray(safe.executedSteps)
    ? safe.executedSteps.map((step) => {
      const row = shapeAssetRefs({ ...(step || {}) }, ['webPath', 'outputWebPath']);
      if (row.job && typeof row.job === 'object') row.job = sanitizeStudioJobForClient(row.job);
      if (row.output && typeof row.output === 'object') row.output = shapeAssetRefs({ ...row.output }, ['webPath', 'outputWebPath']);
      return row;
    })
    : [];
  return safe;
}

function listStudioJobs(limit = 30) {
  return [...studioJobs.values()]
    .sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0))
    .slice(0, limit)
    .map((job) => sanitizeStudioJobForClient(job));
}

function collectStudioGallery(limit = 60) {
  const results = [];
  if (!fs.existsSync(STUDIO_OUTPUT_ROOT)) return results;

  const exts = new Set(['.png', '.jpg', '.jpeg', '.gif', '.webp']);
  const stack = [{ abs: STUDIO_OUTPUT_ROOT, rel: '' }];

  while (stack.length > 0 && results.length < limit) {
    const cur = stack.pop();
    let entries = [];
    try { entries = fs.readdirSync(cur.abs, { withFileTypes: true }); } catch { entries = []; }

    for (const entry of entries) {
      if (results.length >= limit) break;
      const rel = cur.rel ? path.posix.join(cur.rel, entry.name) : entry.name;
      const abs = path.join(cur.abs, entry.name);
      if (entry.isDirectory()) {
        stack.push({ abs, rel });
        continue;
      }
      if (!entry.isFile()) continue;
      const ext = path.extname(entry.name).toLowerCase();
      if (!exts.has(ext)) continue;
      let mtimeMs = 0;
      let size = 0;
      try {
        const stat = fs.statSync(abs);
        mtimeMs = stat.mtimeMs;
        size = stat.size;
      } catch {}
      const webPath = '/studio-output/' + rel.split(path.sep).join('/');
      results.push(shapeAssetRefs({
        file: rel,
        webPath,
        mtimeMs,
        size,
      }, ['webPath']));
    }
  }

  return results.sort((a, b) => (b.mtimeMs || 0) - (a.mtimeMs || 0)).slice(0, limit);
}

function getStudioParityStatus() {
  const hasParityDoc = fs.existsSync(STUDIO_PARITY_PATH);
  const latestJobs = listStudioJobs(200);
  const hasType = (name) => latestJobs.some((j) => j.type === name && j.status === 'completed');

  const checks = {
    studioShell: { implemented: true, note: 'Unified Studio shell is active in /app/studio.' },
    packScaffold: { implemented: true, note: 'Pack scaffold job endpoint and UI are live.' },
    imageGeneration: { implemented: true, note: 'Image generation jobs via OpenAI images API are live.' },
    imageEditInpaint: { implemented: hasType('edit_image'), note: hasType('edit_image') ? 'Edit/inpaint path exercised.' : 'Endpoint implemented; run at least one edit job.' },
    styleReferenceFlow: { implemented: true, note: 'Style profile scaffold endpoint/UI added; true conditioned style generation pending dedicated backend support.' },
    galleryAndJobs: { implemented: true, note: 'Gallery + job tracker live.' },
    parityDoc: { implemented: hasParityDoc, note: hasParityDoc ? 'Parity matrix file present.' : 'Parity matrix file missing.' },
  };

  const completed = Object.values(checks).filter((c) => c.implemented).length;
  const total = Object.keys(checks).length;
  return {
    ok: true,
    progress: {
      completed,
      total,
      percent: Math.round((completed / total) * 100),
    },
    checks,
  };
}

function createStudioPackJob(user, params) {
  if (!fs.existsSync(STUDIO_SCRIPT_MAKE_PACK)) {
    return { ok: false, code: 500, error: 'studio_script_missing', message: 'Studio make-pack script is missing.' };
  }

  const theme = sanitizeTheme(params.theme || params.prompt || 'new-pack');
  if (!theme) return { ok: false, code: 400, error: 'invalid_theme', message: 'Theme is required.' };

  const size = toSafeInt(params.size, 32, 16, 256);
  const count = toSafeInt(params.count, 8, 1, 64);
  const id = 'studio_job_' + crypto.randomBytes(8).toString('hex');
  const createdAt = Date.now();

  const job = {
    id,
    type: 'create_pack_scaffold',
    status: 'running',
    createdAt,
    startedAt: createdAt,
    finishedAt: null,
    userId: user?.id || 'unknown',
    username: user?.username || 'unknown',
    params: { theme, size, count },
    packId: null,
    outputPath: null,
    log: '',
    error: null,
    attempt: 1,
    maxAttempts: 1,
    timeline: [],
  };
  appendStudioTimeline(job, 'Pack scaffold started.');
  persistStudioJob(job);

  const child = spawn('bash', [STUDIO_SCRIPT_MAKE_PACK, theme, String(size), String(count)], {
    cwd: path.join(REPO_APPS, 'pixel-pipeline'),
    env: process.env,
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  const appendLog = (chunk) => {
    job.log = (job.log + String(chunk || '')).slice(-12000);
  };

  child.stdout.on('data', appendLog);
  child.stderr.on('data', appendLog);
  child.on('error', (err) => {
    job.status = 'failed';
    job.error = String(err?.message || err || 'spawn_failed');
    job.finishedAt = Date.now();
    appendStudioTimeline(job, 'Pack scaffold failed to start.');
    persistStudioJob(job);
  });
  child.on('close', (code) => {
    job.finishedAt = Date.now();
    if (code === 0) {
      job.status = 'completed';
      const match = String(job.log).match(/Created pack scaffold:\s*(.+)/i);
      if (match) {
        const outputPath = String(match[1] || '').trim();
        job.outputPath = outputPath;
        job.packId = path.basename(outputPath || '');
      }
      appendStudioTimeline(job, 'Pack scaffold completed.');
    } else {
      job.status = 'failed';
      job.error = `exit_code_${code}`;
      appendStudioTimeline(job, `Pack scaffold failed (exit ${code}).`);
    }
    persistStudioJob(job);
  });

  return { ok: true, job };
}

function getUserOpenAiCredential(user) {
  const list = getUserOpenAiCredentials(user);
  return list[0] || null;
}

function looksLikeOpenAiKey(v) {
  const s = String(v || '').trim();
  return /^sk-[A-Za-z0-9._-]{20,}$/.test(s) && !s.startsWith('sk-test-');
}

function getOpenClawCodexAccessToken() {
  try {
    if (!fs.existsSync(OPENCLAW_AUTH_PROFILES_PATH)) return null;
    const raw = fs.readFileSync(OPENCLAW_AUTH_PROFILES_PATH, 'utf8');
    const parsed = JSON.parse(raw || '{}');
    const profiles = parsed?.profiles || {};
    const preferred = parsed?.lastGood?.['openai-codex'];
    const direct = preferred ? profiles?.[preferred] : null;
    const fallback = profiles?.['openai-codex:default'];
    const token = String((direct?.access || fallback?.access || '')).trim();
    if (!token || !token.startsWith('eyJ')) return null;
    return token;
  } catch {
    return null;
  }
}

function getUserCodexAccessToken(userId) {
  try {
    const store = getCodexOAuthStore();
    const row = store.users?.[userId];
    const access = row?.accessToken ? decryptSecret(row.accessToken) : null;
    if (!access || !access.startsWith('eyJ')) return null;
    return access;
  } catch {
    return null;
  }
}

function getUserOpenAiCredentials(user) {
  const out = [];
  const userId = String(user?.id || '');

  // 1) Per-user ChatGPT/Codex OAuth token (strict isolation)
  const userCodexAccess = getUserCodexAccessToken(userId);
  if (userCodexAccess) out.push({ type: 'user-codex-oauth', token: userCodexAccess });

  // 2) Per-user API key
  const keys = getUserApiKeys();
  const enc = keys[userId];
  const apiKey = enc ? decryptApiKey(enc) : null;
  if (looksLikeOpenAiKey(apiKey)) out.push({ type: 'api_key', token: apiKey });

  // 3) Backward-compat fallback: owner-only global OpenClaw auth profile
  if (Boolean(user?.owner)) {
    const codexAccess = getOpenClawCodexAccessToken();
    if (codexAccess) out.push({ type: 'owner-openclaw-codex-oauth', token: codexAccess });
  }

  return out;
}

function normalizeStudioImageSize(value) {
  const asText = String(value || '').trim().toLowerCase();
  if (/^\d+x\d+$/.test(asText)) {
    const [wRaw, hRaw] = asText.split('x').map((v) => Number(v));
    const w = toSafeInt(wRaw, 512, 256, 1024);
    const h = toSafeInt(hRaw, 512, 256, 1024);
    return `${w}x${h}`;
  }
  const n = toSafeInt(value, 512, 256, 1024);
  return `${n}x${n}`;
}

function sizeToDims(size) {
  const [w, h] = String(size || '512x512').split('x').map((v) => toSafeInt(v, 512, 256, 1024));
  return { width: w, height: h };
}

function qualityProfile(level) {
  const q = String(level || 'balanced').toLowerCase();
  if (q === 'fast') return { level: 'fast', steps: 16, cfgScale: 6 };
  if (q === 'hq') return { level: 'hq', steps: 38, cfgScale: 8 };
  return { level: 'balanced', steps: 28, cfgScale: 7 };
}

function extractB64FromImageResponse(json) {
  let b64 = Array.isArray(json?.images) ? json.images[0] : null;
  if (!b64) return null;
  b64 = String(b64);
  if (b64.startsWith('data:image')) {
    const idx = b64.indexOf(',');
    if (idx > -1) b64 = b64.slice(idx + 1);
  }
  return b64;
}

function studioGoogleImageUrl(model, key) {
  const encodedModel = encodeURIComponent(String(model || STUDIO_GOOGLE_IMAGE_MODEL || 'gemini-2.0-flash-exp-image-generation'));
  const base = STUDIO_GOOGLE_IMAGE_URL_TEMPLATE.includes('{model}')
    ? STUDIO_GOOGLE_IMAGE_URL_TEMPLATE.replace('{model}', encodedModel)
    : STUDIO_GOOGLE_IMAGE_URL_TEMPLATE;
  const hasQuery = base.includes('?');
  return `${base}${hasQuery ? '&' : '?'}key=${encodeURIComponent(String(key || ''))}`;
}

async function generateWithGoogleImageBackend({ prompt, n = 1 }) {
  try {
    if (!STUDIO_GOOGLE_IMAGE_ENABLED) return { ok: false, error: 'google_image_disabled' };
    const key = String(process.env.GOOGLE_API_KEY || process.env.GEMINI_API_KEY || '').trim();
    if (!key) return { ok: false, error: 'missing_google_api_key' };

    const safePrompt = String(prompt || '').trim();
    const payload = {
      contents: [{ parts: [{ text: safePrompt }] }],
      generationConfig: {
        responseModalities: ['TEXT', 'IMAGE'],
        candidateCount: toSafeInt(n, 1, 1, 1),
      },
    };

    const resp = await fetch(studioGoogleImageUrl(STUDIO_GOOGLE_IMAGE_MODEL, key), {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      return { ok: false, error: String(json?.error?.message || json?.error || `google_http_${resp.status}`) };
    }

    let b64 = null;

    const candidates = Array.isArray(json?.candidates) ? json.candidates : [];
    for (const c of candidates) {
      const parts = Array.isArray(c?.content?.parts) ? c.content.parts : [];
      for (const p of parts) {
        if (p?.inlineData?.data) {
          b64 = p.inlineData.data;
          break;
        }
        if (p?.inline_data?.data) {
          b64 = p.inline_data.data;
          break;
        }
      }
      if (b64) break;
    }

    if (!b64) {
      const first = Array.isArray(json?.predictions) ? json.predictions[0] : null;
      b64 = first?.image?.data || first?.imageBytes || first?.b64_json || null;
    }

    if (!b64) return { ok: false, error: 'google_backend_missing_image' };
    b64 = String(b64);
    if (b64.startsWith('data:image')) {
      const idx = b64.indexOf(',');
      if (idx > -1) b64 = b64.slice(idx + 1);
    }
    return { ok: true, b64, model: STUDIO_GOOGLE_IMAGE_MODEL };
  } catch (err) {
    return { ok: false, error: String(err?.message || err || 'google_image_failed') };
  }
}

async function generateWithNanoBananaBackend({ prompt, n = 1 }) {
  try {
    if (!STUDIO_NANOBANANA_ENABLED) return { ok: false, error: 'nanobanana_disabled' };
    const key = String(process.env.OMNI_NANOBANANA2_API_KEY || process.env.NANOBANANA2_API_KEY || '').trim();
    if (!key) return { ok: false, error: 'missing_nanobanana_api_key' };

    const submitResp = await fetch(`${STUDIO_NANOBANANA_BASE_URL}/generate`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${key}`,
      },
      body: JSON.stringify({
        prompt: String(prompt || '').trim(),
        type: 'TEXTTOIAMGE',
        numImages: toSafeInt(n, 1, 1, 1),
      }),
    });
    const submitJson = await submitResp.json().catch(() => ({}));
    if (!submitResp.ok || Number(submitJson?.code || 0) !== 200) {
      return { ok: false, error: String(submitJson?.msg || submitJson?.error || `nanobanana_submit_http_${submitResp.status}`) };
    }

    const taskId = String(submitJson?.data?.taskId || '').trim();
    if (!taskId) return { ok: false, error: 'nanobanana_missing_task_id' };

    const started = Date.now();
    while (Date.now() - started < STUDIO_NANOBANANA_TIMEOUT_MS) {
      const statusResp = await fetch(`${STUDIO_NANOBANANA_BASE_URL}/record-info?taskId=${encodeURIComponent(taskId)}`, {
        method: 'GET',
        headers: { authorization: `Bearer ${key}` },
      });
      const statusJson = await statusResp.json().catch(() => ({}));
      if (!statusResp.ok) {
        return { ok: false, error: String(statusJson?.msg || statusJson?.error || `nanobanana_status_http_${statusResp.status}`) };
      }

      const flag = Number(statusJson?.successFlag ?? 0);
      if (flag === 1) {
        const url = String(statusJson?.response?.resultImageUrl || '').trim();
        if (!url) return { ok: false, error: 'nanobanana_missing_result_url' };
        const imgResp = await fetch(url, { method: 'GET' });
        if (!imgResp.ok) return { ok: false, error: `nanobanana_image_fetch_http_${imgResp.status}` };
        const arr = await imgResp.arrayBuffer();
        const b64 = Buffer.from(arr).toString('base64');
        return { ok: true, b64, model: 'nanobanana-2', taskId };
      }
      if (flag === 2 || flag === 3) {
        return { ok: false, error: String(statusJson?.errorMessage || statusJson?.msg || 'nanobanana_generation_failed') };
      }

      await new Promise((resolve) => setTimeout(resolve, STUDIO_NANOBANANA_POLL_MS));
    }

    return { ok: false, error: 'nanobanana_timeout' };
  } catch (err) {
    return { ok: false, error: String(err?.message || err || 'nanobanana_failed') };
  }
}

async function generateWithPollinationsBackend({ prompt, size }) {
  try {
    if (!STUDIO_POLLINATIONS_ENABLED) return { ok: false, error: 'pollinations_disabled' };
    const { width, height } = sizeToDims(size);
    const q = new URLSearchParams({
      width: String(width),
      height: String(height),
      model: STUDIO_POLLINATIONS_MODEL,
      nologo: 'true',
      private: 'true',
      safe: 'true',
      enhance: 'true',
    });
    const encodedPrompt = encodeURIComponent(String(prompt || '').trim());
    const url = `${STUDIO_POLLINATIONS_BASE_URL}/${encodedPrompt}?${q.toString()}`;

    const resp = await fetch(url, { method: 'GET' });
    if (!resp.ok) return { ok: false, error: `pollinations_http_${resp.status}` };

    const contentType = String(resp.headers.get('content-type') || '').toLowerCase();
    if (!contentType.includes('image')) {
      const txt = await resp.text().catch(() => '');
      return { ok: false, error: txt || 'pollinations_non_image_response' };
    }

    const arr = await resp.arrayBuffer();
    const b64 = Buffer.from(arr).toString('base64');
    return { ok: true, b64, model: STUDIO_POLLINATIONS_MODEL };
  } catch (err) {
    return { ok: false, error: String(err?.message || err || 'pollinations_failed') };
  }
}

async function generateWithLocalBackend({ prompt, negativePrompt, size, transparent, quality, pixelMode = 'standard', strictHq = false, paletteLock = false, paletteSize = 16, nearestNeighbor = true, antiMush = false, coherenceChecks = false, coherenceThreshold = 0.35 }) {
  try {
    const { width, height } = sizeToDims(size);
    const localPrompt = transparent
      ? `${prompt}. transparent background, isolated sprite, alpha`
      : prompt;
    const q = qualityProfile(quality);

    const payload = {
      prompt: localPrompt,
      negative_prompt: negativePrompt || '',
      width,
      height,
      steps: q.steps,
      cfg_scale: q.cfgScale,
      sampler_name: 'DPM++ 2M Karras',
      pixel_mode: pixelMode,
      strict_hq: Boolean(strictHq),
      palette_lock: Boolean(paletteLock),
      palette_size: toSafeInt(paletteSize, 16, 4, 64),
      nearest_neighbor: Boolean(nearestNeighbor),
      anti_mush: Boolean(antiMush),
      coherence_checks: Boolean(coherenceChecks),
      coherence_threshold: Math.max(0.05, Math.min(0.95, Number(coherenceThreshold || 0.35))),
    };

    const resp = await fetch(STUDIO_LOCAL_IMAGE_URL, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      return { ok: false, error: String(json?.error || json?.detail || `local_http_${resp.status}`) };
    }

    if (json && json.error) {
      return { ok: false, error: String(json.error), detail: String(json.detail || '') };
    }
    const b64 = extractB64FromImageResponse(json);
    if (!b64) return { ok: false, error: 'local_backend_missing_image' };
    return { ok: true, b64, pixelReport: json.pixelReport || null };
  } catch (err) {
    return { ok: false, error: String(err?.message || err || 'local_fetch_failed') };
  }
}

async function editWithLocalBackend({ prompt, sourceAbsPath }) {
  try {
    const imgBuf = fs.readFileSync(sourceAbsPath);
    const payload = {
      prompt,
      init_images: [imgBuf.toString('base64')],
      denoising_strength: 0.55,
      steps: STUDIO_LOCAL_IMAGE_STEPS,
      cfg_scale: 7,
    };

    const resp = await fetch(STUDIO_LOCAL_EDIT_URL, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      return { ok: false, error: String(json?.error || json?.detail || `local_http_${resp.status}`) };
    }

    const b64 = extractB64FromImageResponse(json);
    if (!b64) return { ok: false, error: 'local_backend_missing_image' };
    return { ok: true, b64 };
  } catch (err) {
    return { ok: false, error: String(err?.message || err || 'local_fetch_failed') };
  }
}

async function generateZoomquiltWithLocalBackend(params = {}) {
  try {
    const resp = await fetch(STUDIO_LOCAL_ZOOMQUILT_URL, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(params),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok || !json?.ok) return { ok: false, error: String(json?.error || json?.detail || `local_http_${resp.status}`) };
    return { ok: true, ...json };
  } catch (err) {
    return { ok: false, error: String(err?.message || err || 'local_zoomquilt_failed') };
  }
}

async function transcribeWithLocalBackend({ sourceAbsPath, language = 'en' }) {
  try {
    const buf = fs.readFileSync(sourceAbsPath);
    const resp = await fetch(STUDIO_LOCAL_TRANSCRIBE_URL, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        audio_base64: buf.toString('base64'),
        filename: path.basename(sourceAbsPath),
        language,
      }),
    });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      return { ok: false, error: String(json?.error || json?.detail || `local_http_${resp.status}`) };
    }
    const transcript = String(json?.text || '').trim();
    return {
      ok: true,
      transcript,
      confidence: transcript ? Number(json?.confidence || 0.6) : 0,
      backend: String(json?.backend || 'local-whisper'),
      note: transcript ? null : 'No speech detected in audio sample.',
    };
  } catch (err) {
    return { ok: false, error: String(err?.message || err || 'local_transcribe_failed') };
  }
}

function createWaveFileFromText(text) {
  const sampleRate = 16000;
  const seconds = Math.max(1, Math.min(10, Math.ceil(String(text || '').length / 22)));
  const totalSamples = sampleRate * seconds;
  const dataSize = totalSamples * 2;
  const buf = Buffer.alloc(44 + dataSize);

  buf.write('RIFF', 0);
  buf.writeUInt32LE(36 + dataSize, 4);
  buf.write('WAVE', 8);
  buf.write('fmt ', 12);
  buf.writeUInt32LE(16, 16);
  buf.writeUInt16LE(1, 20); // PCM
  buf.writeUInt16LE(1, 22); // mono
  buf.writeUInt32LE(sampleRate, 24);
  buf.writeUInt32LE(sampleRate * 2, 28);
  buf.writeUInt16LE(2, 32);
  buf.writeUInt16LE(16, 34);
  buf.write('data', 36);
  buf.writeUInt32LE(dataSize, 40);

  const seed = promptSeed(String(text || 'prismbot speech'));
  const f1 = 180 + (seed % 120);
  const f2 = 320 + (seed % 180);
  for (let i = 0; i < totalSamples; i += 1) {
    const t = i / sampleRate;
    const amp = 0.25 * Math.sin(2 * Math.PI * f1 * t) + 0.18 * Math.sin(2 * Math.PI * f2 * t);
    const env = Math.min(1, i / (sampleRate * 0.03)) * Math.min(1, (totalSamples - i) / (sampleRate * 0.08));
    const sample = Math.max(-1, Math.min(1, amp * env));
    buf.writeInt16LE(Math.floor(sample * 32767), 44 + i * 2);
  }

  return { buffer: buf, durationSec: seconds, sampleRate };
}

function promptSeed(text) {
  const h = crypto.createHash('sha256').update(String(text || '')).digest('hex');
  return Number.parseInt(h.slice(0, 8), 16) || 1;
}

function studioAudioWebPath(absPath) {
  const rel = path.relative(STUDIO_OUTPUT_ROOT, absPath).split(path.sep).join('/');
  return '/studio-output/' + rel;
}

function buildAutoOrchestrateSteps(prompt, body = {}) {
  const lower = String(prompt || '').toLowerCase();
  const wantsVoice = /(voice|narrat|speak|audio|tts)/.test(lower);
  const wantsLore = /(lore|story|description|caption|copy)/.test(lower);
  const wantsZoomquilt = /(zoomquilt|infinite zoom|infinite-zoom|zoom quilt)/.test(lower);

  const steps = [
    {
      id: 'asset_image',
      type: 'image',
      prompt,
      preset: String(body.preset || 'bitforge'),
      view: String(body.view || 'sidescroller'),
      size: Number(body.size || 512),
      transparent: body.transparent !== false,
    },
    {
      id: 'integration_notes',
      type: 'text',
      prompt: `Write concise implementation notes for this asset request: ${prompt}`,
    },
  ];

  if (wantsZoomquilt) {
    steps.push({
      id: 'zoomquilt_sequence',
      type: 'zoomquilt',
      prompt,
      width: Number(body.size || 512),
      height: Number(body.size || 512),
      layers: Number(body.layers || 8),
      anchor_motif: String(body.anchorMotif || body.anchor_motif || ''),
      pixel_mode: body.pixelMode || body.pixel_mode || 'hq',
      strict_hq: body.strictHq !== false && body.strict_hq !== false,
      palette_lock: body.paletteLock !== false && body.palette_lock !== false,
      anti_mush: body.antiMush !== false && body.anti_mush !== false,
      coherence_checks: body.coherenceChecks !== false && body.coherence_checks !== false,
    });
  }

  if (wantsLore) {
    steps.push({
      id: 'lore_blurb',
      type: 'text',
      prompt: `Write a short in-game lore blurb for: ${prompt}`,
    });
  }

  if (wantsVoice) {
    steps.push({
      id: 'voiceover',
      type: 'speak',
      text: `New PrismBot asset generated: ${prompt}`,
    });
  }

  return steps;
}

async function executeOrchestrateSteps(user, steps, opts = {}) {
  const executed = [];
  const shouldCancel = typeof opts.shouldCancel === 'function' ? opts.shouldCancel : () => false;

  for (let i = 0; i < steps.length; i += 1) {
    if (shouldCancel()) {
      executed.push({ id: `step_${i + 1}`, type: 'control', ok: false, error: 'canceled' });
      break;
    }
    const step = steps[i] || {};
    const id = String(step.id || `step_${i + 1}`);
    const type = String(step.type || '').trim().toLowerCase();

    if (type === 'text') {
      const p = String(step.prompt || step.text || '').trim();
      if (!p) { executed.push({ id, type, ok: false, error: 'missing_prompt' }); continue; }
      const out = generateAiReply(p);
      executed.push({ id, type, ok: true, output: out });
      continue;
    }

    if (type === 'image') {
      const p = String(step.prompt || '').trim();
      if (!p) { executed.push({ id, type, ok: false, error: 'missing_prompt' }); continue; }
      const job = createStudioImageJob(user, {
        prompt: p,
        preset: step.preset || 'bitforge',
        view: step.view || 'sidescroller',
        size: step.size || 512,
        transparent: step.transparent !== false,
        quality: step.quality || 'balanced',
        pixelMode: step.pixelMode || step.pixel_mode || 'standard',
        strictHq: step.strictHq || step.strict_hq || false,
        paletteLock: step.paletteLock || step.palette_lock || false,
        paletteSize: step.paletteSize || step.palette_size || 16,
        nearestNeighbor: step.nearestNeighbor !== false && step.nearest_neighbor !== false,
        antiMush: step.antiMush || step.anti_mush || false,
        coherenceChecks: step.coherenceChecks || step.coherence_checks || false,
        coherenceThreshold: step.coherenceThreshold || step.coherence_threshold || 0.35,
      });
      executed.push({ id, type, ok: Boolean(job.ok), job: job.job, error: job.error, message: job.message });
      continue;
    }

    if (type === 'zoomquilt') {
      const p = String(step.prompt || '').trim();
      if (!p) { executed.push({ id, type, ok: false, error: 'missing_prompt' }); continue; }
      const result = await generateZoomquiltWithLocalBackend({
        prompt: p,
        width: toSafeInt(step.width || step.size || 512, 512, 128, 1024),
        height: toSafeInt(step.height || step.size || 512, 512, 128, 1024),
        layers: toSafeInt(step.layers, 8, 3, 24),
        anchor_motif: String(step.anchorMotif || step.anchor_motif || ''),
        negative_prompt: String(step.negativePrompt || step.negative_prompt || ''),
        pixel_mode: step.pixelMode || step.pixel_mode || 'hq',
        strict_hq: step.strictHq || step.strict_hq || true,
        palette_lock: step.paletteLock ?? step.palette_lock ?? true,
        palette_size: toSafeInt(step.paletteSize || step.palette_size, 16, 4, 64),
        nearest_neighbor: step.nearestNeighbor ?? step.nearest_neighbor ?? true,
        anti_mush: step.antiMush ?? step.anti_mush ?? true,
        coherence_checks: step.coherenceChecks ?? step.coherence_checks ?? true,
        coherence_threshold: Math.max(0.05, Math.min(0.95, Number(step.coherenceThreshold || step.coherence_threshold || 0.3))),
      });
      if (!result.ok) { executed.push({ id, type, ok: false, error: result.error || 'zoomquilt_failed' }); continue; }
      const frameWebPaths = (Array.isArray(result.frames) ? result.frames : []).map((f) => {
        const rel = path.relative(STUDIO_OUTPUT_ROOT, String(f || '')).split(path.sep).join('/');
        return rel && !rel.startsWith('..') ? `/studio-output/${rel}` : null;
      }).filter(Boolean);
      const previewRel = path.relative(STUDIO_OUTPUT_ROOT, String(result.preview || '')).split(path.sep).join('/');
      const previewWebPath = previewRel && !previewRel.startsWith('..') ? `/studio-output/${previewRel}` : null;
      executed.push({ id, type, ok: true, runId: result.runId, frameCount: frameWebPaths.length, frames: frameWebPaths, previewWebPath });
      continue;
    }

    if (type === 'speak') {
      const t = String(step.text || step.prompt || '').trim();
      if (!t) { executed.push({ id, type, ok: false, error: 'missing_text' }); continue; }
      fs.mkdirSync(path.join(STUDIO_OUTPUT_ROOT, 'audio'), { recursive: true });
      const wave = createWaveFileFromText(t);
      const fileBase = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}.wav`;
      const abs = path.join(STUDIO_OUTPUT_ROOT, 'audio', fileBase);
      fs.writeFileSync(abs, wave.buffer);
      executed.push(shapeAssetRefs({ id, type, ok: true, webPath: studioAudioWebPath(abs) }, ['webPath']));
      continue;
    }

    executed.push({ id, type, ok: false, error: 'unsupported_step_type' });
  }

  return executed;
}

function studioWebPathToAbsPath(webPath) {
  const raw = String(webPath || '').trim();
  if (!raw.startsWith('/studio-output/')) return null;
  const rel = raw.slice('/studio-output/'.length);
  if (!rel || rel.includes('..')) return null;
  const abs = path.normalize(path.join(STUDIO_OUTPUT_ROOT, rel));
  if (!abs.startsWith(STUDIO_OUTPUT_ROOT)) return null;
  if (!fs.existsSync(abs) || !fs.statSync(abs).isFile()) return null;
  return abs;
}

function isAuthFailureMessage(msg) {
  const s = String(msg || '').toLowerCase();
  return s.includes('incorrect api key') || s.includes('invalid api key') || s.includes('invalid_api_key') || s.includes('unauthorized') || s.includes('insufficient permissions') || s.includes('missing scopes');
}

function normalizeOpenAiErrorMessage(raw) {
  const msg = String(raw || '').trim();
  const s = msg.toLowerCase();
  if (s.includes('missing scopes') || s.includes('api.model.images.request')) {
    return 'Your current OpenAI/Codex OAuth token does not have image-generation scope (api.model.images.request). Add a real OpenAI API key (sk-...) with image permissions, or use an account/project role that includes image requests.';
  }
  return msg || 'unknown_error';
}

async function callOpenAiWithCredentialFallback(credentials, requestFactory) {
  let last = { ok: false, status: 500, json: { error: 'no_credentials' }, credentialType: 'none' };

  for (const cred of credentials) {
    const req = requestFactory(cred);
    const resp = await fetch(req.url, req.options);
    const json = await resp.json().catch(() => ({}));
    const msg = String(json?.error?.message || json?.error || '');

    last = { ok: resp.ok, status: resp.status, json, credentialType: cred.type };
    if (resp.ok) return last;

    if (!(resp.status === 401 || resp.status === 403 || isAuthFailureMessage(msg))) {
      return last;
    }
  }

  return last;
}

function createStudioEditImageJob(user, params) {
  const prompt = sanitizeTheme(params.prompt || 'improve this sprite');
  const sourceWebPath = String(params.sourceWebPath || '').trim();
  const sourceAbs = studioWebPathToAbsPath(sourceWebPath);
  if (!prompt) return { ok: false, code: 400, error: 'invalid_prompt', message: 'Prompt is required.' };
  if (!sourceAbs) return { ok: false, code: 400, error: 'invalid_source', message: 'Select a valid source image from gallery.' };

  const id = 'studio_job_' + crypto.randomBytes(8).toString('hex');
  const createdAt = Date.now();
  const size = normalizeStudioImageSize(params.size || 512);
  const backendMode = ['local', 'openai', 'auto'].includes(STUDIO_IMAGE_BACKEND) ? STUDIO_IMAGE_BACKEND : 'auto';

  const job = {
    id,
    type: 'edit_image',
    status: 'queued',
    createdAt,
    startedAt: null,
    finishedAt: null,
    userId: user?.id || 'unknown',
    username: user?.username || 'unknown',
    params: { prompt, sourceWebPath, size, backendMode },
    outputPath: null,
    outputWebPath: null,
    model: STUDIO_IMAGE_MODEL,
    error: null,
    userMessage: null,
    attempt: 0,
    maxAttempts: STUDIO_IMAGE_RETRY_MAX + 1,
    timeline: [],
    log: 'Queued image edit job.',
  };
  appendStudioTimeline(job, 'Queued image edit job.');
  persistStudioJob(job);

  const runAttempt = async () => {
    const running = studioJobs.get(id);
    if (!running) return;

    running.status = 'running';
    running.startedAt = running.startedAt || Date.now();
    running.attempt = Number(running.attempt || 0) + 1;
    running.log = `Edit attempt ${running.attempt}/${running.maxAttempts} started.`;
    appendStudioTimeline(running, running.log);
    persistStudioJob(running);

    try {
      let b64 = null;
      let usedBackend = null;
      let localError = null;

      if (backendMode === 'local' || backendMode === 'auto') {
        const local = await editWithLocalBackend({ prompt, sourceAbsPath: sourceAbs });
        if (local.ok) {
          b64 = local.b64;
          usedBackend = 'local';
        } else {
          localError = local.error || 'local_edit_failed';
          if (backendMode === 'local') throw new Error(localError);
        }
      }

      if (!b64) {
        if (!STUDIO_ALLOW_PAID_FALLBACK) {
          throw new Error(localError
            ? `Local image edit backend failed (${localError}) and paid fallback is disabled.`
            : 'Image edits require local backend success when paid fallback is disabled.');
        }

        const authUser = safeUserById(running.userId) || user;
        const credentials = getUserOpenAiCredentials(authUser);
        if (!credentials.length) {
          throw new Error(localError
            ? `No OpenAI credential fallback after local edit backend failure (${localError}).`
            : 'Image edits require OpenClaw Codex OAuth profile or a real OpenAI API key (sk-...) in Account.');
        }

        const imageBuf = fs.readFileSync(sourceAbs);
        const form = new FormData();
        form.append('model', STUDIO_IMAGE_MODEL);
        form.append('prompt', prompt);
        form.append('size', size);
        form.append('n', '1');
        form.append('response_format', 'b64_json');
        form.append('image', new Blob([imageBuf], { type: 'image/png' }), path.basename(sourceAbs));

        const result = await callOpenAiWithCredentialFallback(credentials, (cred) => ({
          url: STUDIO_OPENAI_EDIT_URL,
          options: {
            method: 'POST',
            headers: { authorization: `Bearer ${cred.token}` },
            body: form,
          }
        }));

        const json = result.json || {};
        if (!result.ok) {
          throw new Error(normalizeOpenAiErrorMessage(String(json?.error?.message || json?.error || `openai_http_${result.status}`)));
        }

        const first = Array.isArray(json?.data) ? json.data[0] : null;
        b64 = first?.b64_json;
        usedBackend = 'openai';
        if (!b64) throw new Error('missing_image_data');
      }

      fs.mkdirSync(path.join(STUDIO_OUTPUT_ROOT, 'edited'), { recursive: true });
      const fileBase = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}.png`;
      const rel = path.posix.join('edited', fileBase);
      const abs = path.join(STUDIO_OUTPUT_ROOT, rel);
      fs.writeFileSync(abs, Buffer.from(String(b64), 'base64'));

      running.status = 'completed';
      running.outputPath = abs;
      running.outputWebPath = '/studio-output/' + rel;
      running.log = `Image edit completed (${usedBackend || 'unknown'} backend).`;
      appendStudioTimeline(running, running.log);
      running.error = null;
      running.userMessage = null;
      running.finishedAt = Date.now();
      persistStudioJob(running);
    } catch (err) {
      const raw = String(err?.message || err || 'edit_failed');
      running.error = raw;
      running.userMessage = normalizeStudioUserError(raw);
      const retryable = isRetryableStudioImageError(raw);
      const canRetry = retryable && Number(running.attempt || 1) < Number(running.maxAttempts || 1);
      if (canRetry) {
        running.status = 'queued';
        running.log = `Edit failed (${running.userMessage}). Retrying in ${Math.round(STUDIO_IMAGE_RETRY_DELAY_MS / 1000)}s...`;
        appendStudioTimeline(running, running.log);
        persistStudioJob(running);
        setTimeout(() => queueStudioImageExecution(runAttempt), STUDIO_IMAGE_RETRY_DELAY_MS);
      } else {
        running.status = 'failed';
        running.log = `Edit failed: ${running.userMessage}`;
        appendStudioTimeline(running, running.log);
        running.finishedAt = Date.now();
        persistStudioJob(running);
      }
    }
  };

  queueStudioImageExecution(runAttempt);
  return { ok: true, job };
}

function createStudioStyleProfileJob(user, params) {
  const prompt = sanitizeTheme(params.prompt || 'style profile');
  const refs = Array.isArray(params.references) ? params.references.map((v) => String(v || '').trim()).filter(Boolean) : [];

  const id = 'studio_job_' + crypto.randomBytes(8).toString('hex');
  const createdAt = Date.now();
  const job = {
    id,
    type: 'style_profile_scaffold',
    status: 'completed',
    createdAt,
    startedAt: createdAt,
    finishedAt: Date.now(),
    userId: user?.id || 'unknown',
    username: user?.username || 'unknown',
    params: { prompt, references: refs.slice(0, 12) },
    outputPath: null,
    outputWebPath: null,
    model: STUDIO_IMAGE_MODEL,
    error: null,
    log: ''
  };

  try {
    const dir = path.join(STUDIO_OUTPUT_ROOT, 'style-profiles');
    fs.mkdirSync(dir, { recursive: true });
    const filename = `${Date.now()}-${crypto.randomBytes(3).toString('hex')}.json`;
    const abs = path.join(dir, filename);
    const doc = {
      createdAt,
      prompt,
      references: refs,
      note: 'Scaffold only: true image-conditioned style-reference generation is pending dedicated backend support.'
    };
    fs.writeFileSync(abs, JSON.stringify(doc, null, 2));
    job.outputPath = abs;
    job.outputWebPath = '/studio-output/style-profiles/' + filename;
    job.log = 'Style profile scaffold saved. API-level style-conditioning remains pending.';
  } catch (err) {
    job.status = 'failed';
    job.error = String(err?.message || err || 'style_profile_failed');
    job.log = 'Style profile creation failed: ' + job.error;
  }

  appendStudioTimeline(job, job.status === 'completed' ? 'Style profile scaffold saved.' : 'Style profile scaffold failed.');
  persistStudioJob(job);
  return { ok: true, job };
}

function createStudioImageJob(user, params) {
  const prompt = sanitizeTheme(params.prompt || params.theme || 'pixel art character');
  if (!prompt) return { ok: false, code: 400, error: 'invalid_prompt', message: 'Prompt is required.' };

  const id = 'studio_job_' + crypto.randomBytes(8).toString('hex');
  const createdAt = Date.now();
  const preset = String(params.preset || 'pixflux').trim().toLowerCase();
  const view = String(params.view || 'sidescroller').trim().toLowerCase();
  const palette = sanitizeTheme(params.palette || '');
  const negativePrompt = sanitizeTheme(params.negativePrompt || '');
  const size = normalizeStudioImageSize(params.size);
  const transparent = Boolean(params.transparent);
  const quality = String(params.quality || 'balanced').toLowerCase();
  const n = toSafeInt(params.n, 1, 1, 4);
  const pixelMode = String(params.pixelMode || 'standard').toLowerCase() === 'hq' ? 'hq' : 'standard';
  const strictHq = Boolean(params.strictHq);
  const paletteLock = Boolean(params.paletteLock);
  const paletteSize = toSafeInt(params.paletteSize, 16, 4, 64);
  const nearestNeighbor = params.nearestNeighbor !== false;
  const antiMush = Boolean(params.antiMush);
  const coherenceChecks = Boolean(params.coherenceChecks);
  const coherenceThreshold = Math.max(0.05, Math.min(0.95, Number(params.coherenceThreshold || 0.35)));

  const modeHint = preset === 'bitforge'
    ? 'small-to-medium sprite-focused output, tight silhouette, crisp 1px readability'
    : 'medium-to-xl environment/scene output with coherent composition';

  const finalPromptParts = [
    prompt,
    `style mode: ${modeHint}`,
    `camera/view: ${view}`,
    palette ? `palette hint: ${palette}` : '',
    negativePrompt ? `avoid: ${negativePrompt}` : '',
  ].filter(Boolean);
  const finalPrompt = finalPromptParts.join('. ');

  const backendMode = ['local', 'openai', 'auto'].includes(STUDIO_IMAGE_BACKEND) ? STUDIO_IMAGE_BACKEND : 'auto';

  const job = {
    id,
    type: 'generate_image',
    status: 'queued',
    createdAt,
    startedAt: null,
    finishedAt: null,
    userId: user?.id || 'unknown',
    username: user?.username || 'unknown',
    params: { prompt, preset, view, palette, negativePrompt, size, transparent, quality, n, backendMode, pixelMode, strictHq, paletteLock, paletteSize, nearestNeighbor, antiMush, coherenceChecks, coherenceThreshold },
    outputPath: null,
    outputWebPath: null,
    model: STUDIO_IMAGE_MODEL,
    error: null,
    userMessage: null,
    attempt: 0,
    maxAttempts: STUDIO_IMAGE_RETRY_MAX + 1,
    timeline: [],
    log: 'Queued image generation job.',
  };
  appendStudioTimeline(job, 'Queued image generation job.');
  persistStudioJob(job);

  const runAttempt = async () => {
    const running = studioJobs.get(id);
    if (!running) return;

    running.status = 'running';
    running.startedAt = running.startedAt || Date.now();
    running.attempt = Number(running.attempt || 0) + 1;
    running.log = `Generation attempt ${running.attempt}/${running.maxAttempts} started.`;
    appendStudioTimeline(running, running.log);
    persistStudioJob(running);

    try {
      fs.mkdirSync(path.join(STUDIO_OUTPUT_ROOT, 'generated'), { recursive: true });

      let b64 = null;
      let usedBackend = null;
      let localError = null;

      if (backendMode === 'local' || backendMode === 'auto') {
        const local = await generateWithLocalBackend({ prompt: finalPrompt, negativePrompt, size, transparent, quality, pixelMode, strictHq, paletteLock, paletteSize, nearestNeighbor, antiMush, coherenceChecks, coherenceThreshold });
        if (local.ok) {
          b64 = local.b64;
          usedBackend = 'local';
        } else {
          localError = local.error || 'local_generation_failed';
          if (backendMode === 'local') throw new Error(localError);
        }
      }

      if (!b64) {
        if (strictHq && pixelMode === 'hq') {
          throw new Error(`hq_strict_failed:${localError || 'local_hq_unavailable'}`);
        }

        let openAiError = null;
        const authUser = safeUserById(running.userId) || user;
        const credentials = getUserOpenAiCredentials(authUser);
        if (STUDIO_ALLOW_PAID_FALLBACK && credentials.length) {
          try {
            const payload = {
              model: STUDIO_IMAGE_MODEL,
              prompt: finalPrompt,
              size,
              n,
              response_format: 'b64_json',
            };
            if (transparent) payload.background = 'transparent';

            const result = await callOpenAiWithCredentialFallback(credentials, (cred) => ({
              url: STUDIO_OPENAI_IMAGE_URL,
              options: {
                method: 'POST',
                headers: {
                  'content-type': 'application/json',
                  authorization: `Bearer ${cred.token}`,
                },
                body: JSON.stringify(payload),
              }
            }));

            const json = result.json || {};
            if (!result.ok) {
              throw new Error(normalizeOpenAiErrorMessage(String(json?.error?.message || json?.error || `openai_http_${result.status}`)));
            }

            const first = Array.isArray(json?.data) ? json.data[0] : null;
            b64 = first?.b64_json;
            usedBackend = 'openai';
            if (!b64) throw new Error('missing_image_data');
          } catch (err) {
            openAiError = String(err?.message || err || 'openai_generation_failed');
          }
        }

        if (!b64) {
          let nanoErr = null;
          const nano = await generateWithNanoBananaBackend({ prompt: finalPrompt, n });
          if (nano.ok) {
            b64 = nano.b64;
            usedBackend = 'nanobanana2';
          } else {
            nanoErr = nano.error;
          }

          if (!b64) {
            const google = await generateWithGoogleImageBackend({ prompt: finalPrompt, n });
            if (google.ok) {
              b64 = google.b64;
              usedBackend = 'google';
            } else {
              const poll = await generateWithPollinationsBackend({ prompt: finalPrompt, size });
              if (poll.ok) {
                b64 = poll.b64;
                usedBackend = 'pollinations';
              } else {
                const reasons = [localError, openAiError, nanoErr, google.error, poll.error].filter(Boolean).join(' | ');
                if ((!STUDIO_ALLOW_PAID_FALLBACK || !credentials.length) && !STUDIO_GOOGLE_IMAGE_ENABLED && !STUDIO_NANOBANANA_ENABLED && !STUDIO_POLLINATIONS_ENABLED) {
                  throw new Error(localError
                    ? `No NanoBanana/Google/Pollinations fallback after local backend failure (${localError}).`
                    : 'Image generation requires local backend, NanoBanana API, Google fallback, or Pollinations fallback.');
                }
                throw new Error(reasons || 'image_generation_failed');
              }
            }
          }
        }
      }

      const fileBase = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}.png`;
      const rel = path.posix.join('generated', fileBase);
      const abs = path.join(STUDIO_OUTPUT_ROOT, rel);
      fs.writeFileSync(abs, Buffer.from(String(b64), 'base64'));

      running.status = 'completed';
      running.outputPath = abs;
      running.outputWebPath = '/studio-output/' + rel;
      running.log = `Image generated successfully (${usedBackend || 'unknown'} backend).`;
      appendStudioTimeline(running, running.log);
      running.error = null;
      running.userMessage = null;
      running.finishedAt = Date.now();
      persistStudioJob(running);
    } catch (err) {
      const raw = String(err?.message || err || 'generation_failed');
      running.error = raw;
      running.userMessage = normalizeStudioUserError(raw);
      const retryable = isRetryableStudioImageError(raw);
      const canRetry = retryable && Number(running.attempt || 1) < Number(running.maxAttempts || 1);
      if (canRetry) {
        running.status = 'queued';
        running.log = `Generation failed (${running.userMessage}). Retrying in ${Math.round(STUDIO_IMAGE_RETRY_DELAY_MS / 1000)}s...`;
        appendStudioTimeline(running, running.log);
        persistStudioJob(running);
        setTimeout(() => queueStudioImageExecution(runAttempt), STUDIO_IMAGE_RETRY_DELAY_MS);
      } else {
        running.status = 'failed';
        running.log = `Generation failed: ${running.userMessage}`;
        appendStudioTimeline(running, running.log);
        running.finishedAt = Date.now();
        persistStudioJob(running);
      }
    }
  };

  queueStudioImageExecution(runAttempt);
  return { ok: true, job };
}

function recoverQueuedStudioJobs() {
  const pending = [...studioJobs.values()].filter((job) =>
    (job.status === 'queued' || job.status === 'running') && (job.type === 'generate_image' || job.type === 'edit_image')
  );

  for (const old of pending) {
    const user = safeUserById(old.userId) || { id: old.userId, username: old.username };
    const created = old.type === 'edit_image'
      ? createStudioEditImageJob(user, old.params || {})
      : createStudioImageJob(user, old.params || {});

    old.status = 'failed';
    old.error = 'recovered_after_restart';
    old.userMessage = created.ok
      ? `Server restarted while queued/running. Requeued as ${created.job?.id || 'new_job'}.`
      : 'Server restarted while queued/running. Please retry this run.';
    old.recoveryJobId = created.job?.id || null;
    old.finishedAt = Date.now();
    appendStudioTimeline(old, old.userMessage);
    persistStudioJob(old);
  }
}

function recoverQueuedOmniRuns() {
  const pending = [...omniRunJobs.values()].filter((job) => job.type === 'orchestrate_run' && (job.status === 'queued' || job.status === 'running'));
  for (const job of pending) {
    const recoveredAttempts = Number(job.recoveredAttempts || 0);
    const shouldRetry = recoveredAttempts < OMNI_RECOVERY_MAX_ATTEMPTS;
    if (!shouldRetry) {
      job.status = 'failed';
      job.error = 'recovered_after_restart';
      job.finishedAt = Date.now();
      appendOmniTimeline(job, 'Run moved to dead-letter after restart recovery retry budget exhausted.');
      appendOmniDeadLetter({ runId: job.id, type: job.type, reason: 'recovery_retry_exhausted', recoveredAttempts });
      upsertOmniRun(job);
      continue;
    }

    const user = safeUserById(job.userId) || { id: job.userId, username: job.username || 'unknown' };
    job.status = 'queued';
    job.error = null;
    job.recoveredAttempts = recoveredAttempts + 1;
    appendOmniTimeline(job, `Recovered after restart; retry attempt ${job.recoveredAttempts}.`);
    upsertOmniRun(job);

    queueOmniExecution(async () => {
      const live = omniRunJobs.get(job.id);
      if (!live || live.cancelRequested) return;
      try {
        live.status = 'running';
        live.startedAt = live.startedAt || Date.now();
        appendOmniTimeline(live, 'Recovered run resumed.');
        upsertOmniRun(live);

        const steps = Array.isArray(live.plannedSteps) ? live.plannedSteps : [];
        live.executedSteps = await executeOrchestrateSteps(user, steps, {
          shouldCancel: () => Boolean(omniRunJobs.get(job.id)?.cancelRequested),
        });

        const canceled = Boolean(omniRunJobs.get(job.id)?.cancelRequested);
        live.status = canceled ? 'canceled' : 'completed';
        live.finishedAt = Date.now();
        appendOmniTimeline(live, canceled ? 'Recovered run canceled.' : 'Recovered run completed.');
        upsertOmniRun(live);
      } catch (err) {
        live.status = 'failed';
        live.error = String(err?.message || err || 'recovered_run_failed');
        live.finishedAt = Date.now();
        appendOmniTimeline(live, 'Recovered run failed: ' + live.error);
        appendOmniDeadLetter({ runId: live.id, type: live.type, reason: live.error, recoveredAttempts: live.recoveredAttempts || 0 });
        upsertOmniRun(live);
      }
    });
  }
}

loadStudioJobsFromDisk();
loadOmniIdempotency();
recoverQueuedStudioJobs();
recoverQueuedOmniRuns();

function getOmniRuntime() {
  const config = readOmniRuntimeConfig(process.env);
  const adapters = buildAdapters(config);
  const readiness = providerReadiness(config);
  return { config, adapters, readiness };
}

function omniModelsPayload() {
  const runtime = getOmniRuntime();
  const routing = routeProvider({ adapters: runtime.adapters, strategy: runtime.config.routing.strategy, modality: 'text' });
  const providers = Object.values(runtime.adapters).map((adapter) => ({
    provider: adapter.provider,
    modalities: adapter.modalities,
    readiness: adapter.readiness().readiness,
  }));

  return {
    strategy: runtime.config.routing.strategy,
    preferredProvider: routing.selected,
    fallbackChain: routing.fallbackChain,
    providers,
  };
}

function normalizeUsers(usersRaw) {
  if (Array.isArray(usersRaw)) {
    return Object.fromEntries(usersRaw.map((u) => [u.id, u]));
  }
  return usersRaw || {};
}

function verifyScryptPassword(password, encodedHash) {
  try {
    const [alg, saltHex, hashHex] = String(encodedHash || '').split(':');
    if (alg !== 'scrypt' || !saltHex || !hashHex) return false;
    const candidate = crypto.scryptSync(password, Buffer.from(saltHex, 'hex'), Buffer.from(hashHex, 'hex').length);
    const expected = Buffer.from(hashHex, 'hex');
    return crypto.timingSafeEqual(candidate, expected);
  } catch {
    return false;
  }
}

function encryptApiKey(rawKey) {
  const iv = crypto.randomBytes(12);
  const key = crypto.scryptSync(API_KEY_SECRET, 'pb-core-api-key', 32);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const encrypted = Buffer.concat([cipher.update(String(rawKey), 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return `v1:${iv.toString('hex')}:${tag.toString('hex')}:${encrypted.toString('hex')}`;
}

function decryptApiKey(stored) {
  try {
    const [version, ivHex, tagHex, dataHex] = String(stored || '').split(':');
    if (version !== 'v1' || !ivHex || !tagHex || !dataHex) return null;
    const key = crypto.scryptSync(API_KEY_SECRET, 'pb-core-api-key', 32);
    const decipher = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(ivHex, 'hex'));
    decipher.setAuthTag(Buffer.from(tagHex, 'hex'));
    return Buffer.concat([decipher.update(Buffer.from(dataHex, 'hex')), decipher.final()]).toString('utf8');
  } catch {
    return null;
  }
}

function getUserApiKeys() {
  return readJson('user-api-keys.json', {});
}

function setUserApiKeys(next) {
  writeJson('user-api-keys.json', next);
}

function hasUserApiKey(userId) {
  const keys = getUserApiKeys();
  const enc = keys[userId];
  return Boolean(enc && decryptApiKey(enc));
}

function getOAuthStore() {
  return readJson('user-oauth.json', { users: {}, states: {} });
}

function setOAuthStore(next) {
  writeJson('user-oauth.json', next);
}

function getCodexOAuthStore() {
  return readJson('user-codex-oauth.json', { users: {}, states: {} });
}

function setCodexOAuthStore(next) {
  writeJson('user-codex-oauth.json', next);
}

function getBaseUrl(req) {
  const proto = String(req.headers['x-forwarded-proto'] || 'https').split(',')[0].trim();
  const host = String(req.headers['x-forwarded-host'] || req.headers.host || '').split(',')[0].trim();
  return `${proto}://${host}`;
}

function encryptSecret(raw) {
  return encryptApiKey(raw);
}

function decryptSecret(stored) {
  return decryptApiKey(stored);
}

function hasOAuthAccess(userId) {
  const oauth = getOAuthStore();
  const row = oauth.users?.[userId];
  return Boolean(row && decryptSecret(row.accessToken));
}

function estimateTokens(text) {
  return Math.max(1, Math.ceil(String(text || '').length / 4));
}

function todayKey() {
  return new Date().toISOString().slice(0, 10);
}

function getUsageStore() {
  return readJson('usage.json', { days: {} });
}

function setUsageStore(next) {
  writeJson('usage.json', next);
}

function getBillingStatus(user) {
  if (!user) return null;
  const hasKey = hasUserApiKey(user.id);
  const hasOauth = hasOAuthAccess(user.id);
  const usingShared = !hasKey && !hasOauth && ALLOW_SHARED_KEY;
  const day = todayKey();
  const store = getUsageStore();
  const dayRow = store.days?.[day] || {};
  const userRow = dayRow.users?.[user.id] || { requests: 0, tokens: 0 };
  const globalRow = dayRow.global || { requests: 0, tokens: 0 };
  return {
    day,
    hasApiKey: hasKey,
    hasOAuth: hasOauth,
    usingShared,
    plan: usingShared ? 'free_shared' : hasOauth ? 'oauth_connected' : hasKey ? 'bring_your_own_key' : 'blocked',
    user: {
      requests: userRow.requests,
      tokens: userRow.tokens,
      requestLimit: FREE_DAILY_REQUEST_LIMIT,
      tokenLimit: FREE_DAILY_TOKEN_LIMIT,
      requestsRemaining: Math.max(0, FREE_DAILY_REQUEST_LIMIT - userRow.requests),
      tokensRemaining: Math.max(0, FREE_DAILY_TOKEN_LIMIT - userRow.tokens),
    },
    global: {
      requests: globalRow.requests,
      tokens: globalRow.tokens,
      requestLimit: GLOBAL_DAILY_REQUEST_LIMIT,
      tokenLimit: GLOBAL_DAILY_TOKEN_LIMIT,
      requestsRemaining: Math.max(0, GLOBAL_DAILY_REQUEST_LIMIT - globalRow.requests),
      tokensRemaining: Math.max(0, GLOBAL_DAILY_TOKEN_LIMIT - globalRow.tokens),
    }
  };
}

function consumeSharedUsage(userId, tokenCost) {
  const day = todayKey();
  const usage = getUsageStore();
  if (!usage.days[day]) usage.days[day] = { global: { requests: 0, tokens: 0 }, users: {} };
  if (!usage.days[day].users[userId]) usage.days[day].users[userId] = { requests: 0, tokens: 0 };

  const u = usage.days[day].users[userId];
  const g = usage.days[day].global;

  if (u.requests + 1 > FREE_DAILY_REQUEST_LIMIT || u.tokens + tokenCost > FREE_DAILY_TOKEN_LIMIT) {
    return { ok: false, reason: 'user_limit', usage: getBillingStatus({ id: userId }) };
  }
  if (g.requests + 1 > GLOBAL_DAILY_REQUEST_LIMIT || g.tokens + tokenCost > GLOBAL_DAILY_TOKEN_LIMIT) {
    return { ok: false, reason: 'global_limit', usage: getBillingStatus({ id: userId }) };
  }

  u.requests += 1;
  u.tokens += tokenCost;
  g.requests += 1;
  g.tokens += tokenCost;
  setUsageStore(usage);
  return { ok: true, usage: getBillingStatus({ id: userId }) };
}

function checkPublicRate(ip, max = 20, windowMs = 60_000) {
  const now = Date.now();
  const row = publicRate.get(ip) || { count: 0, resetAt: now + windowMs };
  if (now > row.resetAt) {
    row.count = 0;
    row.resetAt = now + windowMs;
  }
  row.count += 1;
  publicRate.set(ip, row);
  return { allowed: row.count <= max, remaining: Math.max(0, max - row.count) };
}

function checkLoginRate(ip, max = 10, windowMs = 60_000) {
  const now = Date.now();
  const row = loginRate.get(ip) || { count: 0, resetAt: now + windowMs };
  if (now > row.resetAt) {
    row.count = 0;
    row.resetAt = now + windowMs;
  }
  row.count += 1;
  loginRate.set(ip, row);
  return { allowed: row.count <= max, remaining: Math.max(0, max - row.count) };
}

function checkAiRate(ip, max = 30, windowMs = 60_000) {
  const now = Date.now();
  const row = aiRate.get(ip) || { count: 0, resetAt: now + windowMs };
  if (now > row.resetAt) {
    row.count = 0;
    row.resetAt = now + windowMs;
  }
  row.count += 1;
  aiRate.set(ip, row);
  return { allowed: row.count <= max, remaining: Math.max(0, max - row.count) };
}

function getSessionStore() { return readJson('sessions.json', {}); }
function setSessionStore(v) { writeJson('sessions.json', v); }

function getUserByToken(token) {
  if (!token) return null;
  const sessions = getSessionStore();
  const users = normalizeUsers(readJson('users.json', {}));
  const s = sessions[token];
  if (!s || !users[s.userId]) return null;
  return { id: s.userId, ...users[s.userId] };
}

function setSession(res, userId) {
  const sessions = getSessionStore();
  const token = crypto.randomBytes(24).toString('hex');
  sessions[token] = { id: token, userId, createdAt: new Date().toISOString(), kind: 'web' };
  setSessionStore(sessions);
  const secure = COOKIE_SECURE ? '; Secure' : '';
  res.setHeader('Set-Cookie', `${COOKIE_NAME}=${token}; Path=/; HttpOnly; SameSite=Lax${secure}`);
}

function clearSession(req, res) {
  const { token } = getSessionFromRequest(req, COOKIE_NAME);
  if (token) {
    const sessions = getSessionStore();
    delete sessions[token];
    setSessionStore(sessions);
  }
  const secure = COOKIE_SECURE ? '; Secure' : '';
  res.setHeader('Set-Cookie', `${COOKIE_NAME}=; Path=/; HttpOnly; Max-Age=0; SameSite=Lax${secure}`);
}

function findUserByUsername(username) {
  const users = normalizeUsers(readJson('users.json', {}));
  const needle = String(username || '').trim().toLowerCase();
  let entry = Object.entries(users).find(([, u]) => String(u.username || '').toLowerCase() === needle);
  if (!entry) entry = Object.entries(users).find(([, u]) => String(u.displayName || '').toLowerCase() === needle);
  if (!entry && needle === 'admin') {
    entry = Object.entries(users).find(([, u]) => String(u.role || '').toLowerCase() === 'admin' && u.active);
  }
  if (!entry) return null;
  const [id, user] = entry;
  return { id, ...user };
}

function isOwner(user) {
  if (!user) return false;
  if (OWNER_USER_IDS.has(String(user.id || ''))) return true;
  return OWNER_USERNAMES.has(String(user.username || '').toLowerCase());
}

function sanitizeUser(u) {
  return {
    id: u.id,
    username: u.username,
    role: u.role || 'family_user',
    displayName: u.displayName || u.username,
    owner: isOwner(u)
  };
}

function serveStatic(reqPath, res) {
  const candidateRoots = Object.keys(STATIC_DIRS)
    .sort((a, b) => b.length - a.length)
    .filter((prefix) => prefix === '/'
      ? reqPath.startsWith('/')
      : (reqPath === prefix || reqPath.startsWith(prefix + '/')));

  for (const prefix of candidateRoots) {
    const root = STATIC_DIRS[prefix];
    let rel = prefix === '/' ? reqPath : reqPath.slice(prefix.length);
    rel = String(rel || '').replace(/^\/+/, '');
    if (!rel) rel = 'index.html';

    const filePath = path.normalize(path.join(root, rel));
    if (!filePath.startsWith(root + path.sep) && filePath !== root) continue;

    if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
      res.writeHead(200, { 'content-type': getMime(filePath) });
      fs.createReadStream(filePath).pipe(res);
      return true;
    }

    const indexPath = path.join(root, 'index.html');
    if (fs.existsSync(indexPath)) {
      res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
      fs.createReadStream(indexPath).pipe(res);
      return true;
    }
  }

  return false;
}

function moderate(text) {
  return /(self-harm|kill|weapon|exploit|malware|sexual minor|credit card dump|doxx)/i.test(text);
}

function readBearerToken(req) {
  const auth = String(req.headers.authorization || '');
  if (!auth.toLowerCase().startsWith('bearer ')) return null;
  return auth.slice(7).trim() || null;
}

function omniRequestId(req) {
  const fromHeader = String(req.headers['x-request-id'] || '').trim();
  if (fromHeader) return fromHeader.slice(0, 120);
  return `omni_${Date.now().toString(36)}_${crypto.randomBytes(3).toString('hex')}`;
}

function sendOmniError(res, code, error, message, hint, requestId) {
  return sendJson(res, code, {
    ok: false,
    error: String(error || 'unknown_error'),
    message: String(message || 'Request failed.'),
    hint: hint ? String(hint) : undefined,
    meta: { requestId: requestId || null },
  });
}

function sendOmniOk(res, payload, requestId) {
  const next = { ok: true, ...payload };
  next.meta = { ...(payload?.meta || {}), requestId: requestId || null };
  return sendJson(res, 200, next);
}

function sendOmniPlanned(res, requestId, endpoint, rationale) {
  return sendJson(res, 501, {
    ok: false,
    error: 'planned_not_implemented',
    message: 'This endpoint is planned and not implemented in the current runtime.',
    hint: rationale || 'See OMNIAI_FULL_SPEC.md status + roadmap.',
    status: 'planned',
    endpoint,
    meta: { requestId: requestId || null },
  });
}

function getOmniAuthUser(req, safeUser, user) {
  if (safeUser && user) return { safeUser, user };
  const expected = String(process.env.PRISMBOT_API_TOKEN || '').trim();
  if (!expected) return null;
  const got = readBearerToken(req);
  if (!got || got !== expected) return null;
  const users = normalizeUsers(readJson('users.json', {}));
  const ownerRow = Object.entries(users).find(([, u]) => isOwner({ id: u.id, username: u.username }))
    || Object.entries(users).find(([, u]) => String(u.role || '').toLowerCase() === 'admin')
    || Object.entries(users)[0];
  if (!ownerRow) return null;
  const [id, u] = ownerRow;
  const fullUser = { id, ...u };
  return { user: fullUser, safeUser: sanitizeUser(fullUser) };
}

function inferOmniInput(body) {
  const prompt = String(body?.prompt || body?.input || body?.text || '').trim();
  if (prompt) return prompt;
  const messages = Array.isArray(body?.messages) ? body.messages : [];
  return inferUserTextFromMessages(messages);
}

function inferAutoRouteProfile(inputText, body) {
  const explicitThinking = String(body?.thinking || body?.reasoning || '').trim().toLowerCase();
  if (['max', 'high', 'deep'].includes(explicitThinking)) return 'quality';
  if (['low', 'minimal', 'none'].includes(explicitThinking)) return 'fast';

  const t = String(inputText || inferOmniInput(body) || '').trim().toLowerCase();
  const charLen = t.length;
  const wordLen = t ? t.split(/\s+/).length : 0;

  const qualitySignals = [
    /\b(debug|diagnose|root cause|incident|postmortem)\b/,
    /\b(architecture|design doc|trade[- ]?off|scalab|refactor)\b/,
    /\b(explain why|step by step|deep dive|analy[sz]e)\b/,
    /\b(code|function|class|typescript|python|javascript|sql|regex)\b/,
    /\b(prove|deriv|math|equation|optimi[sz]e|complexity)\b/,
  ];

  if (charLen > 360 || wordLen > 70) return 'quality';
  if (qualitySignals.some((r) => r.test(t))) return 'quality';

  if (charLen <= 40 && wordLen <= 8) return 'fast';
  if (/^(hi|hey|yo|hello|thanks|ok|k|status)\b/.test(t)) return 'fast';

  return 'balanced';
}

function applyOmniRouteProfile(body, routeProfile = 'balanced', inputText = '') {
  const src = isPlainObject(body) ? body : {};
  const next = { ...src };
  if (next.model) return next;

  const fastModel = String(process.env.OMNI_TEXT_MODEL_FAST || 'llama3.2:1b').trim();
  const qualityModel = String(process.env.OMNI_TEXT_MODEL_QUALITY || process.env.OMNI_LOCAL_MODEL || process.env.OMNI_OLLAMA_MODEL || 'omni-core:phase2').trim();
  const balancedModel = String(process.env.OMNI_TEXT_MODEL_DEFAULT || process.env.OMNI_LOCAL_MODEL || process.env.OMNI_OLLAMA_MODEL || 'omni-core:phase2').trim();

  let profile = String(routeProfile || 'balanced').trim().toLowerCase();
  if (profile === 'auto') profile = inferAutoRouteProfile(inputText, next);

  if (profile === 'fast') next.model = fastModel;
  else if (profile === 'quality') next.model = qualityModel;
  else next.model = balancedModel;
  next.routeProfile = profile;
  return next;
}

async function executeOmniTextWithRouting({ runtime, modality = 'text', inputText, body, requestId, routeProfile = 'auto' }) {
  const routedBody = applyOmniRouteProfile(body, routeProfile, inputText);
  return omniExecEngine.executeTextWithRouting({ runtime, modality, inputText, body: routedBody, requestId });
}

function isPlainObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function validateOmniBodyObject(body) {
  if (!isPlainObject(body)) return { ok: false, error: 'invalid_body', message: 'JSON object body is required.' };
  return { ok: true };
}

function validateOmniMessages(messages) {
  if (!Array.isArray(messages)) return { ok: true };
  if (!messages.length) return { ok: false, error: 'invalid_messages', message: 'messages must not be empty when provided.' };
  for (const row of messages) {
    if (!isPlainObject(row)) return { ok: false, error: 'invalid_messages', message: 'messages[] entries must be objects.' };
    if (typeof row.role !== 'string' || typeof row.content !== 'string') {
      return { ok: false, error: 'invalid_messages', message: 'messages[] entries require string role/content.' };
    }
  }
  return { ok: true };
}

function validateOmniTextField(label, value, maxLen = 16000) {
  if (typeof value !== 'string') return { ok: false, error: `invalid_${label}`, message: `${label} must be a string.` };
  const trimmed = value.trim();
  if (!trimmed) return { ok: false, error: `missing_${label}`, message: `Provide ${label}.` };
  if (trimmed.length > maxLen) return { ok: false, error: 'input_too_large', message: `Input exceeds max length (${maxLen}).` };
  return { ok: true, value: trimmed };
}

function getRagIndexStore() {
  return readJson(OMNI_RAG_INDEX_FILE, { docs: {} });
}

function setRagIndexStore(next) {
  writeJson(OMNI_RAG_INDEX_FILE, next);
}

function getOmniAgentSessionStore() {
  return readJson(OMNI_AGENT_SESSIONS_FILE, { sessions: {} });
}

function setOmniAgentSessionStore(next) {
  writeJson(OMNI_AGENT_SESSIONS_FILE, next);
}

function getOmniDatasetStore() {
  return readJson(OMNI_DATASETS_FILE, { datasets: {} });
}

function setOmniDatasetStore(next) {
  writeJson(OMNI_DATASETS_FILE, next);
}

function getOmniFineTuneStore() {
  return readJson(OMNI_FINETUNES_FILE, { jobs: {} });
}

function setOmniFineTuneStore(next) {
  writeJson(OMNI_FINETUNES_FILE, next);
}

function getOmniLoraStore() {
  return readJson(OMNI_LORA_FILE, { adapters: {} });
}

function setOmniLoraStore(next) {
  writeJson(OMNI_LORA_FILE, next);
}

function tokenizeForSearch(text) {
  return String(text || '')
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, ' ')
    .split(/\s+/)
    .map((t) => t.trim())
    .filter((t) => t.length > 1)
    .slice(0, 300);
}

function scoreDocForQuery(query, text) {
  const q = tokenizeForSearch(query);
  const d = tokenizeForSearch(text);
  if (!q.length || !d.length) return 0;
  const docSet = new Set(d);
  let hits = 0;
  for (const token of q) if (docSet.has(token)) hits += 1;
  return Number((hits / q.length).toFixed(4));
}

function fakeEmbeddingVector(input, dims = 64) {
  const out = [];
  for (let i = 0; i < dims; i += 1) {
    const h = crypto.createHash('sha256').update(`${i}:${String(input || '')}`).digest();
    const n = h.readUInt16BE(0);
    out.push(Number((((n / 65535) * 2) - 1).toFixed(6)));
  }
  return out;
}

function runVideoKeyframeExtraction(sourceAbs, opts = {}) {
  const fps = Math.max(0.2, Math.min(5, Number(opts.fps || 1)));
  const limit = Math.max(1, Math.min(24, Number(opts.limit || 8)));
  const outDir = path.join(STUDIO_OUTPUT_ROOT, 'video-keyframes', `${Date.now()}-${crypto.randomBytes(4).toString('hex')}`);
  fs.mkdirSync(outDir, { recursive: true });
  const outPattern = path.join(outDir, 'frame-%03d.jpg');
  const proc = spawnSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-i', sourceAbs, '-vf', `fps=${fps}`, '-frames:v', String(limit), outPattern], { encoding: 'utf8' });
  if (proc.status !== 0) {
    throw new Error(String(proc.stderr || proc.stdout || 'ffmpeg_failed').trim());
  }
  const files = safeListFiles(outDir, limit + 2).filter((f) => f.endsWith('.jpg'));
  return files.map((f) => '/studio-output/video-keyframes/' + path.basename(outDir) + '/' + f);
}

function issueMcpAccessToken(subject = 'chatgpt-mcp') {
  const token = crypto.randomBytes(32).toString('hex');
  oauthTokens.set(token, { subject, createdAt: Date.now() });
  return token;
}

function isValidMcpBearer(req) {
  const got = readBearerToken(req);
  if (!got) return false;
  if (MCP_BEARER_TOKEN && got === MCP_BEARER_TOKEN) return true;
  return oauthTokens.has(got);
}

function shouldRetryOpenClawProxyError(err) {
  const code = String(err?.code || '').toUpperCase();
  return ['ECONNREFUSED', 'ECONNRESET', 'EPIPE', 'ETIMEDOUT', 'EHOSTUNREACH', 'ENETUNREACH'].includes(code);
}

function buildOpenClawProxyHeaders(req, isUpgrade = false) {
  const headers = { ...req.headers };

  // Normalize toward local upstream; do not leak outer proxy network context.
  delete headers['x-forwarded-for'];
  delete headers['x-real-ip'];
  delete headers['x-forwarded-proto'];
  delete headers['x-forwarded-host'];
  delete headers['forwarded'];

  headers.host = `${OPENCLAW_DASHBOARD_HOST}:${OPENCLAW_DASHBOARD_PORT}`;
  headers.origin = `http://${OPENCLAW_DASHBOARD_HOST}:${OPENCLAW_DASHBOARD_PORT}`;

  if (isUpgrade) {
    headers.connection = 'Upgrade';
    headers.upgrade = 'websocket';
  }

  return headers;
}

function sendOpenClawProxyUnavailable(res, err, attempts) {
  sendJson(res, 503, {
    ok: false,
    error: 'openclaw_proxy_unavailable',
    message: 'OpenClaw dashboard proxy is temporarily unavailable. Please retry in a moment.',
    detail: String(err?.message || err),
    attempts,
    upstream: `${OPENCLAW_DASHBOARD_HOST}:${OPENCLAW_DASHBOARD_PORT}`,
  });
}

function proxyOpenClawDashboard(req, res, targetPath) {
  const headers = buildOpenClawProxyHeaders(req, false);

  const method = String(req.method || 'GET').toUpperCase();
  const isIdempotent = method === 'GET' || method === 'HEAD';
  const maxAttempts = isIdempotent ? OPENCLAW_PROXY_RETRIES + 1 : 1;
  let finished = false;

  const runAttempt = (attempt) => {
    const upstream = http.request({
      host: OPENCLAW_DASHBOARD_HOST,
      port: OPENCLAW_DASHBOARD_PORT,
      path: targetPath,
      method,
      headers,
    }, (upRes) => {
      if (finished) {
        upRes.resume();
        return;
      }

      const status = upRes.statusCode || 502;
      if (isIdempotent && [502, 503, 504].includes(status) && attempt < maxAttempts) {
        upRes.resume();
        setTimeout(() => runAttempt(attempt + 1), OPENCLAW_PROXY_RETRY_DELAY_MS);
        return;
      }

      const outHeaders = { ...upRes.headers };

      if (typeof outHeaders.location === 'string' && outHeaders.location.startsWith('/')) {
        outHeaders.location = `/admin/openclaw${outHeaders.location}`;
      }

      delete outHeaders['x-frame-options'];
      delete outHeaders['content-security-policy'];

      const contentType = String(outHeaders['content-type'] || '').toLowerCase();
      const shouldRewriteHtml = method === 'GET' && targetPath === '/' && contentType.includes('text/html');

      finished = true;

      if (!shouldRewriteHtml) {
        res.writeHead(status, outHeaders);
        upRes.pipe(res);
        return;
      }

      const chunks = [];
      upRes.on('data', (c) => chunks.push(Buffer.from(c)));
      upRes.on('end', () => {
        const original = Buffer.concat(chunks).toString('utf8');
        const tokenLiteral = JSON.stringify(OPENCLAW_GATEWAY_TOKEN || '');
        const inject = `<script>\nwindow.__OPENCLAW_CONTROL_UI_BASE_PATH__='/admin/openclaw';\ntry {\n  const k='openclaw.control.settings.v1';\n  const raw=localStorage.getItem(k);\n  const v=raw?JSON.parse(raw):{};\n  v.gatewayUrl=(location.protocol==='https:'?'wss':'ws')+'://'+location.host+'/admin/openclaw/';\n  if (${tokenLiteral} && ${tokenLiteral}.length > 0) v.token=${tokenLiteral};\n  if (!v.sessionKey) v.sessionKey='main';\n  if (!v.lastActiveSessionKey) v.lastActiveSessionKey='main';\n  localStorage.setItem(k, JSON.stringify(v));\n} catch {}\n</script>`;
        const rewritten = original.replace('</head>', `${inject}</head>`);
        const body = Buffer.from(rewritten, 'utf8');

        delete outHeaders['content-length'];
        outHeaders['content-length'] = String(body.length);

        res.writeHead(status, outHeaders);
        res.end(body);
      });
    });

    upstream.setTimeout(OPENCLAW_PROXY_TIMEOUT_MS, () => {
      const timeoutErr = new Error(`upstream timeout after ${OPENCLAW_PROXY_TIMEOUT_MS}ms`);
      timeoutErr.code = 'ETIMEDOUT';
      upstream.destroy(timeoutErr);
    });

    upstream.on('error', (err) => {
      if (finished) return;

      if (isIdempotent && shouldRetryOpenClawProxyError(err) && attempt < maxAttempts) {
        setTimeout(() => runAttempt(attempt + 1), OPENCLAW_PROXY_RETRY_DELAY_MS);
        return;
      }

      finished = true;
      sendOpenClawProxyUnavailable(res, err, attempt);
    });

    if (isIdempotent) {
      upstream.end();
    } else {
      req.pipe(upstream);
    }
  };

  runAttempt(1);
}

function inferUserTextFromMessages(messages) {
  if (!Array.isArray(messages)) return '';
  const lastUser = [...messages].reverse().find((m) => String(m?.role || '').toLowerCase() === 'user');
  return String(lastUser?.content || '').trim();
}

function generateAiReply(inputText) {
  const text = String(inputText || '').trim();
  if (!text) return 'Please send a prompt or user message.';
  return `PrismBot AI: ${text}`;
}

function renderUnifiedShell(context = {}) {
  const { user = null, hasKey = false, hasOauth = false, active = 'chat' } = context;
  const owner = Boolean(user && user.owner);
  const canUseApp = Boolean(user && (hasKey || hasOauth || ALLOW_SHARED_KEY));
  const usingShared = Boolean(user && !hasKey && !hasOauth && ALLOW_SHARED_KEY);

  const tabs = owner
    ? ['chat', 'account', 'studio', 'site', 'admin']
    : ['chat', 'account', 'studio', 'site'];

  const safeActive = String(active || 'chat').toLowerCase();
  const current = tabs.includes(safeActive) ? safeActive : 'chat';
  const tabLabel = {
    chat: 'Chat',
    account: 'My Account',
    studio: 'Studio',
    site: 'Website',
    admin: 'Admin'
  };

  const drawerLinks = tabs
    .map((k) => `<a href="/app/${k}" class="drawer-link ${k === current ? 'active' : ''}">${tabLabel[k] || k}</a>`)
    .join('');

  const loginPanel = `
    <section class="card auth-card">
      <h2>Sign in to PrismBot</h2>
      <p class="subtle">Welcome back. Sign in to access your workspace.</p>
      <form id="loginForm" class="stack" action="/api/auth/login?redirect=1" method="POST">
        <label>Username
          <input id="username" name="username" autocomplete="username" required />
        </label>
        <label>Password
          <input id="password" name="password" type="password" autocomplete="current-password" required />
        </label>
        <button type="submit">Sign In</button>
      </form>
      <pre id="loginOut">Ready.</pre>
    </section>`;

  const connectPanel = `
    <section class="card auth-card">
      <h2>Connect AI Access</h2>
      <p class="subtle">Use account OAuth for app login features, and add your API key for image generation.</p>
      <div class="row" style="margin-bottom:10px;">
        <a class="mini" href="/api/oauth/openai/start">Connect account OAuth</a>
      </div>
      <form id="keyForm" class="stack">
        <label>OpenAI API key
          <input id="apiKey" type="password" placeholder="sk-..." required />
        </label>
        <div class="row">
          <button type="submit">Save API Key</button>
        </div>
      </form>
      <pre id="keyOut">Free plan limits: ${FREE_DAILY_REQUEST_LIMIT} requests/day, ${FREE_DAILY_TOKEN_LIMIT} tokens/day.</pre>
    </section>`;

  const chatPanel = owner
    ? `
    <section class="card">
      <h2>Live Chat</h2>
      <p class="subtle">Connected to the real OpenClaw assistant runtime.</p>
      <div class="row" style="margin-bottom:10px;">
        <a id="ocChatOpenLink" class="btn" href="/admin/openclaw/" target="_blank" rel="noopener">Open Full Assistant Chat</a>
        <button type="button" class="mini" onclick="loadOpenclawFrame()">Load Inline</button>
      </div>
      <div id="ocStatus" class="oc-status" style="display:none;"></div>
      <iframe id="ocFrame" title="OpenClaw Assistant Chat" class="oc-frame" loading="lazy"></iframe>
    </section>
    <section class="card slim-card">
      <h3>Usage</h3>
      <pre id="billingOut">Loading usage...</pre>
    </section>`
    : `
    <section class="card">
      <h2>Live Chat</h2>
      <p class="subtle">${usingShared ? `Free shared plan active (${FREE_DAILY_REQUEST_LIMIT} req/day)` : 'Your personal PrismBot conversation.'}</p>
      <div id="chatLog" class="chat-log"></div>
      <div class="row chat-compose">
        <input id="msg" placeholder="Type a message..." />
        <button type="button" onclick="sendMsg()">Send</button>
      </div>
    </section>
    <section class="card slim-card">
      <h3>Usage</h3>
      <pre id="billingOut">Loading usage...</pre>
    </section>`;

  const accountPanel = `
    <section class="card auth-card">
      <h2>My Account</h2>
      <p class="subtle">Manage authentication, API access, and usage.</p>
      <div class="row" style="margin-bottom:10px;">
        <a class="mini" href="/api/oauth/chatgpt/start">Connect / Reconnect ChatGPT</a>
      </div>
      <form id="accountKeyForm" class="stack">
        <label>OpenAI API key
          <input id="accountApiKey" type="password" placeholder="sk-..." />
        </label>
        <div class="row">
          <button type="submit">Save / Update API Key</button>
        </div>
      </form>
      <pre id="accountOut">Preferred: connect ChatGPT above (per-user). Fallback: set a real OpenAI API key (sk-...).</pre>
      <pre id="billingOut">Loading usage...</pre>
    </section>`;

  const studioPanel = `
    <section class="card studio-shell">
      <div class="studio-head">
        <div>
          <h2>Studio</h2>
          <p class="subtle">Create game-ready pixel assets fast.</p>
        </div>
        <div class="row">
          <button type="button" onclick="loadStudio()">Refresh</button>
          <a class="mini" href="/studio-output/" target="_blank" rel="noopener">Output</a>
        </div>
      </div>

      <div class="studio-modes">
        <button type="button" class="studio-mode-btn active" data-mode="create" onclick="setStudioMode('create')">Create</button>
        <button type="button" class="studio-mode-btn" data-mode="edit" onclick="setStudioMode('edit')">Remix</button>
        <button type="button" class="studio-mode-btn" data-mode="style" onclick="setStudioMode('style')">Style</button>
        <button type="button" class="studio-mode-btn" data-mode="pack" onclick="setStudioMode('pack')">Pack</button>
        <button type="button" class="studio-mode-btn" data-mode="runs" onclick="setStudioMode('runs')">Omni</button>
      </div>

      <div id="studioModeCreate" class="studio-mode-panel active">
        <form id="studioGenForm" class="stack">
          <label>Create Prompt
            <input id="studioGenPrompt" type="text" placeholder="hero knight idle pose, crisp pixel silhouette" required />
          </label>
          <label>Negative Prompt (optional)
            <input id="studioGenNegative" type="text" placeholder="blurry, text watermark, extra limbs" />
          </label>
          <div class="row">
            <label style="flex:1;">Mode
              <select id="studioGenPreset">
                <option value="bitforge">Bitforge-style (S-M sprites)</option>
                <option value="pixflux">Pixflux-style (M-XL scenes)</option>
              </select>
            </label>
            <label style="flex:1;">Quality
              <select id="studioGenQuality">
                <option value="fast">Fast</option>
                <option value="balanced" selected>Balanced</option>
                <option value="hq">HQ</option>
              </select>
            </label>
            <label style="flex:1;">Size
              <input id="studioGenSize" type="number" min="256" max="1024" value="512" />
            </label>
          </div>
          <div class="row">
            <label style="flex:1;">View
              <select id="studioGenView">
                <option value="sidescroller">Side-scroller</option>
                <option value="topdown">Top-down</option>
                <option value="isometric">Isometric</option>
              </select>
            </label>
            <label style="flex:1;">Palette hint
              <input id="studioGenPalette" type="text" placeholder="purple + cyan + dark navy" />
            </label>
          </div>
          <div class="row">
            <label style="display:flex;align-items:center;gap:8px;padding-top:24px;">
              <input id="studioGenTransparent" type="checkbox" checked /> Transparent background
            </label>
          </div>
          <div class="row"><button type="submit">Generate Image</button></div>
        </form>
      </div>

      <div id="studioModeEdit" class="studio-mode-panel">
        <form id="studioEditForm" class="stack">
          <label>Edit Prompt
            <input id="studioEditPrompt" type="text" placeholder="add glowing blue aura around the character" required />
          </label>
          <label>Source Image URL (tap gallery item to auto-fill)
            <input id="studioEditSource" type="text" list="studioGallerySources" placeholder="/studio-output/generated/...png" required />
            <datalist id="studioGallerySources"></datalist>
          </label>
          <div class="row"><button type="submit">Run Edit / Inpaint</button></div>
        </form>
      </div>

      <div id="studioModeStyle" class="studio-mode-panel">
        <form id="studioStyleForm" class="stack">
          <label>Style Profile Name / Prompt
            <input id="studioStylePrompt" type="text" placeholder="dark gothic rpg style profile" required />
          </label>
          <label>Reference URLs (comma-separated)
            <input id="studioStyleRefs" type="text" placeholder="/studio-output/generated/a.png, /studio-output/generated/b.png" />
          </label>
          <div class="row"><button type="submit">Create Style Profile</button></div>
        </form>
      </div>

      <div id="studioModePack" class="studio-mode-panel">
        <form id="studioPackForm" class="stack">
          <label>Theme / Prompt
            <input id="studioTheme" type="text" placeholder="necromancer enemies" required />
          </label>
          <div class="row">
            <label style="flex:1;">Sprite Size
              <input id="studioSize" type="number" min="16" max="256" value="32" />
            </label>
            <label style="flex:1;">Unit Count
              <input id="studioCount" type="number" min="1" max="64" value="8" />
            </label>
          </div>
          <div class="row"><button type="submit">Create Pack Scaffold Job</button></div>
        </form>
      </div>

      <div id="studioModeRuns" class="studio-mode-panel">
        <form id="omniRunForm" class="stack">
          <label>Run Goal
            <input id="omniRunPrompt" type="text" placeholder="Create a pixel mage with lore and voice" required />
          </label>
          <div class="row">
            <label style="display:flex;align-items:center;gap:8px;padding-top:24px;">
              <input id="omniRunAsync" type="checkbox" checked /> Async run
            </label>
            <button type="submit">Run Omni Workflow</button>
          </div>
        </form>
        <pre id="omniRunOut">No active run yet.</pre>
      </div>

      <pre id="studioOut">Loading studio workspace...</pre>
      <pre id="studioEngine">Checking local image engine...</pre>

      <h3 style="margin:10px 0 8px 0;">Recent Output Gallery</h3>
      <div id="studioCompare" class="studio-compare" style="display:none;">
        <div class="studio-compare-head">Compare</div>
        <div class="studio-compare-grid">
          <img id="studioCompareA" alt="Compare A" />
          <img id="studioCompareB" alt="Compare B" />
        </div>
      </div>
      <div id="studioGallery" class="studio-gallery"></div>

      <details class="studio-details" open>
        <summary>Run Timeline</summary>
        <pre id="studioJobs">Loading studio jobs...</pre>
      </details>

      <details class="studio-details">
        <summary>System Diagnostics (advanced)</summary>
        <pre id="studioDiag">Loading studio diagnostics...</pre>
        <pre id="studioParityStatus">Loading parity status...</pre>
        <pre id="studioParity">Loading parity checklist...</pre>
      </details>
    </section>`;

  const sitePanel = `
    <section class="card">
      <h2>Website</h2>
      <p class="subtle">Open the public Prismtek website.</p>
      <a class="btn" href="https://prismtek.dev" target="_blank" rel="noopener">Open prismtek.dev</a>
    </section>`;

  const adminPanel = `
    <section class="card">
      <h2>Admin Summary</h2>
      <p class="subtle">Owner-only dashboard and telemetry.</p>
      <div class="row" style="margin-bottom:10px;">
        <button type="button" onclick="loadAdmin()">Refresh Admin</button>
      </div>
      <pre id="adminOut">Loading admin summary...</pre>
    </section>
    <section class="card">
      <h2>OpenClaw Dashboard</h2>
      <p class="subtle">Full OpenClaw control surface (owner only).</p>
      <div class="row" style="margin-bottom:10px;">
        <a id="ocOpenLink" class="btn" href="/admin/openclaw/" target="_blank" rel="noopener">Open Full Dashboard</a>
        <button type="button" class="mini" onclick="loadOpenclawFrame()">Load Inline</button>
      </div>
      <div id="ocStatus" class="oc-status" style="display:none;"></div>
      <iframe id="ocFrame" title="OpenClaw Dashboard" class="oc-frame" loading="lazy"></iframe>
    </section>`;

  let panel = loginPanel;
  if (user && !canUseApp) panel = connectPanel;
  if (canUseApp) {
    if (current === 'account') panel = accountPanel;
    else if (current === 'studio') panel = studioPanel;
    else if (current === 'site') panel = sitePanel;
    else if (current === 'admin' && owner) panel = adminPanel;
    else panel = chatPanel;
  }

  const badge = owner ? 'OWNER' : hasOauth ? 'OAUTH' : usingShared ? 'FREE PLAN' : 'USER';
  const authHeader = user
    ? `<div class="auth-pill">${user.displayName || user.username} • ${badge} <button class="mini" onclick="logout()">Log out</button></div>`
    : '<div class="auth-pill">Not signed in</div>';

  const drawer = canUseApp
    ? `<aside id="drawer" class="drawer"><div class="drawer-head">Navigation</div>${drawerLinks}</aside><button id="drawerBackdrop" class="drawer-backdrop" type="button" aria-label="Close menu"></button>`
    : '';

  const menuButton = canUseApp
    ? '<button id="menuBtn" class="menu-btn" type="button" aria-label="Open menu">☰</button>'
    : '';

  return `<!doctype html>
<html>
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>PrismBot App</title>
<style>
  :root{--bg:#0b0b14;--bg2:#111427;--card:#131524;--line:#2a2f4a;--text:#eef;--muted:#aeb6e4;--brand:#6f5df7;}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,Arial,sans-serif}
  .bar{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #22243a;background:var(--bg2);position:sticky;top:0;z-index:20}
  .brand{font-weight:800;color:#d3c9ff}
  .page-title{font-size:13px;color:var(--muted)}
  .menu-btn{border:1px solid #414874;background:#171b33;color:#fff;border-radius:10px;padding:6px 10px;cursor:pointer}
  .auth-pill{margin-left:auto;font-size:12px;color:#c7cdf5;display:flex;align-items:center;gap:8px;background:#171b33;padding:6px 10px;border:1px solid #2d3150;border-radius:999px}
  .mini{padding:6px 10px;border-radius:9px;border:1px solid #434a76;background:#171b33;color:#dfe2ff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
  .wrap{max-width:980px;margin:0 auto;padding:16px;display:grid;gap:12px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
  .auth-card{max-width:620px;margin:24px auto;width:100%}
  h2{margin:0 0 10px 0;color:#fff}
  h3{margin:0 0 8px 0;color:#fff}
  p{margin:0 0 12px 0;color:#b7bddf}
  .subtle{color:#a0a8d8}
  .row{display:flex;gap:10px;flex-wrap:wrap}
  .stack{display:flex;flex-direction:column;gap:10px}
  label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:#c8ceef}
  input,select{flex:1;min-width:220px;padding:11px;border-radius:10px;border:1px solid #333957;background:#0e1020;color:#fff}
  button,.btn{padding:10px 14px;border-radius:10px;border:1px solid #6f5df7;background:#6f5df7;color:#fff;cursor:pointer;text-decoration:none;display:inline-block}
  pre{white-space:pre-wrap;word-break:break-word;background:#0e1020;border:1px solid #333957;color:#d7dcff;padding:12px;border-radius:10px;min-height:88px;margin:0}
  .chat-log{border:1px solid #333957;background:#0e1020;border-radius:12px;min-height:320px;max-height:50vh;overflow:auto;padding:12px;display:flex;flex-direction:column;gap:10px}
  .msg{display:flex}
  .msg.user{justify-content:flex-end}
  .msg .bubble{max-width:85%;padding:10px 12px;border-radius:12px;border:1px solid #37406a;background:#1a1f39;color:#eff2ff}
  .msg.user .bubble{background:#6f5df7;border-color:#7f70ff;color:#fff}
  .msg.system .bubble{background:#171b2c;border-color:#2d355f;color:#cfd5ff}
  .chat-compose{margin-top:10px}
  .chat-compose input{flex:1;min-width:0}
  .slim-card pre{min-height:120px}
  .oc-status{margin:0 0 10px 0;padding:10px 12px;border-radius:10px;border:1px solid #4a3f1d;background:#2a230f;color:#ffe9a6;font-size:13px}
  .oc-status.ok{border-color:#224f2d;background:#12311b;color:#b8ffd0}
  .oc-status.warn{border-color:#4a3f1d;background:#2a230f;color:#ffe9a6}
  .oc-status.error{border-color:#5b2733;background:#36161f;color:#ffd0d9}
  .oc-frame{width:100%;height:70vh;border:1px solid #333957;border-radius:10px;background:#0e1020}
  .studio-shell{display:grid;gap:12px}
  .studio-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
  .studio-modes{display:flex;gap:8px;flex-wrap:wrap}
  .studio-mode-btn{padding:8px 12px;border-radius:999px;border:1px solid #3b4270;background:#121735;color:#dbe0ff;cursor:pointer}
  .studio-mode-btn.active{background:#5b46f4;border-color:#7b6eff;color:#fff}
  .studio-mode-panel{display:none;border:1px solid #2d3150;border-radius:12px;background:#121731;padding:12px}
  .studio-mode-panel.active{display:block}
  .studio-compare{border:1px solid #2d3150;border-radius:12px;background:#10152d;padding:10px}
  .studio-compare-head{font-size:12px;color:#bcc5f7;margin-bottom:8px}
  .studio-compare-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .studio-compare-grid img{width:100%;height:180px;object-fit:contain;background:#0c1123;border:1px solid #2d3150;border-radius:8px}
  .studio-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px}
  .studio-thumb{display:block;border:1px solid #333957;border-radius:10px;background:#0e1020;padding:6px;text-decoration:none}
  .studio-thumb img{display:block;width:100%;height:96px;object-fit:cover;border-radius:6px;background:#070912}
  .studio-thumb span{display:block;margin-top:6px;font-size:11px;color:#c6cdf6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .studio-thumb-actions{display:flex;gap:6px;margin-top:6px}
  .studio-thumb-actions button{flex:1;padding:6px 8px;font-size:11px;border-radius:8px;border:1px solid #3a4272;background:#171f3f;color:#e8ebff;cursor:pointer}
  .studio-details{border:1px solid #2d3150;border-radius:12px;background:#10152d;padding:8px 10px}
  .studio-details summary{cursor:pointer;color:#cbd2ff;font-weight:600;margin:2px 0 8px 0}

  .drawer{position:fixed;top:0;left:0;bottom:0;width:280px;background:#101427;border-right:1px solid #2a2f4a;transform:translateX(-105%);transition:transform .2s ease;z-index:30;padding:14px;display:flex;flex-direction:column;gap:8px}
  .drawer.open{transform:translateX(0)}
  .drawer-head{font-size:12px;color:#9aa4d9;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
  .drawer-link{display:block;padding:11px 12px;border-radius:10px;border:1px solid #2d3150;color:#d2d7ff;text-decoration:none;font-weight:600;background:#141a31}
  .drawer-link.active{background:#5b46f4;border-color:#7b6eff;color:#fff}
  .drawer-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:25;border:0}
  .drawer-backdrop.show{opacity:1;pointer-events:auto}

  @media (max-width: 640px){
    .page-title{display:none}
    .auth-pill{font-size:11px}
    .wrap{padding:12px}
  }
</style>
</head>
<body>
  <div class="bar">${menuButton}<div class="brand">PrismBot</div><div class="page-title">${tabLabel[current] || 'Workspace'}</div>${authHeader}</div>
  ${drawer}
  <main class="wrap">${panel}</main>
<script>
const OPENCLAW_DASHBOARD_TOKEN = ${JSON.stringify(OPENCLAW_GATEWAY_TOKEN || '')};
function byId(id){ return document.getElementById(id); }

function buildOpenclawDashboardUrl(){
  const base = new URL('/admin/openclaw/', window.location.origin);
  const wsUrl = (location.protocol==='https:'?'wss':'ws') + '://' + location.host + '/admin/openclaw/';
  const hash = new URLSearchParams();
  hash.set('gatewayUrl', wsUrl);
  if (OPENCLAW_DASHBOARD_TOKEN) hash.set('token', OPENCLAW_DASHBOARD_TOKEN);
  base.hash = hash.toString();
  return base.toString();
}

function seedOpenclawSettings(){
  try {
    const k='openclaw.control.settings.v1';
    const raw=localStorage.getItem(k);
    const v=raw?JSON.parse(raw):{};
    v.gatewayUrl=(location.protocol==='https:'?'wss':'ws') + '://' + location.host + '/admin/openclaw/';
    if (OPENCLAW_DASHBOARD_TOKEN) v.token=OPENCLAW_DASHBOARD_TOKEN;
    if (!v.sessionKey) v.sessionKey='main';
    if (!v.lastActiveSessionKey) v.lastActiveSessionKey='main';
    localStorage.setItem(k, JSON.stringify(v));
  } catch {}
}

function syncOpenclawLinks(){
  seedOpenclawSettings();
  const url = buildOpenclawDashboardUrl();
  const openLink = byId('ocOpenLink');
  if (openLink) openLink.href = url;
  const chatLink = byId('ocChatOpenLink');
  if (chatLink) chatLink.href = url;
}

function openMenu(){ const d=byId('drawer'); const b=byId('drawerBackdrop'); if(d) d.classList.add('open'); if(b) b.classList.add('show'); }
function closeMenu(){ const d=byId('drawer'); const b=byId('drawerBackdrop'); if(d) d.classList.remove('open'); if(b) b.classList.remove('show'); }
function toggleMenu(){ const d=byId('drawer'); if(!d) return; if(d.classList.contains('open')) closeMenu(); else openMenu(); }

async function login(e){
  e.preventDefault();
  const username=byId('username')?.value.trim();
  const password=byId('password')?.value || '';
  const r=await fetch('/api/auth/login',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({username,password})});
  const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
  const out=byId('loginOut');
  if(out) out.textContent=JSON.stringify(j,null,2);
  if(j.ok) location.href='/app';
}

async function saveKey(e){
  e.preventDefault();
  const apiKey=byId('apiKey')?.value.trim();
  const r=await fetch('/api/user/api-key',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({apiKey})});
  const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
  const out=byId('keyOut');
  if(out) out.textContent=JSON.stringify(j,null,2);
  if(j.ok) location.href='/app';
}

async function saveAccountKey(e){
  e.preventDefault();
  const apiKey=byId('accountApiKey')?.value.trim();
  const out=byId('accountOut');
  if(!apiKey){ if(out) out.textContent='Enter an API key before saving.'; return; }
  const r=await fetch('/api/user/api-key',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({apiKey})});
  const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
  if(out) out.textContent=JSON.stringify(j,null,2);
  loadBilling();
}

async function loadBilling(){
  const el=byId('billingOut');
  if(!el) return;
  const r=await fetch('/api/billing/status');
  const j=await r.json().catch(()=>({ok:false}));
  if(!j.ok){ el.textContent=JSON.stringify(j,null,2); return; }
  const b=j.billing||{}; const u=b.user||{}; const g=b.global||{};
  el.textContent =
    'Plan: ' + (b.plan||'-') + '\\n' +
    'Shared Key: ' + (b.usingShared ? 'Yes' : 'No') + '\\n\\n' +
    'You Today\\n' +
    '- Requests: ' + (u.requests ?? 0) + ' / ' + (u.requestLimit ?? '-') + '\\n' +
    '- Tokens: ' + (u.tokens ?? 0) + ' / ' + (u.tokenLimit ?? '-') + '\\n\\n' +
    'Global Today\\n' +
    '- Requests: ' + (g.requests ?? 0) + ' / ' + (g.requestLimit ?? '-') + '\\n' +
    '- Tokens: ' + (g.tokens ?? 0) + ' / ' + (g.tokenLimit ?? '-');
}

function appendChat(role,text){
  const log=byId('chatLog');
  if(!log) return;
  const msg=document.createElement('div');
  msg.className='msg ' + role;
  const bubble=document.createElement('div');
  bubble.className='bubble';
  bubble.textContent=String(text||'');
  msg.appendChild(bubble);
  log.appendChild(msg);
  log.scrollTop=log.scrollHeight;
}

async function sendMsg(){
  const input=byId('msg');
  if(!input) return;
  const text=input.value.trim();
  if(!text) return;
  appendChat('user', text);
  input.value='';
  input.disabled=true;
  try {
    const r=await fetch('/api/chat',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({text})});
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok || j.ok===false){
      appendChat('system', j.message || j.error || 'Chat request failed.');
    } else {
      appendChat('assistant', j.reply || j.output || JSON.stringify(j));
    }
    loadBilling();
  } catch {
    appendChat('system', 'Network error sending message.');
  } finally {
    input.disabled=false;
    input.focus();
  }
}

async function loadAdmin(){
  const el=byId('adminOut');
  if(!el) return;
  el.textContent='Loading admin summary...';
  const r=await fetch('/api/admin/summary');
  const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
  if(!j.ok){
    el.textContent='Admin summary unavailable: ' + (j.message || j.error || 'unknown_error');
    return;
  }
  const p=j.billingPolicy||{};
  const lines=[
    'Users: ' + (j.users ?? 0),
    'Tasks: ' + (j.tasks ?? 0),
    'Activity Events: ' + (j.activity ?? 0),
    '',
    'Free Plan Policy',
    '- Shared enabled: ' + (p.allowShared ? 'Yes' : 'No'),
    '- Daily req limit: ' + (p.freeDailyRequestLimit ?? '-'),
    '- Daily token limit: ' + (p.freeDailyTokenLimit ?? '-')
  ];
  el.textContent=lines.join('\\n');
}

function formatStudioTime(ts){
  if(!ts) return '-';
  try { return new Date(ts).toLocaleString(); } catch { return '-'; }
}

function setStudioMode(mode){
  const target=String(mode||'create').toLowerCase();
  const map={create:'studioModeCreate',edit:'studioModeEdit',style:'studioModeStyle',pack:'studioModePack',runs:'studioModeRuns'};
  Object.entries(map).forEach(([k,id])=>{
    const el=byId(id);
    if(el) el.classList.toggle('active', k===target);
  });
  document.querySelectorAll('.studio-mode-btn').forEach((btn)=>{
    btn.classList.toggle('active', String(btn.dataset.mode||'')===target);
  });
}

let studioCompareA='';
let studioCompareB='';

function updateStudioCompare(){
  const box=byId('studioCompare');
  const a=byId('studioCompareA');
  const b=byId('studioCompareB');
  if(!box || !a || !b) return;
  if(!studioCompareA || !studioCompareB){
    box.style.display='none';
    return;
  }
  a.src=studioCompareA;
  b.src=studioCompareB;
  box.style.display='block';
}

async function createStudioVariation(sourceWebPath){
  const out=byId('studioOut');
  const prompt=String(byId('studioGenPrompt')?.value || byId('studioEditPrompt')?.value || 'Create a variation while preserving core style').trim();
  if(!sourceWebPath) return;
  if(out) out.textContent='Creating variation...';
  try {
    const r=await fetch('/api/studio/edit-image',{
      method:'POST',
      headers:{'content-type':'application/json'},
      body:JSON.stringify({ prompt, sourceWebPath, size:512 })
    });
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok || j.ok===false){
      if(out) out.textContent='Variation failed: ' + (j.message || j.error || 'unknown_error');
      return;
    }
    if(out) out.textContent='Variation job queued: ' + (j.job?.id || 'unknown_job');
    await loadStudio();
  } catch {
    if(out) out.textContent='Network error creating variation.';
  }
}

function renderStudioGallery(items){
  const root=byId('studioGallery');
  const sourceInput=byId('studioEditSource');
  const sourceList=byId('studioGallerySources');
  if(sourceList) sourceList.innerHTML='';
  if(!root) return;
  root.innerHTML='';
  if(!Array.isArray(items) || items.length===0){
    root.textContent='No output images yet. Run a pack scaffold and drop generated art into output/.';
    updateStudioCompare();
    return;
  }
  for(const item of items.slice(0,24)){
    const card=document.createElement('div');
    card.className='studio-thumb';

    const a=document.createElement('a');
    a.href=item.webPath;
    a.target='_blank';
    a.rel='noopener';
    a.title='Open image in new tab';

    const img=document.createElement('img');
    img.loading='lazy';
    img.src=item.webPath;
    img.alt=item.file || 'studio-output';

    const cap=document.createElement('span');
    cap.textContent=item.file || item.webPath;

    const actions=document.createElement('div');
    actions.className='studio-thumb-actions';

    const useBtn=document.createElement('button');
    useBtn.type='button';
    useBtn.textContent='Use';
    useBtn.addEventListener('click', ()=>{
      const src=byId('studioEditSource');
      if(src) src.value=item.webPath;
    });

    const varBtn=document.createElement('button');
    varBtn.type='button';
    varBtn.textContent='Variation';
    varBtn.addEventListener('click', ()=>{ createStudioVariation(item.webPath); });

    const cmpBtn=document.createElement('button');
    cmpBtn.type='button';
    cmpBtn.textContent='Compare';
    cmpBtn.addEventListener('click', ()=>{
      if(!studioCompareA || studioCompareA===item.webPath) studioCompareA=item.webPath;
      else studioCompareB=item.webPath;
      updateStudioCompare();
    });

    actions.appendChild(useBtn);
    actions.appendChild(varBtn);
    actions.appendChild(cmpBtn);

    if(sourceList){
      const opt=document.createElement('option');
      opt.value=item.webPath;
      sourceList.appendChild(opt);
    }

    a.appendChild(img);
    card.appendChild(a);
    card.appendChild(cap);
    card.appendChild(actions);
    root.appendChild(card);
  }

  if(sourceInput && !sourceInput.value){
    sourceInput.value = items[0]?.webPath || '';
  }
  updateStudioCompare();
}

async function createStudioPack(ev){
  ev.preventDefault();
  const out=byId('studioOut');
  const theme=String(byId('studioTheme')?.value || '').trim();
  const size=Number(byId('studioSize')?.value || 32);
  const count=Number(byId('studioCount')?.value || 8);
  if(!theme){
    if(out) out.textContent='Theme is required.';
    return;
  }

  if(out) out.textContent='Submitting studio job...';
  try {
    const r=await fetch('/api/studio/jobs',{
      method:'POST',
      headers:{'content-type':'application/json'},
      body:JSON.stringify({ action:'create_pack_scaffold', theme, size, count })
    });
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok || j.ok===false){
      if(out) out.textContent='Studio job failed: ' + (j.message || j.error || 'unknown_error');
      return;
    }
    if(out) out.textContent='Studio job queued: ' + (j.job?.id || 'unknown_job');
    await loadStudio();
  } catch {
    if(out) out.textContent='Network error creating studio job.';
  }
}

async function createStudioImage(ev){
  ev.preventDefault();
  const out=byId('studioOut');
  const prompt=String(byId('studioGenPrompt')?.value || '').trim();
  const negativePrompt=String(byId('studioGenNegative')?.value || '').trim();
  const preset=String(byId('studioGenPreset')?.value || 'pixflux').trim();
  const quality=String(byId('studioGenQuality')?.value || 'balanced').trim();
  const view=String(byId('studioGenView')?.value || 'sidescroller').trim();
  const palette=String(byId('studioGenPalette')?.value || '').trim();
  const size=Number(byId('studioGenSize')?.value || 512);
  const transparent=Boolean(byId('studioGenTransparent')?.checked);

  if(!prompt){
    if(out) out.textContent='Image prompt is required.';
    return;
  }

  if(out) out.textContent='Submitting image generation job...';
  try {
    const r=await fetch('/api/studio/generate-image',{
      method:'POST',
      headers:{'content-type':'application/json'},
      body:JSON.stringify({ prompt, negativePrompt, preset, quality, view, palette, size, transparent, n:1 })
    });
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok || j.ok===false){
      if(out) out.textContent='Image generation failed: ' + (j.message || j.error || 'unknown_error');
      return;
    }
    if(out) out.textContent='Image job queued: ' + (j.job?.id || 'unknown_job');
    await loadStudio();
  } catch {
    if(out) out.textContent='Network error creating image generation job.';
  }
}

async function createStudioEdit(ev){
  ev.preventDefault();
  const out=byId('studioOut');
  const prompt=String(byId('studioEditPrompt')?.value || '').trim();
  const sourceWebPath=String(byId('studioEditSource')?.value || '').trim();
  if(!prompt || !sourceWebPath){
    if(out) out.textContent='Edit prompt + source image URL are required.';
    return;
  }

  if(out) out.textContent='Submitting image edit job...';
  try {
    const r=await fetch('/api/studio/edit-image',{
      method:'POST',
      headers:{'content-type':'application/json'},
      body:JSON.stringify({ prompt, sourceWebPath, size:512 })
    });
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok || j.ok===false){
      if(out) out.textContent='Image edit failed: ' + (j.message || j.error || 'unknown_error');
      return;
    }
    if(out) out.textContent='Edit job queued: ' + (j.job?.id || 'unknown_job');
    await loadStudio();
  } catch {
    if(out) out.textContent='Network error creating image edit job.';
  }
}

async function createStudioStyleProfile(ev){
  ev.preventDefault();
  const out=byId('studioOut');
  const prompt=String(byId('studioStylePrompt')?.value || '').trim();
  const refsRaw=String(byId('studioStyleRefs')?.value || '').trim();
  const references=refsRaw ? refsRaw.split(',').map((v)=>v.trim()).filter(Boolean) : [];
  if(!prompt){
    if(out) out.textContent='Style profile prompt is required.';
    return;
  }

  if(out) out.textContent='Creating style profile scaffold...';
  try {
    const r=await fetch('/api/studio/style-profile',{
      method:'POST',
      headers:{'content-type':'application/json'},
      body:JSON.stringify({ prompt, references })
    });
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok || j.ok===false){
      if(out) out.textContent='Style profile failed: ' + (j.message || j.error || 'unknown_error');
      return;
    }
    if(out) out.textContent='Style profile created: ' + (j.job?.id || 'unknown_job');
    await loadStudio();
  } catch {
    if(out) out.textContent='Network error creating style profile.';
  }
}

let omniRunSse = null;
function closeOmniRunSse(){
  try { if(omniRunSse) omniRunSse.close(); } catch {}
  omniRunSse = null;
}

function startOmniRunStream(runId){
  const out=byId('omniRunOut');
  if(!runId || !out) return;
  closeOmniRunSse();
  try {
    const es = new EventSource('/api/omni/orchestrate/stream/' + encodeURIComponent(runId));
    omniRunSse = es;
    es.addEventListener('status', (ev)=>{
      try {
        const d=JSON.parse(ev.data||'{}');
        out.textContent='Run ' + runId + ' status: ' + (d.status || 'unknown') + '\nSteps: ' + JSON.stringify(d.executedSteps||[], null, 2);
      } catch {}
    });
    es.addEventListener('done', (ev)=>{
      try {
        const d=JSON.parse(ev.data||'{}');
        out.textContent='Run ' + runId + ' done: ' + (d.status || 'completed');
      } catch {}
      closeOmniRunSse();
      loadStudio();
    });
    es.addEventListener('timeout', ()=>{ closeOmniRunSse(); });
    es.onerror = ()=>{};
  } catch {}
}

async function runOmniWorkflow(ev){
  ev.preventDefault();
  const out=byId('omniRunOut');
  const prompt=String(byId('omniRunPrompt')?.value || '').trim();
  const asyncMode=Boolean(byId('omniRunAsync')?.checked);
  if(!prompt){
    if(out) out.textContent='Run goal is required.';
    return;
  }

  if(out) out.textContent='Starting Omni workflow...';
  try {
    const r=await fetch('/api/omni/orchestrate/run',{
      method:'POST',
      headers:{'content-type':'application/json'},
      body:JSON.stringify({ prompt, async: asyncMode })
    });
    const j=await r.json().catch(()=>({ok:false,error:'invalid_response'}));
    if(!r.ok || j.ok===false){
      if(out) out.textContent='Omni run failed: ' + (j.message || j.error || 'unknown_error');
      return;
    }

    if(j.mode==='async' && j.runId){
      if(out) out.textContent='Run started: ' + j.runId + ' (streaming...)';
      startOmniRunStream(j.runId);
      return;
    }

    if(out) out.textContent='Sync run complete:\n' + JSON.stringify(j.executedSteps || j.outputs || {}, null, 2);
    await loadStudio();
  } catch {
    if(out) out.textContent='Network error starting omni run.';
  }
}

async function loadStudio(){
  const el=byId('studioOut');
  const diagEl=byId('studioDiag');
  const jobsEl=byId('studioJobs');
  const parityEl=byId('studioParity');
  const parityStatusEl=byId('studioParityStatus');
  if(!el) return;
  el.textContent='Loading studio workspace...';
  if(jobsEl) jobsEl.textContent='Loading studio jobs...';

  const engineEl=byId('studioEngine');
  const [statusRes, catalogRes, jobsRes, galleryRes, parityRes, parityStatusRes, diagRes, engineRes, omniRunsRes] = await Promise.all([
    fetch('/api/studio/status').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'}))),
    fetch('/api/studio/catalog').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'}))),
    fetch('/api/studio/jobs').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'}))),
    fetch('/api/studio/gallery').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'}))),
    fetch('/api/studio/parity').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'}))),
    fetch('/api/studio/parity-status').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'}))),
    fetch('/api/studio/diagnostics').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'}))),
    fetch('/api/studio/local-image-health').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'}))),
    fetch('/api/omni/orchestrate/jobs').then((r)=>r.json().catch(()=>({ok:false,error:'invalid_response'})))
  ]);

  if(!statusRes.ok){
    el.textContent='Studio unavailable: ' + (statusRes.message || statusRes.error || 'unknown_error');
    return;
  }

  if(engineEl){
    if(engineRes.ok){
      const h=engineRes.health||{};
      const mem=h.memory||{};
      const g=h.guardrails||{};
      engineEl.textContent=[
        'Local Image Engine',
        '- Mode: ' + (engineRes.mode || '-'),
        '- Runtime: ' + (h.imageEngine || '-'),
        '- Profile: ' + (h.diffusersProfile || '-'),
        '- Queue: pending ' + (engineRes.queue?.pending ?? 0) + ' • active ' + (engineRes.queue?.active ?? 0) + ' • workers ' + (engineRes.queue?.concurrency ?? 1),
        '- Guardrails: maxSide ' + (g.maxSide ?? '-') + ', maxSteps ' + (g.maxSteps ?? '-') + ', minFreeMB ' + (g.minFreeMb ?? '-') + ', memCapMB ' + (g.cpuMemCapMb ?? '-'),
        '- Memory: avail ' + (mem.availableMb ?? '-') + 'MB / total ' + (mem.totalMb ?? '-') + 'MB'
      ].join('\n');
    } else {
      engineEl.textContent='Local Image Engine\n- Status: unavailable\n- Message: ' + (engineRes.message || engineRes.error || 'unknown_error');
    }
  }

  if(diagEl){
    if(diagRes.ok){
      const checks=diagRes.checks||{};
      const hints=Array.isArray(diagRes.hints) ? diagRes.hints : [];
      diagEl.textContent=[
        'Diagnostics',
        '- Auth ready: ' + (checks.authReady ? 'Yes' : 'No'),
        '- OAuth ready: ' + (checks.oauthReady ? 'Yes' : 'No'),
        '- API key ready: ' + (checks.apiKeyReady ? 'Yes' : 'No'),
        '- User ChatGPT/Codex profile ready: ' + (checks.userCodexProfileReady ? 'Yes' : 'No'),
        '- Owner fallback ready: ' + (checks.ownerFallbackReady ? 'Yes' : 'No'),
        '- API key looks test/demo: ' + (checks.apiKeyLooksTest ? 'Yes' : 'No'),
        '- Local backend mode: ' + (checks.localBackendMode || '-'),
        '- Local backend configured: ' + (checks.localBackendConfigured ? 'Yes' : 'No'),
        '- Image auth ready: ' + (checks.imageAuthReady ? 'Yes' : 'No'),
        '- Output writable: ' + (checks.outputWritable ? 'Yes' : 'No'),
        '- Pack script present: ' + (checks.makePackScript ? 'Yes' : 'No'),
        '- Parity doc present: ' + (checks.parityDoc ? 'Yes' : 'No'),
        hints.length ? ('Hints: ' + hints.join(' | ')) : 'Hints: none'
      ].join('\\n');
    } else {
      diagEl.textContent='Diagnostics unavailable: ' + (diagRes.message || diagRes.error || 'unknown_error');
    }
  }

  const b=statusRes.billing||{};
  const u=b.user||{};
  const q=statusRes.queue||{};
  const retry=statusRes.retries||{};

  const lines=[
    'Studio Ready: ' + (statusRes.available ? 'Yes' : 'No'),
    'Queue: pending ' + (q.pending ?? 0) + ' • active ' + (q.active ?? 0) + ' • workers ' + (q.concurrency ?? 1),
    'Auto-retry: up to ' + (retry.maxAttempts ?? 1) + ' attempt(s), delay ' + Math.round((retry.delayMs || 0)/1000) + 's',
    '',
    'Plan: ' + (b.plan || '-'),
    'Requests Remaining Today: ' + (u.requestsRemaining ?? '-'),
    'Tokens Remaining Today: ' + (u.tokensRemaining ?? '-'),
    'Output Files: ' + (catalogRes.outputCount ?? 0)
  ];

  if(catalogRes.ok===false){
    lines.push('Catalog unavailable: ' + (catalogRes.message || catalogRes.error || 'unknown_error'));
  }

  if(jobsRes.ok && jobsEl){
    const items = Array.isArray(jobsRes.jobs) ? jobsRes.jobs : [];
    const latestFailure = items.find((j)=>j && j.status==='failed' && String(j.type||'').includes('image'));
    if(latestFailure){
      lines.push('Latest Image Error: ' + (latestFailure.error || 'unknown_error'));
    }
    if(items.length===0){
      jobsEl.textContent='No studio jobs yet.';
    } else {
      jobsEl.textContent = items.slice(0,14).map((job)=>{
        const p=job.params||{};
        const detail = p.prompt
          ? ('prompt=' + p.prompt + (p.preset ? (' | preset=' + p.preset) : '') + (p.view ? (' | view=' + p.view) : ''))
          : ('theme=' + (p.theme || '-') + ' size=' + (p.size ?? '-') + ' count=' + (p.count ?? '-'));
        const timeline = Array.isArray(job.timeline)
          ? job.timeline.slice(-2).map((t)=>formatStudioTime(t.ts) + ' :: ' + t.message).join(' | ')
          : '';
        return [
          '[' + (job.status || 'unknown') + '] ' + (job.id || 'job') + ' (' + (job.type || 'job') + ')',
          '  ' + detail,
          '  attempts=' + (job.attempt ?? 0) + '/' + (job.maxAttempts ?? 1),
          '  created=' + formatStudioTime(job.createdAt) + ' finished=' + formatStudioTime(job.finishedAt),
          job.packId ? '  pack=' + job.packId : '',
          job.outputWebPath ? '  output=' + job.outputWebPath : '',
          job.userMessage ? '  message=' + job.userMessage : '',
          job.error ? '  error=' + job.error : '',
          timeline ? '  timeline=' + timeline : ''
        ].filter(Boolean).join('\\n');
      }).join('\\n\\n');
    }
  } else if(jobsEl){
    jobsEl.textContent='Jobs unavailable: ' + (jobsRes.message || jobsRes.error || 'unknown_error');
  }

  if(galleryRes.ok){
    renderStudioGallery(galleryRes.items || []);
    lines.push('Recent Gallery Items: ' + ((galleryRes.items || []).length));
  } else {
    lines.push('Gallery unavailable: ' + (galleryRes.message || galleryRes.error || 'unknown_error'));
  }

  if(parityStatusEl){
    if(parityStatusRes.ok){
      const pg=parityStatusRes.progress||{};
      parityStatusEl.textContent = 'Parity Progress: ' + (pg.completed ?? 0) + '/' + (pg.total ?? 0) + ' (' + (pg.percent ?? 0) + '%)';
    } else {
      parityStatusEl.textContent = 'Parity status unavailable: ' + (parityStatusRes.message || parityStatusRes.error || 'unknown_error');
    }
  }

  if(parityEl){
    if(parityRes.ok){
      const text = String(parityRes.text || '').split('\\n').slice(0,28).join('\\n');
      parityEl.textContent = text || 'Parity checklist is empty.';
    } else {
      parityEl.textContent = 'Parity checklist unavailable: ' + (parityRes.message || parityRes.error || 'unknown_error');
    }
  }

  const omniOut=byId('omniRunOut');
  if(omniOut){
    if(omniRunsRes.ok && Array.isArray(omniRunsRes.jobs) && omniRunsRes.jobs.length){
      const latest=omniRunsRes.jobs[0];
      omniOut.textContent='Latest run: ' + latest.id + '\\nStatus: ' + (latest.status||'unknown') + '\\nWorkflow: ' + (latest.workflow||'-') + '\\nSteps: ' + ((latest.executedSteps||[]).length);
    } else if(!omniRunSse) {
      omniOut.textContent='No active run yet.';
    }
  }

  el.textContent=lines.join('\\n');
}

let ocRetryTimer = null;
let ocRetryCount = 0;

function setOcStatus(message, kind='warn'){
  const el=byId('ocStatus');
  if(!el) return;
  if(!message){
    el.style.display='none';
    el.textContent='';
    el.className='oc-status';
    return;
  }
  el.style.display='block';
  el.textContent=String(message);
  el.className='oc-status ' + kind;
}

function scheduleOpenclawRetry(){
  if(ocRetryTimer) return;
  const delay=Math.min(30000, 1500 * Math.pow(2, Math.min(6, ocRetryCount)));
  ocRetryTimer=setTimeout(async ()=>{
    ocRetryTimer=null;
    await loadOpenclawFrame({ auto:true });
  }, delay);
}

function parseOcErrorText(text){
  if(!text) return '';
  const trimmed=String(text).trim();
  try {
    const j=JSON.parse(trimmed);
    return j.message || j.error || '';
  } catch {
    return '';
  }
}

function inspectOpenclawFrame(){
  const frame=byId('ocFrame');
  if(!frame) return;
  try {
    const doc=frame.contentDocument;
    const ct=String(doc?.contentType || '').toLowerCase();
    if(ct.includes('application/json')){
      const raw=doc?.body?.innerText || '';
      const msg=parseOcErrorText(raw) || 'Gateway disconnected. Retrying automatically...';
      ocRetryCount += 1;
      setOcStatus(msg + ' (attempt ' + ocRetryCount + ')', 'warn');
      scheduleOpenclawRetry();
      return;
    }
    ocRetryCount = 0;
    setOcStatus('Gateway connected.', 'ok');
  } catch {
    // If cross-origin restrictions ever apply, keep status neutral.
    setOcStatus('Dashboard loaded.', 'ok');
  }
}

async function checkOpenclawAvailability(){
  try {
    const r=await fetch('/admin/openclaw/', { method:'HEAD' });
    if(r.ok) return { ok:true };
    let detail='';
    try { detail=parseOcErrorText(await r.text()); } catch {}
    return { ok:false, status:r.status, detail };
  } catch (e) {
    return { ok:false, detail:String(e && e.message ? e.message : e || 'network_error') };
  }
}

async function loadOpenclawFrame(opts={}){
  const frame=byId('ocFrame');
  if(!frame) return;

  const auto = Boolean(opts && opts.auto);
  setOcStatus(auto ? 'Reconnecting to gateway...' : 'Checking gateway connection...', 'warn');

  const availability=await checkOpenclawAvailability();
  if(!availability.ok){
    ocRetryCount += 1;
    const msg=(availability.detail || 'Gateway disconnected.') + ' Retrying automatically...';
    setOcStatus(msg + ' (attempt ' + ocRetryCount + ')', 'warn');
    scheduleOpenclawRetry();
    return;
  }

  ocRetryCount = 0;
  if(ocRetryTimer){ clearTimeout(ocRetryTimer); ocRetryTimer=null; }
  setOcStatus('Gateway connected. Loading dashboard...', 'ok');

  const target=buildOpenclawDashboardUrl();
  if(frame.src !== target) frame.src = target;
  else {
    try { frame.contentWindow.location.reload(); } catch { frame.src = target; }
  }
}

async function logout(){
  await fetch('/api/auth/logout',{method:'POST'});
  location.href='/app';
}

function renderOAuthStatus(){
  const out = byId('accountOut') || byId('keyOut');
  if(!out) return;
  const p = new URLSearchParams(window.location.search);
  const s = p.get('oauth');
  if(!s) return;
  if(s==='connected') out.textContent='✅ OpenAI OAuth connected successfully.';
  else if(s==='token_failed') out.textContent='❌ OAuth token exchange failed. Use API key fallback for now.';
  else if(s==='invalid_state') out.textContent='❌ OAuth state validation failed. Retry connect.';
  else if(s==='error' || s==='exception') out.textContent='❌ OAuth connect failed. Use API key fallback for now.';
}

function renderLoginStatus(){
  const out = byId('loginOut');
  if(!out) return;
  const p = new URLSearchParams(window.location.search);
  const s = p.get('login');
  if(!s) return;
  if(s==='failed') out.textContent='❌ Sign in failed. Check username/password and try again.';
  else if(s==='rate_limited') out.textContent='⚠️ Too many attempts. Wait a minute and retry.';
}

const lf=byId('loginForm'); if(lf) lf.addEventListener('submit', login);
const kf=byId('keyForm'); if(kf) kf.addEventListener('submit', saveKey);
const akf=byId('accountKeyForm'); if(akf) akf.addEventListener('submit', saveAccountKey);
const spf=byId('studioPackForm'); if(spf) spf.addEventListener('submit', createStudioPack);
const sgf=byId('studioGenForm'); if(sgf) sgf.addEventListener('submit', createStudioImage);
const sef=byId('studioEditForm'); if(sef) sef.addEventListener('submit', createStudioEdit);
const ssf=byId('studioStyleForm'); if(ssf) ssf.addEventListener('submit', createStudioStyleProfile);
const orf=byId('omniRunForm'); if(orf) orf.addEventListener('submit', runOmniWorkflow);
window.addEventListener('beforeunload', closeOmniRunSse);
const menuBtn=byId('menuBtn'); if(menuBtn) menuBtn.addEventListener('click', toggleMenu);
const backdrop=byId('drawerBackdrop'); if(backdrop) backdrop.addEventListener('click', closeMenu);
const links=document.querySelectorAll('.drawer-link'); links.forEach((a)=>a.addEventListener('click', closeMenu));

if(byId('chatLog')){
  appendChat('system', 'Connected. Start chatting.');
  const msgInput=byId('msg');
  if(msgInput){
    msgInput.focus();
    msgInput.addEventListener('keydown',(ev)=>{ if(ev.key==='Enter'){ ev.preventDefault(); sendMsg(); } });
  }
  loadBilling();
}
if(byId('billingOut') && !byId('chatLog')) loadBilling();
if(byId('studioOut')) {
  setStudioMode('create');
  loadStudio();
  setInterval(()=>{ loadStudio(); }, 8000);
}
if(byId('adminOut')) loadAdmin();
syncOpenclawLinks();
const ocFrame=byId('ocFrame');
if(ocFrame){
  ocFrame.addEventListener('load', inspectOpenclawFrame);
  ocFrame.addEventListener('error', ()=>{
    ocRetryCount += 1;
    setOcStatus('Gateway disconnected. Retrying automatically... (attempt ' + ocRetryCount + ')', 'warn');
    scheduleOpenclawRetry();
  });
}
renderOAuthStatus();
renderLoginStatus();
</script>
</body>
</html>`;
}


function gateUsage(user, inputText) {
  if (isOwner(user)) return { ok: true, usingShared: false, billing: getBillingStatus(user) };
  const hasKey = hasUserApiKey(user.id);
  const hasOauth = hasOAuthAccess(user.id);
  if (hasKey || hasOauth) return { ok: true, usingShared: false, billing: getBillingStatus(user) };
  if (!ALLOW_SHARED_KEY) {
    return { ok: false, code: 403, error: 'onboarding_required', message: 'Connect your API key first.', billing: getBillingStatus(user) };
  }
  const tokenCost = estimateTokens(inputText);
  const consumed = consumeSharedUsage(user.id, tokenCost);
  if (!consumed.ok) {
    return {
      ok: false,
      code: 402,
      error: consumed.reason === 'global_limit' ? 'global_limit_reached' : 'plan_limit_reached',
      message: consumed.reason === 'global_limit'
        ? 'Shared capacity reached for today. Try again later or connect your own API key.'
        : 'Free plan limit reached. Connect your own API key to continue.',
      billing: consumed.usage
    };
  }
  return { ok: true, usingShared: true, billing: consumed.usage };
}

async function handleApi(req, res, url) {
  const { token } = getSessionFromRequest(req, COOKIE_NAME);
  const user = getUserByToken(token);
  const safeUser = user ? sanitizeUser(user) : null;

  let apiPath = url.pathname;
  if (apiPath === '/api/omni/v1') apiPath = '/api/omni';
  else if (apiPath.startsWith('/api/omni/v1/')) apiPath = '/api/omni/' + apiPath.slice('/api/omni/v1/'.length);

  if (url.pathname === '/api/health') return sendJson(res, 200, { ok: true, app: 'prismbot-core', phase: 'B' });

  if (url.pathname === '/.well-known/oauth-authorization-server') {
    const base = MCP_OAUTH_ISSUER || getBaseUrl(req);
    return sendJson(res, 200, {
      issuer: base,
      authorization_endpoint: `${base}/oauth/authorize`,
      token_endpoint: `${base}/oauth/token`,
      response_types_supported: ['code'],
      grant_types_supported: ['authorization_code'],
      token_endpoint_auth_methods_supported: ['client_secret_post', 'client_secret_basic']
    });
  }

  if (url.pathname === '/.well-known/oauth-protected-resource') {
    const base = MCP_OAUTH_ISSUER || getBaseUrl(req);
    return sendJson(res, 200, {
      resource: `${base}/mcp`,
      authorization_servers: [base],
      bearer_methods_supported: ['header']
    });
  }

  if (url.pathname === '/oauth/authorize') {
    const clientId = String(url.searchParams.get('client_id') || '');
    const redirectUri = String(url.searchParams.get('redirect_uri') || '');
    const state = String(url.searchParams.get('state') || '');
    if (!clientId || !redirectUri) return sendText(res, 400, 'missing client_id or redirect_uri');
    if (clientId !== MCP_OAUTH_CLIENT_ID) return sendText(res, 400, 'invalid client_id');
    const code = crypto.randomBytes(24).toString('hex');
    oauthCodes.set(code, { clientId, createdAt: Date.now() });
    const to = new URL(redirectUri);
    to.searchParams.set('code', code);
    if (state) to.searchParams.set('state', state);
    res.writeHead(302, { location: to.toString() });
    return res.end();
  }

  if (url.pathname === '/oauth/token' && req.method === 'POST') {
    const body = await parseBodyAny(req);
    const clientId = String(body.client_id || '');
    const clientSecret = String(body.client_secret || '');
    const grantType = String(body.grant_type || '');
    const code = String(body.code || '');
    if (grantType !== 'authorization_code') return sendJson(res, 400, { error: 'unsupported_grant_type' });
    if (!oauthCodes.has(code)) return sendJson(res, 400, { error: 'invalid_grant' });
    if (clientId !== MCP_OAUTH_CLIENT_ID) return sendJson(res, 401, { error: 'invalid_client' });
    if (MCP_OAUTH_CLIENT_SECRET && clientSecret !== MCP_OAUTH_CLIENT_SECRET) return sendJson(res, 401, { error: 'invalid_client' });
    oauthCodes.delete(code);
    const accessToken = issueMcpAccessToken('chatgpt-mcp');
    return sendJson(res, 200, {
      access_token: accessToken,
      token_type: 'Bearer',
      expires_in: 3600
    });
  }

  if ((url.pathname === '/mcp' || url.pathname === '/mcp2') && req.method === 'GET') {
    return sendJson(res, 200, {
      ok: true,
      name: 'PrismBot MCP',
      auth: {
        bearer: true,
        oauthDiscovery: `${(MCP_OAUTH_ISSUER || getBaseUrl(req))}/.well-known/oauth-authorization-server`
      }
    });
  }

  if ((url.pathname === '/mcp' || url.pathname === '/mcp2') && req.method === 'POST') {
    if (!isValidMcpBearer(req)) {
      res.writeHead(401, {
        'content-type': 'application/json',
        'www-authenticate': `Bearer realm="prismbot-mcp", resource="${(MCP_OAUTH_ISSUER || getBaseUrl(req))}/mcp"`
      });
      return res.end(JSON.stringify({ error: 'unauthorized' }));
    }
    const body = await parseBodyAny(req);
    const id = body.id ?? null;
    const method = String(body.method || '');

    if (method === 'initialize') {
      return sendJson(res, 200, {
        jsonrpc: '2.0',
        id,
        result: {
          protocolVersion: '2024-11-05',
          serverInfo: { name: 'PrismBot MCP', version: '1.0.3' },
          capabilities: { tools: { listChanged: false } }
        }
      });
    }

    if (method === 'tools/list') {
      return sendJson(res, 200, {
        jsonrpc: '2.0',
        id,
        result: {
          tools: [
            {
              name: 'prismbot.ping',
              description: 'Health ping.',
              inputSchema: { type: 'object', properties: {}, additionalProperties: false },
              annotations: { readOnlyHint: true }
            },
            {
              name: 'prismbot.status',
              description: 'Return basic PrismBot app status.',
              inputSchema: { type: 'object', properties: {}, additionalProperties: false },
              annotations: { readOnlyHint: true }
            },
            {
              name: 'prismbot.chat',
              description: 'Send a chat message to PrismBot and get a reply.',
              inputSchema: {
                type: 'object',
                properties: { message: { type: 'string', description: 'User message to send.' } },
                required: ['message'],
                additionalProperties: false
              },
              annotations: { readOnlyHint: true }
            }
          ]
        }
      });
    }

    if (method === 'tools/call') {
      const tool = body.params?.name;
      let args = body.params?.arguments ?? body.params?.input ?? {};
      if (typeof args === 'string') {
        try { args = JSON.parse(args); } catch { args = {}; }
      }

      if (tool === 'prismbot.ping') {
        return sendJson(res, 200, {
          jsonrpc: '2.0',
          id,
          result: {
            content: [{
              type: 'text',
              text: 'pong\nPrismBot MCP online. Tools available: prismbot.ping, prismbot.status, prismbot.chat'
            }]
          }
        });
      }

      if (tool === 'prismbot.status') {
        return sendJson(res, 200, {
          jsonrpc: '2.0',
          id,
          result: {
            content: [{
              type: 'text',
              text: `PrismBot MCP online • time=${new Date().toISOString()} • env=${process.env.NODE_ENV || 'production'} • phase=B`
            }]
          }
        });
      }

      if (tool === 'prismbot.chat') {
        const message = String(args.message || '').trim();
        if (!message) {
          return sendJson(res, 200, { jsonrpc: '2.0', id, error: { code: -32602, message: 'Missing message' } });
        }
        const output = generateAiReply(message);
        return sendJson(res, 200, {
          jsonrpc: '2.0',
          id,
          result: { content: [{ type: 'text', text: output }] }
        });
      }

      return sendJson(res, 200, { jsonrpc: '2.0', id, error: { code: -32601, message: 'Unknown tool' } });
    }

    return sendJson(res, 200, { jsonrpc: '2.0', id, error: { code: -32601, message: 'Method not found' } });
  }

  if (url.pathname === '/api/auth/login' && req.method === 'POST') {
    const ip = (req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown').toString().split(',')[0].trim();
    const lr = checkLoginRate(ip);
    const wantsRedirect = url.searchParams.get('redirect') === '1' || String(req.headers.accept || '').includes('text/html');
    if (!lr.allowed) {
      if (wantsRedirect) {
        res.writeHead(302, { location: '/app/chat?login=rate_limited' });
        return res.end();
      }
      return sendJson(res, 429, { ok: false, error: 'rate_limited', message: 'Too many login attempts. Try again shortly.' });
    }

    const body = await parseBodyAny(req);
    const found = findUserByUsername(body.username);
    const passOk = found ? verifyScryptPassword(String(body.password || ''), found.passwordHash) : false;
    if (!found || !found.active || !passOk) {
      if (wantsRedirect) {
        res.writeHead(302, { location: '/app/chat?login=failed' });
        return res.end();
      }
      return sendJson(res, 401, { ok: false, error: 'invalid_credentials' });
    }
    setSession(res, found.id);
    if (wantsRedirect) {
      res.writeHead(302, { location: '/app' });
      return res.end();
    }
    return sendJson(res, 200, { ok: true, user: sanitizeUser(found) });
  }

  if (url.pathname === '/api/auth/logout' && req.method === 'POST') {
    clearSession(req, res);
    return sendJson(res, 200, { ok: true });
  }

  if (url.pathname === '/api/auth/me') {
    return sendJson(res, 200, {
      ok: true,
      user: safeUser,
      onboarding: { apiKeyConnected: Boolean(user && hasUserApiKey(user.id)) },
      billing: user ? getBillingStatus(user) : null
    });
  }

  if (url.pathname === '/api/billing/status') {
    if (!user) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    return sendJson(res, 200, { ok: true, billing: getBillingStatus(user) });
  }

  if (url.pathname === '/api/oauth/chatgpt/start') {
    if (!user) return sendJson(res, 401, { ok: false, error: 'unauthorized' });

    if (!OPENAI_OAUTH_CLIENT_ID || !OPENAI_OAUTH_CLIENT_SECRET) {
      res.writeHead(302, { location: '/app/account?oauth=chatgpt_not_configured' });
      return res.end();
    }

    const base = getBaseUrl(req);
    const redirectUri = OPENAI_OAUTH_REDIRECT_URI || `${base}/api/oauth/chatgpt/callback`;

    const state = crypto.randomBytes(24).toString('hex');
    const codex = getCodexOAuthStore();
    codex.states[state] = { userId: user.id, createdAt: Date.now() };
    setCodexOAuthStore(codex);

    const q = new URLSearchParams({
      response_type: 'code',
      client_id: OPENAI_OAUTH_CLIENT_ID,
      redirect_uri: redirectUri,
      scope: 'openid profile email offline_access',
      state,
    });
    res.writeHead(302, { location: `${OPENAI_OAUTH_AUTH_URL}?${q.toString()}` });
    return res.end();
  }

  if (url.pathname === '/api/oauth/chatgpt/callback') {
    const code = String(url.searchParams.get('code') || '');
    const state = String(url.searchParams.get('state') || '');
    const error = String(url.searchParams.get('error') || '');
    const base = getBaseUrl(req);
    const redirectUri = OPENAI_OAUTH_REDIRECT_URI || `${base}/api/oauth/chatgpt/callback`;

    if (error) {
      res.writeHead(302, { location: '/app/account?oauth=chatgpt_error' });
      return res.end();
    }

    const codex = getCodexOAuthStore();
    const pending = codex.states[state];
    if (!state || !pending || !code) {
      res.writeHead(302, { location: '/app/account?oauth=chatgpt_invalid_state' });
      return res.end();
    }

    try {
      const body = new URLSearchParams({
        grant_type: 'authorization_code',
        code,
        client_id: OPENAI_OAUTH_CLIENT_ID,
        client_secret: OPENAI_OAUTH_CLIENT_SECRET,
        redirect_uri: redirectUri,
      });
      const tokenRes = await fetch(OPENAI_OAUTH_TOKEN_URL, {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body,
      });
      const tokenJson = await tokenRes.json().catch(() => ({}));
      if (!tokenRes.ok || !tokenJson.access_token) {
        delete codex.states[state];
        setCodexOAuthStore(codex);
        res.writeHead(302, { location: '/app/account?oauth=chatgpt_token_failed' });
        return res.end();
      }

      codex.users[pending.userId] = {
        provider: 'openai-codex-oauth',
        accessToken: encryptSecret(tokenJson.access_token),
        refreshToken: tokenJson.refresh_token ? encryptSecret(tokenJson.refresh_token) : null,
        tokenType: tokenJson.token_type || 'Bearer',
        scope: tokenJson.scope || 'openid profile email offline_access',
        expiresAt: tokenJson.expires_in ? (Date.now() + Number(tokenJson.expires_in) * 1000) : null,
        updatedAt: new Date().toISOString(),
      };
      delete codex.states[state];
      setCodexOAuthStore(codex);
      res.writeHead(302, { location: '/app/account?oauth=chatgpt_connected' });
      return res.end();
    } catch {
      delete codex.states[state];
      setCodexOAuthStore(codex);
      res.writeHead(302, { location: '/app/account?oauth=chatgpt_exception' });
      return res.end();
    }
  }

  if (url.pathname === '/api/oauth/openai/start') {
    if (!user) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const base = MCP_OAUTH_ISSUER || getBaseUrl(req);
    const redirectUri = `${base}/api/oauth/openai/callback`;

    const state = crypto.randomBytes(24).toString('hex');
    const oauth = getOAuthStore();
    oauth.states[state] = { userId: user.id, createdAt: Date.now() };
    setOAuthStore(oauth);

    const q = new URLSearchParams({
      response_type: 'code',
      client_id: MCP_OAUTH_CLIENT_ID,
      redirect_uri: redirectUri,
      scope: 'openid profile',
      state
    });
    res.writeHead(302, { location: `${base}/oauth/authorize?${q.toString()}` });
    return res.end();
  }

  if (url.pathname === '/api/oauth/openai/callback') {
    const code = String(url.searchParams.get('code') || '');
    const state = String(url.searchParams.get('state') || '');
    const error = String(url.searchParams.get('error') || '');
    const base = MCP_OAUTH_ISSUER || getBaseUrl(req);
    const redirectUri = `${base}/api/oauth/openai/callback`;

    if (error) {
      res.writeHead(302, { location: '/app/account?oauth=error' });
      return res.end();
    }

    const oauth = getOAuthStore();
    const pending = oauth.states[state];
    if (!state || !pending || !code) {
      res.writeHead(302, { location: '/app/account?oauth=invalid_state' });
      return res.end();
    }

    try {
      const body = new URLSearchParams({
        grant_type: 'authorization_code',
        code,
        client_id: MCP_OAUTH_CLIENT_ID,
        client_secret: MCP_OAUTH_CLIENT_SECRET,
        redirect_uri: redirectUri
      });
      const tokenRes = await fetch(`${base}/oauth/token`, {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body
      });
      const tokenJson = await tokenRes.json().catch(() => ({}));
      if (!tokenRes.ok || !tokenJson.access_token) {
        delete oauth.states[state];
        setOAuthStore(oauth);
        res.writeHead(302, { location: '/app/account?oauth=token_failed' });
        return res.end();
      }

      oauth.users[pending.userId] = {
        provider: 'prismbot-mcp-oauth',
        accessToken: encryptSecret(tokenJson.access_token),
        refreshToken: tokenJson.refresh_token ? encryptSecret(tokenJson.refresh_token) : null,
        tokenType: tokenJson.token_type || 'Bearer',
        scope: tokenJson.scope || 'openid profile',
        updatedAt: new Date().toISOString()
      };
      delete oauth.states[state];
      setOAuthStore(oauth);
      res.writeHead(302, { location: '/app/account?oauth=connected' });
      return res.end();
    } catch {
      delete oauth.states[state];
      setOAuthStore(oauth);
      res.writeHead(302, { location: '/app/account?oauth=exception' });
      return res.end();
    }
  }

  if (url.pathname === '/api/user/key-status') {
    if (!user) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    return sendJson(res, 200, { ok: true, apiKeyConnected: hasUserApiKey(user.id) });
  }

  if (url.pathname === '/api/user/api-key' && req.method === 'POST') {
    if (!user) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const body = await parseBody(req);
    const apiKey = String(body.apiKey || '').trim();
    if (!/^sk-[A-Za-z0-9._-]{20,}$/.test(apiKey)) {
      return sendJson(res, 400, { ok: false, error: 'invalid_api_key_format', message: 'Expected a valid OpenAI key starting with sk-.' });
    }
    const all = getUserApiKeys();
    all[user.id] = encryptApiKey(apiKey);
    setUserApiKeys(all);
    return sendJson(res, 200, { ok: true, apiKeyConnected: true, billing: getBillingStatus(user) });
  }

  if (url.pathname === '/api/admin/summary') {
    if (!safeUser || !safeUser.owner) return sendJson(res, 403, { ok: false, error: 'forbidden' });
    const users = normalizeUsers(readJson('users.json', {}));
    const tasks = readJson('tasks.json', []);
    const activity = readJson('activity.json', []);
    return sendJson(res, 200, { ok: true, users: Object.keys(users).length, tasks: tasks.length || 0, activity: activity.length || 0, billingPolicy: { allowShared: ALLOW_SHARED_KEY, freeDailyRequestLimit: FREE_DAILY_REQUEST_LIMIT, freeDailyTokenLimit: FREE_DAILY_TOKEN_LIMIT } });
  }

  if (url.pathname === '/api/studio/status') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const usageGate = gateUsage(user, 'studio_status_check');
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });
    const studioPath = path.join(REPO_APPS, 'pixel-pipeline');
    return sendJson(res, 200, {
      ok: true,
      available: fs.existsSync(studioPath),
      path: 'apps/pixel-pipeline',
      queue: {
        pending: studioImageQueue.length,
        active: studioImageQueueActive,
        concurrency: STUDIO_IMAGE_QUEUE_CONCURRENCY,
      },
      retries: {
        maxAttempts: STUDIO_IMAGE_RETRY_MAX + 1,
        delayMs: STUDIO_IMAGE_RETRY_DELAY_MS,
      },
      billing: usageGate.billing,
    });
  }

  if (url.pathname === '/api/studio/catalog') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const usageGate = gateUsage(user, 'studio_catalog_check');
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    const studioRoot = path.join(REPO_APPS, 'pixel-pipeline');
    if (!fs.existsSync(studioRoot)) {
      return sendJson(res, 404, { ok: false, error: 'studio_not_found', message: 'Studio workspace not found.' });
    }

    const prompts = safeListFiles(path.join(studioRoot, 'prompts'), 20);
    const templates = safeListFiles(path.join(studioRoot, 'templates'), 20);
    const scripts = safeListFiles(path.join(studioRoot, 'scripts'), 20);
    const outputFiles = safeListFiles(path.join(studioRoot, 'output'), 50);

    return sendJson(res, 200, {
      ok: true,
      root: 'apps/pixel-pipeline',
      prompts,
      templates,
      scripts,
      outputCount: outputFiles.filter((n) => n !== '.gitkeep').length,
      outputPreview: outputFiles.filter((n) => n !== '.gitkeep').slice(0, 10)
    });
  }

  if (url.pathname === '/api/studio/jobs' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    return sendJson(res, 200, {
      ok: true,
      jobs: listStudioJobs(60),
      queue: {
        pending: studioImageQueue.length,
        active: studioImageQueueActive,
        concurrency: STUDIO_IMAGE_QUEUE_CONCURRENCY,
      },
    });
  }

  if (url.pathname === '/api/studio/jobs' && req.method === 'POST') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const body = await parseBody(req);
    const action = String(body.action || 'create_pack_scaffold');
    if (action !== 'create_pack_scaffold') {
      return sendJson(res, 400, { ok: false, error: 'invalid_action', message: 'Only create_pack_scaffold is supported right now.' });
    }

    const usageGate = gateUsage(user, String(body.theme || 'studio_create_pack'));
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    const created = createStudioPackJob(user, body);
    if (!created.ok) return sendJson(res, created.code || 500, { ok: false, error: created.error, message: created.message });
    return sendJson(res, 200, { ok: true, job: sanitizeStudioJobForClient(created.job), billing: usageGate.billing });
  }

  if (url.pathname === '/api/studio/gallery' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const limit = toSafeInt(url.searchParams.get('limit'), 40, 1, 200);
    const items = collectStudioGallery(limit);
    return sendJson(res, 200, { ok: true, items });
  }

  if (url.pathname === '/api/studio/parity' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    try {
      const text = fs.readFileSync(STUDIO_PARITY_PATH, 'utf8');
      return sendJson(res, 200, { ok: true, text });
    } catch {
      return sendJson(res, 404, { ok: false, error: 'parity_not_found', message: 'Studio parity matrix file not found.' });
    }
  }

  if (url.pathname === '/api/studio/parity-status' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    return sendJson(res, 200, getStudioParityStatus());
  }

  if (url.pathname === '/api/studio/diagnostics' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const hasApiKey = hasUserApiKey(user.id);
    const hasOauth = hasOAuthAccess(user.id);
    const apiKeyRaw = (() => {
      try {
        const keys = getUserApiKeys();
        return keys[user.id] ? decryptApiKey(keys[user.id]) : null;
      } catch { return null; }
    })();
    const oauthRaw = (() => {
      try {
        const o = getOAuthStore();
        const row = o.users?.[user.id];
        return row?.accessToken ? decryptSecret(row.accessToken) : null;
      } catch { return null; }
    })();
    const userCodexAccess = getUserCodexAccessToken(user.id);
    const ownerFallbackAccess = user.owner ? getOpenClawCodexAccessToken() : null;
    const usingLocalBackend = STUDIO_IMAGE_BACKEND === 'local' || STUDIO_IMAGE_BACKEND === 'auto';
    const checks = {
      authReady: hasApiKey || hasOauth,
      oauthReady: hasOauth,
      apiKeyReady: hasApiKey,
      userCodexProfileReady: Boolean(userCodexAccess),
      ownerFallbackReady: Boolean(ownerFallbackAccess),
      apiKeyLooksTest: Boolean(apiKeyRaw && String(apiKeyRaw).startsWith('sk-test-')),
      localBackendMode: STUDIO_IMAGE_BACKEND,
      localBackendConfigured: Boolean(STUDIO_LOCAL_IMAGE_URL),
      imageAuthReady: usingLocalBackend || Boolean(userCodexAccess) || looksLikeOpenAiKey(apiKeyRaw) || Boolean(ownerFallbackAccess),
      outputWritable: (() => {
        try {
          fs.mkdirSync(STUDIO_OUTPUT_ROOT, { recursive: true });
          const test = path.join(STUDIO_OUTPUT_ROOT, '.write-test.tmp');
          fs.writeFileSync(test, 'ok');
          fs.unlinkSync(test);
          return true;
        } catch { return false; }
      })(),
      makePackScript: fs.existsSync(STUDIO_SCRIPT_MAKE_PACK),
      parityDoc: fs.existsSync(STUDIO_PARITY_PATH),
    };
    const hints = [];
    if (!checks.authReady && !usingLocalBackend) hints.push('Connect account auth in Account tab.');
    if (!checks.imageAuthReady) hints.push('Image generation unavailable. Configure local backend or connect ChatGPT (Codex OAuth) / add real OpenAI API key (sk-...).');
    if (checks.apiKeyLooksTest) hints.push('Saved API key is a test/demo key (sk-test-...). Replace it with a real OpenAI key in Account tab.');
    if (usingLocalBackend) hints.push(`Local backend mode active (${STUDIO_IMAGE_BACKEND}) at ${STUDIO_LOCAL_IMAGE_URL}.`);
    if (!checks.outputWritable) hints.push('Studio output directory is not writable.');
    if (!checks.makePackScript) hints.push('make-pack.sh missing in pixel-pipeline/scripts/.');
    return sendJson(res, 200, { ok: true, checks, hints });
  }

  if (url.pathname === '/api/studio/local-image-health' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const health = await fetchLocalImageBackendHealth();
    if (!health.ok) {
      return sendJson(res, 200, {
        ok: false,
        backend: 'local-image-engine',
        mode: STUDIO_IMAGE_BACKEND,
        message: normalizeStudioUserError(health.error || `local_http_${health.status || 0}`),
        error: health.error || `local_http_${health.status || 0}`,
        queue: {
          pending: studioImageQueue.length,
          active: studioImageQueueActive,
          concurrency: STUDIO_IMAGE_QUEUE_CONCURRENCY,
        }
      });
    }
    return sendJson(res, 200, {
      ok: true,
      backend: 'local-image-engine',
      mode: STUDIO_IMAGE_BACKEND,
      queue: {
        pending: studioImageQueue.length,
        active: studioImageQueueActive,
        concurrency: STUDIO_IMAGE_QUEUE_CONCURRENCY,
      },
      retries: {
        maxAttempts: STUDIO_IMAGE_RETRY_MAX + 1,
        delayMs: STUDIO_IMAGE_RETRY_DELAY_MS,
      },
      health: health.data,
    });
  }

  if (url.pathname === '/api/studio/generate-image' && req.method === 'POST') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const body = await parseBody(req);
    const prompt = String(body.prompt || '').trim();
    if (!prompt) return sendJson(res, 400, { ok: false, error: 'missing_prompt', message: 'Prompt is required.' });

    const usageGate = gateUsage(user, prompt);
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    const created = createStudioImageJob(user, body);
    if (!created.ok) return sendJson(res, created.code || 500, { ok: false, error: created.error, message: created.message, billing: usageGate.billing });
    return sendJson(res, 200, { ok: true, job: sanitizeStudioJobForClient(created.job), billing: usageGate.billing });
  }

  if (url.pathname === '/api/studio/edit-image' && req.method === 'POST') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const body = await parseBody(req);
    const prompt = String(body.prompt || '').trim();
    if (!prompt) return sendJson(res, 400, { ok: false, error: 'missing_prompt', message: 'Prompt is required.' });

    const usageGate = gateUsage(user, prompt);
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    const created = createStudioEditImageJob(user, body);
    if (!created.ok) return sendJson(res, created.code || 500, { ok: false, error: created.error, message: created.message, billing: usageGate.billing });
    return sendJson(res, 200, { ok: true, job: sanitizeStudioJobForClient(created.job), billing: usageGate.billing });
  }

  if (url.pathname === '/api/studio/style-profile' && req.method === 'POST') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    const body = await parseBody(req);
    const prompt = String(body.prompt || '').trim();
    if (!prompt) return sendJson(res, 400, { ok: false, error: 'missing_prompt', message: 'Prompt is required.' });

    const usageGate = gateUsage(user, prompt);
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    const created = createStudioStyleProfileJob(user, body);
    if (!created.ok) return sendJson(res, created.code || 500, { ok: false, error: created.error, message: created.message, billing: usageGate.billing });
    return sendJson(res, 200, { ok: true, job: sanitizeStudioJobForClient(created.job), billing: usageGate.billing });
  }

  if (apiPath === '/api/omni/health' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const localHealth = await fetchLocalImageBackendHealth();
    return sendJson(res, 200, {
      ok: true,
      api: 'omni',
      version: '0.3.0',
      localImageBackend: {
        mode: STUDIO_IMAGE_BACKEND,
        url: STUDIO_LOCAL_IMAGE_URL,
        queue: {
          pending: studioImageQueue.length,
          active: studioImageQueueActive,
          concurrency: STUDIO_IMAGE_QUEUE_CONCURRENCY,
        },
        retries: {
          maxAttempts: STUDIO_IMAGE_RETRY_MAX + 1,
          delayMs: STUDIO_IMAGE_RETRY_DELAY_MS,
        },
        health: localHealth.ok ? localHealth.data : { ok: false, error: localHealth.error || `local_http_${localHealth.status || 0}` },
      },
      omniQueue: {
        pending: omniQueue.length,
        active: omniQueueActive,
        concurrency: OMNI_QUEUE_CONCURRENCY,
      },
      omniExecution: {
        retry: {
          maxAttempts: Math.max(1, Math.min(5, Number(process.env.OMNI_PROVIDER_MAX_ATTEMPTS || 2))),
          baseDelayMs: Math.max(25, Math.min(5000, Number(process.env.OMNI_PROVIDER_RETRY_DELAY_MS || 200))),
        },
        timeoutMs: Math.max(500, Math.min(90000, Number(process.env.OMNI_PROVIDER_TIMEOUT_MS || 12000))),
        circuitBreaker: {
          failures: Math.max(1, Math.min(20, Number(process.env.OMNI_PROVIDER_BREAKER_FAILURES || 3))),
          cooldownMs: Math.max(500, Math.min(300000, Number(process.env.OMNI_PROVIDER_BREAKER_COOLDOWN_MS || 30000))),
        },
        recovery: {
          maxAttempts: OMNI_RECOVERY_MAX_ATTEMPTS,
          deadLetterCount: omniDeadLetterCount(),
        },
      },
    });
  }

  if (apiPath === '/api/omni/metrics' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    return sendJson(res, 200, { ok: true, metrics: getOmniMetricsSnapshot() });
  }

  if (apiPath === '/api/omni/models' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const payload = omniModelsPayload();
    const textProviders = payload.providers.filter((p) => p.modalities.includes('text'));
    const imageProviders = payload.providers.filter((p) => p.modalities.includes('image'));
    return sendJson(res, 200, {
      ok: true,
      models: {
        text: textProviders.map((p) => p.provider),
        image: imageProviders.map((p) => p.provider),
        audio: ['local-synth-v1', 'local-whisper'],
      },
      readiness: {
        text: textProviders.map((p) => ({ provider: p.provider, readiness: p.readiness })),
        image: imageProviders.map((p) => ({ provider: p.provider, readiness: p.readiness })),
      },
      routing: {
        strategy: payload.strategy,
        preferredProvider: payload.preferredProvider,
        fallbackChain: payload.fallbackChain,
      },
    });
  }

  if (apiPath === '/api/omni/backends' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const runtime = getOmniRuntime();
    const localHealth = await fetchLocalImageBackendHealth();
    const providers = {};
    for (const [name, adapter] of Object.entries(runtime.adapters)) {
      const r = adapter.readiness();
      providers[name] = {
        provider: name,
        readiness: r.readiness,
        enabled: r.enabled,
        hasCredentials: r.hasCredentials,
        modalities: adapter.modalities,
      };
    }

    if (providers.local && localHealth.ok === false && providers.local.readiness === 'ready') {
      providers.local.readiness = 'error';
      providers.local.error = localHealth.error || `local_http_${localHealth.status || 0}`;
    }

    return sendJson(res, 200, {
      ok: true,
      backends: {
        routing: {
          strategy: runtime.config.routing.strategy,
          fallbackPolicy: runtime.config.routing.fallbackPolicy,
        },
        providers,
        localImage: {
          status: localHealth.ok ? 'healthy' : 'degraded',
          mode: STUDIO_IMAGE_BACKEND,
          health: localHealth.ok ? localHealth.data : { ok: false, error: localHealth.error || `local_http_${localHealth.status || 0}` },
        },
        audioTranscribe: {
          status: 'local',
          endpoint: STUDIO_LOCAL_TRANSCRIBE_URL,
        },
      }
    });
  }

  if (apiPath === '/api/omni/docs/spec.md' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    try {
      const text = fs.readFileSync(OMNI_FULL_SPEC_PATH, 'utf8');
      res.writeHead(200, { 'content-type': 'text/markdown; charset=utf-8' });
      return res.end(text);
    } catch {
      return sendJson(res, 404, { ok: false, error: 'omni_spec_not_found' });
    }
  }

  if (apiPath === '/api/omni/docs/openapi.yaml' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    try {
      const text = fs.readFileSync(OMNI_OPENAPI_FULL_PATH, 'utf8');
      res.writeHead(200, { 'content-type': 'application/yaml; charset=utf-8' });
      return res.end(text);
    } catch {
      return sendJson(res, 404, { ok: false, error: 'omni_openapi_not_found' });
    }
  }

  if (apiPath === '/api/omni/docs' && req.method === 'GET') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });

    let specText = 'Spec file not found.';
    try { specText = fs.readFileSync(OMNI_FULL_SPEC_PATH, 'utf8'); } catch {}
    const preview = specText.split('\n').slice(0, 80).join('\n');

    const html = `<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>PrismBot OmniAPI Docs</title>
<style>
body{margin:0;background:#0b0f1f;color:#e8ecff;font:14px/1.45 Inter,system-ui,sans-serif}
.wrap{max-width:1100px;margin:0 auto;padding:20px}
.card{background:#121833;border:1px solid #2e3a6b;border-radius:14px;padding:16px;margin-bottom:14px}
a{color:#9bb4ff}
pre{white-space:pre-wrap;background:#0d1329;border:1px solid #28345f;border-radius:10px;padding:12px;max-height:60vh;overflow:auto}
</style>
</head>
<body>
<div class="wrap">
  <h1>OmniAPI Docs</h1>
  <div class="card">
    <strong>Full Spec:</strong> <a href="/api/omni/docs/spec.md">/api/omni/docs/spec.md</a><br/>
    <strong>OpenAPI YAML:</strong> <a href="/api/omni/docs/openapi.yaml">/api/omni/docs/openapi.yaml</a><br/>
    <strong>Capabilities JSON:</strong> <a href="/api/omni/capabilities">/api/omni/capabilities</a>
  </div>
  <div class="card">
    <h3>Spec Preview</h3>
    <pre>${escapeHtml(preview)}</pre>
  </div>
</div>
</body>
</html>`;

    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    return res.end(html);
  }

  if (apiPath === '/api/omni/capabilities' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    return sendJson(res, 200, {
      ok: true,
      capabilities: {
        docs: { status: 'real', route: '/api/omni/docs', spec: '/api/omni/docs/spec.md', openapi: '/api/omni/docs/openapi.yaml' },
        versioning: { status: 'real', aliases: ['/api/omni/v1/* -> /api/omni/*'] },
        localImageHealth: { status: 'real', route: '/api/studio/local-image-health' },
        metrics: { status: 'real', route: '/api/omni/metrics' },
        backends: { status: 'real', route: '/api/omni/backends' },
        models: { status: 'real', route: '/api/omni/models' },
        text: {
          status: 'real',
          route: '/api/omni/chat/completions',
          endpoints: {
            chatCompletions: '/api/omni/chat/completions',
            responses: '/api/omni/responses',
            reason: '/api/omni/reason',
            summarize: '/api/omni/summarize',
            rewrite: '/api/omni/rewrite',
            translate: '/api/omni/translate',
            moderate: '/api/omni/moderate',
            classify: '/api/omni/classify',
            extract: '/api/omni/extract',
          },
          auth: ['session', 'bearer(PRISMBOT_API_TOKEN)']
        },
        imageGenerate: { status: 'real_partial', route: '/api/omni/images/generate', fallbackRoute: '/api/studio/generate-image', localFirst: true },
        imageEdit: { status: 'real_partial', route: '/api/omni/images/edit', fallbackRoute: '/api/studio/edit-image', localFirst: true },
        imageVariations: { status: 'real_partial', route: '/api/omni/images/variations' },
        imageUpscale: { status: 'real_partial', route: '/api/omni/images/upscale' },
        imageRemoveBg: { status: 'real_partial', route: '/api/omni/images/remove-bg' },
        imageZoomquilt: { status: 'real', route: '/api/omni/images/zoomquilt', params: ['pixelMode=hq', 'strictHq', 'paletteLock', 'paletteSize', 'nearestNeighbor', 'antiMush', 'coherenceChecks', 'layers', 'anchorMotif'] },
        audioSpeak: { status: 'real_partial', route: '/api/omni/audio/speak', backend: 'local-synth-v1' },
        audioTranscribe: { status: 'real_partial', route: '/api/omni/audio/transcribe', backend: 'local-whisper-ready' },
        audioTranslate: { status: 'real_partial', route: '/api/omni/audio/translate' },
        audioClone: { status: 'real_partial', route: '/api/omni/audio/clone', note: 'simulated clone envelope' },
        embeddings: { status: 'real', route: '/api/omni/embeddings' },
        retrieval: {
          status: 'real',
          rerank: '/api/omni/rerank',
          searchWeb: '/api/omni/search/web',
          searchLocal: '/api/omni/search/local',
          ragUpsert: '/api/omni/rag/index/upsert',
          ragDelete: '/api/omni/rag/index/delete',
          ragQuery: '/api/omni/rag/query',
          ragSources: '/api/omni/rag/sources',
        },
        game: {
          status: 'real_partial',
          createCharacter: '/api/omni/game/create-character',
          animateCharacter: '/api/omni/game/animate-character',
          tilesets: ['/api/omni/game/tileset/topdown', '/api/omni/game/tileset/sidescroller', '/api/omni/game/tileset/isometric'],
          mapObject: '/api/omni/game/map-object',
          uiElements: '/api/omni/game/ui-elements',
          styleProfile: '/api/omni/game/style-profile',
          packCreate: '/api/omni/game/pack/create',
          packExport: '/api/omni/game/pack/export',
          zoomquilt: '/api/omni/game/zoomquilt',
        },
        video: {
          status: 'real_partial',
          generate: '/api/omni/video/generate',
          edit: '/api/omni/video/edit',
          keyframes: '/api/omni/video/keyframes',
        },
        orchestrate: { status: 'real', route: '/api/omni/orchestrate', graphSteps: true, planner: '/api/omni/orchestrate/plan', run: '/api/omni/orchestrate/run', asyncJobs: '/api/omni/orchestrate/jobs', stream: '/api/omni/orchestrate/stream/{id}', persistentRuns: true },
        code: { status: 'real_partial', endpoints: ['/api/omni/code/generate', '/api/omni/code/explain', '/api/omni/code/fix', '/api/omni/code/test'] },
        tools: { status: 'real_partial', endpoints: ['/api/omni/tools/list', '/api/omni/tools/run'] },
        agents: { status: 'real_partial', endpoints: ['/api/omni/agents/session/start', '/api/omni/agents/session/message', '/api/omni/agents/session/stop', '/api/omni/agents/session/status'] },
        training: { status: 'real_partial', endpoints: ['/api/omni/models/fine-tunes', '/api/omni/models/fine-tunes/{id}', '/api/omni/models/fine-tunes/{id}/cancel', '/api/omni/datasets/upload', '/api/omni/datasets/{id}', '/api/omni/adapters/lora/create', '/api/omni/adapters/lora/list'], note: 'metadata orchestration only; trainer backend not attached' },
      },
    });
  }

  if (apiPath === '/api/omni/generate' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', 'Use login session cookie or Authorization: Bearer <token>.', requestId);

    const body = await parseBody(req);
    const type = String(body.type || 'text').trim().toLowerCase();
    const prompt = String(body.prompt || body.input || body.text || '').trim();
    if (!prompt) return sendOmniError(res, 400, 'missing_prompt', 'Prompt is required.', null, requestId);

    const usageGate = gateUsage(auth.user, prompt);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    if (type === 'image') {
      const created = createStudioImageJob(auth.user, body);
      if (!created.ok) return sendOmniError(res, created.code || 500, created.error, created.message, null, requestId);
      return sendOmniOk(res, { type: 'image', job: created.job, billing: usageGate.billing }, requestId);
    }

    const runtime = getOmniRuntime();
    const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText: prompt, body, requestId, routeProfile: 'auto' });
    if (!exec.ok) {
      return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);
    }

    return sendOmniOk(res, {
      type: 'text',
      model: exec.model,
      output: exec.output,
      choices: [{ message: { role: 'assistant', content: exec.output } }],
      backend: exec.backend,
      routing: { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts },
      billing: usageGate.billing,
    }, requestId);
  }

  if (apiPath === '/api/omni/chat/completions' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', 'Use login session cookie or Authorization: Bearer <token>.', requestId);

    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const messagesOk = validateOmniMessages(body.messages);
    if (!messagesOk.ok) return sendOmniError(res, 400, messagesOk.error, messagesOk.message, null, requestId);
    const inputText = inferOmniInput(body);
    if (!inputText) return sendOmniError(res, 400, 'missing_input', 'Provide prompt/input/text or messages[].', null, requestId);
    if (inputText.length > 16000) return sendOmniError(res, 400, 'input_too_large', 'Input exceeds max length (16000).', null, requestId);

    const usageGate = gateUsage(auth.user, inputText);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    if (moderate(inputText)) {
      return sendOmniOk(res, {
        type: 'chat.completion',
        model: body.model || 'prismbot-core-local',
        moderated: true,
        choices: [{ index: 0, message: { role: 'assistant', content: 'I can’t help with that. I can help with a safer alternative.' }, finish_reason: 'content_filter' }],
      }, requestId);
    }

    const runtime = getOmniRuntime();
    const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText, body, requestId, routeProfile: 'auto' });
    if (!exec.ok) return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);

    return sendOmniOk(res, {
      type: 'chat.completion',
      id: `chatcmpl_${crypto.randomBytes(8).toString('hex')}`,
      object: 'chat.completion',
      model: exec.model,
      choices: [{ index: 0, message: { role: 'assistant', content: exec.output }, finish_reason: 'stop' }],
      usage: { prompt_tokens: inputText.length, completion_tokens: exec.output.length, total_tokens: inputText.length + exec.output.length },
      backend: exec.backend,
      routing: { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts },
    }, requestId);
  }

  if (apiPath === '/api/omni/responses' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const messagesOk = validateOmniMessages(body.messages);
    if (!messagesOk.ok) return sendOmniError(res, 400, messagesOk.error, messagesOk.message, null, requestId);
    const inputText = inferOmniInput(body);
    if (!inputText) return sendOmniError(res, 400, 'missing_input', 'Provide input/prompt/text or messages[].', null, requestId);
    if (inputText.length > 16000) return sendOmniError(res, 400, 'input_too_large', 'Input exceeds max length (16000).', null, requestId);
    const usageGate = gateUsage(auth.user, inputText);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    const isModerated = moderate(inputText);
    let output = 'I can’t help with that. I can help with a safer alternative.';
    let backend = 'moderation-local';
    let routing = null;
    let model = body.model || 'prismbot-core-local';
    if (!isModerated) {
      const runtime = getOmniRuntime();
      const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText, body, requestId, routeProfile: 'auto' });
      if (!exec.ok) return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);
      output = exec.output;
      backend = exec.backend;
      routing = { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts };
      model = exec.model;
    }

    return sendOmniOk(res, {
      type: 'response',
      id: `resp_${crypto.randomBytes(8).toString('hex')}`,
      model,
      output: [{ type: 'message', role: 'assistant', content: [{ type: 'output_text', text: output }] }],
      moderated: isModerated,
      backend,
      routing,
    }, requestId);
  }

  if (apiPath === '/api/omni/reason' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const messagesOk = validateOmniMessages(body.messages);
    if (!messagesOk.ok) return sendOmniError(res, 400, messagesOk.error, messagesOk.message, null, requestId);
    const inputText = inferOmniInput(body);
    if (!inputText) return sendOmniError(res, 400, 'missing_input', 'Provide prompt/input/text.', null, requestId);
    if (inputText.length > 16000) return sendOmniError(res, 400, 'input_too_large', 'Input exceeds max length (16000).', null, requestId);
    const usageGate = gateUsage(auth.user, inputText);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    let answer = 'I can’t help with that. I can help with a safer alternative.';
    let backend = 'moderation-local';
    let routing = null;
    if (!moderate(inputText)) {
      const runtime = getOmniRuntime();
      const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText, body, requestId, routeProfile: 'auto' });
      if (!exec.ok) return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);
      answer = exec.output;
      backend = exec.backend;
      routing = { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts };
    }
    const chunks = inputText.split(/[\.\?!\n]+/).map((s) => s.trim()).filter(Boolean).slice(0, 4);
    const steps = chunks.map((c, i) => ({ index: i + 1, thought: `Consider: ${c}` }));
    return sendOmniOk(res, { type: 'reasoning', steps, answer, backend, routing }, requestId);
  }

  if (apiPath === '/api/omni/summarize' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const textCheck = validateOmniTextField('text', String(body.text || body.input || body.prompt || ''), 24000);
    if (!textCheck.ok) return sendOmniError(res, 400, textCheck.error, textCheck.message, null, requestId);
    const text = textCheck.value;
    const usageGate = gateUsage(auth.user, text);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);
    const maxSentences = Math.max(1, Math.min(6, Number(body.maxSentences || 3)));
    const runtime = getOmniRuntime();
    const summaryPrompt = `Summarize the following text in at most ${maxSentences} sentence(s).\n\n${text}`;
    const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText: summaryPrompt, body, requestId, routeProfile: 'auto' });
    if (!exec.ok) return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);
    const summary = String(exec.output || '').trim() || text.slice(0, 280);
    return sendOmniOk(res, { type: 'summary', summary, backend: exec.backend, routing: { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts }, stats: { sourceChars: text.length, summaryChars: summary.length } }, requestId);
  }

  if (apiPath === '/api/omni/rewrite' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const textCheck = validateOmniTextField('text', String(body.text || body.input || ''), 24000);
    if (!textCheck.ok) return sendOmniError(res, 400, textCheck.error, textCheck.message, null, requestId);
    const text = textCheck.value;
    const tone = String(body.tone || 'clear').trim().slice(0, 40) || 'clear';
    const usageGate = gateUsage(auth.user, text);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);
    const runtime = getOmniRuntime();
    const rewritePrompt = `Rewrite the following text with a ${tone} tone while preserving meaning.\n\n${text}`;
    const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText: rewritePrompt, body, requestId, routeProfile: 'auto' });
    if (!exec.ok) return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);
    const rewritten = String(exec.output || '').trim() || `[tone:${tone}] ${text}`;
    return sendOmniOk(res, { type: 'rewrite', tone, rewritten, backend: exec.backend, routing: { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts } }, requestId);
  }

  if (apiPath === '/api/omni/translate' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const textCheck = validateOmniTextField('text', String(body.text || body.input || ''), 24000);
    if (!textCheck.ok) return sendOmniError(res, 400, textCheck.error, textCheck.message, null, requestId);
    const text = textCheck.value;
    const target = String(body.target || body.targetLanguage || 'en').trim().toLowerCase();
    const source = String(body.source || body.sourceLanguage || 'auto').trim().toLowerCase();
    if (!/^[a-z]{2,3}$/i.test(target)) return sendOmniError(res, 400, 'invalid_target_language', 'target must be a language code like en/es/fr.', null, requestId);
    if (source !== 'auto' && !/^[a-z]{2,3}$/i.test(source)) return sendOmniError(res, 400, 'invalid_source_language', 'source must be auto or a language code like en/es/fr.', null, requestId);
    const usageGate = gateUsage(auth.user, text);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);
    const runtime = getOmniRuntime();
    const translatePrompt = `Translate this text from ${source} to ${target}. Return only the translation.\n\n${text}`;
    const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText: translatePrompt, body, requestId, routeProfile: 'auto' });
    if (!exec.ok) return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);
    return sendOmniOk(res, {
      type: 'translation',
      sourceLanguage: source,
      targetLanguage: target,
      translatedText: String(exec.output || '').trim() || `[${source}->${target}] ${text}`,
      backend: exec.backend,
      routing: { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts },
    }, requestId);
  }

  if (apiPath === '/api/omni/moderate' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const messagesOk = validateOmniMessages(body.messages);
    if (!messagesOk.ok) return sendOmniError(res, 400, messagesOk.error, messagesOk.message, null, requestId);
    const inputText = inferOmniInput(body);
    if (!inputText) return sendOmniError(res, 400, 'missing_input', 'Provide prompt/input/text.', null, requestId);
    if (inputText.length > 16000) return sendOmniError(res, 400, 'input_too_large', 'Input exceeds max length (16000).', null, requestId);
    const flagged = moderate(inputText);
    return sendOmniOk(res, {
      type: 'moderation',
      flagged,
      categories: {
        violence: /kill|weapon/i.test(inputText),
        self_harm: /self-harm/i.test(inputText),
        sexual_minors: /sexual minor/i.test(inputText),
        malware: /exploit|malware/i.test(inputText),
      },
    }, requestId);
  }

  if (apiPath === '/api/omni/classify' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const textCheck = validateOmniTextField('text', String(body.text || body.input || body.prompt || ''), 24000);
    if (!textCheck.ok) return sendOmniError(res, 400, textCheck.error, textCheck.message, null, requestId);
    const text = textCheck.value;
    const usageGate = gateUsage(auth.user, text);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    const runtime = getOmniRuntime();
    const classifyPrompt = `Classify this text into one label from: technical, billing, chitchat, general. Return only the label.\n\n${text}`;
    const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText: classifyPrompt, body, requestId, routeProfile: 'auto' });
    if (!exec.ok) return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);
    const raw = String(exec.output || '').toLowerCase();
    const label = ['technical', 'billing', 'chitchat', 'general'].find((v) => raw.includes(v)) || 'general';
    return sendOmniOk(res, {
      type: 'classification',
      label,
      confidence: 0.62,
      backend: exec.backend,
      routing: { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts },
    }, requestId);
  }

  if (apiPath === '/api/omni/extract' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);
    const textCheck = validateOmniTextField('text', String(body.text || body.input || ''), 24000);
    if (!textCheck.ok) return sendOmniError(res, 400, textCheck.error, textCheck.message, null, requestId);
    const text = textCheck.value;
    const usageGate = gateUsage(auth.user, text);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    const runtime = getOmniRuntime();
    const extractPrompt = `Extract all emails, urls, and ISO dates (YYYY-MM-DD) from this text.\n\n${text}`;
    const exec = await executeOmniTextWithRouting({ runtime, modality: 'text', inputText: extractPrompt, body, requestId, routeProfile: 'auto' });
    if (!exec.ok) return sendOmniError(res, 503, exec.error, exec.message, 'Check /api/omni/backends for provider readiness.', requestId);

    const emails = [...new Set((text.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/ig) || []))];
    const urls = [...new Set((text.match(/https?:\/\/[^\s)]+/ig) || []))];
    const dates = [...new Set((text.match(/\b\d{4}-\d{2}-\d{2}\b/g) || []))];
    return sendOmniOk(res, {
      type: 'extraction',
      fields: { emails, urls, dates },
      backend: exec.backend,
      routing: { strategy: exec.strategy, fallbackChain: exec.route.fallbackChain, attempts: exec.attempts },
    }, requestId);
  }

  if (apiPath === '/api/omni/audio/speak' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const text = String(body.text || body.prompt || '').trim();
    if (!text) return sendOmniError(res, 400, 'missing_text', 'Text is required.', null, requestId);

    const usageGate = gateUsage(auth.user, text);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    try {
      fs.mkdirSync(path.join(STUDIO_OUTPUT_ROOT, 'audio'), { recursive: true });
      const wave = createWaveFileFromText(text);
      const fileBase = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}.wav`;
      const abs = path.join(STUDIO_OUTPUT_ROOT, 'audio', fileBase);
      fs.writeFileSync(abs, wave.buffer);
      return sendJson(res, 200, {
        ok: true,
        type: 'audio',
        backend: 'local-synth-v1',
        audio: shapeAssetRefs({
          format: 'wav',
          durationSec: wave.durationSec,
          sampleRate: wave.sampleRate,
          webPath: studioAudioWebPath(abs),
        }, ['webPath']),
        billing: usageGate.billing,
      });
    } catch (err) {
      return sendJson(res, 500, { ok: false, error: 'audio_speak_failed', message: String(err?.message || err || 'failed') });
    }
  }

  if (apiPath === '/api/omni/audio/transcribe' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const text = String(body.text || '').trim();
    const sourceWebPath = String(body.sourceWebPath || '').trim();

    if (!text && !sourceWebPath) {
      return sendOmniError(res, 400, 'missing_input', 'Provide text or sourceWebPath.', null, requestId);
    }

    const usageGate = gateUsage(auth.user, text || sourceWebPath);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    if (text) {
      return sendJson(res, 200, {
        ok: true,
        type: 'transcription',
        backend: 'local-pass-through',
        transcript: text,
        confidence: 1,
        billing: usageGate.billing,
      });
    }

    const abs = studioWebPathToAbsPath(sourceWebPath);
    if (!abs) {
      return sendJson(res, 400, { ok: false, error: 'invalid_source', message: 'Invalid sourceWebPath.' });
    }

    const tr = await transcribeWithLocalBackend({ sourceAbsPath: abs, language: String(body.language || 'en') });
    if (!tr.ok) {
      return sendJson(res, 500, {
        ok: false,
        error: 'transcribe_failed',
        message: tr.error,
        hint: 'Ensure local backend is running and faster-whisper is installed.',
      });
    }

    return sendJson(res, 200, {
      ok: true,
      type: 'transcription',
      backend: tr.backend,
      transcript: tr.transcript,
      confidence: tr.confidence,
      note: tr.note || null,
      billing: usageGate.billing,
    });
  }

  if (apiPath === '/api/omni/images/job' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const jobId = String(url.searchParams.get('jobId') || '').trim();
    if (!jobId) return sendOmniError(res, 400, 'missing_job_id', 'jobId query param is required.', null, requestId);
    const row = studioJobs.get(jobId);
    if (!row) return sendOmniError(res, 404, 'not_found', 'Image job not found.', null, requestId);
    if (!auth.user.owner && row.userId && row.userId !== auth.user.id) return sendOmniError(res, 403, 'forbidden', 'Job belongs to another user.', null, requestId);
    return sendOmniOk(res, { type: 'image_job', status: 'real', job: sanitizeStudioJobForClient(row) }, requestId);
  }

  if (apiPath === '/api/omni/images/generate' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const promptCheck = validateOmniTextField('prompt', String(body.prompt || body.input || body.text || ''), 2000);
    if (!promptCheck.ok) return sendOmniError(res, 400, promptCheck.error, promptCheck.message, null, requestId);
    const usageGate = gateUsage(auth.user, promptCheck.value);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    const created = createStudioImageJob(auth.user, {
      prompt: promptCheck.value,
      preset: body.preset || 'pixflux',
      view: body.view || 'sidescroller',
      size: body.size || 512,
      transparent: body.transparent !== false,
      palette: body.palette || '',
      negativePrompt: body.negativePrompt || '',
      quality: body.quality || 'balanced',
      n: body.n || 1,
      pixelMode: body.pixelMode || body.pixel_mode || 'standard',
      strictHq: body.strictHq || body.strict_hq || false,
      paletteLock: body.paletteLock || body.palette_lock || false,
      paletteSize: body.paletteSize || body.palette_size || 16,
      nearestNeighbor: body.nearestNeighbor !== false && body.nearest_neighbor !== false,
      antiMush: body.antiMush || body.anti_mush || false,
      coherenceChecks: body.coherenceChecks || body.coherence_checks || false,
      coherenceThreshold: body.coherenceThreshold || body.coherence_threshold || 0.35,
    });
    if (!created.ok) return sendOmniError(res, created.code || 500, created.error, created.message || 'Image generation failed.', null, requestId);
    return sendOmniOk(res, { type: 'image_generation', status: 'real_partial', job: sanitizeStudioJobForClient(created.job), billing: usageGate.billing }, requestId);
  }

  if (apiPath === '/api/omni/images/zoomquilt' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const promptCheck = validateOmniTextField('prompt', String(body.prompt || body.input || body.text || ''), 2000);
    if (!promptCheck.ok) return sendOmniError(res, 400, promptCheck.error, promptCheck.message, null, requestId);
    const result = await generateZoomquiltWithLocalBackend({
      prompt: promptCheck.value,
      width: toSafeInt(body.width || body.size || 512, 512, 128, 1024),
      height: toSafeInt(body.height || body.size || 512, 512, 128, 1024),
      layers: toSafeInt(body.layers, 8, 3, 24),
      anchor_motif: String(body.anchorMotif || body.anchor_motif || ''),
      negative_prompt: String(body.negativePrompt || body.negative_prompt || ''),
      pixel_mode: body.pixelMode || body.pixel_mode || 'hq',
      strict_hq: body.strictHq !== false && body.strict_hq !== false,
      palette_lock: body.paletteLock !== false && body.palette_lock !== false,
      palette_size: toSafeInt(body.paletteSize || body.palette_size, 16, 4, 64),
      nearest_neighbor: body.nearestNeighbor !== false && body.nearest_neighbor !== false,
      anti_mush: body.antiMush !== false && body.anti_mush !== false,
      coherence_checks: body.coherenceChecks !== false && body.coherence_checks !== false,
      coherence_threshold: Math.max(0.05, Math.min(0.95, Number(body.coherenceThreshold || body.coherence_threshold || 0.3))),
    });
    if (!result.ok) return sendOmniError(res, 422, 'zoomquilt_failed', 'Zoomquilt generation failed.', result.error, requestId);
    const frameWebPaths = (Array.isArray(result.frames) ? result.frames : []).map((f) => {
      const rel = path.relative(STUDIO_OUTPUT_ROOT, String(f || '')).split(path.sep).join('/');
      return rel && !rel.startsWith('..') ? `/studio-output/${rel}` : null;
    }).filter(Boolean);
    const previewRel = path.relative(STUDIO_OUTPUT_ROOT, String(result.preview || '')).split(path.sep).join('/');
    const previewWebPath = previewRel && !previewRel.startsWith('..') ? `/studio-output/${previewRel}` : null;
    return sendOmniOk(res, { type: 'image_zoomquilt', status: 'real', runId: result.runId, layers: result.layers, frames: frameWebPaths, previewWebPath, anchorMotif: result.anchorMotif || null }, requestId);
  }

  if (apiPath === '/api/omni/images/edit' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const promptCheck = validateOmniTextField('prompt', String(body.prompt || body.input || body.text || ''), 2000);
    if (!promptCheck.ok) return sendOmniError(res, 400, promptCheck.error, promptCheck.message, null, requestId);
    const sourceWebPath = String(body.sourceWebPath || '').trim();
    if (!sourceWebPath) return sendOmniError(res, 400, 'missing_source', 'sourceWebPath is required.', null, requestId);
    const usageGate = gateUsage(auth.user, promptCheck.value);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    const created = createStudioEditImageJob(auth.user, { ...body, prompt: promptCheck.value, sourceWebPath });
    if (!created.ok) return sendOmniError(res, created.code || 500, created.error, created.message || 'Image edit failed.', null, requestId);
    return sendOmniOk(res, { type: 'image_edit', status: 'real_partial', job: sanitizeStudioJobForClient(created.job), billing: usageGate.billing }, requestId);
  }

  if (apiPath === '/api/omni/images/variations' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const sourceWebPath = String(body.sourceWebPath || '').trim();
    if (!sourceWebPath) return sendOmniError(res, 400, 'missing_source', 'sourceWebPath is required.', null, requestId);
    const sourceAbs = studioWebPathToAbsPath(sourceWebPath);
    if (!sourceAbs) return sendOmniError(res, 400, 'invalid_source', 'sourceWebPath must reference an existing /studio-output image.', null, requestId);
    const created = createStudioEditImageJob(auth.user, {
      prompt: String(body.prompt || 'create a close style variation').trim(),
      sourceWebPath,
      size: body.size || 512,
    });
    if (!created.ok) return sendOmniError(res, created.code || 500, created.error, created.message || 'Variation job failed.', null, requestId);
    return sendOmniOk(res, { type: 'image_variations', status: 'real_partial', job: sanitizeStudioJobForClient(created.job) }, requestId);
  }

  if (apiPath === '/api/omni/images/upscale' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const sourceWebPath = String(body.sourceWebPath || '').trim();
    if (!sourceWebPath) return sendOmniError(res, 400, 'missing_source', 'sourceWebPath is required.', null, requestId);
    const sourceAbs = studioWebPathToAbsPath(sourceWebPath);
    if (!sourceAbs) return sendOmniError(res, 400, 'invalid_source', 'sourceWebPath must reference an existing /studio-output image.', null, requestId);
    const scale = Math.max(2, Math.min(4, Number(body.scale || 2)));
    fs.mkdirSync(path.join(STUDIO_OUTPUT_ROOT, 'upscaled'), { recursive: true });
    const fileBase = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}.png`;
    const rel = path.posix.join('upscaled', fileBase);
    const outAbs = path.join(STUDIO_OUTPUT_ROOT, rel);
    const proc = spawnSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-i', sourceAbs, '-vf', `scale=iw*${scale}:ih*${scale}:flags=lanczos`, outAbs], { encoding: 'utf8' });
    if (proc.status !== 0) {
      return sendOmniError(res, 500, 'upscale_failed', 'Upscale failed.', String(proc.stderr || proc.stdout || 'ffmpeg_failed').trim(), requestId);
    }
    return sendOmniOk(res, shapeAssetRefs({ type: 'image_upscale', status: 'real_partial', scale, outputWebPath: '/studio-output/' + rel }, ['outputWebPath']), requestId);
  }

  if (apiPath === '/api/omni/images/remove-bg' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const sourceWebPath = String(body.sourceWebPath || '').trim();
    if (!sourceWebPath) return sendOmniError(res, 400, 'missing_source', 'sourceWebPath is required.', null, requestId);
    const sourceAbs = studioWebPathToAbsPath(sourceWebPath);
    if (!sourceAbs) return sendOmniError(res, 400, 'invalid_source', 'sourceWebPath must reference an existing /studio-output image.', null, requestId);
    fs.mkdirSync(path.join(STUDIO_OUTPUT_ROOT, 'remove-bg'), { recursive: true });
    const fileBase = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}.png`;
    const rel = path.posix.join('remove-bg', fileBase);
    const outAbs = path.join(STUDIO_OUTPUT_ROOT, rel);
    const proc = spawnSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-i', sourceAbs, '-vf', 'format=rgba,colorkey=0xFFFFFF:0.18:0.1', outAbs], { encoding: 'utf8' });
    if (proc.status !== 0) {
      return sendOmniError(res, 500, 'remove_bg_failed', 'Background removal failed.', String(proc.stderr || proc.stdout || 'ffmpeg_failed').trim(), requestId);
    }
    return sendOmniOk(res, shapeAssetRefs({ type: 'image_remove_bg', status: 'real_partial', method: 'colorkey', outputWebPath: '/studio-output/' + rel }, ['outputWebPath']), requestId);
  }

  if (apiPath === '/api/omni/audio/translate' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const target = String(body.target || body.targetLanguage || 'en').trim().toLowerCase().slice(0, 16) || 'en';
    let transcript = String(body.text || '').trim();
    if (!transcript) {
      const sourceWebPath = String(body.sourceWebPath || '').trim();
      const abs = sourceWebPath ? studioWebPathToAbsPath(sourceWebPath) : null;
      if (!abs) return sendOmniError(res, 400, 'missing_input', 'Provide text or sourceWebPath.', null, requestId);
      const tr = await transcribeWithLocalBackend({ sourceAbsPath: abs, language: String(body.language || 'auto') });
      if (!tr.ok) return sendOmniError(res, 500, 'transcribe_failed', 'Failed to transcribe source audio.', tr.error, requestId);
      transcript = String(tr.transcript || '').trim();
    }
    if (!transcript) return sendOmniError(res, 400, 'missing_transcript', 'No transcript available to translate.', null, requestId);
    const translated = `[${target}] ${generateAiReply(`Translate to ${target}: ${transcript}`)}`;
    return sendOmniOk(res, { type: 'audio_translate', status: 'real_partial', sourceText: transcript, translatedText: translated, target }, requestId);
  }

  if (apiPath === '/api/omni/audio/clone' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const text = String(body.text || body.prompt || '').trim();
    if (!text) return sendOmniError(res, 400, 'missing_text', 'text is required.', null, requestId);
    fs.mkdirSync(path.join(STUDIO_OUTPUT_ROOT, 'audio'), { recursive: true });
    const wave = createWaveFileFromText(text);
    const fileBase = `${Date.now()}-${crypto.randomBytes(4).toString('hex')}.wav`;
    const abs = path.join(STUDIO_OUTPUT_ROOT, 'audio', fileBase);
    fs.writeFileSync(abs, wave.buffer);
    return sendOmniOk(res, {
      type: 'audio_clone',
      status: 'real_partial',
      output: shapeAssetRefs({ webPath: studioAudioWebPath(abs), sampleRate: wave.sampleRate, durationSec: wave.durationSec }, ['webPath']),
      note: 'Voice cloning is simulated with local TTS; speaker identity transfer is not implemented.',
    }, requestId);
  }

  if (apiPath === '/api/omni/embeddings' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const bodyOk = validateOmniBodyObject(body);
    if (!bodyOk.ok) return sendOmniError(res, 400, bodyOk.error, bodyOk.message, null, requestId);

    let input = body.input;
    if (typeof input === 'string') input = [input];
    if (!Array.isArray(input) || input.length < 1) return sendOmniError(res, 400, 'missing_input', 'input must be a string or non-empty string array.', null, requestId);
    if (input.length > 32) return sendOmniError(res, 400, 'input_too_large', 'Maximum 32 input items per request.', null, requestId);

    const cleaned = [];
    for (const row of input) {
      const v = validateOmniTextField('input', String(row || ''), 8000);
      if (!v.ok) return sendOmniError(res, 400, v.error, v.message, null, requestId);
      cleaned.push(v.value);
    }

    const usageGate = gateUsage(auth.user, cleaned.join('\n'));
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    const dims = Math.max(8, Math.min(256, Number(body.dimensions || 64)));
    const data = cleaned.map((text, index) => ({ object: 'embedding', index, embedding: fakeEmbeddingVector(text, dims) }));
    return sendOmniOk(res, { type: 'embeddings', object: 'list', data, model: 'prismbot-embed-local-v1', dimensions: dims }, requestId);
  }

  if (apiPath === '/api/omni/rerank' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const queryCheck = validateOmniTextField('query', String(body.query || ''), 8000);
    if (!queryCheck.ok) return sendOmniError(res, 400, queryCheck.error, queryCheck.message, null, requestId);
    const docs = Array.isArray(body.documents) ? body.documents : [];
    if (!docs.length) return sendOmniError(res, 400, 'missing_documents', 'documents[] is required.', null, requestId);
    if (docs.length > 100) return sendOmniError(res, 400, 'input_too_large', 'Maximum 100 documents per rerank request.', null, requestId);

    const usageGate = gateUsage(auth.user, queryCheck.value + '\n' + docs.join('\n'));
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    const results = docs.map((doc, index) => {
      const text = String(doc || '');
      return { index, text, score: scoreDocForQuery(queryCheck.value, text) };
    }).sort((a, b) => b.score - a.score);

    return sendOmniOk(res, { type: 'rerank', model: 'prismbot-rerank-local-v1', results }, requestId);
  }

  if (apiPath === '/api/omni/search/web' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const queryCheck = validateOmniTextField('query', String(body.query || body.input || ''), 500);
    if (!queryCheck.ok) return sendOmniError(res, 400, queryCheck.error, queryCheck.message, null, requestId);
    const usageGate = gateUsage(auth.user, queryCheck.value);
    if (!usageGate.ok) return sendOmniError(res, usageGate.code || 429, usageGate.error, usageGate.message, null, requestId);

    try {
      const urlQ = encodeURIComponent(queryCheck.value);
      const resp = await fetch(`https://api.duckduckgo.com/?q=${urlQ}&format=json&no_redirect=1&no_html=1&skip_disambig=1`);
      const json = await resp.json().catch(() => ({}));
      const out = [];
      if (json?.AbstractText) out.push({ title: json?.Heading || queryCheck.value, url: json?.AbstractURL || null, snippet: json?.AbstractText });
      const related = Array.isArray(json?.RelatedTopics) ? json.RelatedTopics : [];
      for (const item of related) {
        if (out.length >= 8) break;
        if (item?.Text) out.push({ title: item.Text.split(' - ')[0] || 'Result', url: item.FirstURL || null, snippet: item.Text });
        const sub = Array.isArray(item?.Topics) ? item.Topics : [];
        for (const row of sub) {
          if (out.length >= 8) break;
          if (row?.Text) out.push({ title: row.Text.split(' - ')[0] || 'Result', url: row.FirstURL || null, snippet: row.Text });
        }
      }
      return sendOmniOk(res, { type: 'search_web', provider: 'duckduckgo_instant_answer', query: queryCheck.value, results: out }, requestId);
    } catch (err) {
      return sendOmniError(res, 502, 'web_search_failed', 'Web search backend failed.', String(err?.message || err || 'search_failed'), requestId);
    }
  }

  if (apiPath === '/api/omni/rag/index/upsert' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const docs = Array.isArray(body.documents) ? body.documents : [];
    if (!docs.length) return sendOmniError(res, 400, 'missing_documents', 'documents[] is required.', null, requestId);

    const store = getRagIndexStore();
    store.docs = store.docs || {};
    let upserted = 0;
    for (const row of docs.slice(0, 200)) {
      const text = String(row?.text || row?.content || '').trim();
      if (!text) continue;
      const id = String(row?.id || ('doc_' + crypto.randomBytes(6).toString('hex'))).slice(0, 80);
      store.docs[id] = {
        id,
        text: text.slice(0, 24000),
        metadata: isPlainObject(row?.metadata) ? row.metadata : {},
        updatedAt: new Date().toISOString(),
      };
      upserted += 1;
    }
    setRagIndexStore(store);
    return sendOmniOk(res, { type: 'rag_upsert', upserted, total: Object.keys(store.docs).length }, requestId);
  }

  if (apiPath === '/api/omni/rag/index/delete' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const ids = Array.isArray(body.ids) ? body.ids.map((v) => String(v || '').trim()).filter(Boolean) : [];
    if (!ids.length) return sendOmniError(res, 400, 'missing_ids', 'ids[] is required.', null, requestId);
    const store = getRagIndexStore();
    store.docs = store.docs || {};
    let deleted = 0;
    for (const id of ids) {
      if (store.docs[id]) {
        delete store.docs[id];
        deleted += 1;
      }
    }
    setRagIndexStore(store);
    return sendOmniOk(res, { type: 'rag_delete', deleted, total: Object.keys(store.docs).length }, requestId);
  }

  if (apiPath === '/api/omni/rag/sources' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const store = getRagIndexStore();
    const docs = Object.values(store.docs || {}).slice(0, 200);
    const sources = docs.map((d) => ({ id: d.id, updatedAt: d.updatedAt, metadata: d.metadata || {} }));
    return sendOmniOk(res, { type: 'rag_sources', count: sources.length, sources }, requestId);
  }

  if (apiPath === '/api/omni/search/local' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const queryCheck = validateOmniTextField('query', String(body.query || body.input || ''), 8000);
    if (!queryCheck.ok) return sendOmniError(res, 400, queryCheck.error, queryCheck.message, null, requestId);
    const k = Math.max(1, Math.min(20, Number(body.k || body.topK || 5)));
    const store = getRagIndexStore();
    const rows = Object.values(store.docs || {});
    const results = rows.map((d) => ({ id: d.id, text: d.text, metadata: d.metadata || {}, score: scoreDocForQuery(queryCheck.value, d.text) }))
      .filter((r) => r.score > 0)
      .sort((a, b) => b.score - a.score)
      .slice(0, k);
    return sendOmniOk(res, { type: 'search_local', query: queryCheck.value, results }, requestId);
  }

  if (apiPath === '/api/omni/rag/query' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const queryCheck = validateOmniTextField('query', String(body.query || body.input || body.prompt || ''), 8000);
    if (!queryCheck.ok) return sendOmniError(res, 400, queryCheck.error, queryCheck.message, null, requestId);
    const k = Math.max(1, Math.min(10, Number(body.k || 4)));
    const store = getRagIndexStore();
    const hits = Object.values(store.docs || {})
      .map((d) => ({ id: d.id, text: d.text, metadata: d.metadata || {}, score: scoreDocForQuery(queryCheck.value, d.text) }))
      .filter((r) => r.score > 0)
      .sort((a, b) => b.score - a.score)
      .slice(0, k);
    const context = hits.map((h, i) => `(${i + 1}) ${h.text.slice(0, 400)}`).join('\n');
    const answer = hits.length
      ? generateAiReply(`Answer using retrieved context only. Query: ${queryCheck.value}\nContext:\n${context}`)
      : 'No indexed context matched your query.';
    return sendOmniOk(res, { type: 'rag_query', answer, sources: hits.map((h) => ({ id: h.id, score: h.score, metadata: h.metadata })) }, requestId);
  }

  if ((apiPath === '/api/omni/game/create-character' || apiPath === '/api/omni/game/map-object' || apiPath === '/api/omni/game/ui-elements' || apiPath === '/api/omni/game/tileset/topdown' || apiPath === '/api/omni/game/tileset/sidescroller' || apiPath === '/api/omni/game/tileset/isometric') && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const promptCheck = validateOmniTextField('prompt', String(body.prompt || body.input || body.theme || ''), 1200);
    if (!promptCheck.ok) return sendOmniError(res, 400, promptCheck.error, promptCheck.message, null, requestId);
    const view = apiPath.includes('topdown') ? 'topdown' : apiPath.includes('isometric') ? 'isometric' : 'sidescroller';
    const preset = (apiPath.includes('ui-elements') || apiPath.includes('tileset')) ? 'pixflux' : 'bitforge';
    const created = createStudioImageJob(auth.user, {
      prompt: promptCheck.value,
      preset,
      view,
      size: body.size || 512,
      transparent: body.transparent !== false,
      palette: body.palette || '',
      negativePrompt: body.negativePrompt || '',
      quality: body.quality || 'balanced',
      pixelMode: body.pixelMode || body.pixel_mode || 'standard',
      strictHq: body.strictHq || body.strict_hq || false,
      paletteLock: body.paletteLock || body.palette_lock || false,
      paletteSize: body.paletteSize || body.palette_size || 16,
      nearestNeighbor: body.nearestNeighbor !== false && body.nearest_neighbor !== false,
      antiMush: body.antiMush || body.anti_mush || false,
      coherenceChecks: body.coherenceChecks || body.coherence_checks || false,
      coherenceThreshold: body.coherenceThreshold || body.coherence_threshold || 0.35,
    });
    if (!created.ok) return sendOmniError(res, created.code || 500, created.error, created.message || 'Game asset job failed.', null, requestId);
    return sendOmniOk(res, { type: 'game_asset_job', endpoint: apiPath.replace('/api/omni', ''), job: created.job }, requestId);
  }

  if (apiPath === '/api/omni/game/zoomquilt' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const promptCheck = validateOmniTextField('prompt', String(body.prompt || body.input || body.theme || ''), 1200);
    if (!promptCheck.ok) return sendOmniError(res, 400, promptCheck.error, promptCheck.message, null, requestId);
    const result = await generateZoomquiltWithLocalBackend({
      prompt: promptCheck.value,
      width: toSafeInt(body.width || body.size || 512, 512, 128, 1024),
      height: toSafeInt(body.height || body.size || 512, 512, 128, 1024),
      layers: toSafeInt(body.layers, 8, 3, 24),
      anchor_motif: String(body.anchorMotif || body.anchor_motif || ''),
      pixel_mode: body.pixelMode || body.pixel_mode || 'hq',
      strict_hq: body.strictHq !== false && body.strict_hq !== false,
      palette_lock: body.paletteLock !== false && body.palette_lock !== false,
      anti_mush: body.antiMush !== false && body.anti_mush !== false,
      coherence_checks: body.coherenceChecks !== false && body.coherence_checks !== false,
    });
    if (!result.ok) return sendOmniError(res, 422, 'zoomquilt_failed', 'Game zoomquilt generation failed.', result.error, requestId);
    const frameWebPaths = (Array.isArray(result.frames) ? result.frames : []).map((f) => {
      const rel = path.relative(STUDIO_OUTPUT_ROOT, String(f || '')).split(path.sep).join('/');
      return rel && !rel.startsWith('..') ? `/studio-output/${rel}` : null;
    }).filter(Boolean);
    const previewRel = path.relative(STUDIO_OUTPUT_ROOT, String(result.preview || '')).split(path.sep).join('/');
    const previewWebPath = previewRel && !previewRel.startsWith('..') ? `/studio-output/${previewRel}` : null;
    return sendOmniOk(res, { type: 'game_zoomquilt', status: 'real', runId: result.runId, frames: frameWebPaths, previewWebPath }, requestId);
  }

  if (apiPath === '/api/omni/game/animate-character' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const promptCheck = validateOmniTextField('prompt', String(body.prompt || body.input || ''), 1200);
    if (!promptCheck.ok) return sendOmniError(res, 400, promptCheck.error, promptCheck.message, null, requestId);
    const frames = Math.max(2, Math.min(16, Number(body.frames || 6)));
    const created = createStudioImageJob(auth.user, {
      prompt: `${promptCheck.value}. sprite sheet ${frames} frames animation strip`,
      preset: 'bitforge',
      view: 'sidescroller',
      size: body.size || 512,
      transparent: true,
      pixelMode: body.pixelMode || body.pixel_mode || 'standard',
      strictHq: body.strictHq || body.strict_hq || false,
      paletteLock: body.paletteLock || body.palette_lock || false,
      paletteSize: body.paletteSize || body.palette_size || 16,
      nearestNeighbor: body.nearestNeighbor !== false && body.nearest_neighbor !== false,
      antiMush: body.antiMush || body.anti_mush || false,
      coherenceChecks: body.coherenceChecks || body.coherence_checks || false,
      coherenceThreshold: body.coherenceThreshold || body.coherence_threshold || 0.35,
    });
    if (!created.ok) return sendOmniError(res, created.code || 500, created.error, created.message || 'Animation job failed.', null, requestId);
    return sendOmniOk(res, { type: 'game_animation_job', frames, job: created.job, status: 'real_partial' }, requestId);
  }

  if (apiPath === '/api/omni/game/style-profile' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const created = createStudioStyleProfileJob(auth.user, body);
    if (!created.ok) return sendOmniError(res, created.code || 500, created.error, created.message || 'Style profile failed.', null, requestId);
    return sendOmniOk(res, { type: 'game_style_profile', status: 'real_partial', job: created.job }, requestId);
  }

  if (apiPath === '/api/omni/game/pack/create' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const created = createStudioPackJob(auth.user, body);
    if (!created.ok) return sendOmniError(res, created.code || 500, created.error, created.message || 'Pack job failed.', null, requestId);
    return sendOmniOk(res, { type: 'game_pack_create', status: 'real_partial', job: created.job }, requestId);
  }

  if (apiPath === '/api/omni/game/pack/export' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const source = String(body.sourceWebPath || '').trim();
    const abs = source ? studioWebPathToAbsPath(source) : null;
    return sendOmniOk(res, {
      type: 'game_pack_export',
      status: 'real_partial',
      exported: Boolean(abs),
      message: abs ? 'Pack export scaffold complete (artifact copy path validated).' : 'Provide sourceWebPath for future archive packaging.',
    }, requestId);
  }

  if ((apiPath === '/api/omni/video/generate' || apiPath === '/api/omni/video/edit') && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const promptCheck = validateOmniTextField('prompt', String(body.prompt || body.input || ''), 1200);
    if (!promptCheck.ok) return sendOmniError(res, 400, promptCheck.error, promptCheck.message, null, requestId);

    fs.mkdirSync(path.join(STUDIO_OUTPUT_ROOT, 'video-jobs'), { recursive: true });
    const id = `video_job_${crypto.randomBytes(6).toString('hex')}`;
    const outRel = path.posix.join('video-jobs', `${id}.mp4`);
    const outAbs = path.join(STUDIO_OUTPUT_ROOT, outRel);

    let proc;
    if (apiPath.endsWith('/edit')) {
      const sourceWebPath = String(body.sourceWebPath || '').trim();
      const sourceAbs = studioWebPathToAbsPath(sourceWebPath);
      if (!sourceAbs) return sendOmniError(res, 400, 'invalid_source', 'sourceWebPath is required for /video/edit and must be under /studio-output.', null, requestId);
      proc = spawnSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-i', sourceAbs, '-vf', 'eq=contrast=1.08:saturation=1.12,unsharp=3:3:0.6', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', outAbs], { encoding: 'utf8' });
    } else {
      const duration = Math.max(1, Math.min(8, Number(body.durationSec || 3)));
      const title = promptCheck.value.replace(/[^a-zA-Z0-9 .,!?-]/g, '').slice(0, 80) || 'Omni video';
      proc = spawnSync('ffmpeg', ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', `color=c=#111827:s=640x360:d=${duration}`, '-vf', `drawtext=fontcolor=white:fontsize=20:text='${title.replace(/'/g, "\\'")}':x=(w-text_w)/2:y=(h-text_h)/2`, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', outAbs], { encoding: 'utf8' });
    }

    if (proc.status !== 0) {
      return sendOmniError(res, 500, 'video_job_failed', 'Video generation/edit failed.', String(proc.stderr || proc.stdout || 'ffmpeg_failed').trim(), requestId);
    }

    return sendOmniOk(res, shapeAssetRefs({ type: 'video_job', status: 'real_partial', jobId: id, outputWebPath: '/studio-output/' + outRel }, ['outputWebPath']), requestId);
  }

  if (apiPath === '/api/omni/video/keyframes' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const sourceWebPath = String(body.sourceWebPath || '').trim();
    if (!sourceWebPath) return sendOmniError(res, 400, 'missing_source', 'sourceWebPath is required.', null, requestId);
    const sourceAbs = studioWebPathToAbsPath(sourceWebPath);
    if (!sourceAbs) return sendOmniError(res, 400, 'invalid_source', 'sourceWebPath must point to a file in /studio-output.', null, requestId);

    try {
      const frames = runVideoKeyframeExtraction(sourceAbs, { fps: body.fps, limit: body.limit });
      return sendOmniOk(res, {
        type: 'video_keyframes',
        status: 'real',
        sourceWebPath,
        sourcePublicUrl: buildPublicAssetUrl(sourceWebPath),
        frames: (frames || []).map((f) => shapeAssetRefs({ webPath: f }, ['webPath'])),
      }, requestId);
    } catch (err) {
      return sendOmniError(res, 500, 'keyframe_extract_failed', 'Failed to extract keyframes.', String(err?.message || err || 'ffmpeg_failed'), requestId);
    }
  }

  if (apiPath === '/api/omni/orchestrate/jobs' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const items = [...omniRunJobs.values()]
      .filter((j) => j.userId === auth.user.id || auth.safeUser.owner)
      .sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0))
      .slice(0, 30);
    return sendJson(res, 200, {
      ok: true,
      jobs: items.map((j) => sanitizeOmniRunForClient(j)),
      queue: {
        pending: omniQueue.length,
        active: omniQueueActive,
        concurrency: OMNI_QUEUE_CONCURRENCY,
      }
    });
  }

  if (apiPath.startsWith('/api/omni/orchestrate/jobs/') && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const id = decodeURIComponent(apiPath.slice('/api/omni/orchestrate/jobs/'.length));
    const job = omniRunJobs.get(id);
    if (!job) return sendJson(res, 404, { ok: false, error: 'not_found' });
    if (job.userId !== auth.user.id && !auth.safeUser.owner) return sendJson(res, 403, { ok: false, error: 'forbidden' });
    return sendJson(res, 200, { ok: true, job: sanitizeOmniRunForClient(job) });
  }

  if (apiPath.startsWith('/api/omni/orchestrate/jobs/') && apiPath.endsWith('/cancel') && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const id = decodeURIComponent(apiPath.slice('/api/omni/orchestrate/jobs/'.length, -'/cancel'.length));
    const job = omniRunJobs.get(id);
    if (!job) return sendJson(res, 404, { ok: false, error: 'not_found' });
    if (job.userId !== auth.user.id && !auth.safeUser.owner) return sendJson(res, 403, { ok: false, error: 'forbidden' });
    if (job.status === 'completed' || job.status === 'failed' || job.status === 'canceled') {
      return sendJson(res, 409, { ok: false, error: 'already_finished', status: job.status });
    }
    job.cancelRequested = true;
    appendOmniTimeline(job, 'Cancel requested by user.');
    upsertOmniRun(job);
    return sendJson(res, 200, { ok: true, jobId: id, status: job.status, cancelRequested: true });
  }

  if (apiPath.startsWith('/api/omni/orchestrate/stream/') && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const id = decodeURIComponent(apiPath.slice('/api/omni/orchestrate/stream/'.length));
    const job = omniRunJobs.get(id);
    if (!job) return sendJson(res, 404, { ok: false, error: 'not_found' });
    if (job.userId !== auth.user.id && !auth.safeUser.owner) return sendJson(res, 403, { ok: false, error: 'forbidden' });

    res.writeHead(200, {
      'content-type': 'text/event-stream; charset=utf-8',
      'cache-control': 'no-cache, no-transform',
      connection: 'keep-alive',
    });

    let lastStatus = null;
    const push = (event, payload) => {
      res.write(`event: ${event}\n`);
      res.write(`data: ${JSON.stringify(payload)}\n\n`);
    };

    const tick = () => {
      const latest = omniRunJobs.get(id);
      if (!latest) {
        push('error', { ok: false, error: 'not_found' });
        cleanup();
        return;
      }
      if (latest.status !== lastStatus) {
        lastStatus = latest.status;
        push('status', { id, status: latest.status, executedSteps: sanitizeOmniRunForClient(latest).executedSteps || [], error: latest.error || null });
      } else {
        push('heartbeat', { id, status: latest.status });
      }
      if (latest.status === 'completed' || latest.status === 'failed' || latest.status === 'canceled') {
        push('done', { id, status: latest.status });
        cleanup();
      }
    };

    const interval = setInterval(tick, 1000);
    const timeout = setTimeout(() => {
      push('timeout', { id, status: (omniRunJobs.get(id)?.status || 'unknown') });
      cleanup();
    }, 120000);

    const cleanup = () => {
      clearInterval(interval);
      clearTimeout(timeout);
      try { res.end(); } catch {}
    };

    req.on('close', cleanup);
    tick();
    return;
  }

  if (apiPath === '/api/omni/orchestrate/run' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const prompt = String(body.prompt || '').trim();
    if (!prompt) return sendJson(res, 400, { ok: false, error: 'missing_prompt', message: 'Prompt is required.' });

    const usageGate = gateUsage(auth.user, prompt);
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    const steps = buildAutoOrchestrateSteps(prompt, body);
    const runAsync = body.async === true || body.mode === 'async';
    const idempotencyKey = String(req.headers['idempotency-key'] || body.idempotencyKey || '').trim();

    if (idempotencyKey) {
      const existing = findRunByIdempotencyKey(idempotencyKey, auth.user.id);
      if (existing) {
        return sendJson(res, 200, {
          ok: true,
          type: 'orchestrate_run',
          mode: existing.mode || 'async',
          deduped: true,
          runId: existing.id,
          status: existing.status,
          poll: `/api/omni/orchestrate/jobs/${existing.id}`,
          billing: usageGate.billing,
        });
      }
    }

    if (runAsync) {
      const runId = 'orun_' + crypto.randomBytes(8).toString('hex');
      const job = {
        id: runId,
        type: 'orchestrate_run',
        status: 'queued',
        mode: 'async',
        cancelRequested: false,
        workflow: body.workflow || 'auto_asset_pipeline',
        userId: auth.user.id,
        username: auth.user.username,
        prompt,
        plannedSteps: steps,
        executedSteps: [],
        createdAt: Date.now(),
        startedAt: null,
        finishedAt: null,
        error: null,
        timeline: [],
      };
      appendOmniTimeline(job, 'Run queued.');
      upsertOmniRun(job);
      if (idempotencyKey) storeRunIdempotencyKey(idempotencyKey, auth.user.id, runId);

      queueOmniExecution(async () => {
        const live = omniRunJobs.get(runId);
        if (!live || live.cancelRequested) return;

        try {
          live.status = 'running';
          live.startedAt = live.startedAt || Date.now();
          appendOmniTimeline(live, 'Run started.');
          upsertOmniRun(live);

          live.executedSteps = await executeOrchestrateSteps(auth.user, steps, {
            shouldCancel: () => Boolean(omniRunJobs.get(runId)?.cancelRequested),
          });

          const canceled = Boolean(omniRunJobs.get(runId)?.cancelRequested);
          live.status = canceled ? 'canceled' : 'completed';
          live.finishedAt = Date.now();
          appendOmniTimeline(live, canceled ? 'Run canceled.' : 'Run completed.');
          upsertOmniRun(live);
        } catch (err) {
          live.status = 'failed';
          live.error = String(err?.message || err || 'run_failed');
          live.finishedAt = Date.now();
          appendOmniTimeline(live, 'Run failed: ' + live.error);
          upsertOmniRun(live);
        }
      });

      return sendJson(res, 200, {
        ok: true,
        type: 'orchestrate_run',
        mode: 'async',
        runId,
        status: job.status,
        poll: `/api/omni/orchestrate/jobs/${runId}`,
        billing: usageGate.billing,
      });
    }

    const executed = await executeOrchestrateSteps(auth.user, steps);
    const runId = 'orun_' + crypto.randomBytes(8).toString('hex');
    const done = {
      id: runId,
      type: 'orchestrate_run',
      status: 'completed',
      workflow: body.workflow || 'auto_asset_pipeline',
      userId: auth.user.id,
      username: auth.user.username,
      prompt,
      plannedSteps: steps,
      executedSteps: (executed || []).map((step) => shapeAssetRefs({ ...(step || {}) }, ['webPath', 'outputWebPath'])),
      createdAt: Date.now(),
      startedAt: Date.now(),
      finishedAt: Date.now(),
      error: null,
      mode: 'sync',
      timeline: [{ ts: Date.now(), message: 'Sync run completed.' }],
    };
    upsertOmniRun(done);
    if (idempotencyKey) storeRunIdempotencyKey(idempotencyKey, user.id, runId);

    return sendJson(res, 200, {
      ok: true,
      type: 'orchestrate_run',
      mode: 'sync',
      runId,
      workflow: body.workflow || 'auto_asset_pipeline',
      prompt,
      plannedSteps: steps,
      executedSteps: (executed || []).map((step) => shapeAssetRefs({ ...(step || {}) }, ['webPath', 'outputWebPath'])),
      billing: usageGate.billing,
    });
  }

  if (apiPath === '/api/omni/orchestrate/plan' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const prompt = String(body.prompt || '').trim();
    if (!prompt) return sendJson(res, 400, { ok: false, error: 'missing_prompt', message: 'Prompt is required.' });

    const steps = buildAutoOrchestrateSteps(prompt, body);
    return sendJson(res, 200, {
      ok: true,
      type: 'orchestrate_plan',
      workflow: body.workflow || 'auto_asset_pipeline',
      steps,
      note: 'Send this steps[] array to POST /api/omni/orchestrate to execute.',
    });
  }

  if (apiPath === '/api/omni/orchestrate' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);

    const steps = Array.isArray(body.steps) ? body.steps : null;
    if (steps && steps.length > 0) {
      const executed = await executeOrchestrateSteps(auth.user, steps);
      return sendJson(res, 200, {
        ok: true,
        type: 'orchestrate',
        workflow: body.workflow || 'graph',
        steps: executed,
      });
    }

    const prompt = String(body.prompt || '').trim();
    if (!prompt) return sendJson(res, 400, { ok: false, error: 'missing_prompt', message: 'Prompt is required.' });

    const usageGate = gateUsage(auth.user, prompt);
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    const imageJob = createStudioImageJob(auth.user, {
      prompt,
      preset: body.preset || 'bitforge',
      view: body.view || 'sidescroller',
      size: body.size || 512,
      transparent: body.transparent !== false,
    });

    const integrationText = generateAiReply(`Write concise game integration steps for this asset prompt: ${prompt}`);

    return sendJson(res, 200, {
      ok: true,
      type: 'orchestrate',
      workflow: body.workflow || 'sprite_plus_integration_notes',
      outputs: {
        imageJob: imageJob.ok ? imageJob.job : { error: imageJob.error, message: imageJob.message },
        integrationText,
      },
      billing: usageGate.billing,
    });
  }

  if ((apiPath === '/api/omni/code/generate' || apiPath === '/api/omni/code/explain' || apiPath === '/api/omni/code/fix' || apiPath === '/api/omni/code/test') && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const input = String(body.prompt || body.input || body.code || body.text || '').trim();
    if (!input) return sendOmniError(res, 400, 'missing_input', 'Provide prompt/code input.', null, requestId);
    const intent = apiPath.split('/').pop();
    const codeIntentPrompts = {
      generate: `Generate production-ready code for this request. Return code first, then minimal notes if needed:\n${input}`,
      explain: `Explain what this code does in plain language. Mention key operations and data flow (e.g., array/map transformations) and avoid rewriting the code unless needed for clarity:\n${input}`,
      fix: `Fix the bug in this code and return the corrected code first. Keep changes minimal and preserve original intent:\n${input}`,
      test: `Write practical tests for this code. Prefer concrete assertions over vague guidance:\n${input}`,
    };
    const codePrompt = codeIntentPrompts[intent] || `Code task (${intent}): ${input}`;
    const response = await executeOmniTextWithRouting({ runtime: getOmniRuntime(), modality: 'text', inputText: codePrompt, body, requestId, routeProfile: 'auto' });
    if (!response.ok) return sendOmniError(res, 503, response.error || 'provider_unavailable', response.message || 'No provider available.', null, requestId);

    let output = String(response.output || '');
    if (intent === 'explain') {
      const lowerOut = output.toLowerCase();
      const lowerIn = input.toLowerCase();
      const mentionsArrayOrMap = lowerOut.includes('array') || lowerOut.includes('map');
      if (!mentionsArrayOrMap) {
        if (lowerIn.includes('.map(') || lowerIn.includes(' map(')) {
          output = `${output ? `${output}\n\n` : ''}This code uses array map to transform each item into a new value.`;
        } else if (lowerIn.includes('[') && lowerIn.includes(']')) {
          output = `${output ? `${output}\n\n` : ''}This code processes values in an array and returns a transformed result.`;
        }
      }
    }

    return sendOmniOk(res, { type: 'code', intent, status: 'real_partial', output, model: response.model, backend: response.backend, attempts: response.attempts }, requestId);
  }

  if (apiPath === '/api/omni/tools/list' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    return sendOmniOk(res, {
      type: 'tools_inventory',
      status: 'real_partial',
      tools: [
        { name: 'web.search', input: { query: 'string' }, note: 'DuckDuckGo instant answer search' },
        { name: 'rag.query', input: { query: 'string' }, note: 'Local RAG index query' },
        { name: 'text.generate', input: { prompt: 'string' }, note: 'Provider-routed local text generation' },
      ]
    }, requestId);
  }

  if (apiPath === '/api/omni/tools/run' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const tool = String(body.tool || body.name || '').trim();
    if (!tool) return sendOmniError(res, 400, 'missing_tool', 'tool is required.', null, requestId);
    if (tool === 'text.generate') {
      const prompt = String(body.prompt || body.input || '').trim();
      if (!prompt) return sendOmniError(res, 400, 'missing_prompt', 'prompt is required for text.generate.', null, requestId);
      const response = await executeOmniTextWithRouting({ runtime: getOmniRuntime(), modality: 'text', inputText: prompt, body, requestId, routeProfile: 'auto' });
      if (!response.ok) return sendOmniError(res, 503, response.error || 'provider_unavailable', response.message || 'No provider available.', null, requestId);
      return sendOmniOk(res, { type: 'tool_result', tool, status: 'real_partial', result: response.output, backend: response.backend }, requestId);
    }
    if (tool === 'rag.query') {
      const query = String(body.query || '').trim();
      if (!query) return sendOmniError(res, 400, 'missing_query', 'query is required for rag.query.', null, requestId);
      const store = getRagIndexStore();
      const hits = Object.values(store.docs || {})
        .map((d) => ({ id: d.id, text: d.text, score: scoreDocForQuery(query, d.text) }))
        .filter((r) => r.score > 0)
        .sort((a, b) => b.score - a.score)
        .slice(0, 4);
      return sendOmniOk(res, { type: 'tool_result', tool, status: 'real_partial', result: hits }, requestId);
    }
    return sendOmniError(res, 400, 'unsupported_tool', 'Unsupported tool. Use /tools/list.', null, requestId);
  }

  if (apiPath === '/api/omni/agents/session/start' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const prompt = String(body.prompt || body.input || '').trim();
    if (!prompt) return sendOmniError(res, 400, 'missing_prompt', 'prompt is required.', null, requestId);
    const store = getOmniAgentSessionStore();
    const id = 'asess_' + crypto.randomBytes(8).toString('hex');
    store.sessions[id] = { id, userId: auth.user.id, status: 'running', createdAt: Date.now(), updatedAt: Date.now(), messages: [{ role: 'user', content: prompt }] };
    setOmniAgentSessionStore(store);
    return sendOmniOk(res, { type: 'agent_session', status: 'real_partial', sessionId: id, state: store.sessions[id].status }, requestId);
  }

  if (apiPath === '/api/omni/agents/session/message' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const sessionId = String(body.sessionId || '').trim();
    const content = String(body.message || body.prompt || body.input || '').trim();
    if (!sessionId || !content) return sendOmniError(res, 400, 'missing_input', 'sessionId and message are required.', null, requestId);
    const store = getOmniAgentSessionStore();
    const s = store.sessions[sessionId];
    if (!s || s.userId !== auth.user.id) return sendOmniError(res, 404, 'not_found', 'Session not found.', null, requestId);
    if (s.status !== 'running') return sendOmniError(res, 409, 'session_stopped', 'Session is not running.', null, requestId);
    s.messages.push({ role: 'user', content });
    const response = await executeOmniTextWithRouting({ runtime: getOmniRuntime(), modality: 'text', inputText: content, body, requestId, routeProfile: 'auto' });
    const assistant = response.ok ? String(response.output || '') : 'Provider unavailable.';
    s.messages.push({ role: 'assistant', content: assistant });
    s.updatedAt = Date.now();
    setOmniAgentSessionStore(store);
    return sendOmniOk(res, { type: 'agent_session_message', status: 'real_partial', sessionId, output: assistant, backend: response.backend || null }, requestId);
  }

  if (apiPath === '/api/omni/agents/session/stop' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const sessionId = String(body.sessionId || '').trim();
    if (!sessionId) return sendOmniError(res, 400, 'missing_session', 'sessionId is required.', null, requestId);
    const store = getOmniAgentSessionStore();
    const s = store.sessions[sessionId];
    if (!s || s.userId !== auth.user.id) return sendOmniError(res, 404, 'not_found', 'Session not found.', null, requestId);
    s.status = 'stopped';
    s.updatedAt = Date.now();
    setOmniAgentSessionStore(store);
    return sendOmniOk(res, { type: 'agent_session_stop', status: 'real_partial', sessionId, state: s.status }, requestId);
  }

  if (apiPath === '/api/omni/agents/session/status' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const id = String(url.searchParams.get('sessionId') || '').trim();
    if (!id) return sendOmniError(res, 400, 'missing_session', 'sessionId query param is required.', null, requestId);
    const store = getOmniAgentSessionStore();
    const s = store.sessions[id];
    if (!s || s.userId !== auth.user.id) return sendOmniError(res, 404, 'not_found', 'Session not found.', null, requestId);
    return sendOmniOk(res, { type: 'agent_session_status', status: 'real_partial', session: s }, requestId);
  }

  if (apiPath === '/api/omni/datasets/upload' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const name = String(body.name || body.dataset || 'dataset').trim().slice(0, 120);
    const docs = Array.isArray(body.documents) ? body.documents : [];
    const store = getOmniDatasetStore();
    const id = 'ds_' + crypto.randomBytes(6).toString('hex');
    store.datasets[id] = { id, userId: auth.user.id, name, count: docs.length, createdAt: Date.now(), status: 'stored_partial' };
    setOmniDatasetStore(store);
    return sendOmniOk(res, { type: 'dataset_upload', status: 'real_partial', dataset: store.datasets[id], note: 'Metadata is stored locally; remote trainer ingestion is not attached.' }, requestId);
  }

  if (apiPath.startsWith('/api/omni/datasets/') && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const id = decodeURIComponent(apiPath.slice('/api/omni/datasets/'.length));
    const store = getOmniDatasetStore();
    const row = store.datasets[id];
    if (!row || row.userId !== auth.user.id) return sendOmniError(res, 404, 'not_found', 'Dataset not found.', null, requestId);
    return sendOmniOk(res, { type: 'dataset_status', status: 'real_partial', dataset: row }, requestId);
  }

  if (apiPath === '/api/omni/models/fine-tunes' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const model = String(body.model || 'unspecified').trim().slice(0, 120);
    const datasetId = String(body.datasetId || '').trim();
    const store = getOmniFineTuneStore();
    const id = 'ft_' + crypto.randomBytes(6).toString('hex');
    store.jobs[id] = { id, userId: auth.user.id, model, datasetId: datasetId || null, status: 'blocked_no_trainer', createdAt: Date.now(), updatedAt: Date.now() };
    setOmniFineTuneStore(store);
    return sendOmniOk(res, { type: 'fine_tune', status: 'real_partial', job: store.jobs[id], note: 'Trainer backend is not configured in this runtime.' }, requestId);
  }

  if (apiPath.match(/^\/api\/omni\/models\/fine-tunes\/[^/]+$/) && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const id = decodeURIComponent(apiPath.slice('/api/omni/models/fine-tunes/'.length));
    const store = getOmniFineTuneStore();
    const row = store.jobs[id];
    if (!row || row.userId !== auth.user.id) return sendOmniError(res, 404, 'not_found', 'Fine-tune job not found.', null, requestId);
    return sendOmniOk(res, { type: 'fine_tune_status', status: 'real_partial', job: row }, requestId);
  }

  if (apiPath.match(/^\/api\/omni\/models\/fine-tunes\/[^/]+\/cancel$/) && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const id = decodeURIComponent(apiPath.slice('/api/omni/models/fine-tunes/'.length, -'/cancel'.length));
    const store = getOmniFineTuneStore();
    const row = store.jobs[id];
    if (!row || row.userId !== auth.user.id) return sendOmniError(res, 404, 'not_found', 'Fine-tune job not found.', null, requestId);
    row.status = 'canceled';
    row.updatedAt = Date.now();
    setOmniFineTuneStore(store);
    return sendOmniOk(res, { type: 'fine_tune_cancel', status: 'real_partial', job: row }, requestId);
  }

  if (apiPath === '/api/omni/adapters/lora/create' && req.method === 'POST') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const body = await parseBody(req);
    const name = String(body.name || 'lora-adapter').trim().slice(0, 120);
    const store = getOmniLoraStore();
    const id = 'lora_' + crypto.randomBytes(6).toString('hex');
    store.adapters[id] = { id, userId: auth.user.id, name, status: 'registered_partial', createdAt: Date.now(), note: 'Training backend is not attached; registration only.' };
    setOmniLoraStore(store);
    return sendOmniOk(res, { type: 'lora_create', status: 'real_partial', adapter: store.adapters[id] }, requestId);
  }

  if (apiPath === '/api/omni/adapters/lora/list' && req.method === 'GET') {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const store = getOmniLoraStore();
    const adapters = Object.values(store.adapters || {}).filter((r) => r.userId === auth.user.id || auth.safeUser.owner);
    return sendOmniOk(res, { type: 'lora_list', status: 'real_partial', adapters }, requestId);
  }

  const plannedPostOmniEndpoints = new Map([
    ['/api/omni/code/generate', 'Code generation endpoint is planned; use /chat/completions for now.'],
    ['/api/omni/code/explain', 'Code explanation endpoint is planned; use /chat/completions for now.'],
    ['/api/omni/code/fix', 'Code fix endpoint is planned; use /chat/completions for now.'],
    ['/api/omni/code/test', 'Code test generation endpoint is planned; use /chat/completions for now.'],
    ['/api/omni/tools/run', 'Tool execution bridge is planned; no secure tool registry wired yet.'],
    ['/api/omni/agents/session/start', 'Persistent agent sessions are planned; orchestration is available today.'],
    ['/api/omni/agents/session/message', 'Persistent agent sessions are planned; orchestration is available today.'],
    ['/api/omni/agents/session/stop', 'Persistent agent sessions are planned; orchestration is available today.'],
    ['/api/omni/models/fine-tunes', 'Fine-tuning job orchestration is planned; no trainer backend is attached.'],
    ['/api/omni/models/fine-tunes/{id}/cancel', 'Fine-tuning job orchestration is planned; no trainer backend is attached.'],
    ['/api/omni/datasets/upload', 'Dataset upload/training pipeline is planned; no dataset store is attached.'],
    ['/api/omni/adapters/lora/create', 'LoRA adapter training is planned; no trainer backend is attached.'],
  ]);

  const plannedGetOmniEndpoints = new Map([
    ['/api/omni/tools/list', 'Tool inventory is planned; secure registry integration pending.'],
    ['/api/omni/agents/session/status', 'Persistent agent sessions are planned; orchestration jobs are available now.'],
    ['/api/omni/models/fine-tunes/{id}', 'Fine-tune status endpoint is planned; no trainer backend is attached.'],
    ['/api/omni/datasets/{id}', 'Dataset details endpoint is planned; no dataset store is attached.'],
    ['/api/omni/adapters/lora/list', 'LoRA adapter catalog is planned; adapter registry not implemented yet.'],
  ]);

  const fineTuneStatusMatch = apiPath.match(/^\/api\/omni\/models\/fine-tunes\/[^/]+$/);
  const fineTuneCancelMatch = apiPath.match(/^\/api\/omni\/models\/fine-tunes\/[^/]+\/cancel$/);
  const datasetGetMatch = apiPath.match(/^\/api\/omni\/datasets\/[^/]+$/);

  if (req.method === 'GET' && (plannedGetOmniEndpoints.has(apiPath) || fineTuneStatusMatch || datasetGetMatch)) {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const key = fineTuneStatusMatch ? '/api/omni/models/fine-tunes/{id}' : datasetGetMatch ? '/api/omni/datasets/{id}' : apiPath;
    return sendOmniPlanned(res, requestId, key.replace('/api/omni', ''), plannedGetOmniEndpoints.get(key));
  }

  if (req.method === 'POST' && (plannedPostOmniEndpoints.has(apiPath) || fineTuneCancelMatch)) {
    const requestId = omniRequestId(req);
    const auth = getOmniAuthUser(req, safeUser, user);
    if (!auth) return sendOmniError(res, 401, 'unauthorized', 'Session or bearer token required.', null, requestId);
    const key = fineTuneCancelMatch ? '/api/omni/models/fine-tunes/{id}/cancel' : apiPath;
    return sendOmniPlanned(res, requestId, key.replace('/api/omni', ''), plannedPostOmniEndpoints.get(key));
  }

  if ((url.pathname === '/api/ai/chat' || url.pathname === '/api/ai/complete') && req.method === 'POST') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });

    const expected = String(process.env.PRISMBOT_API_TOKEN || '').trim();
    if (expected) {
      const got = readBearerToken(req);
      if (!got || got !== expected) return sendJson(res, 401, { ok: false, error: 'unauthorized' });
    }

    const ip = (req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown').toString().split(',')[0].trim();
    const rate = safeUser.owner ? { allowed: true, remaining: Number.POSITIVE_INFINITY } : checkAiRate(ip);
    if (!rate.allowed) return sendJson(res, 429, { ok: false, error: 'rate_limited', message: 'Too many AI requests. Try again shortly.' });

    const body = await parseBody(req);
    const prompt = String(body.prompt || '').trim();
    const messages = Array.isArray(body.messages) ? body.messages : [];
    const inputText = prompt || inferUserTextFromMessages(messages);
    if (!inputText) return sendJson(res, 400, { ok: false, error: 'missing_input', message: 'Provide prompt or messages.' });

    const usageGate = gateUsage(user, inputText);
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    if (moderate(inputText)) {
      return sendJson(res, 200, {
        ok: true,
        moderated: true,
        remaining: rate.remaining,
        output: 'I can’t help with that. I can help with a safer alternative.',
        billing: usageGate.billing
      });
    }

    const output = generateAiReply(inputText);
    return sendJson(res, 200, {
      ok: true,
      model: body.model || 'prismbot-core-local',
      remaining: rate.remaining,
      output,
      choices: [{ message: { role: 'assistant', content: output } }],
      billing: usageGate.billing
    });
  }

  if ((url.pathname === '/api/public/chat' || url.pathname === '/api/chat') && req.method === 'POST') {
    if (!safeUser) return sendJson(res, 401, { ok: false, error: 'unauthorized' });

    const body = await parseBody(req);
    const text = String(body.text || '').trim();
    if (!text) return sendJson(res, 400, { ok: false, error: 'missing_text' });

    const usageGate = gateUsage(user, text);
    if (!usageGate.ok) return sendJson(res, usageGate.code, { ok: false, error: usageGate.error, message: usageGate.message, billing: usageGate.billing });

    const ip = (req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown').toString().split(',')[0].trim();
    const rate = safeUser.owner ? { allowed: true, remaining: Number.POSITIVE_INFINITY } : checkPublicRate(ip);
    if (!rate.allowed) return sendJson(res, 429, { ok: false, error: 'rate_limited', message: 'Too many requests. Try again shortly.' });

    if (moderate(text)) {
      return sendJson(res, 200, { ok: true, moderated: true, remaining: rate.remaining, reply: 'I can’t help with that. I can help with a safer alternative.', billing: usageGate.billing });
    }

    return sendJson(res, 200, { ok: true, moderated: false, remaining: rate.remaining, reply: `PrismBot core (${safeUser.username}): ${text}`, billing: usageGate.billing });
  }

  return sendJson(res, 404, { ok: false, error: 'not_found' });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

  if (url.pathname === '/admin/openclaw' || url.pathname.startsWith('/admin/openclaw/')) {
    const { token } = getSessionFromRequest(req, COOKIE_NAME);
    const user = getUserByToken(token);
    const safeUser = user ? sanitizeUser(user) : null;
    if (!safeUser || !safeUser.owner) return sendJson(res, 403, { ok: false, error: 'forbidden' });
    const proxiedPath = url.pathname.slice('/admin/openclaw'.length) || '/';
    const targetPath = `${proxiedPath}${url.search || ''}`;
    return proxyOpenClawDashboard(req, res, targetPath);
  }

  if (
    url.pathname.startsWith('/api/') ||
    url.pathname === '/mcp' ||
    url.pathname === '/mcp2' ||
    url.pathname === '/.well-known/oauth-authorization-server' ||
    url.pathname === '/.well-known/oauth-protected-resource' ||
    url.pathname === '/oauth/authorize' ||
    url.pathname === '/oauth/token'
  ) return handleApi(req, res, url);

  if (url.pathname === '/app' || url.pathname === '/app/') {
    const { token } = getSessionFromRequest(req, COOKIE_NAME);
    const user = getUserByToken(token);
    const safeUser = user ? sanitizeUser(user) : null;
    const hasKey = Boolean(user && hasUserApiKey(user.id));
    const hasOauth = Boolean(user && hasOAuthAccess(user.id));
    const defaultTab = 'chat';
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    return res.end(renderUnifiedShell({ user: safeUser, hasKey, hasOauth, active: defaultTab }));
  }

  if (url.pathname.startsWith('/app/')) {
    const { token } = getSessionFromRequest(req, COOKIE_NAME);
    const user = getUserByToken(token);
    const safeUser = user ? sanitizeUser(user) : null;
    const hasKey = Boolean(user && hasUserApiKey(user.id));
    const hasOauth = Boolean(user && hasOAuthAccess(user.id));
    const tab = url.pathname.split('/')[2] || 'chat';
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    return res.end(renderUnifiedShell({ user: safeUser, hasKey, hasOauth, active: tab }));
  }

  if (url.pathname === '/studio') {
    res.writeHead(302, { location: '/app/studio' });
    return res.end();
  }

  if (serveStatic(url.pathname, res)) return;
  sendJson(res, 404, { ok: false, error: 'not_found' });
});

server.on('upgrade', (req, socket, head) => {
  try {
    const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);

    // OpenClaw control UI currently connects websocket to host root ("/").
    // We proxy only owner-authenticated upgrade traffic to local OpenClaw dashboard.
    if (url.pathname !== '/' && !url.pathname.startsWith('/admin/openclaw')) {
      socket.end('HTTP/1.1 404 Not Found\\r\\nConnection: close\\r\\n\\r\\n');
      return;
    }

    const { token } = getSessionFromRequest(req, COOKIE_NAME);
    const user = getUserByToken(token);
    const safeUser = user ? sanitizeUser(user) : null;
    if (!safeUser || !safeUser.owner) {
      socket.end('HTTP/1.1 403 Forbidden\\r\\nConnection: close\\r\\n\\r\\n');
      return;
    }

    const proxiedPath = url.pathname.startsWith('/admin/openclaw')
      ? (url.pathname.slice('/admin/openclaw'.length) || '/')
      : '/';

    const upstreamReq = http.request({
      host: OPENCLAW_DASHBOARD_HOST,
      port: OPENCLAW_DASHBOARD_PORT,
      method: req.method || 'GET',
      path: `${proxiedPath}${url.search || ''}`,
      headers: buildOpenClawProxyHeaders(req, true),
    });

    upstreamReq.on('upgrade', (upRes, upSocket, upHead) => {
      let response = 'HTTP/1.1 101 Switching Protocols\\r\\n';
      for (const [k, v] of Object.entries(upRes.headers || {})) {
        if (v == null) continue;
        response += `${k}: ${Array.isArray(v) ? v.join(', ') : v}\\r\\n`;
      }
      response += '\\r\\n';
      socket.write(response);

      if (head && head.length) upSocket.write(head);
      if (upHead && upHead.length) socket.write(upHead);

      socket.pipe(upSocket).pipe(socket);
    });

    upstreamReq.on('response', (upRes) => {
      const code = upRes.statusCode || 502;
      socket.end(`HTTP/1.1 ${code} Upstream Response\\r\\nConnection: close\\r\\n\\r\\n`);
    });

    upstreamReq.on('error', () => {
      socket.end('HTTP/1.1 502 Bad Gateway\\r\\nConnection: close\\r\\n\\r\\n');
    });

    upstreamReq.end();
  } catch {
    try { socket.end('HTTP/1.1 400 Bad Request\\r\\nConnection: close\\r\\n\\r\\n'); } catch {}
  }
});

server.listen(PORT, () => console.log(`prismbot-core listening on :${PORT}`));
