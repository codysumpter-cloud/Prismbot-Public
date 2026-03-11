const { STRATEGIES } = require('./config');

const CHAINS = {
  quality: ['openai', 'anthropic', 'google', 'nanobanana2', 'xai', 'ollama', 'local'],
  latency: ['local', 'ollama', 'nanobanana2', 'google', 'openai', 'xai', 'anthropic'],
  cost: ['local', 'ollama', 'nanobanana2', 'xai', 'google', 'anthropic', 'openai'],
  'local-first': ['local', 'ollama', 'nanobanana2', 'openai', 'anthropic', 'google', 'xai'],
};

function deterministicChain(strategy = 'quality') {
  const key = STRATEGIES.includes(strategy) ? strategy : 'quality';
  return [...CHAINS[key]];
}

function routeProvider({ adapters, strategy = 'quality', modality = 'text' }) {
  const chain = deterministicChain(strategy);
  const attempts = [];

  for (const provider of chain) {
    const adapter = adapters[provider];
    if (!adapter) {
      attempts.push({ provider, readiness: 'error', skipped: true, reason: 'adapter_missing' });
      continue;
    }

    const readiness = adapter.readiness();
    const supportsModality = Array.isArray(adapter.modalities) && adapter.modalities.includes(modality);
    const skipped = readiness.readiness !== 'ready' || !supportsModality;
    attempts.push({
      provider,
      readiness: readiness.readiness,
      skipped,
      reason: !supportsModality ? 'modality_not_supported' : skipped ? readiness.readiness : null,
    });

    if (!skipped) {
      return {
        selected: provider,
        strategy,
        fallbackChain: chain,
        attempts,
      };
    }
  }

  return {
    selected: null,
    strategy,
    fallbackChain: chain,
    attempts,
  };
}

module.exports = {
  deterministicChain,
  routeProvider,
};
