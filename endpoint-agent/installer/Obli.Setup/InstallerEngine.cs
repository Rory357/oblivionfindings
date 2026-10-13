using System.Diagnostics;
using System.Text;
using System.Text.Json;
namespace Oblivion.Setup;
public sealed partial class InstallerEngine:IInstallerEngine,IServiceRepairEngine {
 public async Task<InstallationReview> ReviewAsync(InstallationInput input,CancellationToken cancellation)=>InstallationReview.Parse(await RunAsync(input.Arguments("review"),false,cancellation),input.Role);
 public async Task InstallAsync(InstallationInput input,InstallationReview review,CancellationToken cancellation) {
  string text=await RunAsync(review.InstallArguments(input),true,cancellation);
  using var doc=JsonDocument.Parse(text,new JsonDocumentOptions{MaxDepth=3});
  if(doc.RootElement.GetProperty("phase").GetString()!="installed"||doc.RootElement.GetProperty("recovery_required").GetBoolean())throw new InvalidOperationException("Installation could not be confirmed. Ask your IT team to review the installation record.");
 }
 static async Task<string> RunAsync(IReadOnlyList<string> args,bool installing,CancellationToken cancellation,bool repairing=false) {
  cancellation.ThrowIfCancellationRequested();
  using var lease=await Task.Run(BackendLease.Open,cancellation);
  cancellation.ThrowIfCancellationRequested();
  var info=new ProcessStartInfo(lease.PathName){UseShellExecute=false,CreateNoWindow=true,RedirectStandardInput=true,RedirectStandardOutput=true,RedirectStandardError=true,WorkingDirectory=AppContext.BaseDirectory,StandardOutputEncoding=new UTF8Encoding(false,true),StandardErrorEncoding=new UTF8Encoding(false,true)};
  foreach(string arg in args)info.ArgumentList.Add(arg);
  info.Environment.Clear();string windows=Environment.GetFolderPath(Environment.SpecialFolder.Windows);info.Environment["SystemRoot"]=windows;info.Environment["WINDIR"]=windows;info.Environment["PATH"]=Path.Combine(windows,"System32");
  using var process=new Process{StartInfo=info};
  if(!process.Start())throw new InvalidOperationException("The installation engine could not start.");
  void Stop(){try{process.StandardInput.Close();if(!installing&&!process.HasExited)process.Kill(true);}catch(InvalidOperationException){}catch(IOException){}}
  using var deadline=new CancellationTokenSource(TimeSpan.FromMinutes(15));
  using var linked=CancellationTokenSource.CreateLinkedTokenSource(cancellation,deadline.Token);
  using var registration=linked.Token.Register(Stop);
  var output=ReadBounded(process.StandardOutput,16384,Stop);var errors=ReadBounded(process.StandardError,4096,Stop);
  try {
   await process.WaitForExitAsync().WaitAsync(TimeSpan.FromMinutes(17));
   string stdout=await output,stderr=await errors;
   if(process.ExitCode!=0)throw new InvalidOperationException(SafeMessage(stderr,installing,repairing));
   if(!installing)cancellation.ThrowIfCancellationRequested();return stdout;
  }catch(TimeoutException){Stop();if(!process.HasExited){process.Kill(true);await process.WaitForExitAsync();}throw new InvalidOperationException(repairing?"The repair engine stopped responding. Ask your IT team to review the repair record before retrying.":"The installation engine stopped responding. Ask your IT team to review the installation before retrying.");}
  finally{Stop();}
 }
 static string SafeMessage(string text,bool installing,bool repairing){string value=text.Trim();return value.Length is >0 and <=1000&&!value.Any(c=>char.IsControl(c)||char.GetUnicodeCategory(c)==System.Globalization.UnicodeCategory.Format)?value:repairing?"Service repair could not be confirmed. Ask your IT team to review the installed connection and repair record.":installing?"Installation stopped. Ask your IT team to review the installation record.":"The files could not be reviewed. Download the intended installation package and profiles again.";}
 internal static async Task<string> ReadBounded(StreamReader reader,int limit,Action stop) {
  var text=new StringBuilder();var buffer=new char[1024];bool oversized=false;int count;
  try{while((count=await reader.ReadAsync(buffer))>0){if(text.Length+count<=limit&&!oversized)text.Append(buffer,0,count);else{oversized=true;stop();}}
  if(oversized)throw new InvalidDataException("The installation engine returned too much data.");return text.ToString();}catch{stop();throw;}
 }
}
