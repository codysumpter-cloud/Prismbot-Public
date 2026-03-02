const http = require('http');
const fs = require('fs');
const path = require('path');
const { URL } = require('url');

const PORT = process.env.PORT || 8790;
const HOST = process.env.HOST || '127.0.0.1';
const TOKEN = process.env.MISSION_CONTROL_TOKEN;
const REQUIRE_TOKEN_FOR_READ = String(process.env.REQUIRE_TOKEN_FOR_READ || '').toLowerCase() === 'true';
const baseDir = __dirname;
const publicDir = path.join(baseDir, 'public');
const dataDir = path.join(baseDir, 'data');
const workspaceDir = path.resolve(baseDir, '..', '..');
const openclawDir = path.resolve(workspaceDir, '..');
const sessionsFile = path.join(openclawDir, 'agents', 'main', 'sessions', 'sessions.json');
const cronJobsFile = path.join(openclawDir, 'cron', 'jobs.json');
const familyUsersFile = path.join(dataDir, 'family-users.json');
const familyChatQueueFile = path.join(dataDir, 'family-chat-queue.json');

const WRITE_WINDOW_MS = 60_000;
const WRITE_LIMIT = 30;
const writeRate = new Map();

const FAMILY_ROLES = new Set(['owner', 'admin_lite', 'member', 'family_user', 'child', 'viewer']);
const FAMILY_ROLE_PERMISSIONS = {
  owner: ['manage_users', 'manage_tasks', 'view_sessions', 'chat_priority'],
  admin_lite: ['manage_tasks', 'view_sessions', 'chat_priority'],
  member: ['manage_tasks', 'view_sessions'],
  family_user: ['manage_tasks', 'view_sessions'],
  child: ['view_sessions'],
  viewer: []
};

function setSecurityHeaders(res) {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('Referrer-Policy', 'no-referrer');
  res.setHeader('Cross-Origin-Resource-Policy', 'same-origin');
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
}

function sendJson(res, status, payload) {
  setSecurityHeaders(res);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(payload, null, 2));
}

function serveFile(res, filePath, contentType = 'text/plain; charset=utf-8') {
  fs.readFile(filePath, (err, data) => {
    if (err) {
      sendJson(res, 404, { error: 'Not found' });
      return;
    }
    setSecurityHeaders(res);
    res.writeHead(200, { 'Content-Type': contentType });
    res.end(data);
  });
}

function getClientIp(req) {
  return req.socket?.remoteAddress || 'unknown';
}

function readJson(fileName) {
  const filePath = path.join(dataDir, fileName);
  return fs.promises.readFile(filePath, 'utf8').then(raw => JSON.parse(raw));
}

async function readJsonIfExists(filePath, fallback) {
  try {
    const raw = await fs.promises.readFile(filePath, 'utf8');
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
}

function writeJson(fileName, value) {
  const filePath = path.join(dataDir, fileName);
  return fs.promises.writeFile(filePath, JSON.stringify(value, null, 2) + '\n', 'utf8');
}

function getBody(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
      if (body.length > 1_000_000) {
        reject(new Error('Payload too large'));
        req.destroy();
      }
    });
    req.on('end', () => resolve(body ? JSON.parse(body) : {}));
    req.on('error', reject);
  });
}

function isWriteMethod(method) {
  return method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE';
}

function readBearerToken(req) {
  const header = req.headers.authorization || '';
  return header.startsWith('Bearer ') ? header.slice(7) : null;
}

function authorizeWithMissionToken(req, res) {
  if (!TOKEN) {
    sendJson(res, 503, { error: 'MISSION_CONTROL_TOKEN is required for this mode' });
    return false;
  }

  const token = readBearerToken(req);
  if (token !== TOKEN) {
    sendJson(res, 401, { error: 'Unauthorized' });
    return false;
  }
  return true;
}

function authorizeWrite(req, res) {
  if (!TOKEN) return true;
  const token = readBearerToken(req);
  if (token !== TOKEN) {
    sendJson(res, 401, { error: 'Unauthorized' });
    return false;
  }
  return true;
}

