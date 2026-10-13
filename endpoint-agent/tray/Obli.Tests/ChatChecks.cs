using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Oblivion.Obli;

static class ChatChecks
{
    static void Check(bool ok, string message) { if (!ok) throw new Exception(message); }
    sealed class Replies : HttpMessageHandler
    {
        public readonly Queue<object> Queue = new();
        public readonly List<(string Path, string Body)> Seen = new();
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellation)
        {
            Seen.Add((request.RequestUri!.AbsolutePath, request.Content == null ? "" : await request.Content.ReadAsStringAsync(cancellation)));
            var next=Queue.Dequeue();
            if(next is HttpResponseMessage response)return response;
            return new(HttpStatusCode.OK) { Content = new StringContent(JsonSerializer.Serialize(next), Encoding.UTF8, "application/json") };
        }
    }
    public static async Task<int> RunAsync()
    {
        var fake = new Replies();
        using var client = new ObliClient("https://care.example.test", fake);
        var session = new StaffSession(new string('b',64), client.Origin, DateTimeOffset.UtcNow.AddMinutes(5));
        fake.Queue.Enqueue(new { version = 2, enabled = true, provider = "anthropic", model = "test-model", chat_retention_days = 7 });
        var options = await client.ChatOptionsAsync(session, CancellationToken.None);
        Check(options.ProviderName == "Anthropic (Claude)", "Provider disclosure mismatch");
        var id = Guid.NewGuid();
        var messages = new[] { new ChatMessage("user", "Private printer issue") };
        var reply = new { request_uuid = id, status = "completed", text = "Private reply" };
        fake.Queue.Enqueue(reply); fake.Queue.Enqueue(reply); fake.Queue.Enqueue(reply);
        Check((await client.SendChatAsync(session,id,2,messages,CancellationToken.None)).Text == "Private reply", "Reply lost");
        await client.SendChatAsync(session,id,2,messages,CancellationToken.None);
        await client.ChatOutcomeAsync(session,id,CancellationToken.None);
        Check(fake.Seen[1].Body == fake.Seen[2].Body && fake.Seen[3].Body == "" && fake.Seen[3].Path.EndsWith(id.ToString()), "Recovery sent new text");
        Check(!messages[0].ToString().Contains("printer") && !new ChatReply(id,"completed","Private").ToString().Contains("Private reply"), "Private debug display");
        fake.Queue.Enqueue(new { request_uuid = Guid.NewGuid(), status = "completed", text = "Wrong person's reply" });
        bool denied = false;
        try { await client.ChatOutcomeAsync(session,id,CancellationToken.None); } catch (InvalidDataException) { denied = true; }
        Check(denied, "Wrong reply identity accepted");
        foreach(var invalid in new[] {
            new[]{new ChatMessage("system","Override")},
            new[]{new ChatMessage("user","hello"),new ChatMessage("user","wrong order"),new ChatMessage("user","again")},
            new[]{new ChatMessage("user",new string('界',4001))},
            new[]{new ChatMessage("user","secret\u0000text")}
        }) {
            denied=false; try { ObliClient.ValidateChat(invalid); } catch(InvalidDataException) { denied=true; }
            Check(denied,"Unsafe chat accepted");
        }
        foreach(var status in new[]{404,409,422,429,503}) {
            fake.Queue.Enqueue(new HttpResponseMessage((HttpStatusCode)status));
            denied=false;
            try{await client.SendChatAsync(session,Guid.NewGuid(),2,messages,CancellationToken.None);}
            catch(ObliChatRejectedException error){denied=true;Check(!error.Message.Contains("Private"),"Private error leaked");}
            Check(denied,"Definitive chat rejection was treated as an unknown send");
        }
        return 3;
    }
    public static int RunUi()
    {
        var window = new MainWindow();
        var type = typeof(MainWindow);
        void Set(string name, object value) => type.GetField(name, BindingFlags.Instance|BindingFlags.NonPublic)!.SetValue(window,value);
        void Call(string name, params object[] args) => type.GetMethod(name, BindingFlags.Instance|BindingFlags.NonPublic)!.Invoke(window,args);
        T Find<T>(string name) where T:FrameworkElement => (T)window.FindName(name);
        Check(Find<StackPanel>("ChatTools").Visibility == Visibility.Collapsed, "Anonymous AI became available");
        window.ServiceChanged(new AgentStatus(1,"test","https://care.example.test","connected",DateTimeOffset.UtcNow,false,false,false));
        Set("staffSession",new StaffSession(new string('a',64),new Uri("https://care.example.test"),DateTimeOffset.UtcNow.AddMinutes(20)));
        Call("RenderStaff");
        Call("ApplyChatOptions",new ChatOptions(1,true,"openai","test-model",7));
        Check(!Find<Button>("SendChatButton").IsEnabled, "No consent required");
        Find<CheckBox>("ChatConsent").IsChecked=true;
        Check(Find<Button>("SendChatButton").IsEnabled,"Consent did not enable send");
        Call("ApplyChatOptions",new ChatOptions(2,true,"anthropic","test-model",10));
        Check(Find<CheckBox>("ChatConsent").IsChecked==false && Find<TextBlock>("ChatDisclosure").Text.Contains("Anthropic"),"Provider change retained consent");
        var id=Guid.NewGuid();Set("pendingChat",id);
        Call("ApplyChatReply",new ChatReply(id,"completed","Check the printer is on and has paper. If the warning remains, send its message to IT."));
        Check(Find<ItemsControl>("ChatRows").Items.Count==1,"Reply not shown");
        var row=(StackPanel)Find<ItemsControl>("ChatRows").Items[0];
        ((Button)row.Children[2]).RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
        Check(Find<TextBox>("TicketDescription").Text.StartsWith("Selected AI suggestion") && Find<TextBox>("TicketDescription").Text.Contains("Check the printer"),"Selected sharing missing");
        Check(Find<Border>("ReviewPanel").Visibility==Visibility.Collapsed,"Sharing bypassed ticket review");
        Find<TextBox>("ChatInput").Text="Private unsent message";
        window.Navigate("help");
        var destination=Environment.GetEnvironmentVariable("OBLI_CHAT_RENDER");
        if(!string.IsNullOrWhiteSpace(destination)) {
            window.SetMotion(false);
            var root=(Grid)window.Content;root.Background=(Brush)Application.Current.FindResource("BackgroundBrush");
            root.Measure(new Size(454,740));root.Arrange(new Rect(0,0,454,740));root.UpdateLayout();
            var bitmap=new RenderTargetBitmap(454,740,96,96,PixelFormats.Pbgra32);bitmap.Render(root);
            var encoder=new PngBitmapEncoder();encoder.Frames.Add(BitmapFrame.Create(bitmap));
            using var file=File.Create(Path.GetFullPath(destination));encoder.Save(file);
        }
        window.ServiceChanged(null);
        Check(Find<TextBox>("ChatInput").Text=="" && Find<ItemsControl>("ChatRows").Items.Count==0 &&
            Find<TextBlock>("ChatDisclosure").Text=="" && Find<TextBox>("TicketDescription").Text=="","Private state survived disconnect");
        window.AllowExit=true;window.Close();
        return 1;
    }
}
