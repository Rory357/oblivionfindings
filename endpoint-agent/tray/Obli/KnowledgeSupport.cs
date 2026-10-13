using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace Oblivion.Obli;
public partial class MainWindow
{
    KnowledgeDetail? knowledgeDetail;
    KnowledgeReference? selectedKnowledge;
    readonly Stack<int?> knowledgePages = new();
    string knowledgeSearch = "";
    int? knowledgeBefore, nextKnowledgeBefore;
    long knowledgeGeneration;
    bool knowledgeBusy;
    CancellationTokenSource? knowledgeAttempt;

    void ClearKnowledge()
    {
        knowledgeGeneration++; knowledgeAttempt?.Cancel(); knowledgeAttempt = null; knowledgeBusy = false;
        knowledgeDetail = null; selectedKnowledge = null; knowledgePages.Clear();
        knowledgeBefore = nextKnowledgeBefore = null; knowledgeSearch = "";
        KnowledgeQuery.Clear(); KnowledgeRows.Items.Clear(); KnowledgeExcerpt.Clear();
        KnowledgeTitle.Text = KnowledgeDate.Text = KnowledgeNotice.Text = KnowledgeSharingNotice.Text = "";
        SelectedKnowledgeText.Clear(); SelectedKnowledgeTitle.Text = "";
        if (KnowledgePane.Visibility == Visibility.Visible) Navigate("help");
        KnowledgePane.Visibility = Visibility.Collapsed;
        RenderKnowledge();
    }
    void RenderKnowledge()
    {
        if (BrowseKnowledgeButton == null || KnowledgePane == null) return;
        bool allowed = staffSession != null && chatOptions is { KnowledgeEnabled: true };
        BrowseKnowledgeButton.Visibility = allowed ? Visibility.Visible : Visibility.Collapsed;
        BrowseKnowledgeButton.IsEnabled = allowed && !chatBusy && !knowledgeBusy;
        SearchKnowledgeButton.IsEnabled = KnowledgeQuery.IsEnabled = allowed && !knowledgeBusy;
        PreviousKnowledgeButton.Visibility = knowledgePages.Count > 0 ? Visibility.Visible : Visibility.Collapsed;
        MoreKnowledgeButton.Visibility = nextKnowledgeBefore != null ? Visibility.Visible : Visibility.Collapsed;
        PreviousKnowledgeButton.IsEnabled = MoreKnowledgeButton.IsEnabled = allowed && !knowledgeBusy;
        KnowledgeListPanel.Visibility = knowledgeDetail == null ? Visibility.Visible : Visibility.Collapsed;
        KnowledgeDetailPanel.Visibility = knowledgeDetail != null ? Visibility.Visible : Visibility.Collapsed;
        UseKnowledgeButton.IsEnabled = allowed && chatOptions is { Enabled:true } && knowledgeDetail != null && !knowledgeBusy && !chatBusy && pendingChat == null;
        OpenKnowledgeButton.IsEnabled = BackToGuidesButton.IsEnabled = allowed && !knowledgeBusy;
        RemoveKnowledgeButton.IsEnabled = !chatBusy && pendingChat == null;
        SelectedKnowledgePanel.Visibility = selectedKnowledge != null ? Visibility.Visible : Visibility.Collapsed;
    }
    bool KnowledgeCurrent(StaffSession session, long generation) =>
        staffSession == session && knowledgeGeneration == generation && !DesktopPrivacyLocked;
    void ShowKnowledge()
    {
        Navigate("help"); HelpPane.Visibility = Visibility.Collapsed; KnowledgePane.Visibility = Visibility.Visible; PageScroll.ScrollToTop();
    }
    async void BrowseKnowledgeClick(object sender, RoutedEventArgs e)
    {
        if (staffSession == null || chatOptions is not { KnowledgeEnabled:true } || chatBusy || knowledgeBusy) return;
        ShowKnowledge();
        if (knowledgeDetail == null && KnowledgeRows.Items.Count == 0) await LoadKnowledgeAsync("", null);
    }
    void BackFromKnowledgeClick(object sender, RoutedEventArgs e) { Navigate("help"); PageScroll.ScrollToTop(); }
    void BackToGuidesClick(object sender, RoutedEventArgs e)
    {
        if (knowledgeBusy) return;
        knowledgeDetail = null; KnowledgeExcerpt.Clear(); KnowledgeTitle.Text = KnowledgeDate.Text = KnowledgeSharingNotice.Text = "";
        KnowledgeNotice.Text = ""; RenderKnowledge();
    }
    async void SearchKnowledgeClick(object sender, RoutedEventArgs e)
    {
        if (await LoadKnowledgeAsync(KnowledgeQuery.Text.Trim(), null)) { knowledgePages.Clear(); RenderKnowledge(); }
    }
    async void MoreKnowledgeClick(object sender, RoutedEventArgs e) => await MoreKnowledgeAsync();
    async Task MoreKnowledgeAsync()
    {
        if (knowledgeBusy || nextKnowledgeBefore == null) return;
        int? previous = knowledgeBefore;
        if (await LoadKnowledgeAsync(knowledgeSearch, nextKnowledgeBefore)) knowledgePages.Push(previous);
        RenderKnowledge();
    }
    async void PreviousKnowledgeClick(object sender, RoutedEventArgs e) => await PreviousKnowledgeAsync();
    async Task PreviousKnowledgeAsync()
    {
        if (knowledgeBusy || knowledgePages.Count == 0) return;
        if (await LoadKnowledgeAsync(knowledgeSearch, knowledgePages.Peek())) knowledgePages.Pop();
        RenderKnowledge();
    }
    async Task<bool> LoadKnowledgeAsync(string query, int? before)
    {
        var client = staffClient; var session = staffSession;
        if (client == null || session == null || chatOptions is not {KnowledgeEnabled:true} || knowledgeBusy) return false;
        knowledgeBusy = true; long generation = knowledgeGeneration;
        using var attempt = CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token); knowledgeAttempt = attempt;
        KnowledgeNotice.Text = "Loading guides…"; RenderKnowledge();
        try {
            var page = await client.KnowledgeAsync(session, query, before, attempt.Token);
            if (!KnowledgeCurrent(session, generation) || attempt.IsCancellationRequested) return false;
            knowledgeSearch = query; knowledgeBefore = before; nextKnowledgeBefore = page.NextBefore; knowledgeDetail = null;
            KnowledgeRows.Items.Clear();
            foreach (var article in page.Articles) {
                var panel = new StackPanel { Margin = new Thickness(0,0,0,18) };
                var open = new Button { Content = new TextBlock { Text=article.Title, TextWrapping=TextWrapping.Wrap }, HorizontalContentAlignment=HorizontalAlignment.Left };
                open.Click += async (_, _) => await OpenKnowledgeAsync(article.Id);
                panel.Children.Add(open);
                panel.Children.Add(new TextBlock { Text=article.Summary, TextWrapping=TextWrapping.Wrap, Foreground=(Brush)FindResource("MutedForegroundBrush"), Margin=new Thickness(0,7,0,0) });
                KnowledgeRows.Items.Add(panel);
            }
            KnowledgeNotice.Text = page.Articles.Count == 0 ? (page.NextBefore != null ? "No approved guides on this page. Choose More guides to keep looking." : "No current approved guides match. Try different words or contact IT.") : "Select a guide to read its preview.";
            return true;
        }
        catch (ObliAccessException) { if (KnowledgeCurrent(session,generation)) ForgetStaff("Your staff access changed. Sign in again."); }
        catch (ObliKnowledgeUnavailableException) { if (KnowledgeCurrent(session,generation)) ForgetUnavailableKnowledge(); }
        catch (Exception error) when (error is not OutOfMemoryException) {
            if (KnowledgeCurrent(session,generation)) { KnowledgeRows.Items.Clear(); KnowledgeNotice.Text="Guides could not be loaded. Check your connection and try again."; }
        }
        finally { if (knowledgeGeneration==generation) { knowledgeBusy=false; knowledgeAttempt=null; RenderKnowledge(); } }
        return false;
    }
    async Task OpenKnowledgeAsync(int id)
    {
        var client=staffClient; var session=staffSession;
        if(client==null || session==null || chatOptions is not {KnowledgeEnabled:true} || knowledgeBusy)return;
        knowledgeBusy=true; long generation=knowledgeGeneration;
        using var attempt=CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);knowledgeAttempt=attempt;
        KnowledgeNotice.Text="Checking this guide…";RenderKnowledge();
        try {
            var detail=await client.KnowledgeArticleAsync(session,id,attempt.Token);
            if(!KnowledgeCurrent(session,generation)||attempt.IsCancellationRequested)return;
            knowledgeDetail=detail;KnowledgeTitle.Text=detail.Article.Title;KnowledgeExcerpt.Text=detail.Excerpt;
            KnowledgeDate.Text="Published "+ReplyTime(detail.Article.PublishedAt)+(detail.Article.ReviewDueAt is {} due ? "\nReview due "+due.ToString("d MMM yyyy",CultureInfo.GetCultureInfo("en-NZ")) : "");
            KnowledgeShortened.Visibility=detail.ExcerptTruncated?Visibility.Visible:Visibility.Collapsed;
            KnowledgeSharingNotice.Text=chatOptions is {Enabled:true}
                ? "Attach this excerpt to your question to share it with "+chatOptions.ProviderName+". Nothing is sent until you send your message."
                : "AI chat is switched off. You can still read the guide in Oblivion.";
            KnowledgeNotice.Text="";ShowKnowledge();
        }
        catch(ObliAccessException){if(KnowledgeCurrent(session,generation))ForgetStaff("Your staff access changed. Sign in again.");}
        catch(ObliKnowledgeUnavailableException){if(KnowledgeCurrent(session,generation))ForgetUnavailableKnowledge();}
        catch(Exception error)when(error is not OutOfMemoryException){
            if(KnowledgeCurrent(session,generation)){knowledgeDetail=null;KnowledgeExcerpt.Clear();KnowledgeTitle.Text=KnowledgeDate.Text=KnowledgeSharingNotice.Text="";
                KnowledgeRows.Items.Clear();KnowledgeNotice.Text="This guide could not be opened. It may have changed or no longer be available to you. Search again.";}
        }
        finally{if(knowledgeGeneration==generation){knowledgeBusy=false;knowledgeAttempt=null;RenderKnowledge();}}
    }
    void UseKnowledgeClick(object sender,RoutedEventArgs e)
    {
        if(knowledgeDetail==null||staffSession==null||chatOptions is not {Enabled:true,KnowledgeEnabled:true}||knowledgeBusy||chatBusy||pendingChat!=null)return;
        selectedKnowledge=new(knowledgeDetail.Article.Id,knowledgeDetail.Version,knowledgeDetail.Excerpt,knowledgeDetail.Article.Title);
        SelectedKnowledgeTitle.Text=knowledgeDetail.Article.Title;SelectedKnowledgeText.Text=knowledgeDetail.Excerpt;
        Navigate("help");ChatNotice.Text="Guide excerpt attached. Write your question and review the provider before sending.";RenderChat();ChatInput.Focus();
    }
    void RemoveKnowledgeClick(object sender,RoutedEventArgs e)
    {
        if(chatBusy||pendingChat!=null)return;
        selectedKnowledge=null;SelectedKnowledgeText.Clear();SelectedKnowledgeTitle.Text="";RenderKnowledge();
        ChatNotice.Text="Guide removed from your draft.";
    }
    void OpenKnowledgeClick(object sender,RoutedEventArgs e)
    {
        if(staffSession==null||knowledgeDetail==null||knowledgeBusy)return;
        try { Process.Start(new ProcessStartInfo(new Uri(staffSession.Origin,knowledgeDetail.Article.Path).AbsoluteUri){UseShellExecute=true}); }
        catch(Exception error)when(error is not OutOfMemoryException){KnowledgeNotice.Text="Your browser could not open. Sign into Oblivion and find this guide in IT knowledge.";}
    }
}
