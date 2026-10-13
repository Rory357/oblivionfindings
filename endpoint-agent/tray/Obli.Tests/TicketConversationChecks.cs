using System.IO;
using System.Net;
using System.Net.Http;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using Oblivion.Obli;

static class TicketConversationChecks
{
    static void Check(bool value, string why) { if(!value) throw new Exception(why); }
    static void Complete(Task task) {
        if(!task.IsCompleted) { var frame=new DispatcherFrame(); var dispatcher=Dispatcher.CurrentDispatcher;
            task.ContinueWith(_=>dispatcher.BeginInvoke(new Action(()=>frame.Continue=false))); Dispatcher.PushFrame(frame); }
        task.GetAwaiter().GetResult();
    }
    static object Summary(int id=12)=>new { id, reference="IT-2026-"+id, title="Reception printer", status="open", updated_at="2026-10-07T20:00:00Z", path="/it/tickets/"+id };
    static object Ticket(long version=4)=>new { id=12,reference="IT-2026-12",title="Reception printer",status="open",updated_at="2026-10-07T20:00:00Z",path="/it/tickets/12",
        description="The printer is on and has paper, but the queue shows an error.",description_truncated=false,lock_version=version,can_reply=true };
    static object Comment(int id=31,string body="Please check the display for an error code.")=>new {id,body,body_truncated=false,author="Alex · IT support",created_at="2026-10-07T20:00:00Z"};
    static object Detail(long version=4,int comment=31,int? before=null)=>new {ticket=Ticket(version),comments=new[]{Comment(comment)},next_before=before};
    static HttpResponseMessage Json(object data,HttpStatusCode status=HttpStatusCode.OK)=>new(status){Content=new StringContent(JsonSerializer.Serialize(data),Encoding.UTF8,"application/json")};

