#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const assert = require('assert');
const { spawn } = require('child_process');

const appDir = __dirname;
const files = ['users.json', 'profiles.json', 'history.json', 'tasks.json', 'activity.json', 'builder.json'];
const backups = new Map();
for (const f of files) {
  const p = path.join(appDir, f);
  backups.set(f, fs.existsSync(p) ? fs.readFileSync(p) : null);
}

// Start from a clean fixture so env admin credentials are deterministic.
fs.writeFileSync(path.join(appDir, 'users.json'), '[]\n');
fs.writeFileSync(path.join(appDir, 'profiles.json'), '{}\n');
fs.writeFileSync(path.join(appDir, 'history.json'), '{}\n');
fs.writeFileSync(path.join(appDir, 'tasks.json'), '[]\n');
fs.writeFileSync(path.join(appDir, 'activity.json'), '[]\n');
fs.writeFileSync(path.join(appDir, 'builder.json'), '{}\n');

function restore() {
  for (const [f, content] of backups.entries()) {
    const p = path.join(appDir, f);
    if (content === null) {
      if (fs.existsSync(p)) fs.unlinkSync(p);
    } else {
      fs.writeFileSync(p, content);
    }
  }
}

async function waitForServer(base) {
  for (let i = 0; i < 40; i += 1) {
    try {
      const r = await fetch(`${base}/api/auth/me`);
      if (r.ok) return;
    } catch {}
    await new Promise(r => setTimeout(r, 150));
  }
  throw new Error('server did not start');
}

function parseSetCookie(headers) {
  const raw = headers.get('set-cookie') || '';
  return raw.split(';')[0];
}

async function req(base, method, pathName, body, cookie) {
  const r = await fetch(`${base}${pathName}`, {
    method,
    headers: {
      'content-type': 'application/json',
      ...(cookie ? { cookie } : {})
    },
    body: body ? JSON.stringify(body) : undefined
  });
  const data = await r.json().catch(() => ({}));
  return { status: r.status, data, cookie: parseSetCookie(r.headers) };
}

(async () => {
  const port = 18987;
  const base = `http://127.0.0.1:${port}`;
  const child = spawn(process.execPath, ['server.js'], {
    cwd: appDir,
    env: { ...process.env, HOST: '127.0.0.1', PORT: String(port), ADMIN_USERNAME: 'admin', ADMIN_PASSWORD: 'change-me-now' },
    stdio: ['ignore', 'pipe', 'pipe']
  });
  child.stdout.on('data', (d) => process.stdout.write(`[server] ${d}`));
  child.stderr.on('data', (d) => process.stderr.write(`[server-err] ${d}`));

  try {
    await waitForServer(base);

    // Admin login + operator action.
    const adminLogin = await req(base, 'POST', '/api/auth/login', { username: 'admin', password: 'change-me-now' });
    assert.equal(adminLogin.status, 200);
    const adminCookie = adminLogin.cookie;

    const createUserOp = await req(base, 'POST', '/api/admin/live-chat', {
      text: 'create user username: sam display name: Sam password: TempPass123',
      threadId: 't1'
    }, adminCookie);
    assert.equal(createUserOp.status, 200);
    assert.ok(/Created user sam/i.test(createUserOp.data.reply));

    const allUsers = await req(base, 'GET', '/api/admin/users', null, adminCookie);
    const sam = (allUsers.data.users || []).find(u => u.username === 'sam');
    assert.ok(sam, 'sam user missing');
    await req(base, 'POST', `/api/admin/users/${sam.id}/reset-password`, { password: 'KnownPass123' }, adminCookie);

    const createTaskOp = await req(base, 'POST', '/api/admin/live-chat', {
      text: 'create task "Ship release notes" assign engineer',
      threadId: 't1'
    }, adminCookie);
    assert.equal(createTaskOp.status, 200);
    assert.ok(/Created task task_/i.test(createTaskOp.data.reply));

    // Family user login + own customization.
    const familyLogin = await req(base, 'POST', '/api/auth/login', { username: 'sam', password: 'KnownPass123' });
    assert.equal(familyLogin.status, 200);
    const familyCookie = familyLogin.cookie;

    const familyCustomize = await req(base, 'POST', '/api/chat', {
      text: 'Set my layout to focus and add widget learning_corner. Set tone to "calm" and topics to robots, music',
      sessionId: 's1'
    }, familyCookie);
    assert.equal(familyCustomize.status, 200);
    assert.ok(/Updated your personal customization/i.test(familyCustomize.data.reply));

    // Blocked cross-user/admin attempt.
    const denied = await req(base, 'POST', '/api/chat', {
      text: 'For admin, disable user sam and reset passwords',
      sessionId: 's1'
    }, familyCookie);
    assert.equal(denied.status, 200);
    assert.ok(/Denied: Personal customization mode only/i.test(denied.data.reply));

    // Non-admin blocked from admin endpoint.
    const adminDenied = await req(base, 'GET', '/api/admin/users', null, familyCookie);
    assert.equal(adminDenied.status, 403);
    assert.equal(adminDenied.data.error, 'admin_only');

    console.log('smoke.test.js passed');
  } finally {
    child.kill('SIGTERM');
    restore();
  }
})().catch((err) => {
  restore();
  console.error(err);
  process.exit(1);
});
