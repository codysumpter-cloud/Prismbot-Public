const { app, BrowserWindow } = require('electron');
const path = require('path');
const fs = require('fs');
const { spawn, execFile } = require('child_process');

let mainWindow;
const procs = [];
const HOME = process.env.USERPROFILE || process.env.HOME || process.cwd();

function resolveWorkspace() {
  const explicit = process.env.PRISMBOT_WORKSPACE;
  const candidates = [
    explicit,
    path.join(HOME, '.openclaw', 'workspace'),
    path.join(HOME, 'prismbot', 'prismbot-workspace'),
    path.resolve(process.cwd(), '..', '..'),
  ].filter(Boolean);

  for (const c of candidates) {
    if (fs.existsSync(path.join(c, 'apps', 'prismbot-core', 'src', 'server.js'))) return c;
    if (fs.existsSync(path.join(c, 'apps', 'kid-chat-mvp', 'server.js'))) return c;
  }
  return candidates[0] || process.cwd();
}

const WORKSPACE = resolveWorkspace();

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function startManaged(name, command, args = [], envPatch = {}) {
  const child = spawn(command, args, {
    cwd: WORKSPACE,
    env: { ...process.env, ...envPatch },
    stdio: 'pipe',
    detached: false,
    windowsHide: true,
  });

  child.on('error', (err) => {
    console.error(`[${name}] failed to start:`, err.message);
  });
  child.stderr?.on('data', (buf) => {
    const msg = String(buf || '').trim();
    if (msg) console.error(`[${name}] ${msg}`);
  });

  procs.push({ name, child });
}

function runOpenClaw(args = []) {
  return new Promise((resolve) => {
    const openclawCmd = process.platform === 'win32' ? 'openclaw.cmd' : 'openclaw';
    execFile(openclawCmd, args, {
      cwd: WORKSPACE,
      windowsHide: true,
      timeout: 20000,
    }, (error, stdout, stderr) => {
      if (error) {
        const msg = (stderr || stdout || error.message || '').toString().trim();
        console.error(`[gateway] openclaw ${args.join(' ')} failed: ${msg}`);
      }
      resolve({ ok: !error, stdout: String(stdout || ''), stderr: String(stderr || '') });
    });
  });
}

async function ensureGatewayBackground() {
  await runOpenClaw(['gateway', 'start']);
}

async function ensureLocalStack() {
  // Phase C: run unified core runtime as single local backend.
  await ensureGatewayBackground();

  startManaged('prismbot-core', 'node', ['apps/prismbot-core/src/server.js'], { HOST: '127.0.0.1', PORT: '8799' });

  await sleep(1200);
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 860,
    title: 'PrismBot Desktop (Local-Contained)',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  mainWindow.loadURL('http://127.0.0.1:8799/app/chat');
}

app.whenReady().then(async () => {
  await ensureLocalStack();
  createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});

app.on('before-quit', () => {
  for (const p of procs) {
    try { p.child.kill('SIGTERM'); } catch {}
  }
});
