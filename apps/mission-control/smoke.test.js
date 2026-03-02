const { spawn } = require('child_process');
const assert = require('assert');

const PORT = 8791;
const BASE = `http://127.0.0.1:${PORT}`;

async function waitForHealth(timeoutMs = 10000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    try {
      const res = await fetch(`${BASE}/health`);
      if (res.ok) return;
    } catch {}
    await new Promise(r => setTimeout(r, 200));
  }
  throw new Error('Server failed to start');
}

async function runStandardChecks() {
  const html = await fetch(`${BASE}/`).then(r => r.text());
  assert(html.includes('OpenClaw Mission Control'));

  const projects = await fetch(`${BASE}/api/projects`).then(r => r.json());
  assert(projects.length >= 2);

  const created = await fetch(`${BASE}/api/tasks`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ projectId: 'core', title: 'Smoke Task', owner: 'QA', priority: 'low' })
  }).then(r => r.json());

  assert(created.id && created.title === 'Smoke Task');

  const updated = await fetch(`${BASE}/api/tasks/${created.id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ status: 'done', title: 'Smoke Task Updated', owner: 'Sage', priority: 'high' })
  }).then(r => r.json());

  assert(updated.status === 'done');
  assert(updated.title === 'Smoke Task Updated');

  const coreTasks = await fetch(`${BASE}/api/tasks?project=core`).then(r => r.json());
  const apolloTasks = await fetch(`${BASE}/api/tasks?project=apollo`).then(r => r.json());
  assert(coreTasks.every(t => t.projectId === 'core'));
  assert(apolloTasks.every(t => t.projectId === 'apollo'));

  const coreRoster = await fetch(`${BASE}/api/roster?project=core`).then(r => r.json());
  const apolloRoster = await fetch(`${BASE}/api/roster?project=apollo`).then(r => r.json());
  assert(coreRoster.every(a => a.projectId === 'core'));
  assert(apolloRoster.every(a => a.projectId === 'apollo'));

  const sessions = await fetch(`${BASE}/api/sessions?active=1&limit=20&page=1&sortBy=updatedAt&sortDir=desc`).then(r => r.json());
  assert(Array.isArray(sessions.items));
  assert(Number.isFinite(sessions.total));

  const memoryFiles = await fetch(`${BASE}/api/memory/files?limit=20&page=1&sortBy=updatedAt&sortDir=desc`).then(r => r.json());
  assert(Array.isArray(memoryFiles.items));
  if (memoryFiles.items[0]) {
    const memRead = await fetch(`${BASE}/api/memory/read?file=${encodeURIComponent(memoryFiles.items[0].file)}&q=OpenClaw&lineStart=1&lineLimit=300`).then(r => r.json());
    assert(memRead.file === memoryFiles.items[0].file);
    assert(typeof memRead.content === 'string');
    assert(Array.isArray(memRead.hits));
  }

  const schedules = await fetch(`${BASE}/api/schedules`).then(r => r.json());
  assert(Array.isArray(schedules.jobs));
  assert(Array.isArray(schedules.recentRuns));

  const familyUsers = await fetch(`${BASE}/api/family/users`).then(r => r.json());
  assert(Array.isArray(familyUsers));

  const newFamilyUser = await fetch(`${BASE}/api/family/users`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ displayName: 'Smoke Family', username: 'smoke-family', role: 'member' })
  }).then(r => r.json());
  assert(newFamilyUser.id);

  const disableFamily = await fetch(`${BASE}/api/family/users/${newFamilyUser.id}/disable`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({})
  }).then(r => r.json());
  assert(disableFamily.ok === true);

  const resetFamily = await fetch(`${BASE}/api/family/users/${newFamilyUser.id}/reset-password`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({})
  }).then(r => r.json());
  assert(resetFamily.ok === true);

  const familySessions = await fetch(`${BASE}/api/family/sessions`).then(r => r.json());
  assert(Array.isArray(familySessions.users));

  const familyChat = await fetch(`${BASE}/api/family/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message: 'Smoke chat ping' })
  }).then(r => r.json());
  assert(familyChat.status === 'queued');

  const familyChatList = await fetch(`${BASE}/api/family/chat`).then(r => r.json());
  assert(Array.isArray(familyChatList.queue));

  const search = await fetch(`${BASE}/api/search?q=Mission&project=core&limit=10`).then(r => r.json());
  assert(search && search.query === 'Mission');
  assert(Array.isArray(search.tasks));
  assert(Array.isArray(search.activity));
  assert(Array.isArray(search.sessions));
  assert(Array.isArray(search.memory));

  const memorySearch = await fetch(`${BASE}/api/search?q=Mission&type=memory&limit=5&page=1&sortBy=updatedAt&sortDir=desc`).then(r => r.json());
  assert(memorySearch.type === 'memory');
  assert(Array.isArray(memorySearch.items));

  const del = await fetch(`${BASE}/api/tasks/${created.id}`, { method: 'DELETE' });
  assert(del.ok);
}

async function runStrictReadAuthChecks() {
  const token = 'smoke-token';
  const strictPort = PORT + 1;
  const strictBase = `http://127.0.0.1:${strictPort}`;

  const server = spawn('node', ['server.js'], {
    env: {
      ...process.env,
      PORT: String(strictPort),
      MISSION_CONTROL_TOKEN: token,
      REQUIRE_TOKEN_FOR_READ: 'true'
    },
    stdio: 'inherit'
  });

  try {
    const start = Date.now();
    while (Date.now() - start < 10000) {
      try {
        const res = await fetch(`${strictBase}/health`);
        if (res.ok) break;
      } catch {}
      await new Promise(r => setTimeout(r, 200));
    }

    const unauthorized = await fetch(`${strictBase}/api/projects`);
    assert(unauthorized.status === 401);

    const authorized = await fetch(`${strictBase}/api/projects`, {
      headers: { Authorization: `Bearer ${token}` }
    });
    assert(authorized.ok);
  } finally {
    server.kill('SIGTERM');
  }
}

async function run() {
  const server = spawn('node', ['server.js'], {
    env: { ...process.env, PORT: String(PORT) },
    stdio: 'inherit'
  });

  try {
    await waitForHealth();
    await runStandardChecks();
  } finally {
    server.kill('SIGTERM');
  }

  await runStrictReadAuthChecks();
  console.log('Smoke tests passed');
}

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
