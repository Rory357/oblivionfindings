using System;
using System.IO;
using System.Reflection;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Oblivion.Obli;

static class StaffUiChecks
{
    static void Check(bool value) { if (!value) throw new Exception("Staff helper UI check failed."); }
    public static int Run()
    {
        var window = new MainWindow();
        var type = typeof(MainWindow);
        void Set(string name, object value) => type.GetField(name, BindingFlags.Instance | BindingFlags.NonPublic)!.SetValue(window, value);
        void Call(string name, params object[] values) => type.GetMethod(name, BindingFlags.Instance | BindingFlags.NonPublic)!.Invoke(window, values);
        T Find<T>(string name) where T : FrameworkElement => (T)window.FindName(name);
        Check(!Find<Button>("SignInButton").IsEnabled);
        window.ServiceChanged(new AgentStatus(1, "test", "https://care.example.test", "connected", DateTimeOffset.UtcNow, false, false, false));
        Check(Find<Button>("SignInButton").IsEnabled);
        Set("staffSession", new StaffSession(new string('a',64), new Uri("https://care.example.test"), DateTimeOffset.UtcNow.AddMinutes(20)));
        Set("staffAccount", new StaffAccount("Preview worker", "preview@example.test", new[]{new SupportSite(5,"Main office")}));
        Find<ComboBox>("SiteChoice").ItemsSource = new[]{new SupportSite(5,"Main office")};
        Find<ComboBox>("SiteChoice").SelectedIndex = 0;
        Call("RenderStaff");
        window.Navigate("tickets");
        Check(Find<StackPanel>("StaffTools").Visibility == Visibility.Visible);
        Find<Button>("NewRequestButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
        Check(Find<StackPanel>("NewRequestPanel").Visibility == Visibility.Visible);
        Find<TextBox>("TicketTitle").Text = "Reception printer is not responding";
        Find<TextBox>("TicketDescription").Text = "The printer is on and has paper. The queue shows an error.";
        Find<Button>("ReviewTicketButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
        Check(!Find<StackPanel>("TicketEditor").IsEnabled && Find<Border>("ReviewPanel").Visibility == Visibility.Visible);
        Check(Find<TextBlock>("ReviewSummary").Text.Contains("The queue shows an error."));
        Find<Button>("EditTicketButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
        Check(Find<StackPanel>("TicketEditor").IsEnabled);
        Find<TextBlock>("StaffNotice").Text = "Development preview · fictional staff account";
        var destination = Environment.GetEnvironmentVariable("OBLI_TEST_RENDER");
        if (!string.IsNullOrEmpty(destination))
        {
            window.SetMotion(false);
            var root = (Grid)window.Content;
            root.Background = (Brush)Application.Current.FindResource("BackgroundBrush");
            root.Measure(new Size(454,680)); root.Arrange(new Rect(0,0,454,680));root.UpdateLayout();
            var image=new RenderTargetBitmap(454,680,96,96,PixelFormats.Pbgra32);image.Render(root);
            var encoder=new PngBitmapEncoder();encoder.Frames.Add(BitmapFrame.Create(image));
            using var output=File.Create(Path.GetFullPath(destination));encoder.Save(output);
        }
        // The live service boundary clears private data if origin or connection is lost.
        window.ServiceChanged(null);
        Check(type.GetField("staffSession", BindingFlags.Instance|BindingFlags.NonPublic)!.GetValue(window) == null);
        Check(Find<TextBox>("TicketTitle").Text == "" && Find<TextBox>("TicketDescription").Text == "");
        Check(Find<StackPanel>("StaffTools").Visibility == Visibility.Collapsed);
        window.AllowExit=true;window.Close();
        return 1;
    }
}
