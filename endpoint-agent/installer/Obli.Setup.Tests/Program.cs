using System.IO;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using Oblivion.Setup;
static partial class Program {
 static void Check(bool value,string message){if(!value)throw new Exception(message);}
 static void Reject(Action action){try{action();}catch(Exception e)when(e is not OutOfMemoryException){return;}throw new Exception("Unsafe input accepted.");}
 static string Sample(string role="both")=>JsonSerializer.Serialize(new{schema=1,role,version="1.2.3",revision=7,architecture="amd64",organisation="Example Care",computer=role=="site"?"":"Reception PC",site=role=="computer"?"":"Main office",server_url="https://care.example.test",collector_url=role=="computer"?"":"https://collectors.example.test",expires_at=DateTimeOffset.UtcNow.AddMinutes(10).ToString("O"),review_digest=new string('a',64)});
 static T Find<T>(SetupWindow window,string name)where T:class=>(T)window.FindName(name);
 static void Fill(SetupWindow w,int role=2){Find<ComboBox>(w,"RoleChoice").SelectedIndex=role;Find<TextBox>(w,"PackagePath").Text=@"C:\fixture\release.zip";if(role!=1)Find<TextBox>(w,"ComputerPath").Text=@"C:\fixture\computer.json";if(role!=0)Find<TextBox>(w,"CollectorPath").Text=@"C:\fixture\site.json";}
 static void Complete(Task task){if(!task.IsCompleted){var frame=new DispatcherFrame();var dispatcher=Dispatcher.CurrentDispatcher;task.ContinueWith(_=>dispatcher.BeginInvoke(new Action(()=>frame.Continue=false)));Dispatcher.PushFrame(frame);}task.GetAwaiter().GetResult();}
 static void Render(SetupWindow window,string file,int width=740,int height=690){
  string? folder=Environment.GetEnvironmentVariable("OBLI_SETUP_RENDER");if(string.IsNullOrEmpty(folder))return;Directory.CreateDirectory(folder);
  Find<Oblivion.Obli.ObliOrb>(window,"Orb").SetMotion(false);
  var root=(Grid)window.Content;root.Background=(Brush)window.FindResource("BackgroundBrush");root.Measure(new Size(width,height));root.Arrange(new Rect(0,0,width,height));root.UpdateLayout();
  var bitmap=new RenderTargetBitmap(width,height,96,96,PixelFormats.Pbgra32);bitmap.Render(root);var png=new PngBitmapEncoder();png.Frames.Add(BitmapFrame.Create(bitmap));using var stream=File.Create(Path.Combine(folder,file));png.Save(stream);
 }
 [STAThread]static int Main(){try{
  var app=new Application{ShutdownMode=ShutdownMode.OnExplicitShutdown};
  LeaseChecks();
  RepairChecks();
  foreach(byte[] bytes in new[]{System.Text.Encoding.UTF8.GetBytes(new string('a',33)),new byte[]{255}}){bool stopped=false;using var stream=new MemoryStream(bytes);using var reader=new StreamReader(stream,new System.Text.UTF8Encoding(false,true));Reject(()=>InstallerEngine.ReadBounded(reader,32,()=>stopped=true).GetAwaiter().GetResult());Check(stopped,"Invalid engine output did not stop the controller.");}
  string? goReview=Environment.GetEnvironmentVariable("OBLI_SETUP_GO_REVIEW");if(!string.IsNullOrEmpty(goReview)){var actual=InstallationReview.Parse(File.ReadAllText(goReview),"computer");Check(actual.Computer=="Reception PC"&&actual.Server=="https://care.example.test","Real Go review not understood.");}
  var sample=Sample();var review=InstallationReview.Parse(sample,"both");Check(review.IsCurrent&&review.Site=="Main office","Review parse failed.");
  foreach(string invalid in new[]{sample.Replace("\"schema\":1","\"schema\":1,\"schema\":1"),sample.Replace("\"schema\":1","\"schema\":1,\"token\":\"secret\""),sample.Replace("https://care.example.test","http://care.example.test"),sample.Replace("https://care.example.test","https://staff:secret@care.example.test"),sample.Replace("\"role\":\"both\"","\"role\":\"site\""),sample.Replace(new string('a',64),"invalid")})Reject(()=>InstallationReview.Parse(invalid,"both"));
  var input=new InstallationInput("both",@"C:\fixture\release.zip",@"C:\fixture\computer.json",@"C:\fixture\site.json");
  var args=review.InstallArguments(input);Check(args.Contains("--review-digest")&&args.Contains(review.Digest)&&args.Contains("--control-stdin")&&args.Contains("--confirm-collector-server"),"Install confirmation missing.");
  Reject(()=> (review with{Expires=DateTimeOffset.UtcNow.AddSeconds(-1)}).InstallArguments(input));
  Reject(()=>new InstallationInput("computer",input.Package,input.ComputerProfile,input.CollectorProfile).Arguments("review"));
  var fake=new FakeEngine();var window=new SetupWindow(fake);Fill(window);Render(window,"obli-setup-files.png");
  Complete(window.AdvanceAsync());Check(fake.Reviews==1&&Find<StackPanel>(window,"ReviewPane").Visibility==Visibility.Visible,"Review not shown.");
  Check(!Find<Button>(window,"PrimaryButton").IsEnabled,"Install available before confirmation.");
  Complete(window.AdvanceAsync());Check(fake.Installs==0,"Unconfirmed install ran.");
  Render(window,"obli-setup-review.png");Render(window,"obli-setup-review-narrow.png",520,680);
  Find<CheckBox>(window,"ConfirmDestination").IsChecked=true;Complete(window.AdvanceAsync());Check(fake.Installs==1&&Find<TextBlock>(window,"ResultTitle").Text=="Installation completed","Confirmed install result missing.");Render(window,"obli-setup-result.png");window.Close();
  var delayed=new FakeEngine{Pending=new(TaskCreationOptions.RunContinuationsAsynchronously)};var waiting=new SetupWindow(delayed);Fill(waiting);var first=waiting.AdvanceAsync();Complete(waiting.AdvanceAsync());Check(delayed.Reviews==1,"Duplicate review started.");
  waiting.Close();Complete(first);Check(delayed.Cancelled&&delayed.Installs==0,"Close did not cancel pending review.");
  var stopping=new FakeEngine{InstallPending=new(TaskCreationOptions.RunContinuationsAsynchronously)};var stoppingWindow=new SetupWindow(stopping);Fill(stoppingWindow);Complete(stoppingWindow.AdvanceAsync());Find<CheckBox>(stoppingWindow,"ConfirmDestination").IsChecked=true;var activeInstall=stoppingWindow.AdvanceAsync();Complete(stoppingWindow.AdvanceAsync());Check(stopping.Installs==1,"Duplicate install started.");stoppingWindow.Close();Complete(activeInstall);Check(stopping.InstallCancelled,"Closing installer did not cancel the active engine.");
  var failed=new FakeEngine{FailInstall=true};var failure=new SetupWindow(failed);Fill(failure);Complete(failure.AdvanceAsync());Find<CheckBox>(failure,"ConfirmDestination").IsChecked=true;Complete(failure.AdvanceAsync());Check(Find<TextBlock>(failure,"ResultTitle").Text=="Installation needs attention","Failure became success.");Render(failure,"obli-setup-failure.png");failure.Close();
  Reject(()=>new InstallerEngine().ReviewAsync(input,CancellationToken.None).GetAwaiter().GetResult());
  Console.WriteLine("PASS: strict review, role/profile bounds, explicit confirmation, duplicate prevention, close cancellation, failure state, unconfigured engine rejection, native Orbit rendering.");app.Shutdown();return 0;
 }catch(Exception e){Console.Error.WriteLine(e);return 1;}}
 static void LeaseChecks() {
  string root=Path.Combine(Path.GetTempPath(),"obli-installer-check-"+Guid.NewGuid().ToString("N"));Directory.CreateDirectory(root);
  try{string file=Path.Combine(root,"oblivion-setup-core.exe");File.WriteAllText(file,"nonexecutable fixture");
   string hash=Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(File.ReadAllBytes(file)));
   using(var lease=BackendLease.OpenAt(file,hash)){Reject(()=>File.WriteAllText(file,"changed"));Reject(()=>Directory.Move(root,root+"-moved"));}
   Reject(()=>BackendLease.OpenAt(file,new string('0',64)));File.WriteAllText(file,"changed");Reject(()=>BackendLease.OpenAt(file,hash));
   Reject(()=>BackendLease.OpenAt(file,"unconfigured"));Check(!Directory.Exists(root+"-moved"),"Locked backend parent moved.");
  }finally{Directory.Delete(root,true);}
 }
 sealed class FakeEngine:IInstallerEngine {
  public int Reviews,Installs;public bool Cancelled,FailInstall,InstallCancelled;public TaskCompletionSource<InstallationReview>? Pending;public TaskCompletionSource? InstallPending;
  public async Task<InstallationReview> ReviewAsync(InstallationInput input,CancellationToken token){Reviews++;try{return Pending!=null?await Pending.Task.WaitAsync(token):InstallationReview.Parse(Sample(input.Role),input.Role);}catch(OperationCanceledException){Cancelled=true;throw;}}
  public async Task InstallAsync(InstallationInput input,InstallationReview review,CancellationToken token){Installs++;if(FailInstall)throw new InvalidOperationException("Installation stopped; existing identity was retained. Contact your IT team before retrying.");try{if(InstallPending!=null)await InstallPending.Task.WaitAsync(token);}catch(OperationCanceledException){InstallCancelled=true;throw;}}
 }
}
