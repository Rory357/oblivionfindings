# Oblivion Windows installer

One file for everyone (S&D Phase B, `sdr-plan/pack/windows.md` in the design kit). Oblivion
names each download for its Site with the server and a one-time setup code, for example
`Oblivion-Setup (Tōtara House) [oblivionfindings.com T6WD-3KQN].exe`, so nothing is added
inside the file and it stays valid once signed (decisions D1, D2).

- `inno/oblivion-setup.iss` and `inno/setup-code.iss`: the Inno Setup script (the screens).
- `inno/build.ps1`: builds `oblivion-setup-windows.exe` from the signed release package(s) and
  the setup engine. CI (`.github/workflows/site-hub-release.yml`) installs a pinned, checked
  Inno Setup and publishes the result as the catalogue's Windows `.exe`.
- The engine is `oblivion-setup-core.exe` (`../cmd/oblivion-setup`, `../internal/setup`). It
  checks the package's publisher signature and does all the work: `redeem`, `install`,
  `install-user`, `update`, `repair`, `remove`, `remove-user`, `status`, `network-check`.

## What it does

1. Reads the server and code from its own file name (or the download record, then asks for the
   8 characters), and redeems the code over HTTPS with Windows' certificate checks.
2. A hub or IT's full install starts the file again as administrator (one prompt) and hands the
   redeemed details over in a private folder checked by SHA-256, so a code is never redeemed
   twice. A staff install stays as the person: Obli in their profile and their Run key.
3. Shows Welcome and "What this computer will do" (hub: the hub, updates always on, Obli on this
   PC per D4), then installs with plain steps and ends on "Connected".
4. Registers in Windows Settings › Apps. Running the file again offers Remove, Repair or
   Update. Removing a hub tells Oblivion first, which stops trusting it.

Hubs also get the Windows Firewall rule for the sensor relay (inbound TCP 1883 for the hub's
PHP runtime, private and domain networks, local subnet), kept by the engine and the hub's own
updates and removed with the hub.

## Building locally

    ./inno/build.ps1 -Iscc <ISCC.exe> -PayloadDir <folder> -Version 1.3.1 -OutputDir <folder>

`PayloadDir` holds `oblivion-setup-core-amd64.exe` and `oblivion-windows-amd64.zip`, and
optionally the `arm64` pair. Compiling installs nothing; don't run the result on a development
PC, as it installs services.
