using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Threading;
using System.Threading.Tasks;
using Oblivion.Obli;

// S&D Phase I: Obli fixes things (windows.md H–O). The tray's own safety rules are checked here with
// fakes: no real screen, keyboard or server is touched.
static class FixChecks
{
 static void Check(bool ok,string message){if(!ok)throw new Exception(message);}
 static void Reject(Action action,string message){try{action();}catch(Exception error) when(error is not OutOfMemoryException){return;}throw new Exception(message);}
 static async Task RejectAsync(Func<Task> action,string message){try{await action();}catch(Exception error) when(error is not OutOfMemoryException){return;}throw new Exception(message);}
 static readonly Guid Id=Guid.Parse("cccccccc-cccc-4ccc-8ccc-cccccccccccc");
 static byte[] Json(object value)=>JsonSerializer.SerializeToUtf8Bytes(value);
 static object Session(string state="planned")=>new{
  uuid=Id.ToString("D"),state,title="Printer won’t print",
  checks=new[]{new{tone="ok",text="Printer is switched on"},new{tone="warning",text="3 jobs are stuck"}},
  plan=new object[]{new{text="Cancel the stuck jobs",kind="fix",fix_key="clear_print_queue",it_allows=true},new{text="Print a test page",kind="screen"}},
  minutes=3,note="I’ll use your mouse and keyboard.",expires_at="2026-11-02T10:00:00Z",steps=Array.Empty<object>()};
 static byte[] Reply(int turn,object action,int step=1,int of=3,string narration="Opening Printers")=>Json(new{turn,step,of,narration,action});

