using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace Oblivion.Obli;

/// <summary>
/// S&D Phase I (windows.md H–O): "Ask Obli". Obli checks first and shows its plan; nothing happens
/// until the person presses Fix it (D16). While it works this window steps aside and the Stop bar
/// shows. It stops for passwords (J), says what it did (K), hands over to IT (L), waits for IT's
/// one-off approval (M), runs built-in checks without internet (N), and refuses unsafe asks (O).
/// </summary>
public sealed class FixWindow:Window,IFixView
{
 readonly IObliFixApi? api;
 readonly ServiceClient service;
 readonly Uri origin;
 readonly IReadOnlyList<SupportSite> sites;
 readonly Func<FixBar,IFixDesktop> desktopFor;
 readonly ContentControl body=new();
 readonly FixBar bar=new();
 FixSession? session;
 CancellationTokenSource? working;
 FixRunner? runner;
 TaskCompletionSource<bool>? password;
 string asked="";
 (int Step,int Of,string Narration) lastStep=(0,0,"");
 /// <summary>Raised when the person asks to sign in first.</summary>
 public event Action? SignInRequested;

 public FixWindow(IObliFixApi? api,ServiceClient service,Uri origin,IReadOnlyList<SupportSite> sites,Func<FixBar,IFixDesktop>? desktopFor=null)
 {
  this.api=api;this.service=service;this.origin=origin;this.sites=sites;
  this.desktopFor=desktopFor??(b=>new WindowsFixDesktop(()=>b.Handle));
  Title="Obli";Width=440;SizeToContent=SizeToContent.Height;ResizeMode=ResizeMode.NoResize;WindowStartupLocation=WindowStartupLocation.Manual;
  var area=SystemParameters.WorkArea;Left=area.Right-Width-16;Top=area.Top+80;
  Content=new Border{Padding=new Thickness(20),Child=body};
  Loaded+=(_,_)=>{if(TryFindResource("BackgroundBrush") is Brush b)Background=b;};
  bar.StopClicked+=()=>StopWork("stop");
  Closed+=(_,_)=>{StopWork("closed");bar.Close();};
  Ask();
 }

 /* ------------------------------- building blocks ------------------------------- */

 TextBlock Line(string text,bool strong=false,bool muted=false)=>new(){Text=text,TextWrapping=TextWrapping.Wrap,Margin=new Thickness(0,0,0,8),
  FontWeight=strong?FontWeights.SemiBold:FontWeights.Normal,Foreground=muted&&TryFindResource("MutedForegroundBrush") is Brush m?m:(TryFindResource("ForegroundBrush") as Brush??Brushes.Black)};
 Button Primary(string text,Action click)
 {
  var button=new Button{Content=text,Padding=new Thickness(16,6,16,6),Margin=new Thickness(8,0,0,0),IsDefault=true};
  if(TryFindResource("PrimaryBrush") is Brush p)button.Background=p;
  if(TryFindResource("OnPrimaryBrush") is Brush o)button.Foreground=o;
  button.Click+=(_,_)=>click();return button;
 }
 Button Secondary(string text,Action click){var button=new Button{Content=text,Padding=new Thickness(16,6,16,6),Margin=new Thickness(8,0,0,0)};button.Click+=(_,_)=>click();return button;}
 static StackPanel Buttons(params Button[] buttons){var row=new StackPanel{Orientation=Orientation.Horizontal,HorizontalAlignment=HorizontalAlignment.Right,Margin=new Thickness(0,12,0,0)};foreach(var b in buttons)row.Children.Add(b);return row;}
 TextBlock Check(FixCheck check)=>Line((check.Tone=="ok"?"✓ ":check.Tone=="warning"?"! ":"✕ ")+check.Text,muted:check.Tone=="ok");
 void Present(params UIElement[] parts){var stack=new StackPanel();foreach(var p in parts)stack.Children.Add(p);body.Content=stack;if(!IsVisible)Show();Activate();}

 /* ------------------------------------ ask ------------------------------------ */

