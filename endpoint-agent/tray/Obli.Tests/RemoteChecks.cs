using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Oblivion.Obli;
static class RemoteChecks
{
 static void Check(bool value,string message){if(!value)throw new Exception(message);}
 static void Reject(Action action){try{action();}catch(Exception e)when(e is not OutOfMemoryException){return;}throw new Exception("Unsafe remote state accepted.");}
 public static int Run()
 {
  var now=DateTimeOffset.UtcNow;var origin=new Uri("https://care.example.test");
  string json=JsonSerializer.Serialize(new{protocol=1,server_origin=origin.GetLeftPart(UriPartial.Authority),status="requested",session_uuid="bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",consent_token=new string('a',64),technician="Alex Example",purpose="Check the printer connection",access_mode="control",expires_at=now.AddSeconds(100).ToString("yyyy-MM-ddTHH:mm:ssZ")});
  var requested=RemoteState.Parse(Encoding.UTF8.GetBytes(json),origin,now);
  Check(requested.Pending&&requested.Access=="control","Valid prompt failed.");
  foreach(string changed in new[]{json.Replace("requested","active"),json.Replace("care.example.test","elsewhere.example.test"),json.Replace("https://","http://"),json.Replace("control","shell"),json.Replace("\"protocol\":1","\"protocol\":1,\"protocol\":1"),json.Replace(new string('a',64),"bad"),json.Replace(now.AddSeconds(100).ToString("yyyy-MM-ddTHH:mm:ssZ"),now.AddSeconds(-1).ToString("yyyy-MM-ddTHH:mm:ssZ")),json.Replace("Check the printer connection","Check \\u202Ehidden")})
   Reject(()=>RemoteState.Parse(Encoding.UTF8.GetBytes(changed),origin,now));
  var window=new MainWindow();window.SetMotion(false);
  T Find<T>(string name)=>(T)window.FindName(name);
  window.ApplyRemoteState(requested);
  Check(Find<Border>("RemotePanel").Visibility==Visibility.Visible&&Find<Button>("AllowRemoteButton").IsEnabled&&Find<Button>("StopRemoteButton").Visibility==Visibility.Collapsed,"Prompt controls not correctly scoped.");
  window.Navigate("tickets");Check(Find<Border>("RemotePanel").Visibility==Visibility.Visible,"Consent notice lost on navigation.");
  var destination=Environment.GetEnvironmentVariable("OBLI_REMOTE_RENDER");
  if(!string.IsNullOrEmpty(destination)){
   var root=(Grid)window.Content;root.Background=(Brush)Application.Current.FindResource("BackgroundBrush");
   root.Measure(new Size(454,700));root.Arrange(new Rect(0,0,454,700));root.UpdateLayout();
   var image=new RenderTargetBitmap(454,700,96,96,PixelFormats.Pbgra32);image.Render(root);
   var encoder=new PngBitmapEncoder();encoder.Frames.Add(BitmapFrame.Create(image));
   using var file=System.IO.File.Create(System.IO.Path.GetFullPath(destination));encoder.Save(file);
  }
  var allowed=requested with{Status="allowed",Token=null,Expires=now.AddSeconds(15)};
  window.ApplyRemoteState(allowed);
  Check(Find<Button>("StopRemoteButton").Visibility==Visibility.Visible&&Find<Button>("AllowRemoteButton").Visibility==Visibility.Collapsed&&Find<TextBlock>("RemoteNotice").Text.Contains("No screen connection"),"Consent falsely claimed screen activation.");
  // Closing/minimising must suspend all lease-renewing polls even if Stop's
  // pipe acknowledgement is lost. Invoke with no UUID to avoid contacting SCM.
  window.ApplyRemoteState(allowed with {Id=null});
  typeof(MainWindow).GetMethod("CancelRemoteLocally",System.Reflection.BindingFlags.NonPublic|System.Reflection.BindingFlags.Instance)!.Invoke(window,null);
  Check(window.RemoteRenewalSuspended,"Closing consent kept background approval renewal enabled.");
  window.ApplyRemoteState(new(origin.GetLeftPart(UriPartial.Authority),"stopped",null,null,null,null,null,null));
  Check(Find<Border>("RemotePanel").Visibility==Visibility.Collapsed&&!Find<TextBlock>("RemotePerson").Text.Contains("Alex"),"Terminal prompt retained person.");
  window.AllowExit=true;window.Close();return 3;
 }
}