 public static int Run()
 {
  // The session answer: strict states, a challenge to confirm the computer, a reason with every refusal.
  var planned=FixProtocol.ParseSession(Json(Session()));
  Check(planned.Id==Id&&planned.State=="planned"&&planned.Plan.Count==2&&planned.Plan[0].ItAllows&&planned.Plan[0].FixKey=="clear_print_queue"&&planned.Checks[1].Tone=="warning","A planned fix didn’t read back.");
  Check(!planned.ToString().Contains("Printer"),"A fix session printed its contents.");
  var confirming=JsonNode.Parse(Json(Session("confirming")))!.AsObject();confirming["challenge"]=new string('a',64);
  Check(FixProtocol.ParseSession(Encoding.UTF8.GetBytes(confirming.ToJsonString())).Challenge==new string('a',64),"The computer check challenge was lost.");
  confirming["challenge"]="not-hex";
  Reject(()=>FixProtocol.ParseSession(Encoding.UTF8.GetBytes(confirming.ToJsonString())),"A bad computer check challenge was accepted.");
  Reject(()=>FixProtocol.ParseSession(Json(Session("refused"))),"A refusal without a reason was accepted.");
  Reject(()=>FixProtocol.ParseSession(Json(Session("hacked"))),"An unknown fix state was accepted.");
  var bell=JsonNode.Parse(Json(Session()))!.AsObject();bell["title"]="Printer\u0007";
  Reject(()=>FixProtocol.ParseSession(Encoding.UTF8.GetBytes(bell.ToJsonString())),"A control character in a fix title was accepted.");
  var refused=JsonNode.Parse(Json(Session("refused")))!.AsObject();
  refused["refusal"]=new JsonObject{["text"]="That changes security settings, so IT has to do it.",["offers"]=new JsonArray(new JsonObject{["key"]="raise_ticket",["label"]="Ask IT"})};
  var parsedRefusal=FixProtocol.ParseSession(Encoding.UTF8.GetBytes(refused.ToJsonString()));
  Check(parsedRefusal.Refusal!.Offers.Single().Key=="raise_ticket","A refusal lost its offers.");
  Reject(()=>FixProtocol.ParseSession(new byte[262145]),"An oversized fix answer was accepted.");

  // Each turn: one action, inside the picture, for this turn only, using ordinary keys.
  var click=FixProtocol.ParseReply(Reply(4,new{type="click",x=10,y=20}),4,800,600);
  Check(click.Action.Type=="click"&&click.Action.X==10&&click.Action.Y==20&&click.Action.TouchesScreen&&!click.Action.Ends,"A click didn’t read back.");
  Reject(()=>FixProtocol.ParseReply(Reply(4,new{type="click",x=800,y=20}),4,800,600),"A click outside the picture was accepted.");
  Reject(()=>FixProtocol.ParseReply(Reply(5,new{type="click",x=1,y=1}),4,800,600),"An answer for another turn was accepted.");
  Reject(()=>FixProtocol.ParseReply(Reply(4,new{type="run",text="format c:"}),4,800,600),"An unknown action was accepted.");
  Reject(()=>FixProtocol.ParseReply(Reply(4,new{type="type",text="a\u001bb"}),4,800,600),"Typing a control character was accepted.");
  Reject(()=>FixProtocol.ParseReply(Reply(4,new{type="wait",seconds=60}),4,800,600),"A long wait was accepted.");
  Reject(()=>FixProtocol.ParseReply(Reply(4,new{type="click",x=1,y=1},step:4,of:3),4,800,600),"A step past the plan was accepted.");
  foreach(var keys in new[]{new[]{"ctrl","alt","delete"},new[]{"ctrl","shift","escape"},new[]{"alt","tab"},new[]{"alt","escape"},new[]{"ctrl","escape"},new[]{"win","r"},new[]{"alt","f4"},new[]{"a","b"},new[]{"ctrl","ctrl"},new[]{"enter","ctrl"},Array.Empty<string>()})
   Reject(()=>FixProtocol.ParseReply(Reply(4,new{type="key",keys}),4,800,600),"Obli accepted the keys "+string.Join("+",keys)+".");
  Check(FixProtocol.ParseReply(Reply(4,new{type="key",keys=new[]{"ctrl","p"}}),4,800,600).Action.Keys!.SequenceEqual(new[]{"ctrl","p"}),"Ctrl+P was refused.");
  var done=FixProtocol.ParseReply(Reply(9,new{type="done",@fixed=true,summary="The printer works again.",steps=new[]{"Cancelled 3 stuck jobs","Printed a test page"},pictures=7,deleted_on="2026-11-09"}),9,800,600);
  Check(done.Action.Ends&&done.Action.Fixed&&done.Action.Done!.Count==2&&done.Action.Pictures==7&&done.Action.DeletedOn=="2026-11-09","The done answer lost what was kept.");
  Check(FixWindow.DayWords("2026-11-05")=="Thu 5 Nov"&&FixWindow.DayWords("soon")=="","The pictures’ deletion day didn’t read as a day.");
  var handed=FixProtocol.ParseReply(Reply(9,new{type="handed_to_it",summary="IT will look at it.",ticket=new{id=42,reference="IT-0042"}}),9,800,600);
  Check(handed.Action.Ends&&handed.Action.Ticket!.Reference=="IT-0042","The hand-over lost its ticket.");

  // The tray's own checks: never a password field, never security or admin windows.
  Check(FixSafety.Blocked(new(false,"Task Manager","Taskmgr.exe"))!=null,"Task Manager wasn’t blocked.");
  Check(FixSafety.Blocked(new(false,"Windows Security","SecHealthUI"))!=null,"Windows Security wasn’t blocked.");
  Check(FixSafety.Blocked(new(false,"Administrator: Windows PowerShell","explorer"))!=null,"A PowerShell window wasn’t blocked by its title.");
  Check(FixSafety.Blocked(new(false,"Devices and Printers","explorer.exe"))==null,"An ordinary settings window was blocked.");
  var typing=new FixAction("type",Text:"hello");
  Check(FixSafety.Refuse(typing,new(true,"Sign in","chrome"))=="password","Obli would type into a password field.");
  Check(FixSafety.Refuse(new FixAction("key",Keys:new[]{"enter"}),new(true,"Sign in","chrome"))=="password","Obli would press keys in a password field.");
  Check(FixSafety.Refuse(new FixAction("click",5,5),new(true,"Sign in","chrome"))==null,"A click near a password field was refused.");
  Check(FixSafety.Refuse(new FixAction("wait",Seconds:1),new(false,"Registry Editor","regedit"))==null,"Waiting was refused.");
  Check(FixSafety.ToAbsolute(799,599,800,600)==(65535,65535)&&FixSafety.ToAbsolute(0,0,800,600)==(0,0),"Picture points didn’t map to the screen.");
  Reject(()=>FixSafety.ToAbsolute(800,0,800,600),"A point off the screen was mapped.");

  // Offline checks: plain advice, no AI, in order of what to try first.
  Check(OfflineChecks.Advise(new(false,true,null,null,null,false,false,false)).Advice.Contains("Wi-Fi"),"No network didn’t point to Wi-Fi.");
  Check(OfflineChecks.Advise(new(false,false,null,null,null,false,false,false)).Advice.Contains("cable"),"No cable network didn’t point to the cable.");
  var router=OfflineChecks.Advise(new(true,true,"House",80,"192.168.1.1",false,false,false));
  Check(router.Advice.Contains("router")&&router.Lines.Any(l=>l.Tone=="critical"&&l.Text.Contains("192.168.1.1"))&&router.Lines[0].Text.Contains("strong"),"A silent router wasn’t named.");
  Check(OfflineChecks.Advise(new(true,false,null,null,"10.0.0.1",true,false,false)).Advice.Contains("internet provider"),"No internet didn’t mention the provider.");
  var serverDown=OfflineChecks.Advise(new(true,false,null,null,"10.0.0.1",true,true,false));
  Check(!serverDown.Online&&serverDown.Lines[^1].Tone=="warning","Oblivion being down wasn’t a warning.");
  Check(OfflineChecks.Advise(new(true,false,null,null,"10.0.0.1",true,true,true)).Online,"Everything working wasn’t online.");
  var wifi=OfflineChecks.ParseWifi("    Name                   : Wi-Fi\r\n    State                  : connected\r\n    SSID                   : Kauri House\r\n    BSSID                  : aa:bb\r\n    Signal                 : 61%\r\n");
  Check(wifi.Up&&wifi.Name=="Kauri House"&&wifi.Signal==61,"netsh Wi-Fi output didn’t read.");
  Check(OfflineChecks.ParseWifi("    State                  : disconnected\r\n    SSID                   : Kauri House\r\n").Name==null,"A disconnected Wi-Fi kept its name.");
  return 6;
 }

