# Prismtek Professor Workbook v2 (Noob-to-Builder Edition)

**Built for you:** complete beginner, big vision, pixel-art/game passion, wants to ship independently.

---

## 0) Your Mission (Why this exists)

You want to:
- build cool apps and games independently
- use AI/vibe coding without becoming dependent on it
- organize ideas in GitHub
- launch a pixel-art portfolio + arcade site
- eventually build private AI systems for privacy/control

This workbook turns that into a step-by-step path.

---

## 1) How to Learn Without Burning Out

### The 60-minute session
1. **10 min**: review yesterday notes
2. **25 min**: build one tiny thing
3. **15 min**: debug + fix
4. **10 min**: commit + write lesson learned

### Rules
- Never skip the commit.
- Never end session without a note.
- One tiny win per day > one giant chaotic sprint.

### Your daily win definition
- "I made one thing work and pushed it."

---

## 2) Beginner Survival Glossary (Plain English)

- **Terminal/Bash**: text control panel for your computer.
- **Git**: time machine for your code.
- **GitHub**: cloud home for your code + collaboration.
- **Repo**: project folder tracked by Git.
- **Branch**: safe side-lane for changes.
- **Commit**: saved checkpoint.
- **PR (Pull Request)**: "please merge my lane into main."
- **Issue**: a task card.
- **Node.js**: lets JavaScript run outside browser.
- **npm**: app store for JavaScript packages.
- **React**: UI LEGO system.
- **Next.js**: React with batteries included.
- **RAG**: let AI look up your docs before answering.
- **grep**: text search sniper.

---

## 3) Week-by-Week Learning Roadmap (12 Weeks)

## Week 1 — Terminal + Files + Confidence

### Goals
- move around files without fear
- create/edit/delete safely
- search with grep

### Commands to master
`pwd`, `ls`, `cd`, `mkdir`, `touch`, `cp`, `mv`, `cat`, `grep`, `find`

### Drill
- create `learning/week1/`
- make 5 files
- use `grep` to find a keyword across all files

### Done when
- you can explain each command in your own words.

---

## Week 2 — Git/GitHub Core Loop

### Goals
- commit confidently
- use branches
- open PR and merge

### Command sequence
```bash
git status
git checkout -b feat/my-first-change
git add .
git commit -m "feat: my first change"
git push -u origin feat/my-first-change
```

### Drill
- create one issue
- solve it on a branch
- open PR

### Done when
- you can ship one tiny PR without help.

---

## Week 3 — HTML/CSS Foundations

### Goals
- build a clean webpage
- make it mobile-friendly

### Build
- one-page portfolio shell
- sections: Hero, Projects, About, Contact

### Done when
- page works on phone + desktop.

---

## Week 4 — JavaScript + Node Basics

### Goals
- understand variables, loops, functions
- run scripts with Node

### Build
- script that reads JSON and prints formatted summary.

### Done when
- you can run one useful script from terminal.

---

## Week 5 — React Basics

### Goals
- components, props, state
- handle forms/events

### Build
- Prompt mini-builder with live preview

### Done when
- changing inputs updates preview instantly.

---

## Week 6 — React Router + App Structure

### Goals
- multi-page app architecture
- nav + 404 + layout

### Build
- pages: Home / Prompt Forge / Pixel Lab / Settings

### Done when
- all routes work and bad routes show 404.

---

## Week 7 — Debugging + grep Mastery

### Goals
- stop guessing, debug systematically

### Debug loop
1. reproduce
2. isolate
3. minimal fix
4. verify
5. commit

### Drill
- intentionally break app
- fix using logs + grep

---

## Week 8 — Vibe Coding Correctly

### Goal
use AI as power tool, not crutch.

### Prompt format
```txt
Role:
Goal:
Context:
Constraints:
Output format:
Done condition:
```

### Rule
- ask for **small diffs** not giant rewrites.

---

## Week 9 — Prompt Engineering + Promptfoo

### Goals
- test prompts with evidence
- compare outputs

