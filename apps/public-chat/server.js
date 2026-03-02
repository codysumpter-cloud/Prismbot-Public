const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// Lightweight .env loader (no external deps)
const envPath = path.join(__dirname, '.env');
if (fs.existsSync(envPath)) {
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const t = line.trim();
    if (!t || t.startsWith('#')) continue;
    const i = t.indexOf('=');
    if (i <= 0) continue;
    const k = t.slice(0, i).trim();
    const v = t.slice(i + 1);
    if (!(k in process.env)) process.env[k] = v;
  }
}

const PORT = Number(process.env.PORT || 8788);
const HOST = process.env.HOST || '0.0.0.0';
const MAX_INPUT = Number(process.env.MAX_INPUT || 800);
const RATE_LIMIT_MAX = Number(process.env.RATE_LIMIT_MAX || 20);
const RATE_LIMIT_WINDOW_MS = Number(process.env.RATE_LIMIT_WINDOW_MS || 60_000);
const MODEL = process.env.OPENAI_MODEL || 'gpt-4o-mini';
const ALLOWED_ORIGINS = String(process.env.ALLOWED_ORIGINS || '')
  .split(',')
  .map(s => s.trim())
  .filter(Boolean);
const TRUST_PROXY = String(process.env.TRUST_PROXY || 'false').toLowerCase() === 'true';
const AUDIT_LOG = process.env.AUDIT_LOG || path.join(__dirname, 'logs', 'audit.log');
const BLOCK_DURATION_MS = Number(process.env.BLOCK_DURATION_MS || 10 * 60_000);
const MODERATION_STRIKES_TO_BLOCK = Number(process.env.MODERATION_STRIKES_TO_BLOCK || 3);

const sessions = new Map(); // sessionId => [{role,text,at}]
const ipRate = new Map(); // ip => { count, resetAt }
const ipBlocks = new Map(); // ip => blockedUntilMs
const ipStrikes = new Map(); // ip => moderation strikes in current window

const BLOCKED_PATTERNS = [
  /\b(?:kill|harm|hurt)\s+(?:myself|yourself|someone)\b/i,
  /\bself\s*harm\b/i,
  /\bsuicide\b/i,
  /\bhow\s+to\s+make\s+(?:a\s+)?bomb\b/i,
  /\bcredit\s*card\s*number\b/i,
  /\bsocial\s*security\s*number\b/i,
  /\bchild\s*porn\b/i,
  /\bnudes?\b/i,
  /\bexplicit\s+sexual\b/i
];

function nowIso() {
  return new Date().toISOString();
}

function baseHeaders(extra = {}) {
  return {
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer',
    'X-Frame-Options': 'DENY',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=() ',
    ...extra
  };
}

function sendJson(res, code, body, extraHeaders = {}) {
  res.writeHead(code, baseHeaders({
    'Content-Type': 'application/json; charset=utf-8',
    ...extraHeaders
  }));
  res.end(JSON.stringify(body));
}

function contentTypeFor(file) {
  const ext = path.extname(file);
  if (ext === '.css') return 'text/css';
  if (ext === '.js') return 'application/javascript';
  if (ext === '.json') return 'application/json';
  return 'text/html';
}

function getClientIp(req) {
  const xfwd = (req.headers['x-forwarded-for'] || '').toString();
  if (TRUST_PROXY && xfwd) return xfwd.split(',')[0].trim();
  const raw = req.socket?.remoteAddress || 'unknown';
  return raw.startsWith('::ffff:') ? raw.slice(7) : raw;
}

function appendAudit(event, details = {}) {
  try {
    const dir = path.dirname(AUDIT_LOG);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    const line = JSON.stringify({ at: nowIso(), event, ...details });
    fs.appendFileSync(AUDIT_LOG, line + '\n');
  } catch {
    // best-effort logging only
  }
}

function checkOrigin(req) {
  if (ALLOWED_ORIGINS.length === 0) return { ok: true, allowOrigin: null };
  const origin = String(req.headers.origin || '').trim();
  if (!origin) return { ok: false };
  if (!ALLOWED_ORIGINS.includes(origin)) return { ok: false };
  return { ok: true, allowOrigin: origin };
}

function checkRateLimit(ip) {
  const now = Date.now();
  const blockedUntil = ipBlocks.get(ip) || 0;
  if (blockedUntil > now) {
    return { ok: false, blocked: true, remaining: 0, resetAt: blockedUntil };
  }

  const current = ipRate.get(ip);
  if (!current || now >= current.resetAt) {
    const fresh = { count: 1, resetAt: now + RATE_LIMIT_WINDOW_MS };
    ipRate.set(ip, fresh);
    ipStrikes.set(ip, 0);
    return { ok: true, remaining: RATE_LIMIT_MAX - 1, resetAt: fresh.resetAt };
  }

  if (current.count >= RATE_LIMIT_MAX) {
    return { ok: false, remaining: 0, resetAt: current.resetAt };
  }

  current.count += 1;
  return { ok: true, remaining: Math.max(0, RATE_LIMIT_MAX - current.count), resetAt: current.resetAt };
}

