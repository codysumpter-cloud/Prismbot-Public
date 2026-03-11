# Prismtek Prompt Playbook v2

Built for your real workflow: **pixel art brand + game asset pipeline + OpenClaw orchestration**.

---

## 0) Default Prompt Stack (Prismtek Mode)

Use this order every time:

1. **Intent** — what outcome you want
2. **Artifact** — what file/output should be produced
3. **Constraints** — style, size, platform, deadline
4. **Quality bar** — examples + reject conditions
5. **Execution mode** — scout/builder/judge lanes
6. **Stop rule** — when result is “done enough”

Template:

```txt
Goal: [OUTCOME]
Output artifact: [FILE/POST/ASSET TYPE]
Context: [PROJECT + TARGET USER]
Constraints:
- [hard rule 1]
- [hard rule 2]
- [hard rule 3]
Quality bar:
- Must: [acceptance criteria]
- Reject if: [failure criteria]
Execution mode:
- Scout: [cheap model task]
- Builder: [mid model task]
- Judge: [final pass task]
Stop when: [clear done condition]
```

---

## 1) 3-Lane Prompting (Token-Smart)

### A) Scout Lane Prompt (cheap)

```txt
Task: Triage this request into an execution plan.
Return only:
1) Goal (1 line)
2) Required inputs
3) Risks/unknowns
4) Minimal next actions (max 5 bullets)
Keep output under 120 tokens.
```

### B) Builder Lane Prompt (mid)

```txt
Implement the plan.
Rules:
- Keep changes minimal and reversible
- Produce concrete artifact(s)
- No extra commentary
Return:
1) What changed
2) Output artifact
3) Verification steps
4) Open issues (if any)
```

### C) Judge Lane Prompt (best)

```txt
Review the builder output for quality and drift.
Check:
- Requirement match
- Brand/tone match
- Technical correctness
- Safety and rollback path
Return final polished output plus a go/no-go verdict.
```

---

## 2) PixelLab Asset Prompts (Production)

### Character Pack Prompt

```txt
Create a production-ready pixel character pack.
Character: [DESCRIPTION]
Use:
- n_directions: 8
- size: 48
- outline: single color black outline
- shading: detailed shading
- detail: high detail
- view: low top-down
Output:
- character_id
- preview links
- zip download link
Success criteria:
- silhouette readable at 1x
- direction consistency
- no merged limbs at diagonals
```

### Animation Batch Prompt

```txt
For character_id [ID], queue animations:
- breathing-idle
- walk
- running-6-frames
- fireball
Name each animation with prefix: [PROJECT_TAG]
Return job IDs + a polling checklist.
```

### Tileset Chain Prompt (connected worlds)

```txt
Generate linked top-down tilesets:
1) ocean -> beach
2) beach -> grass (reuse base tile id from #1)
3) grass -> stone (reuse base tile id from #2)
Tile size: 16
Style: medium detail, basic shading
Return IDs + dependency map.
```

---

## 3) Prismtek Website / Ad Copy Prompts

### Hero Section Prompt (site)

```txt
Write 3 homepage hero variants for Prismtek.
Brand: pixel art + indie game dev + AI creativity.
Each variant includes:
- Headline (max 8 words)
- Subheadline (max 18 words)
- Primary CTA
- Secondary CTA
Constraints:
- energetic but not cringe
- no generic "innovative solution" language
```

### 15-Second Ad Prompt

```txt
Generate 5 short-form ad scripts (15 sec) for [PRODUCT].
Audience: indie game devs/pixel artists.
Format:
- 0-2s hook
- 2-10s value + visual beat list
- 10-15s CTA
Tone: bold, playful, builder-first.
```

### Offer Angle Matrix Prompt

```txt
Build a 3x3 ad matrix:
Rows = hook types (pain, aspiration, curiosity)
Cols = offer types (speed, quality, control)
For each cell: one ad concept + expected objection.
```

---

## 4) Vibe Coding Prompts (Shippable)

### Feature Build Prompt

```txt
Build [FEATURE] for [APP].
Stack: [STACK]
Must-have:
- [req1]
- [req2]
- [req3]
Constraints:
- minimal deps
- readable code over clever code
- include tests or verification commands
Return:
1) file tree
2) code by file
3) run/test commands
4) rollback notes
```

### Bug Hunt Prompt

```txt
Diagnose and fix this bug:
[SYMPTOMS]
Return exactly:
- root cause
- minimal diff
- verification checklist
- edge cases
Do not refactor unrelated code.
```

### “Ship It” Prompt

```txt
Perform release readiness check.
Validate:
- functionality
- performance sanity
- error handling
- docs updated
Return GO/NO-GO with reasons and the smallest required fixes.
```

---

## 5) ASCII / Terminal Art (v2)

### Animated Banner Prompt

```txt
Create a 2-frame animated ASCII banner for "Prismtek Pixel Gods".
Max width: 64 chars
Max height: 16 lines
Style: cyber-mythic pixel energy
Return:
1) frame_1
2) frame_2
3) bash loop script
Keep frames stable to avoid jitter.
```

### ANSI Color Prompt

```txt
Add ANSI color accents to this ASCII art.
Rules:
- keep a plain fallback version
- only standard ANSI codes
- no unreadable contrast
Return plain + colored + one-line print command.
```

---

## 6) Prompt QA Checklist (Use before sending)

- Is the output artifact explicitly named?
- Did you set hard constraints (length, format, style)?
- Did you include reject conditions?
- Is there a clear done condition?
- Is this task routed to the right lane?

If 2+ answers are “no,” rewrite prompt before running.

---

## 7) Anti-Drift / Anti-Bloat Rules

- Ask for **structured outputs** (JSON, bullets, checklists) first.
- Use **max count caps** (e.g., max 5 concepts, max 120 tokens for triage).
- For multi-step work: require **phase outputs** (plan -> build -> review).
- For memory-heavy tasks: summarize to durable notes, don’t dump transcript.

---

## 8) Power Commands (copy/paste starters)

### "Give me options, not essays"

```txt
Return 3 options, each <=80 words, with a clear best recommendation.
```

### "Tighter output"

```txt
Rewrite this output to 40% length without losing decision-critical info.
```

### "Production polish"

```txt
Take this draft and make it production-ready: cleaner language, sharper CTA, zero fluff.
```

---

## 9) Daily 10-Minute Skill Loop

1. Pick one real task (ad, code, asset)
2. Run scout prompt
3. Run builder prompt
4. Run judge prompt
5. Save the winning prompt variant

Repeat daily; optimize prompt templates weekly.
