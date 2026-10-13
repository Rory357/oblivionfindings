using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Security.Principal;
using System.Text;
using Forms=System.Windows.Forms;

namespace Oblivion.Obli;

// Runs only in the ordinary-user helper. No service-session capture, elevation,
// secure-desktop switching, clipboard access or privileged input is attempted.
internal sealed class WindowsDesktop : IDesktopBackend
{
 readonly Rectangle bounds;
 readonly IntPtr owner;
 readonly HashSet<int> keys=new(),buttons=new();
 internal WindowsDesktop(IntPtr owner){this.owner=owner;bounds=Forms.Screen.PrimaryScreen?.Bounds??throw new InvalidOperationException();}
 public bool CanUse()
 {
  if(owner==IntPtr.Zero||!IsWindowVisible(owner)||IsIconic(owner)||DwmGetWindowAttribute(owner,14,out uint cloaked,4)!=0||cloaked!=0)return false;
  if(!GetWindowRect(owner,out var ownerRect)||!Forms.Screen.AllScreens.Any(screen=>screen.Bounds.IntersectsWith(Rectangle.FromLTRB(ownerRect.Left,ownerRect.Top,ownerRect.Right,ownerRect.Bottom))))return false;
  if(Process.GetCurrentProcess().SessionId==0||Forms.Screen.PrimaryScreen?.Bounds!=bounds||bounds.Width<1||bounds.Height<1||
     bounds.Width>8192||bounds.Height>8192||(long)bounds.Width*bounds.Height>33554432)return false;
  using var user=WindowsIdentity.GetCurrent();
  if(!GetTokenInformation(user.Token,20,out uint elevated,4,out _)||elevated!=0)return false;
  if(!WTSQuerySessionInformation(IntPtr.Zero,Process.GetCurrentProcess().SessionId,8,out var state,out int bytes))return false;
  try{if(bytes!=4||Marshal.ReadInt32(state)!=0)return false;}finally{WTSFreeMemory(state);}
  IntPtr desktop=OpenInputDesktop(0,false,1);
  if(desktop==IntPtr.Zero)return false;
  try{
   var name=new StringBuilder(256);
   return GetUserObjectInformation(desktop,2,name,512,out _)&&name.ToString()=="Default";
  }finally{CloseDesktop(desktop);}
 }
 internal static void Mask(Bitmap bitmap,Rectangle screen,IEnumerable<Rectangle> regions)
 {
  using var graphics=Graphics.FromImage(bitmap);
  foreach(var region in regions){var area=Rectangle.Intersect(screen,region);if(area.Width>0&&area.Height>0){area.Offset(-screen.X,-screen.Y);graphics.FillRectangle(Brushes.Black,area);}}
 }
 internal static DesktopFrame Encode(Bitmap source)
 {
  double scale=Math.Min(1,Math.Min(1600.0/source.Width,900.0/source.Height));
  int width=Math.Max(1,(int)(source.Width*scale)),height=Math.Max(1,(int)(source.Height*scale));
  using var output=new Bitmap(width,height,PixelFormat.Format24bppRgb);
  using(var graphics=Graphics.FromImage(output))graphics.DrawImage(source,new Rectangle(0,0,width,height));
  var encoder=ImageCodecInfo.GetImageEncoders().Single(e=>e.FormatID==ImageFormat.Jpeg.Guid);
  foreach(long quality in new long[]{70,50,30,15}){
   using var buffer=new MemoryStream();
   using var parameters=new EncoderParameters(1);parameters.Param[0]=new EncoderParameter(System.Drawing.Imaging.Encoder.Quality,quality);
   output.Save(buffer,encoder,parameters);
   if(buffer.Length<=524288)return new(buffer.ToArray(),width,height);
  }
  throw new InvalidDataException("The screen frame exceeds the permitted size.");
 }
 public DesktopFrame Capture()
 {
  if(!CanUse())throw new InvalidOperationException();
  var masks=OwnWindows();
  using var bitmap=new Bitmap(bounds.Width,bounds.Height,PixelFormat.Format24bppRgb);
  using(var graphics=Graphics.FromImage(bitmap))graphics.CopyFromScreen(bounds.Location,Point.Empty,bounds.Size,CopyPixelOperation.SourceCopy);
  if(!CanUse())throw new InvalidOperationException();
  masks.AddRange(OwnWindows());Mask(bitmap,bounds,masks);
  return Encode(bitmap);
 }
 List<Rectangle> OwnWindows()
 {
  var result=new List<Rectangle>();
  bool failed=false;
  bool enumerated=EnumWindows((window,_)=>{
   GetWindowThreadProcessId(window,out uint process);
   if(process==Environment.ProcessId&&IsWindowVisible(window)){
    if(!GetWindowRect(window,out var rect)){failed=true;return true;}
    // Also mask shadows/borders; private chat/ticket panes are hidden in the UI
    // for the entire screen-worker lifetime, including window movement.
    result.Add(Rectangle.FromLTRB(rect.Left-16,rect.Top-16,rect.Right+16,rect.Bottom+16));
   }
   return true;
  },IntPtr.Zero);
  if(!enumerated||failed)throw new InvalidOperationException("Private window bounds could not be verified.");
  return result;
 }
 public void Apply(DesktopInput input)
 {
  if(!CanUse())throw new InvalidOperationException();
  if(input.Type is "key_down" or "key_up"){
   GetWindowThreadProcessId(GetForegroundWindow(),out uint focused);
   if(focused==Environment.ProcessId){Release();return;}
   bool down=input.Type=="key_down";
   if(down){if(!keys.Contains(input.Key)&&((GetAsyncKeyState(input.Key)&0x8000)!=0))return;keys.Add(input.Key);}
   else if(!keys.Contains(input.Key))return;
   Send(new NativeInput{Type=1,Data=new InputData{Keyboard=new KeyInput{VK=(ushort)input.Key,Flags=(down?0u:2u)|(Extended(input.Key)?1u:0u)}}});
   if(!down)keys.Remove(input.Key);
   return;
  }
  int px=bounds.Left+(int)((long)input.X*(bounds.Width-1)/65535),py=bounds.Top+(int)((long)input.Y*(bounds.Height-1)/65535);
  if(OwnWindows().Any(r=>r.Contains(px,py))){Release();return;}
  uint flags=0x8001,data=0;
  if(input.Type is "button_down" or "button_up"){
   bool down=input.Type=="button_down";
   int vk=input.Button==0?1:input.Button==1?4:2;
   if(down){if(buttons.Contains(input.Button)||((GetAsyncKeyState(vk)&0x8000)!=0))return;buttons.Add(input.Button);}
   else if(!buttons.Contains(input.Button))return;
   flags|=input.Button switch{0=>down?2u:4u,1=>down?32u:64u,2=>down?8u:16u,_=>throw new InvalidDataException()};
  }else if(input.Type=="wheel"){flags|=0x800;data=unchecked((uint)input.Delta);}
  else if(input.Type!="move")throw new InvalidDataException();
  Send(new NativeInput{Data=new InputData{Mouse=new MouseInput{X=input.X,Y=input.Y,Data=data,Flags=flags}}});
  if(input.Type=="button_up")buttons.Remove(input.Button);
 }
 static bool Extended(int key)=>key is 3 or 33 or 34 or 35 or 36 or 37 or 38 or 39 or 40 or 44 or 45 or 46 or 91 or 92 or 111 or 144 or 163 or 165;
 static void Send(NativeInput input){if(SendInput(1,new[]{input},Marshal.SizeOf<NativeInput>())!=1)throw new InvalidOperationException("Windows did not accept remote input.");}
 public void Release()
 {
  // Only keys/buttons this worker pressed are released. Windows UIPI remains in
  // force; release is best effort during secure-desktop/logoff transitions.
  foreach(int key in keys.ToArray()){
   try{Send(new NativeInput{Type=1,Data=new InputData{Keyboard=new KeyInput{VK=(ushort)key,Flags=2u|(Extended(key)?1u:0u)}}});}catch(InvalidOperationException){}
  }
  foreach(int button in buttons.ToArray()){
   try{Send(new NativeInput{Data=new InputData{Mouse=new MouseInput{Flags=button==0?4u:button==1?64u:16u}}});}catch(InvalidOperationException){}
  }
  keys.Clear();buttons.Clear();
 }
 [StructLayout(LayoutKind.Sequential)]struct NativeInput{public uint Type;public InputData Data;}
 [StructLayout(LayoutKind.Explicit)]struct InputData{[FieldOffset(0)]public MouseInput Mouse;[FieldOffset(0)]public KeyInput Keyboard;}
 [StructLayout(LayoutKind.Sequential)]struct MouseInput{public int X,Y;public uint Data,Flags,Time;public UIntPtr Extra;}
 [StructLayout(LayoutKind.Sequential)]struct KeyInput{public ushort VK,Scan;public uint Flags,Time;public UIntPtr Extra;}
 [StructLayout(LayoutKind.Sequential)]struct NativeRect{public int Left,Top,Right,Bottom;}
 delegate bool EnumWindow(IntPtr window,IntPtr parameter);
 [DllImport("user32.dll")]static extern bool EnumWindows(EnumWindow callback,IntPtr parameter);
 [DllImport("dwmapi.dll")]static extern int DwmGetWindowAttribute(IntPtr window,int attribute,out uint value,int size);
 [DllImport("user32.dll")]static extern bool IsIconic(IntPtr window);
 [DllImport("user32.dll")]static extern bool IsWindowVisible(IntPtr window);
 [DllImport("user32.dll")]static extern bool GetWindowRect(IntPtr window,out NativeRect rect);
 [DllImport("user32.dll")]static extern uint GetWindowThreadProcessId(IntPtr window,out uint process);
 [DllImport("user32.dll")]static extern IntPtr GetForegroundWindow();
 [DllImport("user32.dll")]static extern short GetAsyncKeyState(int key);
 [DllImport("user32.dll",SetLastError=true)]static extern uint SendInput(uint count,NativeInput[] inputs,int size);
 [DllImport("user32.dll",SetLastError=true)]static extern IntPtr OpenInputDesktop(uint flags,bool inherit,uint access);
 [DllImport("user32.dll")]static extern bool CloseDesktop(IntPtr desktop);
 [DllImport("user32.dll",CharSet=CharSet.Unicode,EntryPoint="GetUserObjectInformationW")]static extern bool GetUserObjectInformation(IntPtr handle,int index,StringBuilder value,int size,out uint needed);
 [DllImport("advapi32.dll",SetLastError=true)]static extern bool GetTokenInformation(IntPtr token,int kind,out uint data,uint size,out uint needed);
 [DllImport("wtsapi32.dll",CharSet=CharSet.Unicode,EntryPoint="WTSQuerySessionInformationW")]static extern bool WTSQuerySessionInformation(IntPtr server,int session,int kind,out IntPtr value,out int bytes);
 [DllImport("wtsapi32.dll")]static extern void WTSFreeMemory(IntPtr value);
}