function recordModerationStrike(ip) {
  const strikes = (ipStrikes.get(ip) || 0) + 1;
  ipStrikes.set(ip, strikes);
  if (strikes >= MODERATION_STRIKES_TO_BLOCK) {
    const blockedUntil = Date.now() + BLOCK_DURATION_MS;
    ipBlocks.set(ip, blockedUntil);
    return { blocked: true, blockedUntil };
  }
  return { blocked: false, strikes };
}

function moderateInput(text) {
  for (const pattern of BLOCKED_PATTERNS) {
    if (pattern.test(text)) {
      return {
        blocked: true,
        reply: 'I can’t help with that request. I can help with safer alternatives if you want.'
      };
    }
  }
  return { blocked: false };
}

function ensureSession(sessionId) {
  if (!sessions.has(sessionId)) sessions.set(sessionId, []);
  return sessions.get(sessionId);
}

function appendHistory(sessionId, role, text) {
  const history = ensureSession(sessionId);
  history.push({ role, text, at: nowIso() });
  if (history.length > 30) sessions.set(sessionId, history.slice(-30));
}

function sanitizeSessionId(input) {
  const cleaned = String(input || '')
    .toLowerCase()
    .replace(/[^a-z0-9_-]/g, '')
    .slice(0, 64);
  return cleaned || '';
}

function parseJsonBody(req, maxBytes = 1_000_000) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
      if (body.length > maxBytes) {
        reject(new Error('payload_too_large'));
        req.destroy();
      }
    });
    req.on('end', () => {
      try {
        resolve(JSON.parse(body || '{}'));
      } catch {
        reject(new Error('invalid_json'));
      }
    });
    req.on('error', reject);
  });
}

function serveStatic(req, res) {
  const urlPath = req.url.split('?')[0];
  const requested = urlPath === '/' ? 'index.html' : urlPath;
  const normalized = path.normalize(requested).replace(/^\/+/, '');
  const webRoot = path.join(__dirname, 'web');
  const file = path.join(webRoot, normalized);

  if (!file.startsWith(webRoot)) return sendJson(res, 403, { error: 'forbidden' });

  fs.readFile(file, (err, data) => {
    if (err) return sendJson(res, 404, { error: 'not_found' });
    res.writeHead(200, baseHeaders({
      'Content-Type': `${contentTypeFor(file)}; charset=utf-8`,
      'Content-Security-Policy': "default-src 'self'; connect-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'"
    }));
    res.end(data);
  });
}

function openAiChat({ apiKey, userText, sessionId }) {
  return new Promise((resolve, reject) => {
    const history = ensureSession(sessionId);
    const trimmedHistory = history.slice(-10).map(m => ({ role: m.role, content: m.text }));

    const payload = {
      model: MODEL,
      temperature: 0.7,
      messages: [
        {
          role: 'system',
          content: 'You are a public community chat assistant. Be helpful, concise, and safe. Refuse harmful, illegal, explicit sexual, and privacy-invasive requests. Offer safer alternatives.'
        },
        ...trimmedHistory,
        { role: 'user', content: userText }
      ]
    };

    const data = JSON.stringify(payload);
    const request = https.request(
      {
        hostname: 'api.openai.com',
        port: 443,
        path: '/v1/chat/completions',
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(data),
          Authorization: `Bearer ${apiKey}`
        }
      },
      response => {
        let out = '';
        response.on('data', d => {
          out += d;
        });
        response.on('end', () => {
          let parsed;
          try {
            parsed = JSON.parse(out || '{}');
          } catch {
            return reject(new Error('openai_invalid_json'));
          }

          if (response.statusCode < 200 || response.statusCode >= 300) {
            const msg = parsed?.error?.message || `openai_http_${response.statusCode}`;
            return reject(new Error(msg));
          }

          const text = parsed?.choices?.[0]?.message?.content?.trim();
          if (!text) return reject(new Error('openai_empty_response'));
          resolve(text);
        });
      }
    );

    request.on('error', reject);
    request.write(data);
    request.end();
  });
}

function randomFallback() {
  const options = [
    'I’m online. Add your OpenAI API key above and ask me anything.',
    'I can help with ideas, writing, summaries, and planning. What do you want to do?',
    'Quick mode ready. Drop a question and I’ll keep it concise.'
  ];
  return options[crypto.randomInt(0, options.length)];
}

