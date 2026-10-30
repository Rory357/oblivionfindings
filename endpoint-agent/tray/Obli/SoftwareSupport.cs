using System;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Threading;

namespace Oblivion.Obli;

public partial class MainWindow
{
    readonly DispatcherTimer softwareTimer = new() { Interval = TimeSpan.FromSeconds(2) };
    SoftwareState? softwareState;
    CancellationTokenSource? softwareCall;
    Guid? softwareAnswered, softwareShown;
    long softwareGeneration;
    bool softwareBusy, softwareConfirmed;
    internal Func<Uri, CancellationToken, Task<SoftwareState>>? FetchSoftware;
    internal Func<Uri, SoftwareState, string, CancellationToken, Task<SoftwareState>>? AnswerSoftware;

    void InitialiseSoftwareSupport()
    {
        softwareTimer.Tick += async (_, _) => { RenderSoftware(); await RefreshSoftwareAsync(); };
        StateChanged += (_, _) => { if (WindowState == WindowState.Minimized) ClearSoftware(); };
        IsVisibleChanged += (_, _) => { if (!IsVisible) ClearSoftware(); };
        Closed += (_, _) => { softwareTimer.Stop(); ClearSoftware(); };
    }

    internal async Task RefreshSoftwareAsync()
    {
        if (softwareBusy || configuredOrigin == null || DesktopPrivacyLocked || lifetime.IsCancellationRequested) return;
        string configured = configuredOrigin;
        var origin = new Uri(configured);
        long generation = softwareGeneration;
        using var call = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        call.CancelAfter(TimeSpan.FromSeconds(6));
        softwareCall = call; softwareBusy = true;
        try
        {
            var state = await (FetchSoftware?.Invoke(origin, call.Token) ?? client.GetSoftwareAsync(origin, call.Token));
            if (generation != softwareGeneration || configuredOrigin != configured || call.IsCancellationRequested) return;
            ApplySoftware(state);
            if (state.Status == "requested" && softwareShown != state.AttemptId)
            {
                softwareShown = state.AttemptId;
                ShowFromTray("device"); SoftwarePanel.BringIntoView();
            }
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        {
            if (generation == softwareGeneration) softwareConfirmed = false;
        }
        finally
        {
            if (generation == softwareGeneration) { softwareBusy = false; softwareCall = null; RenderSoftware(); }
        }
    }

    internal void ApplySoftware(SoftwareState state)
    {
        softwareState = state.Status == "idle" ? null : state;
        softwareConfirmed = true;
        RenderSoftware();
    }

    void RenderSoftware()
    {
        var state = softwareState;
        SoftwarePanel.Visibility = state == null ? Visibility.Collapsed : Visibility.Visible;
        AllowSoftwareButton.IsEnabled = DeclineSoftwareButton.IsEnabled = false;
        CheckSoftwareButton.IsEnabled = !softwareBusy;
        if (state == null)
        {
            SoftwarePerson.Text = SoftwarePurpose.Text = SoftwareTarget.Text = SoftwarePublisher.Text = SoftwareNotice.Text = "";
            return;
        }
        bool requested = state.Status == "requested", expired = state.ExpiresAt <= DateTimeOffset.UtcNow,
            answered = state.AttemptId == softwareAnswered;
        SoftwareTitle.Text = state.Status switch {
            "staging" => "Preparing approved software",
            "requested" when expired => "This installation request has expired",
            "requested" when answered => "Checking your answer",
            "requested" => "Allow this software installation?",
            "installing" when !state.MutationStarted => "Checking installation permission",
            "installing" => "Software installation in progress",
            "awaiting_observation" => "Checking installed software",
            "succeeded" when state.RebootRequired => "Software installed · restart needed",
            "succeeded" => "Software installation confirmed",
            "declined" => "Software installation declined",
            "expired" => "This installation request has expired",
            "uncertain" => "The installation result needs checking",
            _ => "Software could not be installed"
        };
        SoftwareTarget.Text = state.PackageName + " · " + state.ProductVersion;
        SoftwarePublisher.Text = state.Publisher;
        SoftwarePerson.Text = state.Technician + " · IT support";
        SoftwarePurpose.Text = state.Purpose;
        SoftwareNotice.Text = !softwareConfirmed && answered
            ? "Your answer could not be confirmed. Check the result; Obli will not send another approval."
            : !softwareConfirmed ? "The computer service could not confirm this request. Check again before deciding."
            : requested && answered ? "Your answer was sent, but is not confirmed. Check the result; Obli will not send another approval."
            : requested && expired ? "Ask IT to review a new installation request if it is still needed."
            : state.Status switch {
                "staging" => "The computer is downloading and checking the approved installer. Installation has not started.",
                "requested" => "Save your work. This installer may close or change applications. Allow covers only the software shown here. Once allowed, closing Obli does not cancel this installation.",
                "installing" when !state.MutationStarted => "Your approval was received. The computer is checking permission before installation. Closing Obli does not cancel this request.",
                "installing" => "The installation is in progress. Closing Obli does not cancel it. IT will check the final result.",
                "awaiting_observation" when state.RebootRequired => "The installer requested a restart. IT is checking the installed software and will arrange any restart separately.",
                "awaiting_observation" => "The installer has finished. Oblivion is waiting for an independent check of the installed software.",
                "succeeded" when state.RebootRequired => "Oblivion verified the installed version. A restart is still needed to finish applying changes. IT will arrange that separately.",
                "succeeded" => "Oblivion verified this software version on the computer.",
                "declined" => "You declined this installation. IT can review the next step.",
                "expired" => "The approval window closed. IT can check the result before sending another request.",
                "uncertain" when state.MutationStarted => "The installer may have changed this computer. IT must check its state before trying again.",
                "uncertain" => "The installation authority could not be confirmed. IT can review the request.",
                _ => SoftwareFailure(state.ReasonCode)
            };
        AllowSoftwareButton.Visibility = DeclineSoftwareButton.Visibility = requested && !answered ? Visibility.Visible : Visibility.Collapsed;
        bool canDecide = requested && !expired && !answered && state.CanDecline && softwareConfirmed && !softwareBusy;
        AllowSoftwareButton.IsEnabled = DeclineSoftwareButton.IsEnabled = canDecide;
    }

    static string SoftwareFailure(string reason) => reason switch {
        "installer_busy" => "Windows is processing another installation. IT can check it before arranging this one.",
        "already_installed" => "This version is already installed. No new installation was started.",
        "downgrade_denied" => "A newer version is already installed. IT must review the package.",
        "signature_invalid" or "artifact_invalid" or "product_mismatch" => "The installer did not match the approved package. IT must check it before use.",
        "architecture_mismatch" => "This package does not match this computer. IT can choose a compatible version.",
        "artifact_unavailable" => "The approved installer could not be downloaded. IT can check the connection and package.",
        "consent_lost" => "The Windows session changed before installation. IT can review a new request.",
        "policy_unavailable" => "Permission or software policy changed before installation. IT can review the request.",
        _ => "The installation could not be confirmed. IT can check the computer and request."
    };

    internal async Task DecideSoftwareAsync(string decision)
    {
        var state = softwareState;
        if (state == null || state.Status != "requested" || !state.CanDecline || !softwareConfirmed || softwareBusy ||
            state.AttemptId == softwareAnswered || state.ExpiresAt <= DateTimeOffset.UtcNow || configuredOrigin == null ||
            new Uri(configuredOrigin) != state.Origin || DesktopPrivacyLocked || decision is not ("allow" or "decline")) return;
        softwareAnswered = state.AttemptId;
        long generation = ++softwareGeneration;
        using var call = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        call.CancelAfter(TimeSpan.FromSeconds(6));
        softwareCall = call; softwareBusy = true; softwareConfirmed = false;
        RenderSoftware();
        try
        {
            var next = await (AnswerSoftware?.Invoke(state.Origin, state, decision, call.Token) ??
                client.AnswerSoftwareAsync(state.Origin, state, decision, call.Token));
            if (generation != softwareGeneration || call.IsCancellationRequested || configuredOrigin == null ||
                new Uri(configuredOrigin) != state.Origin) return;
            if (next.AttemptId != state.AttemptId || next.CommandId != state.CommandId || next.PayloadHash != state.PayloadHash)
                throw new InvalidOperationException("Software request changed.");
            ApplySoftware(next);
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        {
            if (generation == softwareGeneration) softwareConfirmed = false;
        }
        finally
        {
            if (generation == softwareGeneration) { softwareBusy = false; softwareCall = null; RenderSoftware(); }
        }
    }

    async void AllowSoftwareClick(object sender, RoutedEventArgs e) => await DecideSoftwareAsync("allow");
    async void DeclineSoftwareClick(object sender, RoutedEventArgs e) => await DecideSoftwareAsync("decline");
    async void CheckSoftwareClick(object sender, RoutedEventArgs e) => await RefreshSoftwareAsync();

    internal void ClearSoftware()
    {
        var previous = softwareState;
        ++softwareGeneration; softwareCall?.Cancel(); softwareCall = null; softwareBusy = false;
        softwareState = null; softwareConfirmed = false; RenderSoftware();
        // An already-sent approval is never represented as cancelled by closing the helper.
        if (previous?.Status == "requested" && previous.AttemptId != softwareAnswered && previous.ExpiresAt > DateTimeOffset.UtcNow)
        {
            softwareAnswered = previous.AttemptId;
            _ = DeclineHiddenSoftwareAsync(previous);
        }
    }

    async Task DeclineHiddenSoftwareAsync(SoftwareState state)
    {
        using var deadline = new CancellationTokenSource(TimeSpan.FromSeconds(4));
        try { await (AnswerSoftware?.Invoke(state.Origin, state, "decline", deadline.Token) ??
            client.AnswerSoftwareAsync(state.Origin, state, "decline", deadline.Token)); }
        catch (Exception error) when (error is not OutOfMemoryException) { /* Current desktop and expiry remain service enforced. */ }
    }
}