function enforceWriteRateLimit(req, res) {
  const now = Date.now();
  const key = getClientIp(req);
  const hits = writeRate.get(key) || [];
  const freshHits = hits.filter(ts => now - ts < WRITE_WINDOW_MS);
  if (freshHits.length >= WRITE_LIMIT) {
    sendJson(res, 429, { error: 'Rate limit exceeded' });
    return false;
  }
  freshHits.push(now);
  writeRate.set(key, freshHits);
  return true;
}

function byProject(items, projectId) {
  if (!projectId || projectId === 'all') return items;
  return items.filter(item => item.projectId === projectId);
}

function toIso(ts) {
  if (!ts) return null;
  const n = Number(ts);
  if (!Number.isFinite(n) || n <= 0) return null;
  return new Date(n).toISOString();
}

function fileExists(filePath) {
  try {
    return fs.statSync(filePath).isFile();
  } catch {
    return false;
  }
}

function parsePositiveInt(value, fallback, min = 1, max = Number.MAX_SAFE_INTEGER) {
  const num = Number(value);
  if (!Number.isFinite(num)) return fallback;
  return Math.min(max, Math.max(min, Math.floor(num)));
}

function parseSort(parsedUrl, allowed, defaults = { by: 'updatedAt', dir: 'desc' }) {
  const by = parsedUrl.searchParams.get('sortBy') || defaults.by;
  const sortBy = allowed.includes(by) ? by : defaults.by;
  const rawDir = (parsedUrl.searchParams.get('sortDir') || defaults.dir).toLowerCase();
  const sortDir = rawDir === 'asc' ? 'asc' : 'desc';
  return { sortBy, sortDir };
}

function paginate(items, page, limit) {
  const total = items.length;
  const totalPages = Math.max(1, Math.ceil(total / limit));
  const safePage = Math.min(page, totalPages);
  const start = (safePage - 1) * limit;
  const end = start + limit;
  return {
    items: items.slice(start, end),
    meta: { page: safePage, limit, total, totalPages }
  };
}

async function listMemoryFiles() {
  const files = [];
  const memoryDir = path.join(workspaceDir, 'memory');

  if (fileExists(path.join(workspaceDir, 'MEMORY.md'))) {
    const stat = await fs.promises.stat(path.join(workspaceDir, 'MEMORY.md'));
    files.push({
      file: 'MEMORY.md',
      label: 'MEMORY.md',
      size: stat.size,
      updatedAt: stat.mtime.toISOString()
    });
  }

  try {
    const dirents = await fs.promises.readdir(memoryDir, { withFileTypes: true });
    for (const d of dirents) {
      if (!d.isFile() || !d.name.endsWith('.md')) continue;
      const full = path.join(memoryDir, d.name);
      const stat = await fs.promises.stat(full);
      files.push({
        file: `memory/${d.name}`,
        label: d.name,
        size: stat.size,
        updatedAt: stat.mtime.toISOString()
      });
    }
  } catch {}

  return files;
}

function resolveMemoryFile(fileParam) {
  if (!fileParam) return null;
  const normalized = String(fileParam).replace(/\\/g, '/');
  if (normalized === 'MEMORY.md') return path.join(workspaceDir, 'MEMORY.md');
  if (!normalized.startsWith('memory/') || normalized.includes('..')) return null;
  const full = path.join(workspaceDir, normalized);
  const rel = path.relative(workspaceDir, full);
  if (rel.startsWith('..')) return null;
  return full;
}

function searchInLines(lines, query, maxHits = 20, sortDir = 'asc') {
  if (!query) return [];
  const q = query.toLowerCase();
  const hits = [];

  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i];
    if (line.toLowerCase().includes(q)) {
      hits.push({ line: i + 1, text: line.trim().slice(0, 300) });
      if (hits.length >= maxHits) break;
    }
  }

  if (sortDir === 'desc') {
    hits.sort((a, b) => b.line - a.line);
  } else {
    hits.sort((a, b) => a.line - b.line);
  }

  return hits;
}

