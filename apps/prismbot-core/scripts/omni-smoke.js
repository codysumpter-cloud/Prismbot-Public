#!/usr/bin/env node
const assert = require('assert');
const { readOmniRuntimeConfig } = require('../src/omni/config');
const { buildAdapters } = require('../src/omni/adapters');
const { routeProvider, deterministicChain } = require('../src/omni/router');

function withEnv(overrides, fn) {
  const prev = {};
  for (const [k, v] of Object.entries(overrides)) {
    prev[k] = process.env[k];
    if (v == null) delete process.env[k];
    else process.env[k] = String(v);
  }
  try {
    return fn();
  } finally {
    for (const [k, v] of Object.entries(prev)) {
      if (v == null) delete process.env[k];
      else process.env[k] = v;
    }
  }
}

function run() {
  // 1) Missing keys should not fake ready providers.
  withEnv({ OPENAI_API_KEY: '', ANTHROPIC_API_KEY: '', GOOGLE_API_KEY: '', XAI_API_KEY: '' }, () => {
    const cfg = readOmniRuntimeConfig(process.env);
    const adapters = buildAdapters(cfg);
    const res = routeProvider({ adapters, strategy: 'quality', modality: 'text' });
    assert.strictEqual(res.selected, 'ollama', 'quality should choose ollama before local when cloud keys missing');
  });

  // 2) Disabled provider must be skipped.
  withEnv({ OMNI_OLLAMA_ENABLED: 'false' }, () => {
    const cfg = readOmniRuntimeConfig(process.env);
    const adapters = buildAdapters(cfg);
    const res = routeProvider({ adapters, strategy: 'latency', modality: 'text' });
    assert.notStrictEqual(res.selected, 'ollama', 'disabled provider should never be selected');
  });

  // 3) local-first deterministic order.
  assert.deepStrictEqual(
    deterministicChain('local-first'),
    ['local', 'ollama', 'openai', 'anthropic', 'google', 'xai'],
    'local-first fallback chain changed unexpectedly'
  );

  console.log('omni-smoke: ok');
}

run();
