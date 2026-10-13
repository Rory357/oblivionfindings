using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Oblivion.Obli;

static class DesktopChecks
{
 const string Session="bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",Desktop="cccccccc-cccc-4ccc-8ccc-cccccccccccc";
 static readonly Uri Origin=new("https://care.example.test");
 static readonly string Token=new('a',64);
 static void Check(bool value,string message){if(!value)throw new Exception(message);}
 static void Reject(Action action){try{action();}catch(Exception e)when(e is not OutOfMemoryException){return;}throw new Exception("Unsafe desktop operation accepted.");}
 static byte[] Grant(string state="active",string access="control",object[]? inputs=null)=>JsonSerializer.SerializeToUtf8Bytes(new{
  protocol=1,server_origin=Origin.GetLeftPart(UriPartial.Authority),status=state,session_uuid=Session,desktop_session=Desktop,access_mode=access,worker_token=state=="waiting"?"":Token,
  expires_at=DateTimeOffset.UtcNow.AddSeconds(state=="waiting"?14:2).ToString("yyyy-MM-ddTHH:mm:ssZ"),inputs=inputs??Array.Empty<object>()});
 sealed class Backend:IDesktopBackend
 {
  public bool Available=true;
  public int Frames,Releases;
  public readonly List<DesktopInput> Inputs=new();
  public bool CanUse()=>Available;
  public DesktopFrame Capture(){Frames++;return new(new byte[]{1,2,3},1,1);}
  public void Apply(DesktopInput input)=>Inputs.Add(input);
  public void Release()=>Releases++;
 }
 sealed class Channel:IDesktopChannel
 {
  public Func<object,CancellationToken,Task<byte[]>> Handler=(_,_)=>throw new Exception("Unexpected channel call");
  public Task<byte[]> ExchangeDesktopAsync(object request,CancellationToken cancellation)=>Handler(request,cancellation);
 }
 public static int Run()
 {
  var now=DateTimeOffset.UtcNow;
  var bytes=Grant(inputs:new object[]{new{version=1,sequence=1,type="button_down",x=0,y=0,button=0}});
  var grant=DesktopGrant.Parse(bytes,Origin,Session,"control",now);
  Check(grant.Inputs.Count==1&&grant.Inputs[0].X==0&&grant.Inputs[0].Button==0,"Zero coordinate input lost.");
  string json=Encoding.UTF8.GetString(bytes);
  foreach(string bad in new[]{
   json.Replace("care.example.test","elsewhere.example.test"),json.Replace("https://","http://"),
   json.Replace("\"protocol\":1","\"protocol\":1,\"protocol\":1"),json.Replace("\"protocol\":1","\"protocol\":1,\"secret\":\"x\""),
   json.Replace("\"sequence\":1","\"sequence\":0"),json.Replace("\"x\":0","\"x\":65536"),
   json.Replace(Token,"wrong"),json.Replace("\"inputs\":[","\"inputs\":[null,"),
   json.Replace("\"access_mode\":\"control\"","\"access_mode\":\"view\"")}){
   Reject(()=>DesktopGrant.Parse(Encoding.UTF8.GetBytes(bad),Origin,Session,"control",now));
  }
  var backend=new Backend();long ticks=1000;
  using(var worker=new DesktopWorker(backend,new Channel(),()=>ticks)){
   worker.AcceptLease(now.AddSeconds(2),now);
   Reject(()=>worker.Capture());Check(backend.Frames==0,"Waiting worker captured.");
   worker.AcceptLease(now.AddSeconds(2),now,true);
   worker.ApplyInputs(grant);Check(backend.Inputs.Count==1,"Approved input not applied.");
   Reject(()=>worker.ApplyInputs(grant));
   Reject(()=>worker.ApplyInputs(grant with{Access="view",Inputs=new[]{new DesktopInput(2,"move")}}));
   worker.Capture();Check(backend.Frames==1,"Active fake frame failed.");
   ticks+=3*Stopwatch.Frequency;
   Reject(()=>worker.Capture());Check(backend.Frames==1,"Expired worker captured.");
   worker.Stop();Check(backend.Releases==1,"Stop did not release input.");
   Reject(()=>worker.AcceptLease(now.AddSeconds(2),now,true));
  }
  using(var source=new Bitmap(320,180)){
   using(var graphics=Graphics.FromImage(source))graphics.Clear(System.Drawing.Color.Red);
   WindowsDesktop.Mask(source,new Rectangle(100,200,320,180),new[]{new Rectangle(130,230,60,60)});
   Check(source.GetPixel(60,60).ToArgb()==System.Drawing.Color.Black.ToArgb()&&source.GetPixel(5,5).R==255,"Private window masking coordinates failed.");
   var encoded=WindowsDesktop.Encode(source);
   using var buffer=new MemoryStream(encoded.Jpeg);using var decoded=new Bitmap(buffer);
   Check(encoded.Width==320&&encoded.Height==180&&decoded.GetPixel(60,60).R<5,"Mask did not survive JPEG encoding.");
  }
  // Real UI tree, fictional session data; no native capture backend is created.
  var window=new MainWindow();window.SetMotion(false);
  ((TextBlock)window.FindName("ComputerName")).Text="Reception PC";
  ((TextBlock)window.FindName("ConnectionText")).Text="Connected to Oblivion";
  ((TextBlock)window.FindName("AgentVersionText")).Text="Development preview";
  ((TextBlock)window.FindName("ServerText")).Text="care.example.test";
  ((TextBlock)window.FindName("LastContactText")).Text="Just now";
  typeof(MainWindow).GetField("configuredOrigin",System.Reflection.BindingFlags.NonPublic|System.Reflection.BindingFlags.Instance)!.SetValue(window,Origin.GetLeftPart(UriPartial.Authority));
  window.SetDesktopPrivacy(true);window.Navigate("tickets");
  typeof(MainWindow).GetMethod("RenderStaff",System.Reflection.BindingFlags.NonPublic|System.Reflection.BindingFlags.Instance)!.Invoke(window,null);
  Check(!((Button)window.FindName("SignInButton")).IsEnabled,"Staff refresh reopened sign-in during capture.");
  Check(((FrameworkElement)window.FindName("TicketsPane")).Visibility==Visibility.Collapsed&&((FrameworkElement)window.FindName("HelpPane")).Visibility==Visibility.Collapsed,"Private panes visible during support.");
  window.ApplyRemoteState(new(Origin.GetLeftPart(UriPartial.Authority),"active",Session,null,"Alex Example","Check the printer connection","control",DateTimeOffset.UtcNow.AddSeconds(15)));
  Check(((Button)window.FindName("StopRemoteButton")).Visibility==Visibility.Visible&&((TextBlock)window.FindName("RemoteTitle")).Text.Contains("primary"),"Active notice lacks scope/Stop.");
  string? destination=Environment.GetEnvironmentVariable("OBLI_DESKTOP_RENDER");
  if(!string.IsNullOrEmpty(destination)){
   var root=(Grid)window.Content;root.Background=(System.Windows.Media.Brush)Application.Current.FindResource("BackgroundBrush");
   root.Measure(new System.Windows.Size(454,700));root.Arrange(new Rect(0,0,454,700));root.UpdateLayout();
   var image=new RenderTargetBitmap(454,700,96,96,PixelFormats.Pbgra32);image.Render(root);
   var encoder=new PngBitmapEncoder();encoder.Frames.Add(BitmapFrame.Create(image));
   using var output=File.Create(Path.GetFullPath(destination));encoder.Save(output);
  }
  // Remove the fictional ID before closing so the test cannot call the service.
  window.ApplyRemoteState(new(Origin.GetLeftPart(UriPartial.Authority),"stopped",null,null,null,null,null,null));
  window.SetDesktopPrivacy(false);window.Navigate("tickets");
  Check(((FrameworkElement)window.FindName("TicketsPane")).Visibility==Visibility.Visible,"Private pages did not reopen after Stop.");
  window.AllowExit=true;window.Close();
  return 4;
 }
 public static async Task<int> RunAsync()
 {
  var backend=new Backend();var channel=new Channel();int calls=0,active=0;
  channel.Handler=async(request,cancellation)=>{
   using var body=JsonDocument.Parse(JsonSerializer.SerializeToUtf8Bytes(request));
   string op=body.RootElement.GetProperty("operation").GetString()!;
   int current=Interlocked.Increment(ref calls);
   if(current==1){Check(op=="desktop_open","Worker did not open bound desktop.");return Grant("starting");}
   if(current==2){Check(op=="desktop_poll","Worker did not await active lease.");return Grant(inputs:new object[]{new{version=1,sequence=1,type="move",x=0,y=0}});}
   if(current==3){Check(op=="desktop_frame","Worker did not send active frame.");return Grant();}
   await Task.Delay(Timeout.Infinite,cancellation);throw new Exception("Unreachable");
  };
  using var worker=new DesktopWorker(backend,channel);
  var state=new RemoteState(Origin.GetLeftPart(UriPartial.Authority),"allowed",Session,null,"Alex Example","Printer check","control",DateTimeOffset.UtcNow.AddSeconds(15));
  try{await worker.RunAsync(Origin,state,()=>Interlocked.Increment(ref active),CancellationToken.None).WaitAsync(TimeSpan.FromSeconds(4));}
  catch(OperationCanceledException){}
  Check(worker.Stopped&&backend.Frames==1&&backend.Inputs.Count==1&&backend.Releases==1&&active==1,"Blocked pipe kept input/capture alive past local expiry.");
  return 1;
 }
}
