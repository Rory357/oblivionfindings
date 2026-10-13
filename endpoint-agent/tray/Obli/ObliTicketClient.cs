using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

public sealed record SupportTicketPage(IReadOnlyList<SupportTicket> Tickets, int? NextBefore);
public sealed record SupportTicketComment(int Id, string Body, string Author, DateTimeOffset CreatedAt, bool BodyTruncated = false)
{
    public override string ToString() => "Private support ticket comment";
}
public sealed record SupportTicketDetail(SupportTicket Ticket, string Description, long Version, bool CanReply,
    IReadOnlyList<SupportTicketComment> Comments, int? NextBefore, bool DescriptionTruncated = false)
{
    public override string ToString() => "Private support ticket conversation";
}
public sealed record SupportReplyReceipt(int TicketId, Guid RequestId, SupportTicketComment Comment, long CommittedVersion)
{
    public override string ToString() => "Private support reply receipt";
}
public sealed class ObliTicketRejectedException : Exception
{
    public string Code { get; }
    internal ObliTicketRejectedException(string code) : base(code switch {
        "version_conflict" => "This ticket changed. Reload the conversation before sending your reply.",
        "reply_rejected" => "This reply could not be accepted. Reload the ticket and review your draft.",
        _ => "Review your reply before sending it again."
    }) { Code = code; }
}
public sealed class ObliTicketUnavailableException : Exception
{
    public ObliTicketUnavailableException() : base("This ticket is no longer available to your account.") {}
}

