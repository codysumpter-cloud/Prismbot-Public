const { routeProvider } = require('./router');

function delayMs(ms) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, Number(ms) || 0)));
}

function nowMs() { return Date.now(); }

function extractTextFromOpenAI(payload) {
  if (typeof payload?.output_text === 'string' && payload.output_text.trim()) return payload.output_text.trim();
  const content = payload?.choices?.[0]?.message?.content;
  if (typeof content === 'string' && content.trim()) return content.trim();
  if (Array.isArray(content)) {
    const joined = content.map((c) => String(c?.text || c?.content || '')).join(' ').trim();
    if (joined) return joined;
  }
  return '';
}

function extractTextFromAnthropic(payload) {
  const text = Array.isArray(payload?.content)
    ? payload.content.filter((c) => c?.type === 'text').map((c) => String(c?.text || '')).join(' ').trim()
    : '';
  return text;
}

function extractTextFromGoogle(payload) {
  const parts = payload?.candidates?.[0]?.content?.parts;
  if (!Array.isArray(parts)) return '';
  return parts.map((p) => String(p?.text || '')).join(' ').trim();
}

function extractTextFromOllama(payload) {
  if (typeof payload?.response === 'string') return payload.response.trim();
  return '';
}

function extractTextFromOllamaChat(payload) {
  const text = payload?.message?.content;
  if (typeof text === 'string' && text.trim()) return text.trim();
  return extractTextFromOllama(payload);
}

function envBool(value, fallback = false) {
  if (value == null || value === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).trim().toLowerCase());
}

function normalizeChatMessages(body = {}, inputText = '') {
  const rows = Array.isArray(body?.messages) ? body.messages : [];
  const out = rows
    .filter((m) => m && typeof m === 'object' && typeof m.role === 'string' && typeof m.content === 'string')
    .map((m) => ({ role: String(m.role).toLowerCase(), content: String(m.content) }))
    .filter((m) => ['system', 'user', 'assistant'].includes(m.role) && m.content.trim());

  if (out.length) return out;
  const fallback = String(inputText || '').trim();
  if (!fallback) return [{ role: 'user', content: 'Hello' }];
  return [{ role: 'user', content: fallback }];
}


function generateLocalAssistantReply(inputText = '') {
  const text = String(inputText || '').trim();
  const low = text.toLowerCase();

  if (!text) return 'OmniPrismAI is online. Give me a task and I’ll execute it.';

  if (/^(hi|hello|hey|yo)\b/.test(low)) {
    return 'Yo — OmniPrismAI local mode is live. Want text, image, audio, or a full workflow run?';
  }

  if (low.includes('what backend') || low.includes('which backend') || low.includes('backend are you using')) {
    return 'I’m running on the local Omni backend: prismbot-core-local (external providers disabled).';
  }

  if (low.includes('status')) {
    return 'Status: online ✅ local-only routing ✅ ready for text/image/audio/video endpoints.';
  }

  if (low.includes('what can you do') || low.includes('capabilities') || low.includes('help')) {
    return 'I can handle chat, summarize/rewrite/translate, generate and edit pixel images, transcribe audio, and run orchestrated Omni workflows. Tell me exactly what to make and I’ll run it.';
  }

  if (low.startsWith('summarize ') || low.startsWith('summary:')) {
    const body = text.replace(/^summarize\s+/i, '').replace(/^summary:\s*/i, '').trim();
    if (!body) return 'Send text after "summarize" and I’ll compress it.';
    const cleaned = body.replace(/\s+/g, ' ');
    const clipped = cleaned.length > 420 ? cleaned.slice(0, 420) + '…' : cleaned;
    return `Summary: ${clipped}`;
  }

  if (low.startsWith('rewrite ') || low.startsWith('rewrite:')) {
    const body = text.replace(/^rewrite\s+/i, '').replace(/^rewrite:\s*/i, '').trim();
    if (!body) return 'Send text after "rewrite" and I’ll clean it up.';
    return `Rewrite: ${body.replace(/\s+/g, ' ').trim()}`;
  }

  if (low.startsWith('translate ') || low.startsWith('translate:')) {
    return 'Translation is ready. Format: "translate to <language>: <text>".';
  }

  if (low.includes('image') || low.includes('pixel') || low.includes('zoomquilt')) {
    return 'Nice. I can generate that via Omni image pipeline. Give me: subject, vibe, palette, size (512/1024), and whether you want strict HQ mode.';
  }

  if (low.includes('code') || low.includes('script') || low.includes('bug')) {
    return 'I can help with code tasks too. Paste the snippet + expected behavior and I’ll return a focused fix.';
  }

  return `Got it. Here’s what I understood: "${text.slice(0, 500)}"\n\nIf you want execution, say exactly what output you want (format + style + constraints), and I’ll do it.`;
}


