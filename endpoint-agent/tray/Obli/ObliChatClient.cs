using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

public sealed record ChatOptions(int Version, bool Enabled, string Provider, string Model, int RetentionDays, string DiagnosticCategories = "", bool KnowledgeEnabled = false)
{
    public bool AllowsDiagnostics(string category) => DiagnosticCategories.Split(',').Contains(category);
    public string ProviderName => Provider == "openai" ? "OpenAI" : "Anthropic (Claude)";
}
public sealed record ChatMessage(string Role, string Content, DiagnosticSnapshot? Diagnostics = null, KnowledgeReference? Knowledge = null)
{
    public object ToWire() {
        var value=new Dictionary<string,object>{{"role",Role},{"content",Content}};
        if(Diagnostics!=null)value["diagnostics"]=Diagnostics.ToWire();
        if(Knowledge!=null)value["knowledge"]=Knowledge.ToWire();
        return value;
    }
    public override string ToString() => "Private Obli chat message";
}
public sealed record ChatReply(Guid RequestId, string Status, string? Text)
{
    public override string ToString() => "Private Obli reply (" + Status + ")";
}
public sealed partial class ObliClient
{
    public async Task<ChatOptions> ChatOptionsAsync(StaffSession session, CancellationToken cancellation)
    {
        using var json = await SendAsync(HttpMethod.Get, "chat/options", null, session, cancellation);
        var data = json.RootElement;
        var categories=Array.Empty<string>();
        if(data.TryGetProperty("diagnostic_categories",out var groups)) {
            categories=groups.EnumerateArray().Select(g=>g.GetString()??"").ToArray();
            if(categories.Length>3||categories.Distinct().Count()!=categories.Length||categories.Any(g=>g is not ("system" or "software" or "services")))throw new InvalidDataException();
        }
        var options = new ChatOptions(data.GetProperty("version").GetInt32(), data.GetProperty("enabled").GetBoolean(),
            Text(data, "provider", 16), Text(data, "model", 120), data.GetProperty("chat_retention_days").GetInt32(),string.Join(",",categories.OrderBy(x=>x,StringComparer.Ordinal)), data.TryGetProperty("knowledge_enabled", out var knowledge) && knowledge.GetBoolean());
        if (options.Version < 0 || options.Provider is not ("openai" or "anthropic") ||
            options.RetentionDays is < 1 or > 90 || (options.Enabled && (options.Version < 1 || options.Model.Length == 0)))
            throw new InvalidDataException();
        return options;
    }
    public async Task<ChatReply> SendChatAsync(StaffSession session, Guid id, int version, IReadOnlyList<ChatMessage> messages, CancellationToken cancellation)
    {
        ValidateChat(messages);
        if (id == Guid.Empty || version < 1) throw new InvalidDataException();
        using var json = await SendAsync(HttpMethod.Post, "chat", new {
            request_uuid = id, configuration_version = version,
            messages = messages.Select(m => m.ToWire()).ToArray()
        }, session, cancellation);
        return ParseReply(json.RootElement, id);
    }
    public async Task<ChatReply> ChatOutcomeAsync(StaffSession session, Guid id, CancellationToken cancellation)
    {
        if (id == Guid.Empty) throw new InvalidDataException();
        using var json = await SendAsync(HttpMethod.Get, "chat/" + id.ToString("D"), null, session, cancellation);
        return ParseReply(json.RootElement, id);
    }
    internal static void ValidateChat(IReadOnlyList<ChatMessage> messages)
    {
        foreach (var message in messages) if (message.Knowledge != null) ValidateKnowledgeReference(message.Knowledge);
        if (messages.Count is < 1 or > 15 || messages.Count % 2 != 1 ||
            messages.Where((m, i) => m.Role != (i % 2 == 0 ? "user" : "assistant") ||
                (m.Role != "user" && (m.Diagnostics != null || m.Knowledge != null)) || string.IsNullOrWhiteSpace(m.Content) || m.Content.Any(c => char.IsControl(c) && c != '\n' && c != '\r' && c != '\t')).Any() ||
            messages.Sum(m => KnowledgeUtf8.GetByteCount(m.Content+(m.Diagnostics?.ProviderText??"")+(m.Knowledge?.ProviderText??""))) > 12000) throw new InvalidDataException("Start a new chat to keep the conversation within its size limit.");
    }
    static ChatReply ParseReply(JsonElement data, Guid expected)
    {
        if (!Guid.TryParseExact(Text(data, "request_uuid", 36), "D", out var id) || id != expected) throw new InvalidDataException();
        var status = Text(data, "status", 20);
        if (status is not ("completed" or "pending" or "unconfirmed")) throw new InvalidDataException();
        string? text = data.GetProperty("text").ValueKind == JsonValueKind.Null ? null : Text(data, "text", 65536);
        if ((status == "completed" && string.IsNullOrWhiteSpace(text)) || (status != "completed" && text != null)) throw new InvalidDataException();
        return new ChatReply(id, status, text);
    }
}
