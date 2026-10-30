using System.IO;
using System.Globalization;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using Oblivion.Obli;

internal static class SoftwareChecks
{
    static void Check(bool value, string reason) { if (!value) throw new Exception(reason); }
    static void Denied(Action action) { try { action(); } catch (InvalidDataException) { return; } throw new Exception("Unsafe software message accepted."); }
    static void Complete(Task task)
    {
        if (!task.IsCompleted)
        {
            var frame = new DispatcherFrame();
            var dispatcher = Dispatcher.CurrentDispatcher;
            task.ContinueWith(_ => dispatcher.BeginInvoke(new Action(() => frame.Continue = false)));
            Dispatcher.PushFrame(frame);
        }
        task.GetAwaiter().GetResult();
    }
    sealed class Fixture : IDisposable
    {
        internal readonly MainWindow Window = new();
        internal readonly SoftwareState Offer = new(new Uri("https://care.example.test"), "requested",
            Guid.NewGuid(), Guid.NewGuid(), new string('a',64), new string('b',64), 1, "attended",
            "Alex Example", "Update the approved document viewer used at reception.",
            "Organisation document viewer", 1, "4.2.1", "Example Software Limited", "amd64", 14000000,
            DateTimeOffset.UtcNow.AddMinutes(2), "ready", false, false, true);
        internal readonly List<string> Decisions = new();
        internal Fixture()
        {
            typeof(MainWindow).GetField("configuredOrigin", BindingFlags.NonPublic | BindingFlags.Instance)!.SetValue(Window, Offer.Origin.GetLeftPart(UriPartial.Authority));
            typeof(MainWindow).GetField("softwareShown", BindingFlags.NonPublic | BindingFlags.Instance)!.SetValue(Window, Offer.AttemptId);
            Window.FetchSoftware = (_,_) => Task.FromResult(Offer);
            Window.AnswerSoftware = (_, state, decision, _) => { Decisions.Add(decision); return Task.FromResult(state with {
                Status = decision == "allow" ? "installing" : "declined", ReasonCode = decision == "allow" ? "installing" : "declined",
                ConsentToken = "", CanDecline = false }); };
            Find<TextBlock>("ComputerName").Text = "Reception PC";
            Window.Navigate("device"); Window.ApplySoftware(Offer); Window.SetMotion(false);
        }
        internal T Find<T>(string name) => (T)Window.FindName(name);
        internal void Render(string file, int width)
        {
            string? directory = Environment.GetEnvironmentVariable("OBLI_SOFTWARE_RENDER"); if (directory == null) return;
            Directory.CreateDirectory(directory);
            var root = (Grid)Window.Content; root.Background = (Brush)Application.Current.FindResource("BackgroundBrush");
            root.Measure(new Size(width,760)); root.Arrange(new Rect(0,0,width,760)); root.UpdateLayout();
            var image = new RenderTargetBitmap(width,760,96,96,PixelFormats.Pbgra32); image.Render(root);
            var encoder = new PngBitmapEncoder(); encoder.Frames.Add(BitmapFrame.Create(image));
            using var output = File.Create(Path.Combine(directory,file)); encoder.Save(output);
        }
        public void Dispose() { Window.ClearSoftware(); Window.AllowExit = true; Window.Close(); }
    }
    internal static int Run()
    {
        using (var f = new Fixture())
        {
            Complete(f.Window.RefreshSoftwareAsync());
            Check(f.Find<Button>("AllowSoftwareButton").IsEnabled && f.Decisions.Count == 0, "Software read granted consent or disabled fresh review.");
            Check(f.Find<TextBlock>("SoftwareTarget").Text.EndsWith("4.2.1") && f.Find<TextBlock>("SoftwarePublisher").Text == f.Offer.Publisher, "Software review omitted actual version/publisher.");
            Check(f.Find<Border>("RemotePanel").Visibility == Visibility.Collapsed, "Software consent opened screen control.");
            f.Render("obli-software-request.png",454); f.Render("obli-software-request-narrow.png",380);
            Complete(f.Window.DecideSoftwareAsync("allow"));
            Check(f.Find<TextBlock>("SoftwareTitle").Text == "Checking installation permission" &&
                f.Find<TextBlock>("SoftwareNotice").Text.Contains("does not cancel"), "Prelaunch receipt claimed installation or closing cancellation.");
            Complete(f.Window.DecideSoftwareAsync("allow")); Check(f.Decisions.SequenceEqual(new[]{"allow"}), "Software approval repeated.");
        }
        using (var f = new Fixture())
        {
            f.Window.AnswerSoftware = (_,_,decision,_) => { f.Decisions.Add(decision); throw new IOException("Lost reply"); };
            Complete(f.Window.DecideSoftwareAsync("allow")); Complete(f.Window.RefreshSoftwareAsync()); Complete(f.Window.DecideSoftwareAsync("allow"));
            Check(f.Decisions.Count == 1 && !f.Find<Button>("AllowSoftwareButton").IsEnabled &&
                f.Find<TextBlock>("SoftwareNotice").Text.Contains("not send another approval"), "Lost software answer became retryable.");
            f.Window.ClearSoftware(); Check(f.Decisions.Count == 1, "Clear tried to undo an uncertain approval.");
        }
        using (var f = new Fixture())
        {
            var reply = new TaskCompletionSource<SoftwareState>();
            f.Window.AnswerSoftware = (_,_,decision,_) => { f.Decisions.Add(decision); return reply.Task; };
            var work = f.Window.DecideSoftwareAsync("allow"); f.Window.ClearSoftware();
            reply.SetResult(f.Offer with { Status = "installing", ConsentToken = "", CanDecline = false, ReasonCode = "installing" });
            Complete(work);
            Check(f.Find<Border>("SoftwarePanel").Visibility == Visibility.Collapsed && f.Find<TextBox>("SoftwarePurpose").Text == "", "Late software reply restored cleared private context.");
        }
        using (var f = new Fixture())
        {
            f.Window.FetchSoftware = (_,_) => throw new IOException("Unavailable");
            Complete(f.Window.RefreshSoftwareAsync()); Complete(f.Window.DecideSoftwareAsync("allow"));
            Check(f.Decisions.Count == 0 && !f.Find<Button>("AllowSoftwareButton").IsEnabled, "Unconfirmed software prompt accepted consent.");
            f.Window.ApplySoftware(f.Offer with { ExpiresAt = DateTimeOffset.UtcNow.AddSeconds(-1) });
            Complete(f.Window.DecideSoftwareAsync("allow")); Check(f.Decisions.Count == 0, "Expired software approval accepted.");
        }
        using (var f = new Fixture())
        {
            f.Window.ClearSoftware(); Check(f.Decisions.SequenceEqual(new[]{"decline"}), "Unanswered hidden software request was not declined.");
        }
        using (var f = new Fixture())
        {
            f.Window.ApplySoftware(f.Offer with { Status = "awaiting_observation", MutationStarted = true, CanDecline = false, ConsentToken = "", RebootRequired = true, ReasonCode = "installed_restart_required" });
            Check(f.Find<TextBlock>("SoftwareTitle").Text == "Checking installed software", "Installer receipt alone claimed verified success.");
            f.Window.ApplySoftware(f.Offer with { Status = "succeeded", MutationStarted = true, CanDecline = false, ConsentToken = "", RebootRequired = true, ReasonCode = "installed_restart_required" });
            Check(f.Find<TextBlock>("SoftwareTitle").Text.Contains("restart needed") && f.Find<TextBlock>("SoftwareNotice").Text.Contains("separately"), "Verified software hid required restart.");
            f.Render("obli-software-restart-needed.png",380);
            f.Window.ApplySoftware(f.Offer with { Status = "uncertain", MutationStarted = true, CanDecline = false, ConsentToken = "", ReasonCode = "interrupted" });
            Check(f.Find<TextBlock>("SoftwareNotice").Text.Contains("may have changed"), "Interrupted software installation claimed no changes.");
        }
        Protocol();
        return 7;
    }
    static void Protocol()
    {
        var now = DateTimeOffset.UtcNow; var origin = new Uri("https://care.example.test");
        var fields = new Dictionary<string,object> {
            ["protocol"]=1, ["server_origin"]=origin.AbsoluteUri, ["status"]="requested",
            ["command_uuid"]=Guid.NewGuid().ToString("D"), ["attempt_uuid"]=Guid.NewGuid().ToString("D"),
            ["payload_hash"]=new string('a',64), ["consent_token"]=new string('b',64), ["policy_version"]=1,
            ["consent_mode"]="attended", ["technician"]="Alex Example", ["purpose"]="Install the approved document viewer.",
            ["package_name"]="Document viewer", ["package_version"]=1, ["product_version"]="4.2.1", ["publisher"]="Example Software Limited",
            ["architecture"]="amd64", ["size_bytes"]=14000000, ["expires_at"]=now.AddMinutes(2).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),
            ["reason_code"]="ready", ["mutation_started"]=false, ["reboot_required"]=false, ["can_decline"]=true
        };
        byte[] valid = JsonSerializer.SerializeToUtf8Bytes(fields);
        var state = SoftwareProtocol.Parse(valid,origin,now);
        Check(!state.ToString().Contains("Alex"), "Private software context leaked in debug output.");
        SoftwareProtocol.ParseReply(valid,origin,state,now);
        Denied(()=>SoftwareProtocol.Parse(valid,new Uri("https://wrong.example"),now));
        Denied(()=>SoftwareProtocol.ParseReply(valid,origin,state with { ProductVersion = "4.2.2" },now));
        Denied(()=>SoftwareProtocol.ParseReply(valid,origin,state with { PackageVersion = 2 },now));
        Denied(()=>SoftwareProtocol.Answer(origin,state with { ExpiresAt = now.AddSeconds(-1) },"allow"));
        foreach (var pair in new (string key,object value)[] { ("protocol",2),("extra",true),("product_version","256.0.0"),
            ("product_version","04.2.1"),("publisher","Bad\u202ename"),("size_bytes",536870913L),("mutation_started",true),
            ("consent_mode","unattended"),("can_decline",false),("package_name",new string('x',201)) })
        {
            var bad = new Dictionary<string,object>(fields); bad[pair.key]=pair.value;
            Denied(()=>SoftwareProtocol.Parse(JsonSerializer.SerializeToUtf8Bytes(bad),origin,now));
        }
        foreach(bool needsRestart in new[]{true,false})
        {
            var interrupted = new Dictionary<string,object>(fields) {
                ["status"]="uncertain",["reason_code"]="restart_initiated",["mutation_started"]=true,
                ["reboot_required"]=needsRestart,["can_decline"]=false,["consent_token"]=""
            };
            var uncertain=SoftwareProtocol.Parse(JsonSerializer.SerializeToUtf8Bytes(interrupted),origin,now);
            Check(uncertain.Status=="uncertain" && uncertain.RebootRequired==needsRestart,"Changed boot incorrectly confirmed an uncertain installer.");
        }
        string text = Encoding.UTF8.GetString(valid);
        Denied(()=>SoftwareProtocol.Parse(Encoding.UTF8.GetBytes(text.Replace("\"protocol\":1","\"protocol\":1,\"protocol\":1")),origin,now));
        Denied(()=>SoftwareProtocol.Parse(new byte[32769],origin,now));
        fields["publisher"]=string.Concat(Enumerable.Repeat("😀",200)); fields["purpose"]=string.Concat(Enumerable.Repeat("😀",500));
        SoftwareProtocol.Parse(JsonSerializer.SerializeToUtf8Bytes(fields),origin,now);
        using var message = JsonDocument.Parse(JsonSerializer.SerializeToUtf8Bytes(SoftwareProtocol.Answer(origin,state,"allow")));
        Check(message.RootElement.EnumerateObject().Count()==7 && message.RootElement.GetProperty("operation").GetString()=="software_decide","Software consent message changed.");
        ActualRebootContract();
        string? export = Environment.GetEnvironmentVariable("OBLI_SOFTWARE_LOCAL_VIEW");
        if (!string.IsNullOrEmpty(export))
        {
            byte[] actual = File.ReadAllBytes(export); using var doc = JsonDocument.Parse(actual);
            var actualOrigin = new Uri(doc.RootElement.GetProperty("server_origin").GetString()!);
            var at = DateTimeOffset.ParseExact(doc.RootElement.GetProperty("expires_at").GetString()!,"yyyy-MM-dd'T'HH:mm:ss'Z'",CultureInfo.InvariantCulture,DateTimeStyles.AssumeUniversal|DateTimeStyles.AdjustToUniversal).AddSeconds(-1);
            var parsed = SoftwareProtocol.Parse(actual,actualOrigin,at);
            using var f = new Fixture(); f.Window.ApplySoftware(parsed); f.Render("obli-software-actual-contract.png",380);
            foreach(string suffix in new[]{".requested.json",".installing.json",".awaiting.json"}) {
                var actualState=SoftwareProtocol.Parse(File.ReadAllBytes(export+suffix),actualOrigin,at);
                SoftwareProtocol.ParseReply(File.ReadAllBytes(export+suffix),actualOrigin,actualState,at);
            }
            Console.WriteLine("PASS actual Go software local view: "+parsed.Status);
        }
    }
    static void ActualRebootContract()
    {
        string? export = Environment.GetEnvironmentVariable("OBLI_SOFTWARE_REBOOT_LOCAL_VIEW");
        if (string.IsNullOrEmpty(export)) return;
        byte[] verifiedBytes = File.ReadAllBytes(export);
        using var document = JsonDocument.Parse(verifiedBytes);
        var origin = new Uri(document.RootElement.GetProperty("server_origin").GetString()!);
        var at = DateTimeOffset.ParseExact(document.RootElement.GetProperty("expires_at").GetString()!,
            "yyyy-MM-dd'T'HH:mm:ss'Z'", CultureInfo.InvariantCulture,
            DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal).AddSeconds(-1);
        var verified = SoftwareProtocol.Parse(verifiedBytes, origin, at);
        var awaiting = SoftwareProtocol.ParseReply(File.ReadAllBytes(export + ".awaiting.json"), origin, verified, at);
        var afterBoot = SoftwareProtocol.ParseReply(File.ReadAllBytes(export + ".changed-boot.json"), origin, verified, at);
        Check(awaiting.Status == "awaiting_observation" && awaiting.MutationStarted && awaiting.RebootRequired,
            "Actual installer receipt incorrectly became verified or lost its restart notice.");
        Check(verified.Status == "succeeded" && verified.MutationStarted && verified.RebootRequired,
            "Actual first product verification cleared the required restart.");
        Check(afterBoot.Status == "succeeded" && afterBoot.MutationStarted && !afterBoot.RebootRequired,
            "Actual accepted later-boot product did not clear the restart notice.");
        using var f = new Fixture();
        f.Window.ApplySoftware(awaiting);
        Check(f.Find<TextBlock>("SoftwareTitle").Text == "Checking installed software",
            "Actual receipt was presented as completed software.");
        f.Window.ApplySoftware(verified);
        Check(f.Find<TextBlock>("SoftwareTitle").Text == "Software installed · restart needed" &&
            f.Find<TextBlock>("SoftwareNotice").Text.Contains("separately"),
            "Actual verified software hid the separate restart requirement.");
        f.Render("obli-software-actual-restart-needed.png", 380);
        f.Window.ApplySoftware(afterBoot);
        Check(f.Find<TextBlock>("SoftwareTitle").Text == "Software installation confirmed" &&
            !f.Find<TextBlock>("SoftwareNotice").Text.Contains("restart"),
            "Actual later-boot proof left a stale restart notice.");
        f.Render("obli-software-actual-after-restart.png", 380);
        Check(f.Decisions.Count == 0 && !f.Find<Button>("AllowSoftwareButton").IsEnabled,
            "Reading completed actual software evidence sent or enabled another approval.");
        Console.WriteLine("PASS actual PHP-to-Go 3010 native receipt, verified product and later-boot views.");
    }

}
