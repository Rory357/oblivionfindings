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

static class TicketComputerUiChecks
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
    static HttpResponseMessage Json(object value, HttpStatusCode status = HttpStatusCode.OK) =>
        new(status) { Content = new StringContent(JsonSerializer.Serialize(value), Encoding.UTF8, "application/json") };
    static object Summary() => new { id = 12, reference = "IT-2026-12", title = "Printer stopped", status = "open", updated_at = "2026-10-08T10:00:00+00:00", path = "/it/tickets/12" };
    sealed class Wire : HttpMessageHandler
    {
        internal readonly Queue<Func<HttpRequestMessage, Task<HttpResponseMessage>>> Actions = new();
        internal readonly List<(string Method, string Path, string Body)> Seen = new();
        internal void Add(object value, HttpStatusCode status = HttpStatusCode.OK) => Actions.Enqueue(_ => Task.FromResult(Json(value, status)));
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken token)
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("/logout")) return new(HttpStatusCode.NoContent);
            Seen.Add((request.Method.Method, request.RequestUri.PathAndQuery, request.Content == null ? "" : await request.Content.ReadAsStringAsync()));
            if (Actions.Count == 0) throw new Exception("Unexpected HTTP request in tray test.");
            return await Actions.Dequeue()(request);
        }
    }
    sealed class Fixture : IDisposable
    {
        internal readonly Wire Transport = new();
        internal readonly MainWindow Window = new();
        internal Guid Request;
        internal int LocalProofs;
        internal Fixture(bool enabled = true)
        {
            Set("staffClient", new ObliClient("https://care.example.test", Transport));
            Set("staffSession", new StaffSession(new string('b', 64), new Uri("https://care.example.test"), DateTimeOffset.UtcNow.AddMinutes(20)));
            Set("staffAccount", new StaffAccount("Sam", "sam@example.test", new[] { new SupportSite(5, "Main office") }, enabled));
            Find<ComboBox>("SiteChoice").ItemsSource = new[] { new SupportSite(5, "Main office") };
            Find<ComboBox>("SiteChoice").SelectedIndex = 0;
            Window.ConfirmTicketComputer = (challenge, origin, token) =>
            {
                Check(challenge == new string('a', 64) && origin == new Uri("https://care.example.test"), "Wrong proof or origin forwarded.");
                LocalProofs++; return Task.FromResult(true);
            };
            Call("RenderStaff"); Window.Navigate("tickets");
            Find<TextBlock>("StaffNotice").Text = "Signed in as Sam. Only your own support requests appear here.";
            Find<Button>("NewRequestButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
            Find<TextBox>("TicketTitle").Text = "Printer stopped";
            Find<TextBox>("TicketDescription").Text = "The queue shows an error.";
        }
        internal T Find<T>(string name) where T : class => (T)Window.FindName(name);
        internal void Set(string name, object? value) => typeof(MainWindow).GetField(name, BindingFlags.Instance | BindingFlags.NonPublic)!.SetValue(Window, value);
        internal object? Get(string name) => typeof(MainWindow).GetField(name, BindingFlags.Instance | BindingFlags.NonPublic)!.GetValue(Window);
        internal object? Call(string name, params object?[] values) => typeof(MainWindow).GetMethod(name, BindingFlags.Instance | BindingFlags.NonPublic)!.Invoke(Window, values);
        internal Task Run(string name, params object?[] values) => (Task)Call(name, values)!;
        internal object Context(bool mint, int site = 5, bool confirmed = true) => mint
            ? new { request_uuid = Request, challenge = new string('a', 64), expires_at = DateTimeOffset.UtcNow.AddMinutes(4).ToString("O"), status = "pending", computer = (object?)null }
            : new { request_uuid = Request, expires_at = DateTimeOffset.UtcNow.AddMinutes(4).ToString("O"), status = confirmed ? "confirmed" : "pending", computer = confirmed ? new { name = "Reception PC", site = new { id = site, name = "Main office" } } : (object?)null };
        internal void QueueProof(int site = 5, bool confirmed = true)
        {
            Find<CheckBox>("IncludeComputer").IsChecked = true;
            Transport.Actions.Enqueue(_ =>
            {
                using var json = JsonDocument.Parse(Transport.Seen.Last().Body);
                Request = json.RootElement.GetProperty("request_uuid").GetGuid();
                return Task.FromResult(Json(Context(true)));
            });
            Transport.Actions.Enqueue(_ => Task.FromResult(Json(Context(false, site, confirmed))));
        }
        internal object Receipt() => new { ticket = Summary(), request_uuid = Request, replayed = true };
        internal void QueueList() => Transport.Add(new { tickets = new[] { Summary() }, next_before = (int?)null });
        internal void Render(string name, int width = 454, bool scrollDraft = false)
        {
            var dir = Environment.GetEnvironmentVariable("OBLI_COMPUTER_RENDER"); if (dir == null) return;
            Directory.CreateDirectory(dir); Window.SetMotion(false); Window.Navigate("tickets");
            Find<Button>("SignInButton").Content = "Sign out";
            var root = (Grid)Window.Content; root.Background = (Brush)Application.Current.FindResource("BackgroundBrush");
            root.Measure(new Size(width, 760)); root.Arrange(new Rect(0, 0, width, 760)); root.UpdateLayout();
            if (scrollDraft) { Find<ScrollViewer>("PageScroll").ScrollToVerticalOffset(320); root.UpdateLayout(); }
            var bitmap = new RenderTargetBitmap(width, 760, 96, 96, PixelFormats.Pbgra32); bitmap.Render(root);
            var png = new PngBitmapEncoder(); png.Frames.Add(BitmapFrame.Create(bitmap));
            using var output = File.Create(Path.Combine(dir, name)); png.Save(output);
        }
        public void Dispose() { Call("ForgetStaff", "Test ended."); Window.AllowExit = true; Window.Close(); }
    }
    public static int Run()
    {
        using (var f = new Fixture(false))
        {
            Check(f.Find<StackPanel>("ComputerChoice").Visibility == Visibility.Collapsed, "Unavailable computer capability offered.");
            Complete(f.Run("ReviewTicketAsync"));
            Check(f.Transport.Seen.Count == 0 && f.LocalProofs == 0, "Unlinked review contacted service/server.");
            Check(f.Get("pendingTicket") != null && f.Find<TextBlock>("ReviewComputer").Visibility == Visibility.Collapsed, "Unlinked review changed.");
        }
        using (var f = new Fixture())
        {
            Check(f.Find<CheckBox>("IncludeComputer").IsChecked != true, "Computer silently selected.");
            f.Render("obli-ticket-computer-draft.png", 454, true);
            f.Find<ScrollViewer>("PageScroll").ScrollToTop();
            f.QueueProof(); Complete(f.Run("ReviewTicketAsync"));
            Check(f.LocalProofs == 1 && f.Transport.Seen.Count == 2 && f.Transport.Seen.Last().Method == "GET", "Proof did not get authoritative staff summary.");
            Check(f.Find<TextBlock>("ReviewComputer").Text.Contains("Reception PC") && !f.Get("pendingTicket")!.ToString()!.Contains("Printer"), "Review missing computer or leaking draft in diagnostics.");
            Check(f.Find<StackPanel>("TicketEditor").Visibility == Visibility.Collapsed, "Review duplicated editor.");
            f.Render("obli-ticket-computer-review.png"); f.Render("obli-ticket-computer-review-narrow.png", 380);
            f.Transport.Actions.Enqueue(_ => throw new IOException("Lost reply after ticket commit"));
            Complete(f.Run("SendTicketAsync", false)); string command = f.Transport.Seen.Last().Body;
            Check(command.Contains("\"computer_context\":true") && !f.Find<Button>("EditTicketButton").IsEnabled && f.Find<Button>("CheckTicketDeliveryButton").Visibility == Visibility.Visible, "Unknown linked request became mutable.");
            f.Render("obli-ticket-computer-delivery-unknown.png", 380);
            f.Transport.Add(new { code = "computer_context_unavailable" }, HttpStatusCode.NotFound);
            Complete(f.Run("SendTicketAsync", true));
            Check(f.Get("pendingTicket") != null && f.Transport.Seen.Last().Method == "GET", "Missing receipt discarded immutable request.");
            f.Transport.Actions.Enqueue(_ => throw new IOException("Reply still lost"));
            Complete(f.Run("SendTicketAsync", false));
            Check(f.Transport.Seen.Last().Body == command && f.LocalProofs == 1, "Retry re-minted proof or changed intent.");
            f.Transport.Add(f.Receipt()); f.QueueList(); Complete(f.Run("SendTicketAsync", true));
            Check(f.Get("pendingTicket") == null && f.Find<TextBox>("TicketTitle").Text == "" && f.Find<TextBlock>("ReviewComputer").Text == "", "Confirmed receipt did not clear private draft.");
        }
        using (var f = new Fixture())
        {
            f.QueueProof(9); Complete(f.Run("ReviewTicketAsync"));
            Check(f.Get("pendingTicket") == null && f.Find<StackPanel>("TicketEditor").IsEnabled && f.Transport.Seen.All(x => !x.Path.EndsWith("/tickets")), "Wrong Site became linked ticket.");
            f.QueueProof(5, false); Complete(f.Run("ReviewTicketAsync"));
            Check(f.Get("pendingTicket") == null && f.Find<CheckBox>("IncludeComputer").IsChecked == true, "Pending proof silently became unlinked review.");
        }
        using (var f = new Fixture())
        {
            f.QueueProof(); f.Window.ConfirmTicketComputer = (_, _, _) => Task.FromResult(false);
            Complete(f.Run("ReviewTicketAsync"));
            Check(f.Get("pendingTicket") != null && f.Find<TextBlock>("ReviewComputer").Text.Contains("Reception PC"), "Lost local acknowledgement ignored successful server confirmation.");
        }
        using (var f = new Fixture())
        {
            f.QueueProof(); var slow = new TaskCompletionSource<bool>();
            f.Window.ConfirmTicketComputer = (_, _, _) => slow.Task;
            var work = f.Run("ReviewTicketAsync");
            Check(!work.IsCompleted && f.Find<Button>("CancelTicketCheckButton").Visibility == Visibility.Visible && f.Find<Button>("SignInButton").IsEnabled, "In-flight proof cannot be cancelled or signed out.");
            f.Find<Button>("CancelTicketCheckButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
            slow.SetResult(true); Complete(work);
            Check(f.Get("pendingTicket") == null && f.Find<StackPanel>("TicketEditor").IsEnabled && f.Transport.Seen.Count == 1, "Cancelled proof restored linked review or did extra network work.");
        }
        using (var f = new Fixture())
        {
            f.QueueProof(); var slow = new TaskCompletionSource<bool>();
            f.Window.ConfirmTicketComputer = (_, _, _) => slow.Task;
            var work = f.Run("ReviewTicketAsync");
            f.Call("ForgetStaff", "Signed out."); slow.SetResult(true); Complete(work);
            Check(f.Get("pendingTicket") == null && f.Find<TextBox>("TicketDescription").Text == "" && f.Find<TextBlock>("ReviewComputer").Text == "" && f.Find<CheckBox>("IncludeComputer").IsChecked != true, "Late proof restored signed-out information.");
        }
        using (var f = new Fixture())
        {
            f.QueueProof(); Complete(f.Run("ReviewTicketAsync"));
            var pending = f.Get("pendingTicket")!;
            pending.GetType().GetProperty("ComputerExpiresAt")!.SetValue(pending, DateTimeOffset.UtcNow.AddSeconds(-1));
            int before = f.Transport.Seen.Count;
            Complete(f.Run("SendTicketAsync", false));
            Check(f.Transport.Seen.Count == before && f.Find<Button>("EditTicketButton").IsEnabled && f.Find<TextBlock>("TicketDraftNotice").Text.Contains("expired"), "Expired unsent proof attempted write or trapped draft.");
            f.Find<Button>("EditTicketButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
            Check(f.Get("pendingTicket") == null && f.Find<TextBox>("TicketDescription").Text.Contains("queue"), "Expired review lost editable draft.");
        }
        using (var f = new Fixture())
        {
            f.QueueProof(); Complete(f.Run("ReviewTicketAsync"));
            f.Transport.Add(new { code = "context_expired" }, HttpStatusCode.NotFound);
            Complete(f.Run("SendTicketAsync", false));
            var pending = f.Get("pendingTicket")!;
            pending.GetType().GetProperty("ComputerExpiresAt")!.SetValue(pending, DateTimeOffset.UtcNow.AddSeconds(-1));
            Check(!f.Find<Button>("EditTicketButton").IsEnabled, "Uncertain expired send allowed new request identity.");
            f.Transport.Add(f.Receipt()); f.QueueList();
            Complete(f.Run("SendTicketAsync", true));
            Check(f.Get("pendingTicket") == null && f.LocalProofs == 1, "Expired proof prevented committed receipt recovery or re-minted association.");
        }
        Console.WriteLine("PASS: optional computer review, current Site binding, lost proof acknowledgement, cancel/sign-out clearing, immutable ticket retry and read-only recovery.");
        return 1;
    }
}
