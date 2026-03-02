const DEFAULT_API_BASE = 'http://127.0.0.1:8799';

export const config = {
  apiBaseUrl: process.env.EXPO_PUBLIC_PRISMBOT_API_BASE || DEFAULT_API_BASE,
  familyApiBaseUrl: process.env.EXPO_PUBLIC_PRISMBOT_FAMILY_API_BASE || process.env.EXPO_PUBLIC_PRISMBOT_API_BASE || DEFAULT_API_BASE,
  sessionKey: process.env.EXPO_PUBLIC_PRISMBOT_SESSION_KEY || 'agent:main:mobile:local',
  bridgeToken: process.env.EXPO_PUBLIC_PRISMBOT_BRIDGE_TOKEN || '',
};
