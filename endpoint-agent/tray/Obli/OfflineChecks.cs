using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

/// <summary>What the built-in checks found (no AI needed).</summary>
public sealed record OfflineResult(bool NetworkUp,bool Wifi,string? WifiName,int? WifiSignal,string? Gateway,bool GatewayAnswers,bool NamesWork,bool ServerAnswers);

/// <summary>One line to show, and what to try.</summary>
public sealed record OfflineAdvice(IReadOnlyList<FixCheck> Lines,string Advice,bool Online);

/// <summary>
/// S&D Phase I (windows.md N): when the internet is down Obli can't reach its AI, which is exactly when
/// people need help. These checks are built in: is there a network, does the house router answer, do
/// names work, does Oblivion answer. Plain words, no AI. IT is told when the internet comes back.
/// </summary>
public static class OfflineChecks
{
 public static OfflineAdvice Advise(OfflineResult r)
 {
  var lines=new List<FixCheck>();
  if(r.Wifi)lines.Add(new(r.NetworkUp?"ok":"critical",r.NetworkUp?$"Wi-Fi connected{(r.WifiName!=null?" "+r.WifiName:"")}{(r.WifiSignal is int s?$" · {(s>=70?"strong":s>=40?"fair":"weak")} signal":"")}":"Wi-Fi isn’t connected"));
  else lines.Add(new(r.NetworkUp?"ok":"critical",r.NetworkUp?"Connected by cable":"Not connected to a network"));
  if(r.NetworkUp)lines.Add(new(r.GatewayAnswers?"ok":"critical",r.GatewayAnswers?"The house router answers":$"The house router isn’t answering{(r.Gateway!=null?" · no reply from "+r.Gateway:"")}"));
  if(r.NetworkUp&&r.GatewayAnswers)lines.Add(new(r.NamesWork?"ok":"critical",r.NamesWork?"The internet answers":"The internet isn’t answering"));
  if(r.NetworkUp&&r.GatewayAnswers&&r.NamesWork)lines.Add(new(r.ServerAnswers?"ok":"warning",r.ServerAnswers?"Oblivion answers":"Oblivion isn’t answering"));
  string advice=
   !r.NetworkUp?(r.Wifi?"Turn Wi-Fi on from the network icon beside the clock and choose the house network.":"Check the network cable is plugged in at both ends, or turn on Wi-Fi.")
   :!r.GatewayAnswers?"Check the router (often in a hallway or office cupboard). If its lights are off, turn it off at the wall, wait 10 seconds and turn it on. I’ll tell IT when the internet is back."
   :!r.NamesWork?"The house network works but the internet doesn’t. Turn the router off at the wall, wait 10 seconds and turn it on. If that doesn’t help, the internet provider may be down. I’ll tell IT when it’s back."
   :!r.ServerAnswers?"The internet works but Oblivion isn’t answering right now. Try again in a few minutes; IT will be told."
   :"The internet is working again.";
  return new(lines,advice,r.NetworkUp&&r.GatewayAnswers&&r.NamesWork&&r.ServerAnswers);
 }

