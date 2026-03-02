# PrismBot Desktop Install Guide

## 1) Build on your machine

```bash
cd ~/.openclaw/workspace/apps/prismbot-desktop
npm install
```

### Linux build
```bash
npm run dist:linux
```
Output:
- `dist/*.AppImage`
- `dist/*.deb`

### Windows build (run on Windows machine)
```bash
npm run dist:win
```
Output:
- `dist/*Setup*.exe`
- `dist/*portable*.exe`

### macOS build (run on Mac)
```bash
npm run dist:mac
```
Output:
- `dist/*.dmg`

## 2) Install
- Linux: run `.AppImage` or install `.deb`
- Windows: run installer `.exe`
- macOS: open `.dmg` and drag app to Applications

## 3) Runtime behavior
- Opening app starts local PrismBot services on localhost
- Closing app shuts those managed services down
- No Google VM required while desktop app is open

## 4) First-run check
- App opens to PrismBot Family UI
- Send a message in admin/family chat
- Close app, then confirm local ports are closed
  - Windows: `netstat -ano | findstr :8787` (repeat for 8790/8797)

### If you see a white screen
Set workspace path explicitly before launch:

```powershell
$env:PRISMBOT_WORKSPACE="$HOME\prismbot\prismbot-workspace"
npm.cmd start
```
