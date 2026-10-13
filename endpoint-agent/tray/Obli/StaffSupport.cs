using System;
using System.Diagnostics;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using Microsoft.Win32;

namespace Oblivion.Obli;

public partial class MainWindow
{
    ObliClient? staffClient;
    StaffSession? staffSession;
    StaffAccount? staffAccount;
    CancellationTokenSource? signInAttempt;
    string? configuredOrigin;
    bool staffBusy;
    PendingTicket? pendingTicket;
    sealed record PendingTicket(Guid Id, int Site, string Title, string Description, string Category, bool ComputerContext = false, DateTimeOffset? ComputerExpiresAt = null)
    { public override string ToString() => "Private reviewed support request"; }

    void InitialiseStaffSupport()
    {
        SystemEvents.SessionSwitch += SessionChanged;
        Closed += (_, _) => {
            SystemEvents.SessionSwitch -= SessionChanged;
            signInAttempt?.Cancel();
            ticketOperation?.Cancel();
            ticketDraftOperation?.Cancel();
            staffClient?.Dispose();
        };
        RenderStaff();
    }
    void SessionChanged(object sender, SessionSwitchEventArgs e)
    {
        if (e.Reason is SessionSwitchReason.SessionLock or SessionSwitchReason.SessionLogoff)
            Dispatcher.BeginInvoke(() => {CancelRemoteLocally();ClearServiceAction();ClearRestart();ClearSoftware();ForgetStaff("Signed out when this Windows session was locked.");});
    }
    internal void ServiceChanged(AgentStatus? status)
    {
        string? origin = status is { Connection: "connected", LastAcknowledgedAt: not null } &&
            DateTimeOffset.UtcNow - status.LastAcknowledgedAt.Value <= TimeSpan.FromMinutes(6) ? status.ServerOrigin : null;
        if (configuredOrigin != origin)
        {
            CancelRemoteLocally();
            ClearServiceAction();ClearRestart();ClearSoftware();
            configuredOrigin = origin;
            signInAttempt?.Cancel();
            if (staffSession != null) ForgetStaff("The computer connection changed. Sign in again when it is available.");
        }
        if (staffSession?.ExpiresAt <= DateTimeOffset.UtcNow) ForgetStaff("Your sign-in has expired. Sign in again to continue.");
        RenderStaff();
    }
    async void SignInClick(object sender, RoutedEventArgs e)
    {
        if (DesktopPrivacyLocked) return;
        if (staffSession != null) { ForgetStaff("Signed out. Your private chat and ticket draft have been cleared."); return; }
        if (staffBusy || configuredOrigin == null) return;
        string origin = configuredOrigin;
        Navigate("tickets");
        staffBusy = true;
        using var attempt = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        signInAttempt = attempt;
        var client = new ObliClient(origin);
        StaffNotice.Text = "Continue in your browser and check the account shown there.";
        CancelSignIn.Visibility = Visibility.Visible;
        RenderStaff();
        try
        {
            var session = await BrowserSignIn.RunAsync(client, attempt.Token);
            var account = await client.AccountAsync(session, attempt.Token);
            if (configuredOrigin != origin || attempt.IsCancellationRequested) throw new OperationCanceledException();
            staffClient = client; staffSession = session; staffAccount = account;
            SiteChoice.ItemsSource = account.Sites; SiteChoice.SelectedIndex = 0;
            StaffNotice.Text = "Signed in as " + account.Name + ". Only your own support requests appear here.";
            Navigate("tickets");
            await LoadTicketsAsync();
        }
        catch (OperationCanceledException) { StaffNotice.Text = "Sign-in cancelled or timed out."; client.Dispose(); }
        catch (Exception error) when (error is not OutOfMemoryException)
        { StaffNotice.Text = "Sign-in could not be completed. Check your connection and staff access, then try again."; client.Dispose(); }
        finally { signInAttempt = null; staffBusy = false; CancelSignIn.Visibility = Visibility.Collapsed; RenderStaff(); }
    }
    void CancelSignInClick(object sender, RoutedEventArgs e) => signInAttempt?.Cancel();
    void ForgetStaff(string notice)
    {
        ClearServiceAction();ClearRestart();ClearSoftware();
        var client = staffClient; var session = staffSession;
        signInAttempt?.Cancel();
        staffClient = null; staffSession = null; staffAccount = null; pendingTicket = null;
        ClearPrivateChat();
        ClearTicketConversation();
        ClearTicketDraftOperation();
        submissionAttempted = false; EditTicketButton.IsEnabled = true;
        NewRequestPanel.Visibility = Visibility.Collapsed; NewRequestButton.Content = "New request";
        TicketTitle.Clear(); TicketDescription.Clear(); SiteChoice.ItemsSource = null;
        TicketRows.Items.Clear(); ReviewPanel.Visibility = Visibility.Collapsed; TicketEditor.IsEnabled = true;
        ReviewSummary.Text = ""; ReviewComputer.Text = ""; TicketDraftNotice.Text = ""; IncludeComputer.IsChecked = false;
        TicketEditor.Visibility = Visibility.Visible; StaffNotice.Text = notice;
        RenderStaff();
        if (client != null && session != null) _ = RevokeAndDisposeAsync(client, session);
        else client?.Dispose();
    }
    static async Task RevokeAndDisposeAsync(ObliClient client, StaffSession session)
    {
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5));
        try { await client.LogoutAsync(session, timeout.Token); }
        catch (Exception error) when (error is not OutOfMemoryException) { /* Local sign-out must succeed offline. Server token has a bounded expiry. */ }
        finally { client.Dispose(); }
    }
    void RenderStaff()
    {
        RenderChat();
        RenderTicketConversation();
        bool signedIn = staffSession != null;
        SignInButton.Content = signedIn ? "Sign out" : staffBusy ? "Signing in…" : "Sign in";
        SignInButton.IsEnabled = !DesktopPrivacyLocked && (signedIn || !staffBusy && configuredOrigin != null);
        StaffTools.Visibility = signedIn ? Visibility.Visible : Visibility.Collapsed;
        ReviewTicketButton.IsEnabled = signedIn && !staffBusy && pendingTicket == null;
        SubmitTicketButton.IsEnabled = signedIn && !staffBusy && pendingTicket != null;
        SubmitTicketButton.Content = submissionAttempted ? "Retry same request" : "Send to IT";
        CheckTicketDeliveryButton.Visibility = submissionAttempted ? Visibility.Visible : Visibility.Collapsed;
        CheckTicketDeliveryButton.IsEnabled = signedIn && !staffBusy;
        EditTicketButton.IsEnabled = !staffBusy && !submissionAttempted;
        ReviewTicketButton.Visibility = pendingTicket == null ? Visibility.Visible : Visibility.Collapsed;
        CancelTicketCheckButton.Visibility = reviewingTicket ? Visibility.Visible : Visibility.Collapsed;
        ComputerChoice.Visibility = staffAccount?.ComputerContextEnabled == true ? Visibility.Visible : Visibility.Collapsed;
        RefreshTicketsButton.IsEnabled = signedIn && !staffBusy && !ticketBusy;
    }
    void NewRequestClick(object sender, RoutedEventArgs e)
    {
        if (staffSession == null || staffBusy) return;
        NewRequestPanel.Visibility = NewRequestPanel.Visibility == Visibility.Visible ? Visibility.Collapsed : Visibility.Visible;
        NewRequestButton.Content = NewRequestPanel.Visibility == Visibility.Visible ? "Hide draft" : "New request";
        if (NewRequestPanel.Visibility == Visibility.Visible) TicketTitle.Focus();
    }
}