async function getSessions(activeOnly = false) {
  const raw = await readJsonIfExists(sessionsFile, {});
  const entries = Object.entries(raw || {}).map(([key, value]) => ({
    key,
    id: value.sessionId || key,
    updatedAt: toIso(value.updatedAt),
    updatedAtMs: Number(value.updatedAt) || 0,
    chatType: value.chatType || 'unknown',
    provider: value.origin?.provider || null,
    from: value.origin?.from || null,
    to: value.origin?.to || value.lastTo || null,
    label: value.origin?.label || null,
    systemSent: Boolean(value.systemSent),
    abortedLastRun: Boolean(value.abortedLastRun)
  }));

  const now = Date.now();
  const activeCutoff = now - (6 * 60 * 60 * 1000);
  return activeOnly ? entries.filter(s => s.updatedAtMs >= activeCutoff) : entries;
}

async function getSchedules() {
  const payload = await readJsonIfExists(cronJobsFile, { version: 1, jobs: [] });
  const jobs = Array.isArray(payload.jobs) ? payload.jobs : [];

  const normalized = jobs.map(job => ({
    id: job.id || job.name || `job-${Math.random().toString(16).slice(2)}`,
    name: job.name || job.id || 'Unnamed job',
    schedule: job.schedule || job.cron || null,
    enabled: job.enabled !== false,
    projectId: job.projectId || null,
    task: job.task || job.command || job.invokeCommand || null,
    lastRunAt: job.lastRunAt || job.lastRun || null,
    nextRunAt: job.nextRunAt || null,
    lastStatus: job.lastStatus || job.status || null,
    runCount: Number.isFinite(job.runCount) ? job.runCount : null
  }));

  const recentRuns = normalized
    .filter(j => j.lastRunAt)
    .sort((a, b) => String(b.lastRunAt).localeCompare(String(a.lastRunAt)))
    .slice(0, 20)
    .map(j => ({
      id: j.id,
      name: j.name,
      lastRunAt: j.lastRunAt,
      lastStatus: j.lastStatus,
      projectId: j.projectId
    }));

  return {
    source: cronJobsFile,
    jobs: normalized,
    recentRuns
  };
}

function pickSnippet(text, q) {
  const index = text.toLowerCase().indexOf(q.toLowerCase());
  if (index < 0) return text.slice(0, 160);
  const start = Math.max(0, index - 60);
  const end = Math.min(text.length, index + q.length + 100);
  return text.slice(start, end).replace(/\s+/g, ' ').trim();
}

async function globalSearch(query, projectId) {
  const q = String(query || '').trim();
  if (!q) return { query: '', tasks: [], activity: [], sessions: [], memory: [] };

  const [tasks, activity, sessions, memoryFiles] = await Promise.all([
    readJson('tasks.json').then(items => byProject(items, projectId)),
    readJson('activity.json').then(items => byProject(items, projectId)),
    getSessions(false),
    listMemoryFiles()
  ]);

  const qLower = q.toLowerCase();
  const taskHits = tasks
    .filter(t => [t.title, t.owner, t.status, t.priority].some(v => String(v || '').toLowerCase().includes(qLower)));

  const activityHits = activity
    .filter(a => String(a.event || '').toLowerCase().includes(qLower));

  const sessionHits = sessions
    .filter(s => [s.key, s.id, s.label, s.provider, s.to, s.from].some(v => String(v || '').toLowerCase().includes(qLower)));

  const memoryHits = [];
  for (const file of memoryFiles) {
    const fullPath = resolveMemoryFile(file.file);
    if (!fullPath) continue;
    let content = '';
    try {
      content = await fs.promises.readFile(fullPath, 'utf8');
    } catch {
      continue;
    }
    if (content.toLowerCase().includes(qLower)) {
      memoryHits.push({ file: file.file, snippet: pickSnippet(content, q), updatedAt: file.updatedAt });
    }
  }

  return {
    query: q,
    tasks: taskHits,
    activity: activityHits,
    sessions: sessionHits,
    memory: memoryHits
  };
}

function sortItems(items, sortBy, sortDir, fallbackText = '') {
  const direction = sortDir === 'asc' ? 1 : -1;
  return [...items].sort((a, b) => {
    const av = a?.[sortBy] ?? fallbackText;
    const bv = b?.[sortBy] ?? fallbackText;

    if (typeof av === 'number' && typeof bv === 'number') {
      return (av - bv) * direction;
    }

    return String(av).localeCompare(String(bv)) * direction;
  });
}

function normalizeHandle(value) {
  return String(value || '').trim().toLowerCase();
}

