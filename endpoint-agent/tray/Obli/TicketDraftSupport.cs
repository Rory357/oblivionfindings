using System;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;

namespace Oblivion.Obli;

public partial class MainWindow
{
    CancellationTokenSource? ticketDraftOperation;
    long ticketDraftGeneration;
    bool reviewingTicket, submissionAttempted;
    // The production default always uses the SCM-verified local service.
    internal Func<string, Uri, CancellationToken, Task<bool>>? ConfirmTicketComputer;

    void ClearTicketDraftOperation()
    {
        ticketDraftGeneration++; ticketDraftOperation?.Cancel(); ticketDraftOperation = null;
        reviewingTicket = false; staffBusy = false;
    }
    bool CurrentDraft(long generation, StaffSession session) =>
        generation == ticketDraftGeneration && session == staffSession && !lifetime.IsCancellationRequested;
    async void ReviewTicketClick(object sender, RoutedEventArgs e) => await ReviewTicketAsync();
    void CancelTicketCheckClick(object sender, RoutedEventArgs e) => ticketDraftOperation?.Cancel();

    async Task ReviewTicketAsync()
    {
        if (staffSession == null || staffBusy || pendingTicket != null) return;
        var session = staffSession; var support = staffClient;
        string title = TicketTitle.Text.Trim(), description = TicketDescription.Text.Trim();
        if (SiteChoice.SelectedItem is not SupportSite site || title.Length == 0 || description.Length == 0)
        { TicketDraftNotice.Text = "Choose a Site and describe what you need help with."; return; }
        string category = (CategoryChoice.SelectedItem as ComboBoxItem)?.Tag?.ToString() ?? "other";
        var pending = new PendingTicket(Guid.NewGuid(), site.Id, title, description, category);
        try { ObliClient.ValidateTicketSubmission(pending.Id, site.Id, title, description, category); }
        catch (ArgumentException) { TicketDraftNotice.Text = "Check the summary and description, then review again."; return; }
        bool include = IncludeComputer.IsChecked == true && staffAccount?.ComputerContextEnabled == true;
        if (include && support == null) return;
        long generation = ++ticketDraftGeneration;
        using var operation = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        ticketDraftOperation = operation;
        TicketEditor.IsEnabled = false; staffBusy = true; reviewingTicket = include; RenderStaff();
        TicketComputerSummary? computer = null;
        try
        {
            if (include)
            {
                TicketDraftNotice.Text = "Checking this computer with Oblivion…";
                var context = await support!.MintTicketComputerContextAsync(session, pending.Id, site.Id, title, description, category, operation.Token);
                if (!CurrentDraft(generation, session)) return;
            operation.Token.ThrowIfCancellationRequested();
                if (context.Status != "confirmed")
                {
                    var confirm = ConfirmTicketComputer ?? client.ConfirmTicketComputerAsync;
                    // A lost local acknowledgement may still have reached Oblivion; the staff read is authoritative.
                    try { await confirm(context.Challenge!, session.Origin, operation.Token); }
                    catch (Exception error) when (error is not OutOfMemoryException && !operation.IsCancellationRequested) { }
                    if (!CurrentDraft(generation, session)) return;
                    operation.Token.ThrowIfCancellationRequested();
                    context = await support.TicketComputerContextAsync(session, pending.Id, operation.Token);
                }
                if (!CurrentDraft(generation, session)) return;
                operation.Token.ThrowIfCancellationRequested();
                if (context.RequestId != pending.Id || context.Status != "confirmed" || context.Computer == null ||
                    context.Computer.Site.Id != site.Id || context.ExpiresAt <= DateTimeOffset.UtcNow)
                { TicketDraftNotice.Text = "This computer could not be confirmed for the selected Site. Check the Site or untick Include this computer, then review again."; return; }
                computer = context.Computer;
                pending = pending with { ComputerContext = true, ComputerExpiresAt = context.ExpiresAt };
            }
            if (!CurrentDraft(generation, session)) return;
                operation.Token.ThrowIfCancellationRequested();
            pendingTicket = pending;
            ReviewSummary.Text = site.Name + "\n\n" + title + "\n\n" + description;
            ReviewComputer.Text = computer == null ? "" : "Computer attached\n" + computer.Name + " · " + computer.Site.Name;
            ReviewComputer.Visibility = computer == null ? Visibility.Collapsed : Visibility.Visible;
            ReviewPanel.Visibility = Visibility.Visible; TicketEditor.Visibility = Visibility.Collapsed;
            TicketDraftNotice.Text = "Check what you are sharing. Nothing is sent to IT until you choose Send to IT.";
            SubmitTicketButton.Focus();
        }
        catch (ObliAccessException) { if (CurrentDraft(generation, session)) ForgetStaff("Your staff access has changed. Sign in again."); }
        catch (OperationCanceledException) { if (CurrentDraft(generation, session)) TicketDraftNotice.Text = "Computer check cancelled or timed out. Your draft is still here."; }
        catch (Exception error) when (error is not OutOfMemoryException)
        { if (CurrentDraft(generation, session)) TicketDraftNotice.Text = "This computer could not be confirmed for the selected Site. Check the connection and Site, or untick Include this computer and review again."; }
        finally
        {
            if (CurrentDraft(generation, session))
            {
                ticketDraftOperation = null; reviewingTicket = false; staffBusy = false;
                TicketEditor.IsEnabled = pendingTicket == null; RenderStaff();
            }
        }
    }
    void EditTicketClick(object sender, RoutedEventArgs e)
    {
        if (staffBusy || submissionAttempted) return;
        pendingTicket = null; ReviewPanel.Visibility = Visibility.Collapsed;
        ReviewSummary.Text = ""; ReviewComputer.Text = ""; TicketDraftNotice.Text = "";
        TicketEditor.Visibility = Visibility.Visible; TicketEditor.IsEnabled = true; RenderStaff();
    }
    async void SubmitTicketClick(object sender, RoutedEventArgs e) => await SendTicketAsync(false);
    async void CheckTicketDeliveryClick(object sender, RoutedEventArgs e) => await SendTicketAsync(true);

