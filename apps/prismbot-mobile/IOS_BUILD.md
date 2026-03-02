# PrismBot iOS Build (Private, no public App Store)

## 1) Prereqs
- Mac with Xcode installed
- Apple ID signed into Xcode
- Node 20+ and npm

## 2) Run project
```bash
cd apps/prismbot-mobile
cp .env.example .env
npm install
npm run start
```

## 3) Build to iPhone with Xcode (direct install)
```bash
npx expo prebuild --platform ios
npx expo run:ios --device
```

If prompted, open `ios/PrismBot.xcworkspace` in Xcode:
- Select your Team in Signing & Capabilities
- Set unique bundle id if needed
- Build + Run to device

## 4) Private distribution option
Use TestFlight (internal testers) after archive/upload from Xcode.

## Notes
- App runs local brain on-device while open.
- Hybrid/remote features require bridge/family endpoints in `.env`.
