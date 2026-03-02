#!/usr/bin/env node
const http = require('node:http');
const { execFile } = require('node:child_process');

const PORT = Number(process.env.PORT || 8787);
const HOST = process.env.HOST || '127.0.0.1';
const BRIDGE_TOKEN = process.env.BRIDGE_TOKEN || '';
const AGENT_ID = process.env.BRIDGE_AGENT_ID || 'main';
const TIMEOUT_MS = Number(process.env.BRIDGE_TIMEOUT_MS || 45000);
const MAX_MSG = Number(process.env.BRIDGE_MAX_MESSAGE || 2000);

if (!BRIDGE_TOKEN) {
  console.error('BRIDGE_TOKEN is required. Refusing to start.');
  process.exit(1);
}

function sendJson(res, status, body) {
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  res.end(JSON.stringify(body));
}

function parseBody(req) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', (chunk) => {
      data += chunk.toString();
      if (data.length > 1_000_000) {
        reject(new Error('payload_too_large'));
        req.destroy();
      }
    });
    req.on('end', () => {
      try {
        resolve(JSON.parse(data || '{}'));
      } catch {
        reject(new Error('invalid_json'));
      }
    });
    req.on('error', reject);
  });
}

function runAgent({ message, sessionKey }) {
  return new Promise((resolve, reject) => {
    const args = [
      'agent',
      '--agent', AGENT_ID,
      '--session-id', sessionKey,
      '--message', message,
      '--json',
      '--thinking', 'low',
      '--timeout', String(Math.ceil(TIMEOUT_MS / 1000)),
    ];

    execFile('openclaw', args, { timeout: TIMEOUT_MS, maxBuffer: 5 * 1024 * 1024 }, (err, stdout, stderr) => {
      if (err) {
        return reject(new Error(stderr?.trim() || err.message || 'agent_exec_failed'));
      }

      let parsed;
      try {
        parsed = JSON.parse(stdout || '{}');
      } catch {
        return reject(new Error('agent_invalid_json'));
      }

      const reply =
        parsed?.result?.payloads?.[0]?.text ||
        parsed?.result?.response ||
        parsed?.response ||
        parsed?.message ||
        parsed?.text;
      if (!reply || typeof reply !== 'string') {
        return reject(new Error('agent_no_reply'));
      }
      resolve(reply.trim());
    });
  });
}

function authOk(req) {
  const auth = String(req.headers.authorization || '');
  return auth === `Bearer ${BRIDGE_TOKEN}`;
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/api/mobile/health') {
    return sendJson(res, 200, { ok: true, service: 'openclaw-bridge' });
  }

  if (req.method === 'POST' && req.url === '/api/mobile/chat') {
    if (!authOk(req)) return sendJson(res, 401, { error: 'unauthorized' });

    try {
      const body = await parseBody(req);
      const message = String(body.message || '').trim().slice(0, MAX_MSG);
      const sessionKey = String(body.sessionKey || '').trim() || 'agent:main:mobile:default';

      if (!message) return sendJson(res, 400, { error: 'message_required' });

      const reply = await runAgent({ message, sessionKey });
      return sendJson(res, 200, { reply });
    } catch (err) {
      return sendJson(res, 500, {
        error: 'bridge_error',
        message: err.message || 'Unknown bridge error',
      });
    }
  }

  return sendJson(res, 404, { error: 'not_found' });
});

server.listen(PORT, HOST, () => {
  console.log(`openclaw bridge listening on http://${HOST}:${PORT}`);
});
