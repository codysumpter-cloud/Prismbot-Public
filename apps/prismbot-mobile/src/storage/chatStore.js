import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'prismbot.chat.v1';

export async function loadMessages() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (!raw) return [];
    return JSON.parse(raw);
  } catch {
    return [];
  }
}

export async function saveMessages(messages) {
  await AsyncStorage.setItem(KEY, JSON.stringify(messages.slice(-200)));
}
