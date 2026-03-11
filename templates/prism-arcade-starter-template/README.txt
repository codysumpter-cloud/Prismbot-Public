Prism Arcade Starter Template

What this includes
- index.html
- style.css
- game.js
- score submit hook to /wp-json/prismtek/v1/scores

Upload options
1) Zip this folder and upload .zip in Arcade Games > Upload Your Game
2) Upload a single .html game directly

Rules for best compatibility
- Keep index.html at the root of your zip
- Use relative paths for assets (./images/sprite.png)
- Keep everything static (HTML/CSS/JS)
- Include mobile support (viewport + pointer/touch)

Score API example
POST /wp-json/prismtek/v1/scores
FormData fields:
- game: game slug
- score: positive integer

Notes
- If player is logged in, site uses account display name for leaderboard.
- If not logged in, score still submits as guest.
