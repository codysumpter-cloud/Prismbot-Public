# Superpowers-Style Workflow (Prismtek Edition)

Use this process for every meaningful feature so vibe coding stays disciplined.

## Core Loop

1. **Clarify Goal**
2. **Write Spec**
3. **Plan Tasks**
4. **Build in Small Steps**
5. **Review + Verify**
6. **Merge + Document**

---

## 1) Clarify Goal (Scout lane)

Output (max 120-180 tokens):
- Problem
- Desired outcome
- Constraints
- Risks/unknowns

Template:

```txt
Restate this request as a clear implementation target.
Return only:
1) Goal
2) Required inputs
3) Constraints
4) Unknowns
5) Next 3 actions
```

---

## 2) Write Spec (before coding)

Every feature gets a short spec markdown file in:

- `PROJECTS/<project>/specs/<feature>.md`

Spec format:
- Problem statement
- User story
- Scope (in/out)
- Acceptance criteria
- Rollback plan

---

## 3) Plan Tasks (Builder prep)

Break spec into tasks that can be done in 15-45 minutes.

Each task must include:
- exact file paths
- expected change
- verification command
- done condition

Task template:

```md
## Task: <name>
- Files: <path list>
- Change: <what to implement>
- Verify: <command/test>
- Done when: <observable condition>
```

---

## 4) Build in Small Steps (Builder lane)

Rules:
- one task at a time
- run checks after each task
- commit small and often
- no giant “all-in-one” changes

Commit style:
- `feat: ...`
- `fix: ...`
- `docs: ...`
- `chore: ...`

---

## 5) Review + Verify (Judge lane)

Before merge, run:
- requirement match check
- code quality review
- security/privacy sanity pass
- regression check

Judge output format:
- GO / NO-GO
- blocking issues first
- recommended minimal fixes

---

## 6) Merge + Document

Merge only when:
- checks pass
- acceptance criteria met
- rollback path exists

Then update:
- project README (what changed)
- changelog / roadmap
- next milestone tasks

---

## Branch Mapping

- `dev/prismtek-framework` → process/docs
- `feat/prompt-forge` → prompt builder product
- `feat/pixel-pets-arena` → creature game
- `feat/arcade-streamer-hub` → arcade + leaderboard
- `feat/private-ai-roadmap` → private AI stack
- `feat/react-starter` → starter architecture

---

## Anti-Chaos Rules

- No coding before spec for medium/large features.
- No merge without explicit verification evidence.
- If a task drifts, stop and re-scope.
- Prefer reversible changes over clever changes.

This keeps you fast *and* sane.
