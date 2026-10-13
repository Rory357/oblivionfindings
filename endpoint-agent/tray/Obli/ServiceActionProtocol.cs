using System;
using System.IO;
using System.Linq;
using System.Collections.Generic;
using System.Globalization;
using System.Text;
using System.Text.Json;
namespace Oblivion.Obli;
public sealed record ServiceActionState(Uri Origin,string Status,Guid? CommandId,Guid? AttemptId,string PayloadHash,string ConsentToken,long PolicyVersion,string Technician,string Purpose,string ServiceName,string Operation,DateTimeOffset? ExpiresAt,string ReasonCode,bool MutationStarted)
{
 public string Reason => ReasonCode switch {
  "confirmed"=>"The service action was confirmed.","already_in_state"=>"The service is already in the requested state.","declined"=>"The action was declined.","expired"=>"The action expired.","consent_lost"=>"Windows consent is no longer current.","service_busy"=>"The service is changing state.","dependent_services"=>"Another service depends on this service.","disabled_service"=>"The service is disabled.","operation_timeout" or "interrupted"=>"The result is unconfirmed. Check the actual service state before requesting another action.",""=>"",_=>"The service action could not be confirmed."};
 public override string ToString()=>"Private governed service action";
}
internal static class ServiceActionProtocol
{
 static readonly string[] Fields={"protocol","server_origin","status","command_uuid","attempt_uuid","payload_hash","consent_token","policy_version","technician","purpose","service_name","operation","expires_at","reason_code","mutation_started"};
 internal static ServiceActionState Parse(byte[] bytes,Uri origin,DateTimeOffset now)
 {
  try{return ParseCore(bytes,origin,now);}catch(Exception error) when(error is JsonException or InvalidOperationException or FormatException or KeyNotFoundException or OverflowException or ArgumentException){throw new InvalidDataException("The service action response is invalid.");}
 }
 static ServiceActionState ParseCore(byte[] bytes,Uri origin,DateTimeOffset now)
 {
  if(bytes.Length is 0 or >16384)throw new InvalidDataException();
  using var json=JsonDocument.Parse(bytes,new JsonDocumentOptions{MaxDepth=2});
  if(json.RootElement.ValueKind!=JsonValueKind.Object)throw new InvalidDataException();
  var map=new Dictionary<string,JsonElement>(StringComparer.Ordinal);
  foreach(var p in json.RootElement.EnumerateObject())if(!Fields.Contains(p.Name)||!map.TryAdd(p.Name,p.Value))throw new InvalidDataException();
  if(map.Count!=15||map["protocol"].GetInt32()!=1)throw new InvalidDataException();
  string Text(string key,int max){string s=map[key].GetString()??throw new InvalidDataException();int count=0;foreach(var rune in s.EnumerateRunes()){if(Rune.GetUnicodeCategory(rune) is UnicodeCategory.Control or UnicodeCategory.Format||rune.Value==0xfffd)throw new InvalidDataException();count++;}if(count>max)throw new InvalidDataException();return s;}
  var actual=ObliClient.ValidateOrigin(Text("server_origin",2048));
  if(TicketComputerProtocol.OriginHash(actual)!=TicketComputerProtocol.OriginHash(origin))throw new InvalidDataException();
  string status=Text("status",20),command=Text("command_uuid",36),attempt=Text("attempt_uuid",36),hash=Text("payload_hash",64),token=Text("consent_token",64),technician=Text("technician",200),purpose=Text("purpose",500),service=Text("service_name",200),operation=Text("operation",7),expiry=Text("expires_at",32),reason=Text("reason_code",40);
  long policy=map["policy_version"].GetInt64();bool started=map["mutation_started"].GetBoolean();
  if(status is not("idle" or "requested" or "awaiting_result" or "succeeded" or "failed" or "uncertain" or "declined" or "expired"))throw new InvalidDataException();
  if(status=="idle"){
   if(new[]{command,attempt,hash,token,technician,purpose,service,operation,expiry,reason}.Any(s=>s!="")||policy!=0||started)throw new InvalidDataException();
   return new(actual,status,null,null,"","",0,"","","","",null,"",false);
  }
  if(!Guid.TryParseExact(command,"D",out var commandId)||!Guid.TryParseExact(attempt,"D",out var attemptId)||commandId==Guid.Empty||attemptId==Guid.Empty||!TicketComputerProtocol.Hex(hash)||policy<1||policy>9007199254740991||string.IsNullOrWhiteSpace(technician)||string.IsNullOrWhiteSpace(purpose)||!System.Text.RegularExpressions.Regex.IsMatch(service,"\\A[A-Za-z0-9][A-Za-z0-9_. -]{0,199}\\z")||service.Trim()!=service||operation is not("start" or "stop" or "restart")||!DateTimeOffset.TryParseExact(expiry,"yyyy-MM-dd'T'HH:mm:ss'Z'",CultureInfo.InvariantCulture,DateTimeStyles.AssumeUniversal|DateTimeStyles.AdjustToUniversal,out var expires))throw new InvalidDataException();
  if(status=="requested"&&(!TicketComputerProtocol.Hex(token)||started||reason!=""||expires<=now||expires>now.AddMinutes(15)))throw new InvalidDataException();
  if(status!="requested"&&token!="")throw new InvalidDataException();
  if(reason is not("" or "confirmed" or "already_in_state" or "declined" or "expired" or "policy_unavailable" or "consent_lost" or "service_unavailable" or "service_busy" or "dependent_services" or "unsupported_service" or "disabled_service" or "operation_failed" or "operation_timeout" or "interrupted" or "state_unavailable"))throw new InvalidDataException();
  if(expires>now.AddMinutes(15))throw new InvalidDataException();
  if(status=="awaiting_result"&&reason!="")throw new InvalidDataException();
  if(status=="succeeded"&&!(reason=="confirmed"&&started||reason=="already_in_state"&&!started&&operation!="restart"))throw new InvalidDataException();
  if(status is "failed" or "uncertain" && (reason is "" or "confirmed" or "already_in_state" or "declined" or "expired"))throw new InvalidDataException();
  if(status=="failed"&&started)throw new InvalidDataException();
  if(status is "declined" or "expired" && (started||reason!=status))throw new InvalidDataException();
  return new(actual,status,commandId,attemptId,hash,token,policy,technician,purpose,service,operation,expires,reason,started);
 }
 internal static ServiceActionState ParseAnswer(byte[] bytes,Uri origin,ServiceActionState expected,DateTimeOffset now)
 {
  var actual=Parse(bytes,origin,now);if(actual.CommandId!=expected.CommandId||actual.AttemptId!=expected.AttemptId||actual.PayloadHash!=expected.PayloadHash||actual.PolicyVersion!=expected.PolicyVersion||actual.ServiceName!=expected.ServiceName||actual.Operation!=expected.Operation||actual.Technician!=expected.Technician||actual.Purpose!=expected.Purpose||actual.ExpiresAt!=expected.ExpiresAt)throw new InvalidDataException("The service action response did not match this decision.");return actual;
 }
 internal static object Poll(Uri origin)=>new{version=1,operation="service_action_poll",expected_origin_hash=TicketComputerProtocol.OriginHash(origin)};
 internal static object Answer(Uri origin,ServiceActionState state,string decision)
 {
  if(state.Status!="requested"||state.AttemptId is null||state.AttemptId==Guid.Empty||state.CommandId is null||state.CommandId==Guid.Empty||state.ExpiresAt is null||state.ExpiresAt<=DateTimeOffset.UtcNow||TicketComputerProtocol.OriginHash(state.Origin)!=TicketComputerProtocol.OriginHash(origin)||!TicketComputerProtocol.Hex(state.PayloadHash)||!TicketComputerProtocol.Hex(state.ConsentToken)||decision is not("allow" or "decline"))throw new InvalidDataException("This service action is no longer available for consent.");
  return new{version=1,operation="service_action_decide",expected_origin_hash=TicketComputerProtocol.OriginHash(origin),attempt_uuid=state.AttemptId.Value.ToString("D"),payload_hash=state.PayloadHash,consent_token=state.ConsentToken,decision};
 }
}
