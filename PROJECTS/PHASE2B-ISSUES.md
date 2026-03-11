# Phase 2B Issue Backlog (New App Tracks)

Total issues: **70**

## feat/prismbot-core
1. Audit core config loading and validation paths
2. Harden API input validation across core endpoints
3. Add structured health/status endpoint contract tests
4. Document core runtime boot sequence and dependencies
5. Add migration safety checks and dry-run mode
6. Introduce centralized error code catalog
7. Add rate-limit guardrails for high-cost operations
8. Create core service smoke-test script
9. Add telemetry/event schema documentation
10. Implement rollback checklist for core releases

## feat/pixel-pipeline
1. Normalize prompt template schema for generation jobs
2. Add asset validation checks (size/palette/transparency)
3. Implement batch generation queue with retry logic
4. Create standardized export bundle naming/versioning
5. Add generation metadata manifest per asset pack
6. Build pipeline failure diagnostics view/log summary
7. Add deterministic seed replay support
8. Create quality rubric scoring script for outputs
9. Implement template preset library (characters/tiles/ui)
10. Document end-to-end pipeline runbook

## feat/prismbot-web-platform
1. Choose canonical web app entrypoint and architecture
2. Unify nav/header/footer across web surfaces
3. Add project portfolio cards with filters/tags
4. Create game showcase section with play links
5. Implement SEO baseline (meta/sitemap/robots)
6. Add performance budget checks for main pages
7. Build contact/community CTA section
8. Add analytics event map for key interactions
9. Create content publishing checklist
10. Document deploy and rollback process

## feat/mission-control
1. Build dashboard shell with status panels
2. Add agent/session activity stream module
3. Create safe action console with confirmations
4. Implement cron/job run visibility panel
5. Add alert center for failures and retries
6. Add filters/search for operational events
7. Create role-based view scaffolding
8. Add system health snapshot export
9. Implement incident timeline view
10. Document mission-control operator SOP

## feat/prismbot-mobile
1. Audit mobile env presets and startup paths
2. Implement auth/session persistence hardening
3. Create mobile home + quick actions screen
4. Add push notification routing handlers
5. Build offline state fallback for core views
6. Add crash/error reporting integration
7. Implement secure settings storage handling
8. Create mobile QA smoke checklist
9. Optimize initial load and bundle size
10. Document release checklist for mobile builds

## feat/prismbot-desktop
1. Harden desktop startup/update flow
2. Build settings and config diagnostics panel
3. Add desktop-specific permission checks
4. Implement background task status widget
5. Add error report export for support/debugging
6. Create auto-update rollback guardrails
7. Add local cache management controls
8. Build desktop QA matrix checklist
9. Optimize cold start performance
10. Document packaging/release SOP

## feat/prismbot-wix-integration
1. Define Wix integration auth flow contract
2. Implement Wix embed handshake validation
3. Add API adapter layer for Wix actions
4. Create retry and timeout strategy for Wix calls
5. Add integration status diagnostics endpoint
6. Build Wix-specific error messaging map
7. Create sample Wix page integration guide
8. Add webhook/event verification checks
9. Implement integration smoke test script
10. Document Wix deploy/update/rollback runbook
