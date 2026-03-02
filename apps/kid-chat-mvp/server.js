const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFile, exec } = require('child_process');
const { promisify } = require('util');
const execFileAsync = promisify(execFile);
const execAsync = promisify(exec);

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

const PORT = Number(process.env.PORT || 8787);
const HOST = process.env.HOST || '0.0.0.0';
const MAX_INPUT = Number(process.env.MAX_INPUT || 500);
const ADMIN_USERNAME = process.env.ADMIN_USERNAME || 'admin';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'change-me-now';
const SIGNUP_ENABLED = String(process.env.SIGNUP_ENABLED || 'true').toLowerCase() === 'true';
const SIGNUP_INVITE_CODE = String(process.env.SIGNUP_INVITE_CODE || '');

const WORKSPACE_ROOT = path.resolve(__dirname, '..', '..');
const OPENCLAW_LOCAL_BIN = process.platform === 'win32'
  ? path.join(WORKSPACE_ROOT, 'node_modules', '.bin', 'openclaw.cmd')
  : path.join(WORKSPACE_ROOT, 'node_modules', '.bin', 'openclaw');

const OPENCLAW_LAUNCH_MODE = process.env.OPENCLAW_LAUNCH_MODE || (fs.existsSync(OPENCLAW_LOCAL_BIN) ? 'local-bin' : (process.platform === 'win32' ? 'npx' : 'bin'));
const OPENCLAW_BIN = process.env.OPENCLAW_BIN || (
  OPENCLAW_LAUNCH_MODE === 'local-bin' ? OPENCLAW_LOCAL_BIN :
  OPENCLAW_LAUNCH_MODE === 'npx' ? (process.platform === 'win32' ? 'npx.cmd' : 'npx') :
  'openclaw'
);

const WORKFLOW_STATUSES = ['queued', 'assigned', 'running', 'blocked', 'done'];
const AGENT_ROLES = ['pm', 'architect', 'engineer', 'qa', 'research', 'designer', 'ops'];

const files = {
  users: path.join(__dirname, 'users.json'),
  profiles: path.join(__dirname, 'profiles.json'),
  history: path.join(__dirname, 'history.json'),
  tasks: path.join(__dirname, 'tasks.json'),
  activity: path.join(__dirname, 'activity.json'),
  builder: path.join(__dirname, 'builder.json')
};

const BLOCKED_KID = [
  'address', 'phone number', 'where do you live', 'password', 'credit card',
  'suicide', 'self harm', 'kill myself', 'sexual', 'porn', 'nudes'
];

const sessions = new Map();

const BUILDER_WIDGETS = ['daily_prompt', 'favorites', 'recipe_cards', 'chat_tips', 'learning_corner', 'progress_tracker'];
const BUILDER_LAYOUTS = ['focus', 'balanced', 'explore'];

function defaultBuilderConfig() {
  return {
    layoutPreset: 'balanced',
    widgets: ['daily_prompt', 'chat_tips', 'favorites'],
    helpPinned: true,
    updatedAt: nowIso()
  };
}

function sanitizeBuilderConfig(input, current = defaultBuilderConfig()) {
  const next = { ...current };
  const requestedLayout = String(input?.layoutPreset || current.layoutPreset || 'balanced');
  next.layoutPreset = BUILDER_LAYOUTS.includes(requestedLayout) ? requestedLayout : 'balanced';

  const requestedWidgets = Array.isArray(input?.widgets) ? input.widgets.map(w => String(w)) : current.widgets;
  next.widgets = Array.from(new Set((requestedWidgets || []).filter(w => BUILDER_WIDGETS.includes(w)))).slice(0, 6);
  if (!next.widgets.length) next.widgets = ['daily_prompt', 'chat_tips'];

  if (typeof input?.helpPinned === 'boolean') next.helpPinned = input.helpPinned;
  else if (typeof current.helpPinned === 'boolean') next.helpPinned = current.helpPinned;
  else next.helpPinned = true;

  next.updatedAt = nowIso();
  return next;
}

function parseTopicsFromText(text) {
  const m = text.match(/topics?\s*(?:to|=|:)?\s*([^.;\n]+)/i);
  if (!m) return null;
  return m[1].split(/[;,]/).map(x => x.trim()).filter(Boolean).slice(0, 20);
}

function parseQuotedOrAfter(text, key) {
  const quote = text.match(new RegExp(`${key}\\s*["']([^"']+)["']`, 'i'));
  if (quote) return quote[1].trim();
  const plain = text.match(new RegExp(`${key}\\s*(?:to|as|=|:)\\s*([^.;\\n]+)`, 'i'));
  if (plain) return plain[1].trim();
  return null;
}

