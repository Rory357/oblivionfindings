using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

public sealed record SupportSite(int Id, string Name)
{
    public override string ToString() => Name;
}
public sealed record StaffAccount(string Name, string Email, IReadOnlyList<SupportSite> Sites, bool ComputerContextEnabled=false);
public sealed record SupportTicket(int Id, string Reference, string Title, string Status, string Path);
public sealed class StaffSession
{
    internal string Token { get; }
    public Uri Origin { get; }
    public DateTimeOffset ExpiresAt { get; }
    internal StaffSession(string token, Uri origin, DateTimeOffset expiresAt) { Token = token; Origin = origin; ExpiresAt = expiresAt; }
    public override string ToString() => "Private Obli session";
}
public sealed class ObliAccessException : Exception { public ObliAccessException() : base("Sign in again to use your support account.") {} }

public sealed class ObliChatRejectedException : Exception
{
    public ObliChatRejectedException(string message) : base(message) {}
}

public sealed partial class ObliClient : IDisposable
{
    readonly HttpClient http;
    readonly TimeProvider contextClock;
    public Uri Origin { get; }
    public ObliClient(string origin) : this(origin, new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false }) {}
    internal ObliClient(string origin, HttpMessageHandler handler, TimeProvider? contextClock=null)
    {
        this.contextClock=contextClock??TimeProvider.System;
        Origin = ValidateOrigin(origin);
        http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(50) };
    }
    public static Uri ValidateOrigin(string origin)
    {
        if (origin != origin.Trim() || !Uri.TryCreate(origin, UriKind.Absolute, out var uri) || uri.Scheme != "https" ||
            uri.UserInfo.Length != 0 || uri.Query.Length != 0 || uri.Fragment.Length != 0 ||
            uri.AbsolutePath != "/" || origin.Any(char.IsControl) || origin.Contains('\\'))
            throw new InvalidDataException("The configured Oblivion server is invalid.");
        return new Uri(uri.GetLeftPart(UriPartial.Authority));
    }
    internal static string Text(JsonElement value, string name, int limit)
    {
        string text = value.GetProperty(name).GetString() ?? throw new InvalidDataException();
        if (text.Length > limit || text.Any(c => char.IsControl(c) && c != '\n' && c != '\r' && c != '\t')) throw new InvalidDataException();
        return text;
    }
    async Task<JsonDocument> SendAsync(HttpMethod method, string path, object? body, StaffSession? session, CancellationToken cancellation)
    {
        if (!IsComputerContextRoute(method,path) && !IsTicketRoute(method, path) && !IsKnowledgeRoute(method, path) && path is not ("token" or "me" or "logout" or "chat/options" or "chat") && !(path.StartsWith("chat/", StringComparison.Ordinal) && Guid.TryParseExact(path[5..], "D", out _))) throw new InvalidOperationException();
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellation);
        timeout.CancelAfter(TimeSpan.FromSeconds(path == "chat" ? 50 : 20)); cancellation = timeout.Token;
        using var request = new HttpRequestMessage(method, new Uri(Origin, "/api/obli/" + path));
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        if (session != null)
        {
            if (session.Origin != Origin || session.ExpiresAt <= DateTimeOffset.UtcNow) throw new ObliAccessException();
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", session.Token);
        }
        if (body != null) request.Content = JsonContent.Create(body);
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellation);
        if (response.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden) throw new ObliAccessException();
        if (response.StatusCode == HttpStatusCode.NotFound && (IsKnowledgeRoute(method, path) || path == "chat" || path.StartsWith("chat/", StringComparison.Ordinal)))
            await CheckKnowledgeUnavailableAsync(response, cancellation);
        if ((IsComputerContextRoute(method,path) && !path.StartsWith("tickets/requests/",StringComparison.Ordinal) || method==HttpMethod.Post&&path=="tickets") && response.StatusCode==HttpStatusCode.NotFound) throw new ObliComputerContextUnavailableException();
        if ((IsComputerContextRoute(method,path)||method==HttpMethod.Post&&path=="tickets") && response.StatusCode==HttpStatusCode.UnprocessableEntity)
        {
            using var error=await ReadResponseJsonAsync(response,cancellation);TicketFields(error.RootElement,"code","message");
            if(TicketText(error.RootElement,"code",40,true)=="invalid_request"){_=TicketText(error.RootElement,"message",300,true);throw new ObliTicketRejectedException("invalid_request");}
            throw new IOException("Oblivion could not confirm this request.");
        }
        if (IsKnowledgeRoute(method, path) && response.StatusCode != HttpStatusCode.OK) throw new IOException("Oblivion could not confirm this knowledge request.");
        if (IsTicketRoute(method, path) && !(method == HttpMethod.Post && path == "tickets"))
            return await ReadTicketResponseAsync(response, method, path, cancellation);
        if (path == "chat" && (int)response.StatusCode is 404 or 409 or 422 or 429 or 503)
            throw new ObliChatRejectedException((int)response.StatusCode switch {
                409 => "AI settings changed. Reload them and review the provider before sending.",
                429 => "The request limit or AI budget has been reached. Try later or contact your administrator.",
                422 => "The message could not be accepted. Start a shorter conversation.",
                _ => "AI chat is not available. Ask your administrator to check Obli settings."
            });
        if (IsComputerContextRoute(method,path) && response.StatusCode!=HttpStatusCode.OK || method==HttpMethod.Post&&path=="tickets"&&response.StatusCode is not (HttpStatusCode.OK or HttpStatusCode.Created))throw new IOException("Oblivion could not confirm this request. Keep your draft.");
        if (!response.IsSuccessStatusCode) throw new IOException(response.StatusCode == HttpStatusCode.NotFound
            ? "Obli staff support is not enabled on this server yet."
            : "Oblivion could not confirm this request. Keep your draft and try again.");
        if (response.StatusCode == HttpStatusCode.NoContent) return JsonDocument.Parse("{}");
        return await ReadResponseJsonAsync(response, cancellation);
    }
    static async Task<JsonDocument> ReadResponseJsonAsync(HttpResponseMessage response, CancellationToken cancellation)
    {
        if (response.Content.Headers.ContentType?.MediaType != "application/json") throw new InvalidDataException("Unexpected server response.");
        using var stream = await response.Content.ReadAsStreamAsync(cancellation);
        using var buffer = new MemoryStream();
        var block = new byte[4096];
        int count;
        while ((count = await stream.ReadAsync(block, cancellation)) > 0)
        {
            if (buffer.Length + count > 131072) throw new InvalidDataException("Server response is too large.");
            buffer.Write(block, 0, count);
        }
        var document = JsonDocument.Parse(buffer.ToArray(), new JsonDocumentOptions { MaxDepth = 12 });
        try { RejectDuplicates(document.RootElement); return document; } catch { document.Dispose(); throw; }
    }
    static void RejectDuplicates(JsonElement element)
    {
        if (element.ValueKind == JsonValueKind.Object)
        {
            var names = new HashSet<string>(StringComparer.Ordinal);
            foreach (var property in element.EnumerateObject())
            { if (!names.Add(property.Name)) throw new InvalidDataException(); RejectDuplicates(property.Value); }
        }
        else if (element.ValueKind == JsonValueKind.Array) foreach (var item in element.EnumerateArray()) RejectDuplicates(item);
    }
    public async Task<StaffSession> ExchangeAsync(string code, string verifier, string redirect, CancellationToken cancellation)
    {
        using var response = await SendAsync(HttpMethod.Post, "token", new {
            client_id = "obli-windows", grant_type = "authorization_code", code,
            code_verifier = verifier, redirect_uri = redirect
        }, null, cancellation);
        var data = response.RootElement;
        string token = Text(data, "access_token", 64);
        int seconds = data.GetProperty("expires_in").GetInt32();
        if (token.Length != 64 || token.Any(c => !char.IsAsciiHexDigit(c)) || Text(data, "token_type", 10) != "Bearer" ||
            ValidateOrigin(Text(data, "issuer", 2048)) != Origin || seconds < 1 || seconds > 3600) throw new InvalidDataException();
        return new StaffSession(token, Origin, DateTimeOffset.UtcNow.AddSeconds(seconds));
    }
    public async Task<StaffAccount> AccountAsync(StaffSession session, CancellationToken cancellation)
    {
        using var response = await SendAsync(HttpMethod.Get, "me", null, session, cancellation);
        var root = response.RootElement;
        var sites = root.GetProperty("sites").EnumerateArray().Select(s => new SupportSite(s.GetProperty("id").GetInt32(), Text(s, "name", 300))).ToList();
        if (sites.Count is < 1 or > 2000 || sites.Any(s => s.Id <= 0) || sites.Select(s => s.Id).Distinct().Count() != sites.Count) throw new InvalidDataException();
        return new StaffAccount(Text(root, "name", 300), Text(root, "email", 320), sites, root.TryGetProperty("computer_context_enabled",out var contextEnabled)&&contextEnabled.GetBoolean());
    }
    public async Task<IReadOnlyList<SupportTicket>> TicketsAsync(StaffSession session, CancellationToken cancellation)
        => (await TicketPageAsync(session, null, cancellation)).Tickets;
    public async Task<SupportTicket> SubmitAsync(StaffSession session, Guid requestId, int siteId, string title, string description, string category, CancellationToken cancellation, bool computerContext=false)
    {
        ValidateTicketSubmission(requestId,siteId,title,description,category);
        using var response = await SendAsync(HttpMethod.Post, "tickets", computerContext ? (object)new { request_uuid = requestId, site_id = siteId, title, description, category, computer_context=true } : new { request_uuid = requestId, site_id = siteId, title, description, category }, session, cancellation);
        return ParseCreationReceipt(response.RootElement,requestId).Ticket;
    }
    internal static SupportTicket ParseTicket(JsonElement ticket)
    {
        int id = ticket.GetProperty("id").GetInt32();
        string path = Text(ticket, "path", 80);
        if (id <= 0 || path != "/it/tickets/" + id) throw new InvalidDataException("Invalid ticket link.");
        return new SupportTicket(id, Text(ticket, "reference", 100), Text(ticket, "title", 200), Text(ticket, "status", 40), path);
    }
    public async Task LogoutAsync(StaffSession session, CancellationToken cancellation)
    {
        using var response = await SendAsync(HttpMethod.Post, "logout", new {}, session, cancellation);
    }
    public void Dispose() => http.Dispose();
}







