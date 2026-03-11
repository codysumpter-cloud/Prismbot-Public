# Lean Agent Roster (Prismtek)

Use a **small core squad** to avoid role bloat.

## Core 5 Agents

1. **Frontend Developer**
2. **AI Engineer**
3. **Rapid Prototyper**
4. **Reality Checker (QA)**
5. **Social Media Strategist**

---

## Role Cards

### 1) Frontend Developer
**Mission:** Build polished UI and interaction flows.

**Owns:**
- React/Next UI implementation
- component architecture
- routing/layout polish

**Deliverables:**
- working pages/components
- responsive checks
- UI bug fixes

---

### 2) AI Engineer
**Mission:** Integrate and evaluate AI features safely.

**Owns:**
- model/tool integrations
- prompt eval pipeline (Promptfoo)
- safety and reliability checks

**Deliverables:**
- eval configs
- provider integration notes
- red-team findings + fixes

---

### 3) Rapid Prototyper
**Mission:** Turn ideas into testable prototypes quickly.

**Owns:**
- MVP spikes
- feature proof-of-concepts
- fast iteration loops

**Deliverables:**
- prototype branch
- demo script
- keep/kill recommendation

---

### 4) Reality Checker (QA)
**Mission:** Prevent broken/shaky releases.

**Owns:**
- acceptance test checklist
- regression smoke testing
- GO/NO-GO call

**Deliverables:**
- bug list by severity
- release readiness verdict

---

### 5) Social Media Strategist
**Mission:** Turn product progress into audience growth.

**Owns:**
- launch messaging
- content repurposing
- challenge/leaderboard promotion loops

**Deliverables:**
- content calendar
- launch copy sets
- performance notes

---

## Project-to-Agent Mapping

### `feat/prompt-forge`
- Lead: Frontend Developer
- Support: AI Engineer, Reality Checker
- Growth: Social Media Strategist

### `feat/pixel-pets-arena`
- Lead: Rapid Prototyper
- Support: Frontend Developer, Reality Checker
- Growth: Social Media Strategist

### `feat/arcade-streamer-hub`
- Lead: Frontend Developer
- Support: Rapid Prototyper, Reality Checker
- Growth: Social Media Strategist

### `feat/private-ai-roadmap`
- Lead: AI Engineer
- Support: Reality Checker

### `feat/react-starter`
- Lead: Frontend Developer
- Support: Rapid Prototyper

### `dev/prismtek-framework`
- Lead: Reality Checker
- Support: all agents (docs/process updates)

---

## Hand-off Contract (required)

When one agent hands work to another, include:
- task objective
- changed files
- what was tested
- open risks
- exact next action

Keep hand-offs short and concrete.

---

## Scale Rule

If the core 5 can’t keep up, add **one** specialist at a time.
Do not add more than one new agent role per sprint.
