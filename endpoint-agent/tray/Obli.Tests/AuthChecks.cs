using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using Oblivion.Obli;

static class AuthChecks
{
    static void Check(bool value) { if (!value) throw new Exception("Native authentication check failed."); }
    static void Reject(Action action) { try { action(); } catch (Exception e) when (e is not OutOfMemoryException) { return; } throw new Exception("Invalid authentication data was accepted."); }
    public static async Task<int> RunAsync()
    {
        const string origin = "https://care.example.test";
        const int port = 52101;
        string state = new('s', 43), code = new('a', 64);
        string Header(string query, string host = "127.0.0.1:52101") => "GET /obli/callback?" + query + " HTTP/1.1\r\nHost: " + host + "\r\n\r\n";
        string query = "code=" + code + "&state=" + state + "&iss=" + Uri.EscapeDataString(origin);
        var valid = BrowserSignIn.ParseCallback(Header(query), port, state, new Uri(origin));
        Check(valid.Code == code && !valid.Denied);
        foreach (var header in new[] {
            Header(query, "evil.test:52101"), Header(query, "localhost:52101"), Header(query + "&state=" + state),
            Header(query.Replace(state, new string('x', 43))), Header(query.Replace("care.example.test", "another.example.test")),
            Header(query + "&error=access_denied"), Header(query).Replace("GET ", "POST "),
            Header(query).Replace("/obli/callback?", "/different?"), Header(query).Replace("\r\n\r\n", "\r\nHost: 127.0.0.1:52101\r\n\r\n"),
            Header(query).Replace("\r\n\r\n", "\r\nContent-Length: 2\r\n\r\n"), Header(query).Replace(code, "short")
        }) Reject(() => BrowserSignIn.ParseCallback(header, port, state, new Uri(origin)));
        Check(BrowserSignIn.ParseCallback(Header("error=access_denied&state=" + state + "&iss=" + Uri.EscapeDataString(origin)), port, state, new Uri(origin)).Denied);
        foreach (var bad in new[] { new string('a', 8192), "GET /", "GET /\0\r\n\r\n" })
            Reject(() => BrowserSignIn.ReadHeaderAsync(new MemoryStream(Encoding.ASCII.GetBytes(bad)), CancellationToken.None).GetAwaiter().GetResult());
        Check(BrowserSignIn.Base64Url(System.Security.Cryptography.SHA256.HashData(Encoding.ASCII.GetBytes("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"))) == "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM");
        foreach (var bad in new[] { "http://care.example.test", "https://user:secret@care.example.test", origin + "/path", origin + "?token=secret", " " + origin, origin + "/#fragment" })
            Reject(() => ObliClient.ValidateOrigin(bad));

        var transport = new Replies();
        using var client = new ObliClient(origin, transport);
        string token = new('b', 64);
        transport.Json(new { access_token = token, token_type = "Bearer", expires_in = 1800, issuer = origin });
        var session = await client.ExchangeAsync(code, new string('v', 64), "http://127.0.0.1:52101/obli/callback", CancellationToken.None);
        Check(transport.Seen[0].Auth == null && !session.ToString().Contains(token));
        transport.Json(new { name = "Test Worker", email = "worker@example.test", sites = new[] { new { id = 5, name = "Test Site" } } });
        var account = await client.AccountAsync(session, CancellationToken.None);
        Check(account.Sites[0].Id == 5 && transport.Seen[1].Auth == "Bearer " + token);
        Guid request = Guid.NewGuid();
        var ticket = new { request_uuid = request, replayed = false, ticket = new { id = 10, reference = "IT-TEST", title = "Printer", status = "open", updated_at = "2026-10-08T10:00:00+00:00", path = "/it/tickets/10" } };
        transport.Json(ticket); transport.Json(ticket);
        await client.SubmitAsync(session, request, 5, "Printer", "Reviewed text", "hardware", CancellationToken.None);
        await client.SubmitAsync(session, request, 5, "Printer", "Reviewed text", "hardware", CancellationToken.None);
        Check(transport.Seen[2].Body == transport.Seen[3].Body);
        transport.Responses.Enqueue(new HttpResponseMessage(HttpStatusCode.Redirect) { Headers = { Location = new Uri("https://wrong.example") } });
        Reject(() => client.AccountAsync(session, CancellationToken.None).GetAwaiter().GetResult());
        transport.Json(new { request_uuid = request, replayed = false, ticket = new { id = 10, reference = "IT-TEST", title = "Printer", status = "open", updated_at = "2026-10-08T10:00:00+00:00", path = "https://wrong.example" } });
        Reject(() => client.SubmitAsync(session, request, 5, "Printer", "Text", "hardware", CancellationToken.None).GetAwaiter().GetResult());
        transport.Responses.Enqueue(new HttpResponseMessage(HttpStatusCode.Unauthorized));
        Reject(() => client.AccountAsync(session, CancellationToken.None).GetAwaiter().GetResult());
        var other = new StaffSession(token, new Uri("https://wrong.example"), DateTimeOffset.UtcNow.AddMinutes(2));
        int calls = transport.Seen.Count;
        Reject(() => client.AccountAsync(other, CancellationToken.None).GetAwaiter().GetResult());
        Check(transport.Seen.Count == calls);
        transport.Responses.Enqueue(new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(new string('x', 131073), Encoding.UTF8, "application/json") });
        Reject(() => client.AccountAsync(session, CancellationToken.None).GetAwaiter().GetResult());
        var loopbackReplies = new Replies();
        loopbackReplies.Json(new { access_token = token, token_type = "Bearer", expires_in = 1800, issuer = origin });
        using var loopbackClient = new ObliClient(origin, loopbackReplies);
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(20));
        Task browser = Task.CompletedTask;
        string? observedChallenge = null;
        var signedIn = await BrowserSignIn.RunAsync(loopbackClient, authorization => {
            var uri = new Uri(authorization);
            Check(uri.Host == "care.example.test" && uri.AbsolutePath == "/obli/authorize");
            var parameters = new Dictionary<string,string>();
            foreach (var pair in uri.Query.TrimStart('?').Split('&')) {
                var parts = pair.Split('=',2); parameters.Add(parts[0], Uri.UnescapeDataString(parts[1]));
            }
            Check(!parameters.ContainsKey("code_verifier") && parameters["code_challenge_method"] == "S256");
            observedChallenge = parameters["code_challenge"];
            var target = new Uri(parameters["redirect_uri"]);
            browser = Task.Run(async () => {
                async Task<string> Send(string callbackState) {
                    using var socket = new System.Net.Sockets.TcpClient();
                    await socket.ConnectAsync(IPAddress.Loopback, target.Port, timeout.Token);
                    using var stream = socket.GetStream();
                    string callback = "GET /obli/callback?code=" + code + "&state=" + callbackState + "&iss=" + Uri.EscapeDataString(origin) + " HTTP/1.1\r\nHost: 127.0.0.1:" + target.Port + "\r\n\r\n";
                    await stream.WriteAsync(Encoding.ASCII.GetBytes(callback), timeout.Token);
                    var buffer = new byte[2048];
                    int length = await stream.ReadAsync(buffer, timeout.Token);
                    return Encoding.ASCII.GetString(buffer,0,length);
                }
                Check(await Send(new string('z',43)) == "");
                Check((await Send(parameters["state"])).StartsWith("HTTP/1.1 200", StringComparison.Ordinal));
            }, timeout.Token);
        }, timeout.Token);
        await browser;
        Check(signedIn.Origin == new Uri(origin));
        using var exchanged = JsonDocument.Parse(loopbackReplies.Seen[0].Body!);
        string proof = exchanged.RootElement.GetProperty("code_verifier").GetString()!;
        Check(BrowserSignIn.Base64Url(System.Security.Cryptography.SHA256.HashData(Encoding.ASCII.GetBytes(proof))) == observedChallenge);
        return 5;
    }
    sealed class Replies : HttpMessageHandler
    {
        public Queue<HttpResponseMessage> Responses { get; } = new();
        public List<(string? Auth, string? Body)> Seen { get; } = new();
        public void Json(object value) => Responses.Enqueue(new HttpResponseMessage(HttpStatusCode.OK) {
            Content = new StringContent(JsonSerializer.Serialize(value), Encoding.UTF8, "application/json")
        });
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellation)
        {
            Check(request.RequestUri!.Host == "care.example.test" && request.RequestUri.Scheme == "https");
            Seen.Add((request.Headers.Authorization?.ToString(), request.Content == null ? null : await request.Content.ReadAsStringAsync(cancellation)));
            return Responses.Dequeue();
        }
    }
}