function toHandleList(user) {
  return [user.username, user.displayName, user.email]
    .map(normalizeHandle)
    .filter(Boolean);
}

async function loadFamilyUsers() {
  const fallback = [
    {
      id: 'fam-owner',
      displayName: 'Owner',
      username: 'owner',
      role: 'owner',
      status: 'active',
      email: '',
      lastActiveAt: null,
      createdAt: new Date().toISOString()
    }
  ];
  return readJsonIfExists(familyUsersFile, fallback);
}

function normalizeFamilyRole(role) {
  const normalized = String(role || '').trim().toLowerCase();
  return FAMILY_ROLES.has(normalized) ? normalized : 'member';
}

function userWithPermissions(user) {
  const role = normalizeFamilyRole(user.role);
  return {
    ...user,
    role,
    permissions: FAMILY_ROLE_PERMISSIONS[role] || []
  };
}

async function saveFamilyUsers(users) {
  await fs.promises.writeFile(familyUsersFile, JSON.stringify(users, null, 2) + '\n', 'utf8');
}

async function loadFamilyChatQueue() {
  return readJsonIfExists(familyChatQueueFile, []);
}

async function saveFamilyChatQueue(items) {
  await fs.promises.writeFile(familyChatQueueFile, JSON.stringify(items, null, 2) + '\n', 'utf8');
}

function isPrivateSessionEntry(session) {
  const chatType = normalizeHandle(session.chatType);
  if (chatType.includes('private') || chatType.includes('dm') || chatType.includes('direct')) return true;
  const to = normalizeHandle(session.to);
  return to && !to.includes('channel:') && !to.includes('guild:') && !to.includes('room:');
}

async function getFamilySessionStats() {
  const [users, sessions] = await Promise.all([loadFamilyUsers(), getSessions(false)]);
  const rows = users.map(user => {
    const handles = toHandleList(user);
    const matches = sessions.filter(s => handles.some(h =>
      normalizeHandle(s.to).includes(h) ||
      normalizeHandle(s.from).includes(h) ||
      normalizeHandle(s.label).includes(h)
    ));

    const privateSessions = matches.filter(isPrivateSessionEntry);
    const sorted = [...matches].sort((a, b) => (b.updatedAtMs || 0) - (a.updatedAtMs || 0));

    return {
      userId: user.id,
      displayName: user.displayName,
      username: user.username,
      role: user.role,
      status: user.status,
      sessionCount: matches.length,
      privateSessionCount: privateSessions.length,
      lastActiveAt: sorted[0]?.updatedAt || user.lastActiveAt || null
    };
  });

  return {
    updatedAt: new Date().toISOString(),
    users: rows
  };
}

function maybeAuthorizeRead(req, res) {
  if (!REQUIRE_TOKEN_FOR_READ) return true;
  return authorizeWithMissionToken(req, res);
}

