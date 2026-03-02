import { loadBrainState, saveBrainState } from '../storage/localBrainStore';

function normalize(text) {
  return String(text || '').trim();
}

export async function localReply(message) {
  const text = normalize(message);
  const lower = text.toLowerCase();
  const state = await loadBrainState();

  if (!text) return 'Local mode ready. Say anything and I will respond on-device.';

  if (lower.startsWith('remember that ')) {
    const fact = text.slice('remember that '.length).trim();
    if (!fact) return 'Tell me what to remember after "remember that".';
    state.facts.push({ fact, at: new Date().toISOString() });
    await saveBrainState(state);
    return `Got it. I saved this on-device: "${fact}"`;
  }

  if (lower.includes('what do you remember') || lower === 'memory') {
    if (!state.facts.length) return 'My on-device memory is empty right now.';
    const items = state.facts.slice(-5).map((f, i) => `${i + 1}. ${f.fact}`).join('\n');
    return `Here’s what I remember on this device:\n${items}`;
  }

  if (lower.includes('status')) {
    return 'Local brain online. Running directly on this device while app is open.';
  }

  if (lower.includes('google') || lower.includes('vm')) {
    return 'You are talking in local mode right now. Remote VM fallback is optional in Hybrid mode.';
  }

  return `Local PrismBot heard: "${text}"`;
}
