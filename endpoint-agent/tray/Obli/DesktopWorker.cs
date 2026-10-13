using System;
using System.Diagnostics;
using System.IO;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

internal sealed class DesktopWorker : IDisposable
{
 readonly object gate=new();
 readonly CancellationTokenSource stop=new();
 readonly IDesktopBackend backend;
 readonly IDesktopChannel channel;
 readonly Func<long> clock;
 bool stopped,desktopActive;
 long deadline;
 ulong inputSequence,frameSequence;
 public DesktopWorker(IDesktopBackend backend,IDesktopChannel channel,Func<long>? clock=null){this.backend=backend;this.channel=channel;this.clock=clock??Stopwatch.GetTimestamp;}
 internal bool Stopped {get{lock(gate)return stopped;}}
 public void Stop(){lock(gate){if(stopped)return;stopped=true;try{backend.Release();}catch(Exception e)when(e is not OutOfMemoryException){}}stop.Cancel();}
 public void Dispose(){Stop();stop.Dispose();}
 bool AliveLocked()=>!stopped&&clock()<deadline&&backend.CanUse();
 internal void AcceptLease(DateTimeOffset expires,DateTimeOffset now,bool active=false)
 {
  lock(gate){if(stopped||expires<=now||expires>now.AddSeconds(15))throw new InvalidDataException();desktopActive=active;deadline=clock()+(long)((expires-now).TotalSeconds*Stopwatch.Frequency);}
 }
 internal void ApplyInputs(DesktopGrant grant)
 {
  lock(gate){
   if(grant.Status!="active"||!desktopActive||!AliveLocked())throw new InvalidDataException();
   foreach(var input in grant.Inputs){
    if(grant.Access!="control"||input.Sequence<=inputSequence||!AliveLocked())throw new InvalidDataException();
    inputSequence=input.Sequence;backend.Apply(input);
   }
  }
 }
 internal DesktopFrame Capture()
 {
  lock(gate){
   if(!desktopActive||!AliveLocked())throw new InvalidDataException();
   var frame=backend.Capture();
   if(!AliveLocked()||frame.Jpeg.Length==0||frame.Jpeg.Length>524288||frame.Width<1||frame.Width>4096||frame.Height<1||frame.Height>4096||(long)frame.Width*frame.Height>8388608)throw new InvalidDataException();
   return frame;
  }
 }
 public async Task RunAsync(Uri origin,RemoteState approved,Action active,CancellationToken cancellation)
 {
  using var linked=CancellationTokenSource.CreateLinkedTokenSource(cancellation,stop.Token);
  var token=linked.Token;
  if(approved.Id==null||approved.Access is not ("view" or "control")||approved.Status!="allowed")throw new InvalidDataException();
  string session=approved.Id,access=approved.Access,worker="",desktop="";
  bool announced=false;
  long nextFrame=0;
  // Watchdog remains independent of a blocked pipe call.
  using var watchdog=new Timer(_=>{
   try{bool expired;lock(gate){expired=!stopped&&deadline!=0&&!AliveLocked();}if(expired)Stop();}
   catch(Exception e)when(e is not OutOfMemoryException){Stop();}
  },null,100,100);
  try{
   while(!token.IsCancellationRequested){
    var request=worker==""?(object)new{version=1,operation="desktop_open",session_uuid=session}:new{version=1,operation="desktop_poll",session_uuid=session,worker_token=worker};
    var grant=DesktopGrant.Parse(await channel.ExchangeDesktopAsync(request,token),origin,session,access,DateTimeOffset.UtcNow);
    token.ThrowIfCancellationRequested();
    if(desktop!=""&&grant.Desktop!=desktop||worker!=""&&grant.Token!=worker)throw new InvalidDataException();
    desktop=grant.Desktop;worker=grant.Token;
    AcceptLease(grant.Expires,DateTimeOffset.UtcNow,grant.Status=="active");
    if(grant.Status=="active"){
     if(!announced){active();announced=true;}
     ApplyInputs(grant);
     if(clock()>=nextFrame){
      var frame=Capture();
      token.ThrowIfCancellationRequested();
      var reply=DesktopGrant.Parse(await channel.ExchangeDesktopAsync(new{version=1,operation="desktop_frame",session_uuid=session,worker_token=worker,
       sequence=++frameSequence,width=frame.Width,height=frame.Height,jpeg=Convert.ToBase64String(frame.Jpeg)},token),origin,session,access,DateTimeOffset.UtcNow);
      if(reply.Status!="active"||reply.Token!=worker||reply.Desktop!=desktop||reply.Inputs.Count!=0)throw new InvalidDataException();
      nextFrame=clock()+Stopwatch.Frequency/5;
     }
    }else if(announced)throw new InvalidDataException();
    await Task.Delay(50,token);
   }
  }finally{Stop();}
 }
}
