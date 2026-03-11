#!/usr/bin/env node
const assert = require('assert');
const { readOmniRuntimeConfig } = require('../src/omni/config');
const { buildAdapters } = require('../src/omni/adapters');
const { createExecutionEngine } = require('../src/omni/execution');

function withEnv(overrides, fn) {
  const prev = {};
  for (const [k, v] of Object.entries(overrides)) {
    prev[k] = process.env[k];
    if (v == null) delete process.env[k]; else process.env[k] = String(v);
  }
  try { return fn(); } finally {
    for (const [k, v] of Object.entries(prev)) {
      if (v == null) delete process.env[k]; else process.env[k] = v;
    }
  }
}

function mockFetch(url) {
  const u = String(url);
  const ok = (payload) => ({ ok: true, status: 200, text: async () => JSON.stringify(payload) });
  if (u.includes('openai.com')) return Promise.resolve(ok({ model: 'gpt-4o-mini', choices: [{ message: { content: 'openai-ok' } }] }));
  if (u.includes('/api/generate')) return Promise.resolve(ok({ response: 'ollama-ok' }));
  return Promise.resolve({ ok: false, status: 503, text: async () => JSON.stringify({ error: { message: 'mock_unavailable' } }) });
}

async function run() {
  await withEnv({
    OPENAI_API_KEY: 'sk-test',
    ANTHROPIC_API_KEY: '',
    GOOGLE_API_KEY: '',
    XAI_API_KEY: '',
    OMNI_OLLAMA_HOST: 'http://127.0.0.1:11434',
    OMNI_ROUTING_STRATEGY: 'quality',
    OMNI_PROVIDER_MAX_ATTEMPTS: '2',
  }, async () => {
    const engine = createExecutionEngine({ fetchFn: mockFetch });
    const cfg = readOmniRuntimeConfig(process.env);
    const runtime = { config: cfg, adapters: buildAdapters(cfg) };

    const out = await engine.executeTextWithRouting({ runtime, inputText: 'hello', body: {} });
    assert.strictEqual(out.ok, true);
    assert.strictEqual(out.backend, 'openai');
    assert.strictEqual(out.output, 'openai-ok');

    const m = engine.providerMetrics();
    assert.ok(m.openai && m.openai.success >= 1);
  });

  await withEnv({
    OPENAI_API_KEY: '',
    ANTHROPIC_API_KEY: '',
    GOOGLE_API_KEY: '',
    XAI_API_KEY: '',
    OMNI_OLLAMA_ENABLED: 'false',
    OMNI_ROUTING_STRATEGY: 'local-first',
  }, async () => {
    const engine = createExecutionEngine({ fetchFn: mockFetch });
    const cfg = readOmniRuntimeConfig(process.env);
    const runtime = { config: cfg, adapters: buildAdapters(cfg) };
    const out = await engine.executeTextWithRouting({ runtime, inputText: 'hello', body: {} });
    assert.strictEqual(out.ok, true);
    assert.strictEqual(out.backend, 'local');
  });

  console.log('omni-provider-conformance: ok');
}

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
