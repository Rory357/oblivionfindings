using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using Microsoft.Win32.SafeHandles;
namespace Oblivion.Setup;

// Hold every ordinary local ancestor against rename, and the exact backend
// against replacement/writing for its entire execution. Trust is compiled in.
internal sealed class BackendLease:IDisposable {
 readonly List<SafeFileHandle> directories=new();FileStream? file;
 public string PathName{get;}
 BackendLease(string path){PathName=path;}
 public static BackendLease Open() {
  string expected=typeof(BackendLease).Assembly.GetCustomAttributes<AssemblyMetadataAttribute>().Single(a=>a.Key=="OblivionSetupBackendSHA256").Value??"";
  return OpenAt(Path.Combine(AppContext.BaseDirectory,"oblivion-setup-core.exe"),expected);
 }
 internal static BackendLease OpenAt(string filename,string expected) {
  if(!System.Text.RegularExpressions.Regex.IsMatch(expected,"^[a-fA-F0-9]{64}$"))throw new InvalidOperationException("This development installer has no approved installation engine.");
  string path=Path.GetFullPath(filename);
  if(path.Length<4||path[1]!=':'||path[2]!='\\'||!char.IsAsciiLetter(path[0])||path[3..].Contains(':'))throw new InvalidOperationException("Use an ordinary local installer folder.");
  var lease=new BackendLease(path);
  try {
   string current=Path.GetPathRoot(path)!;
   lease.directories.Add(OpenHandle(current,true));
   foreach(string part in Path.GetDirectoryName(path)![current.Length..].Split(Path.DirectorySeparatorChar,StringSplitOptions.RemoveEmptyEntries)){current=Path.Combine(current,part);lease.directories.Add(OpenHandle(current,true));}
   lease.file=new FileStream(OpenHandle(path,false),FileAccess.Read);
   if(lease.file.Length<1||lease.file.Length>268435456||!CryptographicOperations.FixedTimeEquals(SHA256.HashData(lease.file),Convert.FromHexString(expected)))throw new InvalidOperationException("The installation engine changed. Download the installer again.");
   return lease;
  }catch{lease.Dispose();throw;}
 }
 static SafeFileHandle OpenHandle(string path,bool directory) {
  var handle=CreateFileW(path,0x80000000u,directory?3u:1u,IntPtr.Zero,3,0x00200000u|(directory?0x02000000u:0u),IntPtr.Zero);
  if(handle.IsInvalid){handle.Dispose();throw new IOException("The installer files cannot be held safely.");}
  if(GetFileType(handle)!=1||!GetFileInformationByHandle(handle,out var info)||(info.Attributes&0x400)!=0||((info.Attributes&0x10)!=0)!=directory||(!directory&&info.Links!=1)){handle.Dispose();throw new IOException("Installer links or special files are not supported.");}
  return handle;
 }
 public void Dispose(){file?.Dispose();for(int i=directories.Count-1;i>=0;i--)directories[i].Dispose();directories.Clear();}
 [StructLayout(LayoutKind.Sequential)]struct FileInfo{public uint Attributes,CreationLow,CreationHigh,AccessLow,AccessHigh,WriteLow,WriteHigh,Volume,SizeHigh,SizeLow,Links,IndexHigh,IndexLow;}
 [DllImport("kernel32.dll",CharSet=CharSet.Unicode,SetLastError=true)]static extern SafeFileHandle CreateFileW(string path,uint access,uint share,IntPtr security,uint disposition,uint flags,IntPtr template);
 [DllImport("kernel32.dll",SetLastError=true)][return:MarshalAs(UnmanagedType.Bool)]static extern bool GetFileInformationByHandle(SafeFileHandle handle,out FileInfo info);
 [DllImport("kernel32.dll",SetLastError=true)]static extern uint GetFileType(SafeFileHandle handle);
}
