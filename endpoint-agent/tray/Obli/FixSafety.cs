using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.RegularExpressions;

namespace Oblivion.Obli;

/// <summary>What the person's screen is showing when Obli is about to act.</summary>
public sealed record FixFocus(bool IsPassword,string WindowTitle,string Process);

/// <summary>
/// S&D Phase I: the tray's own safety checks, on top of the server's (ObliFixSafety). Obli never types
/// into a password field; never acts on security or admin windows, command windows, the Settings app
/// (Windows Update and protection live there, and it is only ever titled "Settings"), the Run box, or a
/// window whose program it can't name; never types or presses keys in Start or its search (they run
/// what is typed); never types text that would start a program, a script or a settings page; and uses
/// only ordinary keys (no Windows key, no Ctrl+Alt+Del, no Task Manager, no Alt+F4). Pure and testable.
/// </summary>
public static class FixSafety
{
 /// <summary>Keys Obli may press, by the names the protocol uses, with their Windows virtual-key codes.</summary>
 public static readonly IReadOnlyDictionary<string,ushort> Keys=BuildKeys();
 static readonly HashSet<string> Modifiers=new(StringComparer.Ordinal){"ctrl","shift","alt"};

 static IReadOnlyDictionary<string,ushort> BuildKeys()
 {
  var keys=new Dictionary<string,ushort>(StringComparer.Ordinal){
   ["ctrl"]=0x11,["shift"]=0x10,["alt"]=0x12,["enter"]=0x0D,["tab"]=0x09,["escape"]=0x1B,["backspace"]=0x08,["delete"]=0x2E,["space"]=0x20,
   ["up"]=0x26,["down"]=0x28,["left"]=0x25,["right"]=0x27,["home"]=0x24,["end"]=0x23,["pageup"]=0x21,["pagedown"]=0x22,["f5"]=0x74,["f2"]=0x71};
  for(char c='a';c<='z';c++)keys[c.ToString()]=(ushort)char.ToUpperInvariant(c);
  for(char c='0';c<='9';c++)keys[c.ToString()]=(ushort)c;
  return keys;
 }

 /// <summary>
 /// A key press is one optional set of modifiers then one ordinary key. Combinations that open
 /// security screens or end programs are refused, whatever the server sent.
 /// </summary>
 public static IReadOnlyList<string> ValidateKeys(IReadOnlyList<string> keys)
 {
  if(keys.Count is <1 or >3||keys.Distinct(StringComparer.Ordinal).Count()!=keys.Count)throw new InvalidDataException("Obli asked for keys it doesn’t press.");
  foreach(string name in keys)if(!Keys.ContainsKey(name))throw new InvalidDataException("Obli asked for keys it doesn’t press.");
  var ordinary=keys.Where(k=>!Modifiers.Contains(k)).ToList();
  if(ordinary.Count!=1||!keys[^1].Equals(ordinary[0],StringComparison.Ordinal))throw new InvalidDataException("Obli asked for keys it doesn’t press.");
  bool ctrl=keys.Contains("ctrl"),alt=keys.Contains("alt"),shift=keys.Contains("shift");
  string key=ordinary[0];
  if((ctrl&&alt&&key=="delete")||(ctrl&&shift&&key=="escape")||(alt&&key is "f4" or "tab" or "escape")||(ctrl&&key=="escape"))
   throw new InvalidDataException("Obli asked for keys it doesn’t press.");
  return keys;
 }

