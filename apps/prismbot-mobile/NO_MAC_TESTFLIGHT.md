# No-Mac iPhone Install via TestFlight (Cloud Build)

You can ship PrismBot to your iPhone without a Mac using Expo EAS + TestFlight.

## Requirements
- Apple Developer account ($99/yr)
- Apple App Store Connect access
- Expo account (free)

## 1) Prepare app config

In `app.json`, make sure bundle id is unique:
- `com.prismtek.prismbot`

In `eas.json`:
- Use `preview` for first build
- Fill submit values later when ready

## 2) Login and configure

```bash
cd apps/prismbot-mobile
npm install
npx expo login
npx eas login
npx eas build:configure
```

## 3) Store bridge token as EAS secret

```bash
npx eas secret:create --scope project --name EXPO_PUBLIC_PRISMBOT_BRIDGE_TOKEN --value "YOUR_BRIDGE_TOKEN"
```

## 4) Build iOS in cloud (no Mac)

```bash
npx eas build -p ios --profile preview
```

EAS will guide Apple credentials + provisioning setup interactively.

## 5) Install build

- For internal preview, use install link from EAS build page
- Or submit to TestFlight:

```bash
npx eas submit -p ios --profile production
```

Then install via TestFlight app on iPhone.

## 6) Verify in app

- Login works
- Bridge online status updates
- Hybrid mode falls back local when bridge is down

## Notes
- If you only want private testing, keep distribution internal/preview.
- For stable tester flow, use TestFlight.
