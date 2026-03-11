# BMO Installer ISO (v1)

This folder contains a **custom Ubuntu autoinstall ISO builder** for PrismBot/BMO, plus a GitHub Actions flow that publishes a downloadable ISO asset.

## What it does

- Takes an Ubuntu ISO (24.04 LTS recommended)
- Injects `autoinstall` + NoCloud seed files
- Configures first user/host settings
- Automatically bootstraps PrismBot after OS install:
  - clones `codysumpter-cloud/PrismBot`
  - runs `scripts/install-oneclick.sh`
  - runs `scripts/update-all.sh`
- Installs Ollama and pre-pulls default local models
- Optional `--with-gaming` mode installs legal gaming tooling (`steam-installer`, `retroarch`, `mgba-qt`) and creates a legal ROM folder

## Build

```bash
bash iso/build-bmo-installer-iso.sh \
  --ubuntu-iso ~/Downloads/ubuntu-24.04.2-desktop-amd64.iso \
  --output ~/Downloads/bmo-ubuntu-24.04.2-autoinstall.iso \
  --hostname bmo-node \
  --username bmo \
  --password 'ChangeMeNow!' \
  --with-gaming
```

## One-click download (latest published ISO)

Linux:
```bash
bash scripts/download-latest-bmo-iso.sh ~/Downloads
```

Windows:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\download-latest-bmo-iso.ps1 -OutDir "$env:USERPROFILE\Downloads"
```

Direct URL (for prismtek.dev redirect pages):
- `https://github.com/codysumpter-cloud/Prismbot-Public/releases/download/bmo-iso-latest/bmo-installer-latest.iso`

## Notes

- Use Ubuntu **24.04 LTS** for stability.
- Builder requires: `xorriso`, `7z`, `openssl`, `sed`, `awk`.
- This is a v1 practical builder; always test the ISO in a VM before imaging real hardware.
- GitHub Action publishes `bmo-iso-latest` with default install password `ChangeMeNow!` — change on first boot.
