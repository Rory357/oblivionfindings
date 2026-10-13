using System;
using System.Globalization;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Threading;
namespace Oblivion.Obli;

public partial class MainWindow
{
    readonly DispatcherTimer restartTimer = new() { Interval = TimeSpan.FromSeconds(2) };
    RestartState? restartState;
    CancellationTokenSource? restartCall;
    Guid? restartShown, restartAnswered, restartCancelSent;
    long restartGeneration;
    bool restartBusy, restartConfirmed;
    internal Func<Uri, CancellationToken, Task<RestartState>>? FetchRestart;
    internal Func<Uri, RestartState, string, CancellationToken, Task<RestartState>>? AnswerRestart;
    internal Func<Uri, RestartState, string, CancellationToken, Task<RestartState>>? MarkRestartVisibility;
    internal Func<bool>? RestartVisibilityForChecks;

    void InitialiseRestartSupport()
    {
        restartTimer.Tick += async (_, _) => await RefreshRestartAsync();
        StateChanged += (_, _) => { if (WindowState == WindowState.Minimized) ClearRestart(); };
        Deactivated += (_, _) => ClearRestart();
        IsVisibleChanged += (_, _) => { if (!IsVisible) ClearRestart(); };
        Closed += (_, _) => { restartTimer.Stop(); ClearRestart(); };
    }
    bool RestartVisible()
    {
        if (RestartVisibilityForChecks != null) return RestartVisibilityForChecks();
        if (!IsVisible || !IsActive || WindowState == WindowState.Minimized || DesktopPrivacyLocked ||
            !DevicePane.IsVisible || !RestartPanel.IsVisible) return false;
        // A rendered but scrolled-away notice does not count as a visible warning.
        DependencyObject? parent = RestartPanel;
        while (parent != null && parent is not ScrollViewer) parent = VisualTreeHelper.GetParent(parent);
        if (parent is not ScrollViewer scroll) return false;
        var bounds = RestartCountdown.TransformToAncestor(scroll).TransformBounds(new Rect(RestartCountdown.RenderSize));
        bounds.Union(RestartNotice.TransformToAncestor(scroll).TransformBounds(new Rect(RestartNotice.RenderSize)));
        return new Rect(0, 0, scroll.ViewportWidth, scroll.ViewportHeight).Contains(bounds);
    }
    internal async Task RefreshRestartAsync()
    {
        if (restartBusy || configuredOrigin == null || DesktopPrivacyLocked || lifetime.IsCancellationRequested) return;
        string configured = configuredOrigin;
        var origin = new Uri(configured);
        long generation = restartGeneration;
        using var call = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        call.CancelAfter(TimeSpan.FromSeconds(6));
        restartCall = call; restartBusy = true;
        try
        {
            var next = await (FetchRestart?.Invoke(origin, call.Token) ?? client.GetRestartAsync(origin, call.Token));
            if (generation != restartGeneration || configuredOrigin != configured || call.IsCancellationRequested) return;
            ApplyRestart(next);
            if ((next.Status is "requested" or "countdown") && restartShown != next.AttemptId)
            {
                restartShown = next.AttemptId;
                ShowFromTray("device"); RestartPanel.BringIntoView(); UpdateLayout();
            }
            if (next.Status is "requested" or "countdown")
            {
                string visibility = RestartVisible() ? "visible" : "hidden";
                next = await (MarkRestartVisibility?.Invoke(origin, next, visibility, call.Token) ??
                    client.MarkRestartVisibleAsync(origin, next, visibility, call.Token));
                if (generation != restartGeneration || configuredOrigin != configured || call.IsCancellationRequested) return;
                ApplyRestart(next);
            }
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        {
            if (generation == restartGeneration) restartConfirmed = false;
        }
        finally
        {
            if (generation == restartGeneration)
            {
                restartBusy = false; restartCall = null; RenderRestart();
            }
        }
    }
    internal void ApplyRestart(RestartState state)
    {
        restartState = state.Status == "idle" ? null : state;
        restartConfirmed = true;
        RenderRestart();
    }
    void RenderRestart()
    {
        var state = restartState;
        RestartPanel.Visibility = state == null ? Visibility.Collapsed : Visibility.Visible;
        AllowRestartButton.IsEnabled = DeclineRestartButton.IsEnabled = CancelRestartButton.IsEnabled = false;
        CheckRestartButton.IsEnabled = !restartBusy;
        if (state == null)
        {
            RestartPerson.Text = RestartPurpose.Text = RestartTime.Text = RestartCountdown.Text = RestartNotice.Text = "";
            return;
        }
        bool expired = state.ExpiresAt <= DateTimeOffset.UtcNow;
        bool actionable = state.Status is "requested" or "countdown";
        bool answered = restartAnswered == state.AttemptId, cancelling = restartCancelSent == state.AttemptId;
        RestartTitle.Text = state.Status switch {
            "requested" => "Allow this computer to restart?",
            "countdown" => "Computer restart scheduled",
            "awaiting_result" when state.MutationStarted => "Restart request in progress",
            "awaiting_result" => "Checking restart authority",
            "awaiting_boot" => "Waiting for restart confirmation",
            "succeeded" => "Computer restart confirmed",
            "cancelled" => "Computer restart cancelled",
            "declined" => "Computer restart declined",
            "expired" => "The restart window has closed",
            "uncertain" when !state.MutationStarted => "The restart request needs checking",
            "uncertain" => "The restart result needs checking",
            _ => "Computer restart could not finish"
        };
        RestartPerson.Text = state.Technician + " · IT support";
        RestartPurpose.Text = state.Purpose;
        RestartTime.Text = state.ExecuteAt?.ToLocalTime().ToString("d MMM yyyy, h:mm:ss tt", CultureInfo.GetCultureInfo("en-NZ")) + " · this computer's time";
        RestartCountdown.Text = actionable && !expired && restartConfirmed
            ? state.RemainingSeconds > 0 ? "Scheduled in " + (state.RemainingSeconds / 60) + " min " + (state.RemainingSeconds % 60) + " sec"
                : "Checking whether the restart can proceed"
            : "";
        RestartNotice.Text = !restartConfirmed && cancelling ? "Your cancellation could not be confirmed. Check the result before trying again. Closing Obli does not cancel a restart."
            : !restartConfirmed && answered ? "Your answer could not be confirmed. Check the result; Obli will not send another approval."
            : !restartConfirmed ? "The computer service could not confirm the restart state. Check again before deciding."
            : cancelling && actionable ? "Your cancellation was sent, but it is not confirmed. Check the result. Closing Obli does not cancel a restart."
            : answered && state.Status == "requested" ? "Your answer was sent, but it is not confirmed. Check the result; Obli will not send another approval."
            : actionable && expired ? "This schedule has expired. Ask IT to review a new time."
            : state.Status switch {
                "requested" => "Save your work. Allowing this request covers only the scheduled restart shown here. Your organisation requires your permission.",
                "countdown" when state.ConsentMode == "unattended" => "Your organisation has authorised this scheduled restart. Save your work. You can request cancellation before it starts.",
                "countdown" => "Save your work and keep this notice visible. You can cancel before the restart begins.",
                "awaiting_result" when state.MutationStarted => "The restart request is in progress. Its result is not confirmed yet.",
                "awaiting_result" => "Obli is checking the approved schedule. A restart has not yet been confirmed.",
                "awaiting_boot" => "Windows accepted the restart request. IT is waiting for this computer to check in after restarting.",
                "succeeded" => "Oblivion confirmed a later check-in from this computer after the requested restart.",
                "cancelled" => "The computer confirmed that this request was cancelled before the restart began.",
                "declined" => "You declined this restart. Ask IT to arrange another time if needed.",
                "expired" => "The deadline passed without a confirmed restart. IT can review the next step.",
                "uncertain" when !state.MutationStarted => "The restart authority could not be confirmed. IT can review this request before arranging another time.",
                "uncertain" => "The restart may have started. IT must check the computer before sending another request.",
                _ => RestartFailure(state.ReasonCode)
            };
        AllowRestartButton.Visibility = DeclineRestartButton.Visibility =
            state.Status == "requested" && !answered ? Visibility.Visible : Visibility.Collapsed;
        CancelRestartButton.Visibility = state.CanCancel && (!cancelling || restartConfirmed) && (state.Status == "countdown" || answered) ? Visibility.Visible : Visibility.Collapsed;
        bool ready = actionable && !expired && restartConfirmed && !restartBusy;
        AllowRestartButton.IsEnabled = DeclineRestartButton.IsEnabled = ready && state.Status == "requested" && !answered;
        CancelRestartButton.Content = cancelling ? "Retry cancellation" : "Cancel restart";
        CancelRestartButton.IsEnabled = ready && state.CanCancel;
    }
    static string RestartFailure(string reason) => reason switch {
        "consent_lost" => "The Windows session or visible consent changed. Ask IT to review a new schedule.",
        "warning_incomplete" => "There was not enough visible warning time. Ask IT to arrange another time.",
        "clock_changed" => "The computer's time changed. IT must check it before scheduling another restart.",
        "offline" => "The computer could not confirm permission with Oblivion at the scheduled time.",
        "policy_unavailable" => "The restart policy or permission is no longer available.",
        "restart_refused" => "Windows refused the restart request. IT can check the computer.",
        "boot_unavailable" => "The computer's startup information could not be verified.",
        _ => "The restart could not be confirmed. IT can check the computer and the request."
    };
    internal async Task DecideRestartAsync(string decision)
    {
        var state = restartState;
        if (state == null || restartBusy || !restartConfirmed || configuredOrigin == null ||
            new Uri(configuredOrigin) != state.Origin || state.ExpiresAt <= DateTimeOffset.UtcNow ||
            state.Status is not ("requested" or "countdown") ||
            decision is not ("allow" or "decline" or "cancel") ||
            ((decision is "allow" or "decline") && (state.Status != "requested" || restartAnswered == state.AttemptId)) ||
            (decision == "cancel" && !state.CanCancel)) return;
        if (decision == "cancel") restartCancelSent = state.AttemptId; else restartAnswered = state.AttemptId;
        long generation = ++restartGeneration;
        using var call = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        call.CancelAfter(TimeSpan.FromSeconds(6)); restartCall = call; restartBusy = true;
        RenderRestart();
        try
        {
            var next = await (AnswerRestart?.Invoke(state.Origin, state, decision, call.Token) ??
                client.AnswerRestartAsync(state.Origin, state, decision, call.Token));
            if (generation != restartGeneration || call.IsCancellationRequested) return;
            if (next.AttemptId != state.AttemptId || next.CommandId != state.CommandId || next.PayloadHash != state.PayloadHash)
                throw new InvalidOperationException("Restart context changed.");
            ApplyRestart(next);
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        {
            if (generation == restartGeneration) restartConfirmed = false;
        }
        finally
        {
            if (generation == restartGeneration) { restartBusy = false; restartCall = null; RenderRestart(); }
        }
    }
    internal void ClearRestart()
    {
        var previous = restartState;
        ++restartGeneration; restartCall?.Cancel(); restartCall = null; restartBusy = false;
        restartState = null; restartConfirmed = false; RenderRestart();
        if ((previous?.Status is "requested" or "countdown") && previous.ConsentToken.Length > 0)
            _ = HideRestartAsync(previous);
    }
    async Task HideRestartAsync(RestartState state)
    {
        using var deadline = new CancellationTokenSource(TimeSpan.FromSeconds(4));
        try
        {
            await (MarkRestartVisibility?.Invoke(state.Origin, state, "hidden", deadline.Token) ??
                client.MarkRestartVisibleAsync(state.Origin, state, "hidden", deadline.Token));
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        { /* The service separately expires visibility and current desktop consent. */ }
    }
    async void AllowRestartClick(object sender, RoutedEventArgs e) => await DecideRestartAsync("allow");
    async void DeclineRestartClick(object sender, RoutedEventArgs e) => await DecideRestartAsync("decline");
    async void CancelRestartClick(object sender, RoutedEventArgs e) => await DecideRestartAsync("cancel");
    async void CheckRestartClick(object sender, RoutedEventArgs e) => await RefreshRestartAsync();
}
