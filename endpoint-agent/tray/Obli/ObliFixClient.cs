using System;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

/// <summary>A fix request the server turned down, with words to show the person.</summary>
public sealed class ObliFixRejectedException:Exception{public ObliFixRejectedException(string message):base(message){}}

/// <summary>What the tray sends with each turn: the picture of the screen and what happened last.</summary>
public sealed record FixPicture(byte[] Jpeg,int Width,int Height,DateTimeOffset TakenAt){public override string ToString()=>"Private screen picture";}
public sealed record FixLast(int Turn,string Result,string Detail);

/// <summary>S&D Phase I: the fix-session calls (sd-obli-fix-protocol.md), on the person's Obli session.</summary>
public interface IObliFixApi
{
 Task<FixSession> StartFixAsync(Guid requestId,string problem,int siteId,DiagnosticSnapshot? checks,int screenWidth,int screenHeight,CancellationToken cancellation);
 Task<FixSession> FixAsync(Guid id,CancellationToken cancellation);
 Task<FixSession> ApproveFixAsync(Guid id,Guid requestId,CancellationToken cancellation);
 Task<FixReply> TurnAsync(Guid id,int turn,FixPicture picture,FixLast? last,FixFocus focus,CancellationToken cancellation);
 Task<FixSession> StopFixAsync(Guid id,string reason,CancellationToken cancellation);
 Task<FixSession> ResumeFixAsync(Guid id,CancellationToken cancellation);
 Task<FixSession> FixTicketAsync(Guid id,CancellationToken cancellation);
 Task<FixSession> CancelFixRequestAsync(Guid id,CancellationToken cancellation);
 Task SendOfflineReportAsync(DateTimeOffset started,DateTimeOffset ended,object checks,CancellationToken cancellation);
}

public sealed partial class ObliClient
{
 static readonly string[] FixSubPaths={"approve","turn","stop","resume","ticket","cancel-request"};
 static bool IsFixRoute(HttpMethod method,string path)
 {
  if(path=="fixes"||path=="offline-reports")return method==HttpMethod.Post;
  if(!path.StartsWith("fixes/",StringComparison.Ordinal))return false;
  string rest=path[6..];
  int slash=rest.IndexOf('/');
  string id=slash<0?rest:rest[..slash];
  if(!CanonicalRequestUuid(id))return false;
  if(slash<0)return method==HttpMethod.Get;
  return method==HttpMethod.Post&&Array.IndexOf(FixSubPaths,rest[(slash+1)..])>=0;
 }
 // Planning and each turn wait on the AI.
 static bool FixNeedsTime(HttpMethod method,string path)=>method==HttpMethod.Post&&(path=="fixes"||path.EndsWith("/turn",StringComparison.Ordinal));
 static Exception FixFailure(HttpStatusCode status)=>new ObliFixRejectedException((int)status switch{
  404=>"Obli can’t fix things on this computer yet. Ask IT for help instead.",
  409=>"This fix changed on the server. Start again if it’s still a problem.",
  422=>"Obli couldn’t accept that. Try saying the problem another way.",
  429=>"Obli has reached today’s limit. Ask IT for help instead.",
  503=>"Obli’s fixes aren’t available right now. Ask IT for help instead.",
  _=>"Oblivion didn’t confirm that. Nothing more was done on your computer."});

 internal static byte[] Bytes(JsonDocument json)=>JsonSerializer.SerializeToUtf8Bytes(json.RootElement);

