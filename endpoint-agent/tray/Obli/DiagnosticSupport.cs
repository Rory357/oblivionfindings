using System;
using System.Linq;
using System.Threading;
using System.Windows;
using System.Windows.Controls;

namespace Oblivion.Obli;
public partial class MainWindow
{
    DiagnosticSnapshot? diagnosticCandidate;
    DiagnosticSnapshot? selectedDiagnostics;
    CancellationTokenSource? diagnosticAttempt;
    bool diagnosticBusy;
    void ClearDiagnostics()
    {
        diagnosticAttempt?.Cancel();diagnosticCandidate=null;selectedDiagnostics=null;
        DiagnosticPanel.Visibility=Visibility.Collapsed;SelectedDiagnosticPanel.Visibility=Visibility.Collapsed;
        DiagnosticPreview.Text="";SelectedDiagnosticText.Text="";
        DiagnosticSystem.IsChecked=false;DiagnosticSoftware.IsChecked=false;DiagnosticServices.IsChecked=false;
    }
    void RenderDiagnostics()
    {
        bool allowed=staffSession!=null && chatOptions is {Enabled:true} &&
            new[]{"system","software","services"}.Any(name=>chatOptions.AllowsDiagnostics(name));
        ReviewDiagnosticsButton.Visibility=allowed?Visibility.Visible:Visibility.Collapsed;
        ReviewDiagnosticsButton.IsEnabled=allowed&&!chatBusy&&!diagnosticBusy&&pendingChat==null;
        UseDiagnosticsButton.IsEnabled=allowed&&!chatBusy&&!diagnosticBusy&&pendingChat==null&&diagnosticCandidate!=null&&SelectedDiagnosticGroups().Length>0;
        RemoveDiagnosticsButton.IsEnabled=!chatBusy;
    }
    string[] SelectedDiagnosticGroups()=>new[]{
        (DiagnosticSystem.IsChecked==true?"system":""),(DiagnosticSoftware.IsChecked==true?"software":""),(DiagnosticServices.IsChecked==true?"services":"")
    }.Where(name=>name!="").ToArray();
    async void ReviewDiagnosticsClick(object sender,RoutedEventArgs e)
    {
        var session=staffSession;var options=chatOptions;
        if(session==null||options==null||chatBusy||diagnosticBusy||pendingChat!=null||
            !new[]{"system","software","services"}.Any(options.AllowsDiagnostics))return;
        diagnosticBusy=true;
        using var attempt=CancellationTokenSource.CreateLinkedTokenSource(lifetime.Token);diagnosticAttempt=attempt;
        RenderDiagnostics();
        try {
            var snapshot=await client.ReadDiagnosticsAsync(session.Origin,attempt.Token);
            if(staffSession!=session||chatOptions!=options||attempt.IsCancellationRequested)return;
            if(snapshot==null){ChatNotice.Text="Recent computer checks are not available. Try again after the next check-in.";return;}
            ShowDiagnosticPreview(snapshot.Select(snapshot.Groups.Where(options.AllowsDiagnostics)));
        }
        catch(Exception error)when(error is not OutOfMemoryException){
            if(staffSession==session)ChatNotice.Text="Computer checks could not be verified. Nothing was sent to the AI provider.";
        }
        finally{diagnosticAttempt=null;diagnosticBusy=false;RenderDiagnostics();}
    }
    void ShowDiagnosticPreview(DiagnosticSnapshot snapshot)
    {
        diagnosticCandidate=null;
        DiagnosticSystem.IsChecked=false;DiagnosticSoftware.IsChecked=false;DiagnosticServices.IsChecked=false;
        diagnosticCandidate=snapshot;
        DiagnosticSystem.Visibility=snapshot.Groups.Contains("system")?Visibility.Visible:Visibility.Collapsed;
        DiagnosticSoftware.Visibility=snapshot.Groups.Contains("software")?Visibility.Visible:Visibility.Collapsed;
        DiagnosticServices.Visibility=snapshot.Groups.Contains("services")?Visibility.Visible:Visibility.Collapsed;
        DiagnosticPanel.Visibility=Visibility.Visible;
        ChatNotice.Text=selectedDiagnostics==null?"Preview only. Choose what to attach to your message.":"Your existing attachment stays in the draft until you replace or remove it.";
        DiagnosticPreview.Text="Choose which checks to include. Nothing is sent until you select Use these checks and then send your message.";
        RenderDiagnostics();
    }
    void DiagnosticSelectionChanged(object sender,RoutedEventArgs e)
    {
        if(DiagnosticPreview==null||UseDiagnosticsButton==null)return;
        var groups=SelectedDiagnosticGroups();
        DiagnosticPreview.Text=diagnosticCandidate!=null&&groups.Length>0
            ?diagnosticCandidate.Select(groups).Preview():"Choose which checks to include.";
        RenderDiagnostics();
    }
    void UseDiagnosticsClick(object sender,RoutedEventArgs e)
    {
        var groups=SelectedDiagnosticGroups();
        if(diagnosticCandidate==null||groups.Length==0||chatBusy||diagnosticBusy||pendingChat!=null||staffSession==null)return;
        if(!diagnosticCandidate.IsFresh(DateTimeOffset.UtcNow)){
            ChatNotice.Text="These checks are too old. Request a fresh preview before sharing.";return;
        }
        if(chatOptions==null||groups.Any(name=>!chatOptions.AllowsDiagnostics(name)))return;
        selectedDiagnostics=diagnosticCandidate.Select(groups);
        SelectedDiagnosticText.Text=selectedDiagnostics.Preview();
        SelectedDiagnosticPanel.Visibility=Visibility.Visible;
        diagnosticCandidate=null;DiagnosticPanel.Visibility=Visibility.Collapsed;DiagnosticPreview.Text="";
        ChatNotice.Text="Selected checks are attached to your draft. Review your message before sending.";
    }
    void RemoveDiagnosticsClick(object sender,RoutedEventArgs e)
    {
        if(chatBusy)return;
        selectedDiagnostics=null;SelectedDiagnosticPanel.Visibility=Visibility.Collapsed;SelectedDiagnosticText.Text="";
        ChatNotice.Text="Computer checks removed from your draft.";
    }
    void CancelDiagnosticsClick(object sender,RoutedEventArgs e)
    {
        diagnosticCandidate=null;DiagnosticPanel.Visibility=Visibility.Collapsed;DiagnosticPreview.Text="";
    }
}
