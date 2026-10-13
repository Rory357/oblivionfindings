using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Threading;
using System.Threading.Tasks;
using Oblivion.Obli;

static class TicketClientChecks
{
    static void Check(bool ok, string message) { if (!ok) throw new Exception(message); }
    static async Task Reject(Func<Task> operation, string message)
    {
        try { await operation(); } catch (Exception error) when (error is not OutOfMemoryException) { return; }
        throw new Exception(message);
    }
    static object Summary(int id = 10) => new { id, reference = "IT-TEST", title = "Printer", status = "open", updated_at = "2026-10-08T10:00:00+00:00", path = "/it/tickets/" + id };
    static object Ticket(int id = 10) => new { id, reference = "IT-TEST", title = "Printer", status = "open", updated_at = "2026-10-08T10:00:00+00:00", path = "/it/tickets/" + id,
        description = "Private description", description_truncated = false, lock_version = 3, can_reply = true };
    static object Comment(int id = 50) => new { id, body = "Private reply", body_truncated = false, author = "Test Worker", created_at = "2026-10-08T10:00:00+00:00" };
    static object Receipt(Guid request, int id = 10) => new { ticket = Ticket(id), comment = Comment(), request_uuid = request, committed_version = 3 };
    sealed class Replies : HttpMessageHandler
    {
        public readonly Queue<HttpResponseMessage> Responses = new();
        public readonly List<(string Method, string Path, string? Auth, string Body)> Seen = new();
        public void Json(object value, HttpStatusCode status = HttpStatusCode.OK) => Raw(JsonSerializer.Serialize(value), status);
        public void Raw(string json, HttpStatusCode status = HttpStatusCode.OK) => Responses.Enqueue(new(status) { Content = new StringContent(json, Encoding.UTF8, "application/json") });
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellation)
        {
            cancellation.ThrowIfCancellationRequested();
            Check(request.RequestUri!.GetLeftPart(UriPartial.Authority) == "https://care.example.test", "Ticket transport changed origin");
            Seen.Add((request.Method.Method, request.RequestUri.PathAndQuery, request.Headers.Authorization?.ToString(), request.Content == null ? "" : await request.Content.ReadAsStringAsync(cancellation)));
            return Responses.Dequeue();
        }
    }
    public static async Task<int> RunAsync()
    {
        var fake = new Replies();
        using var client = new ObliClient("https://care.example.test", fake);
        var session = new StaffSession(new string('b',64), client.Origin, DateTimeOffset.UtcNow.AddMinutes(5));
        Guid request = Guid.NewGuid();
        fake.Json(new { tickets = new[] { Summary(10), Summary(9) }, next_before = 9 });
        var page = await client.TicketPageAsync(session, null, CancellationToken.None);
        Check(page.Tickets.Count == 2 && page.NextBefore == 9, "Ticket page lost cursor");
        fake.Json(new { tickets = new[] { Summary(8) }, next_before = (int?)null });
        await client.TicketPageAsync(session, 9, CancellationToken.None);
        Check(fake.Seen[^1].Path == "/api/obli/tickets?before=9", "Ticket page query changed");
        fake.Json(new { tickets = new[] { Summary() }, next_before = (int?)null });
        Check((await client.TicketsAsync(session, CancellationToken.None)).Count == 1, "Existing ticket list compatibility lost");
        fake.Json(new { ticket = Ticket(), comments = new[] { Comment(49), Comment(50) }, next_before = 49 });
        var detail = await client.TicketAsync(session, 10, null, CancellationToken.None);
        Check(detail.Version == 3 && detail.CanReply && detail.Comments[0].Id == 49 && detail.NextBefore == 49 && !detail.DescriptionTruncated && !detail.Comments[0].BodyTruncated, "Ticket detail binding/order lost");
        fake.Json(new { ticket = Ticket(), comments = new[] { Comment(48) }, next_before = (int?)null });
        await client.TicketAsync(session, 10, 49, CancellationToken.None);
        Check(fake.Seen[^1].Path == "/api/obli/tickets/10?before=49", "Comment page query changed");
        fake.Json(Receipt(request), HttpStatusCode.Created); fake.Json(Receipt(request)); fake.Json(Receipt(request));
        var receipt = await client.ReplyAsync(session,10,request,2,"Private reply",CancellationToken.None);
        await client.ReplyAsync(session,10,request,2,"Private reply",CancellationToken.None);
        await client.RecoverReplyAsync(session,10,request,CancellationToken.None);
        Check(receipt.TicketId == 10 && receipt.RequestId == request && receipt.CommittedVersion == 3 && fake.Seen[^3].Body == fake.Seen[^2].Body && fake.Seen[^1].Body == "" && fake.Seen[^1].Path == "/api/obli/tickets/10/replies/" + request.ToString("D"), "Reply receipt/recovery changed immutable intent");
        using (var sent = JsonDocument.Parse(fake.Seen[^2].Body))
            Check(sent.RootElement.EnumerateObject().Count() == 3 && sent.RootElement.GetProperty("request_uuid").GetGuid() == request && sent.RootElement.GetProperty("expected_version").GetInt64() == 2, "Unexpected reply payload fields");
        Check(fake.Seen.All(r => r.Auth == "Bearer " + new string('b',64)), "Ticket session credential missing");
        Check(!detail.ToString().Contains("description") && !receipt.ToString().Contains("Private reply") && !detail.Comments[0].ToString().Contains("Private reply"), "Private ticket debug display leaked text");

        foreach (var change in new Action<JsonNode>[] {
            n => n["ticket"]!["id"] = 11,
            n => n["ticket"]!["path"] = "https://wrong.example",
            n => n["ticket"]!["status"] = "invented",
            n => n["ticket"]!["lock_version"] = 0,
            n => n["ticket"]!["can_reply"] = "true",
            n => n["ticket"]!["description"] = new string('x',12001),
            n => n["ticket"]!["description_truncated"] = null,
            n => n["ticket"]!["unexpected"] = "private",
            n => n["comments"]![0]!["body"] = new string('x',5001),
            n => n["comments"]![0]!["body"] = "bad\0text",
            n => n["comments"]![0]!["created_at"] = "yesterday",
            n => n["comments"]![0]!["author"] = "",
            n => n["comments"]![0]!["body_truncated"] = "false",
            n => n["comments"]!.AsArray().Add(JsonSerializer.SerializeToNode(Comment(49))),
            n => n["next_before"] = 51
        }) {
            var malformed = JsonSerializer.SerializeToNode(new { ticket = Ticket(), comments = new[] { Comment(49), Comment(50) }, next_before = 49 })!;
            change(malformed); fake.Raw(malformed.ToJsonString());
            await Reject(() => client.TicketAsync(session,10,null,CancellationToken.None), "Malformed ticket detail accepted");
        }
        var duplicate = JsonSerializer.Serialize(new { ticket = Ticket(), comments = new[] { Comment() }, next_before = (int?)null }).Replace("\"id\":10", "\"id\":10,\"id\":10");
        fake.Raw(duplicate);
        await Reject(() => client.TicketAsync(session,10,null,CancellationToken.None), "Duplicate ticket fields accepted");
        fake.Json(new { ticket = Ticket(), comments = Enumerable.Range(1,11).Select(Comment).ToArray(), next_before = (int?)null });
        await Reject(() => client.TicketAsync(session,10,null,CancellationToken.None), "Oversized comment page accepted");
        fake.Json(new { ticket = Ticket(), comments = new[] { Comment(51) }, next_before = (int?)null });
        await Reject(() => client.TicketAsync(session,10,51,CancellationToken.None), "Comment cursor ignored");
        foreach (var malformed in new[] { new { tickets = new[] { Summary(), Summary() }, next_before = (int?)null }, new { tickets = new[] { Summary(9), Summary(10) }, next_before = (int?)null }, new { tickets = new[] { Summary() }, next_before = (int?)11 } }) {
            fake.Json(malformed); await Reject(() => client.TicketPageAsync(session,null,CancellationToken.None), "Invalid ticket pagination accepted");
        }
        foreach (var change in new Action<JsonNode>[] {
            n => n["request_uuid"] = Guid.NewGuid().ToString(), n => n["ticket"]!["id"] = 11,
            n => n["committed_version"] = 4, n => n["comment"]!["id"] = 0, n => n["committed_version"] = null
        }) {
            var malformed = JsonSerializer.SerializeToNode(Receipt(request))!; change(malformed); fake.Raw(malformed.ToJsonString());
            await Reject(() => client.RecoverReplyAsync(session,10,request,CancellationToken.None), "Wrong or incomplete reply receipt accepted");
        }
        fake.Json(Receipt(request));
        await Reject(() => client.ReplyAsync(session,10,request,2,"Different immutable reply",CancellationToken.None), "Wrong-body reply acknowledgement accepted");
        var truncatedReceipt = JsonSerializer.SerializeToNode(Receipt(request))!;
        truncatedReceipt["comment"]!["body_truncated"] = true; fake.Raw(truncatedReceipt.ToJsonString());
        await Reject(() => client.ReplyAsync(session,10,request,2,"Private reply",CancellationToken.None), "Truncated acknowledgement accepted");
        fake.Json(Receipt(request));
        await client.ReplyAsync(session,10,request,2," \tPrivate reply\r\n",CancellationToken.None);
        fake.Json(Receipt(request));
        await Reject(() => client.ReplyAsync(session,10,request,2,"\u00a0Private reply\u00a0",CancellationToken.None), "Unicode whitespace improperly normalized in receipt binding");
        string scalarBody = string.Concat(Enumerable.Repeat("\U0001f642",5000));
        var unicodeReceipt = JsonSerializer.SerializeToNode(Receipt(request))!;
        unicodeReceipt["comment"]!["body"] = scalarBody;
        fake.Raw(unicodeReceipt.ToJsonString());
        Check((await client.ReplyAsync(session,10,request,2,scalarBody,CancellationToken.None)).Comment.Body == scalarBody, "Valid non-BMP reply scalar limit rejected");
        var unicodeDetail = JsonSerializer.SerializeToNode(new { ticket = Ticket(), comments = new[] { Comment() }, next_before = (int?)null })!;
        unicodeDetail["ticket"]!["description"] = string.Concat(Enumerable.Repeat("\U0001f642",12000));
        unicodeDetail["ticket"]!["reference"] = new string('r',200);
        fake.Raw(unicodeDetail.ToJsonString().Replace("\\uD83D\\uDE42", "\U0001f642", StringComparison.OrdinalIgnoreCase));
        Check((await client.TicketAsync(session,10,null,CancellationToken.None)).Description.Length == 24000, "Valid non-BMP detail scalar limit rejected");
        await Reject(() => client.ReplyAsync(session,10,request,2,"\ud800",CancellationToken.None), "Unpaired UTF-16 reply identity sent");
        foreach (var pair in new[] { (409,"version_conflict"), (422,"reply_rejected"), (422,"invalid_request") }) {
            fake.Json(new { code = pair.Item2, message = "server private error must never reach the UI" }, (HttpStatusCode)pair.Item1);
            bool rejected = false;
            try { await client.ReplyAsync(session,10,request,2,"Private reply",CancellationToken.None); }
            catch (ObliTicketRejectedException error) { rejected = error.Code == pair.Item2 && !error.Message.Contains("private error"); }
            Check(rejected, "Definitive ticket rejection lost safe code");
        }
        foreach (var pair in new[] { (409,"idempotency_conflict"), (409,"unexpected"), (500,"reply_rejected"), (422,"version_conflict") }) {
            fake.Json(new { code = pair.Item2, message = "private server error" }, (HttpStatusCode)pair.Item1);
            bool uncertain = false;
            try { await client.ReplyAsync(session,10,request,2,"Private reply",CancellationToken.None); }
            catch (IOException) { uncertain = true; }
            Check(uncertain, "Unconfirmed receipt was treated as definitive rejection");
        }
        foreach (bool post in new[] { false, true }) {
            fake.Responses.Enqueue(new(HttpStatusCode.NotFound)); bool concealed = false;
            try { if (post) await client.ReplyAsync(session,10,request,2,"Private reply",CancellationToken.None); else await client.TicketAsync(session,10,null,CancellationToken.None); }
            catch (ObliTicketUnavailableException) { concealed = true; }
            Check(concealed, "Missing ticket conversation not concealed");
        }
        fake.Responses.Enqueue(new(HttpStatusCode.NotFound)); bool missing = false;
        try { await client.RecoverReplyAsync(session,10,request,CancellationToken.None); } catch (IOException) { missing = true; }
        Check(missing, "Missing reply recovery incorrectly released pending intent");
        fake.Responses.Enqueue(new(HttpStatusCode.Redirect) { Headers = { Location = new Uri("https://wrong.example") } });
        await Reject(() => client.TicketAsync(session,10,null,CancellationToken.None), "Ticket redirect accepted");
        fake.Raw(new string(' ',131073));
        await Reject(() => client.TicketAsync(session,10,null,CancellationToken.None), "Oversized ticket response accepted");
        fake.Responses.Enqueue(new(HttpStatusCode.NoContent));
        await Reject(() => client.RecoverReplyAsync(session,10,request,CancellationToken.None), "Empty receipt accepted");
        fake.Responses.Enqueue(new(HttpStatusCode.Unauthorized)); bool access = false;
        try { await client.TicketAsync(session,10,null,CancellationToken.None); } catch (ObliAccessException) { access = true; }
        Check(access, "Expired account authorization not surfaced");
        int calls = fake.Seen.Count;
        foreach (var invalid in new[] { new StaffSession(new string('b',64), new Uri("https://other.example.test"), DateTimeOffset.UtcNow.AddMinutes(5)), new StaffSession(new string('b',64),client.Origin,DateTimeOffset.UtcNow.AddMinutes(-1)) })
            await Reject(() => client.TicketAsync(invalid,10,null,CancellationToken.None), "Invalid session sent ticket request");
        await Reject(() => client.TicketAsync(session,0,null,CancellationToken.None), "Invalid ticket sent");
        await Reject(() => client.TicketPageAsync(session,0,CancellationToken.None), "Invalid cursor sent");
        await Reject(() => client.ReplyAsync(session,10,Guid.Empty,2,"Text",CancellationToken.None), "Missing request identity sent");
        await Reject(() => client.ReplyAsync(session,10,request,0,"Text",CancellationToken.None), "Invalid version sent");
        await Reject(() => client.ReplyAsync(session,10,request,2,new string('x',5001),CancellationToken.None), "Oversized reply sent");
        await Reject(() => client.ReplyAsync(session,10,request,2,"bad\0text",CancellationToken.None), "Control text reply sent");
        Check(fake.Seen.Count == calls, "Invalid local ticket input reached HTTP");
        var route = typeof(ObliClient).GetMethod("IsTicketRoute", BindingFlags.NonPublic|BindingFlags.Static)!;
        foreach (string path in new[] { "tickets/01", "tickets/-1", "tickets/10?before=0", "tickets/10?before=5&site=2", "tickets/10?Before=5", "tickets/10/replies?before=2", "tickets/10/replies/" + request.ToString("D") + "/", "tickets/10/../me", "tickets/10#fragment" })
            Check(!(bool)route.Invoke(null,new object[] { HttpMethod.Get,path })!, "Noncanonical ticket route accepted");
        string? fixture = Environment.GetEnvironmentVariable("OBLI_TICKET_EXPORT");
        if (fixture != null) {
            using var source = JsonDocument.Parse(File.ReadAllText(fixture));
            var root = source.RootElement;
            int id = root.GetProperty("detail").GetProperty("ticket").GetProperty("id").GetInt32();
            Guid uuid = root.GetProperty("receipt").GetProperty("request_uuid").GetGuid();
            fake.Raw(root.GetProperty("detail").GetRawText()); await client.TicketAsync(session,id,null,CancellationToken.None);
            fake.Raw(root.GetProperty("receipt").GetRawText()); await client.RecoverReplyAsync(session,id,uuid,CancellationToken.None);
            fake.Raw(root.GetProperty("page").GetRawText()); await client.TicketPageAsync(session,null,CancellationToken.None);
        }
        return 7;
    }
}