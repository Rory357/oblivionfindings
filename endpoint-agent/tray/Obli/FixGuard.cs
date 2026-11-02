using System;
using System.Runtime.InteropServices;
using System.Windows.Threading;

namespace Oblivion.Obli;

/// <summary>
/// S&D Phase I (windows.md I): the person can always take over. While Obli works, Windows' low-level
/// keyboard and mouse hooks watch for input that Obli didn't send. Obli's own input carries Windows'
/// "injected" mark; anything else (a key, a click, the wheel, or moving the mouse more than a few
/// pixels) stops Obli at once. Esc is reported as Esc. Nothing is swallowed: the person's input
/// still reaches their apps.
/// </summary>
public sealed class FixGuard:IDisposable
{
 const int KeyboardHook=13,MouseHook=14,MouseMove=0x0200,Jitter=6;
 readonly Dispatcher dispatcher;
 readonly HookProc keyboardProc,mouseProc;
 IntPtr keyboard,mouse;
 bool paused,fired;
 int? startX,startY;
 /// <summary>"esc" or "user_input", raised once, on the UI thread.</summary>
 public event Action<string>? TookOver;

 public FixGuard(Dispatcher dispatcher)
 {
  this.dispatcher=dispatcher;
  keyboardProc=OnKeyboard;mouseProc=OnMouse;
 }

 public void Start()
 {
  if(keyboard!=IntPtr.Zero)return;
  IntPtr module=GetModuleHandle(null);
  keyboard=SetWindowsHookEx(KeyboardHook,keyboardProc,module,0);
  mouse=SetWindowsHookEx(MouseHook,mouseProc,module,0);
  // Without both hooks Obli can't promise the person can take over, so it doesn't start.
  if(keyboard==IntPtr.Zero||mouse==IntPtr.Zero){Stop();throw new InvalidOperationException("Obli couldn’t watch for you taking over, so it won’t start.");}
  fired=false;paused=false;startX=startY=null;
 }

 /// <summary>While the person types a password or waits for IT, their input is expected.</summary>
 public void Pause()=>paused=true;
 public void Resume(){paused=false;startX=startY=null;}

 public void Stop()
 {
  if(keyboard!=IntPtr.Zero){UnhookWindowsHookEx(keyboard);keyboard=IntPtr.Zero;}
  if(mouse!=IntPtr.Zero){UnhookWindowsHookEx(mouse);mouse=IntPtr.Zero;}
 }

 public void Dispose()=>Stop();

 void Fire(string reason)
 {
  if(fired||paused)return;
  fired=true;
  dispatcher.BeginInvoke(new Action(()=>TookOver?.Invoke(reason)));
 }

 IntPtr OnKeyboard(int code,IntPtr message,IntPtr data)
 {
  if(code>=0){
   var info=Marshal.PtrToStructure<KeyboardInfo>(data);
   if((info.Flags&0x10)==0)Fire(info.Vk==0x1B?"esc":"user_input");
  }
  return CallNextHookEx(IntPtr.Zero,code,message,data);
 }

 IntPtr OnMouse(int code,IntPtr message,IntPtr data)
 {
  if(code>=0){
   var info=Marshal.PtrToStructure<MouseInfo>(data);
   if((info.Flags&0x01)==0){
    if((int)message==MouseMove){
     startX??=info.X;startY??=info.Y;
     if(Math.Abs(info.X-startX.Value)>Jitter||Math.Abs(info.Y-startY.Value)>Jitter)Fire("user_input");
    }else Fire("user_input");
   }
  }
  return CallNextHookEx(IntPtr.Zero,code,message,data);
 }

 delegate IntPtr HookProc(int code,IntPtr message,IntPtr data);
 [StructLayout(LayoutKind.Sequential)]struct KeyboardInfo{public uint Vk,Scan,Flags,Time;public UIntPtr Extra;}
 [StructLayout(LayoutKind.Sequential)]struct MouseInfo{public int X,Y;public uint Data,Flags,Time;public UIntPtr Extra;}
 [DllImport("user32.dll",SetLastError=true)]static extern IntPtr SetWindowsHookEx(int hook,HookProc callback,IntPtr module,uint thread);
 [DllImport("user32.dll")][return:MarshalAs(UnmanagedType.Bool)]static extern bool UnhookWindowsHookEx(IntPtr hook);
 [DllImport("user32.dll")]static extern IntPtr CallNextHookEx(IntPtr hook,int code,IntPtr message,IntPtr data);
 [DllImport("kernel32.dll",CharSet=CharSet.Unicode)]static extern IntPtr GetModuleHandle(string? name);
}
