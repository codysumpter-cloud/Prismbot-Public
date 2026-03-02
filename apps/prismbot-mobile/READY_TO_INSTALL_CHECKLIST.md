# PrismBot Mobile — Ready to Install Checklist

## 1) Select environment
```bash
cd apps/prismbot-mobile
./scripts/select-env.sh beta
```

## 2) Install and start
```bash
npm install
npm run start
```

## 3) Build to iPhone (private)
```bash
npx expo prebuild --platform ios
npx expo run:ios --device
```

## 4) Validate core flow on phone
- Open app
- Login as family user/admin
- Send 1 message in Hybrid mode
- Toggle to Local mode and confirm local reply
- Confirm bridge health indicator updates

## 5) If bridge is down
- Confirm message still replies locally
- Confirm queued outbox count increments
- Restore bridge and verify queue drains on relaunch
