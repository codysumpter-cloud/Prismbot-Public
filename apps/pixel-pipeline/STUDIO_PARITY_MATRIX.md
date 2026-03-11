# Prism Studio vs PixelLab — Parity+ Plan (2026-03-03)

## Goal
Deliver a Studio that covers PixelLab capability end-to-end and adds stronger workflow reliability, packaging, and automation.

## What PixelLab exposes (publicly discoverable)

### Product surfaces
- Web creator (`/create`)
- Character creator (`/create-character`)
- In-browser editor (`/editor`, Pixelorama-based)
- Aseprite extension flow
- API offering (`/pixellab-api`)
- MCP / AI coding integrations (`/mcp`)

### Core generation/editing capabilities
- Create image (Pixflux / Bitforge)
- Inpaint / true inpainting / edit image
- Rotate (4/8 directional views + perspective handling)
- Animate with text
- Animate with skeleton + skeleton estimation
- Character generation + animation variants
- Map/tileset generation (top-down, side-scroller, isometric)
- Texture creation
- UI element generation
- Remove background, resize, unzoom, reduce colors
- Style consistency / style-reference generation

### Common control patterns
- Prompt + optional init image
- Seed
- Palette controls
- View/direction/camera controls
- Output method controls
- Tier-based limits by canvas size/area

## Current Prism Studio state (repo reality)
- `/app/studio` currently shows a status panel and JSON output, not a full editor workflow.
- `apps/pixel-pipeline` contains scaffold scripts/prompts/templates and packaging flow.
- LibreSprite is installed and intended for polish/export pipeline, but no full user-facing Studio UX is wired.

## Gap summary
1. Missing full Studio UX (canvas/editor, job queue, history, assets browser).
2. Missing first-class tool actions in web UI (generate/edit/animate/map/ui pipeline controls).
3. Missing integrated model orchestration and presets across tool families.
4. Missing parity validation suite to prove output/functionality against PixelLab flows.

## Build order (parity first, then better)

### Phase A — Full Studio Shell (foundation)
- Studio workspace layout: left tool rail, center canvas/preview, right settings, bottom jobs/history.
- Project state model: assets, versions, seeds, prompts, outputs.
- Auth + billing surfacing in Studio context.

### Phase B — Parity Tool Blocks
- Image create + edit/inpaint
- Character + directional rotate
- Animate (text + skeleton)
- Map/tileset/isometric
- UI elements + utility tools (bg remove, resize, palette/reduce)

### Phase C — Better-than baseline
- Reproducible recipes (saved pipelines with deterministic replay)
- Pack builder one-click (spritesheet + previews + zip + manifest)
- Cross-tool consistency engine (shared style profiles across all tools)
- Batch job queue with resumable runs + failure recovery

### Phase D — Validation
- Golden test prompts for each tool family
- Output quality checks (size, transparency, palette constraints)
- Performance + reliability checks under concurrent jobs

## Immediate implementation target (next commit)
1. Replace Studio stub panel in `prismbot-core` with a real Studio app shell page.
2. Wire first interactive flow: Create Image + job tracking + output gallery.
3. Add parity checklist endpoint so progress is measurable.

## Implementation status (live)
- ✅ Phase A shell: `/app/studio` workspace layout with jobs, gallery, parity pane.
- ✅ Phase B baseline: pack scaffold jobs + generate image jobs.
- ✅ Phase C partial: edit/inpaint job path and style-profile scaffolding.
- ✅ Phase D validation: parity status endpoint + `scripts/validate-studio.sh`.

## Known limitation (explicit)
- Style-reference generation is currently scaffolded as a profile artifact. True multi-reference, image-conditioned style transfer in one call is pending dedicated backend support/model path; UI and endpoint expose this transparently and do not claim full completion.

## Source URLs audited
- `https://www.pixellab.ai/`
- `https://www.pixellab.ai/docs`
- `https://www.pixellab.ai/docs/ways-to-use-pixellab`
- `https://www.pixellab.ai/docs/getting-started`
- `https://www.pixellab.ai/docs/tools/animate-with-text-pro`
- `https://www.pixellab.ai/docs/tools/rotate`
- `https://www.pixellab.ai/docs/tools/consistent-style`
- `https://www.pixellab.ai/docs/tools/create-map`
- `https://www.pixellab.ai/docs/tools/create-ui-elements-pro`
- `https://www.pixellab.ai/pixellab-api`
- `https://www.pixellab.ai/mcp`
- plus internal crawl of 76 discoverable public routes under `www.pixellab.ai`
