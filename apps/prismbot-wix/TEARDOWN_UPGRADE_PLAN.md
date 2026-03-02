# PrismBot Wix — Focused Teardown & Upgrade Plan

## Executive teardown (current state)

### What we found
- The imported Wix repo is mostly **template scaffolding**.
- Page code files are mostly placeholder `onReady()` stubs.
- Many pages are generic ecommerce/legal/template pages not aligned with PrismBot’s current conversion goal.

### Core flaw categories
1. **Positioning drift**: generic “AI lab” style language instead of execution-first offer.
2. **Conversion dilution**: too many template pages distract from waitlist + booked call outcomes.
3. **Proof gap**: weak social proof/results sections.
4. **Funnel gap**: no clear single primary CTA path and follow-up flow.
5. **Ops gap**: no documented publish QA cadence for rapid iteration.

---

## Focused cleanup pass (safe)

Because Wix page-code filenames map to live pages, we avoid deleting code files directly in git first.

### Keep / prioritize
- Home page (core conversion page)
- Privacy Policy
- Terms & Conditions
- Thank You Page

### De-prioritize / archive in Wix Editor (not hard-delete in git yet)
- Portfolio
- Project pages
- Collection pages
- Product Page
- Category Page
- Cart / Side Cart / Checkout / My Orders / Search pages
- Accessibility/Refund/Shipping pages unless needed by policy stack

> Action rule: unpublish/hide pages in Wix Editor first; delete permanently after two release cycles if not used.

---

## Upgrade architecture (Phase W1 → W3)

## W1: Conversion baseline (ship immediately)
- Hero: “Ship faster. Earn sooner.”
- Primary CTA: Join Waitlist
- Secondary CTA: Book a Call
- Offer blocks:
  1) Launch Sprint
  2) AI Operator Setup
  3) Pixel Studio Pipeline
- Proof strip with editable metrics
- FAQ + objection handling
- Thank-you redirect + Formspree capture

## W2: Funnel + analytics
- Confirm event tracking: `waitlist_signup`, `book_call_click`
- Add source tagging per CTA/button block
- Add simple lead routing (waitlist vs call intent)

## W3: Competitive edge polish
- Add competitor-comparison section (outcome-based)
- Add 2 case snapshots (before/after)
- Add weekly update/change-log section for trust + momentum

---

## Release QA checklist (every publish)
- Mobile layout sanity (320–430px)
- CTA visible above fold
- Form submits + thank-you redirect works
- Analytics events firing
- No dead nav links
- Lighthouse basic pass (performance/accessibility)

---

## Definition of done
- Single-page conversion funnel is the default experience
- All non-core template pages hidden or archived
- Waitlist + call CTA tracking live
- Messaging reflects PrismBot execution-first positioning
