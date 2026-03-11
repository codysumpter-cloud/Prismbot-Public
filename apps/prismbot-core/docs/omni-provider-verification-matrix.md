# Omni Provider Verification Matrix

Generated: 2026-03-05T15:58:51.337Z
Service: http://127.0.0.1:8799/api/omni

| provider | ready | credentialed | live_tested | pass_fail | skip_reason | backends_readiness | models_readiness | consistency_ok |
|---|---:|---:|---:|---|---|---|---|---:|
| openai | false | false | false | skip | missing_credentials | missing_credentials | missing_credentials | true |
| anthropic | false | false | false | skip | missing_credentials | missing_credentials | missing_credentials | true |
| google | false | false | false | skip | missing_credentials | missing_credentials | missing_credentials | true |
| xai | false | false | false | skip | missing_credentials | missing_credentials | missing_credentials | true |
| ollama | false | true | false | skip | disabled | disabled | disabled | true |

## Uncredentialed Providers: Enable + Re-run

### openai
- Export OPENAI_API_KEY
- After exporting env vars, restart prismbot-core and re-run: npm run verify:providers:live

### anthropic
- Export ANTHROPIC_API_KEY
- After exporting env vars, restart prismbot-core and re-run: npm run verify:providers:live

### google
- Export GOOGLE_API_KEY (or GEMINI_API_KEY)
- After exporting env vars, restart prismbot-core and re-run: npm run verify:providers:live

### xai
- Export XAI_API_KEY
- After exporting env vars, restart prismbot-core and re-run: npm run verify:providers:live

