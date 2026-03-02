const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { getSessionFromRequest, requireRole } = require('../middleware/auth-session');

const PORT = Number(process.env.PORT || 8799);
const ROOT = path.resolve(__dirname, '..');
const REPO_APPS = path.resolve(ROOT, '..');
const DATA_DIR = path.join(ROOT, 'data');
const COOKIE_NAME = process.env.CORE_SESSION_COOKIE || 'pb_core_session';
const COOKIE_SECURE = String(process.env.CORE_COOKIE_SECURE || 'false').toLowerCase() === 'true';

const STATIC_DIRS = {
  '/': path.join(REPO_APPS, 'prismbot-site'),
  '/chat': path.join(REPO_APPS, 'kid-chat-mvp', 'web'),
  '/admin': path.join(REPO_APPS, 'mission-control', 'public'),
  '/public': path.join(REPO_APPS, 'public-chat', 'web'),
};

const publicRate = new Map();
const loginRate = new Map();

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
function getMime(file) {
  if (file.endsWith('.html')) return 'text/html; charset=utf-8';
  if (file.endsWith('.css')) return 'text/css; charset=utf-8';
  if (file.endsWith('.js')) return 'application/javascript; charset=utf-8';
  if (file.endsWith('.json')) return 'application/json; charset=utf-8';
  if (file.endsWith('.png')) return 'image/png';
  if (file.endsWith('.jpg') || file.endsWith('.jpeg')) return 'image/jpeg';
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
  const entry = Object.entries(users).find(([, u]) => String(u.username || '').toLowerCase() === String(username || '').toLowerCase());
  if (!entry) return null;
  const [id, user] = entry;
  return { id, ...user };
}

function sanitizeUser(u) {
  return { id: u.id, username: u.username, role: u.role || 'family_user', displayName: u.displayName || u.username };
}

function serveStatic(reqPath, res) {
  const candidateRoots = Object.keys(STATIC_DIRS)
    .sort((a, b) => b.length - a.length)
    .filter((prefix) => reqPath === prefix || reqPath.startsWith(prefix + '/'));

  for (const prefix of candidateRoots) {
    const root = STATIC_DIRS[prefix];
    let rel = reqPath.slice(prefix.length);
    if (!rel || rel === '/') rel = '/index.html';
    const filePath = path.normalize(path.join(root, rel));
    if (!filePath.startsWith(root)) continue;
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

function renderUnifiedShell(active = 'chat') {
  const tabs = {
    site: '/',
    chat: '/chat',
    admin: '/admin',
    public: '/public',
    studio: '/studio',
  };
  const current = tabs[active] ? active : 'chat';
  const iframeSrc = tabs[current];

  const nav = Object.keys(tabs)
    .map((k) => `<a href="/app/${k}" class="tab ${k === current ? 'active' : ''}">${k.toUpperCase()}</a>`)
    .join('');

  return `<!doctype html>
<html><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>PrismBot Core</title>
<style>
  body{margin:0;background:#0b0b14;color:#fff;font-family:Inter,system-ui,Arial,sans-serif}
  .bar{display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid #22243a;background:#111427;position:sticky;top:0}
  .brand{font-weight:800;margin-right:8px;color:#c7b7ff}
  .tab{padding:6px 10px;border:1px solid #2d3150;border-radius:999px;color:#bfc5f6;text-decoration:none;font-size:12px}
  .tab.active{background:#5b46f4;border-color:#6f5df7;color:#fff}
  iframe{display:block;width:100%;height:calc(100vh - 52px);border:0;background:#0b0b14}
</style></head>
<body>
  <div class="bar"><div class="brand">PrismBot Core</div>${nav}</div>
  <iframe src="${iframeSrc}" title="PrismBot Module"></iframe>
</body></html>`;
}

async function handleApi(req, res, url) {
  const { token } = getSessionFromRequest(req, COOKIE_NAME);
  const user = getUserByToken(token);

  if (url.pathname === '/api/health') return sendJson(res, 200, { ok: true, app: 'prismbot-core', phase: 'B' });

  if (url.pathname === '/api/auth/login' && req.method === 'POST') {
    const ip = (req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown').toString().split(',')[0].trim();
    const lr = checkLoginRate(ip);
    if (!lr.allowed) return sendJson(res, 429, { ok: false, error: 'rate_limited', message: 'Too many login attempts. Try again shortly.' });

    const body = await parseBody(req);
    const found = findUserByUsername(body.username);
    if (!found || !found.active) return sendJson(res, 401, { ok: false, error: 'invalid_credentials' });
    const passOk = verifyScryptPassword(String(body.password || ''), found.passwordHash);
    if (!passOk) return sendJson(res, 401, { ok: false, error: 'invalid_credentials' });
    setSession(res, found.id);
    return sendJson(res, 200, { ok: true, user: sanitizeUser(found) });
  }

  if (url.pathname === '/api/auth/logout' && req.method === 'POST') {
    clearSession(req, res);
    return sendJson(res, 200, { ok: true });
  }

  if (url.pathname === '/api/auth/me') return sendJson(res, 200, { ok: true, user: user ? sanitizeUser(user) : null });

  if (url.pathname === '/api/admin/summary') {
    if (!requireRole(user, ['admin'])) return sendJson(res, 403, { ok: false, error: 'forbidden' });
    const users = normalizeUsers(readJson('users.json', {}));
    const tasks = readJson('tasks.json', []);
    const activity = readJson('activity.json', []);
    return sendJson(res, 200, { ok: true, users: Object.keys(users).length, tasks: tasks.length || 0, activity: activity.length || 0 });
  }

  if (url.pathname === '/api/studio/status') {
    const studioPath = path.join(REPO_APPS, 'pixel-pipeline');
    return sendJson(res, 200, { ok: true, available: fs.existsSync(studioPath), path: 'apps/pixel-pipeline' });
  }

  if ((url.pathname === '/api/public/chat' || url.pathname === '/api/chat') && req.method === 'POST') {
    const body = await parseBody(req);
    const text = String(body.text || '').trim();
    if (!text) return sendJson(res, 400, { ok: false, error: 'missing_text' });

    const ip = (req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown').toString().split(',')[0].trim();
    const rate = checkPublicRate(ip);
    if (!rate.allowed) return sendJson(res, 429, { ok: false, error: 'rate_limited', message: 'Too many requests. Try again shortly.' });

    if (moderate(text)) {
      return sendJson(res, 200, { ok: true, moderated: true, remaining: rate.remaining, reply: 'I can’t help with that. I can help with a safer alternative.' });
    }

    const mode = user ? `user:${user.username}` : 'public';
    return sendJson(res, 200, { ok: true, moderated: false, remaining: rate.remaining, reply: `PrismBot core (${mode}): ${text}` });
  }

  return sendJson(res, 404, { ok: false, error: 'not_found' });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

  if (url.pathname.startsWith('/api/')) return handleApi(req, res, url);

  if (url.pathname === '/app' || url.pathname === '/app/') {
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    return res.end(renderUnifiedShell('chat'));
  }
  if (url.pathname.startsWith('/app/')) {
    const tab = url.pathname.split('/')[2] || 'chat';
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    return res.end(renderUnifiedShell(tab));
  }

  if (url.pathname === '/studio') {
    return sendText(res, 200, 'PrismBot Studio module ready. Pixel pipeline available under apps/pixel-pipeline (UI merge in progress).');
  }

  if (serveStatic(url.pathname, res)) return;
  sendJson(res, 404, { ok: false, error: 'not_found' });
});

server.listen(PORT, () => console.log(`prismbot-core listening on :${PORT}`));
