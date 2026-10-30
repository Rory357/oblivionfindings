using System;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Interop;

namespace Oblivion.Obli;

public partial class MainWindow
{
 DesktopWorker? desktopWorker;
 string? locallyAllowedSession;
 internal bool DesktopPrivacyLocked {get;private set;}
 bool previousTopmost;
 internal void SetDesktopPrivacy(bool enabled)
 {
  if(DesktopPrivacyLocked==enabled)return;
  DesktopPrivacyLocked=enabled;
  if(enabled){ClearSoftware();previousTopmost=Topmost;Topmost=true;signInAttempt?.Cancel();Navigate("device");}
  else Topmost=previousTopmost;
  HelpNav.IsEnabled=TicketsNav.IsEnabled=!enabled;
  RenderStaff();
 }
 void StopDesktopLocally()
 {
  desktopWorker?.Stop();
  // Stop takes the operation lock: a capture/input operation has finished before
  // private content can become visible again.
  SetDesktopPrivacy(false);
 }
 void StartDesktopIfAllowed(RemoteState state)
 {
  if(state.Status!="allowed"||state.Id==null||state.Id!=locallyAllowedSession||desktopWorker!=null||!IsVisible||WindowState==WindowState.Minimized)return;
  SetDesktopPrivacy(true);
  try{
   var backend=new WindowsDesktop(new WindowInteropHelper(this).Handle);
   var worker=new DesktopWorker(backend,client);
   desktopWorker=worker;
   _=RunDesktopAsync(worker,state);
  }catch(Exception error)when(error is not OutOfMemoryException){CancelRemoteLocally();}
 }
 async Task RunDesktopAsync(DesktopWorker worker,RemoteState state)
 {
  try{
   await Task.Run(()=>worker.RunAsync(new Uri(state.Origin),state,()=>Dispatcher.BeginInvoke(()=>{
    if(desktopWorker==worker&&!worker.Stopped&&DesktopPrivacyLocked&&remoteState?.Id==state.Id)RemoteTitle.Text="Your primary screen is being shared";
   }),lifetime.Token));
  }catch(Exception error)when(error is not OutOfMemoryException){/* The visible notice below reports failure without sensitive exception text. */}
  finally{
   worker.Stop();
   if(desktopWorker==worker){
    desktopWorker=null;
    locallyAllowedSession=null;
    SetDesktopPrivacy(false);
    if(!lifetime.IsCancellationRequested&&remoteState?.Id==state.Id){
     CancelRemoteLocally();
     RemotePanel.Visibility=Visibility.Visible;
     RemoteTitle.Text="Remote connection ended";
     RemoteNotice.Text="Screen sharing has stopped. Ask your technician to send a new request if you still need help.";
    }
   }
   worker.Dispose();
  }
 }
}
