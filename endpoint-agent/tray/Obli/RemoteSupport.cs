using System;
using System.Collections.Generic;
using System.IO;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Threading;

namespace Oblivion.Obli;

public sealed record RemoteState(string Origin,string Status,string? Id,string? Token,string? Technician,string? Purpose,string? Access,DateTimeOffset? Expires)
{
 public bool Pending => Status=="requested";
 public bool HasSession => Status is "requested" or "waiting" or "allowed" or "active";
 public static RemoteState Parse(ReadOnlyMemory<byte> data,Uri expected,DateTimeOffset now)
 {
  if(data.Length==0||data.Length>4096)throw new InvalidDataException();
  using var json=JsonDocument.Parse(data,new JsonDocumentOptions{MaxDepth=3});
  var fields=new Dictionary<string,JsonElement>(StringComparer.Ordinal);
  foreach(var property in json.RootElement.EnumerateObject())
   if(property.Name is not ("protocol" or "server_origin" or "status" or "session_uuid" or "consent_token" or "technician" or "purpose" or "access_mode" or "expires_at")||!fields.TryAdd(property.Name,property.Value))throw new InvalidDataException();
  string Text(string key,int maximum,bool optional=false){
   if(!fields.TryGetValue(key,out var value)){if(optional)return "";throw new InvalidDataException();}
   string text=value.GetString()??throw new InvalidDataException();
   if(text.Length>maximum)throw new InvalidDataException();
   foreach(char c in text)if(char.IsControl(c)||char.GetUnicodeCategory(c)==System.Globalization.UnicodeCategory.Format)throw new InvalidDataException();
   return text;
  }
  if(fields["protocol"].GetInt32()!=1)throw new InvalidDataException();
  string origin=Text("server_origin",2048),status=Text("status",20);
  if(!Uri.TryCreate(origin,UriKind.Absolute,out var server)||server.Scheme!="https"||server.UserInfo!=""||server.AbsolutePath!="/"||server.Query!=""||server.Fragment!=""||
     server.GetLeftPart(UriPartial.Authority)!=expected.GetLeftPart(UriPartial.Authority))throw new InvalidDataException();
  if(status is not ("unavailable" or "idle" or "requested" or "waiting" or "allowed" or "active" or "stopped" or "declined"))throw new InvalidDataException();
  bool session=status is "requested" or "waiting" or "allowed" or "active";
  if(!session){if(fields.Count!=3)throw new InvalidDataException();return new(origin,status,null,null,null,null,null,null);}
  string id=Text("session_uuid",36),token=Text("consent_token",64,true),technician=Text("technician",200),purpose=Text("purpose",1000),access=Text("access_mode",10);
  if(!Guid.TryParseExact(id,"D",out _)||technician.Length==0||purpose.Length==0||access is not ("view" or "control"))throw new InvalidDataException();
  if(!DateTimeOffset.TryParse(Text("expires_at",32),System.Globalization.CultureInfo.InvariantCulture,System.Globalization.DateTimeStyles.AssumeUniversal,out var expires)||expires<=now||expires>now.AddSeconds(status is "allowed" or "active"?15:120))throw new InvalidDataException();
  if(status=="requested"){if(token.Length!=64||!System.Text.RegularExpressions.Regex.IsMatch(token,"\\A[a-f0-9]{64}\\z")||fields.Count!=9)throw new InvalidDataException();}
  else if(token.Length!=0||fields.Count!=8)throw new InvalidDataException();
  return new(origin,status,id,token,technician,purpose,access,expires);
 }
}