public sealed partial class ObliClient
{
    const long TicketMaximumVersion = 9007199254740991;
    static string TicketNumber(int id) => id > 0 ? id.ToString(CultureInfo.InvariantCulture) : throw new InvalidDataException("Invalid ticket identity.");
    static string TicketBefore(int? before) => before == null ? "" : "?before=" + TicketNumber(before.Value);
    static bool IsTicketRoute(HttpMethod method, string path)
    {
        var parts = path.Split('?');
        if (parts.Length > 2) return false;
        if (parts.Length == 2 && (method != HttpMethod.Get || !parts[1].StartsWith("before=", StringComparison.Ordinal) || !TicketRouteNumber(parts[1][7..]))) return false;
        var route = parts[0].Split('/');
        if (route[0] != "tickets") return false;
        if (route.Length == 1) return method == HttpMethod.Get || method == HttpMethod.Post && parts.Length == 1;
        if (!TicketRouteNumber(route[1])) return false;
        if (route.Length == 2) return method == HttpMethod.Get;
        if (route[2] != "replies" || parts.Length != 1) return false;
        if (route.Length == 3) return method == HttpMethod.Post;
        return route.Length == 4 && method == HttpMethod.Get && Guid.TryParseExact(route[3], "D", out var id) && id != Guid.Empty && route[3] == id.ToString("D");
    }
    static bool TicketRouteNumber(string value) => int.TryParse(value, NumberStyles.None, CultureInfo.InvariantCulture, out int id) && id > 0 && value == id.ToString(CultureInfo.InvariantCulture);
    static async Task<JsonDocument> ReadTicketResponseAsync(HttpResponseMessage response, HttpMethod method, string path, CancellationToken cancellation)
    {
        bool recovery = path.Split('?')[0].Split('/').Length == 4;
        if (response.StatusCode == HttpStatusCode.NotFound && !recovery && path.StartsWith("tickets/", StringComparison.Ordinal)) throw new ObliTicketUnavailableException();
        if (!response.IsSuccessStatusCode)
        {
            if (method == HttpMethod.Post && path.EndsWith("/replies", StringComparison.Ordinal) && (int)response.StatusCode is 409 or 422)
            {
                using var error = await ReadResponseJsonAsync(response, cancellation);
                TicketFields(error.RootElement, "code", "message");
                string code = TicketText(error.RootElement, "code", 40, true);
                _ = TicketText(error.RootElement, "message", 300, true);
                if ((response.StatusCode == HttpStatusCode.Conflict && code == "version_conflict") || ((int)response.StatusCode == 422 && code is ("reply_rejected" or "invalid_request")))
                    throw new ObliTicketRejectedException(code);
            }
            throw new IOException("Oblivion could not confirm this reply. Keep your draft and check its outcome before trying again.");
        }
        if ((int)response.StatusCode is not (200 or 201) || method == HttpMethod.Get && response.StatusCode != HttpStatusCode.OK)
            throw new InvalidDataException("Unexpected ticket response.");
        return await ReadResponseJsonAsync(response, cancellation);
    }
    public async Task<SupportTicketPage> TicketPageAsync(StaffSession session, int? before, CancellationToken cancellation)
    {
        using var json = await SendAsync(HttpMethod.Get, "tickets" + TicketBefore(before), null, session, cancellation);
        var data = json.RootElement;
        TicketFields(data, "tickets", "next_before");
        var rows = TicketArray(data.GetProperty("tickets"), 30).Select(ParseTicketSummary).ToArray();
        if (rows.Select(t => t.Id).Distinct().Count() != rows.Length || rows.Zip(rows.Skip(1), (a,b) => a.Id <= b.Id).Any(x => x) || before != null && rows.Any(t => t.Id >= before.Value)) throw new InvalidDataException();
        int? next = TicketCursor(data, rows.Select(t => t.Id).ToArray(), before);
        return new SupportTicketPage(rows, next);
    }
    public async Task<SupportTicketDetail> TicketAsync(StaffSession session, int id, int? before, CancellationToken cancellation)
    {
        using var json = await SendAsync(HttpMethod.Get, "tickets/" + TicketNumber(id) + TicketBefore(before), null, session, cancellation);
        var data = json.RootElement;
        TicketFields(data, "ticket", "comments", "next_before");
        var detail = ParseTicketDetail(data.GetProperty("ticket"), id);
        var rows = TicketArray(data.GetProperty("comments"), 10).Select(ParseTicketComment).ToArray();
        if (rows.Select(c => c.Id).Distinct().Count() != rows.Length || rows.Zip(rows.Skip(1), (a,b) => a.Id >= b.Id).Any(x => x) || before != null && rows.Any(c => c.Id >= before.Value)) throw new InvalidDataException();
        return detail with { Comments = rows, NextBefore = TicketCursor(data, rows.Select(c => c.Id).ToArray(), before) };
    }
    internal static void ValidateTicketReply(Guid requestUuid, long expectedVersion, string body)
    {
        if (requestUuid == Guid.Empty || expectedVersion < 1 || expectedVersion >= TicketMaximumVersion || string.IsNullOrWhiteSpace(body) || TicketScalarCount(body) > 5000 || StrictTicketUtf8.GetByteCount(body) > 20000 || body.Any(c => char.IsControl(c) && c is not ('\n' or '\r' or '\t'))) throw new InvalidDataException("Review your reply before sending it.");
    }
    public async Task<SupportReplyReceipt> ReplyAsync(StaffSession session, int id, Guid requestUuid, long expectedVersion, string body, CancellationToken cancellation)
    {
        ValidateTicketReply(requestUuid, expectedVersion, body);
        using var json = await SendAsync(HttpMethod.Post, "tickets/" + TicketNumber(id) + "/replies", new { request_uuid = requestUuid, expected_version = expectedVersion, body }, session, cancellation);
        var receipt = ParseTicketReceipt(json.RootElement, id, requestUuid);
        if (receipt.CommittedVersion <= expectedVersion || receipt.Comment.BodyTruncated || receipt.Comment.Body != body.Trim(' ', '\t', '\r', '\n', '\0', '\v')) throw new InvalidDataException("Unconfirmed reply receipt.");
        return receipt;
    }
    public async Task<SupportReplyReceipt> RecoverReplyAsync(StaffSession session, int id, Guid requestUuid, CancellationToken cancellation)
    {
        if (requestUuid == Guid.Empty) throw new InvalidDataException();
        using var json = await SendAsync(HttpMethod.Get, "tickets/" + TicketNumber(id) + "/replies/" + requestUuid.ToString("D"), null, session, cancellation);
        return ParseTicketReceipt(json.RootElement, id, requestUuid);
    }
    static SupportReplyReceipt ParseTicketReceipt(JsonElement data, int id, Guid request)
    {
        TicketFields(data, "ticket", "comment", "request_uuid", "committed_version");
        var ticket = ParseTicketDetail(data.GetProperty("ticket"), id);
        string uuid = TicketText(data, "request_uuid", 36, true);
        if (!Guid.TryParseExact(uuid, "D", out var actual) || actual != request || uuid != actual.ToString("D")) throw new InvalidDataException("Unconfirmed reply identity.");
        long version = TicketVersion(data, "committed_version");
        if (version > ticket.Version) throw new InvalidDataException("Unconfirmed reply version.");
        return new SupportReplyReceipt(id, actual, ParseTicketComment(data.GetProperty("comment")), version);
    }
    static SupportTicketDetail ParseTicketDetail(JsonElement data, int id)
    {
        TicketFields(data, "id", "reference", "title", "status", "updated_at", "path", "description", "description_truncated", "lock_version", "can_reply");
        var summary = ParseTicketValues(data);
        if (summary.Id != id) throw new InvalidDataException("Unconfirmed ticket identity.");
        bool reply = data.GetProperty("can_reply").GetBoolean();
        if (reply && summary.Status is not ("open" or "in_progress" or "waiting")) throw new InvalidDataException();
        return new SupportTicketDetail(summary, TicketText(data, "description", 12000, false), TicketVersion(data, "lock_version"), reply, Array.Empty<SupportTicketComment>(), null, data.GetProperty("description_truncated").GetBoolean());
    }
    static SupportTicket ParseTicketSummary(JsonElement data)
    {
        TicketFields(data, "id", "reference", "title", "status", "updated_at", "path");
        return ParseTicketValues(data);
    }
    static SupportTicket ParseTicketValues(JsonElement data)
    {
        int id = data.GetProperty("id").GetInt32();
        string status = TicketText(data, "status", 40, true), path = TicketText(data, "path", 80, true);
        if (id < 1 || path != "/it/tickets/" + TicketNumber(id) || status is not ("open" or "in_progress" or "waiting" or "resolved" or "closed")) throw new InvalidDataException("Invalid ticket summary.");
        if (data.GetProperty("updated_at").ValueKind != JsonValueKind.Null) _ = TicketTimestamp(data, "updated_at");
        return new SupportTicket(id, TicketText(data, "reference", 200, true), TicketText(data, "title", 200, true), status, path);
    }
    static SupportTicketComment ParseTicketComment(JsonElement data)
    {
        TicketFields(data, "id", "body", "body_truncated", "author", "created_at");
        int id = data.GetProperty("id").GetInt32();
        if (id < 1) throw new InvalidDataException();
        return new SupportTicketComment(id, TicketText(data, "body", 5000, true), TicketText(data, "author", 200, true), TicketTimestamp(data, "created_at"), data.GetProperty("body_truncated").GetBoolean());
    }
    static DateTimeOffset TicketTimestamp(JsonElement data, string name)
    {
        string value = TicketText(data, name, 40, true);
        string[] formats = { "yyyy-MM-dd'T'HH:mm:sszzz", "yyyy-MM-dd'T'HH:mm:ss.FFFFFFFzzz", "yyyy-MM-dd'T'HH:mm:ss'Z'", "yyyy-MM-dd'T'HH:mm:ss.FFFFFFF'Z'" };
        if (!DateTimeOffset.TryParseExact(value, formats, CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var at)) throw new InvalidDataException();
        return at;
    }
    static long TicketVersion(JsonElement data, string name)
    {
        long version = data.GetProperty(name).GetInt64();
        if (version < 1 || version > TicketMaximumVersion) throw new InvalidDataException();
        return version;
    }
    static int? TicketCursor(JsonElement data, int[] ids, int? before)
    {
        var value = data.GetProperty("next_before");
        if (value.ValueKind == JsonValueKind.Null) return null;
        int next = value.GetInt32();
        if (next < 1 || ids.Length == 0 || next != ids.Min() || before != null && next >= before.Value) throw new InvalidDataException();
        return next;
    }
    static IEnumerable<JsonElement> TicketArray(JsonElement data, int maximum)
    {
        if (data.ValueKind != JsonValueKind.Array || data.GetArrayLength() > maximum) throw new InvalidDataException();
        return data.EnumerateArray();
    }
    static readonly UTF8Encoding StrictTicketUtf8 = new(false, true);
    static int TicketScalarCount(string value)
    {
        _ = StrictTicketUtf8.GetByteCount(value); // Refuse unpaired UTF-16 instead of replacing immutable text.
        return value.EnumerateRunes().Count();
    }
    static string TicketText(JsonElement data, string name, int maximum, bool required)
    {
        string value = data.GetProperty(name).GetString() ?? throw new InvalidDataException();
        if (TicketScalarCount(value) > maximum || StrictTicketUtf8.GetByteCount(value) > maximum * 4 ||
            value.Any(c => char.IsControl(c) && c is not ('\n' or '\r' or '\t')) || required && string.IsNullOrWhiteSpace(value)) throw new InvalidDataException();
        return value;
    }
    static void TicketFields(JsonElement data, params string[] fields)
    {
        if (data.ValueKind != JsonValueKind.Object || data.EnumerateObject().Count() != fields.Length || data.EnumerateObject().Any(p => !fields.Contains(p.Name, StringComparer.Ordinal))) throw new InvalidDataException("Unexpected ticket response fields.");
    }
}