using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;
using System.Windows.Automation;
using Forms=System.Windows.Forms;

namespace Oblivion.Obli;

/// <summary>The screen and input Obli uses while fixing, behind one interface so tests can stand in.</summary>
public interface IFixDesktop
{
 FixPicture Capture();
 FixFocus Focus();
 /// <summary>Carries out one screen action that has already passed the safety checks.</summary>
 void Apply(FixAction action,FixPicture picture);
}

/// <summary>
/// S&D Phase I: Obli's eyes and hands, as the signed-in person (never elevated, never the secure
/// desktop). Pictures are of the primary screen, encoded like remote support's (≤ 1600×900, ≤ 512 KB),
/// with Obli's own windows blacked out except the Stop bar, which Windows leaves out of captures.
/// Input is ordinary SendInput, so it carries Windows' "injected" mark and the take-over guard can
/// tell it from the person's own mouse and keys.
/// </summary>
internal sealed class WindowsFixDesktop(Func<IntPtr> bar):IFixDesktop
{
 static Rectangle Screen=>Forms.Screen.PrimaryScreen?.Bounds??throw new InvalidOperationException("No screen.");

 public FixPicture Capture()
 {
  var bounds=Screen;
  if(bounds.Width<1||bounds.Height<1||bounds.Width>8192||bounds.Height>8192)throw new InvalidOperationException("The screen can’t be read.");
  using var bitmap=new Bitmap(bounds.Width,bounds.Height,PixelFormat.Format24bppRgb);
  using(var graphics=Graphics.FromImage(bitmap))graphics.CopyFromScreen(bounds.Location,Point.Empty,bounds.Size,CopyPixelOperation.SourceCopy);
  WindowsDesktop.Mask(bitmap,bounds,OwnWindows(true));
  var frame=WindowsDesktop.Encode(bitmap);
  return new FixPicture(frame.Jpeg,frame.Width,frame.Height,DateTimeOffset.UtcNow);
 }

 public FixFocus Focus()
 {
  IntPtr window=GetForegroundWindow();
  var title=new StringBuilder(256);
  _=GetWindowText(window,title,256);
  string process="";
  try{GetWindowThreadProcessId(window,out uint id);using var p=Process.GetProcessById((int)id);process=p.ProcessName;}catch(ArgumentException){}catch(InvalidOperationException){}
  bool password;
  try{
   // Fail safe: if Windows can't say what has focus, treat it as a password field (no typing).
   var focused=AutomationElement.FocusedElement;
   password=focused==null||focused.Current.IsPassword;
  }catch(Exception error) when(error is ElementNotAvailableException or InvalidOperationException or COMException){password=true;}
  return new FixFocus(password,title.ToString(),process);
 }

 public void Apply(FixAction action,FixPicture picture)
 {
  switch(action.Type){
   case "click": case "double_click": case "right_click": case "move": case "scroll":{
    var (x,y)=FixSafety.ToAbsolute(action.X,action.Y,picture.Width,picture.Height);
    var bounds=Screen;
    int px=bounds.Left+(int)((long)x*(bounds.Width-1)/65535),py=bounds.Top+(int)((long)y*(bounds.Height-1)/65535);
    if(OwnWindows(false).Any(r=>r.Contains(px,py)))throw new InvalidOperationException("That’s on Obli’s own window.");
    Mouse(x,y,0x8001,0);
    if(action.Type=="move")return;
    if(action.Type=="scroll"){Mouse(x,y,0x8800,unchecked((uint)((action.Direction=="down"?-120:120)*action.Amount)));return;}
    uint down=action.Type=="right_click"?0x8008u:0x8002u,up=action.Type=="right_click"?0x8010u:0x8004u;
    for(int i=0;i<(action.Type=="double_click"?2:1);i++){Mouse(x,y,down,0);Mouse(x,y,up,0);if(i==0&&action.Type=="double_click")Thread.Sleep(60);}
    return;}
   case "type":
    foreach(char c in action.Text??""){
     if(c=='\n'){Key(0x0D,true);Key(0x0D,false);}
     else if(c=='\t'){Key(0x09,true);Key(0x09,false);}
     else{Unicode(c,true);Unicode(c,false);}
    }
    return;
   case "key":{
    var codes=(action.Keys??Array.Empty<string>()).Select(k=>FixSafety.Keys[k]).ToList();
    foreach(ushort vk in codes)Key(vk,true);
    for(int i=codes.Count-1;i>=0;i--)Key(codes[i],false);
    return;}
   default:throw new InvalidOperationException("Not a screen action.");
  }
 }

