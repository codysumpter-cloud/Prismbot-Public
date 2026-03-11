const { providerReadiness } = require('./config');

class OmniProviderAdapter {
  constructor(provider, modalities = ['text']) {
    this.provider = provider;
    this.modalities = modalities;
  }

  readiness() {
    throw new Error('readiness() not implemented');
  }

  async text() {
    throw new Error(`${this.provider} text adapter not implemented`);
  }

  async image() {
    throw new Error(`${this.provider} image adapter not implemented`);
  }
}

class BasicAdapter extends OmniProviderAdapter {
  constructor(provider, modalities, getRuntimeReadiness) {
    super(provider, modalities);
    this.getRuntimeReadiness = getRuntimeReadiness;
  }

  readiness() {
    const readiness = this.getRuntimeReadiness();
    return readiness[this.provider] || {
      provider: this.provider,
      enabled: false,
      hasCredentials: false,
      readiness: 'disabled',
      error: null,
    };
  }
}

function buildAdapters(config) {
  const getRuntimeReadiness = () => providerReadiness(config);
  const textModes = ['text', 'chat', 'responses', 'reason', 'summarize', 'rewrite', 'translate', 'moderate', 'classify', 'extract', 'code'];
  return {
    openai: new BasicAdapter('openai', [...textModes, 'image'], getRuntimeReadiness),
    anthropic: new BasicAdapter('anthropic', textModes, getRuntimeReadiness),
    google: new BasicAdapter('google', [...textModes, 'image'], getRuntimeReadiness),
    nanobanana2: new BasicAdapter('nanobanana2', [...textModes, 'image'], getRuntimeReadiness),
    xai: new BasicAdapter('xai', textModes, getRuntimeReadiness),
    ollama: new BasicAdapter('ollama', [...textModes, 'image'], getRuntimeReadiness),
    local: new BasicAdapter('local', [...textModes, 'image'], getRuntimeReadiness),
  };
}

module.exports = {
  OmniProviderAdapter,
  buildAdapters,
};
