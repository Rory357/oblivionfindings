using System;
using System.Collections.Generic;
using System.IO;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

internal sealed record DesktopInput(ulong Sequence,string Type,int X=0,int Y=0,int Button=0,int Key=0,int Delta=0)
{
 internal static DesktopInput Parse(JsonElement value)
 {
  var fields=DesktopGrant.Fields(value);
  int Number(string name)=>fields[name].GetInt32();
  if(Number("version")!=1)throw new InvalidDataException();
  ulong sequence=fields["sequence"].GetUInt64();
  string kind=fields["type"].GetString()??"";
  string[] extra=kind switch{
   "move"=>new[]{"x","y"},"button_down" or "button_up"=>new[]{"x","y","button"},
   "key_down" or "key_up"=>new[]{"key"},"wheel"=>new[]{"x","y","delta"},_=>throw new InvalidDataException()};
  if(sequence==0||sequence>9007199254740991||fields.Count!=3+extra.Length)throw new InvalidDataException();
  foreach(string key in extra)if(!fields.ContainsKey(key))throw new InvalidDataException();
  int x=fields.ContainsKey("x")?Number("x"):0,y=fields.ContainsKey("y")?Number("y"):0;
  int button=fields.ContainsKey("button")?Number("button"):0,keyCode=fields.ContainsKey("key")?Number("key"):0,delta=fields.ContainsKey("delta")?Number("delta"):0;
  if(x<0||x>65535||y<0||y>65535||button<0||button>2||
   ((kind is "key_down" or "key_up")&&(keyCode<1||keyCode>254))||(kind=="wheel"&&(delta==0||Math.Abs(delta)>1200)))throw new InvalidDataException();
  return new(sequence,kind,x,y,button,keyCode,delta);
 }
}
internal sealed record DesktopGrant(string Status,string Session,string Desktop,string Token,string Access,DateTimeOffset Expires,IReadOnlyList<DesktopInput> Inputs)
{
 internal static Dictionary<string,JsonElement> Fields(JsonElement value)
 {
  var fields=new Dictionary<string,JsonElement>(StringComparer.Ordinal);
  foreach(var field in value.EnumerateObject())
   if(field.Value.ValueKind==JsonValueKind.Null||!fields.TryAdd(field.Name,field.Value))throw new InvalidDataException();
  return fields;
 }
 internal static DesktopGrant Parse(ReadOnlyMemory<byte> bytes,Uri origin,string session,string access,DateTimeOffset now)
 {
  if(bytes.Length==0||bytes.Length>32768)throw new InvalidDataException();
  using var document=JsonDocument.Parse(bytes,new JsonDocumentOptions{MaxDepth=4});
  var f=Fields(document.RootElement);
  string[] names={"protocol","server_origin","status","session_uuid","desktop_session","access_mode","worker_token","expires_at","inputs"};
  if(f.Count!=names.Length)throw new InvalidDataException();
  foreach(string name in names)if(!f.ContainsKey(name))throw new InvalidDataException();
  string Text(string name)=>f[name].GetString()??throw new InvalidDataException();
  if(f["protocol"].GetInt32()!=1||!Uri.TryCreate(Text("server_origin"),UriKind.Absolute,out var server)||
   server.Scheme!="https"||server.UserInfo!=""||server.AbsolutePath!="/"||server.Query!=""||server.Fragment!=""||
   server.GetLeftPart(UriPartial.Authority)!=origin.GetLeftPart(UriPartial.Authority)||Text("session_uuid")!=session||!Guid.TryParseExact(session,"D",out _)||
   Text("access_mode")!=access||access is not ("view" or "control")||!Guid.TryParseExact(Text("desktop_session"),"D",out _))throw new InvalidDataException();
  string state=Text("status"),token=Text("worker_token");
  if(state is not ("waiting" or "starting" or "active"))throw new InvalidDataException();
  if(state=="waiting"?token!="":!Regex.IsMatch(token,"\\A[a-f0-9]{64}\\z"))throw new InvalidDataException();
  if(!DateTimeOffset.TryParseExact(Text("expires_at"),"yyyy-MM-ddTHH:mm:ssZ",System.Globalization.CultureInfo.InvariantCulture,System.Globalization.DateTimeStyles.AssumeUniversal,out var expires)||
   expires<=now||expires>now.AddSeconds(state=="waiting"?15:2))throw new InvalidDataException();
  var events=new List<DesktopInput>();
  foreach(var item in f["inputs"].EnumerateArray()){if(events.Count==32)throw new InvalidDataException();events.Add(DesktopInput.Parse(item));}
  if(events.Count>0&&(state!="active"||access!="control"))throw new InvalidDataException();
  return new(state,session,Text("desktop_session"),token,access,expires,events);
 }
}
internal interface IDesktopChannel
{
 Task<byte[]> ExchangeDesktopAsync(object request,CancellationToken cancellation);
}
internal sealed record DesktopFrame(byte[] Jpeg,int Width,int Height);
internal interface IDesktopBackend
{
 bool CanUse();
 DesktopFrame Capture();
 void Apply(DesktopInput input);
 void Release();
}