### Build
- 5 eval tests
- 1 red-team test

### Done when
- you can fail a bad prompt and fix it intentionally.

---

## Week 10 — Pixel AI Pipeline

### Goals
- repeatable character/tileset workflow
- style consistency

### Build
- one character pack (8 directions)
- one tileset pack
- one export bundle

### Done when
- outputs look like same game world.

---

## Week 11 — RAG Basics

### Goals
- AI answers from your docs, not random memory

### Build
- mini RAG over your playbooks
- ask: "what is our branch policy?"

### Done when
- answers cite your own docs.

---

## Week 12 — Ship Week

### Goals
- publish one real project
- collect user feedback

### Choose one
- Prompt Forge MVP
- Pixel Pets Arena prototype
- Arcade Hub alpha

### Done when
- project is live and usable by someone besides you.

---

## 4) Your Build Tracks (Tailored)

## Track A — Prompt Forge (fastest to launch)

### MVP
- prompt worksheet form
- template library
- copy/export markdown
- eval checks

### Stretch
- shareable prompt links
- favorites and tags

---

## Track B — Pixel Pets Arena (dream game)

### MVP
- creature create
- feed/train/play actions
- simple battle loop
- local leaderboard

### Stretch
- evolutions
- seasons
- streamer challenge mode

---

## Track C — Arcade Hub (brand + portfolio)

### MVP
- arcade landing page
- playable game cards
- score leaderboard
- profile/project links

### Stretch
- Twitch/stream overlays
- weekly challenge board

---

## 5) GitHub System You’ll Use Forever

## Repo hygiene checklist
- README with setup + screenshots
- ROADMAP.md
- issue templates
- milestone labels
- changelog notes

## Branch strategy
- `main` stable
- `feat/...` active work
- small PRs only

## Definition of done (always)
- works locally
- verified
- documented
- committed
- pushed

---

## 6) “I’m Stuck” Playbook

When stuck, do exactly this:
1. write current error message
2. copy minimal reproducible steps
3. run `git status`
4. ask for smallest next step
5. do not jump to new tools until current bug is isolated

Prompt to use:
```txt
I am stuck. Here is exact error:
<error>
Repro steps:
1)
2)
3)
Give me ONE next step only.
```

---

## 7) Vibe Coding Safety Rules

- never paste API secrets in prompts
- never merge code you didn’t run
- always request verification commands
- one feature per prompt thread
- if output is vague, ask for stricter format

---

## 8) Private AI Path (Practical)

### First
- collect rated prompts/outputs
- build eval benchmarks

### Then
- small adapter training (LoRA)
- compare quality/cost

### Later
- bigger private model stack if justified by results

Rule: **evaluate first, scale second**.

---

## 9) Weekend Bootcamp (When you’re free)

## Saturday
- Session 1: bash + git drills
- Session 2: React starter edits
- Session 3: first PR + merge

## Sunday
- Session 1: Prompt Forge mini feature
- Session 2: Pixel asset test flow
- Session 3: roadmap + next week tasks

---

## 10) Professor Mode: How I’ll Coach You

I will:
- explain beginner-first, then advanced
- break big goals into tiny tasks
- give one exact next step during debugging
- keep you shipping, not just studying

You will:
- run commands
- ask when confused
- commit after each tiny win

---

## 11) Milestone Ladder (Your Progress Levels)

### Level 1 — Explorer
- can use terminal and git basics

### Level 2 — Builder
- can make a React feature and push PR

### Level 3 — Shipper
- can deploy one project and maintain it

### Level 4 — Architect
- can plan branches/issues and guide contributors

### Level 5 — Studio Owner
- can run multiple project tracks + AI workflow with confidence

---

## 12) Your Next Action (right now)

When you get home, run:
1. `bash scripts/prismbot-upgrade-check.sh`
2. `bash scripts/phase2-open-issues.sh codysumpter-cloud/PrismBot` (after gh auth)
3. pick one issue and ship the first tiny win

That’s it. Don’t overthink. Build.
