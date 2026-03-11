# Prismtek Prompt Playbook

A practical prompt system for ads, pixel art, and vibe coding.

## Core Prompt Formula (use every time)

1. **Role** – who the AI is
2. **Goal** – what you want made
3. **Context** – audience/product/platform
4. **Constraints** – limits, style rules, deadlines
5. **Output format** – exactly how to return work
6. **Quality bar** – examples / what “good” means

Template:

```txt
You are [ROLE].
Goal: [WHAT TO PRODUCE].
Context: [PROJECT / AUDIENCE / PLATFORM].
Constraints: [LENGTH, STYLE, TOOLS, DEADLINE, DO-NOTS].
Output format: [EXACT STRUCTURE].
Quality bar: [EXAMPLES / SUCCESS CRITERIA].
Ask up to [N] clarifying questions only if needed.
```

---

## Ad / Marketing Prompts

### 1) Ad Concept Sprint

```txt
You are a direct-response creative strategist.
Create 10 ad concepts for [PRODUCT] targeting [AUDIENCE].
Tone: [VIBE]. Platform: [TikTok/IG/YouTube/X].
Each concept must include:
- Hook (first 2 seconds)
- Headline
- Core message
- CTA
- 10-second shot list
Constraints:
- Avoid: [THINGS YOU HATE]
- Keep language at [grade level]
- No generic buzzwords
Output as numbered bullets.
```

### 2) UGC-style Script Generator

```txt
Write 5 UGC-style ad scripts for [PRODUCT].
Audience: [AUDIENCE].
Length: 20–30 seconds each.
Style: authentic, not corporate.
Each script format:
1) Pattern interrupt
2) Problem
3) Product moment
4) Benefit proof
5) CTA
Include 2 alternate hooks per script.
```

### 3) Creative Testing Matrix

```txt
Build a creative testing matrix for [PRODUCT].
Return 3x3 grid:
- 3 hook angles
- 3 offer angles
For each combo include one-line script concept and expected audience reaction.
```

---

## Pixel Art / Game Asset Prompts

### 1) Character Prompt (PixelLab-ready)

```txt
Create a pixel-art character.
Description: [CHARACTER].
Style constraints:
- Directions: 8
- Canvas: 48x48
- Outline: single color black outline
- Shading: detailed shading
- Detail: high detail
- View: low top-down
Aesthetic: [RETRO/SNES/GBA/NEON/etc].
```

### 2) Tileset Prompt

```txt
Create a top-down tileset transition.
Lower terrain: [LOWER]
Upper terrain: [UPPER]
Transition feel: [MOSSY/SANDY/WET/etc]
Tile size: 16 or 32
Style: [OUTLINE/SHADING/DETAIL]
Keep strong readability in gameplay.
```

### 3) Animation Prompt

```txt
Animate this character with [ANIMATION TYPE].
Motion style: [SNAPPY/FLOATY/HEAVY].
Keep silhouette readable at 1x scale.
No unnecessary secondary motion.
```

---

## ASCII / Terminal Art Prompts

### 1) Animated ASCII Banner Prompt

```txt
You are an ASCII animation artist.
Create a 2-frame looping ASCII banner for: [THEME].
Style: [RETRO/CYBER/FANTASY], width max [N] chars, height max [N] lines.
Include only monospaced-safe characters.
Return:
1) frame_1
2) frame_2
3) bash playback snippet using clear + sleep
Constraints:
- readable at default terminal size
- no broken line wrapping
- avoid Unicode unless explicitly requested
```

### 2) Logo-to-ASCII Prompt

```txt
Convert this brand concept into terminal-safe ASCII logo text.
Brand: [NAME]
Mood: [MOOD]
Use 3 variants:
- compact
- medium
- hero banner
Return in fenced code blocks only.
```

### 3) ASCII Sprite Sheet Prompt

```txt
Generate ASCII sprite frames for [CHARACTER] with [ACTION].
Frames: [N]
Max frame size: [W]x[H]
Each frame must keep consistent anchor point.
Return as:
FRAME 1
<ascii>
FRAME 2
<ascii>
...
```

### 4) ANSI Color Prompt (optional)

```txt
Create ANSI-colored ASCII art for terminal.
Theme: [THEME]
Use only standard ANSI escape sequences.
Return:
- plain ASCII version
- ANSI-colored version
- one-line command to print it safely in bash
```

---

## Vibe Coding Prompts

### 1) Feature Builder Prompt

```txt
You are a senior [STACK] engineer.
Build: [FEATURE].
Project context: [APP + PURPOSE].
Requirements:
- [REQ 1]
- [REQ 2]
- [REQ 3]
Constraints:
- Keep dependencies minimal
- Code must be production-clean
- Include error handling and loading states
Return exactly:
1) File tree
2) Full code by file
3) Run commands
4) Quick test plan
5) Risks / tradeoffs
```

### 2) Bug Fix Prompt

```txt
You are a debugging specialist.
Issue: [BUG + SYMPTOMS].
Stack: [STACK].
Repro steps: [STEPS].
Return:
1) Root cause hypothesis
2) Minimal patch
3) Why it works
4) Regression checks
Do not rewrite unrelated files.
```

### 3) Refactor Prompt

```txt
Refactor this code for readability and maintainability without changing behavior.
Rules:
- No feature changes
- Keep API stable
- Add comments only where logic is non-obvious
Return unified diff + summary of improvements.
```

---

## Prompt Upgrade Moves (Instant Quality Boost)

- Ask for **3 variants**, pick best, then iterate.
- Say what to **avoid** (generic words, too salesy, etc).
- Force structure: “Return JSON / checklist / bullets.”
- Add target metric: CTR, watch time, conversion, load time.
- End with: “If uncertain, state assumptions explicitly.”

---

## One-Command Meta Prompt (for any task)

```txt
Before answering:
1) Restate goal in one sentence.
2) List assumptions.
3) Produce draft output.
4) Critique it against constraints.
5) Produce improved final output.
Keep it concise.
```

---

## 15-Minute Practice Loop

1. Pick one real task.
2. Write prompt with formula.
3. Generate v1.
4. Tighten constraints.
5. Generate v2.
6. Compare and save best prompt.

Do this daily and your prompting skill climbs fast.
