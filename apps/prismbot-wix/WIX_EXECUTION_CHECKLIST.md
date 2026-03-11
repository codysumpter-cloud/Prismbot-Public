# Wix Funnel Execution Checklist (Live Publish)

## 1) Page visibility
- [ ] Keep visible: Home, Privacy Policy, Terms, Thank You
- [ ] Hide from nav: Portfolio, Project pages, Collection/Product/Cart/Checkout/Search pages

## 2) Hero + offer copy
- [ ] Headline: **Ship faster. Earn sooner.**
- [ ] Subheadline: PrismBot turns ideas into shipped products, workflows, and launch-ready assets.
- [ ] Primary CTA button text: **Join Waitlist**
- [ ] Secondary CTA button text: **Book a Call**
- [ ] Offer blocks present:
  - Launch Sprint
  - AI Operator Setup
  - Pixel Studio Pipeline

## 3) Waitlist wiring
- [ ] Form action points to Formspree endpoint
- [ ] Hidden field `source=prismbot-site` (or `source=wix-home`)
- [ ] Success redirect to Thank You page
- [ ] Submission test with a real email

## 4) Analytics checks
- [ ] Plausible domain set correctly
- [ ] Optional GA4 ID set correctly
- [ ] Event fires on successful signup: `waitlist_signup`
- [ ] Event fires on call click: `book_call_click`

## 5) Conversion QA
- [ ] CTA visible above fold on mobile
- [ ] No dead links/buttons
- [ ] Form works on mobile + desktop
- [ ] Thank-you page loads after submit
- [ ] Basic page speed sanity check

## 6) Publish gate
- [ ] Preview pass complete
- [ ] Publish
- [ ] Re-test live domain after publish
- [ ] Record baseline metrics (visits, signups, call clicks)
