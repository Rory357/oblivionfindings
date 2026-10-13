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

static class KnowledgeClientChecks
{
    static void Check(bool value, string message) { if (!value) throw new Exception(message); }
    static async Task Reject(Func<Task> operation, string message) { try { await operation(); } catch (Exception error) when (error is not OutOfMemoryException) { return; } throw new Exception(message); }
    static void Reject(Action operation, string message) { try { operation(); } catch (Exception error) when (error is not OutOfMemoryException) { return; } throw new Exception(message); }
    static string Digest => new('a',64);
    static object Article(int id = 10) => new { id, title = "Approved printer guide", path = "/it/knowledge/" + id, published_at = "2026-10-08T10:00:00+00:00", review_due_at = "2026-11-08" };
    static object Summary(int id = 10) => new { id, title = "Approved printer guide", path = "/it/knowledge/" + id, published_at = "2026-10-08T10:00:00+00:00", review_due_at = "2026-11-08", summary = "Review the printer cable." };
    static object Detail(int id = 10) => new { article = Article(id), excerpt = "Private approved instructions", excerpt_truncated = false, version = Digest };
    sealed class Replies : HttpMessageHandler
    {
        public readonly Queue<HttpResponseMessage> Responses = new();
        public readonly List<(string Method,string Path,string? Auth,string Body)> Seen = new();
        public void Json(object value, HttpStatusCode status = HttpStatusCode.OK) => Raw(JsonSerializer.Serialize(value),status);
        public void Raw(string value, HttpStatusCode status = HttpStatusCode.OK) => Responses.Enqueue(new(status) { Content = new StringContent(value, Encoding.UTF8, "application/json") });
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellation)
        {
            cancellation.ThrowIfCancellationRequested();
            Check(request.RequestUri!.GetLeftPart(UriPartial.Authority) == "https://care.example.test", "Knowledge origin changed");
            Seen.Add((request.Method.Method,request.RequestUri.PathAndQuery,request.Headers.Authorization?.ToString(),request.Content == null ? "" : await request.Content.ReadAsStringAsync(cancellation)));
            return Responses.Dequeue();
        }
    }
    public static async Task<int> RunAsync()
    {
        var fake = new Replies(); using var client = new ObliClient("https://care.example.test", fake);
        var session = new StaffSession(new string('b',64),client.Origin,DateTimeOffset.UtcNow.AddMinutes(5));
        fake.Json(new { articles = new[] { Summary(10), Summary(9) }, next_before = 9 });
        var page = await client.KnowledgeAsync(session,"printer & cable",null,CancellationToken.None);
        Check(page.Articles.Count == 2 && page.NextBefore == 9 && fake.Seen[^1].Path == "/api/obli/knowledge?q=printer%20%26%20cable", "Knowledge search contract mismatch");
        fake.Json(new { articles = new[] { Summary(8) }, next_before = (int?)null });
        await client.KnowledgeAsync(session,"",9,CancellationToken.None);
        Check(fake.Seen[^1].Path == "/api/obli/knowledge?q=&before=9", "Knowledge cursor query changed");
        fake.Json(new { articles = Array.Empty<object>(), next_before = 7 });
        var empty = await client.KnowledgeAsync(session,"",9,CancellationToken.None);
        Check(empty.Articles.Count == 0 && empty.NextBefore == 7, "Empty bounded knowledge scan lost continuation");
        fake.Json(new { articles = new[] { Summary(8) }, next_before = 6 });
        var scanned = await client.KnowledgeAsync(session,"",9,CancellationToken.None);
        Check(scanned.Articles.Count == 1 && scanned.NextBefore == 6, "Knowledge continuation must follow last examined candidate");
        foreach (int cursor in new[] { 0,9,10 }) {
            fake.Json(new { articles = Array.Empty<object>(), next_before = cursor });
            await Reject(() => client.KnowledgeAsync(session,"",9,CancellationToken.None), "Non-progressing empty knowledge cursor accepted");
        }
        fake.Json(new { articles = new[] { Summary(8) }, next_before = 9 });
        await Reject(() => client.KnowledgeAsync(session,"",10,CancellationToken.None), "Knowledge cursor above returned source accepted");
        fake.Json(Detail()); var detail = await client.KnowledgeArticleAsync(session,10,CancellationToken.None);
        var reference = detail.ToReference();
        Check(detail.Article.Id == 10 && detail.Article.Summary == "" && detail.Article.ReviewDueAt!.Value.ToString("yyyy-MM-dd") == "2026-11-08" && reference.Title == detail.Article.Title && reference.Excerpt == detail.Excerpt, "Knowledge preview binding lost");
        Check(fake.Seen.All(r => r.Method == "GET" && r.Auth == "Bearer " + new string('b',64) && r.Body == ""), "Knowledge retrieval leaked content or lost session");
        Check(!detail.ToString().Contains(detail.Excerpt) && !reference.ToString().Contains(detail.Excerpt), "Private knowledge debug display leaked content");
        fake.Json(new { version = 2, enabled = true, provider = "openai", model = "test", chat_retention_days = 7, diagnostic_categories = Array.Empty<string>(), knowledge_enabled = true });
        Check((await client.ChatOptionsAsync(session,CancellationToken.None)).KnowledgeEnabled, "Knowledge option missing");
        fake.Json(new { version = 2, enabled = true, provider = "openai", model = "test", chat_retention_days = 7 });
        Check(!(await client.ChatOptionsAsync(session,CancellationToken.None)).KnowledgeEnabled, "Missing knowledge option silently enabled source sharing");
        fake.Json(new { version = 2, enabled = true, provider = "openai", model = "test", chat_retention_days = 7, knowledge_enabled = "true" });
        await Reject(() => client.ChatOptionsAsync(session,CancellationToken.None), "Wrong knowledge option type accepted");

        foreach (var change in new Action<JsonNode>[] {
            n => n["article"]!["id"] = 11, n => n["article"]!["path"] = "https://wrong.example.test",
            n => n["article"]!["title"] = new string('x',201), n => n["article"]!["published_at"] = null,
            n => n["article"]!["published_at"] = "yesterday", n => n["article"]!["review_due_at"] = null,
            n => n["article"]!["review_due_at"] = "2026-02-30", n => n["article"]!["summary"] = "unexpected",
            n => n["excerpt"] = new string('x',4001), n => n["excerpt"] = string.Concat(Enumerable.Repeat("界",1334)),
            n => n["excerpt"] = "unsafe\0text", n => n["excerpt_truncated"] = "false",
            n => n["version"] = new string('A',64), n => n["version"] = new string('a',63), n => n["unexpected"] = "private"
        }) {
            var bad = JsonSerializer.SerializeToNode(Detail())!; change(bad); fake.Raw(bad.ToJsonString());
            await Reject(() => client.KnowledgeArticleAsync(session,10,CancellationToken.None), "Malformed approved knowledge accepted");
        }
        fake.Raw(JsonSerializer.Serialize(Detail()).Replace("\"id\":10", "\"id\":10,\"id\":10"));
        await Reject(() => client.KnowledgeArticleAsync(session,10,CancellationToken.None), "Duplicate knowledge field accepted");
        foreach (var value in new[] { new { articles = new[] { Summary(), Summary() }, next_before = (int?)null }, new { articles = new[] { Summary(9), Summary(10) }, next_before = (int?)null }, new { articles = new[] { Summary() }, next_before = (int?)11 } }) {
            fake.Json(value); await Reject(() => client.KnowledgeAsync(session,"",null,CancellationToken.None), "Invalid knowledge pagination accepted");
        }
        fake.Json(new { articles = Enumerable.Range(1,11).Reverse().Select(Summary).ToArray(), next_before = (int?)null });
        await Reject(() => client.KnowledgeAsync(session,"",null,CancellationToken.None), "Knowledge page over10 accepted");
        fake.Json(new { articles = new[] { Summary(9) }, next_before = (int?)null });
        await Reject(() => client.KnowledgeAsync(session,"",9,CancellationToken.None), "Knowledge before cursor ignored");
        var scalar = JsonSerializer.SerializeToNode(Detail())!;
        scalar["article"]!["title"] = string.Concat(Enumerable.Repeat("\U0001f642",200));
        scalar["excerpt"] = string.Concat(Enumerable.Repeat("\U0001f642",1000));
        fake.Raw(scalar.ToJsonString());
        Check((await client.KnowledgeArticleAsync(session,10,CancellationToken.None)).Excerpt.Length == 2000, "Valid Unicode scalar knowledge rejected");

        Guid request = Guid.NewGuid();
        var messages = new[] { new ChatMessage("user","Help with this printer",null,reference) };
        fake.Json(new { request_uuid = request, status = "completed", text = "Mock answer" });
        await client.SendChatAsync(session,request,2,messages,CancellationToken.None);
        using (var sent = JsonDocument.Parse(fake.Seen[^1].Body)) {
            var source = sent.RootElement.GetProperty("messages")[0].GetProperty("knowledge");
            Check(source.EnumerateObject().Count() == 2 && source.GetProperty("article_id").GetInt32() == 10 && source.GetProperty("version").GetString() == Digest && !fake.Seen[^1].Body.Contains(detail.Excerpt), "Client sent source preview instead of reference");
        }
        Check(reference.ProviderText == KnowledgeReference.Prefix + "Article ID: 10\nVersion: " + Digest + "\nTitle: Approved printer guide\nPrivate approved instructions" + KnowledgeReference.Suffix, "Knowledge rendered budget prefix diverged");
        Reject(() => ObliClient.ValidateChat(new[] { new ChatMessage("user","hello"), new ChatMessage("assistant","answer",null,reference), new ChatMessage("user","again") }), "Assistant knowledge attachment accepted");
        Reject(() => ObliClient.ValidateChat(new[] { new ChatMessage("user","hello",null,reference with { Version = "broken" }) }), "Invalid source digest accepted");
        Reject(() => ObliClient.ValidateChat(new[] { new ChatMessage("user","hello",null,reference with { Excerpt = "\ud800" }) }), "Invalid Unicode source accepted");
        var large = reference with { Excerpt = new string('x',4000) };
        Reject(() => ObliClient.ValidateChat(new[] { new ChatMessage("user","hello",null,large), new ChatMessage("assistant","answer"), new ChatMessage("user","hello",null,large), new ChatMessage("assistant","answer"), new ChatMessage("user","hello",null,large) }), "Historical sources excluded from chat budget");
        var diagnostics = DiagnosticSnapshot.Parse(JsonSerializer.SerializeToElement(new { schema = 1, observed_at = DateTimeOffset.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ"), services = new[] { new { name = "print_spooler", state = "running" } } }), DateTimeOffset.UtcNow,true);
        ObliClient.ValidateChat(new[] { new ChatMessage("user","help",diagnostics,reference) });
        int overhead = Encoding.UTF8.GetByteCount(diagnostics.ProviderText + large.ProviderText);
        Reject(() => ObliClient.ValidateChat(new[] { new ChatMessage("user",new string('x',12001-overhead),diagnostics,large) }), "Combined diagnostics/knowledge render budget ignored");
        foreach (int operation in new[] { 0,1,2,3 }) {
            fake.Json(new { code = "knowledge_unavailable", message = "private server details must not reach user" },HttpStatusCode.NotFound);
            bool concealed = false;
            try {
                if (operation == 0) await client.KnowledgeAsync(session,"",null,CancellationToken.None);
                if (operation == 1) await client.KnowledgeArticleAsync(session,10,CancellationToken.None);
                if (operation == 2) await client.SendChatAsync(session,request,2,messages,CancellationToken.None);
                if (operation == 3) await client.ChatOutcomeAsync(session,request,CancellationToken.None);
            } catch (ObliKnowledgeUnavailableException error) { concealed = !error.Message.Contains("private server"); }
            Check(concealed, "Revoked knowledge not concealed consistently");
        }
        foreach (var status in new[] { HttpStatusCode.Redirect, HttpStatusCode.Created, HttpStatusCode.NoContent }) {
            fake.Json(Detail(),status); await Reject(() => client.KnowledgeArticleAsync(session,10,CancellationToken.None), "Unexpected knowledge HTTP result accepted");
        }
        fake.Raw(new string(' ',131073)); await Reject(() => client.KnowledgeArticleAsync(session,10,CancellationToken.None), "Knowledge wire size unbounded");
        int calls = fake.Seen.Count;
        await Reject(() => client.KnowledgeAsync(session,new string('x',101),null,CancellationToken.None), "Oversized knowledge search sent");
        await Reject(() => client.KnowledgeAsync(session,"unsafe\nquery",null,CancellationToken.None), "Control search sent");
        await Reject(() => client.KnowledgeAsync(session,"\ud800",null,CancellationToken.None), "Unpaired Unicode search sent");
        await Reject(() => client.KnowledgeAsync(session,"",0,CancellationToken.None), "Invalid knowledge cursor sent");
        await Reject(() => client.KnowledgeArticleAsync(session,0,CancellationToken.None), "Invalid article sent");
        await Reject(() => client.KnowledgeArticleAsync(new StaffSession(new string('b',64),new Uri("https://other.example.test"),DateTimeOffset.UtcNow.AddMinutes(5)),10,CancellationToken.None), "Cross-origin session sent knowledge request");
        Check(calls == fake.Seen.Count, "Invalid knowledge input reached HTTP");
        var route = typeof(ObliClient).GetMethod("IsKnowledgeRoute",BindingFlags.NonPublic|BindingFlags.Static)!;
        foreach (string path in new[] { "knowledge/01", "knowledge/10?before=2", "knowledge?q=a&before=0", "knowledge?q=a&site=2", "knowledge?q=a&before=2&before=1", "knowledge?q=%61", "knowledge?q=hello+world", "knowledge/10#fragment", "knowledge/10/../me" })
            Check(!(bool)route.Invoke(null,new object[] { HttpMethod.Get,path })!, "Noncanonical knowledge route allowed");
        string? fixture = Environment.GetEnvironmentVariable("OBLI_KNOWLEDGE_EXPORT");
        if (fixture != null) {
            using var source = JsonDocument.Parse(File.ReadAllText(fixture));
            var root = source.RootElement;
            int id = root.GetProperty("detail").GetProperty("article").GetProperty("id").GetInt32();
            fake.Raw(root.GetProperty("page").GetRawText()); await client.KnowledgeAsync(session,"",null,CancellationToken.None);
            fake.Raw(root.GetProperty("detail").GetRawText()); var actual = await client.KnowledgeArticleAsync(session,id,CancellationToken.None);
            Check(actual.ToReference().ProviderText == root.GetProperty("rendered_reference").GetString(), "Actual PHP rendered reference differs from native budget");
        }
        return 5;
    }
}