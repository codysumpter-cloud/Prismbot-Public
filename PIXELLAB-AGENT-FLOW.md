# PixelLab Agent Flow (PrismBot)

Use PixelLab via MCP (hosted, no local GPU needed).

## Current backend

- MCP endpoint: `https://api.pixellab.ai/mcp`
- Transport: HTTP MCP
- Driver CLI: `mcporter`

---

## Quick commands

### 1) Create a character (8 directions)

```bash
mcporter call https://api.pixellab.ai/mcp.create_character \
  description="cute wizard with purple robe and glowing staff" \
  n_directions=8 \
  size=48 \
  --output json
```

### 2) Check character status

```bash
mcporter call https://api.pixellab.ai/mcp.get_character \
  character_id=<CHARACTER_ID> \
  --output json
```

### 3) Animate a character

```bash
mcporter call https://api.pixellab.ai/mcp.animate_character \
  character_id=<CHARACTER_ID> \
  template_animation_id=walk \
  animation_name="walk" \
  --output json
```

### 4) Create an isometric tile

```bash
mcporter call https://api.pixellab.ai/mcp.create_isometric_tile \
  description="grass block with dirt sides" \
  size=32 \
  tile_shape=block \
  --output json
```

### 5) Check isometric tile status

```bash
mcporter call https://api.pixellab.ai/mcp.get_isometric_tile \
  tile_id=<TILE_ID> \
  --output json
```

### 6) Create top-down tileset

```bash
mcporter call https://api.pixellab.ai/mcp.create_topdown_tileset \
  lower_description="ocean water" \
  upper_description="sandy beach" \
  transition_size=0.25 \
  --output json
```

### 7) Check top-down tileset status

```bash
mcporter call https://api.pixellab.ai/mcp.get_topdown_tileset \
  tileset_id=<TILESET_ID> \
  --output json
```

---

## Recommended PrismBot routing

- Character requests → `create_character`, then `get_character`
- Animation requests → `animate_character`, then `get_character`
- Isometric object/tile requests → `create_isometric_tile`, then `get_isometric_tile`
- Terrain tileset requests → `create_topdown_tileset` / `create_sidescroller_tileset`, then corresponding `get_*`

---

## Notes

- These jobs are asynchronous. Always queue first, then poll with `get_*`.
- Poll every 20-45 seconds to avoid spam.
- Keep prompts focused on sprite subject/style; avoid background scene prose.
