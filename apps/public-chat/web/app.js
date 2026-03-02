const STORAGE_KEY = 'prism_public_chat_api_key';
const SESSION_KEY = 'prism_public_chat_session_id';

const apiKeyInput = document.getElementById('apiKey');
const saveKeyBtn = document.getElementById('saveKey');
const clearKeyBtn = document.getElementById('clearKey');
const sessionInfo = document.getElementById('sessionInfo');
const statusEl = document.getElementById('status');
const chatForm = document.getElementById('chatForm');
const promptInput = document.getElementById('prompt');
const messages = document.getElementById('messages');

function makeSessionId() {
  const rand = Math.random().toString(36).slice(2, 10);
  const ts = Date.now().toString(36);
  return `anon-${ts}-${rand}`;
}

function getSessionId() {
  let id = localStorage.getItem(SESSION_KEY);
  if (!id) {
    id = makeSessionId();
    localStorage.setItem(SESSION_KEY, id);
  }
  return id;
}

function loadApiKey() {
  return localStorage.getItem(STORAGE_KEY) || '';
}

function saveApiKey() {
  localStorage.setItem(STORAGE_KEY, apiKeyInput.value.trim());
  setStatus('API key saved in local browser storage.', false);
}

function clearApiKey() {
  localStorage.removeItem(STORAGE_KEY);
  apiKeyInput.value = '';
  setStatus('API key cleared.', false);
}

function setStatus(text, isWarn) {
  statusEl.textContent = text;
  statusEl.classList.toggle('warn', Boolean(isWarn));
}

function addMessage(role, text) {
  const item = document.createElement('div');
  item.className = `msg ${role === 'user' ? 'user' : 'bot'}`;
  item.textContent = text;
  messages.appendChild(item);
  messages.scrollTop = messages.scrollHeight;
}

async function sendMessage(text) {
  const sessionId = getSessionId();
  const apiKey = loadApiKey();

  const resp = await fetch('/api/public/chat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ text, sessionId, apiKey })
  });

  const data = await resp.json();
  if (!resp.ok) {
    throw new Error(data.message || data.error || 'request_failed');
  }

  return data;
}

saveKeyBtn.addEventListener('click', saveApiKey);
clearKeyBtn.addEventListener('click', clearApiKey);

chatForm.addEventListener('submit', async event => {
  event.preventDefault();
  const text = promptInput.value.trim();
  if (!text) return;

  promptInput.value = '';
  addMessage('user', text);
  setStatus('Sending...', false);

  try {
    const data = await sendMessage(text);
    addMessage('assistant', data.reply || 'No response');

    if (data.moderated) {
      setStatus('Message was moderated for safety.', true);
      return;
    }

    if (typeof data.remaining === 'number') {
      setStatus(`Ready • Rate limit remaining this minute: ${data.remaining}`, false);
    } else {
      setStatus('Ready', false);
    }
  } catch (err) {
    addMessage('assistant', `Error: ${err.message}`);
    setStatus('Request failed.', true);
  }
});

(function init() {
  apiKeyInput.value = loadApiKey();
  const sessionId = getSessionId();
  sessionInfo.textContent = `Anonymous session id: ${sessionId}`;
  addMessage('assistant', 'Welcome! Add your OpenAI key (optional for fallback mode) and start chatting.');
  setStatus('Ready', false);
})();
