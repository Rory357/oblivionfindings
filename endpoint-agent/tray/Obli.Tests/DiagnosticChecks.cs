using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Oblivion.Obli;

static class DiagnosticChecks
{
    static void Check(bool condition,string message){if(!condition)throw new Exception(message);}
    static void Reject(Action action){
        bool denied=false;
        try{action();}catch(Exception error)when(error is InvalidDataException or JsonException or InvalidOperationException or KeyNotFoundException){denied=true;}
        Check(denied,"Invalid diagnostic snapshot accepted.");
    }
    public static int Run()
    {
        var now=DateTimeOffset.UtcNow;
        var origin=new Uri("https://care.example.test");
        var snapshot=new {
            schema=1,observed_at=now.ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),
            system=new {windows_version="10.0.26100",architecture="amd64",memory_total_bytes=17179869184L,
                memory_available_bytes=8589934592L,uptime_seconds=3600,cpu_percent=12.5,
                disks=new[]{new {name="C:",total_bytes=512000000000L,free_bytes=128000000000L}}},
            software=new[]{new {name="microsoft_edge",version="140.0.1000.1"}},
            services=new[]{new {name="print_spooler",state="running"}}
        };
        string envelope=JsonSerializer.Serialize(new{protocol=1,available=true,server_origin=origin.GetLeftPart(UriPartial.Authority),snapshot});
        DiagnosticSnapshot? Read(string value,Uri? expected=null)=>DiagnosticSnapshot.ParseEnvelope(Encoding.UTF8.GetBytes(value),now,expected??origin);
        var parsed=Read(envelope)!;
        Check(parsed.Groups.Count==3&&parsed.IsFresh(now),"Valid snapshot not available.");
        var selected=parsed.Select(new[]{"system"});
        var wire=JsonSerializer.Serialize(new ChatMessage("user","My computer is slow",selected).ToWire());
        Check(!wire.Contains("software")&&!wire.Contains("services")&&wire.Contains("diagnostics")&&wire.Contains("10.0.26100"),"Unselected groups shared.");
        Check(!JsonSerializer.Serialize(new ChatMessage("user","No checks").ToWire()).Contains("diagnostics"),"Plain message acquired checks.");
        Check(parsed.Preview().Contains("Microsoft Edge")&&parsed.Preview().Contains("Print spooler")&&!parsed.ToString().Contains("10.0.26100"),"Preview or private debug display failed.");
        Check(Read("{\"protocol\":1,\"available\":false}")==null,"Unavailable checks accepted.");
        Reject(()=>Read(envelope,new Uri("https://other.example.test")));
        foreach(var invalid in new[]{
            envelope.Replace("\"schema\":1","\"schema\":1,\"hostname\":\"private-computer\""),
            envelope.Replace("\"schema\":1","\"schema\":1,\"schema\":1"),
            envelope.Replace("\"windows_version\":\"10.0.26100\"","\"windows_version\":\"10.0.26100\",\"user\":\"private\""),
            envelope.Replace("\"name\":\"C:\"","\"name\":\"C:/Users/Private\""),
            envelope.Replace("\"microsoft_edge\"","\"private_app\""),
            envelope.Replace("\"140.0.1000.1\"","\"private data\""),
            envelope.Replace("\"cpu_percent\":12.5","\"cpu_percent\":101"),
            envelope.Replace("\"memory_available_bytes\":8589934592","\"memory_available_bytes\":17179869185"),
            envelope.Replace("\"free_bytes\":128000000000","\"free_bytes\":512000000001"),
            envelope.Replace("\"print_spooler\"","\"arbitrary_service\""),
            envelope.Replace("\"running\"","\"command:run\""),
            envelope.Replace(snapshot.observed_at,now.AddMinutes(-7).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'")),
            envelope.Replace(snapshot.observed_at,now.AddMinutes(1).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'")),
            envelope.Replace("\"protocol\":1","\"protocol\":2"),
            envelope.Replace("\"available\":true","\"available\":false"),new string(' ',4097)
        })Reject(()=>Read(invalid));
        Reject(()=>parsed.Select(Array.Empty<string>()));
        Reject(()=>parsed.Select(new[]{"files"}));
        Reject(()=>ObliClient.ValidateChat(new[]{new ChatMessage("user",new string('x',11990),selected)}));
        Reject(()=>ObliClient.ValidateChat(new[]{new ChatMessage("user","hello"),new ChatMessage("assistant","response",selected),new ChatMessage("user","again")}));
        ObliClient.ValidateChat(new[]{new ChatMessage("user","My computer is slow",selected)});

        var window=new MainWindow();
        var type=typeof(MainWindow);
        const BindingFlags flags=BindingFlags.NonPublic|BindingFlags.Instance;
        void Set(string name,object value)=>type.GetField(name,flags)!.SetValue(window,value);
        object? Get(string name)=>type.GetField(name,flags)!.GetValue(window);
        void Call(string name,params object[] args)=>type.GetMethod(name,flags)!.Invoke(window,args);
        T Find<T>(string name)where T:FrameworkElement=>(T)window.FindName(name);
        window.ServiceChanged(new AgentStatus(1,"test",origin.GetLeftPart(UriPartial.Authority),"connected",now,false,false,false));
        Set("staffSession",new StaffSession(new string('a',64),origin,now.AddMinutes(20)));
        Call("RenderStaff");
        Call("ApplyChatOptions",new ChatOptions(1,true,"openai","test-model",7,"services,software,system"));
        Call("ShowDiagnosticPreview",parsed);
        Check(!Find<Button>("UseDiagnosticsButton").IsEnabled&&Get("selectedDiagnostics")==null,"Checks selected without user choice.");
        Find<CheckBox>("DiagnosticSoftware").IsChecked=true;
        Find<CheckBox>("DiagnosticServices").IsChecked=true;
        Call("ShowDiagnosticPreview",selected);
        Check(Find<CheckBox>("DiagnosticSoftware").IsChecked==false&&Find<CheckBox>("DiagnosticServices").IsChecked==false&&
            !Find<Button>("UseDiagnosticsButton").IsEnabled,"New preview retained old selections.");
        Find<CheckBox>("DiagnosticSystem").IsChecked=true;
        Check(Find<TextBox>("DiagnosticPreview").Text.Contains("10.0.26100")&&!Find<TextBox>("DiagnosticPreview").Text.Contains("Microsoft Edge"),"Preview contains unselected data.");
        Call("UseDiagnosticsClick",Find<Button>("UseDiagnosticsButton"),new RoutedEventArgs());
        Check(((DiagnosticSnapshot)Get("selectedDiagnostics")!).Groups.SequenceEqual(new[]{"system"})&&
            Find<ItemsControl>("ChatRows").Items.Count==0&&Get("pendingChat")==null,"Selecting checks sent a message.");
        Call("RemoveDiagnosticsClick",Find<Button>("RemoveDiagnosticsButton"),new RoutedEventArgs());
        Check(Get("selectedDiagnostics")==null&&Find<Border>("SelectedDiagnosticPanel").Visibility==Visibility.Collapsed,"Removing draft attachment failed.");
        Call("ShowDiagnosticPreview",parsed.Select(new[]{"services"}));
        Find<CheckBox>("DiagnosticServices").IsChecked=true;
        window.Navigate("help");window.SetMotion(false);
        var destination=Environment.GetEnvironmentVariable("OBLI_DIAGNOSTICS_RENDER");
        if(!string.IsNullOrWhiteSpace(destination)){
            var root=(Grid)window.Content;root.Background=(Brush)Application.Current.FindResource("BackgroundBrush");
            root.Measure(new Size(454,900));root.Arrange(new Rect(0,0,454,900));root.UpdateLayout();
            var bitmap=new RenderTargetBitmap(454,900,96,96,PixelFormats.Pbgra32);bitmap.Render(root);
            var encoder=new PngBitmapEncoder();encoder.Frames.Add(BitmapFrame.Create(bitmap));
            using var file=File.Create(Path.GetFullPath(destination));encoder.Save(file);
        }
        Call("UseDiagnosticsClick",Find<Button>("UseDiagnosticsButton"),new RoutedEventArgs());
        Call("AddChatRow",new ChatMessage("user","Please check printing",parsed.Select(new[]{"services"})));
        var row=(StackPanel)Find<ItemsControl>("ChatRows").Items[0];
        ((Button)row.Children[row.Children.Count-1]).RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
        Check(Find<TextBox>("TicketDescription").Text.Contains("Print spooler")&&!Find<TextBox>("TicketDescription").Text.Contains("Microsoft Edge")&&
            Find<Border>("ReviewPanel").Visibility==Visibility.Collapsed,"Chosen diagnostics did not enter a reviewed ticket draft.");
        Call("ApplyChatOptions",new ChatOptions(2,true,"openai","test-model",7));
        Check(Get("selectedDiagnostics")==null&&Get("diagnosticCandidate")==null&&Find<Button>("ReviewDiagnosticsButton").Visibility==Visibility.Collapsed,"Policy change retained diagnostic draft.");
        window.ServiceChanged(null);
        Check(Find<TextBox>("SelectedDiagnosticText").Text==""&&Find<TextBox>("DiagnosticPreview").Text==""&&
            Find<TextBox>("TicketDescription").Text=="","Diagnostic evidence survived loss of staff connection.");
        window.AllowExit=true;window.Close();
        return 3;
    }
}
