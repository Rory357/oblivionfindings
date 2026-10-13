using System.IO;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using Oblivion.Setup;
static partial class Program {
 static string RepairSample(string role="both")=>JsonSerializer.Serialize(new{
  schema=1,action="repair_services",role,version="1.2.3",revision=7,architecture="amd64",computer="RECEPTION-01",
  server_url="https://care.example.test",collector_url=role=="computer"?"":"https://collectors.example.test",
  agent_uuid=role=="site"?"":"01234567-89ab-4cde-8f01-23456789abcd",collector_uuid=role=="computer"?"":"2df1d87c-2d04-4e57-80ab-8a15f39c944d",
  site_id=role=="computer"?0:9,installation_attempt=new string('a',32),manifest_sha256=new string('f',64),stage="r-7-"+new string('b',32),
  expires_at=DateTimeOffset.UtcNow.AddMinutes(10).ToString("O"),review_digest=new string('d',64)});
 static string RepairResult(RepairReview r)=>JsonSerializer.Serialize(new{schema=1,repair_id=new string('c',32),action="repair_services",
  installation_attempt=r.Attempt,manifest_sha256=r.Manifest,stage=r.Stage,role=r.Role,revision=r.Revision,
  status="local_services_ready",step="complete",started_at=DateTimeOffset.UtcNow.AddMinutes(-1).ToString("O"),updated_at=DateTimeOffset.UtcNow.ToString("O")});
 static SetupWindow RepairWindow(FakeRepair fake){
  var w=new SetupWindow(new FakeEngine(),fake);Find<ComboBox>(w,"ModeChoice").SelectedIndex=1;Find<TextBox>(w,"PackagePath").Text=@"C:\fixture\release.zip";return w;
 }
 static void RepairChecks(){
  foreach(string role in new[]{"computer","site","both"}){
   var review=RepairReview.Parse(RepairSample(role));var input=new RepairInput(@"C:\fixture\release.zip");
   var args=review.RepairArguments(input);Check(args.Contains("--control-stdin")&&args.Contains("--review-expires")&&args.Contains("--confirm-service-repair")&&!args.Contains("--bootstrap"),"Repair confirmation/identity boundary missing.");
   review.CheckResult(RepairResult(review));
   Reject(()=>(review with{Expires=DateTimeOffset.UtcNow.AddSeconds(-1)}).RepairArguments(input));
  }
  string raw=RepairSample();var r=RepairReview.Parse(raw);string result=RepairResult(r);
  foreach(string bad in new[]{raw.Replace("\"schema\":1","\"schema\":1,\"schema\":1"),raw.Replace("\"schema\":1","\"schema\":1,\"credential\":\"hidden\""),
   raw.Replace("https://care.example.test","http://care.example.test"),raw.Replace("\"role\":\"both\"","\"role\":\"computer\""),
   raw.Replace("\"site_id\":9","\"site_id\":0"),raw.Replace(new string('d',64),"invalid"),
   raw.Replace("r-7-","../r-7-")})Reject(()=>RepairReview.Parse(bad));
  foreach(string bad in new[]{result.Replace("local_services_ready","incomplete"),result.Replace(new string('f',64),new string('e',64)),
   result.Replace("\"revision\":7","\"revision\":8"),result.Replace("\"schema\":1","\"schema\":1,\"schema\":1")})Reject(()=>r.CheckResult(bad));
  string? actual=Environment.GetEnvironmentVariable("OBLI_SETUP_GO_REPAIR_REVIEW");
  if(!string.IsNullOrEmpty(actual)){var parsed=RepairReview.Parse(File.ReadAllText(actual));parsed.CheckResult(File.ReadAllText(actual+".result.json"));Check(parsed.Computer=="RECEPTION-01"&&parsed.SiteId==9,"Real Go repair contract changed.");}

  var fake=new FakeRepair();var window=RepairWindow(fake);
  Check(Find<ComboBox>(window,"RoleChoice").Visibility==Visibility.Collapsed&&Find<StackPanel>(window,"ComputerFiles").Visibility==Visibility.Collapsed&&Find<StackPanel>(window,"CollectorFiles").Visibility==Visibility.Collapsed,"Repair still requests setup profiles.");
  Render(window,"obli-repair-files.png");Complete(window.AdvanceAsync());
  Check(fake.Reviews==1&&!Find<Button>(window,"PrimaryButton").IsEnabled,"Repair was not explicitly confirmed.");
  Complete(window.AdvanceAsync());Check(fake.Repairs==0,"Unconfirmed repair ran.");
  Render(window,"obli-repair-review.png");Render(window,"obli-repair-review-narrow.png",520,680);
  Find<CheckBox>(window,"ConfirmDestination").IsChecked=true;Complete(window.AdvanceAsync());
  Check(fake.Repairs==1&&Find<TextBlock>(window,"ResultTitle").Text=="Services repaired","Confirmed repair result absent.");
  Render(window,"obli-repair-result.png");window.Close();

  var expired=new FakeRepair{Expired=true};var expiredWindow=RepairWindow(expired);Complete(expiredWindow.AdvanceAsync());expiredWindow.CheckExpiry();
  Find<CheckBox>(expiredWindow,"ConfirmDestination").IsChecked=true;Complete(expiredWindow.AdvanceAsync());
  Check(expired.Repairs==0&&!Find<Button>(expiredWindow,"PrimaryButton").IsEnabled,"Expired repair became executable.");
  Find<Button>(expiredWindow,"BackButton").RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
  Check(Find<StackPanel>(expiredWindow,"FilesPane").Visibility==Visibility.Visible&&Find<CheckBox>(expiredWindow,"ConfirmDestination").IsChecked==false,"Back retained stale repair confirmation.");expiredWindow.Close();

  var delayed=new FakeRepair{ReviewPending=new(TaskCreationOptions.RunContinuationsAsynchronously)};var wait=RepairWindow(delayed);var first=wait.AdvanceAsync();Complete(wait.AdvanceAsync());
  Check(delayed.Reviews==1,"Duplicate repair review started.");wait.Close();Complete(first);Check(delayed.Cancelled&&delayed.Repairs==0,"Close did not stop pending repair review.");

  foreach(bool committed in new[]{false,true}){
   var active=new FakeRepair{RepairPending=new(TaskCreationOptions.RunContinuationsAsynchronously),CompleteOnCancel=committed};var pending=RepairWindow(active);
   Complete(pending.AdvanceAsync());Find<CheckBox>(pending,"ConfirmDestination").IsChecked=true;var running=pending.AdvanceAsync();Complete(pending.AdvanceAsync());
   Check(active.Repairs==1,"Duplicate repair started.");pending.Close();Complete(running);
   Check(active.Cancelled&&Find<TextBlock>(pending,"ResultTitle").Text==(committed?"Services repaired":"Repair stopped"),"Close lost the confirmed/unconfirmed repair distinction.");
  }
  var failed=new FakeRepair{Fail=true};var failure=RepairWindow(failed);Complete(failure.AdvanceAsync());Find<CheckBox>(failure,"ConfirmDestination").IsChecked=true;Complete(failure.AdvanceAsync());
  Check(Find<TextBlock>(failure,"ResultTitle").Text=="Repair needs attention","Failed repair displayed success.");Render(failure,"obli-repair-failure.png");failure.Close();
  Reject(()=>new InstallerEngine().ReviewRepairAsync(new RepairInput(@"C:\fixture\release.zip"),CancellationToken.None).GetAwaiter().GetResult());
  Console.WriteLine("PASS: repair review/result contracts, explicit confirmation, expiry/back, duplicate prevention, close cancellation, late committed receipt, failed outcome and native repair rendering.");
 }
 sealed class FakeRepair:IServiceRepairEngine {
  public int Reviews,Repairs;public bool Cancelled,Fail,Expired,CompleteOnCancel;
  public TaskCompletionSource<RepairReview>? ReviewPending;public TaskCompletionSource? RepairPending;
  public async Task<RepairReview> ReviewRepairAsync(RepairInput input,CancellationToken token){
   Reviews++;try{var review=ReviewPending!=null?await ReviewPending.Task.WaitAsync(token):RepairReview.Parse(RepairSample());return Expired?review with{Expires=DateTimeOffset.UtcNow.AddSeconds(-1)}:review;}catch(OperationCanceledException){Cancelled=true;throw;}
  }
  public async Task RepairAsync(RepairInput input,RepairReview review,CancellationToken token){
   Repairs++;if(Fail)throw new InvalidOperationException("Installed details changed. Review the connection again before retrying.");
   try{if(RepairPending!=null)await RepairPending.Task.WaitAsync(token);}catch(OperationCanceledException){Cancelled=true;if(!CompleteOnCancel)throw;}
  }
 }
}