 static readonly string[] BlockedProcesses={"sechealthui","securityhealthhost","securityhealthsystray","regedit","regedt32","consent","credentialuibroker","logonui","lockapp","netplwiz","lusrmgr","gpedit","mmc","taskmgr","msconfig","secpol","bitlockerwizard","bitlockerwizardelev","fvenotify","manage-bde","useraccountcontrolsettings","wf","firewallcontrolpanel","credwiz","mrt","msmpeng","wscript","cscript",
  // Settings holds Windows Update, protection and accounts.
  "systemsettings","systemsettingsadminflows","control",
  // Command windows and terminals: no action at all.
  "powershell","pwsh","powershell_ise","cmd","windowsterminal","wt","conhost","openconsole","wsl","bash","mintty"};
 static readonly string[] LauncherProcesses={"searchhost","searchapp","searchui","startmenuexperiencehost","shellexperiencehost"};
 static readonly Regex LaunchText=new(@"\b(?:powershell|pwsh|cmd|command|conhost|wt|mshta|rundll32|regsvr32|wscript|cscript|certutil|bitsadmin|msiexec|wmic|schtasks|reg|sc|net|netsh|bcdedit|vssadmin|curl|wget|iwr|invoke-webrequest|start-process)(?:\.exe)?\b(?=\s|$|/|-)|-e(?:nc(?:odedcommand)?)?\s+[a-z0-9+/=]{8,}|\b(?:ms-settings|windowsdefender|shell|ms-msdt|search-ms|file|vbscript|javascript):|\.(?:ps1|psm1|bat|cmd|vbs|vbe|js|jse|wsf|hta|scr|msi|lnk|exe|com|reg)\b|\\\\[^\s\\]+\\|%[a-z_()]+%",RegexOptions.IgnoreCase|RegexOptions.CultureInvariant,TimeSpan.FromMilliseconds(100));
 static readonly string[] BlockedTitles={"windows security","firewall","bitlocker","user accounts","local users and groups","group policy","registry editor","virus & threat","virus and threat","credential manager","windows defender","tamper protection","sign-in options","family options","user account control","command prompt","windows powershell"};

 /// <summary>Why Obli mustn't act on the window in front, or null when it may.</summary>
 public static string? Blocked(FixFocus focus)
 {
  string process=Process(focus);
  string title=focus.WindowTitle.Trim().ToLowerInvariant();
  // Fail safe: a window whose program can't be named (often one run as an administrator) is left alone.
  if(process.Length==0||BlockedProcesses.Contains(process)||BlockedTitles.Any(t=>title.Contains(t,StringComparison.Ordinal))
   ||(process=="applicationframehost"&&title.StartsWith("settings",StringComparison.Ordinal))||(process=="explorer"&&title=="run"))
   return "This window looks after the computer’s security or settings, so Obli doesn’t touch it.";
  return null;
 }

 static string Process(FixFocus focus)
 {
  string process=focus.Process.Trim().ToLowerInvariant();
  int slash=Math.Max(process.LastIndexOf('\\'),process.LastIndexOf('/'));
  if(slash>=0)process=process[(slash+1)..];
  return process.EndsWith(".exe",StringComparison.Ordinal)?process[..^4]:process;
 }

 /// <summary>Text that would start a program, a script or a settings page if it reached an address bar or Start.</summary>
 public static bool StartsSomething(string text)
 {
  try{return LaunchText.IsMatch(text);}
  catch(RegexMatchTimeoutException){return true;}
 }

 /// <summary>
 /// Whether an action may run with this focus. Typing and keys never go to a password field: Obli
 /// stops and asks the person to type it themselves (windows.md J).
 /// </summary>
 public static string? Refuse(FixAction action,FixFocus focus)
 {
  if(!action.TouchesScreen)return null;
  string? blocked=Blocked(focus);
  if(blocked!=null)return blocked;
  if(focus.IsPassword&&action.Type is "type" or "key")return "password";
  if(action.Type is "type" or "key"&&LauncherProcesses.Contains(Process(focus)))return "Start and its search run what is typed, so Obli opens things by clicking instead.";
  if(action.Type=="type"&&StartsSomething(action.Text??""))return "That text would start a program, a script or a settings page, so Obli doesn’t type it.";
  return null;
 }

 /// <summary>Picture coordinates (from a picture of the given size) to Windows' 0–65535 absolute input range.</summary>
 public static (int X,int Y) ToAbsolute(int x,int y,int pictureWidth,int pictureHeight)
 {
  if(pictureWidth<2||pictureHeight<2||x<0||y<0||x>=pictureWidth||y>=pictureHeight)throw new InvalidDataException("Obli asked to point outside the screen.");
  return ((int)((long)x*65535/(pictureWidth-1)),(int)((long)y*65535/(pictureHeight-1)));
 }
}
