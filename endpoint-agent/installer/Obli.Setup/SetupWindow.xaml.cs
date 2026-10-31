using System.ComponentModel;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Threading;
using Microsoft.Win32;
namespace Oblivion.Setup;
public partial class SetupWindow:Window {
 readonly IInstallerEngine engine;readonly IServiceRepairEngine? repairEngine;
 InstallationInput? input;InstallationReview? review;RepairInput? repairInput;RepairReview? repairReview;string? temporaryComputerProfile;string? temporaryCollectorProfile;
 CancellationTokenSource? operation;bool busy,installing,closeAfterStop,closed;int step;
 readonly DispatcherTimer expiry=new(){Interval=TimeSpan.FromSeconds(1)};
 bool RepairMode=>((ComboBoxItem)ModeChoice.SelectedItem).Tag?.ToString()=="repair";
 bool ReviewCurrent=>RepairMode?repairReview?.IsCurrent==true:review?.IsCurrent==true;
 string Role=>((ComboBoxItem)RoleChoice.SelectedItem).Tag.ToString()!;
 string ReviewButton=>RepairMode?"Review service repair":"Review setup";
 public SetupWindow(IInstallerEngine engine,IServiceRepairEngine? repairEngine=null){
  this.engine=engine;this.repairEngine=repairEngine??engine as IServiceRepairEngine;
  InitializeComponent();((ComboBoxItem)ModeChoice.Items[1]).IsEnabled=this.repairEngine!=null;ModeChanged(null,null!);
  TryPopulateAdjacentPackage();
  Closing+=OnClosing;Closed+=(_,_)=>{closed=true;expiry.Stop();operation?.Cancel();operation?.Dispose();CleanupTemporaryProfile();};
  expiry.Tick+=(_,_)=>CheckExpiry();expiry.Start();
 }
 void TryPopulateAdjacentPackage(){
  try{
   var candidates=Directory.GetFiles(AppContext.BaseDirectory,"oblivion-windows-*.zip");
   if(candidates.Length==1&&string.IsNullOrEmpty(PackagePath.Text))PackagePath.Text=candidates[0];
  }catch{}
 }
 void CleanupTemporaryComputerProfile(){
  if(temporaryComputerProfile==null)return;
  try{if(File.Exists(temporaryComputerProfile))File.Delete(temporaryComputerProfile);}catch{}
  temporaryComputerProfile=null;
 }
 void CleanupTemporaryCollectorProfile(){
  if(temporaryCollectorProfile==null)return;
  try{if(File.Exists(temporaryCollectorProfile))File.Delete(temporaryCollectorProfile);}catch{}
  temporaryCollectorProfile=null;
 }
 void CleanupTemporaryProfile(){CleanupTemporaryComputerProfile();CleanupTemporaryCollectorProfile();}
 static string ResolveEncodedProfile(string raw,string prefix,string filePrefix,Action cleanupBeforeWrite,Action<string> trackFile){
  string trimmed=raw.Trim();
  if(!trimmed.StartsWith(prefix,StringComparison.Ordinal))return trimmed;
  if(trimmed.Length>24576)throw new InvalidOperationException("The one-time setup code is too long.");
  string encoded=trimmed[prefix.Length..].Replace('-','+').Replace('_','/');
  switch(encoded.Length%4){case 2:encoded+="==";break;case 3:encoded+="=";break;case 0:break;default:throw new InvalidOperationException("The one-time setup code is invalid. Copy it again from Oblivion.");}
  byte[] bytes;
  try{bytes=Convert.FromBase64String(encoded);}catch{throw new InvalidOperationException("The one-time setup code is invalid. Copy it again from Oblivion.");}
  cleanupBeforeWrite();
  string dir=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"Oblivion","Setup","Profiles");
  Directory.CreateDirectory(dir);
  string file=Path.Combine(dir,filePrefix+Guid.NewGuid().ToString("N")+".json");
  using(var stream=new FileStream(file,FileMode.CreateNew,FileAccess.Write,FileShare.None)){stream.Write(bytes);stream.Flush(true);}
  Array.Clear(bytes,0,bytes.Length);
  trackFile(file);
  return file;
 }
 internal string ResolveComputerProfile(string raw)=>ResolveEncodedProfile(raw,"ofc1_","computer-",CleanupTemporaryComputerProfile,file=>temporaryComputerProfile=file);
 string ResolveCollectorProfile(string raw)=>ResolveEncodedProfile(raw,"ofh1_","site-",CleanupTemporaryCollectorProfile,file=>temporaryCollectorProfile=file);
 internal void CheckExpiry(){
  if(step==1&&!ReviewCurrent){ShowNotice(RepairMode?"The repair review expired. Review the installed connection again.":"The installation profile expired. Download a new profile and review the setup again.");PrimaryButton.IsEnabled=false;ConfirmDestination.IsChecked=false;}
 }
 void ModeChanged(object? sender,SelectionChangedEventArgs e){
  if(!IsInitialized||busy)return;input=null;review=null;repairInput=null;repairReview=null;CleanupTemporaryProfile();ConfirmDestination.IsChecked=false;HideNotice();
  if(RepairMode){ComputerPath.Text="";CollectorPath.Text="";}
  RoleLabel.Visibility=RoleChoice.Visibility=RepairMode?Visibility.Collapsed:Visibility.Visible;
  FilesIntroduction.Text=RepairMode?"Choose the same signed release package used for this installation. Obli will read the saved computer, site and server details.":"Use the installation package and profile or one-time code from your organisation’s Oblivion.";
  PrimaryButton.Content=ReviewButton;StepText.Text=RepairMode?"1 · Repair installed services":"1 · Choose what to install";
  RoleChanged(null,null!);
 }
 void RoleChanged(object? sender,SelectionChangedEventArgs e){
  if(!IsInitialized)return;bool computer=!RepairMode&&Role is "computer" or "both",site=!RepairMode&&Role is "site" or "both";
  ComputerFiles.Visibility=computer?Visibility.Visible:Visibility.Collapsed;CollectorFiles.Visibility=site?Visibility.Visible:Visibility.Collapsed;
  if(!computer){ComputerPath.Text="";CleanupTemporaryComputerProfile();}if(!site){CollectorPath.Text="";CleanupTemporaryCollectorProfile();}
  RoleHelp.Text=RepairMode?"Restore Obli’s services and startup settings while keeping the existing device identity and monitoring data.":Role switch{"computer"=>"Installs the Obli Windows tray helper, computer health service, and attended remote support.","site"=>"Monitor approved devices on this site’s network.","both"=>"One setup installing the Obli Windows tray helper, computer support service, and site monitoring.",_=>""};
 }
 void BrowsePackage(object sender,RoutedEventArgs e)=>Browse(PackagePath,"Installation package (*.zip)|*.zip");
 void BrowseComputer(object sender,RoutedEventArgs e)=>Browse(ComputerPath,"Computer profile (*.json)|*.json");
 void BrowseCollector(object sender,RoutedEventArgs e)=>Browse(CollectorPath,"Site profile (*.json)|*.json");
 void Browse(TextBox target,string filter){if(busy)return;var picker=new OpenFileDialog{Filter=filter,CheckFileExists=true,Multiselect=false};if(picker.ShowDialog(this)==true){target.Text=picker.FileName;HideNotice();}}
 void ShowNotice(string text){Notice.Text=text;NoticeBox.Visibility=Visibility.Visible;}
 void HideNotice(){Notice.Text="";NoticeBox.Visibility=Visibility.Collapsed;}
 void ConfirmationChanged(object sender,RoutedEventArgs e){if(IsInitialized&&step==1)PrimaryButton.IsEnabled=!busy&&ReviewCurrent&&ConfirmDestination.IsChecked==true;}
 async void PrimaryClicked(object sender,RoutedEventArgs e)=>await AdvanceAsync();
 public async Task AdvanceAsync(){
  if(busy)return;
  if(step==0){
   try {
    if(RepairMode){repairInput=new(PackagePath.Text);_=repairInput.ReviewArguments();if(repairEngine==null)throw new InvalidOperationException("Service repair is unavailable in this build.");}
    else{string computerProfile=Role is "computer" or "both"?ResolveComputerProfile(ComputerPath.Text):"";string collectorProfile=Role is "site" or "both"?ResolveCollectorProfile(CollectorPath.Text):"";input=new(Role,PackagePath.Text,computerProfile,collectorProfile);_=input.Arguments("review");}
   }catch(Exception error){ShowNotice(error.Message);return;}
   Start(false);
   try {
    if(RepairMode){var value=await repairEngine!.ReviewRepairAsync(repairInput!,operation!.Token);if(operation.IsCancellationRequested)return;repairReview=value;ShowRepairReview(value);}
    else{var value=await engine.ReviewAsync(input!,operation!.Token);if(operation.IsCancellationRequested)return;review=value;ShowReview(value);}
   }catch(OperationCanceledException){ShowNotice("Review was cancelled.");}
   catch(Exception error){ShowNotice(error.Message);}
   finally{Finish();}return;
  }
  if(step!=1||ConfirmDestination.IsChecked!=true||!ReviewCurrent)return;
  if(RepairMode?(repairInput==null||repairReview==null||repairEngine==null):(input==null||review==null))return;
  Start(true);step=2;SetPanes();StepText.Text=RepairMode?"3 · Repairing services":"3 · Installing";
  WorkingTitle.Text=RepairMode?"Restoring the installed services…":"Installing the selected components…";
  WorkingExplanation.Text=RepairMode?"Keep this window open. Cancelling asks repair to stop and record its outcome; existing identity and monitoring data are retained.":"Keep this window open. Cancelling asks setup to stop and finish its cleanup before returning.";
  PrimaryButton.Visibility=BackButton.Visibility=Visibility.Collapsed;CancelButton.Content=RepairMode?"Cancel repair":"Cancel installation";
  try{
   if(RepairMode){
    await repairEngine!.RepairAsync(repairInput!,repairReview!,operation!.Token);
    step=3;ResultTitle.Text="Services repaired";ResultBody.Text="The installed services are ready locally. Check their connection and latest reports in Oblivion before relying on monitoring or remote support. The existing device identity and monitoring data were retained.";
   }else{
    await engine.InstallAsync(input!,review!,operation!.Token);
    CleanupTemporaryProfile();
    step=3;ResultTitle.Text="Installation completed";ResultBody.Text="The selected services are installed. Check their connection and latest reports in Oblivion before relying on monitoring or remote support. Delete any downloaded installation profiles after confirming setup.";
   }
  }catch(OperationCanceledException){
   step=3;ResultTitle.Text=RepairMode?"Repair stopped":"Installation stopped";ResultBody.Text="Check the saved outcome with your IT team before retrying. Existing device identity and monitoring state are retained.";
  }catch(Exception error){step=3;ResultTitle.Text=RepairMode?"Repair needs attention":"Installation needs attention";ResultBody.Text=error.Message;}
  finally{Finish();SetPanes();StepText.Text=RepairMode?"Repair result":"Setup result";PrimaryButton.Visibility=Visibility.Collapsed;CancelButton.Content="Close";}
 }
 void Start(bool install){
  HideNotice();busy=true;installing=install;operation?.Dispose();operation=new();FilesPane.IsEnabled=false;PrimaryButton.IsEnabled=false;BackButton.IsEnabled=false;ConfirmDestination.IsEnabled=false;
  CancelButton.Content=install?(RepairMode?"Cancel repair":"Cancel installation"):"Cancel review";
 }
 void Finish(){
  busy=false;installing=false;FilesPane.IsEnabled=true;BackButton.IsEnabled=true;ConfirmDestination.IsEnabled=true;
  PrimaryButton.IsEnabled=step==0||(step==1&&ConfirmDestination.IsChecked==true&&ReviewCurrent);CancelButton.Content="Close";CancelButton.IsEnabled=true;
  if(closeAfterStop)Dispatcher.BeginInvoke(new Action(()=>{if(!closed)Close();}));
 }
 static string RoleName(string role)=>role switch{"computer"=>"Computer support","site"=>"Site monitoring",_=>"Computer support and site monitoring"};
 static string Software(string version,string arch)=>version+" · "+(arch=="amd64"?"Windows x64":"Windows ARM64");
 static string LocalExpiry(DateTimeOffset expires)=>expires.ToLocalTime().ToString("ddd d MMM, h:mm tt",System.Globalization.CultureInfo.GetCultureInfo("en-NZ"))+" (this computer’s time)";
 void ShowReview(InstallationReview value){
  step=1;ReviewRows.Children.Clear();ReviewIntroduction.Text="Check that these details belong to the organisation and site you intend to connect.";
  Row("Installation",RoleName(value.Role));Row("Organisation",value.Organisation);if(value.Computer!="")Row("Computer",value.Computer);if(value.Site!="")Row("Site",value.Site);
  Row("Oblivion server",value.Server);if(value.CollectorServer!=""&&value.CollectorServer!=value.Server)Row("Collector server",value.CollectorServer);
  Row("Software",Software(value.Version,value.Architecture));Row("Profile expires",LocalExpiry(value.Expires));
  ConfirmDestination.IsChecked=false;ConfirmationCopy.Text="I have checked the organisation, server and installation details.";
  TrayExplanation.Text=value.Role=="site"?"This installs site monitoring without a staff tray helper.":"Computer support installs the Windows service and starts the Obli tray helper when staff sign in.";
  StepText.Text="2 · Review the connection";PrimaryButton.Content="Install";BackButton.Visibility=Visibility.Visible;SetPanes();
 }
 void ShowRepairReview(RepairReview value){
  step=1;ReviewRows.Children.Clear();ReviewIntroduction.Text="These are the saved details for this computer. Check the connection before restoring its services.";
  Row("Repair",RoleName(value.Role));Row("Computer",value.Computer);if(value.SiteId>0)Row("Site","Site reference "+value.SiteId);
  Row("Oblivion server",value.Server);if(value.CollectorServer!=""&&value.CollectorServer!=value.Server)Row("Collector server",value.CollectorServer);
  Row("Installed software",Software(value.Version,value.Architecture));Row("Review expires",LocalExpiry(value.Expires));
  ConfirmDestination.IsChecked=false;ConfirmationCopy.Text="I have checked these details and want to restore the installed services.";
  TrayExplanation.Text="Repair restores service, logging and startup settings. It keeps the existing identity and monitoring data. Damaged program files need a separate repair or update.";
  StepText.Text="2 · Review service repair";PrimaryButton.Content="Repair services";BackButton.Visibility=Visibility.Visible;SetPanes();
 }
 void Row(string name,string value){
  var grid=new Grid{Margin=new Thickness(0,5,0,5)};grid.ColumnDefinitions.Add(new ColumnDefinition{Width=new GridLength(140)});grid.ColumnDefinitions.Add(new ColumnDefinition());
  var label=new TextBlock{Text=name,Style=(Style)FindResource("SecondaryText"),Margin=new Thickness(0,0,12,0)};
  var content=new TextBlock{Text=value,FontWeight=FontWeights.SemiBold,TextWrapping=TextWrapping.Wrap};Grid.SetColumn(content,1);grid.Children.Add(label);grid.Children.Add(content);ReviewRows.Children.Add(grid);
 }
 void SetPanes(){FilesPane.Visibility=step==0?Visibility.Visible:Visibility.Collapsed;ReviewPane.Visibility=step==1?Visibility.Visible:Visibility.Collapsed;WorkingPane.Visibility=step==2?Visibility.Visible:Visibility.Collapsed;ResultPane.Visibility=step==3?Visibility.Visible:Visibility.Collapsed;}
 void BackClicked(object sender,RoutedEventArgs e){
  if(busy)return;step=0;review=null;input=null;repairReview=null;repairInput=null;CleanupTemporaryProfile();ConfirmDestination.IsChecked=false;HideNotice();SetPanes();
  StepText.Text=RepairMode?"1 · Repair installed services":"1 · Choose what to install";BackButton.Visibility=Visibility.Collapsed;PrimaryButton.Content=ReviewButton;PrimaryButton.IsEnabled=true;
 }
 void CancelClicked(object sender,RoutedEventArgs e){if(!busy){Close();return;}RequestStop();}
 void RequestStop(){operation?.Cancel();CancelButton.IsEnabled=false;ShowNotice(installing?(RepairMode?"Stopping repair and recording the outcome…":"Stopping installation and allowing cleanup to finish…"):"Cancelling review…");}
 void OnClosing(object? sender,CancelEventArgs e){if(!busy)return;e.Cancel=true;closeAfterStop=true;RequestStop();}
}