async function handleApi(req, res, parsedUrl) {
  const { method } = req;
  const pathname = parsedUrl.pathname;
  const projectId = parsedUrl.searchParams.get('project');

  if (method === 'GET' && pathname === '/api/projects') {
    if (!maybeAuthorizeRead(req, res)) return;
    return sendJson(res, 200, await readJson('projects.json'));
  }

  if (method === 'GET' && pathname === '/api/roster') {
    if (!maybeAuthorizeRead(req, res)) return;
    return sendJson(res, 200, byProject(await readJson('agents.json'), projectId));
  }

  if (method === 'GET' && pathname === '/api/activity') {
    if (!maybeAuthorizeRead(req, res)) return;
    return sendJson(res, 200, byProject(await readJson('activity.json'), projectId));
  }

  if (method === 'GET' && pathname === '/api/tasks') {
    if (!maybeAuthorizeRead(req, res)) return;
    return sendJson(res, 200, byProject(await readJson('tasks.json'), projectId));
  }

  if (method === 'GET' && pathname === '/api/sessions') {
    if (!maybeAuthorizeRead(req, res)) return;
    const limit = parsePositiveInt(parsedUrl.searchParams.get('limit'), 50, 1, 200);
    const page = parsePositiveInt(parsedUrl.searchParams.get('page'), 1, 1, 10_000);
    const active = parsedUrl.searchParams.get('active') === '1';
    const { sortBy, sortDir } = parseSort(parsedUrl, ['updatedAt', 'id', 'provider', 'to', 'from', 'chatType'], { by: 'updatedAt', dir: 'desc' });

    const sessions = await getSessions(active);
    const normalized = sessions.map(({ updatedAtMs, ...rest }) => ({ ...rest, updatedAtMs }));
    const sorted = sortItems(normalized, sortBy === 'updatedAt' ? 'updatedAtMs' : sortBy, sortDir, '');
    const { items, meta } = paginate(sorted.map(({ updatedAtMs, ...rest }) => rest), page, limit);

    return sendJson(res, 200, {
      items,
      ...meta,
      activeOnly: active,
      sortBy,
      sortDir
    });
  }

  if (method === 'GET' && pathname === '/api/memory/files') {
    if (!maybeAuthorizeRead(req, res)) return;
    const q = (parsedUrl.searchParams.get('q') || '').trim().toLowerCase();
    const limit = parsePositiveInt(parsedUrl.searchParams.get('limit'), 50, 1, 200);
    const page = parsePositiveInt(parsedUrl.searchParams.get('page'), 1, 1, 10_000);
    const { sortBy, sortDir } = parseSort(parsedUrl, ['updatedAt', 'label', 'size', 'file'], { by: 'updatedAt', dir: 'desc' });

    const files = await listMemoryFiles();
    const filtered = q ? files.filter(f => f.file.toLowerCase().includes(q) || f.label.toLowerCase().includes(q)) : files;
    const sorted = sortItems(filtered, sortBy, sortDir, '');
    const { items, meta } = paginate(sorted, page, limit);

    return sendJson(res, 200, {
      items,
      ...meta,
      sortBy,
      sortDir,
      query: q
    });
  }

  if (method === 'GET' && pathname === '/api/memory/read') {
    if (!maybeAuthorizeRead(req, res)) return;
    const file = parsedUrl.searchParams.get('file');
    const q = (parsedUrl.searchParams.get('q') || '').trim();
    const lineStart = parsePositiveInt(parsedUrl.searchParams.get('lineStart'), 1, 1, 1_000_000);
    const lineLimit = parsePositiveInt(parsedUrl.searchParams.get('lineLimit'), 500, 1, 2000);
    const hitsLimit = parsePositiveInt(parsedUrl.searchParams.get('hitsLimit'), 20, 1, 200);
    const { sortBy, sortDir } = parseSort(parsedUrl, ['line', 'text'], { by: 'line', dir: 'asc' });

    const resolved = resolveMemoryFile(file);
    if (!resolved) return sendJson(res, 400, { error: 'Invalid memory file path' });

    try {
      const content = await fs.promises.readFile(resolved, 'utf8');
      const maxChars = 120_000;
      const trimmed = content.length > maxChars ? content.slice(0, maxChars) : content;
      const allLines = trimmed.split(/\r?\n/);
      const startIndex = Math.min(allLines.length, lineStart - 1);
      const linesPage = allLines.slice(startIndex, startIndex + lineLimit);
      const pagedContent = linesPage.join('\n');

      const rawHits = searchInLines(allLines, q, hitsLimit, sortBy === 'line' ? sortDir : 'asc');
      const hits = sortBy === 'text' ? sortItems(rawHits, 'text', sortDir, '') : rawHits;

      return sendJson(res, 200, {
        file,
        chars: trimmed.length,
        truncated: content.length > maxChars,
        totalLines: allLines.length,
        lineStart,
        lineLimit,
        content: pagedContent,
        hits,
        hitsLimit,
        sortBy,
        sortDir
      });
    } catch {
      return sendJson(res, 404, { error: 'Memory file not found' });
    }
  }

  if (method === 'GET' && pathname === '/api/schedules') {
    if (!maybeAuthorizeRead(req, res)) return;
    const schedules = await getSchedules();
    return sendJson(res, 200, {
      ...schedules,
      jobs: byProject(schedules.jobs, projectId),
      recentRuns: byProject(schedules.recentRuns, projectId)
    });
  }

  if (method === 'GET' && pathname === '/api/search') {
    if (!maybeAuthorizeRead(req, res)) return;
    const q = parsedUrl.searchParams.get('q') || '';
    const type = (parsedUrl.searchParams.get('type') || 'all').toLowerCase();
    const limit = parsePositiveInt(parsedUrl.searchParams.get('limit'), 20, 1, 100);
    const page = parsePositiveInt(parsedUrl.searchParams.get('page'), 1, 1, 10_000);
    const { sortBy, sortDir } = parseSort(parsedUrl, ['updatedAt', 'name'], { by: 'updatedAt', dir: 'desc' });

    const result = await globalSearch(q, projectId);

    if (type === 'memory') {
      const normalized = result.memory.map(item => ({ ...item, name: item.file }));
      const sorted = sortItems(normalized, sortBy, sortDir, '');
      const { items, meta } = paginate(sorted.map(({ name, ...rest }) => rest), page, limit);
      return sendJson(res, 200, {
        query: result.query,
        type,
        items,
        ...meta,
        sortBy,
        sortDir
      });
    }

    const take = arr => arr.slice(0, limit);
    return sendJson(res, 200, {
      query: result.query,
      type: 'all',
      tasks: take(result.tasks),
      activity: take(result.activity),
      sessions: take(result.sessions),
      memory: take(result.memory)
    });
  }

  if (method === 'GET' && pathname === '/api/family/users') {
    if (!maybeAuthorizeRead(req, res)) return;
    const users = await loadFamilyUsers();
    return sendJson(res, 200, users.map(userWithPermissions));
  }

  if (method === 'GET' && pathname === '/api/family/sessions') {
    if (!maybeAuthorizeRead(req, res)) return;
    return sendJson(res, 200, await getFamilySessionStats());
  }

  if (method === 'GET' && pathname === '/api/family/chat') {
    if (!maybeAuthorizeRead(req, res)) return;
    const queue = await loadFamilyChatQueue();
    return sendJson(res, 200, {
      updatedAt: new Date().toISOString(),
      queue: queue.slice(-25).reverse()
    });
  }

  if (isWriteMethod(method) && pathname.startsWith('/api/family/')) {
    if (!authorizeWrite(req, res) || !enforceWriteRateLimit(req, res)) return;

    if (method === 'POST' && pathname === '/api/family/users') {
      const payload = await getBody(req);
      if (!payload.displayName || !payload.username) {
        return sendJson(res, 400, { error: 'displayName and username are required' });
      }
      const users = await loadFamilyUsers();
      const user = {
        id: `fam-${Date.now()}`,
        displayName: String(payload.displayName),
        username: String(payload.username),
        role: normalizeFamilyRole(payload.role || 'member'),
        email: String(payload.email || ''),
        status: 'active',
        lastActiveAt: null,
        createdAt: new Date().toISOString()
      };
      users.push(user);
      await saveFamilyUsers(users);
      return sendJson(res, 201, userWithPermissions(user));
    }

    if (method === 'POST' && pathname.match(/^\/api\/family\/users\/[^/]+\/disable$/)) {
      const userId = pathname.split('/')[4];
      const users = await loadFamilyUsers();
      const idx = users.findIndex(u => u.id === userId);
      if (idx === -1) return sendJson(res, 404, { error: 'User not found' });
      users[idx] = { ...users[idx], status: 'disabled' };
      await saveFamilyUsers(users);
      return sendJson(res, 200, { ok: true, user: users[idx], message: 'User disabled (scaffold action)' });
    }

    if (method === 'POST' && pathname.match(/^\/api\/family\/users\/[^/]+\/reset-password$/)) {
      const userId = pathname.split('/')[4];
      const payload = await getBody(req);
      if (String(payload.confirm || '').toUpperCase() !== 'RESET') {
        return sendJson(res, 400, {
          error: 'confirm_required',
          message: 'Set confirm="RESET" to perform a manual password reset.'
        });
      }
      const users = await loadFamilyUsers();
      const user = users.find(u => u.id === userId);
      if (!user) return sendJson(res, 404, { error: 'User not found' });
      return sendJson(res, 200, {
        ok: true,
        userId,
        message: 'Manual reset acknowledged. Apply password change in your identity provider/auth backend.'
      });
    }

    if (method === 'POST' && pathname === '/api/family/chat') {
      const payload = await getBody(req);
      if (!payload.message || !String(payload.message).trim()) {
        return sendJson(res, 400, { error: 'message is required' });
      }

      const queue = await loadFamilyChatQueue();
      const item = {
        id: `chat-${Date.now()}`,
        message: String(payload.message).trim(),
        createdAt: new Date().toISOString(),
        status: 'queued',
        delivery: 'local-assistant-queue',
        note: 'Queued safely. Live bridge not configured in this build.'
      };
      queue.push(item);
      await saveFamilyChatQueue(queue);
      return sendJson(res, 201, item);
    }
  }

  if (isWriteMethod(method) && pathname.startsWith('/api/tasks')) {
    if (!authorizeWrite(req, res) || !enforceWriteRateLimit(req, res)) return;

    const parts = pathname.split('/').filter(Boolean);
    const taskId = parts[2];
    const tasks = await readJson('tasks.json');

    if (method === 'POST' && pathname === '/api/tasks') {
      const payload = await getBody(req);
      if (!payload.title || !payload.projectId) {
        return sendJson(res, 400, { error: 'title and projectId are required' });
      }
      const task = {
        id: `task-${Date.now()}`,
        projectId: String(payload.projectId),
        title: String(payload.title),
        owner: payload.owner ? String(payload.owner) : 'Unassigned',
        priority: payload.priority ? String(payload.priority) : 'medium',
        status: payload.status ? String(payload.status) : 'todo'
      };
      tasks.push(task);
      await writeJson('tasks.json', tasks);
      return sendJson(res, 201, task);
    }

    if ((method === 'PATCH' || method === 'PUT') && taskId) {
      const payload = await getBody(req);
      const index = tasks.findIndex(t => t.id === taskId);
      if (index === -1) return sendJson(res, 404, { error: 'Task not found' });

      const current = tasks[index];
      const next = {
        ...current,
        ...(payload.title !== undefined ? { title: String(payload.title) } : {}),
        ...(payload.owner !== undefined ? { owner: String(payload.owner) } : {}),
        ...(payload.priority !== undefined ? { priority: String(payload.priority) } : {}),
        ...(payload.status !== undefined ? { status: String(payload.status) } : {}),
        ...(payload.projectId !== undefined ? { projectId: String(payload.projectId) } : {})
      };

      tasks[index] = next;
      await writeJson('tasks.json', tasks);
      return sendJson(res, 200, next);
    }

    if (method === 'DELETE' && taskId) {
      const index = tasks.findIndex(t => t.id === taskId);
      if (index === -1) return sendJson(res, 404, { error: 'Task not found' });
      const [deleted] = tasks.splice(index, 1);
      await writeJson('tasks.json', tasks);
      return sendJson(res, 200, deleted);
    }
  }

  sendJson(res, 404, { error: 'Not found' });
}

