export function createOmniClient({ baseUrl = '', fetchImpl = fetch } = {}) {
  const call = async (path, init = {}) => {
    const res = await fetchImpl(baseUrl + path, {
      headers: { 'content-type': 'application/json', ...(init.headers || {}) },
      ...init,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.ok === false) throw new Error(json.message || json.error || `http_${res.status}`);
    return json;
  };

  return {
    health: () => call('/api/omni/health'),
    capabilities: () => call('/api/omni/capabilities'),
    run: ({ prompt, async = true, idempotencyKey } = {}) =>
      call('/api/omni/orchestrate/run', {
        method: 'POST',
        headers: idempotencyKey ? { 'idempotency-key': idempotencyKey } : {},
        body: JSON.stringify({ prompt, async }),
      }),
    job: (id) => call(`/api/omni/orchestrate/jobs/${encodeURIComponent(id)}`),
    cancel: (id) => call(`/api/omni/orchestrate/jobs/${encodeURIComponent(id)}/cancel`, { method: 'POST' }),
  };
}