 // A fake desktop: records what Obli would have done, never touches the real screen.
 sealed class FakeDesktop:IFixDesktop
 {
  public FixFocus Front=new(false,"Devices and Printers","explorer");
  public readonly List<FixAction> Applied=new();
  public int Pictures;
  public FixPicture Capture(){Pictures++;return new(new byte[]{0xFF,0xD8,0xFF},800,600,DateTimeOffset.UtcNow);}
  public FixFocus Focus()=>Front;
  public void Apply(FixAction action,FixPicture picture)=>Applied.Add(action);
 }
 sealed class FakeView:IFixView
 {
  public bool TypesPassword=true;public int PasswordAsks;public readonly List<string> Said=new();
  public void Working(int step,int of,string narration)=>Said.Add($"{step}/{of} {narration}");
  public Task<bool> PasswordAsync(string text,CancellationToken cancellation){PasswordAsks++;return Task.FromResult(TypesPassword);}
  public void WaitingForIt(string askedAt)=>Said.Add("waiting");
  public void FixRunning(string label)=>Said.Add("fix "+label);
 }
 sealed class FakeApi:IObliFixApi
 {
  public readonly Queue<Func<int,FixReply>> Turns=new();
  public readonly List<FixLast?> Lasts=new();
  public readonly List<string> Stops=new();
  public int Resumes;
  public Action<int>? OnTurn;
  public Task<FixReply> TurnAsync(Guid id,int turn,FixPicture picture,FixLast? last,FixFocus focus,CancellationToken c)
  {Lasts.Add(last);OnTurn?.Invoke(turn);return Task.FromResult(Turns.Count>0?Turns.Dequeue()(turn):new FixReply(turn,1,1,"Waiting",new FixAction("wait",Seconds:1)));}
  public Task<FixSession> StopFixAsync(Guid id,string reason,CancellationToken c){Stops.Add(reason);return Task.FromResult(FixProtocol.ParseSession(Json(new{uuid=Id.ToString("D"),state="stopped",title="Printer",outcome="Stopped. Nothing else was changed."})));}
  public Task<FixSession> ResumeFixAsync(Guid id,CancellationToken c){Resumes++;return Task.FromResult(FixProtocol.ParseSession(Json(Session("working"))));}
  public Task<FixSession> StartFixAsync(Guid r,string p,int s,DiagnosticSnapshot? d,int w,int h,CancellationToken c)=>throw new NotSupportedException();
  public readonly Queue<string> States=new();public int Polls;
  public Task<FixSession> FixAsync(Guid id,CancellationToken c){Polls++;return Task.FromResult(FixProtocol.ParseSession(Json(new{uuid=Id.ToString("D"),state=States.Dequeue(),title="Printer",outcome="IT said no.",ticket=new{id=7,reference="IT-0007"}})));}
  public Task<FixSession> ApproveFixAsync(Guid id,Guid r,CancellationToken c)=>throw new NotSupportedException();
  public Task<FixSession> FixTicketAsync(Guid id,CancellationToken c)=>throw new NotSupportedException();
  public Task<FixSession> CancelFixRequestAsync(Guid id,CancellationToken c)=>throw new NotSupportedException();
  public Task SendOfflineReportAsync(DateTimeOffset s,DateTimeOffset e,object checks,CancellationToken c)=>Task.CompletedTask;
 }
 static Func<TimeSpan,CancellationToken,Task> NoDelay=>(_,token)=>{token.ThrowIfCancellationRequested();return Task.CompletedTask;};