function parseAdminOperatorIntent(text) {
  const t = String(text || '').trim();
  const l = t.toLowerCase();

  if (/\b(list|show|get|view)\b.*\busers\b/.test(l)) return { action: 'list_users' };
  if (/\b(list|show|get|view)\b.*\bsessions?\b/.test(l)) return { action: 'list_sessions' };
  if (/\b(list|show|get|view)\b.*\b(tasks?|workflow)\b/.test(l)) return { action: 'list_tasks' };
  if (/\b(list|show|get|view|review)\b.*\bactivity\b/.test(l)) return { action: 'list_activity' };

  if ((/\b(create|add)\b.*\btask\b/.test(l)) || /^task\b/.test(l)) {
    const title = parseQuotedOrAfter(t, 'task') || parseQuotedOrAfter(t, 'title') || t.replace(/^.*\btask\b\s*/i, '').trim();
    const roleMatch = l.match(/\b(pm|architect|engineer|qa|research|designer|ops)\b/);
    return { action: 'create_task', title: title || null, assigneeRole: roleMatch ? roleMatch[1] : null };
  }

  if (/\b(update|set|move|mark|change|reassign)\b.*\btask\b/.test(l)) {
    const idMatch = t.match(/task[_-][a-z0-9]+/i) || t.match(/\btask\s+([a-z0-9_-]{4,})/i);
    const statusMatch = l.match(/\b(queued|assigned|running|blocked|done)\b/);
    const roleMatch = l.match(/\b(pm|architect|engineer|qa|research|designer|ops|none|unassigned)\b/);
    return {
      action: 'update_task',
      taskId: idMatch ? (idMatch[1] || idMatch[0]) : null,
      status: statusMatch ? statusMatch[1] : null,
      assigneeRole: roleMatch ? roleMatch[1] : null
    };
  }

  if (/\b(create|add)\b.*\buser\b/.test(l)) {
    const usernameMatch = t.match(/username\s*(?:=|:)?\s*([a-z0-9_-]+)/i);
    const displayMatch = t.match(/display\s+name\s*(?:=|:)?\s*([a-z0-9 _-]+)/i);
    const passwordMatch = t.match(/password\s*(?:=|:)?\s*([\S]+)/i);
    const username = (usernameMatch?.[1] || parseQuotedOrAfter(t, 'username') || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
    const displayName = (displayMatch?.[1] || parseQuotedOrAfter(t, 'display name') || parseQuotedOrAfter(t, 'name') || username || null);
    const password = passwordMatch?.[1] || parseQuotedOrAfter(t, 'password');
    return { action: 'create_user', username: username || null, displayName, password: password || null };
  }

  if (/\breset\b.*\bpassword\b/.test(l)) {
    return { action: 'password_reset_disabled' };
  }

  if (/\b(enable|disable)\b.*\buser\b/.test(l)) {
    const active = /\benable\b/.test(l);
    const userRef = parseQuotedOrAfter(t, 'user') || parseQuotedOrAfter(t, 'username') || t.match(/user\s+([a-z0-9_-]+)/i)?.[1] || null;
    return { action: 'set_user_active', userRef, active };
  }

  if (/\b(update|set|rename|change)\b.*\b(display name|name)\b/.test(l)) {
    const userRef = parseQuotedOrAfter(t, 'user') || parseQuotedOrAfter(t, 'username') || t.match(/for\s+([a-z0-9_-]+)/i)?.[1] || null;
    const displayName = parseQuotedOrAfter(t, 'display name') || parseQuotedOrAfter(t, 'name');
    return { action: 'update_user_display_name', userRef, displayName: displayName || null };
  }

  if (/\b(update|set|change)\b.*\b(profile|mode|greeting|topics?)\b/.test(l)) {
    const userRef = parseQuotedOrAfter(t, 'user') || parseQuotedOrAfter(t, 'username') || t.match(/for\s+([a-z0-9_-]+)/i)?.[1] || null;
    const mode = /\bkid\b/.test(l) ? 'kid' : (/\badult\b/.test(l) ? 'adult' : null);
    const greeting = parseQuotedOrAfter(t, 'greeting');
    const topics = parseTopicsFromText(t);
    return { action: 'update_user_profile', userRef, mode, greeting, topics };
  }

  return { action: 'unsupported' };
}

function parseFamilyBuilderIntent(text) {
  const t = String(text || '').trim();
  const l = t.toLowerCase();
  const crossUser = /(for user|for admin|for owner|all users|system|server|mission control|create user|reset password|admin)/.test(l);
  if (crossUser) return { action: 'blocked_scope', reason: 'family_scope_only' };
  if (!/(builder|layout|widget|help pin|help center|profile|tone|greeting|topics?|customi[sz]e|personal)/.test(l)) {
    return { action: 'none' };
  }

  const out = { action: 'update_self_builder_or_profile', builder: {}, profile: {} };

  const layout = l.match(/\b(focus|balanced|explore)\b/);
  if (layout) out.builder.layoutPreset = layout[1];

  if (/\bpin\b.*\bhelp\b|\bhelp\b.*\bpin\b/.test(l)) out.builder.helpPinned = true;
  if (/\bunpin\b.*\bhelp\b|\bhide\b.*\bhelp\b/.test(l)) out.builder.helpPinned = false;

  const widgetMentions = BUILDER_WIDGETS.filter(w => l.includes(w) || l.includes((w.replace('_', ' '))));
  if (widgetMentions.length) {
    const remove = /\bremove\b|\bhide\b|\bwithout\b/.test(l);
    out.builder.widgetPatch = { mode: remove ? 'remove' : 'add', widgets: widgetMentions };
  }

  const mode = /\bkid\b/.test(l) ? 'kid' : (/\badult\b/.test(l) ? 'adult' : null);
  if (mode) out.profile.mode = mode;

  const tone = parseQuotedOrAfter(t, 'tone');
  if (tone) out.profile.tone = tone.slice(0, 32);

  const greeting = parseQuotedOrAfter(t, 'greeting');
  if (greeting) out.profile.greeting = greeting.slice(0, 200);

  const topics = parseTopicsFromText(t);
  if (topics) out.profile.topics = topics.map(x => x.slice(0, 32));

  const hasChanges = Object.keys(out.builder).length || Object.keys(out.profile).length;
  return hasChanges ? out : { action: 'none' };
}

function nowIso() { return new Date().toISOString(); }
function randomId(prefix = 'id') { return `${prefix}_${crypto.randomBytes(8).toString('hex')}`; }

function readJson(file, fallback) {
  if (!fs.existsSync(file)) return fallback;
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return fallback; }
}

function writeJson(file, data) {
  fs.writeFileSync(file, JSON.stringify(data, null, 2));
}

function scryptHash(password, salt = crypto.randomBytes(16).toString('hex')) {
  const derived = crypto.scryptSync(password, salt, 64).toString('hex');
  return `scrypt:${salt}:${derived}`;
}

function verifyPassword(password, packed) {
  if (!packed || !packed.startsWith('scrypt:')) return false;
  const [, salt, hash] = packed.split(':');
  if (!salt || !hash) return false;
  const test = crypto.scryptSync(password, salt, 64).toString('hex');
  return crypto.timingSafeEqual(Buffer.from(test), Buffer.from(hash));
}

function parseCookies(req) {
  const raw = req.headers.cookie || '';
  const out = {};
  raw.split(';').map(x => x.trim()).filter(Boolean).forEach(p => {
    const i = p.indexOf('=');
    if (i > 0) out[p.slice(0, i)] = decodeURIComponent(p.slice(i + 1));
  });
  return out;
}

function parseBody(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', c => {
      body += c;
      if (body.length > 1e6) req.destroy();
    });
    req.on('end', () => {
      try { resolve(JSON.parse(body || '{}')); } catch (e) { reject(e); }
    });
    req.on('error', reject);
  });
}

function sendJson(res, code, body, headers = {}) {
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', ...headers });
  res.end(JSON.stringify(body));
}

function contentTypeFor(file) {
  const ext = path.extname(file);
  if (ext === '.css') return 'text/css';
  if (ext === '.js') return 'application/javascript';
  if (ext === '.webmanifest') return 'application/manifest+json';
  if (ext === '.json') return 'application/json';
  return 'text/html';
}

function sanitizeUser(user) {
  return {
    id: user.id,
    username: user.username,
    displayName: user.displayName,
    role: user.role,
    active: user.active,
    createdAt: user.createdAt,
    lastLoginAt: user.lastLoginAt || null,
    onboardingCompletedAt: user.onboardingCompletedAt || null
  };
}

