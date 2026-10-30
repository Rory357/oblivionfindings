using System;
using System.Buffers.Binary;
using System.ComponentModel;
using System.IO;
using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.Win32.SafeHandles;

namespace Oblivion.Obli;

// A local pipe name alone is not proof of the installed service's identity.
// Check the connected process against SCM before sending any request.
public sealed class ServiceClient : IDesktopChannel
{
 const string PipePath=@"\\.\pipe\Oblivion.Obli.v1";
 public async Task<AgentStatus> ReadStatusAsync(CancellationToken cancellation) => StatusProtocol.Parse(await ReadAsync("status",cancellation));
 public async Task<DiagnosticSnapshot?> ReadDiagnosticsAsync(Uri expectedOrigin,CancellationToken cancellation) => DiagnosticSnapshot.ParseEnvelope(await ReadAsync("diagnostics",cancellation),DateTimeOffset.UtcNow,expectedOrigin);
 public async Task<RemoteState> ReadRemoteAsync(Uri origin,CancellationToken cancellation)=>RemoteState.Parse(await SendAsync(new{version=1,operation="remote_poll"},cancellation),origin,DateTimeOffset.UtcNow);
 public async Task<RemoteState> DecideRemoteAsync(Uri origin,RemoteState state,string decision,CancellationToken cancellation)=>RemoteState.Parse(await SendAsync(new{version=1,operation="remote_decide",session_uuid=state.Id,consent_token=state.Token,decision},cancellation),origin,DateTimeOffset.UtcNow);
 public async Task<RemoteState> StopRemoteAsync(Uri origin,string id,CancellationToken cancellation)=>RemoteState.Parse(await SendAsync(new{version=1,operation="remote_stop",session_uuid=id},cancellation),origin,DateTimeOffset.UtcNow);
 public async Task<bool> ConfirmTicketComputerAsync(string challenge,Uri expectedOrigin,CancellationToken cancellation)=>TicketComputerProtocol.Parse(await SendOnPipeAsync(PipePath,TicketComputerProtocol.Request(challenge,expectedOrigin),512,4096,TimeSpan.FromSeconds(8),cancellation));
 public async Task<ServiceActionState> GetServiceActionAsync(Uri expectedOrigin,CancellationToken cancellation)=>ServiceActionProtocol.Parse(await SendOnPipeAsync(PipePath,ServiceActionProtocol.Poll(expectedOrigin),512,16384,TimeSpan.FromSeconds(4),cancellation),expectedOrigin,DateTimeOffset.UtcNow);
 public async Task<ServiceActionState> AnswerServiceActionAsync(Uri origin,ServiceActionState state,string decision,CancellationToken cancellation)=>ServiceActionProtocol.ParseAnswer(await SendOnPipeAsync(PipePath,ServiceActionProtocol.Answer(origin,state,decision),512,16384,TimeSpan.FromSeconds(4),cancellation),origin,state,DateTimeOffset.UtcNow);
 public async Task<RestartState> GetRestartAsync(Uri origin,CancellationToken cancellation)=>RestartProtocol.Parse(await SendOnPipeAsync(PipePath,RestartProtocol.Poll(origin),512,16384,TimeSpan.FromSeconds(4),cancellation),origin,DateTimeOffset.UtcNow);
 public async Task<RestartState> MarkRestartVisibleAsync(Uri origin,RestartState state,string visibility,CancellationToken cancellation)=>RestartProtocol.ParseReply(await SendOnPipeAsync(PipePath,RestartProtocol.Message(origin,state,visibility,true),512,16384,TimeSpan.FromSeconds(4),cancellation),origin,state,DateTimeOffset.UtcNow);
 public async Task<RestartState> AnswerRestartAsync(Uri origin,RestartState state,string decision,CancellationToken cancellation)=>RestartProtocol.ParseReply(await SendOnPipeAsync(PipePath,RestartProtocol.Message(origin,state,decision,false),512,16384,TimeSpan.FromSeconds(4),cancellation),origin,state,DateTimeOffset.UtcNow);
 public async Task<SoftwareState> GetSoftwareAsync(Uri origin,CancellationToken cancellation)=>SoftwareProtocol.Parse(await SendOnPipeAsync(PipePath,SoftwareProtocol.Poll(origin),512,32768,TimeSpan.FromSeconds(4),cancellation),origin,DateTimeOffset.UtcNow);
 public async Task<SoftwareState> AnswerSoftwareAsync(Uri origin,SoftwareState state,string decision,CancellationToken cancellation)=>SoftwareProtocol.ParseReply(await SendOnPipeAsync(PipePath,SoftwareProtocol.Answer(origin,state,decision),512,32768,TimeSpan.FromSeconds(4),cancellation),origin,state,DateTimeOffset.UtcNow);
 Task<byte[]> ReadAsync(string operation,CancellationToken cancellation)=>SendAsync(new{version=1,operation},cancellation);
 Task<byte[]> SendAsync(object request,CancellationToken cancellation)=>SendOnPipeAsync(PipePath,request,512,4096,TimeSpan.FromSeconds(4),cancellation);
 public Task<byte[]> ExchangeDesktopAsync(object request,CancellationToken cancellation)=>SendOnPipeAsync(@"\\.\pipe\Oblivion.Obli.Desktop.v1",request,720*1024,32768,TimeSpan.FromSeconds(2),cancellation);
 async Task<byte[]> SendOnPipeAsync(string path,object request,int maximumRequest,int maximumResponse,TimeSpan timeout,CancellationToken cancellation)
 {
  using var deadline=CancellationTokenSource.CreateLinkedTokenSource(cancellation);deadline.CancelAfter(timeout);
  SafePipeHandle handle;
  while(true)
  {
   deadline.Token.ThrowIfCancellationRequested();
   handle=CreateFile(path,0x12019b,0,IntPtr.Zero,3,0x40110000,IntPtr.Zero);
   if(!handle.IsInvalid)break;
   int error=Marshal.GetLastWin32Error();handle.Dispose();
   if(error!=231)throw new IOException("Computer support service is unavailable.");
   await Task.Delay(100,deadline.Token);
  }
  using(handle)
  {
   VerifyServicePeer(handle);
   using var pipe=new NamedPipeClientStream(PipeDirection.InOut,true,true,handle);
   byte[] body=System.Text.Json.JsonSerializer.SerializeToUtf8Bytes(request);
   if(body.Length>maximumRequest)throw new InvalidDataException();
   var frame=new byte[4+body.Length];BinaryPrimitives.WriteUInt32LittleEndian(frame,(uint)body.Length);body.CopyTo(frame,4);
   await pipe.WriteAsync(frame,deadline.Token);
   var header=new byte[4];await pipe.ReadExactlyAsync(header,deadline.Token);
   uint size=BinaryPrimitives.ReadUInt32LittleEndian(header);
   if(size==0||size>maximumResponse)throw new InvalidDataException();
   var response=new byte[(int)size];await pipe.ReadExactlyAsync(response,deadline.Token);return response;
  }
 }
 static void VerifyServicePeer(SafePipeHandle pipe)
 {
  if(!GetNamedPipeServerProcessId(pipe,out uint pipeProcess)||pipeProcess==0)throw new IOException("Unable to verify computer support.");
  using var manager=OpenSCManager(null,null,1);
  if(manager.IsInvalid)throw new IOException("Unable to verify computer support.");
  using var service=OpenService(manager,"OblivionAgent",4);
  if(service.IsInvalid || !QueryServiceStatusEx(service,0,out var status,Marshal.SizeOf<ServiceStatus>(),out _) || status.CurrentState!=4 || status.ProcessId!=pipeProcess)throw new IOException("The local connection is not the registered Oblivion service.");
 }
 [StructLayout(LayoutKind.Sequential)]struct ServiceStatus { public uint ServiceType,CurrentState,ControlsAccepted,Win32ExitCode,ServiceSpecificExitCode,CheckPoint,WaitHint,ProcessId,ServiceFlags; }
 sealed class ServiceHandle:SafeHandleZeroOrMinusOneIsInvalid
 {
  public ServiceHandle():base(true){}
  protected override bool ReleaseHandle()=>CloseServiceHandle(handle);
 }
 [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true,EntryPoint="CreateFileW")]static extern SafePipeHandle CreateFile(string name,uint access,uint sharing,IntPtr security,uint disposition,uint flags,IntPtr template);
 [DllImport("kernel32.dll",SetLastError=true)][return:MarshalAs(UnmanagedType.Bool)]static extern bool GetNamedPipeServerProcessId(SafePipeHandle pipe,out uint process);
 [DllImport("advapi32.dll",CharSet=CharSet.Unicode,SetLastError=true,EntryPoint="OpenSCManagerW")]static extern ServiceHandle OpenSCManager(string? machine,string? database,uint access);
 [DllImport("advapi32.dll",CharSet=CharSet.Unicode,SetLastError=true,EntryPoint="OpenServiceW")]static extern ServiceHandle OpenService(ServiceHandle manager,string name,uint access);
 [DllImport("advapi32.dll",SetLastError=true)][return:MarshalAs(UnmanagedType.Bool)]static extern bool QueryServiceStatusEx(ServiceHandle service,int level,out ServiceStatus status,int size,out int needed);
 [DllImport("advapi32.dll")][return:MarshalAs(UnmanagedType.Bool)]static extern bool CloseServiceHandle(IntPtr service);
}

