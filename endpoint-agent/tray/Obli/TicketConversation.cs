using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;

namespace Oblivion.Obli;

public partial class MainWindow
{
    readonly Stack<int?> ticketPages = new();
    readonly Stack<int?> replyPages = new();
    int? ticketBefore, nextTicketBefore, replyBefore;
    SupportTicketDetail? conversation;
    PendingReply? pendingReply;
    bool replyAttempted, ticketBusy;
    long ticketGeneration;
    CancellationTokenSource? ticketOperation;
    sealed record PendingReply(int TicketId, Guid RequestId, long Version, string Body) {
        public override string ToString() => "Private pending support reply";
    }

    static string TicketStatusLabel(string status) => status switch {
        "open" => "Received by IT", "in_progress" => "In progress",
        "waiting" => "Waiting for an update", "resolved" => "Resolved", "closed" => "Closed",
        _ => "Status unavailable"
    };
    static string ReplyTime(DateTimeOffset at) =>
        TimeZoneInfo.ConvertTime(at, TimeZoneInfo.FindSystemTimeZoneById("New Zealand Standard Time"))
            .ToString("d MMM yyyy, h:mm tt", CultureInfo.GetCultureInfo("en-NZ")) + " (NZ time)";

    void ClearTicketConversation()
    {
        ticketGeneration++; ticketOperation?.Cancel(); ticketOperation = null; ticketBusy = false;
        ticketPages.Clear(); replyPages.Clear(); ticketBefore = nextTicketBefore = replyBefore = null;
        conversation = null; pendingReply = null; replyAttempted = false;
        ReplyBody.Clear(); ReplyReviewText.Clear(); ReplyReviewHeading.Text = ""; ConversationRows.Items.Clear();
        ConversationReference.Text = ConversationTitle.Text = ConversationStatus.Text = ConversationNotice.Text = "";
        ConversationDescription.Clear(); TicketListNotice.Text = "";
        RenderTicketConversation();
    }
    void RenderTicketConversation()
    {
        if(TicketConversationPanel == null || DiscardReplyButton == null) return;
        bool signed = staffSession != null, active = signed && conversation != null;
        TicketOverview.Visibility = active ? Visibility.Collapsed : Visibility.Visible;
        TicketConversationPanel.Visibility = active ? Visibility.Visible : Visibility.Collapsed;
        OlderTicketsButton.Visibility = nextTicketBefore != null ? Visibility.Visible : Visibility.Collapsed;
        NewerTicketsButton.Visibility = ticketPages.Count > 0 ? Visibility.Visible : Visibility.Collapsed;
        OlderTicketsButton.IsEnabled = NewerTicketsButton.IsEnabled = signed && !ticketBusy && !staffBusy;
        BackToTicketsButton.IsEnabled = active && !ticketBusy && pendingReply == null && string.IsNullOrWhiteSpace(ReplyBody.Text);
        RefreshConversationButton.IsEnabled = active && !ticketBusy && pendingReply == null;
        OpenTicketBrowserButton.IsEnabled = active && !ticketBusy;
        OlderRepliesButton.Visibility = conversation?.NextBefore != null ? Visibility.Visible : Visibility.Collapsed;
        NewerRepliesButton.Visibility = replyPages.Count > 0 ? Visibility.Visible : Visibility.Collapsed;
        OlderRepliesButton.IsEnabled = NewerRepliesButton.IsEnabled = active && !ticketBusy && pendingReply == null;
        ReplyComposer.Visibility = active && (conversation!.CanReply || pendingReply != null || ReplyBody.Text.Length > 0) ? Visibility.Visible : Visibility.Collapsed;
        ReplyUnavailable.Visibility = active && !conversation!.CanReply && pendingReply == null ? Visibility.Visible : Visibility.Collapsed;
        ReplyBody.IsEnabled = active && !ticketBusy && pendingReply == null;
        DiscardReplyButton.IsEnabled = !ticketBusy && !replyAttempted && ReplyBody.Text.Length > 0;
        ReviewReplyButton.IsEnabled = active && !ticketBusy && pendingReply == null && conversation!.CanReply;
        ReplyReviewPanel.Visibility = pendingReply != null ? Visibility.Visible : Visibility.Collapsed;
        SendReplyButton.IsEnabled = CheckReplyButton.IsEnabled = active && !ticketBusy;
        SendReplyButton.Content = replyAttempted ? "Retry same reply" : "Send reply";
        EditReplyButton.IsEnabled = !ticketBusy && !replyAttempted;
        CheckReplyButton.Visibility = replyAttempted ? Visibility.Visible : Visibility.Collapsed;
        RefreshTicketsButton.IsEnabled = signed && !staffBusy && !ticketBusy;
        NewRequestButton.IsEnabled = signed && !staffBusy && !ticketBusy;
    }
    bool TicketResultCurrent(StaffSession session, long generation) =>
        staffSession == session && ticketGeneration == generation && !DesktopPrivacyLocked;

