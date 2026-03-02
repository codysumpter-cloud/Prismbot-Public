import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'prismbot.localbrain.v1';

export async function loadBrainState() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (!raw) return { facts: [] };
    const parsed = JSON.parse(raw);
    return { facts: Array.isArray(parsed.facts) ? parsed.facts.slice(-50) : [] };
  } catch {
    return { facts: [] };
  }
}

export async function saveBrainState(state) {
  const next = { facts: Array.isArray(state?.facts) ? state.facts.slice(-50) : [] };
  await AsyncStorage.setItem(KEY, JSON.stringify(next));
}
