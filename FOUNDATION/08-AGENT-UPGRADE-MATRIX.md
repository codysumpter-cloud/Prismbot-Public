# Agent Upgrade Matrix (Applied from Referenced GitHub Repos)

This file tracks how external repos were translated into PrismBot upgrades.

## 1) promptfoo/promptfoo

### Applied
- `evals/promptfoo/promptfooconfig.yaml`
- `evals/promptfoo/promptfooredteam.yaml`
- `evals/promptfoo/prompts/prismtek-assistant.txt`
- `.github/workflows/promptfoo-evals.yml`

### Effect
- Prompt quality and safety are now testable before shipping.
- Red-team baseline added for prompt injection/PII/hallucination checks.

---

## 2) obra/superpowers

### Applied
- `FOUNDATION/06-SUPERPOWERS-STYLE-WORKFLOW.md`

### Effect
- Enforced process: clarify -> spec -> plan -> small tasks -> review -> verify -> merge.
- Reduced vibe-coding drift and large risky commits.

---

## 3) msitarzewski/agency-agents

### Applied
- `FOUNDATION/07-LEAN-AGENT-ROSTER.md`
- `BRANCH-PLAYBOOKS/*`

### Effect
- Lean 5-agent roster mapped to branches/projects.
- Clear ownership + handoff contracts.

---

## 4) reactjs/react.dev + remix-run/react-router

### Applied
- `prism-react-starter/` with router + PixelLab panel

### Effect
- Usable starter app for Prismtek project frontends.
- Better route architecture baseline for upcoming products.

---

## 5) 666ghj/MiroFish

### Applied
- Concept only (R&D inspiration)

### Effect
- Captured as later-stage simulation inspiration for Pixel Pets/arena systems.
- Not adopted as core dependency at current stage.

---

## Upgrade Policy

- Adopt patterns, not bloat.
- Keep upgrades reversible and branch-scoped.
- Prefer measurable improvements (tests/evals/checklists) over hype tooling.