    async void RefreshTicketsClick(object sender, RoutedEventArgs e)
    {
        if(staffBusy || ticketBusy) return;
        ticketBefore = null; ticketPages.Clear(); await LoadTicketsAsync();
    }
    async void OlderTicketsClick(object sender, RoutedEventArgs e)
    {
        if(staffBusy || ticketBusy || nextTicketBefore == null) return;
        var previous = ticketBefore; ticketPages.Push(previous); ticketBefore = nextTicketBefore;
        await LoadTicketsAsync();
    }
    async void NewerTicketsClick(object sender, RoutedEventArgs e)
    {
        if(staffBusy || ticketBusy || ticketPages.Count == 0) return;
        ticketBefore = ticketPages.Pop(); await LoadTicketsAsync();
    }
    async Task LoadTicketsAsync()
    {
        var client = staffClient; var session = staffSession;
        if(client == null || session == null || ticketBusy) return;
        long generation = ++ticketGeneration; ticketBusy = true; RenderStaff();
        using var operation = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token); ticketOperation = operation;
        try
        {
            TicketListNotice.Text = "Loading your requests…";
            var page = await client.TicketPageAsync(session, ticketBefore, operation.Token);
            if(!TicketResultCurrent(session, generation)) return;
            TicketRows.Items.Clear(); nextTicketBefore = page.NextBefore;
            foreach(var ticket in page.Tickets)
            {
                var button = new Button {
                    Content = new TextBlock { Text = ticket.Reference + " · " + TicketStatusLabel(ticket.Status) + "\n" + ticket.Title },
                    HorizontalContentAlignment = HorizontalAlignment.Left, Margin = new Thickness(0,0,0,8)
                };
                button.Click += async (_,_) => { if(staffSession == session && !ticketBusy && !staffBusy) await OpenTicketAsync(ticket.Id); };
                TicketRows.Items.Add(button);
            }
            TicketListNotice.Text = page.Tickets.Count == 0 ? "No support requests on this page." :
                ticketBefore == null ? "Your latest support requests. Select one to read or reply." : "Earlier support requests.";
        }
        catch(ObliAccessException) { if(TicketResultCurrent(session,generation)) ForgetStaff("Your staff access changed. Sign in again."); }
        catch(Exception error) when(error is not OutOfMemoryException)
        {
            if(TicketResultCurrent(session,generation)) { TicketRows.Items.Clear(); nextTicketBefore = null; TicketListNotice.Text = "Your tickets could not be loaded. Refresh to try again."; }
        }
        finally { if(generation == ticketGeneration) { ticketBusy = false; ticketOperation = null; RenderStaff(); } }
    }
    async Task OpenTicketAsync(int id)
    {
        if(staffSession == null || ticketBusy || pendingReply != null || !string.IsNullOrWhiteSpace(ReplyBody.Text)) return;
        replyBefore = null; replyPages.Clear();
        await LoadConversationAsync(id, null);
        if(conversation != null) PageScroll.ScrollToTop();
    }
    async Task LoadConversationAsync(int id, int? before)
    {
        var client = staffClient; var session = staffSession;
        if(client == null || session == null || ticketBusy) return;
        long generation = ++ticketGeneration; ticketBusy = true; RenderStaff();
        using var operation = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token); ticketOperation = operation;
        try
        {
            if(conversation == null) StaffNotice.Text = "Opening your ticket…";
            else ConversationNotice.Text = "Loading the conversation…";
            var detail = await client.TicketAsync(session,id,before,operation.Token);
            if(!TicketResultCurrent(session,generation)) return;
            conversation = detail; replyBefore = before; ShowConversation(detail);
            ConversationNotice.Text = before == null ? "Public replies shared with IT." : "Earlier replies. Use Latest updates to return to the newest messages.";
            StaffNotice.Text = ""; RenderTicketConversation();
        }
        catch(ObliAccessException) { if(TicketResultCurrent(session,generation)) ForgetStaff("Your staff access changed. Sign in again."); }
        catch(ObliTicketUnavailableException) { if(TicketResultCurrent(session,generation)) HideUnavailableTicket(); }
        catch(Exception error) when(error is not OutOfMemoryException)
        {
            if(TicketResultCurrent(session,generation))
            {
                if(conversation == null) StaffNotice.Text = "This conversation could not be loaded. Try again when connected.";
                else { conversation = conversation with { CanReply = false }; ConversationNotice.Text = "Updates could not be loaded. Select Latest updates before replying."; }
            }
        }
        finally { if(generation == ticketGeneration) { ticketBusy = false; ticketOperation = null; RenderStaff(); } }
    }
    void ShowConversation(SupportTicketDetail detail)
    {
        ConversationReference.Text = detail.Ticket.Reference; ConversationTitle.Text = detail.Ticket.Title;
        ConversationStatus.Text = TicketStatusLabel(detail.Ticket.Status);
        ConversationDescription.Text = detail.Description;
        DescriptionShortened.Visibility = detail.DescriptionTruncated ? Visibility.Visible : Visibility.Collapsed;
        ConversationRows.Items.Clear();
        foreach(var comment in detail.Comments)
        {
            var content = new StackPanel { Margin = new Thickness(0,0,0,18) };
            content.Children.Add(new TextBlock { Text = comment.Author, FontWeight = FontWeights.SemiBold });
            content.Children.Add(new TextBlock { Text = ReplyTime(comment.CreatedAt), Style = (Style)FindResource("SecondaryText"), Margin = new Thickness(0,3,0,7) });
            content.Children.Add(new TextBox { Text = comment.Body, IsReadOnly = true, TextWrapping = TextWrapping.Wrap,
                VerticalScrollBarVisibility = ScrollBarVisibility.Auto, MaxHeight = 190,
                Background = System.Windows.Media.Brushes.Transparent, BorderThickness = new Thickness(0), Padding = new Thickness(0) });
            if(comment.BodyTruncated) content.Children.Add(new TextBlock { Text = "Shortened preview · read the full reply in Oblivion.", Style = (Style)FindResource("SecondaryText") });
            ConversationRows.Items.Add(content);
        }
        ConversationEmpty.Visibility = detail.Comments.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
    }
    void HideUnavailableTicket()
    {
        ClearTicketConversation(); TicketRows.Items.Clear();
        StaffNotice.Text = "This ticket is no longer available to your account. Refresh your requests.";
    }
    void BackToTicketsClick(object sender, RoutedEventArgs e)
    {
        if(ticketBusy || pendingReply != null || !string.IsNullOrWhiteSpace(ReplyBody.Text)) return;
        conversation = null; ConversationRows.Items.Clear(); ConversationDescription.Clear(); RenderTicketConversation(); PageScroll.ScrollToTop();
    }
    async void RefreshConversationClick(object sender,RoutedEventArgs e)
    {
        if(ticketBusy || pendingReply != null || conversation == null) return;
        await LoadConversationAsync(conversation.Ticket.Id,null);
        if(conversation != null && replyBefore == null) replyPages.Clear();
        RenderTicketConversation();
    }
    async void OlderRepliesClick(object sender,RoutedEventArgs e) => await LoadEarlierRepliesAsync();
    async Task LoadEarlierRepliesAsync()
    {
        if(ticketBusy || pendingReply != null || conversation?.NextBefore is not int before) return;
        var previous = replyBefore;
        await LoadConversationAsync(conversation.Ticket.Id,before);
        if(conversation != null && replyBefore == before) replyPages.Push(previous);
        RenderTicketConversation();
    }
    async void NewerRepliesClick(object sender,RoutedEventArgs e) => await LoadNewerRepliesAsync();
    async Task LoadNewerRepliesAsync()
    {
        if(ticketBusy || pendingReply != null || conversation == null || replyPages.Count == 0) return;
        var before = replyPages.Peek();
        await LoadConversationAsync(conversation.Ticket.Id,before);
        if(conversation != null && replyBefore == before && replyPages.Count > 0) replyPages.Pop();
        RenderTicketConversation();
    }
    void OpenTicketBrowserClick(object sender,RoutedEventArgs e)
    {
        var session = staffSession;
        if(session == null || session.ExpiresAt <= DateTimeOffset.UtcNow || conversation == null || ticketBusy) return;
        try { Process.Start(new ProcessStartInfo(new Uri(session.Origin,conversation.Ticket.Path).AbsoluteUri) { UseShellExecute = true }); }
        catch(Exception error) when(error is not OutOfMemoryException) { ConversationNotice.Text = "Oblivion could not open in your browser. Use your usual Oblivion shortcut."; }
    }
    void ReplyBodyChanged(object sender, TextChangedEventArgs e) => RenderTicketConversation();
    void DiscardReplyClick(object sender, RoutedEventArgs e)
    {
        if(ticketBusy || replyAttempted) return;
        pendingReply = null; ReplyBody.Clear(); ReplyReviewText.Clear(); ConversationNotice.Text = "Reply draft discarded.";
        RenderTicketConversation();
    }
    void ReviewReplyClick(object sender,RoutedEventArgs e)
    {
        if(ticketBusy || staffSession == null || conversation?.CanReply != true || pendingReply != null) return;
        string body = ReplyBody.Text.Trim();
        if(body.Length == 0) { ConversationNotice.Text = "Write your reply before reviewing it."; ReplyBody.Focus(); return; }
        Guid requestId = Guid.NewGuid();
        try { ObliClient.ValidateTicketReply(requestId,conversation.Version,body); }
        catch(Exception error) when(error is not OutOfMemoryException) {
            ConversationNotice.Text = "This draft needs attention. Use plain text, refresh the ticket and review again."; ReplyBody.Focus(); return;
        }
        pendingReply = new(conversation.Ticket.Id,requestId,conversation.Version,body); replyAttempted = false;
        ReplyReviewHeading.Text = "Reply to " + conversation.Ticket.Reference;
        ReplyReviewText.Text = body; ConversationNotice.Text = "Check your reply, then send it to IT.";
        RenderTicketConversation(); ReplyReviewPanel.BringIntoView(); SendReplyButton.Focus();
    }
    void EditReplyClick(object sender,RoutedEventArgs e)
    {
        if(ticketBusy || replyAttempted) return;
        pendingReply = null; ReplyReviewText.Clear(); RenderTicketConversation(); ReplyBody.Focus();
    }
    async void SendReplyClick(object sender,RoutedEventArgs e) => await SendReplyAsync(false);
    async void CheckReplyClick(object sender,RoutedEventArgs e) => await SendReplyAsync(true);
    async Task SendReplyAsync(bool recover)
    {
        var client = staffClient; var session = staffSession; var pending = pendingReply;
        if(client == null || session == null || pending == null || ticketBusy || (recover && !replyAttempted)) return;
        long generation = ++ticketGeneration; ticketBusy = true; replyAttempted = true;
        RenderStaff(); ConversationNotice.Text = recover ? "Checking whether IT received your reply…" : "Sending your reply…";
        using var operation = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token); ticketOperation = operation;
        bool confirmed = false;
        try
        {
            var receipt = recover ? await client.RecoverReplyAsync(session,pending.TicketId,pending.RequestId,operation.Token) :
                await client.ReplyAsync(session,pending.TicketId,pending.RequestId,pending.Version,pending.Body,operation.Token);
            if(!TicketResultCurrent(session,generation)) return;
            if(receipt.TicketId != pending.TicketId || receipt.RequestId != pending.RequestId || receipt.CommittedVersion <= pending.Version ||
                receipt.Comment.BodyTruncated || receipt.Comment.Body != pending.Body.Trim(' ', '\t', '\r', '\n', '\0', '\v'))
                throw new InvalidDataException("Reply receipt does not match the reviewed message.");
            pendingReply = null; replyAttempted = false; ReplyBody.Clear(); ReplyReviewText.Clear(); replyPages.Clear();
            ConversationNotice.Text = "Reply received by IT."; confirmed = true;
        }
        catch(ObliAccessException) { if(TicketResultCurrent(session,generation)) ForgetStaff("Your staff access changed. Sign in again."); }
        catch(ObliTicketUnavailableException) { if(TicketResultCurrent(session,generation)) HideUnavailableTicket(); }
        catch(ObliTicketRejectedException error)
        {
            if(TicketResultCurrent(session,generation))
            {
                pendingReply = null; replyAttempted = false; ReplyReviewText.Clear();
                if(conversation != null) conversation = conversation with { CanReply = false };
                ConversationNotice.Text = error.Message + " Select Latest updates; your draft is still here.";
            }
        }
        catch(Exception error) when(error is not OutOfMemoryException)
        {
            if(TicketResultCurrent(session,generation)) ConversationNotice.Text =
                "Delivery is not confirmed. Check delivery or retry this same reply. Keep this window open; editing could create a duplicate.";
        }
        finally { if(generation == ticketGeneration) { ticketBusy = false; ticketOperation = null; RenderStaff(); } }
        if(confirmed && staffSession == session)
        {
            await LoadConversationAsync(pending.TicketId,null);
            if(staffSession == session && conversation?.Ticket.Id == pending.TicketId)
            {
                ConversationNotice.Text = "Reply received by IT." + (conversation.CanReply ? "" : " Refresh the latest updates before replying again.");
                PageScroll.ScrollToTop();
            }
        }
    }
}
