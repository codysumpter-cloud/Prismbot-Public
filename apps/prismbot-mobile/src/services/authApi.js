import { config } from '../config/env';

export async function loginFamily({ username, password }) {
  const res = await fetch(`${config.familyApiBaseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.error || 'login_failed');
  if (!data?.user?.username) throw new Error('invalid_login_response');

  return {
    username: String(data.user.username),
    displayName: String(data.user.displayName || data.user.username),
    role: String(data.user.role || 'family_user'),
    // Core auth is cookie-based; keep a local marker for app state compatibility.
    sessionToken: String(data.sessionToken || `cookie:${Date.now()}`),
  };
}
