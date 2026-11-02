; Oblivion's one-file Windows installer (S&D Phase B, sdr-plan/pack/windows.md, screens 1-6,
; A, E, F, G and "If something goes wrong").
;
; Everyone downloads the same signed file. Oblivion names it for the Site with the server and a
; one-time setup code, e.g. "Oblivion-Setup (Totara House) [oblivionfindings.com T6WD-3KQN].exe",
; so nothing is added inside the file and signing stays valid (D1, D2). The file holds the
; signed release package(s) and oblivion-setup-core.exe, the Go engine that checks the
; package's publisher signature and does all the real work (redeem, install, update, repair,
; remove). This script only shows the screens and runs the engine.
;
; Built by build.ps1 (CI: .github/workflows/site-hub-release.yml):
;   ISCC /DAppVersion=1.3.1 /DPayloadDir=<folder> [/DDefaultServer=host] [/DInstallSizeMB=380] oblivion-setup.iss
; PayloadDir holds oblivion-setup-core-amd64.exe and oblivion-windows-amd64.zip, and, when
; built, oblivion-setup-core-arm64.exe and oblivion-windows-arm64.zip (Obli on Arm PCs).

#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif
#ifndef PayloadDir
  #error Pass /DPayloadDir=<folder with the signed release package and setup engine>
#endif
#ifndef DefaultServer
  #define DefaultServer ""
#endif
#ifndef InstallSizeMB
  #define InstallSizeMB "0"
#endif
#ifndef OutputDir
  #define OutputDir "Output"
#endif
#if FileExists(AddBackslash(PayloadDir) + "oblivion-windows-arm64.zip") && FileExists(AddBackslash(PayloadDir) + "oblivion-setup-core-arm64.exe")
  #define HaveArm64
#endif

[Setup]
; One AppId for every role, so running the file again finds what's there (Update, Repair, Remove).
AppId={{6C0E5B7A-3F2D-4B8E-9A61-0B11E7A9C2D4}
AppName=Oblivion
AppVersion={#AppVersion}
AppVerName=Oblivion {#AppVersion}
AppPublisher={code:BrandName}
UninstallDisplayName={code:UninstallName}
VersionInfoVersion={#AppVersion}
VersionInfoDescription=Oblivion Setup
VersionInfoCompany=Oblivion
VersionInfoProductName=Oblivion
; Privileges: Inno decides them before any [Code] runs, but only the setup code says whether this
; is a hub, IT's full install or a staff install. So setup starts as the person (no prompt) and
; redeems the code. A staff install carries on as them: Obli in their own profile, their own Run
; key, no admin password (screen F). A hub or full install starts this same file again with
; /ALLUSERS, which makes Inno ask Windows for administrator rights itself (one "allow changes"
; prompt, screen 2), and hands the redeemed details over in a private folder checked by SHA-256,
; so the code is never redeemed twice (a computer code works once).
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=commandline
DefaultDirName={autopf}\Oblivion
UninstallFilesDir={app}\Setup
DisableDirPage=yes
DisableProgramGroupPage=yes
DisableReadyPage=yes
DisableWelcomePage=yes
UsePreviousAppDir=no
CreateAppDir=yes
; A 64-bit Setup (an x64 program, which the signed release list records as amd64). Windows 11 on Arm
; runs it too, and Obli there gets its Arm build.
UseSetupLdr=x64
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern dynamic
SetupLogging=yes
CloseApplications=no
RestartIfNeededByRun=no
; The release zips are already compressed and signed byte for byte; store them as they are.
Compression=lzma2/fast
SolidCompression=no
OutputDir={#OutputDir}
OutputBaseFilename=oblivion-setup-windows
; Unsigned while testing (D1): Windows warns once. Nothing here changes when it is signed later,
; because the setup code lives in the file name.

[Messages]
SetupWindowTitle=Oblivion Setup
UninstallAppTitle=Remove Oblivion

[Files]
; Nothing is copied by Inno itself except the engine for Remove (see CurStepChanged). The
; package is unpacked and checked by the engine, which owns C:\Program Files\Oblivion.
Source: "{#PayloadDir}\oblivion-setup-core-amd64.exe"; Flags: dontcopy
Source: "{#PayloadDir}\oblivion-windows-amd64.zip"; Flags: dontcopy nocompression
#ifdef HaveArm64
Source: "{#PayloadDir}\oblivion-setup-core-arm64.exe"; Flags: dontcopy
Source: "{#PayloadDir}\oblivion-windows-arm64.zip"; Flags: dontcopy nocompression
#endif

[Registry]
; What "Oblivion is already on this computer" shows, and Remove's name in Settings › Apps.
Root: HKA; Subkey: "Software\Oblivion\Setup"; ValueType: string; ValueName: "Role"; ValueData: "{code:InstalledRole}"; Flags: uninsdeletekey; Check: IsFreshInstall
Root: HKA; Subkey: "Software\Oblivion\Setup"; ValueType: string; ValueName: "Site"; ValueData: "{code:SiteName}"; Flags: uninsdeletekey; Check: IsFreshInstall
Root: HKA; Subkey: "Software\Oblivion\Setup"; ValueType: string; ValueName: "Server"; ValueData: "{code:ServerHost}"; Flags: uninsdeletekey; Check: IsFreshInstall
Root: HKA; Subkey: "Software\Oblivion\Setup"; ValueType: string; ValueName: "Brand"; ValueData: "{code:BrandName}"; Flags: uninsdeletekey; Check: IsFreshInstall
Root: HKA; Subkey: "Software\Oblivion\Setup"; ValueType: string; ValueName: "Version"; ValueData: "{#AppVersion}"; Flags: uninsdeletekey

[UninstallDelete]
Type: files; Name: "{app}\Setup\oblivion-setup-core.exe"
Type: dirifempty; Name: "{app}\Setup"
Type: dirifempty; Name: "{app}"

[Run]
; Obli starts for the signed-in person at Finish, and at every sign-in from the Run key.
Filename: "{code:TrayProgram}"; Parameters: "--tray"; Flags: nowait runasoriginaluser skipifdoesntexist; Check: ShouldStartTray
Filename: "{code:OblivionAddress}"; Description: "Open Oblivion now"; Flags: postinstall shellexec nowait runasoriginaluser skipifsilent; Check: ShouldOfferOpen

#include "setup-code.iss"
