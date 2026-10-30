using System.IO;
using System.Windows.Controls;
using System.Buffers.Binary;
using System.Text;
using System.Windows;
using System.Windows.Media;
using Oblivion.Obli;

static class Program
{
 static int passed;
 static void Check(bool ok,string message){if(!ok)throw new Exception(message);}
 static void Reject(Action action,string message){try{action();}catch(Exception error) when(error is not OutOfMemoryException){return;}throw new Exception(message);}
 static string Valid => "{\"protocol\":1,\"agent_version\":\"0.1.0-dev\",\"server_origin\":\"https://care.example.test\",\"connection\":\"connecting\",\"ai_available\":false,\"tickets_available\":false,\"remote_available\":false}";
 [STAThread]static int Main()
 {
  try
  {
   var parsed=StatusProtocol.Parse(Encoding.UTF8.GetBytes(Valid));
   Check(parsed.Connection=="connecting"&&!parsed.AIAvailable,"Valid status did not round-trip.");passed++;
   foreach(string malformed in new[]{
    Valid.Replace("\"protocol\":1","\"protocol\":1,\"protocol\":1"),
    Valid.Replace("\"protocol\":1","\"protocol\":2"),
    Valid.Replace("\"protocol\":1","\"credential\":\"secret\",\"protocol\":1"),
    Valid.Replace("https://care.example.test","http://care.example.test"),
    Valid.Replace("https://care.example.test","https://staff:secret@care.example.test"),
    Valid.Replace("https://care.example.test","https://care.example.test/?token=secret"),
    Valid.Replace("\"connecting\"","\"connected\""),
    Valid.Replace("\"connecting\"","\"fake\""),
    Valid+"{}", new string(' ',4097), "[]"
   })Reject(()=>StatusProtocol.Parse(Encoding.UTF8.GetBytes(malformed)),"Malformed status accepted.");
   passed++;
   foreach(uint length in new uint[]{0,4097,uint.MaxValue}){
    var header=new byte[4];BinaryPrimitives.WriteUInt32LittleEndian(header,length);
    Reject(()=>StatusProtocol.ReadAsync(new MemoryStream(header),CancellationToken.None).GetAwaiter().GetResult(),"Oversized frame accepted.");
   }
   var incomplete=new byte[5];BinaryPrimitives.WriteUInt32LittleEndian(incomplete,4);
   Reject(()=>StatusProtocol.ReadAsync(new MemoryStream(incomplete),CancellationToken.None).GetAwaiter().GetResult(),"Truncated frame accepted.");passed++;
   var stale=new AgentStatus(1,"test","https://care.example.test","connected",DateTimeOffset.UtcNow.AddMinutes(-8),false,false,false);
   Check(stale.ConnectionLabel(DateTimeOffset.UtcNow)!="Connected to Oblivion","Stale contact claimed live connection.");passed++;
   var app=new App();app.InitializeComponent();
   var window=new MainWindow();
   window.Navigate("device");
   Check(((FrameworkElement)window.FindName("DevicePane")).Visibility==Visibility.Visible&&((FrameworkElement)window.FindName("HelpPane")).Visibility==Visibility.Collapsed,"Device navigation failed.");
   Check(((Button)window.FindName("DeviceNav")).Foreground.Equals(app.FindResource("AccentBrush")),"Selected tab did not use the accessible accent.");
   window.Navigate("tickets");Check(((FrameworkElement)window.FindName("TicketsPane")).Visibility==Visibility.Visible,"Ticket navigation failed.");
   window.SetMotion(false);window.AllowExit=true;window.Close();passed++;
   Color ColorOf(string name)=>((SolidColorBrush)app.FindResource(name)).Color;
   static double L(Color c){double C(byte v){double n=v/255.0;return n<=.04045?n/12.92:Math.Pow((n+.055)/1.055,2.4);}return .2126*C(c.R)+.7152*C(c.G)+.0722*C(c.B);}
   foreach(var pair in new[]{("ForegroundBrush","BackgroundBrush"),("MutedForegroundBrush","BackgroundBrush"),("AccentBrush","BackgroundBrush"),("OnPrimaryBrush","PrimaryBrush")}){
    double a=L(ColorOf(pair.Item1)),b=L(ColorOf(pair.Item2));Check((Math.Max(a,b)+.05)/(Math.Min(a,b)+.05)>=4.5,"Text contrast below AA: "+pair.Item1);
   }
   passed++;
   passed += AuthChecks.RunAsync().GetAwaiter().GetResult();
   passed += StaffUiChecks.Run();
   passed += TicketComputerUiChecks.Run();
   passed += TicketConversationChecks.Run();
   passed += TicketClientChecks.RunAsync().GetAwaiter().GetResult();
   passed += KnowledgeClientChecks.RunAsync().GetAwaiter().GetResult();
   passed += ComputerContextChecks.RunAsync().GetAwaiter().GetResult();
   passed += ChatChecks.RunAsync().GetAwaiter().GetResult();
   passed += ChatChecks.RunUi();
   passed += KnowledgeUiChecks.Run();
   passed += DiagnosticChecks.Run();
   passed += RemoteChecks.Run();
   passed += ServiceActionUiChecks.Run();
   passed += ServiceActionProtocolChecks.Run();
   passed += RestartChecks.Run();
   passed += SoftwareChecks.Run();
   passed += DesktopChecks.Run();
   passed += DesktopChecks.RunAsync().GetAwaiter().GetResult();
   Console.WriteLine($"{passed} native helper check groups passed.");app.Shutdown();return 0;
  }
  catch(Exception error){Console.Error.WriteLine(error);return 1;}
 }
}

