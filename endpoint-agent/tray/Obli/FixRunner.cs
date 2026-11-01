using System;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

/// <summary>How a fix session ended, for the done (K), hand-over (L) and stopped screens.</summary>
public sealed record FixOutcome(string Kind,FixAction? Final,string? StopReason);

/// <summary>What the person sees while Obli works (the Stop bar and the pauses).</summary>
public interface IFixView
{
 void Working(int step,int of,string narration);
 /// <summary>J: Obli stops and asks the person to type a password themselves. True when they have.</summary>
 Task<bool> PasswordAsync(string text,CancellationToken cancellation);
 /// <summary>M: waiting for IT to approve a one-off fix; the person can keep working.</summary>
 void WaitingForIt(string askedAt);
 void FixRunning(string label);
}

/// <summary>
/// S&D Phase I: runs an approved fix session, one checked action per turn (sd-obli-fix-protocol.md).
/// Every screen action passes the tray's own checks right before it runs, on a fresh look at what's
/// in front; a password field pauses instead of typing. Stop, Esc or the person's own input cancels
/// <paramref name="stop"/>, and then nothing more is sent to the screen. At most 60 turns and 15 minutes.
/// </summary>
public sealed class FixRunner(IObliFixApi api,IFixDesktop desktop,Func<TimeSpan,CancellationToken,Task>? delay=null)
{
 public const int MaxTurns=60;
 public static readonly TimeSpan MaxTime=TimeSpan.FromMinutes(15);
 readonly Func<TimeSpan,CancellationToken,Task> wait=delay??((time,token)=>Task.Delay(time,token));

 /// <summary>The guard's reason when it cancels; read after cancellation.</summary>
 public string StopReason{get;set;}="stop";

 public async Task<FixOutcome> RunAsync(FixSession session,IFixView view,FixGuard? guard,CancellationToken stop)
 {
  var started=DateTimeOffset.UtcNow;
  FixLast? last=null;
  try{
   for(int turn=1;;turn++){
    if(turn>MaxTurns||DateTimeOffset.UtcNow-started>MaxTime)return await EndAsync(session,"closed");
    stop.ThrowIfCancellationRequested();
    var picture=desktop.Capture();
    var reply=await api.TurnAsync(session.Id,turn,picture,last,desktop.Focus(),stop);
    stop.ThrowIfCancellationRequested();
    var action=reply.Action;
    if(action.Ends)return new FixOutcome(action.Type,action,null);
    switch(action.Type){
     case "wait":
      view.Working(reply.Step,reply.Of,reply.Narration);
      await wait(TimeSpan.FromSeconds(action.Seconds),stop);
      last=new FixLast(turn,"done","");
      break;
     case "fix_running":
      guard?.Pause();view.FixRunning(action.Label??"");
      await wait(TimeSpan.FromSeconds(3),stop);
      last=null;
      break;
     case "waiting_it":{
      // M: the person keeps working while IT decides, so no screen pictures are taken; Obli only asks
      // Oblivion how the request stands. Waiting doesn't count towards the 15 minutes of work.
      guard?.Pause();view.WaitingForIt(action.Label??"");
      var waitStarted=DateTimeOffset.UtcNow;
      FixSession now;
      do{await wait(TimeSpan.FromSeconds(10),stop);now=await api.FixAsync(session.Id,stop);}while(now.State is "waiting_it");
      started+=DateTimeOffset.UtcNow-waitStarted;
      stop.ThrowIfCancellationRequested();
      if(now.State is "done")return new FixOutcome("done",new FixAction("done",Summary:now.Outcome),null);
      if(now.State is "handed_to_it" or "needs_it")return new FixOutcome("handed_to_it",new FixAction("handed_to_it",Summary:now.Outcome,Ticket:now.Ticket),null);
      if(now.State is not("working" or "planned"))return new FixOutcome("stopped",new FixAction("stopped",Summary:now.Outcome),now.State);
      last=null;
      break;}
     case "pause_password":
      last=await PasswordAsync(session,view,guard,action.Text??"",turn,stop);
      break;
     default:{
      guard?.Resume();
      view.Working(reply.Step,reply.Of,reply.Narration);
      // A fresh look right before acting: the window in front may have changed.
      string? refusal=FixSafety.Refuse(action,desktop.Focus());
      if(refusal=="password"){last=await PasswordAsync(session,view,guard,"This is asking for a password. Please type it yourself; I never type passwords. I’ll carry on when it’s done.",turn,stop);break;}
      if(refusal!=null){last=new FixLast(turn,"blocked",refusal);break;}
      stop.ThrowIfCancellationRequested();
      try{desktop.Apply(action,picture);last=new FixLast(turn,"done","");}
      catch(InvalidOperationException error){last=new FixLast(turn,"failed",error.Message);}
      await wait(TimeSpan.FromMilliseconds(500),stop);
      break;}
    }
   }
  }catch(OperationCanceledException){
   // The person took over, chose Stop, or Oblivion didn't answer in time: stop either way (fail safe).
   return await EndAsync(session,StopReason);
  }
 }

 async Task<FixLast> PasswordAsync(FixSession session,IFixView view,FixGuard? guard,string text,int turn,CancellationToken stop)
 {
  guard?.Pause();
  bool typed=await view.PasswordAsync(text,stop);
  if(!typed){StopReason="stop";throw new OperationCanceledException();}
  await api.ResumeFixAsync(session.Id,stop);
  guard?.Resume();
  return new FixLast(turn,"blocked","A password was asked for; the person typed it themselves.");
 }

 async Task<FixOutcome> EndAsync(FixSession session,string reason)
 {
  // Tell the server even though the person's cancellation has fired; a short, separate wait.
  using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(10));
  FixSession? ended=null;
  try{ended=await api.StopFixAsync(session.Id,reason,timeout.Token);}catch(Exception error) when(error is not OutOfMemoryException){}
  return new FixOutcome("stopped",new FixAction("stopped",Summary:ended?.Outcome),reason);
 }
}
