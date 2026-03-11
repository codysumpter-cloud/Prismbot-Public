# Prismtek Professor Workbook v4 — Master Edition

Built for Prismtek: complete beginner -> independent developer -> creative studio operator.

## How to use this workbook

- Follow in order.
- Type every command yourself.
- End every session with:
  1) one commit,
  2) one note,
  3) one “what I learned” sentence.

---

## Part A — Core Builder Path (12 Weeks)

### Week 1: Terminal Confidence
- Learn: `pwd`, `ls`, `cd`, `mkdir`, `touch`, `cat`, `grep`, `find`
- Lab: create a project folder + search text across files
- Done when: you can explain each command in plain English

### Week 2: Git + GitHub Foundations
- Learn: `git init`, `status`, `add`, `commit`, `checkout -b`, `push`
- Lab: create issue -> branch -> commit -> PR
- Done when: you can ship one tiny PR alone

### Week 3: HTML + CSS Basics
- Learn: semantic HTML, flex/grid, responsive layout
- Lab: single-page portfolio shell
- Done when: looks good on phone + desktop

### Week 4: JavaScript + Node Basics
- Learn: functions, arrays/objects, async, npm
- Lab: Node script reading JSON and printing report
- Done when: useful script runs from terminal

### Week 5: React Fundamentals
- Learn: components, props, state, events
- Lab: mini prompt builder with live preview
- Done when: state updates UI correctly

### Week 6: React Router + App Structure
- Learn: routes, layouts, 404/error screens
- Lab: Home / Builder / Library / Settings
- Done when: routes + fallbacks are clean

### Week 7: Debugging + grep Mastery
- Learn: reproduce -> isolate -> minimal fix -> verify
- Lab: intentionally break and fix app
- Done when: you can debug without panic

### Week 8: Vibe Coding Discipline
- Learn: strict prompt format + small diffs + verification
- Lab: build one feature in <=3 prompts
- Done when: AI helps speed without chaos

### Week 9: Prompt Engineering + Promptfoo
- Learn: eval tests + red-team basics
- Lab: create 5 prompt tests and improve failures
- Done when: prompts are measured, not guessed

### Week 10: Pixel Asset Pipeline
- Learn: style constraints, consistency, packaging
- Lab: character pack + tileset pack + export
- Done when: assets look like same universe

### Week 11: RAG Basics
- Learn: retrieve your own docs before answering
- Lab: mini RAG over your playbooks
- Done when: answers cite your own docs

### Week 12: Ship Week
- Pick one MVP to publish:
  - Prompt Forge
  - Pixel Pets Arena
  - Arcade Hub
- Done when: live and usable by another person

---

## Part B — Project Tracks (Your Vision)

## Track 1: Prompt Forge
MVP scope:
- structured prompt worksheet
- template library
- copy/export markdown
- Promptfoo eval hooks

## Track 2: Pixel Pets Arena
MVP scope:
- create creature
- feed/train/play loop
- simple battle loop
- local leaderboard

## Track 3: Arcade Streamer Hub
MVP scope:
- retro arcade landing
- game cards + launch links
- shared leaderboard
- streamer challenge links

---

## Part C — Human-Only Actions (What I Can’t Do For You)

This section is specifically for actions requiring your direct account/device control.

### 1) Account/Auth Ownership
You must do:
- GitHub login/auth refresh (`gh auth login`)
- grant repo/org permissions
- connect OAuth sessions
- browser-based account approvals

Why: these require your identity/session and cannot be safely automated from my side.

### 2) Secrets + Credentials
You must do:
- create/store API keys and tokens
- add repo secrets in GitHub settings
- rotate leaked keys
- approve access scopes

Why: secret custody belongs to you.

### 3) Local Machine Administration
You must do:
- install/update drivers and some system dependencies
- approve firewall/network/security prompts
- manage BIOS/GPU/OS-level settings

Why: host-level privileges and hardware choices are yours.

### 4) Billing + Infrastructure Decisions
You must do:
- connect payment methods
- choose paid tiers/providers
- approve cloud spending and hardware purchases

Why: cost/risk decisions require your explicit control.

### 5) Legal/Policy/Trust Decisions
You must do:
- approve what data can be public/private
- consent to licenses/ToS/compliance requirements
- approve sensitive external communications

Why: this is owner responsibility, not automation.

### 6) Final “Go Live” Approval
You should do:
- final read-through before public launch
- approve deployment to production
- approve high-impact changes

Why: final accountability stays with you.

---

## Part D — Human-Only Skill Labs (Practice These)

## Lab H1: GitHub Auth Recovery
- Run `gh auth status`
- If broken, run `gh auth login`
- Verify `gh repo view codysumpter-cloud/PrismBot`

## Lab H2: Secrets Setup
- Add `OPENAI_API_KEY` in repo secrets
- Trigger Promptfoo CI workflow
- Confirm workflow can access secret (without printing it)

## Lab H3: Safe Deploy Checklist
- check build
- check rollback
- check monitoring
- deploy
- verify

## Lab H4: Incident Drill
- simulate failed deploy
- run rollback
- write short incident report

---

## Part E — Session Templates

### Daily 60-minute session
1. Review (10)
2. Learn (15)
3. Build (25)
4. Verify (5)
5. Commit + note (5)

### Stuck protocol
Use this exact format:

```txt
I am stuck.
Error:
Repro steps:
What I expected:
What happened:
Give me ONE next step only.
```

---

## Part F — Graduation Ladder

### Level 1 Explorer
Terminal + git basics without fear

### Level 2 Builder
Can build and ship React features with PR workflow

### Level 3 Shipper
Can deploy and maintain one live project

### Level 4 Architect
Can plan branches/issues/milestones and guide contributors

### Level 5 Studio Owner
Runs multiple project tracks with AI + quality systems

---

## Final Promise

You don’t need to be a genius to become a developer.
You need reps, structure, and shipping discipline.

You bring the consistency.
I’ll bring the map.
