#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT_DIR = path.join(ROOT, 'docs');
const OUT_JSON = path.join(OUT_DIR, 'omni-provider-verification-matrix.json');
const OUT_MD = path.join(OUT_DIR, 'omni-provider-verification-matrix.md');

const PROVIDERS = ['openai', 'anthropic', 'google', 'nanobanana2', 'xai', 'ollama'];

function boolEnv(v, fallback = true) {
  if (v == null || String(v).trim() === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(v).trim().toLowerCase());
}

function findServerPid() {
  const out = execSync("ps -eo pid,args | grep 'apps/prismbot-core/src/server.js' | grep -v grep | head -n1", { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  if (!out) throw new Error('prismbot_core_server_not_found');
  return String(out.split(/\s+/)[0]);
}

function readProcEnv(pid) {
  const raw = fs.readFileSync(`/proc/${pid}/environ`);
  const map = {};
  for (const pair of raw.toString('utf8').split('\u0000')) {
    if (!pair) continue;
    const i = pair.indexOf('=');
    if (i <= 0) continue;
    map[pair.slice(0, i)] = pair.slice(i + 1);
  }
  return map;
}

async function httpJson(url, init, timeoutMs = 12000) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await fetch(url, { ...(init || {}), signal: ctrl.signal });
    const text = await res.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch { payload = { raw: text }; }
    return { ok: res.ok, status: res.status, payload };
  } catch (err) {
    return { ok: false, status: 0, payload: { error: String(err?.message || err || 'request_failed') } };
  } finally {
    clearTimeout(timer);
  }
}

function providerCfg(env) {
  return {
    openai: {
      enabled: boolEnv(env.OMNI_OPENAI_ENABLED, true),
      credentialed: Boolean(String(env.OPENAI_API_KEY || '').trim()),
      model: String(env.OMNI_OPENAI_MODEL || 'gpt-4o-mini'),
    },
    anthropic: {
      enabled: boolEnv(env.OMNI_ANTHROPIC_ENABLED, true),
      credentialed: Boolean(String(env.ANTHROPIC_API_KEY || '').trim()),
      model: String(env.OMNI_ANTHROPIC_MODEL || 'claude-3-5-haiku-latest'),
    },
    google: {
      enabled: boolEnv(env.OMNI_GOOGLE_ENABLED, true),
      credentialed: Boolean(String(env.GOOGLE_API_KEY || env.GEMINI_API_KEY || '').trim()),
      model: String(env.OMNI_GOOGLE_MODEL || 'gemini-1.5-flash'),
    },
    xai: {
      enabled: boolEnv(env.OMNI_XAI_ENABLED, true),
      credentialed: Boolean(String(env.XAI_API_KEY || '').trim()),
      model: String(env.OMNI_XAI_MODEL || 'grok-2-latest'),
    },
    nanobanana2: {
      enabled: boolEnv(env.OMNI_NANOBANANA2_ENABLED, false),
      credentialed: Boolean(String(env.OMNI_NANOBANANA2_CHAT_URL || '').trim()),
      chatUrl: String(env.OMNI_NANOBANANA2_CHAT_URL || '').trim(),
      model: String(env.OMNI_NANOBANANA2_MODEL || 'nanobanana-2'),
      apiKey: String(env.OMNI_NANOBANANA2_API_KEY || env.NANOBANANA2_API_KEY || '').trim(),
    },
    ollama: {
      enabled: boolEnv(env.OMNI_OLLAMA_ENABLED, true),
      credentialed: Boolean(String(env.OMNI_OLLAMA_HOST || 'http://127.0.0.1:11434').trim()),
      host: String(env.OMNI_OLLAMA_HOST || 'http://127.0.0.1:11434').trim().replace(/\/$/, ''),
      model: String(env.OMNI_OLLAMA_MODEL || 'llama3.2:3b'),
    },
  };
}