function createExecutionEngine(opts = {}) {
  const fetchFn = opts.fetchFn || fetch;
  const providerStats = new Map();
  const breakers = new Map();

  function cfg() {
    return {
      maxAttempts: Math.max(1, Math.min(5, Number(process.env.OMNI_PROVIDER_MAX_ATTEMPTS || 2))),
      baseDelayMs: Math.max(25, Math.min(5000, Number(process.env.OMNI_PROVIDER_RETRY_DELAY_MS || 200))),
      timeoutMs: Math.max(500, Math.min(90000, Number(process.env.OMNI_PROVIDER_TIMEOUT_MS || 12000))),
      breakerFailures: Math.max(1, Math.min(20, Number(process.env.OMNI_PROVIDER_BREAKER_FAILURES || 3))),
      breakerCooldownMs: Math.max(500, Math.min(300000, Number(process.env.OMNI_PROVIDER_BREAKER_COOLDOWN_MS || 30000))),
    };
  }

  function ensure(provider) {
    if (!providerStats.has(provider)) providerStats.set(provider, { success: 0, failure: 0, timeout: 0, latencyMs: [] });
    if (!breakers.has(provider)) breakers.set(provider, { failures: 0, openUntil: 0 });
    return { stats: providerStats.get(provider), breaker: breakers.get(provider) };
  }

  function note(provider, ok, latencyMs, code) {
    const { stats, breaker } = ensure(provider);
    if (ok) {
      stats.success += 1;
      breaker.failures = 0;
      breaker.openUntil = 0;
    } else {
      stats.failure += 1;
      breaker.failures += 1;
      if (code === 'PROVIDER_TIMEOUT') stats.timeout += 1;
      const c = cfg();
      if (breaker.failures >= c.breakerFailures) breaker.openUntil = nowMs() + c.breakerCooldownMs;
    }
    if (Number.isFinite(latencyMs) && latencyMs >= 0) {
      stats.latencyMs.push(latencyMs);
      if (stats.latencyMs.length > 100) stats.latencyMs = stats.latencyMs.slice(-100);
    }
  }

  function breakerState(provider) {
    const { breaker } = ensure(provider);
    return { state: breaker.openUntil > nowMs() ? 'open' : 'closed', openUntil: breaker.openUntil || null, failures: breaker.failures || 0 };
  }

  async function withTimeout(promise, timeoutMs, label = 'provider_call_timeout') {
    const ms = Math.max(500, Number(timeoutMs) || 12000);
    let timer = null;
    const timeoutPromise = new Promise((_, reject) => {
      timer = setTimeout(() => {
        const err = new Error(`${label}:${ms}`);
        err.code = 'PROVIDER_TIMEOUT';
        reject(err);
      }, ms);
    });
    try {
      return await Promise.race([promise, timeoutPromise]);
    } finally {
      if (timer) clearTimeout(timer);
    }
  }

  async function httpJson(url, init, timeoutMs) {
    const res = await withTimeout(fetchFn(url, init), timeoutMs, 'provider_http_timeout');
    const text = await res.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch { payload = { raw: text }; }
    if (!res.ok) {
      const msg = String(payload?.error?.message || payload?.message || `http_${res.status}`).slice(0, 240);
      const err = new Error(msg);
      err.code = `HTTP_${res.status}`;
      throw err;
    }
    return payload || {};
  }

  async function invokeProvider(provider, inputText, body = {}, timeoutMs = 12000) {
    const model = String(body.model || '').trim();
    if (provider === 'local') {
      const localMode = String(process.env.OMNI_LOCAL_MODE || 'ollama').trim().toLowerCase();
      if (localMode === 'stub') {
        return { output: generateLocalAssistantReply(inputText), model: model || 'prismbot-core-local', backend: 'local-stub' };
      }

      if (localMode === 'ollama') {
        const host = String(process.env.OMNI_LOCAL_OLLAMA_HOST || process.env.OMNI_OLLAMA_HOST || 'http://127.0.0.1:11434').trim().replace(/\/$/, '');
        const localModel = model || process.env.OMNI_LOCAL_MODEL || process.env.OMNI_OLLAMA_MODEL || 'llama3.2:3b';
        const messages = normalizeChatMessages(body, inputText);
        try {
          const localMaxTokens = Math.max(32, Math.min(1024, Number(process.env.OMNI_LOCAL_MAX_TOKENS || 320)));
          const keepAlive = String(process.env.OMNI_OLLAMA_KEEP_ALIVE || '30m').trim();
          const payload = await httpJson(`${host}/api/chat`, {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
            body: JSON.stringify({ model: localModel, messages, stream: false, keep_alive: keepAlive, options: { temperature: 0.2, num_predict: localMaxTokens } }),
          }, timeoutMs);
          const output = extractTextFromOllamaChat(payload);
          if (!output) throw Object.assign(new Error('empty_local_ollama_output'), { code: 'EMPTY_OUTPUT' });
          return { output, model: localModel, backend: 'local-ollama' };
        } catch (err) {
          if (envBool(process.env.OMNI_LOCAL_ALLOW_STUB_FALLBACK, false)) {
            return { output: generateLocalAssistantReply(inputText), model: model || 'prismbot-core-local', backend: 'local-stub-fallback' };
          }
          throw Object.assign(new Error(`local_model_unavailable:${err?.message || 'unknown_error'}`), { code: String(err?.code || 'LOCAL_MODEL_UNAVAILABLE') });
        }
      }

      throw Object.assign(new Error(`unsupported_local_mode:${localMode}`), { code: 'LOCAL_MODE_INVALID' });
    }

    if (provider === 'openai') {
      const key = String(process.env.OPENAI_API_KEY || '').trim();
      if (!key) throw Object.assign(new Error('missing_openai_api_key'), { code: 'MISSING_CREDENTIALS' });
      const payload = await httpJson('https://api.openai.com/v1/chat/completions', {
        method: 'POST',
        headers: { 'content-type': 'application/json', authorization: `Bearer ${key}` },
        body: JSON.stringify({ model: model || 'gpt-4o-mini', messages: [{ role: 'user', content: inputText }], temperature: 0.2 }),
      }, timeoutMs);
      const output = extractTextFromOpenAI(payload);
      if (!output) throw Object.assign(new Error('empty_openai_output'), { code: 'EMPTY_OUTPUT' });
      return { output, model: payload?.model || model || 'gpt-4o-mini', backend: 'openai' };
    }

    if (provider === 'anthropic') {
      const key = String(process.env.ANTHROPIC_API_KEY || '').trim();
      if (!key) throw Object.assign(new Error('missing_anthropic_api_key'), { code: 'MISSING_CREDENTIALS' });
      const payload = await httpJson('https://api.anthropic.com/v1/messages', {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-api-key': key, 'anthropic-version': '2023-06-01' },
        body: JSON.stringify({ model: model || 'claude-3-5-haiku-latest', max_tokens: 512, messages: [{ role: 'user', content: inputText }] }),
      }, timeoutMs);
      const output = extractTextFromAnthropic(payload);
      if (!output) throw Object.assign(new Error('empty_anthropic_output'), { code: 'EMPTY_OUTPUT' });
      return { output, model: payload?.model || model || 'claude-3-5-haiku-latest', backend: 'anthropic' };
    }

    if (provider === 'google') {
      const key = String(process.env.GOOGLE_API_KEY || process.env.GEMINI_API_KEY || '').trim();
      if (!key) throw Object.assign(new Error('missing_google_api_key'), { code: 'MISSING_CREDENTIALS' });
      const gModel = model || 'gemini-1.5-flash';
      const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(gModel)}:generateContent?key=${encodeURIComponent(key)}`;
      const payload = await httpJson(url, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ contents: [{ role: 'user', parts: [{ text: inputText }] }] }),
      }, timeoutMs);
      const output = extractTextFromGoogle(payload);
      if (!output) throw Object.assign(new Error('empty_google_output'), { code: 'EMPTY_OUTPUT' });
      return { output, model: gModel, backend: 'google' };
    }

    if (provider === 'xai') {
      const key = String(process.env.XAI_API_KEY || '').trim();
      if (!key) throw Object.assign(new Error('missing_xai_api_key'), { code: 'MISSING_CREDENTIALS' });
      const payload = await httpJson('https://api.x.ai/v1/chat/completions', {
        method: 'POST',
        headers: { 'content-type': 'application/json', authorization: `Bearer ${key}` },
        body: JSON.stringify({ model: model || 'grok-2-latest', messages: [{ role: 'user', content: inputText }], temperature: 0.2 }),
      }, timeoutMs);
      const output = extractTextFromOpenAI(payload);
      if (!output) throw Object.assign(new Error('empty_xai_output'), { code: 'EMPTY_OUTPUT' });
      return { output, model: payload?.model || model || 'grok-2-latest', backend: 'xai' };
    }

    if (provider === 'nanobanana2') {
      const chatUrl = String(process.env.OMNI_NANOBANANA2_CHAT_URL || '').trim();
      if (!chatUrl) throw Object.assign(new Error('missing_nanobanana2_chat_url'), { code: 'MISSING_CREDENTIALS' });
      const key = String(process.env.OMNI_NANOBANANA2_API_KEY || process.env.NANOBANANA2_API_KEY || '').trim();
      const headers = { 'content-type': 'application/json' };
      if (key) headers.authorization = `Bearer ${key}`;

      const payload = await httpJson(chatUrl, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          model: model || process.env.OMNI_NANOBANANA2_MODEL || 'nanobanana-2',
          messages: normalizeChatMessages(body, inputText),
          temperature: 0.2,
          stream: false,
        }),
      }, timeoutMs);
      const output = extractTextFromOpenAI(payload)
        || extractTextFromGoogle(payload)
        || extractTextFromAnthropic(payload)
        || extractTextFromOllamaChat(payload)
        || String(payload?.output || '').trim();
      if (!output) throw Object.assign(new Error('empty_nanobanana2_output'), { code: 'EMPTY_OUTPUT' });
      return { output, model: payload?.model || model || process.env.OMNI_NANOBANANA2_MODEL || 'nanobanana-2', backend: 'nanobanana2' };
    }

    if (provider === 'ollama') {
      const host = String(process.env.OMNI_OLLAMA_HOST || 'http://127.0.0.1:11434').trim().replace(/\/$/, '');
      const maxTokens = Math.max(32, Math.min(1024, Number(process.env.OMNI_OLLAMA_MAX_TOKENS || 320)));
      const keepAlive = String(process.env.OMNI_OLLAMA_KEEP_ALIVE || '30m').trim();
      const payload = await httpJson(`${host}/api/generate`, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ model: model || process.env.OMNI_OLLAMA_MODEL || 'llama3.2:3b', prompt: inputText, stream: false, keep_alive: keepAlive, options: { num_predict: maxTokens, temperature: 0.2 } }),
      }, timeoutMs);
      const output = extractTextFromOllama(payload);
      if (!output) throw Object.assign(new Error('empty_ollama_output'), { code: 'EMPTY_OUTPUT' });
      return { output, model: model || process.env.OMNI_OLLAMA_MODEL || 'llama3.2:3b', backend: 'ollama' };
    }

    throw Object.assign(new Error('provider_not_implemented'), { code: 'PROVIDER_NOT_IMPLEMENTED' });
  }

  async function executeTextWithRouting({ runtime, modality = 'text', inputText, body, requestId }) {
    const strategy = runtime?.config?.routing?.strategy || 'quality';
    const route = routeProvider({ adapters: runtime.adapters, strategy, modality });
    const c = cfg();
    const attempts = [];

    for (const provider of route.fallbackChain) {
      const adapter = runtime.adapters[provider];
      if (!adapter) {
        attempts.push({ provider, ok: false, skipped: true, reason: 'adapter_missing' });
        continue;
      }
      const ready = adapter.readiness();
      const supports = Array.isArray(adapter.modalities) && adapter.modalities.includes(modality);
      const breaker = breakerState(provider);
      if (ready.readiness !== 'ready' || !supports || breaker.state === 'open') {
        const reason = !supports ? 'modality_not_supported' : (breaker.state === 'open' ? 'circuit_open' : ready.readiness);
        attempts.push({ provider, ok: false, skipped: true, reason, breaker });
        continue;
      }

      for (let n = 1; n <= c.maxAttempts; n += 1) {
        const t0 = nowMs();
        try {
          const result = await invokeProvider(provider, inputText, body, c.timeoutMs);
          const latencyMs = nowMs() - t0;
          note(provider, true, latencyMs, null);
          attempts.push({ provider, ok: true, attempt: n, timeoutMs: c.timeoutMs, latencyMs });
          return { ok: true, strategy, route, attempts, requestId, ...result };
        } catch (err) {
          const latencyMs = nowMs() - t0;
          const code = String(err?.code || 'provider_failed');
          const message = String(err?.message || 'provider_failed');
          note(provider, false, latencyMs, code);
          const retryable = !['PROVIDER_NOT_IMPLEMENTED', 'MISSING_CREDENTIALS', 'HTTP_400', 'HTTP_401', 'HTTP_403'].includes(code) && n < c.maxAttempts;
          attempts.push({ provider, ok: false, attempt: n, code, message, retryable, latencyMs, breaker: breakerState(provider) });
          if (retryable) {
            const jitter = Math.floor(Math.random() * 40);
            await delayMs(c.baseDelayMs * (2 ** (n - 1)) + jitter);
          } else {
            break;
          }
        }
      }
    }

    return { ok: false, strategy, route, attempts, error: 'no_provider_available', message: 'No ready provider could execute this request.' };
  }

  function providerMetrics() {
    const out = {};
    for (const [provider, stats] of providerStats.entries()) {
      const arr = [...stats.latencyMs].sort((a, b) => a - b);
      const pick = (p) => (arr.length ? arr[Math.min(arr.length - 1, Math.floor(arr.length * p))] : 0);
      out[provider] = {
        success: stats.success,
        failure: stats.failure,
        timeout: stats.timeout,
        latencyMsP50: pick(0.5),
        latencyMsP95: pick(0.95),
        circuit: breakerState(provider),
      };
    }
    return out;
  }

  return { executeTextWithRouting, providerMetrics };
}

module.exports = { createExecutionEngine };
