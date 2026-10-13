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

static class KnowledgeUiChecks
{
    static void Check(bool ok,string why){if(!ok)throw new Exception(why);}
    static void Complete(Task task){
        if(!task.IsCompleted){var frame=new DispatcherFrame();var dispatcher=Dispatcher.CurrentDispatcher;
            task.ContinueWith(_=>dispatcher.BeginInvoke(new Action(()=>frame.Continue=false)));Dispatcher.PushFrame(frame);}
        task.GetAwaiter().GetResult();
    }
    static object Metadata(int id=12)=>new{id,title="Before restarting a shared computer",path="/it/knowledge/"+id,published_at="2026-10-07T20:00:00Z",review_due_at="2027-10-07"};
    static object Page(int id=12,int? before=null)=>new{articles=new[]{new{id,title="Before restarting a shared computer",path="/it/knowledge/"+id,published_at="2026-10-07T20:00:00Z",review_due_at="2027-10-07",summary="Save your work and check whether anyone else is using the computer."}},next_before=before};
    static object Detail()=>new{article=Metadata(),excerpt="Save your work.\nCheck with the staff using this computer before restarting it.\nIf a care task is in progress, contact IT first.",excerpt_truncated=true,version=new string('a',64)};
    static object Options(bool enabled=true)=>new{version=2,enabled,provider="openai",model="test-model",chat_retention_days=7,diagnostic_categories=Array.Empty<string>(),knowledge_enabled=true};
    static HttpResponseMessage Json(object data,HttpStatusCode status=HttpStatusCode.OK)=>new(status){Content=new StringContent(JsonSerializer.Serialize(data),Encoding.UTF8,"application/json")};
    sealed class Wire:HttpMessageHandler{
        internal readonly Queue<Func<HttpRequestMessage,Task<HttpResponseMessage>>> Actions=new();
        internal readonly List<(string Path,string Method,string Body)> Seen=new();
        internal void Add(object value,HttpStatusCode status=HttpStatusCode.OK)=>Actions.Enqueue(_=>Task.FromResult(Json(value,status)));
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request,CancellationToken cancellation){
            Seen.Add((request.RequestUri!.PathAndQuery,request.Method.Method,request.Content==null?"":await request.Content.ReadAsStringAsync()));
            if(request.RequestUri!.AbsolutePath.EndsWith("/logout"))return new(HttpStatusCode.NoContent);
            if(Actions.Count==0)throw new Exception("Unexpected request");return await Actions.Dequeue()(request);
        }
    }
    sealed class Fixture:IDisposable{
        internal readonly MainWindow Window=new();
        internal readonly Wire Transport=new();
        internal Fixture(bool enabled=true){
            Set("staffClient",new ObliClient("https://care.example.test",Transport));
            Set("staffSession",new StaffSession(new string('b',64),new Uri("https://care.example.test"),DateTimeOffset.UtcNow.AddMinutes(20)));
            Call("RenderStaff"); Call("ApplyChatOptions",new ChatOptions(2,enabled,"openai","test-model",7,"",true));Window.Navigate("help");
        }
        internal T Find<T>(string name)where T:class=>(T)Window.FindName(name);
        internal object? Get(string name)=>typeof(MainWindow).GetField(name,BindingFlags.Instance|BindingFlags.NonPublic)!.GetValue(Window);
        internal void Set(string name,object? value)=>typeof(MainWindow).GetField(name,BindingFlags.Instance|BindingFlags.NonPublic)!.SetValue(Window,value);
        internal object? Call(string name,params object?[] values)=>typeof(MainWindow).GetMethod(name,BindingFlags.Instance|BindingFlags.NonPublic)!.Invoke(Window,values);
        internal Task Task(string name,params object?[] values)=>(Task)Call(name,values)!;
        internal void Open(){Transport.Add(Detail());Complete(Task("OpenKnowledgeAsync",12));}
        internal void Attach(){Find<Button>("UseKnowledgeButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));}
        internal void Render(string name,int width=454,int height=820){
            string? root=Environment.GetEnvironmentVariable("OBLI_KNOWLEDGE_RENDER");if(string.IsNullOrEmpty(root))return;
            Directory.CreateDirectory(root);Window.SetMotion(false);
            var panel=(Grid)Window.Content;panel.Background=(Brush)Application.Current.FindResource("BackgroundBrush");
            panel.Measure(new Size(width,height));panel.Arrange(new Rect(0,0,width,height));panel.UpdateLayout();
            var bitmap=new RenderTargetBitmap(width,height,96,96,PixelFormats.Pbgra32);bitmap.Render(panel);
            var png=new PngBitmapEncoder();png.Frames.Add(BitmapFrame.Create(bitmap));using var file=File.Create(Path.Combine(root,name));png.Save(file);
        }
        public void Dispose(){Call("ForgetStaff","Test finished");Window.AllowExit=true;Window.Close();}
    }
    public static int Run()
    {
        using(var f=new Fixture(false)){
            Check(f.Find<StackPanel>("ChatTools").Visibility==Visibility.Collapsed&&f.Find<Button>("BrowseKnowledgeButton").Visibility==Visibility.Visible,"Reading guides tied to AI provider access.");
            f.Open();Check(!f.Find<Button>("UseKnowledgeButton").IsEnabled,"AI-off guide could be attached.");
            Check(f.Find<TextBlock>("KnowledgeShortened").Visibility==Visibility.Visible,"Shortened procedure lacks full-guide notice.");
            f.Render("obli-guide-reading.png");f.Render("obli-guide-reading-narrow.png",380,840);
            Check(f.Transport.Seen.All(s=>s.Method=="GET"),"Reading triggered an AI request.");
        }
        using(var f=new Fixture()){
            f.Transport.Add(Page(12,12));Complete(f.Task("LoadKnowledgeAsync","computer",null));f.Call("ShowKnowledge");
            f.Render("obli-guide-search.png",380,750);
            f.Transport.Actions.Enqueue(_=>throw new IOException("offline"));Complete(f.Task("MoreKnowledgeAsync"));
            Check(((Stack<int?>)f.Get("knowledgePages")!).Count==0&&f.Get("knowledgeBefore")==null,"Failed load advanced guide cursor.");
            f.Transport.Add(Page(8));Complete(f.Task("MoreKnowledgeAsync"));
            Check(((Stack<int?>)f.Get("knowledgePages")!).Count==1&&f.Transport.Seen.Last().Path.EndsWith("q=computer&before=12"),"Guide pagination lost query/history.");
            f.Transport.Add(Page(12,12));Complete(f.Task("PreviousKnowledgeAsync"));Check(((Stack<int?>)f.Get("knowledgePages")!).Count==0,"Previous guides unavailable.");
            f.Open();f.Attach();
            Check(f.Find<Border>("SelectedKnowledgePanel").Visibility==Visibility.Visible&&!f.Find<Button>("SendChatButton").IsEnabled,"Attachment bypassed provider consent.");
            Check(f.Find<StackPanel>("HelpPane").Visibility==Visibility.Visible&&f.Transport.Seen.All(s=>s.Method=="GET"),"Attach sent provider request.");
            f.Find<TextBox>("ChatInput").Text="What should I check before restarting?";
            f.Find<CheckBox>("ChatConsent").IsChecked=true;
            f.Transport.Add(Options());
            f.Transport.Actions.Enqueue(request=>{
                using var body=JsonDocument.Parse(f.Transport.Seen.Last().Body);
                var message=body.RootElement.GetProperty("messages")[0];var source=message.GetProperty("knowledge");
                Check(source.EnumerateObject().Count()==2&&source.GetProperty("article_id").GetInt32()==12&&!source.TryGetProperty("excerpt",out _),"Client sent arbitrary article text.");
                return System.Threading.Tasks.Task.FromResult(Json(new{request_uuid=body.RootElement.GetProperty("request_uuid").GetString(),status="completed",text="Check with other staff and save your work first."}));
            });
            Complete(f.Task("SendChatAsync"));
            Check(f.Get("pendingChat")==null&&f.Find<ItemsControl>("ChatRows").Items.Count==2&&f.Get("selectedKnowledge")==null,"Completed knowledge chat retained draft or lost messages.");
            f.Render("obli-guide-chat.png",380,900);
            f.Call("ForgetStaff","Locked");
            Check(f.Find<ItemsControl>("ChatRows").Items.Count==0&&f.Find<TextBox>("KnowledgeExcerpt").Text==""&&f.Find<TextBox>("SelectedKnowledgeText").Text==""&&f.Find<TextBlock>("KnowledgeTitle").Text=="","Private guide content survived sign-out.");
        }
        using(var f=new Fixture()){
            f.Open();f.Attach();f.Find<TextBox>("ChatInput").Text="Explain this guide";f.Find<CheckBox>("ChatConsent").IsChecked=true;
            f.Transport.Add(Options());f.Transport.Add(new{code="knowledge_unavailable",message="Unavailable"},HttpStatusCode.NotFound);
            Complete(f.Task("SendChatAsync"));
            Check(f.Get("pendingChat")==null&&f.Find<ItemsControl>("ChatRows").Items.Count==0&&f.Get("selectedKnowledge")==null&&f.Find<TextBlock>("ChatNotice").Text.Contains("processed or charged"),"Source denial misrepresented provider uncertainty or retained source.");
            Check(f.Transport.Seen.Count(s=>s.Method=="POST")==1,"Denied source automatically resent.");
        }
        using(var f=new Fixture()){
            f.Open();f.Attach();f.Set("pendingChat",Guid.NewGuid());f.Transport.Add(new{code="knowledge_unavailable",message="Unavailable"},HttpStatusCode.NotFound);
            Complete(f.Task("CheckChatAsync"));Check(f.Find<TextBox>("SelectedKnowledgeText").Text==""&&f.Get("pendingChat")==null,"Recovered source denial retained private excerpt.");
        }
        using(var f=new Fixture()){
            var delayed=new TaskCompletionSource<HttpResponseMessage>(TaskCreationOptions.RunContinuationsAsynchronously);
            f.Transport.Actions.Enqueue(_=>delayed.Task);var pending=f.Task("OpenKnowledgeAsync",12);
            f.Call("ForgetStaff","Signed out");delayed.SetResult(Json(Detail()));Complete(pending);
            Check(f.Get("knowledgeDetail")==null&&f.Find<TextBox>("KnowledgeExcerpt").Text=="","Late source restored signed-out data.");
        }
        Console.WriteLine("PASS: approved guide reading without AI, paging, explicit source/provider consent, reference-only send, denial uncertainty, sign-out and late-response privacy.");
        return 1;
    }
}
