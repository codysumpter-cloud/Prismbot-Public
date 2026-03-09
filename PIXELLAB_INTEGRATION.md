# PixelLab Integration (PrismBot + prismtek.dev)

## Status

Integrated in website as **BYOK** (Bring Your Own Key):
- Users connect their own PixelLab API key in `My Account`
- Prism Creatures page gives users direct PixelLab launch + prompt template copy
- Users must accept PixelLab usage rules before key connect
- No shared global key required for end users

## Website Endpoints (prismtek.dev)

- `GET /wp-json/prismtek/v1/pixellab/status`
- `POST /wp-json/prismtek/v1/pixellab/connect`
- `POST /wp-json/prismtek/v1/pixellab/disconnect`
- `GET /wp-json/prismtek/v1/pixellab/prompt-template`

## User Flow

1. User goes to **My Account** on prismtek.dev
2. User creates PixelLab account on PixelLab site
3. User gets their own API key from PixelLab
4. User connects key to their Prismtek account (accepts usage rules)
5. User goes to Prism Creatures and uses:
   - Open PixelLab
   - Copy Creature Prompt

## PixelLab Links

- MCP docs: <https://www.pixellab.ai/mcp>
- API docs: <https://www.pixellab.ai/pixellab-api>
- Site: <https://www.pixellab.ai/>

## OpenClaw MCP server template (local agent)

Use environment variables for secrets (do not commit real keys):

```toml
[mcp_servers.pixellab]
command = "npx"
args = [
  "mcp-remote@latest",
  "https://api.pixellab.ai/mcp",
  "--transport",
  "http-only",
  "--header",
  "Authorization:${AUTH_HEADER}"
]

[mcp_servers.pixellab.env]
AUTH_HEADER = "Bearer ${PIXELLAB_API_KEY}"
```

## Security / Policy Notes

- Do **not** store shared production PixelLab keys in repo.
- Use per-user keys for user-generated content.
- Enforce/communicate PixelLab usage rules before generation.
- Keep content moderation rules aligned with PixelLab policy and prismtek.dev community policy.
