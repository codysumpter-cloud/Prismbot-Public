# Public Repo Branching Model

## Branches

- `main` — stable, sanitized public source of truth
- `public/dev` — staging branch for upcoming public updates
- `docs/*` — documentation-focused work
- `examples/*` — tutorials/sample projects
- `release/*` — release prep branches

## Rules

1. Do not mirror private operational artifacts to public.
2. Merge to `main` only from reviewed public branches.
3. Keep sensitive/internal features private-repo only.
4. Use small PRs and clear commit messages.

## Suggested Flow

private repo -> sanitize -> `public/dev` -> review -> `main`
