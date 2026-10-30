using System;
using System.ComponentModel;
using System.Globalization;
using System.Runtime.InteropServices;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Threading;

namespace Oblivion.Obli;
public partial class MainWindow:Window
{
 readonly ServiceClient client=new();
 readonly CancellationTokenSource lifetime=new();
 readonly DispatcherTimer refresh=new(){Interval=TimeSpan.FromSeconds(15)};
 bool refreshing;
 public bool AllowExit{get;set;}
 public MainWindow()
 {
  InitializeComponent();InitialiseStaffSupport();InitialiseRemoteSupport();InitialiseServiceActions();InitialiseRestartSupport();InitialiseSoftwareSupport();ComputerName.Text=Environment.MachineName;Navigate("help");
  Closing+=(_,args)=>{if(!AllowExit){args.Cancel=true;CancelRemoteLocally();ClearServiceAction();ClearRestart();ClearSoftware();Hide();}};
  Closed+=(_,_)=>{refresh.Stop();lifetime.Cancel();lifetime.Dispose();};
  SourceInitialized+=(_,_)=>{int enabled=1;_ = DwmSetWindowAttribute(new WindowInteropHelper(this).Handle,20,ref enabled,sizeof(int));};
  refresh.Tick+=async(_,_)=>await RefreshAsync();
 }
 public void BeginStatusUpdates(){refresh.Start();remoteTimer.Start();serviceActionTimer.Start();restartTimer.Start();softwareTimer.Start();_ = RefreshAsync();}
 public void ShowFromTray(string? page=null)
 {
  if(page!=null)Navigate(page);
  var area=SystemParameters.WorkArea;
  Left=Math.Max(area.Left,area.Right-Width-16);Top=Math.Max(area.Top,area.Bottom-Height-16);
  if(!IsVisible)Show();WindowState=WindowState.Normal;Activate();_ = RefreshAsync();
 }
 public void Navigate(string page)
 {
  if(DesktopPrivacyLocked)page="device";
  if(page!="device"){ClearRestart();ClearSoftware();}
  KnowledgePane.Visibility=Visibility.Collapsed;
  HelpPane.Visibility=page=="help"?Visibility.Visible:Visibility.Collapsed;
  TicketsPane.Visibility=page=="tickets"?Visibility.Visible:Visibility.Collapsed;
  DevicePane.Visibility=page=="device"?Visibility.Visible:Visibility.Collapsed;
  HelpNav.Foreground=(Brush)FindResource(page=="help"?"AccentBrush":"MutedForegroundBrush");
  TicketsNav.Foreground=(Brush)FindResource(page=="tickets"?"AccentBrush":"MutedForegroundBrush");
  DeviceNav.Foreground=(Brush)FindResource(page=="device"?"AccentBrush":"MutedForegroundBrush");
 }
 public void SetMotion(bool value)=>HeaderOrb.SetMotion(value);
 public async Task RefreshAsync()
 {
  if(refreshing||lifetime.IsCancellationRequested)return;refreshing=true;
  try
  {
   var status=await client.ReadStatusAsync(lifetime.Token);
   ServiceChanged(status);
   ConnectionText.Text=status.ConnectionLabel(DateTimeOffset.UtcNow);
   AgentVersionText.Text=status.AgentVersion;
   ServerText.Text=status.ServerOrigin.Length>0?new Uri(status.ServerOrigin).Authority:"Waiting for service";
   LastContactText.Text=status.LastAcknowledgedAt?.ToLocalTime().ToString("d MMM, h:mm tt",CultureInfo.GetCultureInfo("en-NZ"))??"No confirmed check-in";
  }
  catch(Exception error) when(error is not OutOfMemoryException)
  {
   if(!lifetime.IsCancellationRequested)
   {
    ServiceChanged(null);
    ConnectionText.Text="Computer support service unavailable";
    AgentVersionText.Text="Unavailable";ServerText.Text="Unavailable";LastContactText.Text="No current confirmation";
   }
  }
  finally{refreshing=false;}
 }
 void HelpClick(object sender,RoutedEventArgs e)=>Navigate("help");
 void TicketsClick(object sender,RoutedEventArgs e)=>Navigate("tickets");
 void DeviceClick(object sender,RoutedEventArgs e)=>Navigate("device");
 async void RefreshClick(object sender,RoutedEventArgs e)=>await RefreshAsync();
 void Guide(string title,string body){GuideTitle.Text=title;GuideText.Text=body;GuidePanel.Visibility=Visibility.Visible;}
 void PrinterHelp(object sender,RoutedEventArgs e)=>Guide("Check your printer","1. Check the printer is on and has paper.\n2. Check that the correct printer is selected in the print window.\n3. Look for a message on the printer's screen.\n4. If work is still waiting, tell IT the printer name and the message you can see.");
 void InternetHelp(object sender,RoutedEventArgs e)=>Guide("Check your connection","1. Check the network icon beside the Windows clock.\n2. If you use a cable, check it is connected.\n3. Ask a colleague whether they have the same problem.\n4. Tell IT which app or website is affected and when it started.");
 void SlowHelp(object sender,RoutedEventArgs e)=>Guide("When your computer feels slow","1. Save your current work.\n2. Close apps and browser tabs you no longer need.\n3. Note whether one app is slow or the whole computer.\n4. If it continues, tell IT what you were doing and when it started.");
 void ShowPrivacy(object sender,RoutedEventArgs e)=>MessageBox.Show(this,"Obli reads the support service’s connection status, version and last accepted check-in. When you sign in, it also shows your account, approved Sites and your own IT requests. Only the ticket text you review is sent to IT. Your password stays in your browser.\n\nWhen you enable private chat, only the conversation shown in this window is sent through Oblivion to the named AI provider. No files or screenshots are added automatically. Approved computer checks are read from the support service only when you request a preview, and shared only after you select them and send a message. Checks exclude names, paths, addresses, publishers and raw logs. Replies are stored encrypted for your organisation’s configured recovery period; provider retention is separate. Only a message you explicitly select is added to a ticket draft. Organisation guides come from approved IT publications you can access. A guide is sent to the provider only when you attach its displayed excerpt and send a message. Quick guides stay on this computer. Sign-out or locking Windows clears the helper’s account, chat and draft from memory.","What Obli can access",MessageBoxButton.OK,MessageBoxImage.Information);
 [DllImport("dwmapi.dll")]static extern int DwmSetWindowAttribute(IntPtr window,int attribute,ref int value,int size);
}