function localKidReply(text) {
  const t = text.toLowerCase();
  const facts = [
    'Octopuses have three hearts 💙💙💙.',
    'Honey never really spoils — archaeologists found edible honey in ancient tombs!',
    'A group of flamingos is called a flamboyance 🦩.',
    'Bananas are berries, but strawberries are not!'
  ];
  const jokes = [
    'Why did the math book look sad? Because it had too many problems 😄.',
    'What do you call a sleeping bull? A bulldozer 😴.',
    'Why did the cookie go to the doctor? It felt crummy 🍪.'
  ];
  if (t.includes('joke')) return jokes[Math.floor(Math.random() * jokes.length)];
  if (t.includes('fact')) return facts[Math.floor(Math.random() * facts.length)];
  if (t.includes('story')) return 'Once upon a time, a tiny dragon learned that kindness is a superpower. The end ✨';
  if (t.includes('homework') || t.includes('school')) return 'I can help! Tell me the subject and what part feels tricky.';
  if (t.includes('website') || t.includes('web site') || t.includes('code')) return 'Absolutely — we can build your website step by step in a safe way. Tell me what you want it to look like and I\'ll help you create it.';
  return 'Great question! Want a fun fact, a joke, a story, or help building something cool?';
}

function localAdultReply(text) {
  const t = text.toLowerCase();
  if (t.includes('recipe')) return 'Try this quick dinner: garlic butter chicken, rice, and steamed broccoli (25 mins).';
  if (t.includes('plan') || t.includes('schedule')) return 'I can help organize that. What are the top 3 priorities for tomorrow?';
  if (t.includes('joke')) return 'Adult joke-lite: I told my router we needed to talk. It said: “I’m already connected.” 😅';
  return 'I can help with plans, writing, ideas, or quick answers. What outcome do you want?';
}

function safeKidResponse(input) {
  const lowered = input.toLowerCase();
  if (BLOCKED_KID.some(k => lowered.includes(k))) {
    return 'I can’t help with that. Let’s ask an admin and switch to something safe and fun.';
  }
  return null;
}

function isMetaAck(text = '') {
  const t = String(text).toLowerCase();
  return t.startsWith('got it') || t.includes('family web mode') || t.includes('i won’t use tools') || t.includes('respond in plain text');
}

async function askOpenClaw(text, mode, conversationId, contextType = 'family_chat', allowRetry = true) {
  const guardrail = mode === 'kid'
    ? 'You are in kid-safe family web mode. Keep responses age-appropriate and safe. Do not use tools. Respond in plain text only.'
    : 'You are in family web mode. Do not use tools. Respond in plain text only.';

  const productContext = `Product context: You are assisting inside Prism Family Chat + Mission Control admin dashboard. Assume requests refer to this app unless user explicitly says otherwise. Default to concrete, actionable next steps for THIS app (users, sessions, tasks, activity, profiles, admin controls), not generic web-dev advice.`;
  const adminContext = contextType === 'admin_live_chat'
    ? 'Admin context: user is owner/admin and expects operationally useful guidance for this dashboard and family app.'
    : 'Family user context: keep guidance practical for their own account/profile and app usage.';

  const composed = `${guardrail}\n${productContext}\n${adminContext}\n\nInstruction: Reply directly to the user's message below. Do NOT mention system/prompt/tool instructions.\n\n=== USER MESSAGE START ===\n${text}\n=== USER MESSAGE END ===`;
  const openclawArgs = [
    'agent',
    '--agent', 'main',
    '--session-id', conversationId,
    '--message', composed,
    '--json',
    '--thinking', 'low',
    '--timeout', '90'
  ];

  let stdout;
  if (process.platform === 'win32' && /\.cmd$/i.test(OPENCLAW_BIN)) {
    const quoted = [OPENCLAW_BIN, ...openclawArgs].map((x) => `"${String(x).replaceAll('"', '\\"')}"`).join(' ');
    const out = await execAsync(quoted, {
      timeout: 95_000,
      maxBuffer: 5 * 1024 * 1024,
      cwd: WORKSPACE_ROOT,
      windowsHide: true,
    });
    stdout = out.stdout;
  } else {
    const launchArgs = OPENCLAW_LAUNCH_MODE === 'npx' ? ['openclaw', ...openclawArgs] : openclawArgs;
    const out = await execFileAsync(OPENCLAW_BIN, launchArgs, { timeout: 95_000, maxBuffer: 5 * 1024 * 1024, cwd: WORKSPACE_ROOT });
    stdout = out.stdout;
  }
  let out = '';
  try {
    const parsed = JSON.parse(stdout || '{}');
    out = parsed?.result?.payloads?.[0]?.text || parsed?.payloads?.[0]?.text || parsed?.result?.response || parsed?.result?.text || parsed?.response || parsed?.message || '';
  } catch {
    // Some local OpenClaw invocations may emit plain text instead of JSON.
    out = String(stdout || '').trim();
  }

  if (!out || out === 'completed') throw new Error('empty_agent_response');

  if (allowRetry && isMetaAck(out)) {
    // one retry with a direct framing to avoid instruction-echo responses
    return askOpenClaw(`Reply naturally to this user request only: ${text}`, mode, conversationId, contextType, false);
  }

  return out;
}

function migrateAndLoad() {
  const rawProfiles = readJson(files.profiles, []);
  const rawProfilesList = Array.isArray(rawProfiles) ? rawProfiles : [];
  const legacyHistory = readJson(files.history, {});

  let users = readJson(files.users, null);
  let history = readJson(files.history, null);

  if (!users || !Array.isArray(users)) users = [];
  if (!users.some(u => u.role === 'admin')) {
    users.unshift({
      id: 'u_admin',
      username: ADMIN_USERNAME,
      displayName: 'Owner',
      role: 'admin',
      active: true,
      passwordHash: scryptHash(ADMIN_PASSWORD),
      createdAt: nowIso(),
      mustResetPassword: ADMIN_PASSWORD === 'change-me-now',
      onboardingCompletedAt: nowIso()
    });
  }

  if (users.length === 1) {
    const existingIds = new Set(['kid', 'adult']);
    for (const p of rawProfilesList) {
      if (!p || !p.id) continue;
      if (existingIds.has(p.id)) continue;
      const username = String(p.id).toLowerCase().replace(/[^a-z0-9_-]/g, '');
      if (!username) continue;
      users.push({
        id: `u_${username}`,
        username,
        displayName: p.name || username,
        role: 'family_user',
        active: true,
        passwordHash: scryptHash(`family-${username}`),
        createdAt: nowIso(),
        mustResetPassword: true,
        onboardingCompletedAt: null
      });
    }
  }
  writeJson(files.users, users);

  if (!history || Array.isArray(history)) history = {};

  const isLegacy = Object.keys(history).some(k => Array.isArray(history[k]));
  if (isLegacy || Object.keys(history).length === 0) {
    const migrated = {};
    const mapUsernameToId = Object.fromEntries(users.map(u => [u.username, u.id]));

    for (const [key, messages] of Object.entries(legacyHistory || {})) {
      if (!Array.isArray(messages)) continue;
      let userId = mapUsernameToId[key];
      if (!userId) {
        const fallback = users.find(u => u.role === 'admin');
        userId = fallback?.id || 'u_admin';
      }
      if (!migrated[userId]) migrated[userId] = {};
      migrated[userId].default = (migrated[userId].default || []).concat(messages);
    }

    history = migrated;
    writeJson(files.history, history);
  }

  const tasks = readJson(files.tasks, []);
  const activity = readJson(files.activity, []);

  if (!fs.existsSync(files.tasks)) writeJson(files.tasks, tasks);
  if (!fs.existsSync(files.activity)) writeJson(files.activity, activity);

  return { users, history, tasks, activity };
}

