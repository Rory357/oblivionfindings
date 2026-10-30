using System.IO;
using System.Reflection;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using Oblivion.Obli;

static class ServiceActionUiChecks
{
    static void Check(bool value, string reason) { if (!value) throw new Exception(reason); }
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
        internal readonly ServiceActionState Offer = new(new Uri("https://care.example.test"), "requested",
            Guid.NewGuid(), Guid.NewGuid(), new string('a',64), new string('b',64), 2, "Alex Example",
            "Restart the print service to clear the stalled print queue.", "Spooler", "restart", DateTimeOffset.UtcNow.AddMinutes(2), "", false);
        internal readonly List<string> Decisions = new();
        internal Fixture()
        {
            typeof(MainWindow).GetField("configuredOrigin", BindingFlags.NonPublic | BindingFlags.Instance)!.SetValue(Window, Offer.Origin.GetLeftPart(UriPartial.Authority));
            typeof(MainWindow).GetField("serviceActionShown", BindingFlags.NonPublic | BindingFlags.Instance)!.SetValue(Window, Offer.AttemptId);
            Window.FetchServiceAction = (_, _) => Task.FromResult(Offer);
            Window.AnswerServiceAction = (_, state, decision, _) => { Decisions.Add(decision); return Task.FromResult(state with { Status = "awaiting_result", ConsentToken = "" }); };
            Find<TextBlock>("ComputerName").Text = "Reception PC";
            Window.ApplyServiceAction(Offer); Window.Navigate("device"); Window.SetMotion(false);
        }
        internal T Find<T>(string name) => (T)Window.FindName(name);
        internal void Render(string file, int width)
        {
            string? directory = Environment.GetEnvironmentVariable("OBLI_ACTION_RENDER"); if (directory == null) return;
            Directory.CreateDirectory(directory);
            var root = (Grid)Window.Content; root.Background = (Brush)Application.Current.FindResource("BackgroundBrush");
            root.Measure(new Size(width,760)); root.Arrange(new Rect(0,0,width,760)); root.UpdateLayout();
            var bitmap = new RenderTargetBitmap(width,760,96,96,PixelFormats.Pbgra32); bitmap.Render(root);
            var encoder = new PngBitmapEncoder(); encoder.Frames.Add(BitmapFrame.Create(bitmap));
            using var output = File.Create(Path.Combine(directory,file)); encoder.Save(output);
        }
        public void Dispose() { Window.ClearServiceAction(); Window.AllowExit = true; Window.Close(); }
    }
    public static int Run()
    {
        using (var f = new Fixture())
        {
            Complete(f.Window.RefreshServiceActionAsync());
            Check(f.Find<Button>("AllowServiceActionButton").IsEnabled && f.Decisions.Count == 0, "Confirmed refresh did not enable a pending request or acted on it.");
            Check(f.Find<TextBlock>("ServiceActionTarget").Text == "Restart service: Spooler" && f.Find<TextBox>("ServiceActionPurpose").Text == f.Offer.Purpose, "Exact action review missing.");
            Check(f.Find<Border>("RemotePanel").Visibility == Visibility.Collapsed, "Service request opened screen consent.");
            f.Render("obli-service-action-request.png",454); f.Render("obli-service-action-request-narrow.png",380);
            Complete(f.Window.DecideServiceActionAsync("allow"));
            Check(f.Decisions.SequenceEqual(new[]{"allow"}) && f.Find<TextBlock>("ServiceActionNotice").Text.Contains("does not undo"), "Approval misrepresented undo or dispatch.");
            Complete(f.Window.DecideServiceActionAsync("allow")); Check(f.Decisions.Count == 1, "Repeated Allow resent consent.");
        }
        using (var f = new Fixture())
        {
            f.Window.AnswerServiceAction = (_,_,decision,_) => { f.Decisions.Add(decision); throw new IOException("Lost reply"); };
            Complete(f.Window.DecideServiceActionAsync("allow"));
            f.Render("obli-service-action-unknown.png",380);
            Complete(f.Window.RefreshServiceActionAsync());
            Check(!f.Find<Button>("AllowServiceActionButton").IsEnabled && f.Decisions.Count == 1, "Uncertain approval became repeatable.");
            f.Window.FetchServiceAction = (_,_) => Task.FromResult(f.Offer with { Status = "uncertain", ConsentToken = "", MutationStarted = true, ReasonCode = "interrupted" });
            Complete(f.Window.RefreshServiceActionAsync());
            Check(f.Find<TextBlock>("ServiceActionNotice").Text.Contains("may have started"), "Unknown result falsely claimed no change.");
        }
        using (var f = new Fixture())
        {
            f.Window.ApplyServiceAction(f.Offer with { ExpiresAt = DateTimeOffset.UtcNow.AddSeconds(-1) });
            Complete(f.Window.DecideServiceActionAsync("allow"));
            Check(f.Decisions.Count == 0 && !f.Find<Button>("AllowServiceActionButton").IsEnabled, "Expired request allowed.");
        }
        using (var f = new Fixture())
        {
            f.Window.FetchServiceAction = (_,_) => throw new IOException("Unavailable");
            Complete(f.Window.RefreshServiceActionAsync());
            typeof(MainWindow).GetMethod("RenderServiceAction", BindingFlags.NonPublic | BindingFlags.Instance)!.Invoke(f.Window,null);
            Check(!f.Find<Button>("AllowServiceActionButton").IsEnabled, "Timer re-enabled unconfirmed stale request.");
            Complete(f.Window.DecideServiceActionAsync("allow"));
            Check(f.Decisions.Count == 0, "Unconfirmed stale request accepted a direct decision.");
            f.Window.FetchServiceAction = (_,_) => Task.FromResult(f.Offer);
            Complete(f.Window.RefreshServiceActionAsync());
            Check(f.Find<Button>("AllowServiceActionButton").IsEnabled, "Fresh confirmation did not recover the prompt.");
        }
        using (var f = new Fixture())
        {
            var delayed = new TaskCompletionSource<ServiceActionState>();
            f.Window.AnswerServiceAction = (_,_,decision,_) => { f.Decisions.Add(decision); return delayed.Task; };
            var work = f.Window.DecideServiceActionAsync("allow");
            f.Window.ClearServiceAction();
            delayed.SetResult(f.Offer with { Status = "awaiting_result", ConsentToken = "" }); Complete(work);
            Check(f.Find<Border>("ServiceActionPanel").Visibility == Visibility.Collapsed && f.Find<TextBlock>("ServiceActionPerson").Text == "", "Late response restored cleared identity.");
            Check(f.Decisions.Count == 1, "Clear tried to undo an in-flight approval.");
        }
        using (var f = new Fixture())
        {
            f.Window.ClearServiceAction();
            Check(f.Decisions.SequenceEqual(new[]{"decline"}) && f.Find<TextBox>("ServiceActionPurpose").Text == "", "Hidden pending consent was not declined and cleared.");
        }
        return 6;
    }
}
