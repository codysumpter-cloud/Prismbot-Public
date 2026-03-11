# Promptfoo Starter (PrismBot)

This folder adds a practical Promptfoo baseline so you can evaluate prompt quality/safety before shipping.

## Files

- `promptfooconfig.yaml` — standard eval checks (format, uncertainty behavior, secret refusal)
- `promptfooredteam.yaml` — starter red-team scan
- `prompts/prismtek-assistant.txt` — test prompt template

## Quick start

```bash
cd evals/promptfoo
export OPENAI_API_KEY=sk-...

# Run baseline eval suite
npx promptfoo@latest eval -c promptfooconfig.yaml
npx promptfoo@latest view

# Run red-team scan
npx promptfoo@latest redteam run -c promptfooredteam.yaml
```

## Local-only option

If you have Ollama running, `promptfooconfig.yaml` also includes `ollama:llama3.2:3b`.

## CI recommendation

Run baseline evals on PRs touching prompt/config files, and fail PR if eval score drops below your threshold.
