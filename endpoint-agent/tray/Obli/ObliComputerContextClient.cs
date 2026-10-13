using System.Linq;
using System;
using System.IO;
using System.Net.Http;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
namespace Oblivion.Obli;
public sealed record TicketComputerSummary(string Name,SupportSite Site)
{ public override string ToString()=>"Verified computer context"; }
public sealed record TicketComputerContext(Guid RequestId,string? Challenge,DateTimeOffset ExpiresAt,string Status,TicketComputerSummary? Computer)
{ public override string ToString()=>"Private computer confirmation"; }
public sealed record SupportTicketCreationReceipt(SupportTicket Ticket,Guid RequestId,bool Replayed)
{public override string ToString()=>"Private ticket receipt";}
public sealed class ObliComputerContextUnavailableException:IOException
{public ObliComputerContextUnavailableException():base("Computer confirmation is unavailable. Review your ticket before trying again."){} }
public sealed partial class ObliClient
{
 static bool IsComputerContextRoute(HttpMethod method,string path)
 {
  if(method==HttpMethod.Post&&path=="ticket-computer-context")return true;
  return method==HttpMethod.Get&&(path.StartsWith("ticket-computer-context/",StringComparison.Ordinal)&&CanonicalRequestUuid(path[24..])||path.StartsWith("tickets/requests/",StringComparison.Ordinal)&&CanonicalRequestUuid(path[17..]));
 }
 static bool CanonicalRequestUuid(string value)=>Guid.TryParseExact(value,"D",out var id)&&id!=Guid.Empty&&value==id.ToString("D");
 public async Task<TicketComputerContext> MintTicketComputerContextAsync(StaffSession session,Guid requestId,int siteId,string title,string description,string category,CancellationToken cancellation)
 {
  ValidateTicketSubmission(requestId,siteId,title,description,category);
  using var json=await SendAsync(HttpMethod.Post,"ticket-computer-context",new{request_uuid=requestId,site_id=siteId,title,description,category},session,cancellation);
  var context=ParseComputerContext(json.RootElement,requestId,true);
  if(context.Computer!=null&&context.Computer.Site.Id!=siteId)throw new InvalidDataException("Unconfirmed computer Site.");
  return context;
 }
 public async Task<TicketComputerContext> TicketComputerContextAsync(StaffSession session,Guid requestId,CancellationToken cancellation)
 {
  if(requestId==Guid.Empty)throw new ArgumentException();
  using var json=await SendAsync(HttpMethod.Get,"ticket-computer-context/"+requestId.ToString("D"),null,session,cancellation);
  return ParseComputerContext(json.RootElement,requestId,false);
 }
 internal static void ValidateTicketSubmission(Guid requestId,int siteId,string title,string description,string category)
 {
  if(requestId==Guid.Empty||siteId<1||TicketScalarCount(title)>200||TicketScalarCount(description)>12000||string.IsNullOrWhiteSpace(title)||string.IsNullOrWhiteSpace(description)||title.Concat(description).Any(c=>char.IsControl(c)&&c is not ('\t' or '\r' or '\n'))||category is not("hardware" or "account" or "network" or "other"))throw new ArgumentException("Review the ticket title, description and category before sending.");
 }
 TicketComputerContext ParseComputerContext(JsonElement root,Guid request,bool mint)
 {
  if(mint)TicketFields(root,"request_uuid","challenge","expires_at","status","computer");else TicketFields(root,"request_uuid","expires_at","status","computer");
  if(TicketText(root,"request_uuid",36,true)!=request.ToString("D"))throw new InvalidDataException("Unconfirmed computer request.");
  string? challenge=mint?TicketText(root,"challenge",64,true):null;
  if(challenge!=null&&!TicketComputerProtocol.Hex(challenge))throw new InvalidDataException();
  var expires=TicketTimestamp(root,"expires_at");
  if(expires<=contextClock.GetUtcNow()||expires>contextClock.GetUtcNow().AddMinutes(5).AddSeconds(5))throw new ObliComputerContextUnavailableException();
  string status=TicketText(root,"status",10,true);TicketComputerSummary? computer=null;
  var data=root.GetProperty("computer");
  if(status=="confirmed"){
   TicketFields(data,"name","site");var site=data.GetProperty("site");TicketFields(site,"id","name");int id=site.GetProperty("id").GetInt32();if(id<1)throw new InvalidDataException();
   computer=new(TicketText(data,"name",200,true),new SupportSite(id,TicketText(site,"name",200,true)));
  }else if(status!="pending"||data.ValueKind!=JsonValueKind.Null)throw new InvalidDataException();
  return new(request,challenge,expires,status,computer);
 }
 static SupportTicketCreationReceipt ParseCreationReceipt(JsonElement root,Guid request)
 {
  TicketFields(root,"ticket","request_uuid","replayed");
  if(TicketText(root,"request_uuid",36,true)!=request.ToString("D"))throw new InvalidDataException("Unconfirmed ticket request.");
  return new(ParseTicketSummary(root.GetProperty("ticket")),request,root.GetProperty("replayed").GetBoolean());
 }
 public async Task<SupportTicket?> TicketRequestAsync(StaffSession session,Guid requestId,CancellationToken cancellation)=> (await RecoverTicketAsync(session,requestId,cancellation)).Ticket;
 public async Task<SupportTicketCreationReceipt> RecoverTicketAsync(StaffSession session,Guid requestId,CancellationToken cancellation)
 {
  if(requestId==Guid.Empty)throw new ArgumentException();
  using var json=await SendAsync(HttpMethod.Get,"tickets/requests/"+requestId.ToString("D"),null,session,cancellation);
  return ParseCreationReceipt(json.RootElement,requestId);
 }
}





