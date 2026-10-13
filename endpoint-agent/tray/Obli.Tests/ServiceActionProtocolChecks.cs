using System;
using System.IO;
using System.Text.Json;
using System.Text;
using System.Linq;
using Oblivion.Obli;
internal static class ServiceActionProtocolChecks
{
 internal static int Run()
 {
  var origin=new Uri("https://localhost");var now=DateTimeOffset.UtcNow;
  byte[] Response(string status="requested",string hash="",DateTimeOffset? expiry=null)=>JsonSerializer.SerializeToUtf8Bytes(new{protocol=1,server_origin=origin.AbsoluteUri,status,command_uuid=Guid.Parse("bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb").ToString("D"),attempt_uuid=Guid.Parse("cccccccc-cccc-4ccc-8ccc-cccccccccccc").ToString("D"),payload_hash=hash==""?new string('a',64):hash,consent_token=status=="requested"?new string('b',64):"",policy_version=1,technician="Alex",purpose="Restore the approved service",service_name="Organisation App 2",operation="restart",expires_at=(expiry??now.AddMinutes(1)).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),reason_code=status=="uncertain"?"interrupted":"",mutation_started=status=="uncertain"});
  var state=ServiceActionProtocol.Parse(Response(),origin,now);
  if(state.Status!="requested"||state.ServiceName!="Organisation App 2"||state.ToString().Contains("Restore"))throw new Exception("Action consent contract changed.");
  ServiceActionProtocol.Answer(origin,state,"allow");
  ServiceActionProtocol.ParseAnswer(Response(),origin,state,now);
  void Denied(Action operation){try{operation();}catch(InvalidDataException){return;}throw new Exception("Unsafe action state accepted.");}
  Denied(()=>ServiceActionProtocol.Parse(Response(hash:new string('A',64)),origin,now));
  Denied(()=>ServiceActionProtocol.Parse(Response(expiry:now.AddMinutes(-1)),origin,now));
  Denied(()=>ServiceActionProtocol.Parse(Response(),new Uri("https://other.example"),now));
  Denied(()=>ServiceActionProtocol.Answer(origin,state with { PayloadHash="wrong" },"allow"));
  Denied(()=>ServiceActionProtocol.ParseAnswer(Response(),origin,state with { AttemptId=Guid.NewGuid() },now));
  var terminal=ServiceActionProtocol.Parse(Response("uncertain",expiry:now.AddMinutes(-2)),origin,now);
  if(!terminal.Reason.Contains("unconfirmed"))throw new Exception("Action result exposed unsafe text.");
  Denied(()=>ServiceActionProtocol.Answer(origin,terminal,"allow"));
  string valid=Encoding.UTF8.GetString(Response());
  foreach(string bad in new[]{valid.Replace("\"protocol\":1", "\"protocol\":1,\"protocol\":1"),valid.Replace("\"protocol\":1", "\"protocol\":null"),valid.Replace("\"protocol\":1,", ""),valid.Replace("\"protocol\":1", "\"protocol\":1,\"device_id\":123"),valid.Replace("Organisation App 2",new string('x',201))})Denied(()=>ServiceActionProtocol.Parse(Encoding.UTF8.GetBytes(bad),origin,now));
  Denied(()=>ServiceActionProtocol.Parse(new byte[16385],origin,now));
  string international=valid.Replace("Alex",string.Concat(Enumerable.Repeat("😀",200))).Replace("Restore the approved service",string.Concat(Enumerable.Repeat("😀",499))+"<");
  using var internationalDocument=JsonDocument.Parse(international);byte[] internationalBytes=JsonSerializer.SerializeToUtf8Bytes(internationalDocument.RootElement);
  if(internationalBytes.Length<=4096)throw new Exception("International fixture did not exercise encoded byte bounds.");
  if(ServiceActionProtocol.Parse(internationalBytes,origin,now).Purpose.EnumerateRunes().Count()!=500)throw new Exception("International consent text rejected.");
  string maxName=new string('x',200);
  if(ServiceActionProtocol.Parse(Encoding.UTF8.GetBytes(valid.Replace("Organisation App 2",maxName)),origin,now).ServiceName!=maxName)throw new Exception("Valid service name bound changed.");
  using(var poll=JsonDocument.Parse(JsonSerializer.SerializeToUtf8Bytes(ServiceActionProtocol.Poll(origin))))if(poll.RootElement.EnumerateObject().Count()!=3)throw new Exception("Poll contract changed.");
  using(var answer=JsonDocument.Parse(JsonSerializer.SerializeToUtf8Bytes(ServiceActionProtocol.Answer(origin,state,"decline"))))if(answer.RootElement.EnumerateObject().Count()!=7||answer.RootElement.GetProperty("attempt_uuid").GetString()!=state.AttemptId?.ToString("D"))throw new Exception("Answer contract changed.");
  string? exported=Environment.GetEnvironmentVariable("OBLI_ACTION_LOCAL_VIEW");
  if(!string.IsNullOrEmpty(exported)){byte[] actual=File.ReadAllBytes(exported);using var actualJson=JsonDocument.Parse(actual);var at=DateTimeOffset.Parse(actualJson.RootElement.GetProperty("expires_at").GetString()!).AddSeconds(-1);var actualState=ServiceActionProtocol.Parse(actual,origin,at);if(actualState.Status!="requested")throw new Exception("Actual signed PHP to Go local action contract changed.");}
  Console.WriteLine("PASS governed service action transport contract");return 1;
 }
}
