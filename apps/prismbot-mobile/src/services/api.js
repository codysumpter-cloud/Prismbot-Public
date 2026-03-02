import { config } from '../config/env';
import { MODES } from '../storage/settingsStore';
import { localReply } from './localBrain';

async function sendRemote(text, sessionKey) {
  const endpoint = `${config.apiBaseUrl}/api/chat`;
  const headers = { 'Content-Type': 'application/json' };
  if (config.bridgeToken) headers.Authorization = `Bearer ${config.bridgeToken}`;

  const res = await fetch(endpoint, {
    method: 'POST',
    headers,
    body: JSON.stringify({ sessionKey, text }),
  });

  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();
  return data.reply || 'Delivered, but no reply payload.';
}

export async function checkBridgeHealth() {
  try {
    const res = await fetch(`${config.apiBaseUrl}/api/health`);
    return res.ok;
  } catch {
    return false;
  }
}

export async function sendMessage(text, mode = MODES.HYBRID, sessionKey = config.sessionKey) {
  if (mode === MODES.LOCAL) return { reply: await localReply(text), queued: false, remoteOk: false };

  if (mode === MODES.REMOTE) {
    const reply = await sendRemote(text, sessionKey);
    return { reply, queued: false, remoteOk: true };
  }

  try {
    const reply = await sendRemote(text, sessionKey);
    return { reply, queued: false, remoteOk: true };
  } catch {
    return {
      reply: `${await localReply(text)}\n\n(Hybrid note: remote backend unavailable, queued for retry.)`,
      queued: true,
      remoteOk: false,
    };
  }
}

export async function flushOutbox(outbox, sessionKey = config.sessionKey) {
  const remaining = [];
  for (const item of outbox) {
    try {
      await sendRemote(item.text, sessionKey);
    } catch {
      remaining.push(item);
    }
  }
  return remaining;
}