 public static async Task<int> RunAsync()
 {
  var session=FixProtocol.ParseSession(Json(Session("working")));

  // A password field pauses: Obli never types it, the person does, then Obli carries on.
  {
   var desktop=new FakeDesktop();var view=new FakeView();var api=new FakeApi();
   api.Turns.Enqueue(t=>new(t,1,3,"Opening Printers",new FixAction("click",10,10)));
   api.Turns.Enqueue(t=>{desktop.Front=new(true,"Sign in","chrome");return new(t,2,3,"Signing in",new FixAction("type",Text:"secret"));});
   api.Turns.Enqueue(t=>new(t,3,3,"",new FixAction("done",Fixed:true,Summary:"Fixed")));
   var outcome=await new FixRunner(api,desktop,NoDelay).RunAsync(session,view,null,CancellationToken.None);
   Check(outcome.Kind=="done"&&outcome.Final!.Fixed,"A fix with a password pause didn’t finish.");
   Check(desktop.Applied.Count==1&&desktop.Applied[0].Type=="click","Obli typed into a password field.");
   Check(view.PasswordAsks==1&&api.Resumes==1&&api.Lasts[2]!.Result=="blocked","The password pause wasn’t told to Oblivion.");
   Check(api.Stops.Count==0,"A finished fix was also stopped.");
  }
  // The person won’t type the password: Obli stops.
  {
   var desktop=new FakeDesktop{Front=new(true,"Sign in","chrome")};var view=new FakeView{TypesPassword=false};var api=new FakeApi();
   api.Turns.Enqueue(t=>new(t,1,1,"",new FixAction("type",Text:"x")));
   var outcome=await new FixRunner(api,desktop,NoDelay).RunAsync(session,view,null,CancellationToken.None);
   Check(outcome.Kind=="stopped"&&outcome.StopReason=="stop"&&api.Stops.SequenceEqual(new[]{"stop"})&&desktop.Applied.Count==0,"Declining the password didn’t stop Obli.");
  }
  // Security windows are skipped and reported, never touched.
  {
   var desktop=new FakeDesktop{Front=new(false,"Windows Security","SecHealthUI")};var view=new FakeView();var api=new FakeApi();
   api.Turns.Enqueue(t=>new(t,1,2,"",new FixAction("click",5,5)));
   api.Turns.Enqueue(t=>new(t,2,2,"",new FixAction("handed_to_it",Summary:"IT will look")));
   var outcome=await new FixRunner(api,desktop,NoDelay).RunAsync(session,view,null,CancellationToken.None);
   Check(outcome.Kind=="handed_to_it"&&desktop.Applied.Count==0&&api.Lasts[1]!.Result=="blocked","Obli acted on a security window.");
  }
  // Esc or the person's own mouse/keyboard: nothing more reaches the screen, and Oblivion is told why.
  {
   var desktop=new FakeDesktop();var view=new FakeView();var api=new FakeApi();
   using var stop=new CancellationTokenSource();
   var runner=new FixRunner(api,desktop,NoDelay);
   api.Turns.Enqueue(t=>new(t,1,3,"",new FixAction("click",10,10)));
   api.Turns.Enqueue(t=>{runner.StopReason="esc";stop.Cancel();return new(t,2,3,"",new FixAction("click",20,20));});
   var outcome=await runner.RunAsync(session,view,null,stop.Token);
   Check(outcome.Kind=="stopped"&&outcome.StopReason=="esc"&&api.Stops.SequenceEqual(new[]{"esc"}),"Esc didn’t stop the fix.");
   Check(desktop.Applied.Count==1&&desktop.Applied[0].X==10,"Obli acted after Esc.");
   Check(outcome.Final!.Summary=="Stopped. Nothing else was changed.","The stop summary was lost.");
  }
  // Waiting for IT (M): the person keeps working, so no screen pictures; Obli only asks how it stands.
  {
   var desktop=new FakeDesktop();var view=new FakeView();var api=new FakeApi();
   api.Turns.Enqueue(t=>new(t,1,2,"",new FixAction("waiting_it",Label:"2026-11-02T01:00:00Z")));
   api.Turns.Enqueue(t=>new(t,2,2,"",new FixAction("done",Fixed:true,Summary:"Fixed")));
   api.States.Enqueue("waiting_it");api.States.Enqueue("waiting_it");api.States.Enqueue("working");
   var outcome=await new FixRunner(api,desktop,NoDelay).RunAsync(session,view,null,CancellationToken.None);
   Check(outcome.Kind=="done"&&api.Polls==3&&desktop.Pictures==2&&view.Said.Count(s=>s=="waiting")==1,"Waiting for IT took screen pictures or kept interrupting.");
  }
  {
   var desktop=new FakeDesktop();var view=new FakeView();var api=new FakeApi();
   api.Turns.Enqueue(t=>new(t,1,2,"",new FixAction("waiting_it")));
   api.States.Enqueue("handed_to_it");
   var outcome=await new FixRunner(api,desktop,NoDelay).RunAsync(session,view,null,CancellationToken.None);
   Check(outcome.Kind=="handed_to_it"&&outcome.Final!.Ticket!.Reference=="IT-0007"&&desktop.Pictures==1,"IT declining didn’t hand over with the ticket.");
  }
  // At most 60 turns: then Obli stops by itself.
  {
   var desktop=new FakeDesktop();var view=new FakeView();var api=new FakeApi();
   var outcome=await new FixRunner(api,desktop,NoDelay).RunAsync(session,view,null,CancellationToken.None);
   Check(outcome.Kind=="stopped"&&outcome.StopReason=="closed"&&api.Lasts.Count==FixRunner.MaxTurns&&api.Stops.SequenceEqual(new[]{"closed"}),"The turn limit didn’t stop Obli.");
  }
  // Oblivion doesn't answer: stop, fail safe.
  {
   var desktop=new FakeDesktop();var view=new FakeView();var api=new FakeApi{OnTurn=t=>{if(t==2)throw new OperationCanceledException();}};
   api.Turns.Enqueue(t=>new(t,1,3,"",new FixAction("click",10,10)));
   var outcome=await new FixRunner(api,desktop,NoDelay).RunAsync(session,view,null,CancellationToken.None);
   Check(outcome.Kind=="stopped"&&desktop.Applied.Count==1,"A timed-out turn didn’t stop Obli.");
  }

  // The web calls: allowed routes only, the picture and focus sent, answers checked against the session.
  var fake=new Replies();
  using var client=new ObliClient("https://care.example.test",fake);
  var staff=new StaffSession(new string('b',64),client.Origin,DateTimeOffset.UtcNow.AddMinutes(5));
  var fixApi=new ObliFixApi(client,staff);
  fake.Json(new{turn=1,step=1,of=2,narration="Opening Printers",action=new{type="click",x=3,y=4}});
  var reply=await fixApi.TurnAsync(Id,1,new FixPicture(new byte[]{1,2,3},800,600,DateTimeOffset.Parse("2026-11-02T01:00:00Z")),new FixLast(0,"done",new string('x',300)),new FixFocus(false,"Printers\u0007","explorer.exe"),CancellationToken.None);
  Check(reply.Action.X==3,"The turn answer didn’t read.");
  var sent=JsonNode.Parse(fake.Seen[^1].Body)!;
  Check(fake.Seen[^1].Path=="/api/obli/fixes/"+Id.ToString("D")+"/turn"&&fake.Seen[^1].Auth=="Bearer "+new string('b',64),"The turn went to the wrong place.");
  Check(sent["picture"]!["jpeg_base64"]!.GetValue<string>()=="AQID"&&sent["picture"]!["taken_at"]!.GetValue<string>()=="2026-11-02T01:00:00Z","The picture wasn’t sent.");
  Check(sent["last"]!["detail"]!.GetValue<string>().Length==200&&sent["focus"]!["window_title"]!.GetValue<string>()=="Printers","The turn details weren’t trimmed.");
  fake.Json(new{uuid="dddddddd-dddd-4ddd-8ddd-dddddddddddd",state="working",title="Other"});
  await RejectAsync(()=>fixApi.FixAsync(Id,CancellationToken.None),"An answer for another session was accepted.");
  await RejectAsync(()=>fixApi.StopFixAsync(Id,"because",CancellationToken.None),"An unknown stop reason was sent.");
  await RejectAsync(()=>fixApi.TurnAsync(Id,1,new FixPicture(new byte[524289],800,600,DateTimeOffset.UtcNow),null,new(false,"",""),CancellationToken.None),"An oversized picture was sent.");
  fake.Raw("{}",HttpStatusCode.TooManyRequests);
  try{await fixApi.ResumeFixAsync(Id,CancellationToken.None);throw new Exception("A refused fix call succeeded.");}
  catch(ObliFixRejectedException error){Check(error.Message.Contains("limit"),"The limit message was lost.");}
  int before=fake.Seen.Count;
  await RejectAsync(()=>fixApi.StartFixAsync(Guid.NewGuid(),"short",1,null,800,600,CancellationToken.None),"A one-word problem was sent.");
  Check(fake.Seen.Count==before,"A refused request still reached Oblivion.");
  return 3;
 }

 sealed class Replies:HttpMessageHandler
 {
  readonly Queue<HttpResponseMessage> responses=new();
  public readonly List<(string Method,string Path,string? Auth,string Body)> Seen=new();
  public void Json(object value)=>Raw(JsonSerializer.Serialize(value));
  public void Raw(string json,HttpStatusCode status=HttpStatusCode.OK)=>responses.Enqueue(new(status){Content=new StringContent(json,Encoding.UTF8,"application/json")});
  protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request,CancellationToken cancellation)
  {
   Check(request.RequestUri!.GetLeftPart(UriPartial.Authority)=="https://care.example.test","A fix call changed origin.");
   Seen.Add((request.Method.Method,request.RequestUri.PathAndQuery,request.Headers.Authorization?.ToString(),request.Content==null?"":await request.Content.ReadAsStringAsync(cancellation)));
   return responses.Dequeue();
  }
 }
}
