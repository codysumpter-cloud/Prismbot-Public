import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'prismbot.auth.v1';

export async function loadAuth() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (!raw) return { username: '', role: '', sessionToken: '' };
    const data = JSON.parse(raw);
    return {
      username: String(data?.username || ''),
      role: String(data?.role || ''),
      sessionToken: String(data?.sessionToken || ''),
    };
  } catch {
    return { username: '', role: '', sessionToken: '' };
  }
}

export async function saveAuth(auth) {
  await AsyncStorage.setItem(KEY, JSON.stringify({
    username: String(auth?.username || ''),
    role: String(auth?.role || ''),
    sessionToken: String(auth?.sessionToken || ''),
  }));
}

export async function clearAuth() {
  await AsyncStorage.removeItem(KEY);
}