 void Ask(string? text=null)
 {
  var problem=new TextBox{Text=text??"",AcceptsReturn=true,TextWrapping=TextWrapping.Wrap,MinHeight=72,MaxLength=1000,Margin=new Thickness(0,0,0,8)};
  var site=new ComboBox{ItemsSource=sites,SelectedIndex=sites.Count>0?0:-1,Margin=new Thickness(0,0,0,8),Visibility=sites.Count>1?Visibility.Visible:Visibility.Collapsed};
  Present(Line("What’s wrong?",strong:true),problem,site,
   Line("Obli checks your computer first and shows you its plan. Nothing happens until you press Fix it.",muted:true),
   Buttons(Secondary("Not now",Close),Primary("Check",()=>_=StartAsync(problem.Text,(site.SelectedItem as SupportSite)?.Id??0))));
  problem.Focus();
 }

 async Task StartAsync(string problem,int siteId)
 {
  if(problem.Trim().Length<10){Present(Line("Say what’s wrong in a sentence or two, for example “The printer won’t print”."),Buttons(Primary("OK",()=>Ask(problem))));return;}
  Present(Line("Checking your computer…",strong:true),Line("This takes a few seconds.",muted:true));
  if(api==null){
   var reach=await OfflineChecks.RunAsync(origin,CancellationToken.None);
   if(!OfflineChecks.Advise(reach).Online){Offline(reach);return;}
   Present(Line("Sign in to Obli first",strong:true),Line("Obli uses your support account to fix things, so IT can see what it did.",muted:true),
    Buttons(Secondary("Not now",Close),Primary("Sign in",()=>{SignInRequested?.Invoke();Close();})));
   return;
  }
  try{
   DiagnosticSnapshot? checks=null;
   try{checks=await service.ReadDiagnosticsAsync(origin,CancellationToken.None);}catch(Exception error) when(error is not OutOfMemoryException){}
   var screen=System.Windows.Forms.Screen.PrimaryScreen?.Bounds??System.Drawing.Rectangle.Empty;
   asked=problem.Trim();
   session=await api.StartFixAsync(Guid.NewGuid(),problem,siteId,checks,screen.Width,screen.Height,CancellationToken.None);
   if(session.State=="confirming"){
    // Prove which computer this is, as tickets do: the local Oblivion service confirms the challenge.
    if(!await service.ConfirmTicketComputerAsync(session.Challenge!,origin,CancellationToken.None))throw new ObliFixRejectedException("This computer couldn’t be confirmed. Ask IT for help instead.");
    for(int i=0;i<30&&session.State=="confirming";i++){await Task.Delay(2000);session=await api.FixAsync(session.Id,CancellationToken.None);}
   }
   _=OfflineChecks.ReportIfBackAsync(api,CancellationToken.None);
   Planned(session);
  }catch(Exception error) when(error is HttpRequestException or TaskCanceledException){
   var reach=await OfflineChecks.RunAsync(origin,CancellationToken.None);Offline(reach);
  }catch(Exception error) when(error is ObliFixRejectedException or ObliAccessException or ArgumentException or System.IO.InvalidDataException or System.IO.IOException){
   Present(Line(error.Message),Buttons(Primary("OK",Close)));
  }
 }

 /* ------------------------------- H, O and needs IT ------------------------------- */