    async Task SendTicketAsync(bool checkOnly)
    {
        if (staffBusy || pendingTicket == null || staffClient == null || staffSession == null || checkOnly && !submissionAttempted) return;
        var pending = pendingTicket; var support = staffClient; var session = staffSession;
        if (!submissionAttempted && pending.ComputerContext && pending.ComputerExpiresAt <= DateTimeOffset.UtcNow)
        { TicketDraftNotice.Text = "The computer check expired. Choose Edit, then review again before sending."; return; }
        long generation = ++ticketDraftGeneration;
        using var operation = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);
        ticketDraftOperation = operation; staffBusy = true; submissionAttempted = true; RenderStaff();
        TicketDraftNotice.Text = checkOnly ? "Checking whether IT received this request…" : "Sending your reviewed request…";
        try
        {
            var ticket = checkOnly
                ? await support.TicketRequestAsync(session, pending.Id, operation.Token)
                : await support.SubmitAsync(session, pending.Id, pending.Site, pending.Title, pending.Description, pending.Category, operation.Token, pending.ComputerContext);
            if (!CurrentDraft(generation, session)) return;
                operation.Token.ThrowIfCancellationRequested();
            if (ticket == null) { TicketDraftNotice.Text = "Delivery is still unconfirmed. Keep this request open and check again, or retry the same request."; return; }
            pendingTicket = null; submissionAttempted = false;
            TicketTitle.Clear(); TicketDescription.Clear(); IncludeComputer.IsChecked = false;
            TicketEditor.IsEnabled = true; TicketEditor.Visibility = Visibility.Visible;
            ReviewSummary.Text = ""; ReviewComputer.Text = ""; TicketDraftNotice.Text = "";
            ReviewPanel.Visibility = Visibility.Collapsed; NewRequestPanel.Visibility = Visibility.Collapsed; NewRequestButton.Content = "New request";
            StaffNotice.Text = "Sent to IT · " + ticket.Reference;
            ticketBefore = null; ticketPages.Clear();
            await LoadTicketsAsync();
        }
        catch (ObliAccessException) { if (CurrentDraft(generation, session)) ForgetStaff("Your staff access has changed. Sign in again."); }
        catch (ObliComputerContextUnavailableException)
        {
            if (CurrentDraft(generation, session))
                TicketDraftNotice.Text = "The computer link is no longer available. Delivery is unconfirmed; check delivery or contact IT before starting another request.";
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        {
            if (CurrentDraft(generation, session))
                TicketDraftNotice.Text = "Delivery is unconfirmed. Check delivery, or retry this same request without creating a duplicate. Keep this window open.";
        }
        finally
        {
            if (CurrentDraft(generation, session)) { ticketDraftOperation = null; staffBusy = false; RenderStaff(); }
        }
    }
}
