# Prismtek Private AI Roadmap (12 Months)

Privacy-first path to build your own assistant + pixel art model stack without wasting money.

## North Star
Build a private, high-quality AI stack for:
- coding/vibe development
- prompt tooling
- pixel art/game asset generation
- long-term model ownership

---

## Phase 1 — Foundation (Month 1-2)

### Objectives
- Make local/private inference the default.
- Start collecting clean training/eval data.
- Define data/privacy policy.

### Tasks
- [ ] Create dataset structure:
  - `data/prompts/`
  - `data/outputs/`
  - `data/ratings/`
  - `data/pixel-style/`
- [ ] Add a lightweight rating rubric (1-5) for every output.
- [ ] Log prompt -> output -> score -> notes for all important runs.
- [ ] Define privacy tiers:
  - `private-only`
  - `safe-to-share`
  - `public`

### Exit Criteria
- At least 300 rated prompt/output pairs.
- Clear data labeling policy in repo.

---

## Phase 2 — Personal Assistant Specialization (Month 3-4)

### Objectives
- Make assistant outputs match Prismtek style and workflow.
- Improve consistency with retrieval before heavy fine-tuning.

### Tasks
- [ ] Choose base open model for local coding/assistant use.
- [ ] Add RAG over playbooks/docs/project notes.
- [ ] Build eval suite (fixed prompts) for weekly quality checks.
- [ ] Train first LoRA/adapter on high-rated examples.

### Exit Criteria
- Assistant quality improves on eval set by >=20% (subjective rubric).
- Stable output format adherence.

---

## Phase 3 — Pixel Model Specialization (Month 5-7)

### Objectives
- Build custom pixel style generation capability.
- Reduce prompt micromanagement for consistent art style.

### Tasks
- [ ] Curate pixel datasets by category:
  - characters
  - tilesets
  - UI icons
  - animations
- [ ] Tag style metadata (outline, shading, detail, angle, palette).
- [ ] Train and compare LoRA variants.
- [ ] Keep fixed benchmark prompts to track drift.

### Exit Criteria
- Visual consistency clearly improved across benchmark prompts.
- Usable outputs for production asset pipeline.

---

## Phase 4 — Production Asset Pipeline (Month 8-9)

### Objectives
- Convert model capability into a repeatable pipeline.
- Minimize manual cleanup time.

### Tasks
- [ ] Build internal flow: prompt -> generate -> review -> approve -> export.
- [ ] Add quality checks:
  - silhouette readability
  - palette consistency
  - tileset seam checks
- [ ] Export packs in game-ready folder structure.

### Exit Criteria
- One complete game-ready asset pack produced end-to-end.

---

## Phase 5 — Platform + Open Source Flywheel (Month 10-12)

### Objectives
- Ship public-facing products while preserving private edge.
- Open-source non-sensitive tooling.

### Tasks
- [ ] Launch portfolio + arcade updates using the pipeline.
- [ ] Open-source selected utilities (prompt tools, eval scripts).
- [ ] Keep private assets private (best datasets/adapters/scoring heuristics).
- [ ] Build contributor docs and governance rules.

### Exit Criteria
- Public repo activity + shipped product updates + private model advantage retained.

---

## Hardware/Cost Guardrails

- Do NOT scale hardware before eval pipeline is stable.
- Prefer LoRA/adapters over full-model training until justified.
- Track GPU cost per improvement cycle.

---

## Weekly Cadence

- Monday: prioritize experiments
- Midweek: run experiments + collect ratings
- Friday: eval + compare + commit + notes
- Sunday: roadmap review + next sprint scope

---

## Anti-Drift Rules

- Keep benchmark prompt set constant.
- Never train on unlabeled/noisy outputs.
- Separate private vs public data from day one.
- Always keep rollback checkpoints for model/pipeline changes.