const server = http.createServer(async (req, res) => {
  const reqId = crypto.randomUUID();
  const ip = getClientIp(req);
  const originCheck = checkOrigin(req);

  if (req.method === 'OPTIONS') {
    if (!originCheck.ok && ALLOWED_ORIGINS.length > 0) return sendJson(res, 403, { error: 'origin_forbidden' }, { 'X-Request-Id': reqId });
    const allowOrigin = originCheck.allowOrigin || '*';
    res.writeHead(204, baseHeaders({
      'Access-Control-Allow-Origin': allowOrigin,
      'Access-Control-Allow-Methods': 'GET,POST,OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Access-Control-Max-Age': '600',
      'X-Request-Id': reqId
    }));
    return res.end();
  }

  if (!originCheck.ok && ALLOWED_ORIGINS.length > 0) {
    appendAudit('origin_forbidden', { ip, origin: String(req.headers.origin || ''), path: req.url, reqId });
    return sendJson(res, 403, { error: 'origin_forbidden' }, { 'X-Request-Id': reqId });
  }

  if (req.method === 'GET' && req.url === '/api/health') {
    return sendJson(res, 200, { ok: true, at: nowIso() }, { 'X-Request-Id': reqId });
  }

  if (req.method === 'POST' && req.url === '/api/chat') {
    const limit = checkRateLimit(ip);
    if (!limit.ok) {
      appendAudit('rate_limited', { ip, reqId, blocked: Boolean(limit.blocked), resetAt: new Date(limit.resetAt).toISOString() });
      return sendJson(res, 429, {
        error: 'rate_limited',
        message: limit.blocked
          ? 'Temporarily blocked due to repeated unsafe requests. Please try again later.'
          : 'Too many requests from this IP. Please wait a moment and try again.',
        resetAt: new Date(limit.resetAt).toISOString()
      }, { 'X-Request-Id': reqId });
    }

    try {
      const parsed = await parseJsonBody(req);
      const text = String(parsed.text || '').trim().slice(0, MAX_INPUT);
      const sessionId = sanitizeSessionId(parsed.sessionId);
      const apiKey = String(parsed.apiKey || '').trim();

      if (!text) return sendJson(res, 400, { error: 'text_required' }, { 'X-Request-Id': reqId });
      if (!sessionId) return sendJson(res, 400, { error: 'session_required' }, { 'X-Request-Id': reqId });

      appendHistory(sessionId, 'user', text);

      const moderation = moderateInput(text);
      if (moderation.blocked) {
        const strike = recordModerationStrike(ip);
        appendAudit('moderation_block', {
          ip,
          reqId,
          sessionId,
          strikes: strike.strikes || MODERATION_STRIKES_TO_BLOCK,
          autoBlocked: strike.blocked,
          blockedUntil: strike.blocked ? new Date(strike.blockedUntil).toISOString() : null
        });
        appendHistory(sessionId, 'assistant', moderation.reply);
        return sendJson(res, 200, {
          reply: moderation.reply,
          moderated: true,
          remaining: limit.remaining,
          blockedUntil: strike.blocked ? new Date(strike.blockedUntil).toISOString() : null
        }, { 'X-Request-Id': reqId });
      }

      if (!apiKey) {
        const fallback = randomFallback();
        appendHistory(sessionId, 'assistant', fallback);
        return sendJson(res, 200, { reply: fallback, fallback: true, remaining: limit.remaining }, { 'X-Request-Id': reqId });
      }

      try {
        const reply = await openAiChat({ apiKey, userText: text, sessionId });
        appendHistory(sessionId, 'assistant', reply);
        return sendJson(res, 200, { reply, remaining: limit.remaining }, { 'X-Request-Id': reqId });
      } catch (err) {
        const friendly = `OpenAI request failed: ${err.message}. Check key, model access, and network.`;
        appendHistory(sessionId, 'assistant', friendly);
        appendAudit('openai_error', { ip, reqId, sessionId, error: err.message });
        return sendJson(res, 200, { reply: friendly, error: true, remaining: limit.remaining }, { 'X-Request-Id': reqId });
      }
    } catch (err) {
      const code = err.message === 'payload_too_large' ? 413 : 400;
      appendAudit('invalid_request', { ip, reqId, error: err.message || 'invalid_request' });
      return sendJson(res, code, { error: err.message || 'invalid_request' }, { 'X-Request-Id': reqId });
    }
  }

  if (req.method === 'GET') return serveStatic(req, res);

  return sendJson(res, 404, { error: 'not_found' });
});

server.listen(PORT, HOST, () => {
  console.log(`public-chat listening on http://${HOST}:${PORT}`);
});
