import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'prismbot.settings.v1';

export const MODES = {
  LOCAL: 'local',
  HYBRID: 'hybrid',
  REMOTE: 'remote',
};

const DEFAULT_SETTINGS = {
  mode: MODES.HYBRID,
};

export async function loadSettings() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (!raw) return DEFAULT_SETTINGS;
    return { ...DEFAULT_SETTINGS, ...JSON.parse(raw) };
  } catch {
    return DEFAULT_SETTINGS;
  }
}

export async function saveSettings(settings) {
  await AsyncStorage.setItem(KEY, JSON.stringify(settings));
}
