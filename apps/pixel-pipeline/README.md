# Pixel Pipeline (Image + Animation Production)

Goal: compete on image/animation output quality and speed by combining AI generation + LibreSprite polish + product packaging.

## What this does

1. Generate concept batches (external generator / PixelLab-style tools)
2. Run a LibreSprite polish checklist
3. Build animation sets (idle/walk/attack/hit/death)
4. Export spritesheets + individual frames
5. Package a sellable asset pack (zip + previews + license/readme)

## Quick Start

```bash
cd apps/pixel-pipeline
./scripts/make-pack.sh "necromancer enemies" 32 8
```

## Output Structure

- `output/<pack-id>/raw/` - generated source frames
- `output/<pack-id>/polished/` - cleaned/palette-fixed assets
- `output/<pack-id>/sheets/` - sprite sheets
- `output/<pack-id>/preview/` - gif/png previews
- `output/<pack-id>/release/` - final zip payload

## Notes
- LibreSprite is installed and available as `libresprite`.
- For now, generation is operator-guided; automation hooks are scaffolded.
