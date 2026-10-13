using System;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Threading;

namespace Oblivion.Obli;

public partial class MainWindow
{
    readonly DispatcherTimer serviceActionTimer = new() { Interval = TimeSpan.FromSeconds(2) };
    ServiceActionState? serviceActionState;
    CancellationTokenSource? serviceActionCall;
    Guid? serviceActionAnswered, serviceActionShown;
    long serviceActionGeneration;
    bool serviceActionBusy, serviceActionConfirmed;
    internal Func<Uri, CancellationToken, Task<ServiceActionState>>? FetchServiceAction;
    internal Func<Uri, ServiceActionState, string, CancellationToken, Task<ServiceActionState>>? AnswerServiceAction;

    void InitialiseServiceActions()
    {
        serviceActionTimer.Tick += async (_, _) => {
            if (serviceActionState?.Status == "requested" && serviceActionState.ExpiresAt <= DateTimeOffset.UtcNow) RenderServiceAction();
            await RefreshServiceActionAsync();
        };
        StateChanged += (_, _) => { if (WindowState == WindowState.Minimized) ClearServiceAction(); };
        Closed += (_, _) => { serviceActionTimer.Stop(); ClearServiceAction(); };
    }

    internal async Task RefreshServiceActionAsync()
    {
        if (serviceActionBusy || configuredOrigin == null || lifetime.IsCancellationRequested) return;
        string configured = configuredOrigin;
        var origin = new Uri(configured);
        long generation = serviceActionGeneration;
        using var call = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        call.CancelAfter(TimeSpan.FromSeconds(6));
        serviceActionCall = call; serviceActionBusy = true;
        bool received = false;
        try
        {
            var state = await (FetchServiceAction?.Invoke(origin, call.Token) ?? client.GetServiceActionAsync(origin, call.Token));
            if (generation != serviceActionGeneration || configuredOrigin != configured || call.IsCancellationRequested) return;
            ApplyServiceAction(state);
            received = true;
            if (state.Status == "requested" && serviceActionShown != state.AttemptId)
            {
                serviceActionShown = state.AttemptId;
                ShowFromTray("device");
                ServiceActionPanel.BringIntoView();
            }
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        {
            if (generation == serviceActionGeneration && !call.IsCancellationRequested && serviceActionState != null)
                ServiceActionNotice.Text = "The computer service could not confirm this request. Check again before deciding.";
            if (generation == serviceActionGeneration) DisableServiceActionDecision();
        }
        finally
        {
            if (generation == serviceActionGeneration)
            {
                serviceActionBusy = false; serviceActionCall = null;
                CheckServiceActionButton.IsEnabled = true;
                if (received) RenderServiceAction();
            }
        }
    }

    internal void ApplyServiceAction(ServiceActionState state)
    {
        serviceActionConfirmed = true;
        serviceActionState = state.Status == "idle" ? null : state;
        RenderServiceAction();
    }

    void RenderServiceAction()
    {
        var state = serviceActionState;
        ServiceActionPanel.Visibility = state == null ? Visibility.Collapsed : Visibility.Visible;
        if (state == null)
        {
            ServiceActionPerson.Text = ServiceActionPurpose.Text = ServiceActionTarget.Text = ServiceActionNotice.Text = "";
            DisableServiceActionDecision();
            return;
        }
        bool requested = state.Status == "requested";
        bool expired = state.ExpiresAt <= DateTimeOffset.UtcNow;
        bool decided = state.AttemptId == serviceActionAnswered;
        ServiceActionTitle.Text = state.Status switch {
            "requested" => expired ? "This action request has expired" : decided ? "Checking your answer" : "Allow this computer action?",
            "awaiting_result" => "Computer action in progress",
            "succeeded" => "Computer action reported complete",
            "failed" => "Computer action could not finish",
            "uncertain" => "The action result needs checking",
            "declined" => "Computer action declined",
            "expired" => "This action request has expired",
            _ => "Computer action"
        };
        string verb = state.Operation switch { "start" => "Start", "stop" => "Stop", "restart" => "Restart", _ => "Review" };
        ServiceActionTarget.Text = verb + " service: " + state.ServiceName;
        ServiceActionPerson.Text = state.Technician + " · IT support";
        ServiceActionPurpose.Text = state.Purpose;
        ServiceActionNotice.Text = state.Status switch {
            "requested" when expired => "Ask IT to review and send a new request if the action is still needed.",
            "requested" when decided => "Your answer was sent, but its result is not confirmed. Check the result; this window will not send another approval.",
            "requested" => "This may interrupt apps that use this service. Save your work before allowing it. This permission covers only the action shown here.",
            "awaiting_result" => "Your approval was received. Once an action starts, closing Obli does not undo it.",
            "succeeded" => "The computer reported the action complete. IT will check the final service state.",
            "uncertain" => "The action may have started. IT must check the computer before trying it again.",
            "declined" => "You declined this request. IT can review the next step.",
            "expired" => "The permission window closed. IT can check the result and decide the next step.",
            _ => state.Reason
        };
        bool canDecide = requested && !expired && !decided && !serviceActionBusy && serviceActionConfirmed;
        AllowServiceActionButton.Visibility = DeclineServiceActionButton.Visibility = requested && !decided ? Visibility.Visible : Visibility.Collapsed;
        AllowServiceActionButton.IsEnabled = DeclineServiceActionButton.IsEnabled = canDecide;
        AllowServiceActionButton.Content = "Allow " + state.Operation;
        CheckServiceActionButton.IsEnabled = !serviceActionBusy;
    }

    void DisableServiceActionDecision()
    {
        serviceActionConfirmed = false;
        AllowServiceActionButton.IsEnabled = DeclineServiceActionButton.IsEnabled = false;
    }

    internal async Task DecideServiceActionAsync(string decision)
    {
        var state = serviceActionState;
        if (state == null || state.Status != "requested" || !serviceActionConfirmed || serviceActionBusy || state.AttemptId == serviceActionAnswered ||
            state.ExpiresAt <= DateTimeOffset.UtcNow || configuredOrigin == null ||
            new Uri(configuredOrigin) != state.Origin || decision is not ("allow" or "decline")) return;
        // Once sent, an approval is never repeated by this window. Reads recover its outcome.
        serviceActionAnswered = state.AttemptId;
        long generation = ++serviceActionGeneration;
        using var call = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        call.CancelAfter(TimeSpan.FromSeconds(6));
        serviceActionCall = call; serviceActionBusy = true;
        DisableServiceActionDecision();
        ServiceActionNotice.Text = decision == "allow" ? "Sending your approval…" : "Declining this action…";
        try
        {
            var next = await (AnswerServiceAction?.Invoke(state.Origin, state, decision, call.Token) ??
                client.AnswerServiceActionAsync(state.Origin, state, decision, call.Token));
            if (generation != serviceActionGeneration || call.IsCancellationRequested) return;
            if (next.AttemptId != state.AttemptId || next.CommandId != state.CommandId || next.PayloadHash != state.PayloadHash)
                throw new InvalidOperationException("Action context changed.");
            ApplyServiceAction(next);
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        {
            if (generation == serviceActionGeneration)
                ServiceActionNotice.Text = "Your answer could not be confirmed. Check the result before asking IT to try again.";
        }
        finally
        {
            if (generation == serviceActionGeneration)
            {
                serviceActionBusy = false; serviceActionCall = null;
                AllowServiceActionButton.Visibility = DeclineServiceActionButton.Visibility = Visibility.Collapsed;
                CheckServiceActionButton.IsEnabled = true;
            }
        }
    }

    async void AllowServiceActionClick(object sender, RoutedEventArgs e) => await DecideServiceActionAsync("allow");
    async void DeclineServiceActionClick(object sender, RoutedEventArgs e) => await DecideServiceActionAsync("decline");
    async void CheckServiceActionClick(object sender, RoutedEventArgs e) => await RefreshServiceActionAsync();

    internal void ClearServiceAction()
    {
        var previous = serviceActionState;
        ++serviceActionGeneration; serviceActionCall?.Cancel(); serviceActionCall = null; serviceActionBusy = false;
        serviceActionState = null; RenderServiceAction();
        // A pending prompt may be declined. An approval already sent cannot be represented as undone.
        if (previous?.Status == "requested" && previous.AttemptId != serviceActionAnswered && previous.ExpiresAt > DateTimeOffset.UtcNow)
        {
            serviceActionAnswered = previous.AttemptId;
            _ = DeclineHiddenServiceActionAsync(previous);
        }
    }

    async Task DeclineHiddenServiceActionAsync(ServiceActionState state)
    {
        using var deadline = new CancellationTokenSource(TimeSpan.FromSeconds(4));
        try
        {
            await (AnswerServiceAction?.Invoke(state.Origin, state, "decline", deadline.Token) ??
                client.AnswerServiceActionAsync(state.Origin, state, "decline", deadline.Token));
        }
        catch (Exception error) when (error is not OutOfMemoryException) { /* Service enforces desktop liveness and expiry independently. */ }
    }
}
