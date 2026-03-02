import React, { useEffect, useState } from 'react';
import {
  SafeAreaView,
  StatusBar,
  View,
  Text,
  TextInput,
  TouchableOpacity,
  FlatList,
  StyleSheet,
} from 'react-native';
import { checkBridgeHealth, flushOutbox, sendMessage } from './services/api';
import { loadMessages, saveMessages } from './storage/chatStore';
import ModeSwitcher from './components/ModeSwitcher';
import { loadSettings, MODES, saveSettings } from './storage/settingsStore';
import { loadSession, saveSession } from './storage/sessionStore';
import { loadOutbox, saveOutbox } from './storage/outboxStore';
import { loadAuth, saveAuth, clearAuth } from './storage/authStore';
import { loginFamily } from './services/authApi';

function Bubble({ item }) {
  const mine = item.role === 'user';
  return (
    <View style={[styles.bubble, mine ? styles.mine : styles.bot]}>
      <Text style={styles.bubbleText}>{item.text}</Text>
    </View>
  );
}

const modeSubtitle = {
  [MODES.LOCAL]: 'Local Mode (device runtime)',
  [MODES.HYBRID]: 'Hybrid Mode (remote + local fallback)',
  [MODES.REMOTE]: 'Remote Mode (server only)',
};

export default function App() {
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState(MODES.HYBRID);
  const [displayName, setDisplayName] = useState('Prismtek');
  const [sessionKey, setSessionKey] = useState('agent:main:mobile:prismtek');
  const [bridgeOnline, setBridgeOnline] = useState(false);
  const [outboxCount, setOutboxCount] = useState(0);
  const [authUser, setAuthUser] = useState('');
  const [authPass, setAuthPass] = useState('');
  const [authRole, setAuthRole] = useState('');
  const [authInfo, setAuthInfo] = useState('Not signed in');

  useEffect(() => {
    (async () => {
      const [cachedMessages, settings, session, outbox, auth] = await Promise.all([
        loadMessages(),
        loadSettings(),
        loadSession(),
        loadOutbox(),
        loadAuth(),
      ]);

      setMode(settings.mode || MODES.HYBRID);
      setDisplayName(session.displayName);
      setSessionKey(session.sessionKey);
      setOutboxCount(outbox.length);
      if (auth.username) {
        setAuthUser(auth.username);
        setAuthRole(auth.role || 'family_user');
        setAuthInfo(`Signed in: ${auth.username} (${auth.role || 'family_user'})`);
      }

      if (cachedMessages.length) {
        setMessages(cachedMessages);
      } else {
        setMessages([
          {
            id: `${Date.now()}-hello`,
            role: 'assistant',
            text: 'PrismBot online. App-hosted mode is ready.',
          },
        ]);
      }

      const online = await checkBridgeHealth();
      setBridgeOnline(online);
      if (online && outbox.length) {
        const remaining = await flushOutbox(outbox, session.sessionKey);
        await saveOutbox(remaining);
        setOutboxCount(remaining.length);
      }
    })();
  }, []);

  useEffect(() => {
    if (messages.length) saveMessages(messages);
  }, [messages]);

  async function changeMode(nextMode) {
    setMode(nextMode);
    await saveSettings({ mode: nextMode });
    setMessages((prev) => [
      ...prev,
      {
        id: `${Date.now()}-mode`,
        role: 'assistant',
        text: `Mode switched to ${nextMode.toUpperCase()}.`,
      },
    ]);
  }

  async function saveSessionPrefs() {
    await saveSession({ displayName, sessionKey });
    setMessages((prev) => [...prev, {
      id: `${Date.now()}-sess`,
      role: 'assistant',
      text: 'Session preferences saved on this device.',
    }]);
  }

  async function onLoginFamily() {
    try {
      const auth = await loginFamily({ username: authUser.trim(), password: authPass });
      await saveAuth(auth);
      setDisplayName(auth.displayName);
      const nextSessionKey = `family-mobile:${auth.username}`;
      setSessionKey(nextSessionKey);
      await saveSession({ displayName: auth.displayName, sessionKey: nextSessionKey });
      setAuthPass('');
      setAuthRole(auth.role || 'family_user');
      setAuthInfo(`Signed in: ${auth.username} (${auth.role || 'family_user'})`);
    } catch (err) {
      setAuthInfo(`Login failed: ${String(err?.message || 'unknown')}`);
    }
  }

  async function onLogoutFamily() {
    await clearAuth();
    setAuthInfo('Not signed in');
    setAuthRole('');
    setAuthPass('');
  }

  async function onSend() {
    const text = input.trim();
    if (!text || busy) return;

    const userMsg = { id: `${Date.now()}-u`, role: 'user', text };
    setMessages((prev) => [...prev, userMsg]);
    setInput('');
    setBusy(true);

    try {
      const result = await sendMessage(text, mode, sessionKey);
      const botMsg = { id: `${Date.now()}-a`, role: 'assistant', text: result.reply };
      setMessages((prev) => [...prev, botMsg]);

      if (result.queued) {
        const outbox = await loadOutbox();
        const next = [...outbox, { id: `${Date.now()}-q`, text }];
        await saveOutbox(next);
        setOutboxCount(next.length);
      }

      if (result.remoteOk) setBridgeOnline(true);
    } catch (err) {
      setMessages((prev) => [...prev, {
        id: `${Date.now()}-err`,
        role: 'assistant',
        text: `Send failed: ${String(err?.message || 'unknown error')}`,
      }]);
      setBridgeOnline(false);
    }

    setBusy(false);
  }

  return (
    <SafeAreaView style={styles.root}>
      <StatusBar barStyle="light-content" />
      <View style={styles.header}>
        <Text style={styles.title}>PrismBot</Text>
        <Text style={styles.subtitle}>{modeSubtitle[mode]}</Text>
        <Text style={styles.health}>{bridgeOnline ? 'Bridge: online' : 'Bridge: offline/local'}</Text>
        <Text style={styles.health}>Outbox queued: {outboxCount}</Text>
      </View>

      <ModeSwitcher mode={mode} onChange={changeMode} />
      {mode === MODES.LOCAL && (
        <Text style={styles.banner}>
          Local mode runs while the app is open. iOS may pause background runtime.
        </Text>
      )}

      <View style={styles.profileCard}>
        <Text style={styles.sectionLabel}>Family login</Text>
        <TextInput
          style={styles.prefInput}
          value={authUser}
          onChangeText={setAuthUser}
          placeholder="Family username"
          placeholderTextColor="#7a7a8d"
          autoCapitalize="none"
        />
        <TextInput
          style={styles.prefInput}
          value={authPass}
          onChangeText={setAuthPass}
          placeholder="Family password"
          placeholderTextColor="#7a7a8d"
          secureTextEntry
        />
        <View style={styles.rowBtns}>
          <TouchableOpacity style={styles.saveBtn} onPress={onLoginFamily}>
            <Text style={styles.sendText}>Login</Text>
          </TouchableOpacity>
          <TouchableOpacity style={styles.saveBtn} onPress={onLogoutFamily}>
            <Text style={styles.sendText}>Logout</Text>
          </TouchableOpacity>
        </View>
        <Text style={styles.health}>{authInfo}</Text>
        {!!authRole && (
          <View style={styles.roleBadgeWrap}>
            <Text style={styles.roleBadge}>
              {authRole === 'admin' ? 'Admin powers enabled' : 'Family user mode'}
            </Text>
          </View>
        )}

        <Text style={styles.sectionLabel}>Session profile</Text>
        <TextInput
          style={styles.prefInput}
          value={displayName}
          onChangeText={setDisplayName}
          placeholder="Display name"
          placeholderTextColor="#7a7a8d"
        />
        <TextInput
          style={styles.prefInput}
          value={sessionKey}
          onChangeText={setSessionKey}
          placeholder="Session key"
          placeholderTextColor="#7a7a8d"
        />
        <TouchableOpacity style={styles.saveBtn} onPress={saveSessionPrefs}>
          <Text style={styles.sendText}>Save Session</Text>
        </TouchableOpacity>
      </View>

      <FlatList
        data={messages}
        keyExtractor={(item) => item.id}
        renderItem={({ item }) => <Bubble item={item} />}
        contentContainerStyle={styles.list}
      />

      <View style={styles.inputRow}>
        <TextInput
          style={styles.input}
          value={input}
          onChangeText={setInput}
          placeholder="Message PrismBot"
          placeholderTextColor="#7a7a8d"
          editable={!busy}
        />
        <TouchableOpacity style={styles.send} onPress={onSend} disabled={busy}>
          <Text style={styles.sendText}>{busy ? '...' : 'Send'}</Text>
        </TouchableOpacity>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#0b0b14' },
  header: { paddingHorizontal: 16, paddingTop: 8, paddingBottom: 6 },
  title: { color: '#fff', fontSize: 24, fontWeight: '700' },
  subtitle: { color: '#8f9cf6', fontSize: 12, marginTop: 2 },
  health: { color: '#93c5fd', fontSize: 11, marginTop: 1 },
  banner: {
    color: '#f59e0b',
    fontSize: 12,
    paddingHorizontal: 12,
    paddingBottom: 8,
  },
  profileCard: { paddingHorizontal: 12, gap: 8, paddingBottom: 6 },
  sectionLabel: { color: '#cbd5e1', fontSize: 12, fontWeight: '700', marginTop: 4 },
  roleBadgeWrap: { alignItems: 'flex-start' },
  roleBadge: {
    backgroundColor: '#1f2937',
    color: '#e5e7eb',
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 5,
    fontSize: 11,
    overflow: 'hidden',
  },
  prefInput: {
    backgroundColor: '#151524', color: '#fff', borderRadius: 10, paddingHorizontal: 10, paddingVertical: 8,
  },
  rowBtns: { flexDirection: 'row', gap: 8 },
  saveBtn: { backgroundColor: '#334155', borderRadius: 10, padding: 9, alignItems: 'center', flex: 1 },
  list: { padding: 12, gap: 10 },
  bubble: { maxWidth: '88%', borderRadius: 14, padding: 11 },
  mine: { alignSelf: 'flex-end', backgroundColor: '#4f46e5' },
  bot: { alignSelf: 'flex-start', backgroundColor: '#1b1b2a' },
  bubbleText: { color: '#fff', lineHeight: 20 },
  inputRow: {
    flexDirection: 'row',
    borderTopWidth: 1,
    borderColor: '#1b1b2a',
    padding: 10,
    gap: 8,
  },
  input: {
    flex: 1,
    backgroundColor: '#151524',
    color: '#fff',
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 10,
  },
  send: {
    backgroundColor: '#6366f1',
    borderRadius: 12,
    paddingHorizontal: 14,
    justifyContent: 'center',
  },
  sendText: { color: '#fff', fontWeight: '700' },
});