 public static async Task<OfflineResult> RunAsync(Uri server,CancellationToken cancellation)
 {
  var interfaces=NetworkInterface.GetAllNetworkInterfaces().Where(n=>n.OperationalStatus==OperationalStatus.Up&&n.NetworkInterfaceType is not(NetworkInterfaceType.Loopback or NetworkInterfaceType.Tunnel)).ToList();
  string? gateway=interfaces.SelectMany(n=>n.GetIPProperties().GatewayAddresses).Select(g=>g.Address).FirstOrDefault(a=>a.AddressFamily==AddressFamily.InterNetwork&&!a.Equals(IPAddress.Any))?.ToString();
  bool wifiInterface=NetworkInterface.GetAllNetworkInterfaces().Any(n=>n.NetworkInterfaceType==NetworkInterfaceType.Wireless80211);
  var (wifiUp,name,signal)=wifiInterface?await WifiAsync(cancellation):(false,null,null);
  bool up=interfaces.Any(n=>n.GetIPProperties().GatewayAddresses.Count>0);
  bool gatewayAnswers=false;
  if(gateway!=null){try{using var ping=new Ping();gatewayAnswers=(await ping.SendPingAsync(IPAddress.Parse(gateway),TimeSpan.FromMilliseconds(1500),null,null,cancellation)).Status==IPStatus.Success;}catch(PingException){}}
  bool names=false;
  try{using var t=CancellationTokenSource.CreateLinkedTokenSource(cancellation);t.CancelAfter(3000);names=(await Dns.GetHostAddressesAsync(server.IdnHost,t.Token)).Length>0;}catch(Exception error) when(error is SocketException or OperationCanceledException){}
  bool serverAnswers=false;
  if(names){try{using var tcp=new TcpClient();using var t=CancellationTokenSource.CreateLinkedTokenSource(cancellation);t.CancelAfter(3000);await tcp.ConnectAsync(server.IdnHost,server.Port,t.Token);serverAnswers=true;}catch(Exception error) when(error is SocketException or OperationCanceledException){}}
  return new(up,wifiInterface,name,signal,gateway,gatewayAnswers||(gateway==null&&up),names,serverAnswers);
 }

 static async Task<(bool Up,string? Name,int? Signal)> WifiAsync(CancellationToken cancellation)
 {
  try{
   using var process=Process.Start(new ProcessStartInfo("netsh","wlan show interfaces"){RedirectStandardOutput=true,UseShellExecute=false,CreateNoWindow=true});
   if(process==null)return (false,null,null);
   using var t=CancellationTokenSource.CreateLinkedTokenSource(cancellation);t.CancelAfter(5000);
   string output=await process.StandardOutput.ReadToEndAsync(t.Token);
   return ParseWifi(output);
  }catch(Exception error) when(error is InvalidOperationException or System.ComponentModel.Win32Exception or OperationCanceledException){return (false,null,null);}
 }

 /// <summary>Reads `netsh wlan show interfaces` (English Windows): state, SSID and signal.</summary>
 public static (bool Up,string? Name,int? Signal) ParseWifi(string output)
 {
  string? Field(string label)=>Regex.Match(output,@"^\s*"+label+@"\s*:\s*(.+?)\s*$",RegexOptions.Multiline).Groups[1].Value is {Length:>0} v?v:null;
  bool up=string.Equals(Field("State"),"connected",StringComparison.OrdinalIgnoreCase);
  string? name=Field("SSID");
  int? signal=int.TryParse(Field("Signal")?.TrimEnd('%'),out int s)&&s is >=0 and <=100?s:null;
  if(name!=null&&(name.Length>64||name.Any(char.IsControl)))name=null;
  return (up,up?name:null,up?signal:null);
 }

 /// <summary>A small local note of when the internet went down, so IT can be told when it's back.</summary>
 public static string NotePath=>Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"Oblivion","Obli","offline-note.json");

 public static void Remember(OfflineResult result)
 {
  try{
   Directory.CreateDirectory(Path.GetDirectoryName(NotePath)!);
   if(File.Exists(NotePath))return;
   File.WriteAllText(NotePath,JsonSerializer.Serialize(new{started_at=DateTimeOffset.UtcNow,result.NetworkUp,result.GatewayAnswers,result.NamesWork,result.ServerAnswers}));
  }catch(IOException){}catch(UnauthorizedAccessException){}
 }

 /// <summary>Once Oblivion answers again: send the note (when signed in) and forget it.</summary>
 public static async Task ReportIfBackAsync(IObliFixApi api,CancellationToken cancellation)
 {
  if(!File.Exists(NotePath))return;
  try{
   using var json=JsonDocument.Parse(await File.ReadAllTextAsync(NotePath,cancellation));
   var started=json.RootElement.GetProperty("started_at").GetDateTimeOffset();
   await api.SendOfflineReportAsync(started,DateTimeOffset.UtcNow,json.RootElement.Clone(),cancellation);
   File.Delete(NotePath);
  }catch(Exception error) when(error is IOException or JsonException or KeyNotFoundException or FormatException or ObliFixRejectedException or InvalidOperationException){}
 }
}
