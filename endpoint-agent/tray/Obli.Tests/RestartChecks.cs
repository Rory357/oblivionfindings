using System.IO;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using Oblivion.Obli;

static class RestartChecks
{
    static void Check(bool value, string reason) { if (!value) throw new Exception(reason); }
    static void Denied(Action action)
    {
        try { action(); } catch (InvalidDataException) { return; }
        throw new Exception("Invalid restart data was accepted.");
    }
    static void Complete(Task task)
    {
        if (!task.IsCompleted)
        {
            var frame = new DispatcherFrame(); var dispatcher = Dispatcher.CurrentDispatcher;
            task.ContinueWith(_ => dispatcher.BeginInvoke(new Action(() => frame.Continue = false)));
            Dispatcher.PushFrame(frame);
        }
        task.GetAwaiter().GetResult();
    }
    sealed class Fixture : IDisposable
    {
        internal readonly MainWindow Window = new();
        internal readonly RestartState Offer;
        internal readonly List<string> Decisions = new(), Visibility = new();
        internal Fixture()
        {
            var time = DateTimeOffset.FromUnixTimeSeconds(DateTimeOffset.UtcNow.AddMinutes(3).ToUnixTimeSeconds());
            Offer = new(new Uri("https://care.example.test"), "requested", Guid.NewGuid(), Guid.NewGuid(),
                new string('a',64), new string('b',64), 2, "attended", "Alex Example",
                "Restart after the approved update. Save any open care notes before allowing the restart.",
                time, time.AddSeconds(5), 60, 180, "", false, true);
            typeof(MainWindow).GetField("configuredOrigin", BindingFlags.NonPublic | BindingFlags.Instance)!.SetValue(Window, Offer.Origin.GetLeftPart(UriPartial.Authority));
            typeof(MainWindow).GetField("restartShown", BindingFlags.NonPublic | BindingFlags.Instance)!.SetValue(Window, Offer.AttemptId);
            Window.FetchRestart = (_, _) => Task.FromResult(Offer);
            Window.RestartVisibilityForChecks = () => true;
            Window.MarkRestartVisibility = (_, state, decision, _) => { Visibility.Add(decision); return Task.FromResult(state); };
            Window.AnswerRestart = (_, state, decision, _) => {
                Decisions.Add(decision);
                return Task.FromResult(decision == "cancel"
                    ? state with {Status="cancelled", ReasonCode="cancelled", ConsentToken="", CanCancel=false}
                    : state with {Status="countdown"});
            };
            Find<TextBlock>("ComputerName").Text = "Reception PC";
            Window.Navigate("device"); Window.SetMotion(false); Window.ApplyRestart(Offer);
        }
        internal T Find<T>(string name) => (T)Window.FindName(name);
        internal void Render(string file, int width)
        {
            string? directory = Environment.GetEnvironmentVariable("OBLI_RESTART_RENDER");
            if (directory == null) return;
            Directory.CreateDirectory(directory);
            var root=(Grid)Window.Content; root.Background=(Brush)Application.Current.FindResource("BackgroundBrush");
            root.Measure(new Size(width,820)); root.Arrange(new Rect(0,0,width,820)); root.UpdateLayout();
            var bitmap=new RenderTargetBitmap(width,820,96,96,PixelFormats.Pbgra32); bitmap.Render(root);
            var encoder=new PngBitmapEncoder(); encoder.Frames.Add(BitmapFrame.Create(bitmap));
            using var output=File.Create(Path.Combine(directory,file)); encoder.Save(output);
        }
        public void Dispose() { Window.ClearRestart(); Window.AllowExit=true; Window.Close(); }
    }
    internal static int Run()
    {
        using(var f=new Fixture())
        {
            Complete(f.Window.RefreshRestartAsync());
            Check(f.Decisions.Count==0 && f.Visibility.SequenceEqual(new[]{"visible"}), "Polling consent issued a restart decision.");
            Check(f.Find<Button>("AllowRestartButton").IsEnabled && f.Find<Button>("CancelRestartButton").Visibility==Visibility.Collapsed, "Pending review controls are unclear.");
            Check(f.Find<TextBox>("RestartPurpose").Text==f.Offer.Purpose && f.Find<Border>("RemotePanel").Visibility==Visibility.Collapsed, "Restart review lost purpose or requested screen access.");
            f.Render("obli-restart-request.png",454); f.Render("obli-restart-request-narrow.png",380);
            Complete(f.Window.DecideRestartAsync("allow")); Complete(f.Window.DecideRestartAsync("allow"));
            Check(f.Decisions.SequenceEqual(new[]{"allow"}) && f.Find<TextBlock>("RestartTitle").Text=="Computer restart scheduled", "Allow was repeated or claimed completed restart.");
            f.Render("obli-restart-countdown.png",380);
            Complete(f.Window.DecideRestartAsync("cancel"));
            Check(f.Decisions.SequenceEqual(new[]{"allow","cancel"}) && f.Find<TextBlock>("RestartNotice").Text.Contains("confirmed"), "Cancellation lacked exact local acknowledgement.");
        }
        using(var f=new Fixture())
        {
            f.Window.AnswerRestart=(_,_,decision,_)=>{f.Decisions.Add(decision);throw new IOException("Lost answer");};
            Complete(f.Window.DecideRestartAsync("allow"));
            Complete(f.Window.RefreshRestartAsync()); Complete(f.Window.DecideRestartAsync("allow"));
            Check(f.Decisions.Count==1 && !f.Find<Button>("AllowRestartButton").IsEnabled, "An uncertain approval was sent again.");
        }
        using(var f=new Fixture())
        {
            f.Window.ApplyRestart(f.Offer with {Status="countdown"});
            f.Window.AnswerRestart=(_,_,decision,_)=>{f.Decisions.Add(decision);throw new IOException("Lost cancel");};
            Complete(f.Window.DecideRestartAsync("cancel")); Complete(f.Window.DecideRestartAsync("cancel"));
            Check(f.Decisions.Count==1 && !f.Find<TextBlock>("RestartTitle").Text.Contains("cancelled") && f.Find<TextBlock>("RestartNotice").Text.Contains("cancellation could not be confirmed"), "Unknown cancellation claimed success or repeated.");
            f.Render("obli-restart-cancel-unconfirmed.png",380);
            f.Window.FetchRestart=(_,_)=>Task.FromResult(f.Offer with{Status="countdown"});
            Complete(f.Window.RefreshRestartAsync());
            Check((string)f.Find<Button>("CancelRestartButton").Content=="Retry cancellation", "Fresh cancellable state did not offer explicit recovery.");
            Check(f.Decisions.Count==1, "Reading cancellation state automatically retried it.");
            Complete(f.Window.DecideRestartAsync("cancel")); Check(f.Decisions.Count==2, "Explicit confirmed cancellation retry was unavailable.");
            f.Window.FetchRestart=(_,_)=>Task.FromResult(f.Offer with{Status="awaiting_boot", ConsentToken="", CanCancel=false, MutationStarted=true, ReasonCode="restart_requested"});
            Complete(f.Window.RefreshRestartAsync());
            Check(f.Find<TextBlock>("RestartTitle").Text=="Waiting for restart confirmation" && f.Find<Button>("CancelRestartButton").Visibility==Visibility.Collapsed, "Accepted restart was represented as cancelable or complete.");
        }
        using(var f=new Fixture())
        {
            var delayed=new TaskCompletionSource<RestartState>();
            f.Window.AnswerRestart=(_,_,decision,_)=>{f.Decisions.Add(decision);return delayed.Task;};
            var action=f.Window.DecideRestartAsync("allow");
            f.Window.ClearRestart();
            delayed.SetResult(f.Offer with{Status="countdown"}); Complete(action);
            Check(f.Visibility.Contains("hidden") && f.Find<Border>("RestartPanel").Visibility==Visibility.Collapsed && f.Find<TextBox>("RestartPurpose").Text=="", "Late reply restored cleared restart context.");
        }
        using(var f=new Fixture())
        {
            f.Window.Navigate("help");
            Check(f.Visibility.SequenceEqual(new[]{"hidden"}) && f.Decisions.Count==0, "Leaving notice failed to withdraw visibility or manufactured cancellation.");
        }
        using(var f=new Fixture())
        {
            f.Window.FetchRestart=(_,_)=>throw new IOException("Pipe unavailable");
            Complete(f.Window.RefreshRestartAsync()); Complete(f.Window.DecideRestartAsync("allow"));
            Check(f.Decisions.Count==0 && !f.Find<Button>("AllowRestartButton").IsEnabled, "Stale local confirmation allowed a decision.");
            f.Window.ApplyRestart(f.Offer with{ExecuteAt=DateTimeOffset.UtcNow.AddSeconds(-10),ExpiresAt=DateTimeOffset.UtcNow.AddSeconds(-5)});
            Complete(f.Window.DecideRestartAsync("allow"));
            Check(f.Decisions.Count==0 && !f.Find<Button>("AllowRestartButton").IsEnabled, "Expired schedule allowed a decision.");
        }
        using(var f=new Fixture())
        {
            f.Window.ApplyRestart(f.Offer with{Status="uncertain", ConsentToken="", CanCancel=false, MutationStarted=false, ReasonCode="interrupted"});
            Check(!f.Find<TextBlock>("RestartNotice").Text.Contains("may have started"), "Pre-API uncertainty claimed execution.");
            f.Window.ApplyRestart(f.Offer with{Status="uncertain", ConsentToken="", CanCancel=false, MutationStarted=true, ReasonCode="interrupted"});
            Check(f.Find<TextBlock>("RestartNotice").Text.Contains("may have started"), "API uncertainty hid possible execution.");
            f.Render("obli-restart-uncertain.png",380);
        }
        using(var f=new Fixture())
        {
            f.Window.RestartVisibilityForChecks=()=>false;
            Complete(f.Window.RefreshRestartAsync());
            Check(f.Visibility.SequenceEqual(new[]{"hidden"}) && f.Decisions.Count==0, "A hidden warning was marked visible or used as consent.");
        }
        Protocol();
        return 9;
    }
    static void Protocol()
    {
        var origin=new Uri("https://care.example.test"); var now=DateTimeOffset.UtcNow;
        var execute=DateTimeOffset.FromUnixTimeSeconds(now.AddMinutes(3).ToUnixTimeSeconds());
        var data=new {protocol=1, server_origin=origin.AbsoluteUri, status="requested",
            command_uuid=Guid.NewGuid().ToString("D"),attempt_uuid=Guid.NewGuid().ToString("D"),
            payload_hash=new string('a',64),consent_token=new string('b',64),policy_version=1,
            consent_mode="attended",technician="Alex Example",purpose="Restart after the approved update.",
            execute_at=execute.ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),expires_at=execute.AddSeconds(5).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),
            warning_seconds=60,remaining_seconds=180,reason_code="",mutation_started=false,can_cancel=true};
        byte[] bytes=JsonSerializer.SerializeToUtf8Bytes(data);
        var state=RestartProtocol.Parse(bytes,origin,now);
        Check(!state.ToString().Contains("Alex"),"Private restart information leaked in debug output.");
        RestartProtocol.ParseReply(bytes,origin,state,now);
        using(var message=JsonDocument.Parse(JsonSerializer.SerializeToUtf8Bytes(RestartProtocol.Message(origin,state,"allow",false))))
            Check(message.RootElement.EnumerateObject().Count()==7 && message.RootElement.GetProperty("operation").GetString()=="restart_decide","Restart decision shape changed.");
        Denied(()=>RestartProtocol.Parse(bytes,new Uri("https://wrong.example"),now));
        Denied(()=>RestartProtocol.ParseReply(bytes,origin,state with{ExecuteAt=execute.AddSeconds(1)},now));
        Denied(()=>RestartProtocol.Message(origin,state with{Status="awaiting_boot",ConsentToken="",MutationStarted=true,CanCancel=false},"cancel",false));
        Denied(()=>RestartProtocol.Message(origin,state with{ExpiresAt=now.AddSeconds(-1)},"allow",false));
        RestartProtocol.Message(origin,state with{ExpiresAt=now.AddSeconds(-1)},"hidden",true);
        string valid=Encoding.UTF8.GetString(bytes);
        foreach(string bad in new[]{
            valid.Replace("\"protocol\":1","\"protocol\":1,\"protocol\":1"),
            valid.Replace("\"protocol\":1","\"protocol\":1,\"force_close\":true"),
            valid.Replace("\"warning_seconds\":60","\"warning_seconds\":0"),
            valid.Replace("\"remaining_seconds\":180","\"remaining_seconds\":86401"),
            valid.Replace("\"mutation_started\":false","\"mutation_started\":true"),
            valid.Replace("\"consent_mode\":\"attended\"","\"consent_mode\":\"unattended\""),
            valid.Replace("\"can_cancel\":true","\"can_cancel\":false"),
            valid.Replace(new string('a',64),new string('A',64)),
            valid.Replace("Alex Example","Alex\\u202eExample")
        })Denied(()=>RestartProtocol.Parse(Encoding.UTF8.GetBytes(bad),origin,now));
        string? exported=Environment.GetEnvironmentVariable("OBLI_RESTART_LOCAL_VIEW");
        if(!string.IsNullOrEmpty(exported))
        {
            byte[] actual=File.ReadAllBytes(exported); using var doc=JsonDocument.Parse(actual);
            var at=DateTimeOffset.Parse(doc.RootElement.GetProperty("execute_at").GetString()!).AddSeconds(-1);
            var actualOrigin=new Uri(doc.RootElement.GetProperty("server_origin").GetString()!);
            var parsed=RestartProtocol.Parse(actual,actualOrigin,at);
            Check(parsed.Status=="awaiting_boot" && parsed.MutationStarted && parsed.ReasonCode=="restart_requested","Actual Go restart state changed.");
            foreach(string suffix in new[]{".requested.json",".countdown.json"}) {
                byte[] live=File.ReadAllBytes(exported+suffix);
                var other=RestartProtocol.Parse(live,actualOrigin,at);
                Check(other.Status==suffix.Split('.')[1] && other.ConsentToken.Length==64 && other.CanCancel, "Actual Go actionable state differs.");
                RestartProtocol.ParseReply(live,actualOrigin,other,at);
            }
            Console.WriteLine("PASS actual Go requested/countdown/awaiting_boot restart contracts");
            using(var render=new Fixture()) {
                render.Window.ApplyRestart(parsed);
                render.Render("obli-restart-php-awaiting-boot.png",380);
            }
        }
        foreach(var extra in new[]{("OBLI_RESTART_CONFIRMED_VIEW","succeeded","boot_observed",true),
                                   ("OBLI_RESTART_CANCELLED_VIEW","cancelled","cancelled",false)}) {
            string? extraPath=Environment.GetEnvironmentVariable(extra.Item1);
            if(string.IsNullOrEmpty(extraPath)) continue;
            byte[] actual=File.ReadAllBytes(extraPath); using var doc=JsonDocument.Parse(actual);
            var originValue=new Uri(doc.RootElement.GetProperty("server_origin").GetString()!);
            var observed=DateTimeOffset.Parse(doc.RootElement.GetProperty("execute_at").GetString()!).AddSeconds(1);
            var actualState=RestartProtocol.Parse(actual,originValue,observed);
            Check(actualState.Status==extra.Item2 && actualState.ReasonCode==extra.Item3 &&
                actualState.MutationStarted==extra.Item4 && !actualState.CanCancel,
                "Actual PHP-to-Go terminal state is inconsistent.");
            using(var render=new Fixture()) {
                render.Window.ApplyRestart(actualState);
                Check(render.Find<Button>("AllowRestartButton").Visibility!=Visibility.Visible &&
                    render.Find<Button>("CancelRestartButton").Visibility!=Visibility.Visible,
                    "A terminal restart still offers execution or cancellation.");
                render.Render("obli-restart-php-"+extra.Item2+".png",380);
            }
            Console.WriteLine("PASS actual PHP-to-Go "+extra.Item2+" restart contract");
        }
    }
}
