using System;
using System.Drawing;
using System.IO;
using System.Runtime.InteropServices;
using System.Security.Principal;
using System.Threading;
using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Forms=System.Windows.Forms;

namespace Oblivion.Obli;

public partial class App:System.Windows.Application
{
 Mutex? instance;
 EventWaitHandle? reopen;
 RegisteredWaitHandle? reopenWait;
 Forms.NotifyIcon? tray;
 Icon? trayIcon;
 MainWindow? window;
 protected override void OnStartup(StartupEventArgs args)
 {
  base.OnStartup(args);
  if(args.Args.Length==2 && args.Args[0]=="--render-preview")
  {
   try{RenderPreview(args.Args[1]);Shutdown(0);}
   catch(Exception error){File.WriteAllText(args.Args[1]+".error.txt",error.ToString());Shutdown(1);}
   return;
  }
  if(args.Args.Length>1 || (args.Args.Length==1&&args.Args[0]!="--tray")){Shutdown(2);return;}
  try
  {
   string sid=WindowsIdentity.GetCurrent().User?.Value??throw new InvalidOperationException();
   string prefix=@"Local\Oblivion.Obli."+sid+"."+System.Diagnostics.Process.GetCurrentProcess().SessionId;
   instance=new Mutex(true,prefix+".instance",out bool created);
   if(!created)
   {
    if(EventWaitHandle.TryOpenExisting(prefix+".open",out var signal)){using(signal){signal.Set();}}
    Shutdown();return;
   }
   reopen=new EventWaitHandle(false,EventResetMode.AutoReset,prefix+".open");
   window=new MainWindow();
   reopenWait=ThreadPool.RegisterWaitForSingleObject(reopen,(_,_)=>Dispatcher.BeginInvoke(new Action(()=>window.ShowFromTray())),null,Timeout.Infinite,false);
   trayIcon=CreateTrayIcon();
   var menu=new Forms.ContextMenuStrip();
   menu.Items.Add("Open Obli",null,(_,_)=>window.ShowFromTray("help"));
   menu.Items.Add("My tickets",null,(_,_)=>window.ShowFromTray("tickets"));
   menu.Items.Add("This computer",null,(_,_)=>window.ShowFromTray("device"));
   menu.Items.Add(new Forms.ToolStripSeparator());
   var motion=new Forms.ToolStripMenuItem("Animate Obli"){Checked=SystemParameters.ClientAreaAnimation,CheckOnClick=true};
   motion.CheckedChanged+=(_,_)=>window.SetMotion(motion.Checked);
   menu.Items.Add(motion);
   menu.Items.Add("Exit Obli",null,(_,_)=>{window.AllowExit=true;Shutdown();});
   tray=new Forms.NotifyIcon{Icon=trayIcon,Text="Obli — computer support",ContextMenuStrip=menu,Visible=true};
   tray.DoubleClick+=(_,_)=>window.ShowFromTray();
   window.BeginStatusUpdates();
   if(args.Args.Length==0)window.ShowFromTray();
  }
  catch(Exception)
  {
   MessageBox.Show("Obli could not start. Please contact your IT team.","Obli",MessageBoxButton.OK,MessageBoxImage.Warning);
   Shutdown(1);
  }
 }
 protected override void OnExit(ExitEventArgs args)
 {
  if(window!=null){window.AllowExit=true;window.Close();}
  reopenWait?.Unregister(null);reopen?.Dispose();
  if(tray!=null){tray.Visible=false;tray.ContextMenuStrip?.Dispose();tray.Dispose();}
  trayIcon?.Dispose();instance?.Dispose();base.OnExit(args);
 }
 static Icon CreateTrayIcon()
 {
  var orb=new ObliOrb{Width=32,Height=32};orb.SetMotion(false);
  orb.Measure(new System.Windows.Size(32,32));orb.Arrange(new Rect(0,0,32,32));orb.UpdateLayout();
  var image=new RenderTargetBitmap(32,32,96,96,PixelFormats.Pbgra32);image.Render(orb);
  var encoder=new PngBitmapEncoder();encoder.Frames.Add(BitmapFrame.Create(image));
  using var memory=new MemoryStream();encoder.Save(memory);memory.Position=0;
  using var bitmap=new Bitmap(memory);
  IntPtr handle=bitmap.GetHicon();
  try{using var original=Icon.FromHandle(handle);return (Icon)original.Clone();}
  finally{DestroyIcon(handle);}
 }
 static void RenderPreview(string destination)
 {
  // Only render this app's visual tree. No tray, service connection or host
  // installation is created by this developer rendering entry point.
  var sample=new MainWindow();sample.Navigate("device");
  sample.ComputerName.Text="Reception PC · preview";
  sample.ConnectionText.Text="Service connection not checked in preview";
  sample.SetMotion(false);
  var root=(FrameworkElement)sample.Content;
  ((System.Windows.Controls.Grid)root).Background=(System.Windows.Media.Brush)Current.FindResource("BackgroundBrush");
  root.Measure(new System.Windows.Size(454,620));root.Arrange(new Rect(0,0,454,620));root.UpdateLayout();
  var image=new RenderTargetBitmap(454,620,96,96,PixelFormats.Pbgra32);image.Render(root);
  var encoder=new PngBitmapEncoder();encoder.Frames.Add(BitmapFrame.Create(image));
  using var output=File.Create(Path.GetFullPath(destination));encoder.Save(output);
  sample.AllowExit=true;sample.Close();
 }
 [DllImport("user32.dll")][return:MarshalAs(UnmanagedType.Bool)]static extern bool DestroyIcon(IntPtr icon);
}
