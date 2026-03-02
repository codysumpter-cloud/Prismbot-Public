# Phase C Execution (Client Unification)

## Implemented now

- Mobile client updated to target PrismBot Core API (`/api/chat`, `/api/health`).
- Mobile default local endpoint switched to `http://127.0.0.1:8799`.
- Mobile family auth now accepts core cookie-based login response format.
- Desktop app now launches unified `prismbot-core` runtime instead of multiple backend services.
- Desktop default app URL switched to `http://127.0.0.1:8799/chat`.

## Result

Desktop and mobile now point to the same unified backend surface (`prismbot-core`).

## Phase C polish completed in this pass

- Added unified shell routes:
  - `/app` (defaults to chat)
  - `/app/site`, `/app/chat`, `/app/admin`, `/app/public`, `/app/studio`
- Shell provides one navigation surface and embeds modules in a single frame.

## Remaining Phase C polish

- Add dedicated mobile endpoints only if required (currently unified `/api/chat` works).
- Replace iframe shell with a native consolidated front-end shell over time.