    sealed class Wire:HttpMessageHandler {
        internal readonly Queue<Func<HttpRequestMessage,Task<HttpResponseMessage>>> Actions=new();
        internal readonly List<(string Path,string Method,string Body)> Seen=new();
        internal void Add(object value,HttpStatusCode status=HttpStatusCode.OK)=>Actions.Enqueue(_=>Task.FromResult(Json(value,status)));
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request,CancellationToken cancellation) {
            Seen.Add((request.RequestUri!.PathAndQuery,request.Method.Method,request.Content==null?"":await request.Content.ReadAsStringAsync()));
            if(request.RequestUri!.AbsolutePath.EndsWith("/logout")) return new(HttpStatusCode.NoContent);
            if(Actions.Count==0) throw new Exception("Unexpected test HTTP call.");
            return await Actions.Dequeue()(request);
        }
    }
    sealed class Fixture:IDisposable {
        internal readonly Wire Transport=new();
        internal readonly MainWindow Window=new();
        internal Fixture() {
            var session=new StaffSession(new string('b',64),new Uri("https://care.example.test"),DateTimeOffset.UtcNow.AddMinutes(20));
            Set("staffClient",new ObliClient("https://care.example.test",Transport));Set("staffSession",session);
            Set("staffAccount",new StaffAccount("Sam","sam@example.test",new[]{new SupportSite(5,"Main office")}));
            Call("RenderStaff");Window.Navigate("tickets");
        }
        internal T Find<T>(string name)where T:class=>(T)Window.FindName(name);
        internal object? Get(string name)=>typeof(MainWindow).GetField(name,BindingFlags.Instance|BindingFlags.NonPublic)!.GetValue(Window);
        internal void Set(string name,object? value)=>typeof(MainWindow).GetField(name,BindingFlags.Instance|BindingFlags.NonPublic)!.SetValue(Window,value);
        internal object? Call(string name,params object?[] values)=>typeof(MainWindow).GetMethod(name,BindingFlags.Instance|BindingFlags.NonPublic)!.Invoke(Window,values);
        internal Task Task(string name,params object?[] values)=>(Task)Call(name,values)!;
        internal void Review(string body) { Find<TextBox>("ReplyBody").Text=body; Find<Button>("ReviewReplyButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent)); }
        internal void Open() { Transport.Add(Detail());Complete(Task("OpenTicketAsync",12)); }
        internal void Render(string name,int width=454,int height=800) {
            string? root=Environment.GetEnvironmentVariable("OBLI_TICKET_RENDER");if(string.IsNullOrEmpty(root))return;
            Directory.CreateDirectory(root); Window.SetMotion(false);Window.Navigate("tickets");
            var panel=(Grid)Window.Content;panel.Background=(Brush)Application.Current.FindResource("BackgroundBrush");
            panel.Measure(new Size(width,height));panel.Arrange(new Rect(0,0,width,height));panel.UpdateLayout();
            var bitmap=new RenderTargetBitmap(width,height,96,96,PixelFormats.Pbgra32);bitmap.Render(panel);
            var png=new PngBitmapEncoder();png.Frames.Add(BitmapFrame.Create(bitmap));using var file=File.Create(Path.Combine(root,name));png.Save(file);
        }
        public void Dispose() { Call("ForgetStaff","Test finished.");Window.AllowExit=true;Window.Close(); }
    }
    public static int Run()
    {
        using(var f=new Fixture()) {
            f.Open();
            Check(f.Find<StackPanel>("TicketConversationPanel").Visibility==Visibility.Visible && f.Find<TextBlock>("ConversationReference").Text=="IT-2026-12","Conversation not shown.");
            Check(f.Find<ItemsControl>("ConversationRows").Items.Count==1,"Public message absent.");
            f.Render("obli-ticket-conversation.png");f.Render("obli-ticket-conversation-narrow.png",380,740);
            f.Review("The display says E4. I checked the paper tray.");
            Check(f.Transport.Seen.Count==1 && !f.Find<TextBox>("ReplyBody").IsEnabled,"Review sent or remained mutable.");
            Check(f.Find<TextBox>("ReplyReviewText").Text.Contains("E4"),"Reviewed reply absent.");
            f.Transport.Actions.Enqueue(_=>throw new IOException("Lost reply after commit"));
            Complete(f.Task("SendReplyAsync",false));
            string first=f.Transport.Seen.Last().Body;
            Check(f.Get("pendingReply")!=null && !f.Find<Button>("EditReplyButton").IsEnabled && f.Find<Button>("CheckReplyButton").Visibility==Visibility.Visible,"Unknown reply became editable.");
            f.Render("obli-ticket-delivery-unknown.png",380,850);
            f.Transport.Add(new{},HttpStatusCode.NotFound);Complete(f.Task("SendReplyAsync",true));
            Check(f.Get("pendingReply")!=null && f.Transport.Seen.Last().Method=="GET","Missing receipt lost pending reply.");
            f.Transport.Actions.Enqueue(request=>{
                using var json=JsonDocument.Parse(f.Transport.Seen.Last().Body);
                return System.Threading.Tasks.Task.FromResult(Json(new {ticket=Ticket(5),comment=Comment(32,"The display says E4. I checked the paper tray."),
                    request_uuid=json.RootElement.GetProperty("request_uuid").GetString(),committed_version=5}));
            });
            f.Transport.Add(Detail(5,32));
            Complete(f.Task("SendReplyAsync",false));
            var posts=f.Transport.Seen.Where(s=>s.Method=="POST").ToArray();
            Check(posts.Length==2 && posts[0].Body==posts[1].Body && posts[0].Body==first,"Retry changed command identity/content.");
            Check(f.Get("pendingReply")==null && f.Find<TextBox>("ReplyBody").Text=="" && f.Find<TextBlock>("ConversationNotice").Text.StartsWith("Reply received"),"Confirmed receipt failed to clear draft.");
        }
        using(var f=new Fixture()) {
            f.Open();f.Review("Please call me when available.");
            f.Transport.Add(new{code="version_conflict",message="Ticket changed."},HttpStatusCode.Conflict);
            Complete(f.Task("SendReplyAsync",false));
            Check(f.Get("pendingReply")==null && f.Find<TextBox>("ReplyBody").Text.Contains("call me") && !f.Find<Button>("ReviewReplyButton").IsEnabled,"Version conflict lost draft or allowed stale send.");
            f.Transport.Add(Detail(8));
            Complete(f.Task("LoadConversationAsync",12,null));
            Check(f.Find<Button>("ReviewReplyButton").IsEnabled,"Fresh details did not re-enable review.");
            f.Review("Please call me when available.");
            Check(f.Get("pendingReply")!.GetType().GetProperty("Version")!.GetValue(f.Get("pendingReply"))!.Equals(8L),"Review retained stale version.");
            f.Find<Button>("EditReplyButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
            f.Find<Button>("DiscardReplyButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
            Check(f.Find<TextBox>("ReplyBody").Text=="" && f.Find<Button>("BackToTicketsButton").IsEnabled,"Draft cannot be discarded.");
        }
        using(var f=new Fixture()) {
            f.Open();
            f.Review("Unsent\u0001draft");
            Check(f.Get("pendingReply")==null && f.Find<TextBox>("ReplyBody").IsEnabled && f.Transport.Seen.Count==1,"Invalid local text locked a draft as uncertain.");
            f.Review("This exact reply is awaiting confirmation.");
            f.Transport.Actions.Enqueue(_=>throw new IOException("Unknown outcome"));
            Complete(f.Task("SendReplyAsync",false));
            var posted=JsonDocument.Parse(f.Transport.Seen.Last().Body);
            f.Transport.Add(new{ticket=Ticket(5),comment=Comment(32,"Different message"),request_uuid=posted.RootElement.GetProperty("request_uuid").GetString(),committed_version=5});
            Complete(f.Task("SendReplyAsync",true));
            Check(f.Get("pendingReply")!=null && f.Find<TextBox>("ReplyBody").Text.Contains("exact reply"),"Mismatched recovered body cleared reviewed intent.");
        }
        using(var f=new Fixture()) {
            f.Transport.Add(new{tickets=new[]{Summary(12),Summary(10)},next_before=10});
            Complete(f.Task("LoadTicketsAsync"));
            Check(f.Find<ItemsControl>("TicketRows").Items.Count==2 && f.Find<Button>("OlderTicketsButton").Visibility==Visibility.Visible,"Ticket cursor absent.");
            f.Set("ticketBefore",10);f.Transport.Add(new{tickets=new[]{Summary(8)},next_before=(int?)null});Complete(f.Task("LoadTicketsAsync"));
            Check(f.Transport.Seen.Last().Path.EndsWith("?before=10") && f.Find<ItemsControl>("TicketRows").Items.Count==1,"Ticket page not replaced.");
            f.Transport.Add(Detail(4,31,31));Complete(f.Task("OpenTicketAsync",12));
            f.Transport.Add(Detail(4,20));Complete(f.Task("LoadConversationAsync",12,31));
            Check(f.Transport.Seen.Last().Path.EndsWith("?before=31") && f.Find<ItemsControl>("ConversationRows").Items.Count==1,"Reply history not paginated.");
            f.Transport.Add(new{},HttpStatusCode.NotFound);Complete(f.Task("LoadConversationAsync",12,null));
            Check(f.Get("conversation")==null && f.Find<ItemsControl>("ConversationRows").Items.Count==0,"Unavailable ticket retained private data.");
        }
        using(var f=new Fixture()) {
            f.Transport.Add(Detail(4,31,31));Complete(f.Task("OpenTicketAsync",12));
            f.Transport.Actions.Enqueue(_=>throw new IOException("Offline"));
            Complete(f.Task("LoadEarlierRepliesAsync"));
            Check(((Stack<int?>)f.Get("replyPages")!).Count==0 && f.Get("replyBefore")==null,"Failed history load advanced the cursor.");
            f.Transport.Add(Detail(4,20));Complete(f.Task("LoadEarlierRepliesAsync"));
            Check(((Stack<int?>)f.Get("replyPages")!).Count==1,"Successful history navigation lost the newer page.");
            f.Transport.Actions.Enqueue(_=>throw new IOException("Offline"));
            Complete(f.Task("LoadNewerRepliesAsync"));
            Check(((Stack<int?>)f.Get("replyPages")!).Count==1,"Failed newer-page load discarded history.");
        }
        using(var f=new Fixture()) {
            var delayed=new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
            f.Transport.Actions.Enqueue(_=>delayed.Task);
            var old=f.Task("OpenTicketAsync",12);Complete(f.Task("OpenTicketAsync",12));
            Check(f.Transport.Seen.Count==1,"Duplicate detail request.");
            f.Call("ForgetStaff","Signed out.");delayed.SetResult(Json(Detail()));Complete(old);
            Check(f.Get("conversation")==null && f.Find<StackPanel>("TicketConversationPanel").Visibility==Visibility.Collapsed,"Late response restored signed-out data.");
        }
        using(var f=new Fixture()) {
            f.Open();f.Review("Private draft to clear on Windows lock.");
            f.Window.ServiceChanged(null);
            // A fixture has no configured origin; directly exercise the same private clear used by session lock.
            f.Call("ForgetStaff","Locked");
            Check(f.Get("pendingReply")==null && f.Get("conversation")==null && f.Find<TextBox>("ReplyBody").Text=="" && f.Find<TextBox>("ReplyReviewText").Text=="" && f.Find<TextBlock>("ReplyReviewHeading").Text=="","Private reply survived sign-out.");
        }
        Console.WriteLine("PASS: native ticket conversation, reviewed replies, exact uncertain retry, read-only recovery, version conflicts, pagination, unavailable/late-response privacy and rendering.");
        return 1;
    }
}
