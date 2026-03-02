// Phase A auth/session middleware extraction path
// Source target: apps/kid-chat-mvp/server.js auth + role/session logic

function parseCookie(header = '') {
  return Object.fromEntries(
    header
      .split(';')
      .map((v) => v.trim())
      .filter(Boolean)
      .map((v) => {
        const i = v.indexOf('=');
        return i === -1 ? [v, ''] : [v.slice(0, i), decodeURIComponent(v.slice(i + 1))];
      })
  );
}

function getSessionFromRequest(req, cookieName = process.env.CORE_SESSION_COOKIE || 'pb_core_session') {
  const cookies = parseCookie(req.headers.cookie || '');
  const token = cookies[cookieName] || null;
  return { token, cookies };
}

function requireRole(user, allowedRoles = []) {
  if (!user) return false;
  return allowedRoles.includes(user.role);
}

module.exports = {
  parseCookie,
  getSessionFromRequest,
  requireRole,
};
