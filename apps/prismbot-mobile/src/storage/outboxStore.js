import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'prismbot.outbox.v1';

export async function loadOutbox() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

export async function saveOutbox(items) {
  await AsyncStorage.setItem(KEY, JSON.stringify((items || []).slice(-50)));
}