let { users, history, tasks, activity } = migrateAndLoad();
let builderConfigsByUser = readJson(files.builder, {});

let usersChanged = false;
for (const u of users) {
  if (u.role === 'admin' && !u.onboardingCompletedAt) { u.onboardingCompletedAt = nowIso(); usersChanged = true; }
  if (u.role !== 'admin' && !Object.prototype.hasOwnProperty.call(u, 'onboardingCompletedAt')) { u.onboardingCompletedAt = null; usersChanged = true; }
  if (!builderConfigsByUser[u.id]) builderConfigsByUser[u.id] = defaultBuilderConfig();
}
if (usersChanged) writeJson(files.users, users);
writeJson(files.builder, builderConfigsByUser);

function logActivity(type, actorId, details = {}) {
  activity.push({ id: randomId('act'), type, actorId, details, at: nowIso() });
  if (activity.length > 1000) activity = activity.slice(-1000);
  writeJson(files.activity, activity);
}

function getAuth(req) {
  const cookie = parseCookies(req);
  const token = cookie.fc_session || req.headers['x-session-token'];
  if (!token) return null;
  const session = sessions.get(token);
  if (!session) return null;
  const user = users.find(u => u.id === session.userId && u.active);
  if (!user) return null;
  return { token, session, user };
}

function requireAuth(req, res) {
  const auth = getAuth(req);
  if (!auth) {
    sendJson(res, 401, { error: 'auth_required' });
    return null;
  }
  return auth;
}

function requireAdmin(req, res) {
  const auth = requireAuth(req, res);
  if (!auth) return null;
  if (auth.user.role !== 'admin') {
    sendJson(res, 403, { error: 'admin_only' });
    return null;
  }
  return auth;
}

function ensureUserProfile(userId) {
  let profile = rawProfilesByUser[userId];
  if (!profile) {
    profile = {
      mode: 'adult',
      greeting: 'Hi! I’m Prism. I can help with plans, ideas, and quick answers.',
      preferences: { tone: 'friendly', topics: [] },
      updatedAt: nowIso()
    };
    rawProfilesByUser[userId] = profile;
    persistProfiles();
  }
  return profile;
}

const rawProfilesByUser = readJson(files.profiles, null) && !Array.isArray(readJson(files.profiles, null))
  ? readJson(files.profiles, {})
  : {};

if (Object.keys(rawProfilesByUser).length === 0) {
  // Bootstrap from users if profiles.json was legacy list.
  for (const u of users) {
    if (u.role === 'admin') continue;
    rawProfilesByUser[u.id] = {
      mode: 'adult',
      greeting: `Hi ${u.displayName}! I’m Prism.`,
      preferences: { tone: 'friendly', topics: [] },
      updatedAt: nowIso()
    };
  }
  writeJson(files.profiles, rawProfilesByUser);
}

function persistProfiles() { writeJson(files.profiles, rawProfilesByUser); }
function persistUsers() { writeJson(files.users, users); }
function persistHistory() { writeJson(files.history, history); }
function persistTasks() { writeJson(files.tasks, tasks); }
function persistBuilder() { writeJson(files.builder, builderConfigsByUser); }

function findUserByRef(userRef) {
  if (!userRef) return null;
  const ref = String(userRef).trim().toLowerCase();
  return users.find(u => u.id.toLowerCase() === ref || u.username.toLowerCase() === ref || u.displayName.toLowerCase() === ref) || null;
}

