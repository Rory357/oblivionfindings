; The installer's screens (sdr-plan/pack/windows.md). Words come from the approved design.
; oblivion-setup-core.exe does the work and reports back through small UTF-16 INI files and
; "progress: <step> started|done|failed" lines; it never prints a profile, token or key.

[Code]
const
  Tick = #$2713;      { check mark }
  Ellipsis = #$2026;
  Dot = #$00B7;
  Apostrophe = #$2019;

var
  Core: String;              { the engine, extracted to the temporary folder }
  CoreArch: String;          { amd64 or arm64: must match the package it installs }
  WorkDir: String;           { private folder with setup.ini and the profiles }
  HandoffDir: String;        { folder shared with the elevated copy (marker only for actions) }
  Mode: String;              { install, repair, update or remove }
  PerUser: Boolean;          { Obli in this person's profile (screen F) }
  Proxy: String;
  InstalledOK: Boolean;
  EngineRunning: Boolean;
  RestartAfterRemove: Boolean;
  WizardReady: Boolean;      { WizardForm exists (dialogs before it centre on the screen) }

  { From setup.ini }
  Role, InstallMode, ServerHostValue, ServerUrl, CollectorUrl, SiteNameValue, SiteAddress: String;
  MadeBy, MadeAt, ExpiresAt, BrandValue, Person, ComputerName: String;
  HasComputerProfile, HasSiteProfile: Boolean;

  { Pages }
  WelcomePage, WhatPage, WorkPage: TWizardPage;
  HubBox, UpdatesBox, ObliBox: TNewCheckBox;
  StepLabels: array[0..3] of TNewStaticText;
  StepTexts: array[0..3] of String;
  StepCount: Integer;
  NetworkSubnet: String;

{ ---------- small helpers ---------- }

function Ini(const Section, Key: String): String;
begin
  Result := GetIniString(Section, Key, '', AddBackslash(WorkDir) + 'setup.ini');
end;

function HostOnly(const Server: String): String;
var
  I: Integer;
begin
  Result := Server;
  I := Pos(':', Result);
  if I > 0 then
    Result := Copy(Result, 1, I - 1);
end;

function Quote(const S: String): String;
begin
  Result := '"' + S + '"';
end;

{ x.y.z comparison: -1, 0 or 1 }
function NextNumber(var S: String): Integer;
var
  I: Integer;
begin
  I := Pos('.', S);
  if I = 0 then
  begin
    Result := StrToIntDef(S, 0);
    S := '';
  end else begin
    Result := StrToIntDef(Copy(S, 1, I - 1), 0);
    S := Copy(S, I + 1, Length(S));
  end;
end;

function CompareVersions(A, B: String): Integer;
var
  I, X, Y: Integer;
begin
  Result := 0;
  for I := 1 to 3 do
  begin
    X := NextNumber(A);
    Y := NextNumber(B);
    if X > Y then begin Result := 1; Exit; end;
    if X < Y then begin Result := -1; Exit; end;
  end;
end;

{ "Save details for IT": the setup log, with the engine's output, goes to the desktop. }
procedure SaveDetailsForIT;
var
  Target: String;
begin
  Target := AddBackslash(ExpandConstant('{userdesktop}')) + 'Oblivion setup details ' + GetDateTimeString('yyyy-mm-dd hh.nn', '-', '.') + '.txt';
  if CopyFile(ExpandConstant('{log}'), Target, False) then
    MsgBox('Saved to your desktop as "' + ExtractFileName(Target) + '". Send it to IT.', mbInformation, MB_OK)
  else
    MsgBox('The details couldn' + Apostrophe + 't be saved to the desktop.', mbError, MB_OK);
end;

procedure SaveDetailsClick(Sender: TObject);
begin
  SaveDetailsForIT;
end;

{ ---------- the small "Oblivion Setup" dialogs ("If something goes wrong") ---------- }

{ Shows a dialog and returns the index of the button pressed (or -1 when closed).
  A button named 'Save details for IT' saves and keeps the dialog open. }
function ShowDialog(const Problem: Boolean; const Title, Body: String; const Buttons: array of String; EditText: String; var Edited: String): Integer;
var
  Form: TSetupForm;
  Icon, TitleLabel, BodyLabel: TNewStaticText;
  Edit: TNewEdit;
  Button: TNewButton;
  I, X, W, Top: Integer;
begin
  Form := CreateCustomForm(ScaleX(440), ScaleY(190), False, True);
  try
    Form.Caption := 'Oblivion Setup';
    Icon := TNewStaticText.Create(Form);
    Icon.Parent := Form;
    Icon.Left := ScaleX(16);
    Icon.Top := ScaleY(14);
    Icon.Font.Size := 16;
    Icon.Font.Style := [fsBold];
    if Problem then Icon.Caption := '!' else Icon.Caption := 'i';

    TitleLabel := TNewStaticText.Create(Form);
    TitleLabel.Parent := Form;
    TitleLabel.Left := ScaleX(44);
    TitleLabel.Top := ScaleY(16);
    TitleLabel.Width := Form.ClientWidth - ScaleX(60);
    TitleLabel.Font.Style := [fsBold];
    TitleLabel.Font.Size := 11;
    TitleLabel.WordWrap := True;
    TitleLabel.Caption := Title;

    BodyLabel := TNewStaticText.Create(Form);
    BodyLabel.Parent := Form;
    BodyLabel.Left := ScaleX(44);
    BodyLabel.Top := TitleLabel.Top + TitleLabel.Height + ScaleY(8);
    BodyLabel.Width := Form.ClientWidth - ScaleX(60);
    BodyLabel.WordWrap := True;
    BodyLabel.Caption := Body;
    Top := BodyLabel.Top + BodyLabel.Height + ScaleY(12);

    Edit := nil;
    if EditText <> #0 then
    begin
      Edit := TNewEdit.Create(Form);
      Edit.Parent := Form;
      Edit.Left := ScaleX(44);
      Edit.Top := Top;
      Edit.Width := ScaleX(200);
      Edit.Text := EditText;
      Top := Top + Edit.Height + ScaleY(12);
      Form.ActiveControl := Edit;
    end;

    Form.ClientHeight := Top + ScaleY(23 + 16);
    W := Form.CalculateButtonWidth(Buttons);
    X := Form.ClientWidth - ScaleX(16);
    for I := GetArrayLength(Buttons) - 1 downto 0 do
    begin
      Button := TNewButton.Create(Form);
      Button.Parent := Form;
      Button.Caption := Buttons[I];
      Button.Width := W;
      if Buttons[I] = 'Save details for IT' then
      begin
        Button.Width := Form.CalculateButtonWidth([Buttons[I]]);
        Button.OnClick := @SaveDetailsClick;
      end else
        Button.ModalResult := 100 + I;
      Button.Height := ScaleY(23);
      Button.Top := Form.ClientHeight - ScaleY(23 + 12);
      X := X - Button.Width;
      Button.Left := X;
      X := X - ScaleX(8);
      if I = GetArrayLength(Buttons) - 1 then
        Button.Default := True;
    end;

    if WizardReady then
      Form.FlipAndCenterIfNeeded(True, WizardForm, False)
    else
      Form.FlipAndCenterIfNeeded(False, nil, False);
    Result := Form.ShowModal() - 100;
    if Result < 0 then Result := -1;
    if Edit <> nil then
      Edited := Trim(Edit.Text);
  finally
    Form.Free();
  end;
end;

function Ask(const Problem: Boolean; const Title, Body: String; const Buttons: array of String): Integer;
var
  Unused: String;
begin
  Result := ShowDialog(Problem, Title, Body, Buttons, #0, Unused);
end;

{ ---------- running the engine ---------- }

function ExtractCore(const Arch: String): String;
begin
  Result := ExpandConstant('{tmp}\oblivion-setup-core-' + Arch + '.exe');
  if not FileExists(Result) then
    ExtractTemporaryFile('oblivion-setup-core-' + Arch + '.exe');
end;

{ Plain engine run; output goes to the setup log for "Save details for IT". }
procedure LogLine(const S: String; const Error, FirstLine: Boolean);
begin
  if Trim(S) <> '' then
    Log('engine: ' + S);
end;

function RunCore(const Params: String): Integer;
begin
  Result := -1;
  Log('engine command: ' + Copy(Params, 1, Pos(' ', Params + ' ') - 1));
  if not ExecAndLogOutput(Core, Params, ExpandConstant('{tmp}'), SW_HIDE, ewWaitUntilTerminated, Result, @LogLine) then
    Result := -1;
end;

{ ---------- the setup code ---------- }

function LoadSetupIni: Boolean;
begin
  Role := Ini('result', 'role');
  InstallMode := Ini('result', 'install_mode');
  ServerHostValue := Ini('result', 'server');
  ServerUrl := Ini('result', 'server_url');
  CollectorUrl := Ini('result', 'collector_url');
  ComputerName := Ini('result', 'computer_name');
  HasComputerProfile := Ini('result', 'has_computer_profile') = '1';
  HasSiteProfile := Ini('result', 'has_site_profile') = '1';
  SiteNameValue := Ini('display', 'site_name');
  if SiteNameValue = '' then SiteNameValue := Ini('result', 'site_label');
  SiteAddress := Ini('display', 'site_address');
  MadeBy := Ini('display', 'made_by');
  MadeAt := Ini('display', 'made_at');
  ExpiresAt := Ini('display', 'expires_at');
  BrandValue := Ini('display', 'brand_name');
  if BrandValue = '' then BrandValue := Ini('result', 'organisation');
  Person := Ini('display', 'person');
  if Person = '' then Person := ExpandConstant('{username}');
  Result := (Ini('result', 'state') = 'ok') and (ServerUrl <> '') and ((Role = 'hub') or (Role = 'computer'));
end;

{ Redeems the code, showing the "If something goes wrong" dialogs until it works or the
  person gives up. Returns True when setup.ini holds a working setup. }
function Redeem: Boolean;
var
  Params, State, Message, TypedCode, Server, Edited: String;
  Choice: Integer;
begin
  Result := False;
  TypedCode := '';
  Server := '';
  while True do
  begin
    Params := 'redeem --out ' + Quote(WorkDir) + ' --installer ' + Quote(ExpandConstant('{srcexe}'));
    if '{#DefaultServer}' <> '' then Params := Params + ' --default-server {#DefaultServer}';
    if TypedCode <> '' then Params := Params + ' --code ' + Quote(TypedCode);
    if Server <> '' then Params := Params + ' --server ' + Quote(Server);
    if Proxy <> '' then Params := Params + ' --proxy ' + Quote(Proxy);
    DeleteFile(AddBackslash(WorkDir) + 'setup.ini');
    RunCore(Params);
    State := Ini('result', 'state');
    Message := Ini('result', 'message');
    if State = 'ok' then
    begin
      Result := LoadSetupIni;
      Exit;
    end;
    if Ini('result', 'server') <> '' then Server := Ini('result', 'server');
    if (State = 'no_code') or (State = 'unknown') then
    begin
      if Ini('result', 'server_known') = '0' then
      begin
        Ask(True, 'We couldn' + Apostrophe + 't find the setup code', 'This file' + Apostrophe + 's name was changed, so the code is missing. Download the file again from Oblivion.', ['Close']);
        Exit;
      end;
      if State = 'unknown' then
        Message := Message
      else
        Message := 'This file' + Apostrophe + 's name was changed, so the code is missing. Type the 8-character code from Oblivion (Add a hub, step 2), or download the file again.';
      if ShowDialog(True, 'We couldn' + Apostrophe + 't find the setup code', Message, ['Cancel', 'Continue'], TypedCode, Edited) <> 1 then
        Exit;
      TypedCode := Edited;
    end
    else if (State = 'unreachable') or (State = 'proxy') or (State = 'untrusted') then
    begin
      Choice := Ask(True, 'Can' + Apostrophe + 't reach ' + HostOnly(Server), Message, ['Save details for IT', 'Use a proxy' + Ellipsis, 'Try again']);
      if Choice = 1 then
      begin
        if ShowDialog(False, 'Use a proxy', 'Type the proxy address IT gave you, for example proxy.house.local:8080.', ['Cancel', 'Use this proxy'], Proxy, Edited) = 1 then
          Proxy := Edited;
      end
      else if Choice <> 2 then
        Exit;
    end
    else if State = 'expired' then
    begin
      if Ask(True, 'This installer has expired', Message, ['Close', 'Sign in to Oblivion']) = 1 then
        ShellExec('open', 'https://' + Server, '', '', SW_SHOWNORMAL, ewNoWait, Choice);
      Exit;
    end
    else if State = 'used' then
    begin
      Ask(True, 'This installer was already used', Message, ['Close']);
      Exit;
    end
    else if State = 'cancelled' then
    begin
      Ask(True, 'This installer was cancelled', Message, ['Close']);
      Exit;
    end
    else
    begin
      if Message = '' then Message := 'Setup couldn' + Apostrophe + 't start. Try again, or send the details to IT.';
      if Ask(True, 'Oblivion couldn' + Apostrophe + 't set this computer up', Message, ['Save details for IT', 'Close', 'Try again']) <> 2 then
        Exit;
    end;
  end;
end;

{ ---------- handing over to the elevated copy ---------- }

function HandoffSum(const Folder: String): String;
var
  Name: String;
  I: Integer;
  Names: array[0..2] of String;
begin
  Names[0] := 'setup.ini';
  Names[1] := 'site-profile.json';
  Names[2] := 'computer-profile.json';
  Result := '';
  for I := 0 to 2 do
  begin
    Name := AddBackslash(Folder) + Names[I];
    if FileExists(Name) then
      Result := Result + Names[I] + '=' + GetSHA256OfFile(Name) + ';';
  end;
end;

{ Starts this file again as administrator (the one "allow changes" prompt), in admin install
  mode (/ALLUSERS), and waits for it. It runs a copy kept in the private folder, without the
  download's internet mark, so Windows' SmartScreen warning isn't shown a second time (D1:
  "warns once"); the person already chose Run anyway for this exact file. The copy leaves a
  marker once it runs, so a refused prompt is noticed and screen G is shown instead. }
function RunElevated(const ExtraParams: String): Boolean;
var
  Code: Integer;
  Params, Copied: String;
begin
  Result := False;
  if HandoffDir = '' then
  begin
    HandoffDir := AddBackslash(GetTempDir) + 'Oblivion-Setup-' + GetDateTimeString('yyyymmddhhnnss', #0, #0) + '-x';
    ForceDirectories(HandoffDir);
  end;
  DeleteFile(AddBackslash(HandoffDir) + 'elevated.txt');
  Copied := AddBackslash(HandoffDir) + ExtractFileName(ExpandConstant('{srcexe}'));
  if not CopyFile(ExpandConstant('{srcexe}'), Copied, False) then
    Exit;
  DeleteFile(Copied + ':Zone.Identifier');
  Params := '/ALLUSERS /HANDOFF=' + Quote(HandoffDir) + ' ' + ExtraParams;
  if Proxy <> '' then Params := Params + ' /PROXY=' + Quote(Proxy);
  if ShellExec('runas', Copied, Params, '', SW_SHOWNORMAL, ewWaitUntilTerminated, Code) then
    Result := FileExists(AddBackslash(HandoffDir) + 'elevated.txt')
  else
    Log('The administrator prompt was refused or failed: ' + IntToStr(Code));
  DeleteFile(Copied);
end;

{ In the elevated copy: take over the redeemed details, checked against the sums. }
function TakeHandoff: Boolean;
var
  Expected: String;
  Names: TArrayOfString;
  I: Integer;
begin
  Result := False;
  SaveStringToFile(AddBackslash(HandoffDir) + 'elevated.txt', 'elevated', False);
  Expected := ExpandConstant('{param:HANDOFFSUM|}');
  if Expected = '' then Exit;
  WorkDir := ExpandConstant('{tmp}\redeem');
  ForceDirectories(WorkDir);
  SetArrayLength(Names, 3);
  Names[0] := 'setup.ini';
  Names[1] := 'site-profile.json';
  Names[2] := 'computer-profile.json';
  for I := 0 to 2 do
    if FileExists(AddBackslash(HandoffDir) + Names[I]) then
      CopyFile(AddBackslash(HandoffDir) + Names[I], AddBackslash(WorkDir) + Names[I], False);
  if HandoffSum(WorkDir) <> Expected then
  begin
    Log('The handed-over setup details changed; not using them.');
    Exit;
  end;
  Result := LoadSetupIni;
end;

{ ---------- what's already here ---------- }

function MachineInstalled(var Version, Running, InstalledRoleValue, InstalledSite: String): Boolean;
var
  StatusFile: String;
begin
  StatusFile := ExpandConstant('{tmp}\status.ini');
  RunCore('status --out ' + Quote(StatusFile));
  Result := GetIniString('status', 'installed', '0', StatusFile) = '1';
  Version := GetIniString('status', 'version', '', StatusFile);
  Running := GetIniString('status', 'running', '0', StatusFile);
  InstalledRoleValue := GetIniString('status', 'role', '', StatusFile);
  InstalledSite := '';
  RegQueryStringValue(HKLM64, 'Software\Oblivion\Setup', 'Site', InstalledSite);
  if Version = '' then
    RegQueryStringValue(HKLM64, 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{6C0E5B7A-3F2D-4B8E-9A61-0B11E7A9C2D4}_is1', 'DisplayVersion', Version);
  if not Result then
    Result := RegValueExists(HKLM64, 'Software\Oblivion\Setup', 'Role');
end;

function RunUninstaller(const Root: Integer): Boolean;
var
  Command: String;
  Code: Integer;
begin
  Result := RegQueryStringValue(Root, 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{6C0E5B7A-3F2D-4B8E-9A61-0B11E7A9C2D4}_is1', 'UninstallString', Command);
  if Result then
    Result := Exec(RemoveQuotes(Command), '', '', SW_SHOWNORMAL, ewNoWait, Code);
end;

{ "Oblivion is already on this computer". Returns True to carry on with a fresh install. }
function OfferExisting: Boolean;
var
  Version, Running, InstalledRoleValue, InstalledSite, Text: String;
  Buttons: TArrayOfString;
  UpdateIndex, Choice: Integer;
begin
  Result := True;
  if MachineInstalled(Version, Running, InstalledRoleValue, InstalledSite) then
  begin
    Result := False;
    Text := 'Version ' + Version;
    if InstalledRoleValue = 'hub' then
    begin
      if InstalledSite <> '' then Text := Text + ', hub for ' + InstalledSite else Text := Text + ', hub';
    end else if InstalledSite <> '' then
      Text := Text + ', ' + InstalledSite;
    if Running = '1' then Text := Text + ', running.' else Text := Text + ', not running.';
    SetArrayLength(Buttons, 2);
    Buttons[0] := 'Remove';
    Buttons[1] := 'Repair';
    UpdateIndex := -1;
    if CompareVersions('{#AppVersion}', Version) > 0 then
    begin
      SetArrayLength(Buttons, 3);
      Buttons[2] := 'Update to {#AppVersion}';
      UpdateIndex := 2;
    end;
    Choice := Ask(False, 'Oblivion is already on this computer', Text, Buttons);
    if Choice = 0 then
    begin
      if not RunUninstaller(HKLM64) then
        RunElevated('/ACTION=remove');
    end
    else if Choice = 1 then
      RunElevated('/ACTION=repair')
    else if (Choice = UpdateIndex) and (UpdateIndex >= 0) then
      RunElevated('/ACTION=update');
    Exit;
  end;
  { Obli in this person's own profile: Remove, or set it up again with this file. }
  if RegValueExists(HKCU, 'Software\Oblivion\Setup', 'Role') then
  begin
    Version := '';
    RegQueryStringValue(HKCU, 'Software\Oblivion\Setup', 'Version', Version);
    InstalledSite := '';
    RegQueryStringValue(HKCU, 'Software\Oblivion\Setup', 'Site', InstalledSite);
    Text := 'Version ' + Version + ', Obli for ' + ExpandConstant('{username}');
    if InstalledSite <> '' then Text := Text + ', ' + InstalledSite;
    Text := Text + '.';
    if CompareVersions('{#AppVersion}', Version) > 0 then
      Choice := Ask(False, 'Oblivion is already on this computer', Text, ['Remove', 'Repair', 'Update to {#AppVersion}'])
    else
      Choice := Ask(False, 'Oblivion is already on this computer', Text, ['Remove', 'Repair']);
    if Choice = 0 then
    begin
      RunUninstaller(HKCU);
      Result := False;
    end
    else if Choice > 0 then
    begin
      { Repair and Update for one person's Obli set it up again from this file. }
      Core := ExtractCore(CoreArch);
      Result := RunCore('remove-user') = 0;
    end else
      Result := False;
  end;
end;

{ Remove when Settings › Apps has no entry for it (an older install, or a lost entry). Runs in
  the elevated copy; the engine removes only what is exactly this installation's. }
procedure RemoveWithoutUninstaller;
var
  Code: Integer;
  Report: String;
begin
  Report := ExpandConstant('{tmp}\remove.ini');
  Code := RunCore('remove --out ' + Quote(Report));
  if (Code = 0) or (Code = 3) then
  begin
    RegDeleteKeyIncludingSubkeys(HKLM64, 'Software\Oblivion\Setup');
    if Code = 3 then
      Ask(False, 'Removed', 'Oblivion was removed from this computer, but Oblivion didn' + Apostrophe + 't confirm the hub was removed. Remove it in Oblivion ' + #$203A + ' Setup ' + #$203A + ' Hubs too.', ['Close'])
    else
      Ask(False, 'Removed', 'Oblivion was removed from this computer.', ['Close']);
  end else
    Ask(True, 'Oblivion couldn' + Apostrophe + 't be removed', GetIniString('remove', 'detail', 'Something on this computer no longer matches this installation, so it was left for IT.', Report), ['Save details for IT', 'Close']);
end;

{ ---------- start ---------- }

function InitializeSetup: Boolean;
var
  Action: String;
  Choice: Integer;
  NeedsAdmin: Boolean;
  Body: String;
begin
  Result := False;
  Mode := 'install';
  CoreArch := 'amd64';
  Core := ExtractCore('amd64');
  Proxy := ExpandConstant('{param:PROXY|}');
  HandoffDir := ExpandConstant('{param:HANDOFF|}');

  { The elevated copy, started by the person's copy below. }
  if IsAdminInstallMode and (HandoffDir <> '') then
  begin
    Action := ExpandConstant('{param:ACTION|}');
    if Action = '' then
    begin
      Result := TakeHandoff;
      if not Result then
        Ask(True, 'Oblivion couldn' + Apostrophe + 't set this computer up', 'The setup details changed after they were checked, so nothing was installed. Run the file again.', ['Close']);
    end else begin
      SaveStringToFile(AddBackslash(HandoffDir) + 'elevated.txt', 'elevated', False);
      if Action = 'remove' then
        RemoveWithoutUninstaller
      else if (Action = 'repair') or (Action = 'update') then
      begin
        Mode := Action;
        Result := True;
      end;
    end;
    Exit;
  end;

  if not OfferExisting then
    Exit;

  WorkDir := AddBackslash(GetTempDir) + 'Oblivion-Setup-' + GetDateTimeString('yyyymmddhhnnss', #0, #0);
  if not Redeem then
  begin
    DelTree(WorkDir, True, True, True);
    Exit;
  end;

  NeedsAdmin := (Role = 'hub') or (InstallMode = 'full');
  if NeedsAdmin and not IsAdminInstallMode then
  begin
    if Ini('result', 'can_elevate') = '1' then
    begin
      HandoffDir := WorkDir;
      if RunElevated('/HANDOFFSUM=' + HandoffSum(WorkDir)) then
      begin
        DelTree(WorkDir, True, True, True);
        Exit;
      end;
    end;
    { Screen G: no password box they can't fill in. }
    if Role = 'hub' then
      Body := 'Setting up the hub for ' + SiteNameValue + ' needs an administrator on this computer.'
    else
      Body := 'Setting up Oblivion for ' + SiteNameValue + ' needs an administrator on this computer.';
    if MadeBy <> '' then Body := Body + ' Ask ' + MadeBy + ' (IT)' else Body := Body + ' Ask IT';
    if HasComputerProfile then
    begin
      Body := Body + ', or install Obli just for yourself.';
      Choice := Ask(False, 'This part needs IT', Body, ['Close', 'Install Obli for me']);
    end else begin
      Ask(False, 'This part needs IT', Body + '.', ['Close']);
      Choice := 0;
    end;
    if Choice <> 1 then
    begin
      DelTree(WorkDir, True, True, True);
      Exit;
    end;
    PerUser := True;
  end;
  if (Role = 'computer') and (InstallMode = 'staff') then
    PerUser := True;
  if PerUser and IsAdminInstallMode then
  begin
    Ask(True, 'Open this file without administrator rights', 'This download sets up Obli for one person. Close this, then open the file normally (not with Run as administrator).', ['Close']);
    Exit;
  end;
  Result := True;
end;

{ The person's copy tidies the private folders once the elevated copy has finished. The
  elevated copy leaves the handover folder alone: the person's copy still reads its marker. }
procedure DeinitializeSetup;
begin
  if not IsAdminInstallMode then
  begin
    if (WorkDir <> '') and (Pos('Oblivion-Setup-', WorkDir) > 0) then
      DelTree(WorkDir, True, True, True);
    if (HandoffDir <> '') and (Pos('Oblivion-Setup-', HandoffDir) > 0) then
      DelTree(HandoffDir, True, True, True);
  end;
end;

{ ---------- values for the [Setup], [Registry] and [Run] sections ---------- }

function IsHubInstall: Boolean;
begin
  Result := (Role = 'hub') and not PerUser and ((HubBox = nil) or HubBox.Checked);
end;

function InstalledRole(Param: String): String;
begin
  if IsHubInstall then Result := 'hub' else Result := 'computer';
end;

function SavedSetting(const Name: String): String;
begin
  Result := '';
  if IsAdminInstallMode then
    RegQueryStringValue(HKLM64, 'Software\Oblivion\Setup', Name, Result)
  else
    RegQueryStringValue(HKCU, 'Software\Oblivion\Setup', Name, Result);
end;

function BrandName(Param: String): String;
begin
  Result := BrandValue;
  if Result = '' then Result := SavedSetting('Brand');
  if Result = '' then Result := 'Oblivion';
end;

function UninstallName(Param: String): String;
var
  Saved: String;
begin
  if Mode = 'install' then
  begin
    if IsHubInstall then Result := 'Oblivion Hub' else Result := 'Obli';
  end else begin
    Saved := SavedSetting('Role');
    if Saved = 'hub' then Result := 'Oblivion Hub' else Result := 'Obli';
  end;
end;

function SiteName(Param: String): String;
begin
  Result := SiteNameValue;
end;

function ServerHost(Param: String): String;
begin
  Result := ServerHostValue;
end;

function OblivionAddress(Param: String): String;
begin
  Result := ServerUrl;
  if Result = '' then Result := 'https://' + SavedSetting('Server');
end;

function IsFreshInstall: Boolean;
begin
  Result := (Mode = 'install') and InstalledOK;
end;

function ShouldOfferOpen: Boolean;
begin
  Result := InstalledOK and (Mode = 'install');
end;

{ Obli's program, from the sign-in entry the engine wrote (machine-wide or this person's). }
function TrayProgram(Param: String): String;
var
  Value: String;
  I: Integer;
begin
  Result := '';
  if PerUser then
    RegQueryStringValue(HKCU, 'Software\Microsoft\Windows\CurrentVersion\Run', 'Oblivion Obli', Value)
  else
    RegQueryStringValue(HKLM64, 'Software\Microsoft\Windows\CurrentVersion\Run', 'Oblivion Obli', Value);
  if Copy(Value, 1, 1) = '"' then
  begin
    Value := Copy(Value, 2, Length(Value));
    I := Pos('"', Value);
    if I > 0 then Result := Copy(Value, 1, I - 1);
  end else begin
    I := Pos(' --tray', Value);
    if I > 0 then Result := Copy(Value, 1, I - 1);
  end;
end;

function ShouldStartTray: Boolean;
begin
  Result := InstalledOK and (Mode = 'install') and (TrayProgram('') <> '');
end;

{ ---------- screen 3 / F: Welcome ---------- }

function AddText(Page: TWizardPage; const Top: Integer; const Text: String; const Bold: Boolean; const Left: Integer): TNewStaticText;
begin
  Result := TNewStaticText.Create(Page);
  Result.Parent := Page.Surface;
  Result.Left := Left;
  Result.Top := Top;
  Result.Width := Page.SurfaceWidth - Left;
  Result.WordWrap := True;
  Result.Caption := Text;
  if Bold then Result.Font.Style := [fsBold];
end;

function AddRow(Page: TWizardPage; const Top: Integer; const Name, Value: String): Integer;
var
  NameLabel, ValueLabel: TNewStaticText;
begin
  NameLabel := AddText(Page, Top, Name, True, 0);
  NameLabel.Width := ScaleX(96);
  ValueLabel := AddText(Page, Top, Value, False, ScaleX(104));
  Result := Top + ValueLabel.Height + ScaleY(10);
end;

procedure BuildWelcome;
var
  Top: Integer;
  SiteLine, MadeLine: String;
begin
  if PerUser then
  begin
    WelcomePage := CreateCustomPage(wpWelcome, 'Set up Obli for ' + Person, ComputerName + ' ' + Dot + ' no admin password needed');
    Top := AddRow(WelcomePage, 0, 'Installs for', Person + ' only (their Windows profile)');
    Top := AddRow(WelcomePage, Top, 'Connects to', HostOnly(ServerHostValue) + ' ' + Tick);
    Top := AddRow(WelcomePage, Top, 'Site', SiteNameValue);
    Top := AddRow(WelcomePage, Top, 'They can', 'Ask IT for help; share their screen when they click Allow');
    AddRow(WelcomePage, Top, 'Needs IT', 'The hub, updates for everyone on this PC, help at the Windows sign-in screen');
    Exit;
  end;
  if Role = 'hub' then
    WelcomePage := CreateCustomPage(wpWelcome, 'Set up Oblivion for ' + SiteNameValue, BrandName('') + ' Hub {#AppVersion}')
  else
    WelcomePage := CreateCustomPage(wpWelcome, 'Set up Oblivion for ' + SiteNameValue, BrandName('') + ' {#AppVersion}');
  if Role = 'hub' then
    Top := AddText(WelcomePage, 0, 'This computer will be the hub for ' + SiteNameValue + '. It checks the cameras, alarm, sensors and network there and reports to Oblivion. Nothing to type.', False, 0).Height
  else
    Top := AddText(WelcomePage, 0, 'This computer will get Obli, the helper that can fix things, plus remote support. Nothing to type.', False, 0).Height;
  Top := Top + ScaleY(16);
  SiteLine := SiteNameValue;
  if SiteAddress <> '' then SiteLine := SiteLine + ' ' + Dot + ' ' + SiteAddress;
  Top := AddRow(WelcomePage, Top, 'Site', SiteLine);
  Top := AddRow(WelcomePage, Top, 'Connects to', HostOnly(ServerHostValue) + ' ' + Tick);
  MadeLine := MadeBy;
  if MadeAt <> '' then MadeLine := MadeLine + ', ' + MadeAt;
  if ExpiresAt <> '' then MadeLine := MadeLine + ' ' + Dot + ' works once, until ' + ExpiresAt;
  AddRow(WelcomePage, Top, 'Made by', MadeLine);
end;

{ ---------- screen 4: What this computer will do ---------- }

function AddChoice(const Top: Integer; const Caption, Description: String; const Checked, Enabled: Boolean; var Box: TNewCheckBox): Integer;
var
  Text: TNewStaticText;
begin
  Box := TNewCheckBox.Create(WhatPage);
  Box.Parent := WhatPage.Surface;
  Box.Top := Top;
  Box.Left := 0;
  Box.Width := WhatPage.SurfaceWidth;
  Box.Caption := Caption;
  Box.Font.Style := [fsBold];
  Box.Checked := Checked;
  Box.Enabled := Enabled;
  Text := AddText(WhatPage, Box.Top + Box.Height + ScaleY(2), Description, False, ScaleX(18));
  Result := Text.Top + Text.Height + ScaleY(12);
end;

procedure ChoiceClick(Sender: TObject);
begin
  WizardForm.NextButton.Enabled := ((HubBox <> nil) and HubBox.Checked) or ((ObliBox <> nil) and ObliBox.Checked);
end;

procedure BuildWhat;
var
  Top: Integer;
  Place: String;
begin
  WhatPage := CreateCustomPage(WelcomePage.ID, 'What this computer will do', 'Already chosen for you. Change only if you need to.');
  Top := 0;
  if Role = 'hub' then
  begin
    Top := AddChoice(Top, 'Watch ' + SiteNameValue + Apostrophe + 's network (hub)', 'Checks devices every minute and finds new ones. Runs in the background, even when nobody is signed in.', True, True, HubBox);
    HubBox.OnClick := @ChoiceClick;
    { D3: updates are always on. }
    Top := AddChoice(Top, 'Keep itself up to date', 'Updates install overnight. If one fails, it goes back to the last good version.', True, False, UpdatesBox);
    { D4: a hub PC gets the hub and Obli. Without a computer profile from the server it can't. }
    Top := AddChoice(Top, 'Obli on this PC', 'Helps whoever uses this PC, and lets IT ask Obli about ' + SiteNameValue + Apostrophe + 's network. IT sees the screen only after someone here clicks Allow.', HasComputerProfile, HasComputerProfile, ObliBox);
    ObliBox.OnClick := @ChoiceClick;
  end else begin
    Top := AddChoice(Top, 'Obli on this PC', 'Helps whoever uses this PC. IT sees the screen only after someone here clicks Allow.', True, False, ObliBox);
  end;
  Place := 'Installs to ' + ExpandConstant('{commonpf}') + '\Oblivion';
  if StrToIntDef('{#InstallSizeMB}', 0) > 0 then
    Place := Place + ' ' + Dot + ' about {#InstallSizeMB} MB';
  AddText(WhatPage, Top + ScaleY(8), Place, False, 0);
end;

{ ---------- screen 5: Installing, then connecting ---------- }

procedure SetStep(const Index: Integer; const Mark: String);
var
  Text: String;
begin
  if (Index < 0) or (Index >= StepCount) then Exit;
  Text := StepTexts[Index];
  { "Connecting to oblivionfindings.com..." becomes "Connected to oblivionfindings.com". }
  if (Mark = Tick) and (Copy(Text, 1, 14) = 'Connecting to ') then
    Text := 'Connected to ' + Copy(Text, 15, Length(Text) - 15);
  StepLabels[Index].Caption := Mark + '  ' + Text;
end;

procedure AddStep(const Text: String);
begin
  StepTexts[StepCount] := Text;
  StepLabels[StepCount] := AddText(WorkPage, StepCount * ScaleY(26), '   ' + Text, False, 0);
  StepCount := StepCount + 1;
end;

{ Which line on screen 5 an engine step belongs to. }
function StepIndex(const Step: String): Integer;
begin
  Result := -1;
  if Step = 'staging' then Result := 0
  else if Step = 'enrolment' then Result := 1
  else if (Step = 'service_registration') or (Step = 'service_start') or (Step = 'switch') then
  begin
    if Mode = 'update' then Result := 1 else Result := 2;
  end
  else if Step = 'tray_registration' then
  begin
    if PerUser then Result := 2 else Result := -1;
  end;
end;

{ Reads "progress: <step> <status>[: detail]" lines while the engine works. }
procedure ProgressLine(const S: String; const Error, FirstLine: Boolean);
var
  Rest, Step, Status: String;
  I: Integer;
begin
  if Trim(S) = '' then Exit;
  Log('engine: ' + S);
  if Pos('progress: ', S) <> 1 then Exit;
  Rest := Copy(S, 11, Length(S));
  I := Pos(' ', Rest);
  if I = 0 then Exit;
  Step := Copy(Rest, 1, I - 1);
  Status := Copy(Rest, I + 1, Length(Rest));
  I := Pos(':', Status);
  if I > 0 then Status := Copy(Status, 1, I - 1);
  if Status = 'started' then SetStep(StepIndex(Step), Ellipsis)
  else if Status = 'done' then SetStep(StepIndex(Step), Tick)
  else if Status = 'failed' then SetStep(StepIndex(Step), '!');
end;

function RunCoreWithSteps(const Params: String): Integer;
begin
  Result := -1;
  Log('engine command: ' + Copy(Params, 1, Pos(' ', Params + ' ') - 1));
  if not ExecAndLogOutput(Core, Params, ExpandConstant('{tmp}'), SW_HIDE, ewWaitUntilTerminated, Result, @ProgressLine) then
    Result := -1;
end;

function PackageFor(const Arch: String): String;
begin
  Result := ExpandConstant('{tmp}\oblivion-windows-' + Arch + '.zip');
  if not FileExists(Result) then
    ExtractTemporaryFile('oblivion-windows-' + Arch + '.zip');
end;

{ Obli on an Arm PC uses its own build when this file carries one; hubs are x64. }
function ChooseArch(const ForHub: Boolean): String;
begin
  Result := 'amd64';
#ifdef HaveArm64
  if IsArm64 and not ForHub then Result := 'arm64';
#endif
end;

procedure BuildWork;
var
  Host: String;
  After: Integer;
begin
  After := wpWelcome;
  if WhatPage <> nil then
    After := WhatPage.ID
  else if WelcomePage <> nil then
    After := WelcomePage.ID;
  WorkPage := CreateCustomPage(After, 'Setting up Oblivion', 'About a minute');
  StepCount := 0;
  Host := HostOnly(ServerHostValue);
  if Host = '' then Host := SavedSetting('Server');
  if Mode = 'repair' then
  begin
    AddStep('Checked the installed files and started the Oblivion service');
  end
  else if Mode = 'update' then
  begin
    AddStep('Copied files');
    AddStep('Started the Oblivion service (starts with Windows)');
  end
  else if PerUser then
  begin
    AddStep('Copied files');
    AddStep('Connecting to ' + Host + Ellipsis);
    AddStep('Obli starts when ' + Person + ' signs in');
  end else begin
    AddStep('Copied files');
    AddStep('Connecting to ' + Host + Ellipsis);
    AddStep('Started the Oblivion service (starts with Windows)');
    if Role = 'hub' then
      AddStep('Checking it can see this computer' + Apostrophe + 's network');
  end;
end;

{ The engine install/update/repair/remove for the chosen mode. }
function DoWork: Boolean;
var
  Params, EngineRole, Arch, NetworkFile, State: String;
  Code: Integer;
  ForHub: Boolean;
begin
  Result := False;
  if (Mode = 'repair') or (Mode = 'update') then
  begin
    ForHub := SavedSetting('Role') = 'hub';
    Arch := ChooseArch(ForHub);
    Core := ExtractCore(Arch);
    if Mode = 'repair' then
    begin
      SetStep(0, Ellipsis);
      Code := RunCore('repair --package ' + Quote(PackageFor(Arch)));
      Result := Code = 0;
      if Result then SetStep(0, Tick) else SetStep(0, '!');
    end else
      Result := RunCoreWithSteps('update --progress --package ' + Quote(PackageFor(Arch))) = 0;
    Exit;
  end;

  { A fresh install. }
  ForHub := IsHubInstall;
  Arch := ChooseArch(ForHub or ((Role = 'hub') and not PerUser));
  CoreArch := Arch;
  Core := ExtractCore(Arch);
  SetStep(0, Ellipsis);
  if PerUser then
  begin
    Params := 'install-user --progress --package ' + Quote(PackageFor(Arch)) + ' --bootstrap ' + Quote(AddBackslash(WorkDir) + 'computer-profile.json') + ' --confirm-server ' + Quote(ServerUrl);
  end else begin
    if ForHub and (ObliBox <> nil) and ObliBox.Checked then EngineRole := 'both'
    else if ForHub then EngineRole := 'site'
    else EngineRole := 'computer';
    Params := 'install --progress --role ' + EngineRole + ' --package ' + Quote(PackageFor(Arch)) + ' --confirm-server ' + Quote(ServerUrl);
    if EngineRole <> 'computer' then
    begin
      Params := Params + ' --collector-bootstrap ' + Quote(AddBackslash(WorkDir) + 'site-profile.json');
      if (CollectorUrl <> '') and (CollectorUrl <> ServerUrl) then
        Params := Params + ' --confirm-collector-server ' + Quote(CollectorUrl);
    end;
    if EngineRole <> 'site' then
      Params := Params + ' --bootstrap ' + Quote(AddBackslash(WorkDir) + 'computer-profile.json');
  end;
  if Proxy <> '' then Params := Params + ' --proxy ' + Quote(Proxy);
  Result := RunCoreWithSteps(Params) = 0;
  if not Result then Exit;
  if ForHub and (StepCount > 3) then
  begin
    { "Checking it can see 172.16.0.0/24": the router on this computer's network answers. }
    SetStep(3, Ellipsis);
    NetworkFile := ExpandConstant('{tmp}\network.ini');
    RunCore('network-check --out ' + Quote(NetworkFile));
    NetworkSubnet := GetIniString('network', 'subnet', '', NetworkFile);
    State := GetIniString('network', 'can_see', '0', NetworkFile);
    if NetworkSubnet <> '' then
      StepTexts[3] := 'Checking it can see ' + NetworkSubnet;
    if State = '1' then SetStep(3, Tick) else SetStep(3, '!');
  end;
end;

procedure RunWorkPage;
var
  Choice: Integer;
  Body: String;
begin
  WizardForm.NextButton.Enabled := False;
  WizardForm.BackButton.Enabled := False;
  WizardForm.CancelButton.Enabled := False;
  while True do
  begin
    EngineRunning := True;
    InstalledOK := DoWork;
    EngineRunning := False;
    if InstalledOK then Break;
    if Mode = 'repair' then
      Body := 'The repair didn' + Apostrophe + 't finish, and nothing was changed that can' + Apostrophe + 't be repaired again. If this installer is a newer version, choose Update instead.'
    else
      Body := 'Setup stopped before it finished. What was done has been undone where possible, and the same file can try again.';
    Choice := Ask(True, 'Oblivion couldn' + Apostrophe + 't finish', Body, ['Save details for IT', 'Close', 'Try again']);
    if Choice <> 2 then
    begin
      WizardForm.CancelButton.Enabled := True;
      Exit;
    end;
  end;
  WizardForm.CancelButton.Enabled := True;
  WizardForm.NextButton.Enabled := True;
  WizardForm.NextButton.OnClick(WizardForm.NextButton);
end;

{ ---------- screen 6: All set ---------- }

procedure ShowFinished;
var
  Heading, Text: String;
begin
  if Mode = 'repair' then
  begin
    Heading := Tick + ' Repaired';
    Text := 'The Oblivion service is running again.';
  end
  else if Mode = 'update' then
  begin
    Heading := Tick + ' Updated';
    Text := 'Oblivion is on version {#AppVersion}.';
  end
  else if PerUser then
  begin
    Heading := Tick + ' Connected';
    Text := 'Obli is set up for ' + Person + ' and connected to ' + HostOnly(ServerHostValue) + '.';
  end
  else if IsHubInstall then
  begin
    Heading := Tick + ' Connected';
    Text := BrandName('') + ' Hub {#AppVersion}' + #13#10#13#10 + SiteNameValue + ' hub is online. ';
    if MadeBy <> '' then
      Text := Text + MadeBy + ' has been asked to approve it in Oblivion; once approved, it starts checking the network.'
    else
      Text := Text + 'An admin has been asked to approve it in Oblivion; once approved, it starts checking the network.';
  end else begin
    Heading := Tick + ' Connected';
    Text := ComputerName + ' is online in Oblivion.';
  end;
  WizardForm.FinishedHeadingLabel.Caption := Heading;
  WizardForm.FinishedLabel.Caption := Text;
end;

{ ---------- wizard events ---------- }

procedure InitializeWizard;
begin
  WizardReady := True;
  if PerUser then
    WizardForm.Caption := 'Obli Setup';
  if Mode = 'install' then
  begin
    BuildWelcome;
    if not PerUser then
      BuildWhat;
  end;
  BuildWork;
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if (WelcomePage <> nil) and (CurPageID = WelcomePage.ID) and PerUser then
    WizardForm.NextButton.Caption := 'Install';
  if (WhatPage <> nil) and (CurPageID = WhatPage.ID) then
    ChoiceClick(nil);
  if (WorkPage <> nil) and (CurPageID = WorkPage.ID) and not InstalledOK then
    RunWorkPage;
  if CurPageID = wpFinished then
    ShowFinished;
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  { The work page ran already when going forward; never show it twice. }
  Result := (WorkPage <> nil) and (PageID = WorkPage.ID) and InstalledOK;
end;

procedure CancelButtonClick(CurPageID: Integer; var Cancel, Confirm: Boolean);
begin
  if EngineRunning then
    Cancel := False;
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  { Inno's own install step only registers Remove; never before the engine succeeded. }
  Result := True;
  if (WorkPage <> nil) and (CurPageID = WorkPage.ID) then
    Result := InstalledOK;
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  { Keep the engine beside the uninstaller, for Remove in Windows Settings › Apps. }
  if (CurStep = ssPostInstall) and InstalledOK then
  begin
    ForceDirectories(ExpandConstant('{app}\Setup'));
    CopyFile(Core, ExpandConstant('{app}\Setup\oblivion-setup-core.exe'), False);
  end;
end;

{ ---------- Remove (Windows Settings › Apps, screen E) ---------- }

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  Engine, Report, Detail: String;
  Code: Integer;
begin
  if CurUninstallStep <> usUninstall then Exit;
  Engine := ExpandConstant('{app}\Setup\oblivion-setup-core.exe');
  if not FileExists(Engine) then Exit;
  if not IsAdminInstallMode then
  begin
    Exec(Engine, 'remove-user', '', SW_HIDE, ewWaitUntilTerminated, Code);
    Exit;
  end;
  { A hub tells Oblivion first, which shows "hub removed" and stops trusting it. }
  Report := AddBackslash(GetTempDir) + 'oblivion-remove-' + GetDateTimeString('yyyymmddhhnnss', #0, #0) + '.ini';
  Exec(Engine, 'remove --out ' + Quote(Report), '', SW_HIDE, ewWaitUntilTerminated, Code);
  RestartAfterRemove := GetIniString('remove', 'restart_needed', '0', Report) = '1';
  Detail := GetIniString('remove', 'detail', '', Report);
  DeleteFile(Report);
  if Code = 3 then
    MsgBox('Oblivion was removed from this computer, but Oblivion didn' + Apostrophe + 't confirm the hub was removed. Remove it in Oblivion › Setup › Hubs too.', mbInformation, MB_OK)
  else if Code <> 0 then
    MsgBox('Some of Oblivion was left on this computer for IT to check: ' + Detail, mbError, MB_OK);
end;

function UninstallNeedRestart: Boolean;
begin
  Result := RestartAfterRemove;
end;
