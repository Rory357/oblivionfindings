using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace Oblivion.Obli;
public partial class MainWindow
{
    readonly List<ChatMessage> chatMessages = new();
    ChatOptions? chatOptions;
    Guid? pendingChat;
    CancellationTokenSource? chatAttempt;
    bool chatBusy;

    void ClearPrivateChat()
    {
        chatAttempt?.Cancel();
        ClearDiagnostics(); ClearKnowledge();
        chatMessages.Clear(); pendingChat = null; chatOptions = null;
        ChatInput.Clear(); ChatRows.Items.Clear(); ChatConsent.IsChecked = false;
        ChatDisclosure.Text = ""; ChatNotice.Text = "Sign in to use private AI chat. The quick guides below work without signing in.";
        RenderChat();
    }
    void RenderChat()
    {
        RenderDiagnostics(); RenderKnowledge();
        bool signedIn = staffSession != null;
        LoadChatButton.Visibility = signedIn ? Visibility.Visible : Visibility.Collapsed;
        LoadChatButton.Content = chatOptions == null ? "Load chat and guides" : "Refresh chat and guides";
        LoadChatButton.IsEnabled = signedIn && !chatBusy;
        ChatTools.Visibility = signedIn && chatOptions is { Enabled: true } ? Visibility.Visible : Visibility.Collapsed;
        SendChatButton.IsEnabled = signedIn && !chatBusy && pendingChat == null && ChatConsent.IsChecked == true;
        ChatInput.IsEnabled = !chatBusy && pendingChat == null;
        CheckChatButton.Visibility = pendingChat != null ? Visibility.Visible : Visibility.Collapsed;
        CheckChatButton.IsEnabled = signedIn && !chatBusy;
        NewChatButton.IsEnabled = !chatBusy;
        StopChatButton.Visibility = chatBusy ? Visibility.Visible : Visibility.Collapsed;
    }
    void ChatConsentChanged(object sender, RoutedEventArgs e)
    {
        if (SendChatButton != null) RenderChat();
    }
    async void LoadChatClick(object sender, RoutedEventArgs e) => await LoadChatOptionsAsync();
    async Task LoadChatOptionsAsync()
    {
        var client = staffClient; var session = staffSession;
        if (client == null || session == null || chatBusy) return;
        chatBusy = true; RenderChat();
        using var attempt = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token); chatAttempt = attempt;
        try
        {
            var options = await client.ChatOptionsAsync(session, attempt.Token);
            if (staffSession != session || attempt.IsCancellationRequested) return;
            ApplyChatOptions(options);
        }
        catch (ObliAccessException) { if (staffSession == session) ForgetStaff("Your staff access changed. Sign in again."); }
        catch (Exception error) when (error is not OutOfMemoryException)
        { if (staffSession == session) ChatNotice.Text = "AI settings could not be loaded. Try again when connected."; }
        finally { chatAttempt = null; chatBusy = false; RenderChat(); }
    }
    void ApplyChatOptions(ChatOptions options)
    {
        if (chatOptions != null && chatOptions != options && chatMessages.Any(m => m.Knowledge != null))
        { chatMessages.Clear(); ChatRows.Items.Clear(); pendingChat=null; }
        chatOptions = options; ChatConsent.IsChecked = false;
        ClearDiagnostics(); ClearKnowledge();
        ChatDisclosure.Text = "Send the conversation shown here to " + options.ProviderName + " (" + options.Model + ") through Oblivion. " +
            "No tickets, files or screenshots are added. Only computer checks and guide excerpts you preview and select can be attached. Your replies are stored encrypted for " + options.RetentionDays +
            " days for recovery. Your provider has its own retention policy.";
        ChatNotice.Text = options.Enabled ? "Obli can explain a fix. It cannot change your computer. Check its advice before acting." : "Your organisation has switched AI chat off.";
        RenderChat();
    }
    async void SendChatClick(object sender, RoutedEventArgs e) => await SendChatAsync();
    async Task SendChatAsync()
    {
        var client = staffClient; var session = staffSession; var options = chatOptions;
        if (client == null || session == null || options == null || !options.Enabled || chatBusy || pendingChat != null || ChatConsent.IsChecked != true) return;
        if(selectedDiagnostics!=null && !selectedDiagnostics.IsFresh(DateTimeOffset.UtcNow)){ChatNotice.Text="Attached checks are too old. Review fresh computer checks before sending.";return;}
        if(chatMessages.Any(m=>m.Diagnostics!=null && m.Diagnostics.Groups.Any(g=>!options.AllowsDiagnostics(g)))){ChatNotice.Text="Your organisation changed which checks may be shared. Start a new chat to continue.";return;}
        var message = new ChatMessage("user", ChatInput.Text.Trim(),selectedDiagnostics,selectedKnowledge);
        var next = chatMessages.Append(message).ToArray();
        try { ObliClient.ValidateChat(next); }
        catch { ChatNotice.Text = "Enter a message. For long conversations, start a new chat (up to 8 questions / 12 KB including replies, checks and guides)."; return; }
        chatBusy = true;
        using var attempt = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token); chatAttempt = attempt; RenderChat();
        try
        {
            // Recheck the disclosure immediately before sending. A changed provider requires fresh consent.
            var current = await client.ChatOptionsAsync(session, attempt.Token);
            if (staffSession != session || attempt.IsCancellationRequested) return;
            if (current != options) { ApplyChatOptions(current); ChatNotice.Text = "Your organisation changed AI settings. Review the updated provider before sending."; return; }
            pendingChat = Guid.NewGuid();
            chatMessages.Add(message); AddChatRow(message); ChatInput.Clear();
            selectedKnowledge=null; SelectedKnowledgeText.Clear(); SelectedKnowledgeTitle.Text="";
            selectedDiagnostics=null;SelectedDiagnosticPanel.Visibility=Visibility.Collapsed;SelectedDiagnosticText.Text="";
            ChatNotice.Text = "Waiting for Obli…"; RenderChat();
            var reply = await client.SendChatAsync(session, pendingChat.Value, options.Version, next, attempt.Token);
            if (staffSession == session && !attempt.IsCancellationRequested) ApplyChatReply(reply);
        }
        catch (ObliAccessException) { if (staffSession == session) ForgetStaff("Your staff access changed. Sign in again."); }
        catch (ObliKnowledgeUnavailableException) { if (staffSession == session) ForgetUnavailableKnowledge(); }
        catch (ObliChatRejectedException rejected)
        {
            if (staffSession == session)
            {
                if (pendingChat != null) { chatMessages.RemoveAt(chatMessages.Count - 1); ChatRows.Items.RemoveAt(ChatRows.Items.Count - 1); }
                pendingChat = null; ChatInput.Text = message.Content;
                selectedKnowledge=message.Knowledge; SelectedKnowledgeText.Text=selectedKnowledge?.Excerpt??""; SelectedKnowledgeTitle.Text=selectedKnowledge?.Title??"";
                selectedDiagnostics=message.Diagnostics;
                SelectedDiagnosticText.Text=selectedDiagnostics?.Preview()??"";SelectedDiagnosticPanel.Visibility=selectedDiagnostics==null?Visibility.Collapsed:Visibility.Visible; chatOptions = null; ChatConsent.IsChecked = false;
                ChatNotice.Text = rejected.Message;
            }
        }
        catch (Exception error) when (error is not OutOfMemoryException)
        {
            if (staffSession == session) ChatNotice.Text = pendingChat != null
                ? "The reply is not confirmed. Check for the reply without sending again. The provider request may still finish or incur a charge."
                : "The message was not sent. Reload AI settings and try again.";
        }
        finally { chatAttempt = null; chatBusy = false; RenderChat(); }
    }
    async void CheckChatClick(object sender, RoutedEventArgs e) => await CheckChatAsync();
    async Task CheckChatAsync()
    {
        var client = staffClient; var session = staffSession; var pending = pendingChat;
        if (client == null || session == null || pending == null || chatBusy) return;
        chatBusy = true;
        using var attempt = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token); chatAttempt = attempt; RenderChat();
        try
        {
            var reply = await client.ChatOutcomeAsync(session, pending.Value, attempt.Token);
            if (staffSession == session && !attempt.IsCancellationRequested) ApplyChatReply(reply);
        }
        catch (ObliAccessException) { if (staffSession == session) ForgetStaff("Your staff access changed. Sign in again."); }
        catch (ObliKnowledgeUnavailableException) { if (staffSession == session) ForgetUnavailableKnowledge(); }
        catch (Exception error) when (error is not OutOfMemoryException)
        { if (staffSession == session) ChatNotice.Text = "No reply could be recovered. You can check again, or clear this conversation and start a new chat."; }
        finally { chatAttempt = null; chatBusy = false; RenderChat(); }
    }
    void ForgetUnavailableKnowledge()
    {
        ClearPrivateChat(); Navigate("help");
        ChatNotice.Text="A selected guide changed or is no longer available to you. This conversation has been cleared. A request already sent may still be processed or charged. Reload chat and guides to start again; nothing was resent.";
    }
    void ApplyChatReply(ChatReply reply)
    {
        if (pendingChat != reply.RequestId) return;
        if (reply.Status == "completed")
        {
            var message = new ChatMessage("assistant", reply.Text!);
            chatMessages.Add(message); AddChatRow(message); pendingChat = null;
            ChatNotice.Text = "Private conversation · use Share in ticket draft only for a message you want IT to see.";
        }
        else ChatNotice.Text = reply.Status == "pending"
            ? "The request is still awaiting confirmation. Check again shortly."
            : "The provider reply could not be confirmed. This request will not be sent again. Start a new chat if you want to try again.";
    }
    void AddChatRow(ChatMessage message)
    {
        var panel = new StackPanel { Margin = new Thickness(0, 12, 0, 12) };
        panel.Children.Add(new TextBlock { Text = message.Role == "user" ? "You" : "Obli", FontWeight = FontWeights.SemiBold, Margin = new Thickness(0, 0, 0, 6) });
        panel.Children.Add(new TextBlock { Text = message.Content, TextWrapping = TextWrapping.Wrap });
        if(message.Knowledge!=null)panel.Children.Add(new TextBlock{Text="Selected guide: "+message.Knowledge.Title+"\n"+message.Knowledge.Excerpt,TextWrapping=TextWrapping.Wrap,Margin=new Thickness(0,8,0,0),Foreground=(Brush)FindResource("MutedForegroundBrush")});
        if(message.Diagnostics!=null)panel.Children.Add(new TextBlock{Text=message.Diagnostics.Preview(),Margin=new Thickness(0,8,0,0),Foreground=(Brush)FindResource("MutedForegroundBrush")});
        var share = new Button { Content = "Share in ticket draft", HorizontalAlignment = HorizontalAlignment.Left, Margin = new Thickness(0, 8, 0, 0) };
        share.Click += (_, _) => {
            if (staffSession == null || staffBusy || chatBusy || ticketBusy) return;
            if (pendingReply != null || !string.IsNullOrWhiteSpace(ReplyBody.Text))
            { ChatNotice.Text = "You have a reply draft. Send or discard it in My tickets before starting another request."; return; }
            if (pendingTicket != null || !string.IsNullOrWhiteSpace(TicketDescription.Text))
            { ChatNotice.Text = "You already have a ticket draft. Finish or clear it before sharing this message."; return; }
            string sharedText=message.Content+(message.Diagnostics==null?"":"\n\n"+message.Diagnostics.Preview());
            if (sharedText.Length > 11800) { ChatNotice.Text = "This message is too long for a ticket. Copy only the relevant steps into your draft."; return; }
            TicketTitle.Text = "Computer support from Obli";
            TicketDescription.Text = (message.Role == "user" ? "My selected message:\n\n" : "Selected AI suggestion (not a confirmed diagnosis):\n\n") + sharedText;
            conversation = null; ConversationRows.Items.Clear(); ConversationDescription.Clear(); RenderTicketConversation();
            NewRequestPanel.Visibility = Visibility.Visible; NewRequestButton.Content = "Hide draft";
            StaffNotice.Text = message.Knowledge != null ? "Your selected message was added. Guide excerpts are not included. Edit and review this draft before sending it to IT." : "Only your selected message was added. Edit and review this draft before sending it to IT.";
            Navigate("tickets");
        };
        panel.Children.Add(share); ChatRows.Items.Add(panel);
    }
    void NewChatClick(object sender, RoutedEventArgs e)
    {
        if (chatBusy) return;
        if ((chatMessages.Count > 0 || ChatInput.Text.Length > 0 || selectedKnowledge != null || selectedDiagnostics != null) && MessageBox.Show(this,
            "Clear this conversation from the helper? Previously sent requests may still finish; starting another request can incur a separate charge. Replies remain on Oblivion until their retention expires.",
            "Start a new chat", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes) return;
        var options = chatOptions; ClearPrivateChat(); if (options != null) ApplyChatOptions(options);
    }
    void StopChatClick(object sender, RoutedEventArgs e) => chatAttempt?.Cancel();
}
