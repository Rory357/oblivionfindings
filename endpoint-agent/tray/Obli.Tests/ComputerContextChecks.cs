using System;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using Oblivion.Obli;
static class ComputerContextChecks
{
 static void Check(bool value,string message){if(!value)throw new Exception(message);}
 static async Task Reject(Func<Task> action,string message){try{await action();}catch(Exception e)when(e is not OutOfMemoryException){return;}throw new Exception(message);}
 sealed class Fake:HttpMessageHandler
 {
  public string Json="{}",Path="",Body="";public HttpStatusCode Status=HttpStatusCode.OK;public int Calls;
  protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request,CancellationToken ct){Calls++;Path=request.RequestUri!.PathAndQuery;Body=request.Content==null?"":await request.Content.ReadAsStringAsync(ct);return new(Status){Content=new StringContent(Json,Encoding.UTF8,"application/json")};}
  public void Set(object body,HttpStatusCode status=HttpStatusCode.OK){Json=JsonSerializer.Serialize(body);Status=status;}
 }
 sealed class FixedClock(DateTimeOffset now):TimeProvider{public override DateTimeOffset GetUtcNow()=>now;}
 static object Summary()=>new{id=12,reference="IT-12",title="Printer stopped",status="open",updated_at="2026-10-08T10:00:00+00:00",path="/it/tickets/12"};
 public static async Task<int> RunAsync()
 {
  var fake=new Fake();using var client=new ObliClient("https://care.example.test",fake);var session=new StaffSession(new string('b',64),client.Origin,DateTimeOffset.UtcNow.AddMinutes(10));var id=Guid.Parse("11111111-1111-4111-8111-111111111111");string challenge=new('a',64),expiry=DateTimeOffset.UtcNow.AddMinutes(4).ToString("O");
  fake.Set(new{request_uuid=id,challenge,expires_at=expiry,status="pending",computer=(object?)null});var mint=await client.MintTicketComputerContextAsync(session,id,3,"Printer stopped","Please help","hardware",CancellationToken.None);Check(mint.Challenge==challenge&&mint.Computer==null&&fake.Path=="/api/obli/ticket-computer-context","Mint binding failed");Check(!mint.ToString().Contains(challenge),"Challenge appeared in diagnostics");
  using(var wire=JsonDocument.Parse(fake.Body))Check(wire.RootElement.EnumerateObject().Count()==5&&!wire.RootElement.TryGetProperty("device_id",out _),"Mint intent contract changed");
  fake.Set(new{request_uuid=id,expires_at=expiry,status="confirmed",computer=new{name="Reception computer",site=new{id=3,name="Reception"}}});var context=await client.TicketComputerContextAsync(session,id,CancellationToken.None);Check(context.Computer?.Site.Id==3&&context.Challenge==null,"Read proof summary failed");
  foreach(var bad in new object[]{new{request_uuid=Guid.NewGuid(),expires_at=expiry,status="pending",computer=(object?)null},new{request_uuid=id,expires_at=expiry,status="confirmed",computer=(object?)null},new{request_uuid=id,expires_at=expiry,status="pending",computer=new{name="Private",site=new{id=3,name="Site"}}},new{request_uuid=id,expires_at=DateTimeOffset.UtcNow.AddMinutes(-1).ToString("O"),status="pending",computer=(object?)null}}){fake.Set(bad);await Reject(()=>client.TicketComputerContextAsync(session,id,CancellationToken.None),"Unbound context accepted");}
  fake.Set(new{ticket=Summary(),request_uuid=id,replayed=false},HttpStatusCode.Created);var ticket=await client.SubmitAsync(session,id,3,"Printer stopped","Please help","hardware",CancellationToken.None,true);Check(ticket.Id==12&&fake.Body.Contains("\"computer_context\":true"),"Explicit computer selection lost");
  fake.Set(new{ticket=Summary(),request_uuid=id,replayed=true});await client.SubmitAsync(session,id,3,"Printer stopped","Please help","hardware",CancellationToken.None);Check(!fake.Body.Contains("computer_context"),"Unselected context sent");await client.TicketRequestAsync(session,id,CancellationToken.None);Check(fake.Path=="/api/obli/tickets/requests/"+id,"Receipt path changed");
  fake.Set(new{ticket=Summary(),request_uuid=Guid.NewGuid(),replayed=false});await Reject(()=>client.SubmitAsync(session,id,3,"Printer stopped","Please help","hardware",CancellationToken.None),"Wrong receipt UUID accepted");
  foreach(var status in new[]{HttpStatusCode.NotFound,HttpStatusCode.Conflict,HttpStatusCode.InternalServerError,HttpStatusCode.NoContent}){fake.Set(new{code="computer_context_unavailable",message="private"},status);await Reject(()=>client.TicketRequestAsync(session,id,CancellationToken.None),"Missing receipt claimed committed");}
  int calls=fake.Calls;await Reject(()=>client.MintTicketComputerContextAsync(session,id,3,"Unsafe\u0080","Please help","hardware",CancellationToken.None),"Unsafe intent sent");Check(fake.Calls==calls,"Local validation used network");
  foreach(var vector in new[]{
   ("https://CARE.example.test:443/","66df9086fbf476a6682b5c64b8262d40bb3ae707173fb1cecb8696262c2eee4c"),
   ("https://care.example.test:8443","26d4f5e46890c3da01549551a20137e45648032f13c54613f9db32eef65db6fb"),
   ("https://[2001:0db8::1]:443","860ce09858baa550b66ed4e109262effbdcafd53f2f4a4bdb7d7e0f9de58377d"),
   ("https://[2001:db8:0:0:0:0:0:1]","860ce09858baa550b66ed4e109262effbdcafd53f2f4a4bdb7d7e0f9de58377d"),
   ("https://[::ffff:192.0.2.1]","e8ea0fd14ab4f3ad6793631ef09d5674320d4f9e8261f3430c6ea5a12eff97dd"),
   ("https://[::ffff:c000:201]:443/","e8ea0fd14ab4f3ad6793631ef09d5674320d4f9e8261f3430c6ea5a12eff97dd"),
   ("https://[::192.0.2.1]","3b0518bf9668466e58152daee95ab851d5409855c9c3e118a6bf696a30974a22")
  })Check(TicketComputerProtocol.OriginHash(new Uri(vector.Item1))==vector.Item2,"Origin differs from Go vector");
  var request=TicketComputerProtocol.Request(challenge,new Uri("https://CARE.example.test:443/"));using(var wire=JsonDocument.Parse(JsonSerializer.Serialize(request))){Check(wire.RootElement.EnumerateObject().Count()==4&&wire.RootElement.GetProperty("expected_origin_hash").GetString()==TicketComputerProtocol.OriginHash(new Uri("https://care.example.test")),"IPC origin mismatch");Check(!wire.RootElement.TryGetProperty("staff_token",out _),"Staff token reached service");}
  Check(TicketComputerProtocol.Parse(Encoding.UTF8.GetBytes("{\"protocol\":1,\"status\":\"confirmed\"}")),"Proof response rejected");foreach(string raw in new[]{"{\"protocol\":1,\"status\":\"confirmed\",\"status\":\"confirmed\"}","{\"protocol\":1,\"status\":\"confirmed\",\"device_id\":2}","{\"protocol\":1,\"status\":\"unknown\"}"})await Reject(()=>Task.Run(()=>TicketComputerProtocol.Parse(Encoding.UTF8.GetBytes(raw))),"Unsafe IPC proof accepted");
  string? fixture=Environment.GetEnvironmentVariable("OBLI_COMPUTER_EXPORT");if(fixture!=null){using var json=JsonDocument.Parse(File.ReadAllText(fixture));var root=json.RootElement;var requestId=root.GetProperty("mint").GetProperty("request_uuid").GetGuid();byte[] payload=Convert.FromBase64String(root.GetProperty("envelope").GetProperty("payload").GetString()!);using var ack=JsonDocument.Parse(payload);using var fixtureClient=new ObliClient(client.Origin.AbsoluteUri,fake,new FixedClock(DateTimeOffset.FromUnixTimeSeconds(ack.RootElement.GetProperty("issued_at").GetInt64())));fake.Json=root.GetProperty("mint").GetRawText();fake.Status=HttpStatusCode.OK;await fixtureClient.MintTicketComputerContextAsync(session,requestId,root.GetProperty("context").GetProperty("computer").GetProperty("site").GetProperty("id").GetInt32(),"Fixture title","Fixture description","hardware",CancellationToken.None);fake.Json=root.GetProperty("context").GetRawText();await fixtureClient.TicketComputerContextAsync(session,requestId,CancellationToken.None);fake.Json=root.GetProperty("receipt").GetRawText();await fixtureClient.TicketRequestAsync(session,requestId,CancellationToken.None);}
  return 3;
 }
}