const server = http.createServer(async (req, res) => {
  try {
    const parsedUrl = new URL(req.url, `http://${HOST}:${PORT}`);
    const { method } = req;

    if (parsedUrl.pathname === '/health') {
      return sendJson(res, 200, {
        status: 'ok',
        app: 'mission-control',
        port: Number(PORT),
        requireTokenForRead: REQUIRE_TOKEN_FOR_READ
      });
    }

    if (parsedUrl.pathname.startsWith('/api/')) {
      return await handleApi(req, res, parsedUrl);
    }

    if (method !== 'GET') {
      return sendJson(res, 405, { error: 'Method not allowed' });
    }

    if (parsedUrl.pathname === '/' || parsedUrl.pathname === '/index.html') {
      return serveFile(res, path.join(publicDir, 'index.html'), 'text/html; charset=utf-8');
    }

    if (parsedUrl.pathname === '/styles.css') {
      return serveFile(res, path.join(publicDir, 'styles.css'), 'text/css; charset=utf-8');
    }

    sendJson(res, 404, { error: 'Not found' });
  } catch (error) {
    if (error instanceof SyntaxError) {
      return sendJson(res, 400, { error: 'Invalid JSON payload' });
    }
    console.error(error);
    sendJson(res, 500, { error: 'Internal server error' });
  }
});

server.listen(PORT, HOST, () => {
  console.log(`Mission Control listening at http://${HOST}:${PORT}`);
});
