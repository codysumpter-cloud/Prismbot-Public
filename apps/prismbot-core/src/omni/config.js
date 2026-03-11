const PROVIDERS = ['openai', 'anthropic', 'google', 'nanobanana2', 'xai', 'ollama', 'local'];

const STRATEGIES = ['quality', 'latency', 'cost', 'local-first'];

function toBool(value, fallback = false) {
  if (value == null || value === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).trim().toLowerCase());
}

function normalizeState({ enabled, hasCredentials, error = null }) {
  if (!enabled) return 'disabled';
  if (error) return 'error';
  return hasCredentials ? 'ready' : 'missing_credentials';
}

function readOmniRuntimeConfig(env = process.env) {
  const strategy = STRATEGIES.includes(String(env.OMNI_ROUTING_STRATEGY || '').toLowerCase())
    ? String(env.OMNI_ROUTING_STRATEGY).toLowerCase()
    : 'quality';

  const config = {
    routing: {
      strategy,
      fallbackPolicy: 'deterministic-chain-v1',
    },
    providers: {
      openai: {
        enabled: toBool(env.OMNI_OPENAI_ENABLED, true),
        hasCredentials: Boolean(String(env.OPENAI_API_KEY || '').trim()),
      },
      anthropic: {
        enabled: toBool(env.OMNI_ANTHROPIC_ENABLED, true),
        hasCredentials: Boolean(String(env.ANTHROPIC_API_KEY || '').trim()),
      },
      google: {
        enabled: toBool(env.OMNI_GOOGLE_ENABLED, true),
        hasCredentials: Boolean(String(env.GOOGLE_API_KEY || env.GEMINI_API_KEY || '').trim()),
      },
      xai: {
        enabled: toBool(env.OMNI_XAI_ENABLED, true),
        hasCredentials: Boolean(String(env.XAI_API_KEY || '').trim()),
      },
      nanobanana2: {
        enabled: toBool(env.OMNI_NANOBANANA2_ENABLED, false),
        hasCredentials: Boolean(
          String(env.OMNI_NANOBANANA2_CHAT_URL || '').trim() &&
          (String(env.OMNI_NANOBANANA2_API_KEY || env.NANOBANANA2_API_KEY || '').trim() || 'no-key-needed')
        ),
      },
      ollama: {
        enabled: toBool(env.OMNI_OLLAMA_ENABLED, true),
        hasCredentials: Boolean(String(env.OMNI_OLLAMA_HOST || 'http://127.0.0.1:11434').trim()),
      },
      local: {
        enabled: toBool(env.OMNI_LOCAL_ENABLED, true),
        hasCredentials: true,
      },
    },
  };

  return config;
}

function providerReadiness(config) {
  const out = {};
  for (const provider of PROVIDERS) {
    const row = config.providers[provider] || {};
    out[provider] = {
      provider,
      enabled: Boolean(row.enabled),
      hasCredentials: Boolean(row.hasCredentials),
      readiness: normalizeState(row),
      error: row.error || null,
    };
  }
  return out;
}

module.exports = {
  PROVIDERS,
  STRATEGIES,
  readOmniRuntimeConfig,
  providerReadiness,
  normalizeState,
};