 void Planned(FixSession s)
 {
  if(s.State=="refused"&&s.Refusal!=null){
   var offers=s.Refusal.Offers.Select(o=>o.Key=="ask_it"?Secondary(o.Label,()=>_=TicketAsync()):Primary(o.Label,()=>Ask(o.Label))).ToArray();
   Present(Line(s.Refusal.Text),Buttons(offers.Length>0?offers:new[]{Primary("OK",Close)}));
   return;
  }
  // H: the person's own words first, then what Obli found.
  var parts=new List<UIElement>{Line(asked.Length>0?asked:s.Title,muted:asked.Length>0)};
  parts.AddRange(s.Checks.Select(Check));
  if(s.State=="needs_it"){
   parts.Add(Line("This needs IT. I can raise a ticket with what I checked, so nobody asks the same questions again."));
   parts.Add(Buttons(Secondary("Not now",Close),Primary("Ask IT",()=>_=TicketAsync())));
   Present(parts.ToArray());return;
  }
  if(s.State!="planned"||s.Plan.Count==0){Present(Line("Obli couldn’t make a plan for this. Ask IT for help instead."),Buttons(Secondary("OK",Close),Primary("Ask IT",()=>_=TicketAsync())));return;}
  parts.Add(Line("Here’s what I’ll do:",strong:true));
  parts.AddRange(s.Plan.Select(p=>Line("• "+p.Text+(p.Kind=="fix"&&p.ItAllows?" · IT allows this fix":""))));
  string time=s.Minutes<=1?"About a minute.":$"About {s.Minutes} minutes.";
  parts.Add(Line(time+" "+(s.Note.Length>0?s.Note:"I keep pictures of your screen for 7 days so IT can check my work."),muted:true));
  parts.Add(Line("While I work, moving the mouse, pressing a key or Esc, or pressing Stop takes control back at once.",muted:true));
  parts.Add(Buttons(Secondary("Not now",Close),Primary("Fix it",()=>_=FixAsync())));
  Present(parts.ToArray());
 }

 /* ------------------------------------ I ------------------------------------ */

 async Task FixAsync()
 {
  if(api==null||session==null)return;
  try{session=await api.ApproveFixAsync(session.Id,Guid.NewGuid(),CancellationToken.None);}
  catch(Exception error) when(error is ObliFixRejectedException or HttpRequestException or TaskCanceledException or System.IO.IOException){Present(Line(error.Message),Buttons(Primary("OK",Close)));return;}
  using var guard=new FixGuard(Dispatcher);
  working=new CancellationTokenSource();
  runner=new FixRunner(api,desktopFor(bar));
  guard.TookOver+=reason=>StopWork(reason);
  Hide();
  bar.Show(session.Title,0,session.Plan.Count,"starting");
  FixOutcome outcome;
  try{
   guard.Start();
   outcome=await runner.RunAsync(session,this,guard,working.Token);
  }catch(InvalidOperationException error){
   // The guard couldn't start: Obli won't work without the person being able to take over.
   await api.StopFixAsync(session.Id,"closed",CancellationToken.None).ContinueWith(_=>{});
   outcome=new FixOutcome("stopped",new FixAction("stopped",Summary:error.Message),"closed");
  }finally{guard.Stop();bar.Hide();}
  Ended(outcome);
 }

 void StopWork(string reason)
 {
  if(working==null||working.IsCancellationRequested)return;
  if(runner!=null)runner.StopReason=reason;
  password?.TrySetResult(false);
  working.Cancel();
 }

 public void Working(int step,int of,string narration){lastStep=(step,of,narration);if(session!=null){Hide();bar.Show(session.Title,step,of,narration);}}
 public void FixRunning(string label){if(session!=null)bar.Show(session.Title,0,0,label.Length>0?label:"IT’s fix is running through the Oblivion service");}

 /* ------------------------------------ J ------------------------------------ */

 public Task<bool> PasswordAsync(string text,CancellationToken cancellation)
 {
  password=new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
  cancellation.Register(()=>password.TrySetResult(false));
  bar.Hide();
  Present(Line(text.Length>0?text:"This is asking for your password. Please type it yourself; I never type passwords.",strong:true),
   Line("I’ll carry on when it’s done."+(lastStep.Of>0?$" Paused at step {Math.Max(1,lastStep.Step)} of {lastStep.Of}"+(lastStep.Narration.Length>0?" · "+lastStep.Narration:""):""),muted:true),
   Buttons(Secondary("Stop",()=>password?.TrySetResult(false)),Primary("I’ve typed it",()=>password?.TrySetResult(true))));
  return password.Task;
 }

 /* ------------------------------------ M ------------------------------------ */