async function liveCheck(provider, cfg, env) {
  if (!cfg.enabled) return { live_tested: false, pass_fail: 'skip', skip_reason: 'disabled' };
  if (!cfg.credentialed) return { live_tested: false, pass_fail: 'skip', skip_reason: 'missing_credentials' };

  if (provider === 'openai') {
    const key = String(env.OPENAI_API_KEY || '').trim();
    const r = await httpJson('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: { 'content-type': 'application/json', authorization: `Bearer ${key}` },
      body: JSON.stringify({ model: cfg.model, messages: [{ role: 'user', content: 'ping' }], max_tokens: 8 }),
    });
    return { live_tested: true, pass_fail: r.ok ? 'pass' : 'fail', skip_reason: r.ok ? '' : `http_${r.status}` };
  }

  if (provider === 'anthropic') {
    const key = String(env.ANTHROPIC_API_KEY || '').trim();
    const r = await httpJson('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-api-key': key, 'anthropic-version': '2023-06-01' },
      body: JSON.stringify({ model: cfg.model, max_tokens: 8, messages: [{ role: 'user', content: 'ping' }] }),
    });
    return { live_tested: true, pass_fail: r.ok ? 'pass' : 'fail', skip_reason: r.ok ? '' : `http_${r.status}` };
  }

  if (provider === 'google') {
    const key = String(env.GOOGLE_API_KEY || env.GEMINI_API_KEY || '').trim();
    const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(cfg.model)}:generateContent?key=${encodeURIComponent(key)}`;
    const r = await httpJson(url, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ contents: [{ role: 'user', parts: [{ text: 'ping' }] }] }),
    });
    return { live_tested: true, pass_fail: r.ok ? 'pass' : 'fail', skip_reason: r.ok ? '' : `http_${r.status}` };
  }

  if (provider === 'xai') {
    const key = String(env.XAI_API_KEY || '').trim();
    const r = await httpJson('https://api.x.ai/v1/chat/completions', {
      method: 'POST',
      headers: { 'content-type': 'application/json', authorization: `Bearer ${key}` },
      body: JSON.stringify({ model: cfg.model, messages: [{ role: 'user', content: 'ping' }], max_tokens: 8 }),
    });
    return { live_tested: true, pass_fail: r.ok ? 'pass' : 'fail', skip_reason: r.ok ? '' : `http_${r.status}` };
  }

  if (provider === 'nanobanana2') {
    const headers = { 'content-type': 'application/json' };
    if (cfg.apiKey) headers.authorization = `Bearer ${cfg.apiKey}`;
    const r = await httpJson(cfg.chatUrl, {
      method: 'POST',
      headers,
      body: JSON.stringify({ model: cfg.model, messages: [{ role: 'user', content: 'ping' }], max_tokens: 8, stream: false }),
    });
    return { live_tested: true, pass_fail: r.ok ? 'pass' : 'fail', skip_reason: r.ok ? '' : `http_${r.status}` };
  }

  if (provider === 'ollama') {
    const tags = await httpJson(`${cfg.host}/api/tags`, { method: 'GET' }, 6000);
    if (!tags.ok) return { live_tested: true, pass_fail: 'fail', skip_reason: `http_${tags.status}` };
    const r = await httpJson(`${cfg.host}/api/generate`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ model: cfg.model, prompt: 'ping', stream: false }),
    }, 12000);
    return { live_tested: true, pass_fail: r.ok ? 'pass' : 'fail', skip_reason: r.ok ? '' : `http_${r.status}` };
  }

  return { live_tested: false, pass_fail: 'skip', skip_reason: 'unknown_provider' };
}

function readinessFromBackends(payload, provider) {
  return payload?.backends?.providers?.[provider]?.readiness || 'unknown';
}

function readModelsReadiness(modelsPayload, provider) {
  const rows = []
    .concat(modelsPayload?.readiness?.text || [])
    .concat(modelsPayload?.readiness?.image || []);
  const r = rows.find((x) => x && x.provider === provider);
  return r ? r.readiness : 'unknown';
}

function opSteps(provider) {
  const shared = 'After exporting env vars, restart prismbot-core and re-run: npm run verify:providers:live';
  if (provider === 'openai') return ['Export OPENAI_API_KEY', shared];
  if (provider === 'anthropic') return ['Export ANTHROPIC_API_KEY', shared];
  if (provider === 'google') return ['Export GOOGLE_API_KEY (or GEMINI_API_KEY)', shared];
  if (provider === 'xai') return ['Export XAI_API_KEY', shared];
  if (provider === 'nanobanana2') return ['Export OMNI_NANOBANANA2_CHAT_URL (and optional OMNI_NANOBANANA2_API_KEY)', shared];
  if (provider === 'ollama') return ['Ensure Ollama is running and OMNI_OLLAMA_HOST is reachable', `Pull model: ollama pull ${process.env.OMNI_OLLAMA_MODEL || 'llama3.2:3b'}`, shared];
  return [shared];
}

(async function main() {
  const pid = findServerPid();
  const env = readProcEnv(pid);
  const token = String(env.PRISMBOT_API_TOKEN || '').trim();
  if (!token) throw new Error('PRISMBOT_API_TOKEN missing in running prismbot-core process');

  const base = String(env.OMNI_BASE_URL || 'http://127.0.0.1:8799/api/omni').replace(/\/$/, '');
  const auth = { authorization: `Bearer ${token}` };

  const backendsRes = await httpJson(`${base}/backends`, { headers: auth }, 8000);
  const modelsRes = await httpJson(`${base}/models`, { headers: auth }, 8000);
  if (!backendsRes.ok) throw new Error(`backends_fetch_failed_http_${backendsRes.status}`);
  if (!modelsRes.ok) throw new Error(`models_fetch_failed_http_${modelsRes.status}`);

  const cfg = providerCfg(env);
  const matrix = [];
  for (const provider of PROVIDERS) {
    const c = cfg[provider];
    const live = await liveCheck(provider, c, env);
    const backendsReadiness = readinessFromBackends(backendsRes.payload, provider);
    const modelsReadiness = readModelsReadiness(modelsRes.payload, provider);
    matrix.push({
      provider,
      enabled: c.enabled,
      ready: backendsReadiness === 'ready',
      credentialed: c.credentialed,
      live_tested: live.live_tested,
      pass_fail: live.pass_fail,
      skip_reason: live.skip_reason || '',
      backends_readiness: backendsReadiness,
      models_readiness: modelsReadiness,
      consistency_ok: backendsReadiness === modelsReadiness,
    });
  }

  const uncredentialed = matrix.filter((r) => !r.credentialed);
  const failed = matrix.filter((r) => r.pass_fail === 'fail');
  const inconsistent = matrix.filter((r) => !r.consistency_ok);

  const artifact = {
    generated_at: new Date().toISOString(),
    service_base: base,
    server_pid: pid,
    matrix,
    summary: {
      providers: matrix.length,
      credentialed: matrix.filter((r) => r.credentialed).length,
      live_pass: matrix.filter((r) => r.pass_fail === 'pass').length,
      live_fail: failed.length,
      skipped: matrix.filter((r) => r.pass_fail === 'skip').length,
      consistency_ok: inconsistent.length === 0,
    },
    operational_steps: Object.fromEntries(uncredentialed.map((r) => [r.provider, opSteps(r.provider)])),
  };

  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(OUT_JSON, JSON.stringify(artifact, null, 2));

  const lines = [];
  lines.push('# Omni Provider Verification Matrix');
  lines.push('');
  lines.push(`Generated: ${artifact.generated_at}`);
  lines.push(`Service: ${base}`);
  lines.push('');
  lines.push('| provider | ready | credentialed | live_tested | pass_fail | skip_reason | backends_readiness | models_readiness | consistency_ok |');
  lines.push('|---|---:|---:|---:|---|---|---|---|---:|');
  for (const row of matrix) {
    lines.push(`| ${row.provider} | ${row.ready} | ${row.credentialed} | ${row.live_tested} | ${row.pass_fail} | ${row.skip_reason || '-'} | ${row.backends_readiness} | ${row.models_readiness} | ${row.consistency_ok} |`);
  }
  lines.push('');
  if (uncredentialed.length) {
    lines.push('## Uncredentialed Providers: Enable + Re-run');
    lines.push('');
    for (const row of uncredentialed) {
      lines.push(`### ${row.provider}`);
      for (const step of opSteps(row.provider)) lines.push(`- ${step}`);
      lines.push('');
    }
  }
  if (failed.length) {
    lines.push('## Live Failures');
    lines.push('');
    for (const row of failed) lines.push(`- ${row.provider}: ${row.skip_reason || 'live_check_failed'}`);
    lines.push('');
  }
  if (inconsistent.length) {
    lines.push('## Consistency Alerts (/backends vs /models)');
    lines.push('');
    for (const row of inconsistent) {
      lines.push(`- ${row.provider}: backends=${row.backends_readiness}, models=${row.models_readiness}`);
    }
    lines.push('');
  }

  fs.writeFileSync(OUT_MD, lines.join('\n') + '\n');

  console.log(`wrote ${OUT_JSON}`);
  console.log(`wrote ${OUT_MD}`);
  console.log(`summary credentialed=${artifact.summary.credentialed}/${artifact.summary.providers} live_pass=${artifact.summary.live_pass} live_fail=${artifact.summary.live_fail} skipped=${artifact.summary.skipped} consistency_ok=${artifact.summary.consistency_ok}`);
})();