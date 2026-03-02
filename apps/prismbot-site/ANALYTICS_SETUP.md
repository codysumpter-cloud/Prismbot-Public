# Analytics Setup (PrismBot Site)

The site ships with both Plausible and GA4 placeholders.

## 1) Plausible (recommended lightweight)

In `index.html`, update:
- `data-domain="prismbot.ai"` to your real domain

If you do not use Plausible, remove that script tag.

## 2) Google Analytics 4

In `index.html`, replace both instances of:
- `G-XXXXXXXXXX`
with your GA4 Measurement ID.

If you do not use GA4, remove the two GA snippets.

## Tracked Events

- `waitlist_signup`
  - Fired on successful waitlist form submit
  - Plausible custom event: `waitlist_signup`
  - GA4 event: `waitlist_signup` with `{ method: 'formspree' }`

## Privacy Tip

Use either Plausible or GA4 to keep analytics simple. Plausible is privacy-friendly and easier to maintain.