function runAdminOperatorIntent(intent, actorId) {
  const deny = (message) => ({ ok: false, error: message });
  switch (intent.action) {
    case 'list_users':
      return { ok: true, message: `Users (${users.length}):\n${users.map(u => `- ${u.username} (${u.role}, ${u.active ? 'active' : 'disabled'})`).join('\n')}` };
    case 'list_sessions': {
      const overview = users.map(u => {
        const sessionsById = history[u.id] || {};
        const names = Object.keys(sessionsById);
        const messages = names.reduce((acc, sid) => acc + (sessionsById[sid]?.length || 0), 0);
        return `- ${u.username}: ${names.length} session(s), ${messages} message(s)`;
      });
      return { ok: true, message: `Session overview:\n${overview.join('\n')}` };
    }
    case 'list_tasks':
      return { ok: true, message: tasks.length
        ? `Tasks:\n${tasks.map(t => `- ${t.id}: ${t.title} [${t.status}] role=${t.assigneeRole || 'none'}`).join('\n')}`
        : 'No tasks yet.' };
    case 'list_activity':
      return { ok: true, message: activity.length
        ? `Recent activity:\n${activity.slice(-20).reverse().map(a => `- ${a.type} by ${a.actorId} at ${a.at}`).join('\n')}`
        : 'No activity yet.' };
    case 'create_task': {
      const title = String(intent.title || '').trim().slice(0, 120);
      if (!title) return deny('Missing task title. Example: create task "Review onboarding copy" assign engineer.');
      const role = intent.assigneeRole && AGENT_ROLES.includes(intent.assigneeRole) ? intent.assigneeRole : null;
      const item = {
        id: randomId('task'),
        title,
        status: role ? 'assigned' : 'queued',
        assigneeRole: role,
        assigneeUserId: null,
        createdAt: nowIso(),
        updatedAt: nowIso(),
        ownerId: actorId
      };
      tasks.push(item); persistTasks();
      logActivity('task_create', actorId, { taskId: item.id, role: item.assigneeRole, status: item.status, via: 'admin_operator' });
      return { ok: true, message: `Created task ${item.id}: "${item.title}" (${item.status}, role=${item.assigneeRole || 'none'}).` };
    }
    case 'update_task': {
      const id = String(intent.taskId || '').trim();
      if (!id) return deny('Missing task id. Example: update task task_abcd status running.');
      const t = tasks.find(x => x.id === id);
      if (!t) return deny(`Task not found: ${id}`);
      if (intent.status && WORKFLOW_STATUSES.includes(intent.status)) t.status = intent.status;
      if (intent.assigneeRole) {
        if (intent.assigneeRole === 'none' || intent.assigneeRole === 'unassigned') t.assigneeRole = null;
        else if (AGENT_ROLES.includes(intent.assigneeRole)) t.assigneeRole = intent.assigneeRole;
      }
      t.updatedAt = nowIso();
      persistTasks();
      logActivity('task_update', actorId, { taskId: t.id, status: t.status, role: t.assigneeRole || null, via: 'admin_operator' });
      return { ok: true, message: `Updated task ${t.id}: status=${t.status}, role=${t.assigneeRole || 'none'}.` };
    }
    case 'create_user': {
      const username = String(intent.username || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
      if (!username) return deny('Missing username. Example: create user username alex display name "Alex".');
      if (users.some(u => u.username === username)) return deny(`Username already exists: ${username}`);
      const displayName = String(intent.displayName || username).trim().slice(0, 50);
      const password = String(intent.password || `family-${username}`);
      const user = { id: randomId('u'), username, displayName, role: 'family_user', active: true, passwordHash: scryptHash(password), createdAt: nowIso(), mustResetPassword: true, onboardingCompletedAt: null };
      users.push(user);
      builderConfigsByUser[user.id] = defaultBuilderConfig();
      rawProfilesByUser[user.id] = { mode: 'adult', greeting: `Hi ${displayName}! I’m Prism.`, preferences: { tone: 'friendly', topics: [] }, updatedAt: nowIso() };
      persistUsers(); persistProfiles(); persistBuilder();
      logActivity('user_create', actorId, { target: user.id, via: 'admin_operator' });
      return { ok: true, message: `Created user ${username} (${displayName}). Temporary password: ${password}` };
    }
    case 'password_reset_disabled': {
      return deny('For safety, password reset is manual-only via admin endpoint. Use /api/admin/users/:id/reset-password with confirm="RESET".');
    }
    case 'set_user_active': {
      const user = findUserByRef(intent.userRef);
      if (!user || user.role !== 'family_user') return deny('User not found or not a family_user.');
      user.active = !!intent.active;
      persistUsers();
      logActivity('user_update', actorId, { target: user.id, active: user.active, via: 'admin_operator' });
      return { ok: true, message: `${user.username} is now ${user.active ? 'enabled' : 'disabled'}.` };
    }
    case 'update_user_display_name': {
      const user = findUserByRef(intent.userRef);
      if (!user || user.role !== 'family_user') return deny('User not found or not a family_user.');
      const displayName = String(intent.displayName || '').trim().slice(0, 50);
      if (!displayName) return deny('Missing display name.');
      user.displayName = displayName;
      persistUsers();
      logActivity('user_update', actorId, { target: user.id, displayName, via: 'admin_operator' });
      return { ok: true, message: `Updated ${user.username} display name to "${displayName}".` };
    }
    case 'update_user_profile': {
      const user = findUserByRef(intent.userRef);
      if (!user || user.role !== 'family_user') return deny('User not found or not a family_user.');
      const profile = ensureUserProfile(user.id);
      if (intent.mode) profile.mode = intent.mode === 'kid' ? 'kid' : 'adult';
      if (intent.greeting) profile.greeting = String(intent.greeting).slice(0, 200);
      if (Array.isArray(intent.topics)) profile.preferences.topics = intent.topics.map(t => String(t).slice(0, 32)).slice(0, 20);
      profile.updatedAt = nowIso();
      persistProfiles();
      logActivity('profile_update', actorId, { target: user.id, via: 'admin_operator' });
      return { ok: true, message: `Updated profile for ${user.username} (mode=${profile.mode}).` };
    }
    case 'unsupported':
    default:
      return deny('Unsupported admin operator command. Allowed: list users/sessions/tasks/activity, create/update tasks, create users, enable/disable user, update display name, update profile mode/greeting/topics.');
  }
}

function runFamilyBuilderIntent(intent, actor) {
  if (intent.action === 'blocked_scope') {
    return { ok: false, error: 'Personal customization mode only: you can change your own layout/widgets/help/profile tone/greeting/topics.' };
  }
  if (intent.action !== 'update_self_builder_or_profile') return null;

  const currentBuilder = builderConfigsByUser[actor.id] || defaultBuilderConfig();
  const nextBuilder = sanitizeBuilderConfig(currentBuilder, currentBuilder);
  if (intent.builder.layoutPreset) nextBuilder.layoutPreset = intent.builder.layoutPreset;
  if (typeof intent.builder.helpPinned === 'boolean') nextBuilder.helpPinned = intent.builder.helpPinned;
  if (intent.builder.widgetPatch) {
    const set = new Set(nextBuilder.widgets || []);
    for (const w of intent.builder.widgetPatch.widgets) {
      if (!BUILDER_WIDGETS.includes(w)) continue;
      if (intent.builder.widgetPatch.mode === 'remove') set.delete(w);
      else set.add(w);
    }
    nextBuilder.widgets = Array.from(set).slice(0, 6);
    if (!nextBuilder.widgets.length) nextBuilder.widgets = ['daily_prompt', 'chat_tips'];
  }
  nextBuilder.updatedAt = nowIso();
  builderConfigsByUser[actor.id] = sanitizeBuilderConfig(nextBuilder, nextBuilder);
  persistBuilder();

  const profile = ensureUserProfile(actor.id);
  if (intent.profile.mode) profile.mode = intent.profile.mode;
  if (intent.profile.tone) profile.preferences.tone = intent.profile.tone;
  if (intent.profile.greeting) profile.greeting = intent.profile.greeting;
  if (Array.isArray(intent.profile.topics)) profile.preferences.topics = intent.profile.topics;
  profile.updatedAt = nowIso();
  persistProfiles();

  logActivity('builder_update', actor.id, { target: actor.id, via: 'family_customization_mode' });
  return {
    ok: true,
    message: `Updated your personal customization. Layout=${builderConfigsByUser[actor.id].layoutPreset}, widgets=${builderConfigsByUser[actor.id].widgets.join(', ')}, helpPinned=${builderConfigsByUser[actor.id].helpPinned}, mode=${profile.mode}.`
  };
}

function serveStatic(req, res) {
  const pathname = req.url.split('?')[0];
  const requested = pathname === '/' || pathname === '/admin' ? '/index.html' : pathname;
  const root = path.join(__dirname, 'web');
  const file = path.join(root, requested);
  if (!file.startsWith(root)) return sendJson(res, 403, { error: 'forbidden' });
  fs.readFile(file, (err, data) => {
    if (err) return sendJson(res, 404, { error: 'not_found' });
    res.writeHead(200, { 'Content-Type': `${contentTypeFor(file)}; charset=utf-8` });
    res.end(data);
  });
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'POST' && req.url === '/api/auth/login') {
      const body = await parseBody(req);
      const username = String(body.username || '').trim().toLowerCase();
      const password = String(body.password || '');
      const user = users.find(u => String(u.username || '').toLowerCase() === username && u.active);
      if (!user || !verifyPassword(password, user.passwordHash)) {
        return sendJson(res, 401, { error: 'invalid_credentials' });
      }
      const token = randomId('sess');
      sessions.set(token, { userId: user.id, createdAt: nowIso() });
      user.lastLoginAt = nowIso();
      persistUsers();
      logActivity('login', user.id, {});
      return sendJson(res, 200, { ok: true, user: sanitizeUser(user), mustResetPassword: !!user.mustResetPassword, sessionToken: token }, {
        'Set-Cookie': `fc_session=${encodeURIComponent(token)}; HttpOnly; SameSite=Lax; Path=/`
      });
    }

    if (req.method === 'POST' && req.url === '/api/auth/signup') {
      if (!SIGNUP_ENABLED) return sendJson(res, 403, { error: 'signup_disabled' });
      const body = await parseBody(req);
      const username = String(body.username || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
      const displayName = String(body.displayName || username).trim().slice(0, 50);
      const password = String(body.password || '');
      const inviteCode = String(body.inviteCode || '');

      if (!username) return sendJson(res, 400, { error: 'username_required' });
      if (username.length < 3) return sendJson(res, 400, { error: 'username_too_short' });
      if (password.length < 8) return sendJson(res, 400, { error: 'password_too_short' });
      if (users.some(u => String(u.username || '').toLowerCase() === username)) {
        return sendJson(res, 409, { error: 'username_taken' });
      }
      if (SIGNUP_INVITE_CODE && inviteCode !== SIGNUP_INVITE_CODE) {
        return sendJson(res, 403, { error: 'invalid_invite_code' });
      }

      const user = {
        id: randomId('u'),
        username,
        displayName,
        role: 'family_user',
        active: true,
        passwordHash: scryptHash(password),
        createdAt: nowIso(),
        mustResetPassword: false,
        onboardingCompletedAt: null
      };
      users.push(user);
      ensureUserProfile(user.id);
      if (!builderConfigsByUser[user.id]) builderConfigsByUser[user.id] = defaultBuilderConfig();
      persistUsers();
      persistProfiles();
      persistBuilder();
      logActivity('signup', user.id, {});

      const token = randomId('sess');
      sessions.set(token, { userId: user.id, createdAt: nowIso() });
      return sendJson(res, 200, { ok: true, user: sanitizeUser(user), mustResetPassword: false, sessionToken: token }, {
        'Set-Cookie': `fc_session=${encodeURIComponent(token)}; HttpOnly; SameSite=Lax; Path=/`
      });
    }

    if (req.method === 'POST' && req.url === '/api/auth/logout') {
      const auth = getAuth(req);
      if (auth) sessions.delete(auth.token);
      return sendJson(res, 200, { ok: true }, { 'Set-Cookie': 'fc_session=; Max-Age=0; Path=/; SameSite=Lax' });
    }

    if (req.method === 'GET' && req.url === '/api/auth/me') {
      const auth = getAuth(req);
      if (!auth) return sendJson(res, 200, { user: null });
      return sendJson(res, 200, { user: sanitizeUser(auth.user), mustResetPassword: !!auth.user.mustResetPassword });
    }

    if (req.method === 'POST' && req.url === '/api/auth/change-password') {
      const auth = requireAuth(req, res); if (!auth) return;
      const body = await parseBody(req);
      const currentPassword = String(body.currentPassword || '');
      const newPassword = String(body.newPassword || '');
      if (newPassword.length < 8) return sendJson(res, 400, { error: 'password_too_short' });
      if (!verifyPassword(currentPassword, auth.user.passwordHash)) {
        return sendJson(res, 401, { error: 'invalid_current_password' });
      }
      auth.user.passwordHash = scryptHash(newPassword);
      auth.user.mustResetPassword = false;
      persistUsers();
      logActivity('password_change', auth.user.id, {});
      return sendJson(res, 200, { ok: true });
    }

    if (req.method === 'GET' && req.url === '/api/profiles/me') {
      const auth = requireAuth(req, res); if (!auth) return;
      const profile = ensureUserProfile(auth.user.id);
      return sendJson(res, 200, { profile });
    }

    if (req.method === 'PUT' && req.url === '/api/profiles/me') {
      const auth = requireAuth(req, res); if (!auth) return;
      const body = await parseBody(req);
      const current = ensureUserProfile(auth.user.id);
      current.greeting = String(body.greeting || current.greeting).slice(0, 200);
      current.mode = body.mode === 'kid' ? 'kid' : 'adult';
      const topics = Array.isArray(body?.preferences?.topics) ? body.preferences.topics.map(t => String(t).slice(0, 32)).slice(0, 20) : current.preferences.topics;
      const tone = String(body?.preferences?.tone || current.preferences.tone || 'friendly').slice(0, 32);
      current.preferences = { tone, topics };
      current.updatedAt = nowIso();
      persistProfiles();
      logActivity('profile_update', auth.user.id, { target: auth.user.id });
      return sendJson(res, 200, { ok: true, profile: current });
    }

    if (req.method === 'GET' && req.url === '/api/builder/me') {
      const auth = requireAuth(req, res); if (!auth) return;
      if (auth.user.role !== 'family_user') return sendJson(res, 403, { error: 'family_user_only' });
      const current = builderConfigsByUser[auth.user.id] || defaultBuilderConfig();
      builderConfigsByUser[auth.user.id] = sanitizeBuilderConfig(current, current);
      persistBuilder();
      return sendJson(res, 200, {
        config: builderConfigsByUser[auth.user.id],
        schema: { widgets: BUILDER_WIDGETS, layoutPresets: BUILDER_LAYOUTS },
        onboardingRequired: !auth.user.onboardingCompletedAt
      });
    }

    if (req.method === 'PUT' && req.url === '/api/builder/me') {
      const auth = requireAuth(req, res); if (!auth) return;
      if (auth.user.role !== 'family_user') return sendJson(res, 403, { error: 'family_user_only' });
      const body = await parseBody(req);
      const current = builderConfigsByUser[auth.user.id] || defaultBuilderConfig();
      const next = sanitizeBuilderConfig(body, current);
      builderConfigsByUser[auth.user.id] = next;
      persistBuilder();
      logActivity('builder_update', auth.user.id, { target: auth.user.id });
      return sendJson(res, 200, { ok: true, config: next });
    }

    if (req.method === 'POST' && req.url === '/api/onboarding/complete') {
      const auth = requireAuth(req, res); if (!auth) return;
      if (auth.user.role !== 'family_user') return sendJson(res, 403, { error: 'family_user_only' });
      auth.user.onboardingCompletedAt = nowIso();
      persistUsers();
      logActivity('onboarding_complete', auth.user.id, { target: auth.user.id });
      return sendJson(res, 200, { ok: true, onboardingCompletedAt: auth.user.onboardingCompletedAt });
    }

    if (req.method === 'GET' && req.url.startsWith('/api/history/me')) {
      const auth = requireAuth(req, res); if (!auth) return;
      const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
      const sessionId = String(url.searchParams.get('session') || 'default');
      const scoped = history[auth.user.id] || {};
      return sendJson(res, 200, { history: scoped[sessionId] || [], sessionId });
    }

    if (req.method === 'POST' && req.url === '/api/chat') {
      const auth = requireAuth(req, res); if (!auth) return;
      const body = await parseBody(req);
      const text = String(body.text || '').trim().slice(0, MAX_INPUT);
      const sessionId = String(body.sessionId || 'default').slice(0, 64);
      if (!text) return sendJson(res, 400, { error: 'text_required' });

      if (!history[auth.user.id]) history[auth.user.id] = {};
      if (!history[auth.user.id][sessionId]) history[auth.user.id][sessionId] = [];

      const profile = ensureUserProfile(auth.user.id);
      history[auth.user.id][sessionId].push({ role: 'you', text, at: nowIso() });
      if (history[auth.user.id][sessionId].length > 120) history[auth.user.id][sessionId] = history[auth.user.id][sessionId].slice(-120);
      persistHistory();

      if (profile.mode === 'kid') {
        const blocked = safeKidResponse(text);
        if (blocked) {
          history[auth.user.id][sessionId].push({ role: 'bot', text: blocked, at: nowIso() });
          persistHistory();
          return sendJson(res, 200, { reply: blocked, safeMode: true, sessionId });
        }
      }

      let reply;
      const familyIntent = parseFamilyBuilderIntent(text);
      const familyHandled = runFamilyBuilderIntent(familyIntent, auth.user);
      if (familyHandled) {
        reply = familyHandled.ok
          ? `${familyHandled.message} (Personal customization mode: scoped to your account only.)`
          : `Denied: ${familyHandled.error}`;
      } else {
        try {
          const convId = `family-${auth.user.id}-${sessionId}`;
          reply = await askOpenClaw(text, profile.mode, convId, 'family_chat');
        } catch (err) {
          const fallback = profile.mode === 'kid' ? localKidReply(text) : localAdultReply(text);
          const why = String(err?.message || 'unknown').slice(0, 180);
          reply = `[Connection note: live PrismBot link failed (${why}), using local fallback] ${fallback}`;
          logActivity('chat_fallback', auth.user.id, { sessionId, error: String(err?.stack || err?.message || 'unknown') });
        }
      }

      history[auth.user.id][sessionId].push({ role: 'bot', text: reply, at: nowIso() });
      if (history[auth.user.id][sessionId].length > 120) history[auth.user.id][sessionId] = history[auth.user.id][sessionId].slice(-120);
      persistHistory();
      logActivity('chat', auth.user.id, { sessionId });
      return sendJson(res, 200, { reply, sessionId });
    }

    if (req.method === 'GET' && req.url === '/api/admin/users') {
      const auth = requireAdmin(req, res); if (!auth) return;
      return sendJson(res, 200, { users: users.map(sanitizeUser) });
    }

    if (req.method === 'POST' && req.url === '/api/admin/users') {
      const auth = requireAdmin(req, res); if (!auth) return;
      const body = await parseBody(req);
      const username = String(body.username || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
      const displayName = String(body.displayName || username).trim().slice(0, 50);
      const password = String(body.password || `family-${username}`);
      if (!username) return sendJson(res, 400, { error: 'username_required' });
      if (users.some(u => u.username === username)) return sendJson(res, 409, { error: 'username_taken' });
      const user = {
        id: randomId('u'),
        username,
        displayName,
        role: 'family_user',
        active: true,
        passwordHash: scryptHash(password),
        createdAt: nowIso(),
        mustResetPassword: true,
        onboardingCompletedAt: null
      };
      users.push(user);
      builderConfigsByUser[user.id] = defaultBuilderConfig();
      rawProfilesByUser[user.id] = {
        mode: 'adult',
        greeting: `Hi ${displayName}! I’m Prism.`,
        preferences: { tone: 'friendly', topics: [] },
        updatedAt: nowIso()
      };
      persistUsers(); persistProfiles(); persistBuilder();
      logActivity('user_create', auth.user.id, { target: user.id });
      return sendJson(res, 200, { ok: true, user: sanitizeUser(user), tempPassword: password });
    }

    if (req.method === 'POST' && req.url.startsWith('/api/admin/users/') && req.url.endsWith('/reset-password')) {
      const auth = requireAdmin(req, res); if (!auth) return;
      const id = req.url.split('/')[4];
      const body = await parseBody(req);
      const user = users.find(u => u.id === id && u.role === 'family_user');
      if (!user) return sendJson(res, 404, { error: 'user_not_found' });
      if (String(body.confirm || '').toUpperCase() !== 'RESET') {
        return sendJson(res, 400, { error: 'confirm_required', message: 'Set confirm="RESET" to perform manual password reset.' });
      }
      const password = String(body.password || `family-${user.username}-${Date.now().toString().slice(-4)}`);
      if (password.length < 8) return sendJson(res, 400, { error: 'password_too_short' });
      user.passwordHash = scryptHash(password);
      user.mustResetPassword = true;
      persistUsers();
      logActivity('user_reset_password_manual', auth.user.id, { target: user.id });
      return sendJson(res, 200, { ok: true, tempPassword: password, user: sanitizeUser(user) });
    }

    if (req.method === 'PATCH' && req.url.startsWith('/api/admin/users/')) {
      const auth = requireAdmin(req, res); if (!auth) return;
      const id = req.url.split('/')[4];
      const body = await parseBody(req);
      const user = users.find(u => u.id === id);
      if (!user) return sendJson(res, 404, { error: 'user_not_found' });
      if (user.role === 'admin' && 'role' in body) return sendJson(res, 400, { error: 'admin_role_locked' });
      if (typeof body.active === 'boolean') user.active = body.active;
      if (body.displayName) user.displayName = String(body.displayName).slice(0, 50);
      persistUsers();
      logActivity('user_update', auth.user.id, { target: user.id });
      return sendJson(res, 200, { ok: true, user: sanitizeUser(user) });
    }

    if (req.method === 'GET' && req.url === '/api/admin/sessions') {
      const auth = requireAdmin(req, res); if (!auth) return;
      const overview = users.map(u => {
        const sessionsById = history[u.id] || {};
        const names = Object.keys(sessionsById);
        const messages = names.reduce((acc, sid) => acc + (sessionsById[sid]?.length || 0), 0);
        return { userId: u.id, username: u.username, sessions: names.length, messages };
      });
      return sendJson(res, 200, { overview });
    }

    if (req.method === 'GET' && req.url.startsWith('/api/admin/activity')) {
      const auth = requireAdmin(req, res); if (!auth) return;
      const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
      const limit = Math.min(200, Number(url.searchParams.get('limit') || 50));
      return sendJson(res, 200, { activity: activity.slice(-limit).reverse() });
    }

    if (req.method === 'GET' && req.url === '/api/admin/tasks') {
      const auth = requireAdmin(req, res); if (!auth) return;
      const normalized = tasks.map(t => ({
        ...t,
        status: WORKFLOW_STATUSES.includes(t.status) ? t.status : 'queued',
        assigneeRole: AGENT_ROLES.includes(t.assigneeRole) ? t.assigneeRole : null,
        assigneeUserId: t.assigneeUserId || null
      }));
      return sendJson(res, 200, { tasks: normalized, workflow: { statuses: WORKFLOW_STATUSES, roles: AGENT_ROLES } });
    }

    if (req.method === 'POST' && req.url === '/api/admin/tasks') {
      const auth = requireAdmin(req, res); if (!auth) return;
      const body = await parseBody(req);
      const assigneeRole = AGENT_ROLES.includes(String(body.assigneeRole || '').toLowerCase())
        ? String(body.assigneeRole).toLowerCase()
        : null;
      const item = {
        id: randomId('task'),
        title: String(body.title || '').slice(0, 120),
        status: assigneeRole ? 'assigned' : 'queued',
        assigneeRole,
        assigneeUserId: body.assigneeUserId ? String(body.assigneeUserId) : null,
        createdAt: nowIso(),
        updatedAt: nowIso(),
        ownerId: auth.user.id
      };
      if (!item.title) return sendJson(res, 400, { error: 'title_required' });
      tasks.push(item); persistTasks();
      logActivity('task_create', auth.user.id, { taskId: item.id, role: item.assigneeRole, status: item.status });
      return sendJson(res, 200, { ok: true, task: item });
    }

    if (req.method === 'PATCH' && req.url.startsWith('/api/admin/tasks/')) {
      const auth = requireAdmin(req, res); if (!auth) return;
      const id = req.url.split('/')[4];
      const body = await parseBody(req);
      const t = tasks.find(x => x.id === id);
      if (!t) return sendJson(res, 404, { error: 'task_not_found' });

      const requestedRole = typeof body.assigneeRole === 'string' ? body.assigneeRole.toLowerCase() : null;
      if (requestedRole !== null) {
        if (requestedRole === '' || requestedRole === 'none') {
          t.assigneeRole = null;
          t.assigneeUserId = null;
          if (t.status !== 'done') t.status = 'queued';
        } else if (AGENT_ROLES.includes(requestedRole)) {
          t.assigneeRole = requestedRole;
          t.assigneeUserId = body.assigneeUserId ? String(body.assigneeUserId) : (t.assigneeUserId || null);
          if (t.status === 'queued') t.status = 'assigned';
        }
      }

      if (typeof body.assigneeUserId === 'string') {
        t.assigneeUserId = body.assigneeUserId || null;
      }

      if (WORKFLOW_STATUSES.includes(body.status)) t.status = body.status;
      t.updatedAt = nowIso();
      persistTasks();
      logActivity('task_update', auth.user.id, {
        taskId: t.id,
        status: t.status,
        role: t.assigneeRole || null,
        assigneeUserId: t.assigneeUserId || null,
        action: body.action || null
      });
      return sendJson(res, 200, { ok: true, task: t });
    }

    if (req.method === 'POST' && req.url === '/api/admin/live-chat') {
      const auth = requireAdmin(req, res); if (!auth) return;
      const body = await parseBody(req);
      const text = String(body.text || '').trim().slice(0, MAX_INPUT);
      const threadId = String(body.threadId || 'default').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 48) || 'default';
      if (!text) return sendJson(res, 400, { error: 'text_required' });
      const status = { startedAt: nowIso(), state: 'running', mode: 'admin_operator' };

      const intent = parseAdminOperatorIntent(text);
      const op = runAdminOperatorIntent(intent, auth.user.id);
      if (op && op.ok) {
        status.state = 'executed';
        status.completedAt = nowIso();
        logActivity('admin_live_chat', auth.user.id, { ok: true, threadId, mode: 'operator_execute', action: intent.action });
        return sendJson(res, 200, { status, reply: op.message, threadId, intent });
      }
      if (op && !op.ok && intent.action !== 'unsupported') {
        status.state = 'denied';
        status.completedAt = nowIso();
        logActivity('admin_live_chat', auth.user.id, { ok: false, threadId, mode: 'operator_denied', action: intent.action });
        return sendJson(res, 200, { status, reply: `Operator mode error: ${op.error}`, threadId, intent });
      }

      try {
        const reply = await askOpenClaw(text, 'adult', `family-admin-live-${auth.user.id}-${threadId}`, 'admin_live_chat');
        status.state = 'done';
        status.completedAt = nowIso();
        logActivity('admin_live_chat', auth.user.id, { ok: true, threadId, mode: 'assistant_fallback' });
        return sendJson(res, 200, { status, reply, threadId });
      } catch {
        status.state = 'fallback';
        status.completedAt = nowIso();
        const reply = localAdultReply(text);
        logActivity('admin_live_chat', auth.user.id, { ok: false, fallback: true, threadId, mode: 'local_fallback' });
        return sendJson(res, 200, { status, reply, fallback: true, threadId });
      }
    }

    if (req.method === 'GET' && req.url.split('?')[0] === '/admin') {
      const auth = requireAdmin(req, res); if (!auth) return;
      return serveStatic(req, res);
    }

    if (req.method === 'GET') return serveStatic(req, res);

    return sendJson(res, 404, { error: 'not_found' });
  } catch (err) {
    return sendJson(res, 500, { error: 'server_error', detail: err.message });
  }
});

server.listen(PORT, HOST, () => {
  console.log(`family-chat unified app listening on http://${HOST}:${PORT}`);
});