 /// <summary>Obli's own visible windows; the Stop bar only when asked (Windows already leaves it out of pictures).</summary>
 List<Rectangle> OwnWindows(bool exceptBar)
 {
  var result=new List<Rectangle>();
  IntPtr skip=exceptBar?bar():IntPtr.Zero;
  EnumWindows((window,_)=>{
   GetWindowThreadProcessId(window,out uint process);
   if(process==Environment.ProcessId&&IsWindowVisible(window)&&window!=skip&&GetWindowRect(window,out var r))result.Add(Rectangle.FromLTRB(r.Left-8,r.Top-8,r.Right+8,r.Bottom+8));
   return true;
  },IntPtr.Zero);
  return result;
 }

 static void Mouse(int x,int y,uint flags,uint data)=>Send(new NativeInput{Type=0,Data=new InputData{Mouse=new MouseInput{X=x,Y=y,Data=data,Flags=flags}}});
 static void Key(ushort vk,bool down)=>Send(new NativeInput{Type=1,Data=new InputData{Keyboard=new KeyInput{VK=vk,Flags=(down?0u:2u)|(Extended(vk)?1u:0u)}}});
 static void Unicode(char c,bool down)=>Send(new NativeInput{Type=1,Data=new InputData{Keyboard=new KeyInput{Scan=c,Flags=4u|(down?0u:2u)}}});
 static bool Extended(ushort key)=>key is 0x21 or 0x22 or 0x23 or 0x24 or 0x25 or 0x26 or 0x27 or 0x28 or 0x2E;
 static void Send(NativeInput input){if(SendInput(1,new[]{input},Marshal.SizeOf<NativeInput>())!=1)throw new InvalidOperationException("Windows didn’t accept Obli’s input.");}

 delegate bool EnumWindowsProc(IntPtr window,IntPtr data);
 [StructLayout(LayoutKind.Sequential)]struct NativeInput{public uint Type;public InputData Data;}
 [StructLayout(LayoutKind.Explicit)]struct InputData{[FieldOffset(0)]public MouseInput Mouse;[FieldOffset(0)]public KeyInput Keyboard;}
 [StructLayout(LayoutKind.Sequential)]struct MouseInput{public int X,Y;public uint Data,Flags,Time;public UIntPtr Extra;}
 [StructLayout(LayoutKind.Sequential)]struct KeyInput{public ushort VK,Scan;public uint Flags,Time;public UIntPtr Extra;}
 [StructLayout(LayoutKind.Sequential)]struct NativeRect{public int Left,Top,Right,Bottom;}
 [DllImport("user32.dll",SetLastError=true)]static extern uint SendInput(uint count,NativeInput[] inputs,int size);
 [DllImport("user32.dll")]static extern IntPtr GetForegroundWindow();
 [DllImport("user32.dll",CharSet=CharSet.Unicode)]static extern int GetWindowText(IntPtr window,StringBuilder text,int max);
 [DllImport("user32.dll")]static extern uint GetWindowThreadProcessId(IntPtr window,out uint process);
 [DllImport("user32.dll")][return:MarshalAs(UnmanagedType.Bool)]static extern bool EnumWindows(EnumWindowsProc callback,IntPtr data);
 [DllImport("user32.dll")][return:MarshalAs(UnmanagedType.Bool)]static extern bool IsWindowVisible(IntPtr window);
 [DllImport("user32.dll")][return:MarshalAs(UnmanagedType.Bool)]static extern bool GetWindowRect(IntPtr window,out NativeRect rect);
}