public partial class MainWindow
{
 readonly DispatcherTimer remoteTimer=new(){Interval=TimeSpan.FromSeconds(2)};
 RemoteState? remoteState;
 bool remoteBusy;
 long remoteRenewAfter;
 void SuspendRemoteRenewal()=>remoteRenewAfter=System.Diagnostics.Stopwatch.GetTimestamp()+20*System.Diagnostics.Stopwatch.Frequency;
 internal bool RemoteRenewalSuspended=>System.Diagnostics.Stopwatch.GetTimestamp()<remoteRenewAfter;
 long remoteGeneration;
 string? shownRequest;
 void InitialiseRemoteSupport()
 {
  remoteTimer.Tick+=async(_,_)=>await RefreshRemoteAsync();
  StateChanged+=(_,_)=>{if(WindowState==WindowState.Minimized&&remoteState?.HasSession==true)CancelRemoteLocally();};
  Closed+=(_,_)=>{remoteTimer.Stop();CancelRemoteLocally();};
 }
 async Task RefreshRemoteAsync()
 {
  if(remoteBusy||RemoteRenewalSuspended||configuredOrigin==null||lifetime.IsCancellationRequested)return;
  string origin=configuredOrigin;long generation=remoteGeneration;remoteBusy=true;
  try{
   var state=await client.ReadRemoteAsync(new Uri(origin),lifetime.Token);
   if(generation!=remoteGeneration||configuredOrigin!=origin)return;
   ApplyRemoteState(state);StartDesktopIfAllowed(state);
   if(state.Pending&&shownRequest!=state.Id){shownRequest=state.Id;ShowFromTray("device");}
  }catch(Exception error)when(error is not OutOfMemoryException){
   if(generation==remoteGeneration&&remoteState?.HasSession==true){RemoteNotice.Text="The computer service could not confirm this request. Approval is unavailable; use Stop or wait for it to expire.";AllowRemoteButton.IsEnabled=DeclineRemoteButton.IsEnabled=false;StopRemoteButton.Visibility=Visibility.Visible;}
  }finally{remoteBusy=false;}
 }
 internal void ApplyRemoteState(RemoteState state)
 {
  if(!state.HasSession)StopDesktopLocally();
  remoteState=state;
  RemotePanel.Visibility=state.HasSession?Visibility.Visible:Visibility.Collapsed;
  AllowRemoteButton.Visibility=DeclineRemoteButton.Visibility=state.Pending?Visibility.Visible:Visibility.Collapsed;
  AllowRemoteButton.IsEnabled=DeclineRemoteButton.IsEnabled=state.Pending;
  StopRemoteButton.Visibility=state.Status is "waiting" or "allowed" or "active"?Visibility.Visible:Visibility.Collapsed;
  RemoteTitle.Text=state.Pending?"Allow remote connection?":state.Status=="active"?"Your primary screen is being shared":"Waiting for the technician connection";
  RemotePerson.Text=state.HasSession?(state.Technician??"")+" · "+(state.Access=="control"?"View and control":"View only"):"";
  RemotePurpose.Text=state.Purpose??"";
  RemoteNotice.Text=state.Pending?(state.Access=="control"?"They may see your primary screen and use your mouse and keyboard. Obli’s private chat and tickets will be hidden. Recording is off.":"They may see your primary screen. They cannot use your mouse or keyboard. Obli’s private chat and tickets will be hidden. Recording is off."):state.Status=="active"?"Only your primary screen is shared. Obli’s private chat and tickets are hidden. Recording is off. Select Stop to end this connection.":"You have allowed this request. No screen connection has been confirmed. You can withdraw permission with Stop.";
 }
 async Task DecideRemoteAsync(string decision)
 {
  var state=remoteState;if(state?.Pending!=true||configuredOrigin!=state.Origin||remoteBusy)return;
  if(state.Expires<=DateTimeOffset.UtcNow){CancelRemoteLocally();return;}
  if(decision=="allow")locallyAllowedSession=state.Id;
  long generation=++remoteGeneration;remoteBusy=true;AllowRemoteButton.IsEnabled=DeclineRemoteButton.IsEnabled=false;
  // Stop remains available while an approval reply is uncertain.
  StopRemoteButton.Visibility=Visibility.Visible;
  RemoteNotice.Text=decision=="allow"?"Sending your approval…":"Declining this request…";
  try{
   var next=await client.DecideRemoteAsync(new Uri(state.Origin),state,decision,lifetime.Token);
   if(generation==remoteGeneration)ApplyRemoteState(next);
  }catch(Exception error)when(error is not OutOfMemoryException){
   if(generation==remoteGeneration){RemoteNotice.Text="Your answer could not be confirmed. Use Stop to withdraw this request.";AllowRemoteButton.IsEnabled=DeclineRemoteButton.IsEnabled=false;}
  }finally{remoteBusy=false;}
 }
 async void AllowRemoteClick(object sender,RoutedEventArgs e)=>await DecideRemoteAsync("allow");
 async void DeclineRemoteClick(object sender,RoutedEventArgs e)=>await DecideRemoteAsync("decline");
 async void StopRemoteClick(object sender,RoutedEventArgs e)
 {
  var state=remoteState;if(state?.Id==null)return;
  StopDesktopLocally();locallyAllowedSession=null;
  SuspendRemoteRenewal();
  long generation=++remoteGeneration;AllowRemoteButton.IsEnabled=DeclineRemoteButton.IsEnabled=false;
  RemoteNotice.Text="Stopping this request…";
  try{var next=await client.StopRemoteAsync(new Uri(state.Origin),state.Id,lifetime.Token);if(generation==remoteGeneration)ApplyRemoteState(next);}
  catch(Exception error)when(error is not OutOfMemoryException){if(generation==remoteGeneration)RemoteNotice.Text="Stop could not be confirmed by the computer service. Try Stop again. The service also withdraws permission when the session or its connection expires.";}
 }
 void CancelRemoteLocally()
 {
  StopDesktopLocally();locallyAllowedSession=null;
  var previous=remoteState;++remoteGeneration;remoteState=null;shownRequest=null;
  if(previous?.HasSession==true)SuspendRemoteRenewal();
  RemotePanel.Visibility=Visibility.Collapsed;RemotePerson.Text=RemotePurpose.Text=RemoteNotice.Text="";
  if(previous?.Id!=null)_=StopRemoteOnChangeAsync(previous);
 }
 async Task StopRemoteOnChangeAsync(RemoteState previous)
 {
  using var deadline=new CancellationTokenSource(TimeSpan.FromSeconds(4));
  try{await client.StopRemoteAsync(new Uri(previous.Origin),previous.Id!,deadline.Token);}
  catch(Exception error)when(error is not OutOfMemoryException){/* OS/session liveness independently expires local approval. */}
 }
}
