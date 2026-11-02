using System;
using System.Linq;
using System.Runtime.InteropServices;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Interop;

namespace Oblivion.Obli;

// S&D Phase I: "Ask Obli" (windows.md H), from the tray, the Help page, or Ctrl+Alt+O anywhere.
public partial class MainWindow
{
 const int FixHotkeyId=0x0B11;
 FixWindow? fixWindow;
 HwndSource? hotkeySource;

 /// <summary>Ctrl+Alt+O opens Ask Obli. If another app already uses it, the tray menu still works.</summary>
 public void RegisterFixHotkey()
 {
  IntPtr handle=new WindowInteropHelper(this).EnsureHandle();
  hotkeySource=HwndSource.FromHwnd(handle);
  hotkeySource?.AddHook((IntPtr _,int message,IntPtr wParam,IntPtr _,ref bool handled)=>{
   if(message==0x0312&&wParam.ToInt32()==FixHotkeyId){handled=true;_=OpenFixAsync();}
   return IntPtr.Zero;
  });
  _=RegisterHotKey(handle,FixHotkeyId,0x0001|0x0002|0x4000,0x4F);
  Closed+=(_,_)=>UnregisterHotKey(handle,FixHotkeyId);
 }

 public async Task OpenFixAsync()
 {
  if(fixWindow!=null){fixWindow.Show();fixWindow.Activate();return;}
  Uri? origin=staffClient?.Origin;
  if(origin==null){
   try{var status=await client.ReadStatusAsync(lifetime.Token);if(status.ServerOrigin.Length>0)origin=new Uri(status.ServerOrigin);}catch(Exception error) when(error is not OutOfMemoryException){}
  }
  if(origin==null){MessageBox.Show(this,"Obli isn’t connected to Oblivion yet. Please contact your IT team.","Obli",MessageBoxButton.OK,MessageBoxImage.Information);return;}
  IObliFixApi? api=staffClient!=null&&staffSession!=null?new ObliFixApi(staffClient,staffSession):null;
  var sites=staffAccount?.Sites.ToList()??new System.Collections.Generic.List<SupportSite>();
  fixWindow=new FixWindow(api,client,origin,sites);
  fixWindow.SignInRequested+=()=>ShowFromTray("help");
  fixWindow.Closed+=(_,_)=>fixWindow=null;
  fixWindow.Show();fixWindow.Activate();
 }

 async void AskObliToFix(object sender,RoutedEventArgs e)=>await OpenFixAsync();

 [DllImport("user32.dll",SetLastError=true)][return:MarshalAs(UnmanagedType.Bool)]static extern bool RegisterHotKey(IntPtr window,int id,uint modifiers,uint key);
 [DllImport("user32.dll")][return:MarshalAs(UnmanagedType.Bool)]static extern bool UnregisterHotKey(IntPtr window,int id);
}
