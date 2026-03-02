import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'prismbot.session.v1';

const DEFAULT_SESSION = {
  displayName: 'Prismtek',
  sessionKey: 'agent:main:mobile:prismtek',
};

export async function loadSession() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (!raw) return DEFAULT_SESSION;
    return { ...DEFAULT_SESSION, ...JSON.parse(raw) };
  } catch {
    return DEFAULT_SESSION;
  }
}

export async function saveSession(session) {
  await AsyncStorage.setItem(KEY, JSON.stringify({
    displayName: String(session?.displayName || DEFAULT_SESSION.displayName),
    sessionKey: String(session?.sessionKey || DEFAULT_SESSION.sessionKey),
  }));
}