 public void WaitingForIt(string askedAt)
 {
  bar.Hide();
  string when=DateTimeOffset.TryParse(askedAt,CultureInfo.InvariantCulture,DateTimeStyles.AssumeUniversal,out var at)?" · asked "+at.ToLocalTime().ToString("h:mm tt",CultureInfo.GetCultureInfo("en-NZ")).ToLowerInvariant():"";
  Present(Line("This needs an administrator, and it isn’t on IT’s list. I’ve asked IT to approve it once.",strong:true),
   Line("You can keep working; I’ll tell you when they answer. Waiting for IT"+when,muted:true),
   Buttons(Secondary("Cancel the request",()=>_=CancelRequestAsync())));
 }

 async Task CancelRequestAsync()
 {
  if(api==null||session==null)return;
  try{await api.CancelFixRequestAsync(session.Id,CancellationToken.None);}catch(Exception error) when(error is not OutOfMemoryException){}
  StopWork("stop");
 }

 /* --------------------------------- K, L, stopped --------------------------------- */

 void Ended(FixOutcome outcome)
 {
  var final=outcome.Final;
  switch(outcome.Kind){
   case "done":{
    var parts=new List<UIElement>{Line(final?.Summary??(final?.Fixed==true?"Fixed.":"It’s not fixed yet."),strong:true)};
    parts.AddRange((final?.Done??Array.Empty<string>()).Select(s=>Line("• "+s)));
    if(final?.Pictures>0)parts.Add(Line($"{final.Pictures} screen {(final.Pictures==1?"picture":"pictures")}{(DayWords(final.DeletedOn) is {Length:>0} d?", deleted "+d:"")}.",muted:true));
    parts.Add(Buttons(Secondary("It’s still not working",()=>_=TicketAsync()),Primary("Thanks",Close)));
    Present(parts.ToArray());break;}
   case "handed_to_it":
    Present(Line(final?.Summary??"This needs IT.",strong:true),
     Line(final?.Ticket!=null?"I’ve raised ticket "+final.Ticket.Reference+" with what I checked and tried.":"I’ve passed it to IT with what I checked and tried.",muted:true),
     Buttons(Primary("OK",Close)));
    break;
   default:
    Present(Line("I stopped.",strong:true),Line(final?.Summary is {Length:>0} s?s:"Nothing more was done on your computer.",muted:true),
     Buttons(Secondary("Ask IT",()=>_=TicketAsync()),Primary("OK",Close)));
    break;
  }
 }

 /// <summary>"2026-11-05" → "Thu 5 Nov" (K); anything else is left out.</summary>
 internal static string DayWords(string? date)=>DateTime.TryParseExact(date,"yyyy-MM-dd",CultureInfo.InvariantCulture,DateTimeStyles.None,out var day)?day.ToString("ddd d MMM",CultureInfo.GetCultureInfo("en-NZ")):"";

 async Task TicketAsync()
 {
  if(api==null||session==null)return;
  try{
   var s=await api.FixTicketAsync(session.Id,CancellationToken.None);
   Ended(new FixOutcome("handed_to_it",new FixAction("handed_to_it",Summary:s.Outcome??"This needs IT.",Ticket:s.Ticket),null));
  }catch(Exception error) when(error is ObliFixRejectedException or HttpRequestException or TaskCanceledException or System.IO.IOException){Present(Line(error.Message),Buttons(Primary("OK",Close)));}
 }

 /* ------------------------------------ N ------------------------------------ */

 void Offline(OfflineResult result)
 {
  var advice=OfflineChecks.Advise(result);
  if(!advice.Online)OfflineChecks.Remember(result);
  Title=advice.Online?"Obli":"Obli · no internet";
  var parts=new List<UIElement>();
  parts.AddRange(advice.Lines.Select(Check));
  parts.Add(Line(advice.Advice,strong:!advice.Online));
  parts.Add(Buttons(Secondary("Close",Close),Primary("Check again",()=>_=CheckAgainAsync())));
  Present(parts.ToArray());
 }

 async Task CheckAgainAsync()
 {
  Present(Line("Checking…",strong:true));
  var result=await OfflineChecks.RunAsync(origin,CancellationToken.None);
  if(OfflineChecks.Advise(result).Online&&api!=null)await OfflineChecks.ReportIfBackAsync(api,CancellationToken.None);
  Offline(result);
 }
}