 public async Task<FixSession> StartFixAsync(StaffSession session,Guid requestId,string problem,int siteId,DiagnosticSnapshot? checks,int screenWidth,int screenHeight,CancellationToken cancellation)
 {
  string text=problem.Trim();
  if(requestId==Guid.Empty||siteId<1||text.Length is <10 or >1000)throw new ArgumentException("Say what’s wrong in a sentence or two.");
  using var json=await SendAsync(HttpMethod.Post,"fixes",new{request_uuid=requestId,problem=text,site_id=siteId,checks=checks?.Json,screen=new{width=screenWidth,height=screenHeight}},session,cancellation);
  return FixProtocol.ParseSession(Bytes(json));
 }
 public async Task<FixSession> FixAsync(StaffSession session,Guid id,CancellationToken cancellation)
 {
  using var json=await SendAsync(HttpMethod.Get,"fixes/"+id.ToString("D"),null,session,cancellation);
  var parsed=FixProtocol.ParseSession(Bytes(json));
  return parsed.Id==id?parsed:throw new InvalidDataException("Obli answered for another session.");
 }
 async Task<FixSession> FixPostAsync(StaffSession session,Guid id,string what,object? body,CancellationToken cancellation)
 {
  using var json=await SendAsync(HttpMethod.Post,"fixes/"+id.ToString("D")+"/"+what,body??new{},session,cancellation);
  var parsed=FixProtocol.ParseSession(Bytes(json));
  return parsed.Id==id?parsed:throw new InvalidDataException("Obli answered for another session.");
 }
 public Task<FixSession> ApproveFixAsync(StaffSession session,Guid id,Guid requestId,CancellationToken cancellation)=>FixPostAsync(session,id,"approve",new{request_uuid=requestId},cancellation);
 public Task<FixSession> StopFixAsync(StaffSession session,Guid id,string reason,CancellationToken cancellation)=>
  reason is "stop" or "esc" or "user_input" or "closed"?FixPostAsync(session,id,"stop",new{reason},cancellation):throw new ArgumentException();
 public Task<FixSession> ResumeFixAsync(StaffSession session,Guid id,CancellationToken cancellation)=>FixPostAsync(session,id,"resume",null,cancellation);
 public Task<FixSession> FixTicketAsync(StaffSession session,Guid id,CancellationToken cancellation)=>FixPostAsync(session,id,"ticket",null,cancellation);
 public Task<FixSession> CancelFixRequestAsync(StaffSession session,Guid id,CancellationToken cancellation)=>FixPostAsync(session,id,"cancel-request",null,cancellation);
 public async Task<FixReply> TurnAsync(StaffSession session,Guid id,int turn,FixPicture picture,FixLast? last,FixFocus focus,CancellationToken cancellation)
 {
  if(turn<1||picture.Jpeg.Length is 0 or >524288)throw new ArgumentException();
  using var json=await SendAsync(HttpMethod.Post,"fixes/"+id.ToString("D")+"/turn",new{
   turn,
   picture=new{jpeg_base64=Convert.ToBase64String(picture.Jpeg),width=picture.Width,height=picture.Height,taken_at=picture.TakenAt.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ")},
   last=last==null?null:new{turn=last.Turn,result=last.Result,detail=last.Detail.Length>200?last.Detail[..200]:last.Detail},
   focus=new{is_password=focus.IsPassword,window_title=Clip(focus.WindowTitle,120),process=Clip(focus.Process,64)}},session,cancellation);
  return FixProtocol.ParseReply(Bytes(json),turn,picture.Width,picture.Height);
 }
 public async Task SendOfflineReportAsync(StaffSession session,DateTimeOffset started,DateTimeOffset ended,object checks,CancellationToken cancellation)
 {
  using var _=await SendAsync(HttpMethod.Post,"offline-reports",new{started_at=started.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ"),ended_at=ended.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ"),checks},session,cancellation);
 }
 static string Clip(string value,int limit)
 {
  var clean=new string(value.Where(c=>!char.IsControl(c)).ToArray());
  return clean.Length>limit?clean[..limit]:clean;
 }
}

/// <summary>Binds the fix calls to one signed-in Obli session.</summary>
public sealed class ObliFixApi(ObliClient client,StaffSession session):IObliFixApi
{
 public Task<FixSession> StartFixAsync(Guid requestId,string problem,int siteId,DiagnosticSnapshot? checks,int w,int h,CancellationToken c)=>client.StartFixAsync(session,requestId,problem,siteId,checks,w,h,c);
 public Task<FixSession> FixAsync(Guid id,CancellationToken c)=>client.FixAsync(session,id,c);
 public Task<FixSession> ApproveFixAsync(Guid id,Guid requestId,CancellationToken c)=>client.ApproveFixAsync(session,id,requestId,c);
 public Task<FixReply> TurnAsync(Guid id,int turn,FixPicture picture,FixLast? last,FixFocus focus,CancellationToken c)=>client.TurnAsync(session,id,turn,picture,last,focus,c);
 public Task<FixSession> StopFixAsync(Guid id,string reason,CancellationToken c)=>client.StopFixAsync(session,id,reason,c);
 public Task<FixSession> ResumeFixAsync(Guid id,CancellationToken c)=>client.ResumeFixAsync(session,id,c);
 public Task<FixSession> FixTicketAsync(Guid id,CancellationToken c)=>client.FixTicketAsync(session,id,c);
 public Task<FixSession> CancelFixRequestAsync(Guid id,CancellationToken c)=>client.CancelFixRequestAsync(session,id,c);
 public Task SendOfflineReportAsync(DateTimeOffset s,DateTimeOffset e,object checks,CancellationToken c)=>client.SendOfflineReportAsync(session,s,e,checks,c);
}
