import requests


class OmniClient:
    def __init__(self, base_url: str = "http://127.0.0.1:8799"):
        self.base_url = base_url.rstrip("/")

    def _call(self, path: str, method: str = "GET", json=None, headers=None):
        url = f"{self.base_url}{path}"
        r = requests.request(method, url, json=json, headers=headers or {}, timeout=60)
        data = r.json() if r.content else {}
        if r.status_code >= 400 or data.get("ok") is False:
            raise RuntimeError(data.get("message") or data.get("error") or f"http_{r.status_code}")
        return data

    def health(self):
        return self._call("/api/omni/health")

    def capabilities(self):
        return self._call("/api/omni/capabilities")

    def run(self, prompt: str, async_mode: bool = True, idempotency_key: str | None = None):
        headers = {"idempotency-key": idempotency_key} if idempotency_key else None
        return self._call(
            "/api/omni/orchestrate/run",
            method="POST",
            json={"prompt": prompt, "async": async_mode},
            headers=headers,
        )

    def job(self, run_id: str):
        return self._call(f"/api/omni/orchestrate/jobs/{run_id}")

    def cancel(self, run_id: str):
        return self._call(f"/api/omni/orchestrate/jobs/{run_id}/cancel", method="POST")
