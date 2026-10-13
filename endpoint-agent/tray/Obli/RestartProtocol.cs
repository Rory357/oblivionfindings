using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.Json;
namespace Oblivion.Obli;

public sealed record RestartState(Uri Origin, string Status, Guid? CommandId, Guid? AttemptId,
    string PayloadHash, string ConsentToken, long PolicyVersion, string ConsentMode,
    string Technician, string Purpose, DateTimeOffset? ExecuteAt, DateTimeOffset? ExpiresAt,
    int WarningSeconds, int RemainingSeconds, string ReasonCode, bool MutationStarted, bool CanCancel)
{
    public override string ToString() => "Private scheduled computer restart";
}
internal static class RestartProtocol
{
    static readonly string[] Fields = { "protocol", "server_origin", "status", "command_uuid", "attempt_uuid",
        "payload_hash", "consent_token", "policy_version", "consent_mode", "technician", "purpose", "execute_at",
        "expires_at", "warning_seconds", "remaining_seconds", "reason_code", "mutation_started", "can_cancel" };
    static readonly string[] Reasons = { "", "declined", "cancelled", "expired", "consent_lost", "warning_incomplete",
        "clock_changed", "offline", "policy_unavailable", "boot_unavailable", "restart_refused", "interrupted",
        "state_unavailable", "restart_requested", "boot_observed", "operation_failed" };
    internal static RestartState Parse(byte[] bytes, Uri origin, DateTimeOffset now)
    {
        try { return ParseCore(bytes, origin, now); }
        catch (Exception error) when (error is JsonException or InvalidOperationException or FormatException or
            KeyNotFoundException or OverflowException or ArgumentException)
        { throw new InvalidDataException("The restart response is invalid."); }
    }
    static RestartState ParseCore(byte[] bytes, Uri origin, DateTimeOffset now)
    {
        if (bytes.Length is 0 or > 16384) throw new InvalidDataException();
        using var json = JsonDocument.Parse(bytes, new JsonDocumentOptions { MaxDepth = 2 });
        if (json.RootElement.ValueKind != JsonValueKind.Object) throw new InvalidDataException();
        var map = new Dictionary<string, JsonElement>(StringComparer.Ordinal);
        foreach (var property in json.RootElement.EnumerateObject())
            if (!Fields.Contains(property.Name) || !map.TryAdd(property.Name, property.Value)) throw new InvalidDataException();
        if (map.Count != Fields.Length || map["protocol"].GetInt32() != 1) throw new InvalidDataException();
        string Text(string key, int maximum)
        {
            string value = map[key].GetString() ?? throw new InvalidDataException();
            int length = 0;
            foreach (var rune in value.EnumerateRunes())
            {
                if (Rune.GetUnicodeCategory(rune) is UnicodeCategory.Control or UnicodeCategory.Format || rune.Value == 0xfffd)
                    throw new InvalidDataException();
                length++;
            }
            if (length > maximum) throw new InvalidDataException();
            return value;
        }
        var actual = ObliClient.ValidateOrigin(Text("server_origin", 2048));
        if (TicketComputerProtocol.OriginHash(actual) != TicketComputerProtocol.OriginHash(origin)) throw new InvalidDataException();
        string status = Text("status", 20), command = Text("command_uuid", 36), attempt = Text("attempt_uuid", 36),
            hash = Text("payload_hash", 64), token = Text("consent_token", 64), mode = Text("consent_mode", 10),
            technician = Text("technician", 200), purpose = Text("purpose", 500), execution = Text("execute_at", 20),
            expiry = Text("expires_at", 20), reason = Text("reason_code", 40);
        long policy = map["policy_version"].GetInt64();
        int warning = map["warning_seconds"].GetInt32(), remaining = map["remaining_seconds"].GetInt32();
        bool started = map["mutation_started"].GetBoolean(), cancel = map["can_cancel"].GetBoolean();
        if (status is not ("idle" or "requested" or "countdown" or "awaiting_result" or "awaiting_boot" or
            "succeeded" or "failed" or "uncertain" or "declined" or "cancelled" or "expired") || !Reasons.Contains(reason))
            throw new InvalidDataException();
        if (status == "idle")
        {
            if (new[] { command, attempt, hash, token, mode, technician, purpose, execution, expiry, reason }.Any(x => x != "") ||
                policy != 0 || warning != 0 || remaining != 0 || started || cancel) throw new InvalidDataException();
            return new(actual, status, null, null, "", "", 0, "", "", "", null, null, 0, 0, "", false, false);
        }
        bool Timestamp(string value, out DateTimeOffset result) => DateTimeOffset.TryParseExact(value,
            "yyyy-MM-dd'T'HH:mm:ss'Z'", CultureInfo.InvariantCulture,
            DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out result);
        if (!Guid.TryParseExact(command, "D", out var commandId) || commandId == Guid.Empty ||
            !Guid.TryParseExact(attempt, "D", out var attemptId) || attemptId == Guid.Empty ||
            !TicketComputerProtocol.Hex(hash) || policy is < 1 or > 9007199254740991 ||
            mode is not ("attended" or "unattended") || string.IsNullOrWhiteSpace(technician) ||
            string.IsNullOrWhiteSpace(purpose) || !Timestamp(execution, out var executeAt) ||
            !Timestamp(expiry, out var expiresAt) || expiresAt != executeAt.AddSeconds(5) ||
            warning is < 60 or > 3600 || remaining is < 0 or > 86400 || executeAt > now.AddDays(1))
            throw new InvalidDataException();
        bool actionable = status is "requested" or "countdown";
        if ((actionable && (!TicketComputerProtocol.Hex(token) || started || !cancel || reason != "")) ||
            (!actionable && (token != "" || cancel)) || (status == "requested" && mode != "attended"))
            throw new InvalidDataException();
        if ((status == "awaiting_result" && reason != "") ||
            (status == "awaiting_boot" && (!started || reason != "restart_requested")) ||
            (status == "succeeded" && (!started || reason != "boot_observed")) ||
            (status == "uncertain" && reason is not ("interrupted" or "state_unavailable" or "policy_unavailable")) ||
            (status == "failed" && (reason is "" or "declined" or "cancelled" or "expired" or "restart_requested" or "boot_observed" ||
                (started && reason != "restart_refused"))) ||
            ((status is "declined" or "cancelled" or "expired") && (started || reason != status)))
            throw new InvalidDataException();
        return new(actual, status, commandId, attemptId, hash, token, policy, mode, technician, purpose,
            executeAt, expiresAt, warning, remaining, reason, started, cancel);
    }
    internal static RestartState ParseReply(byte[] bytes, Uri origin, RestartState expected, DateTimeOffset now)
    {
        var actual = Parse(bytes, origin, now);
        if (actual.CommandId != expected.CommandId || actual.AttemptId != expected.AttemptId ||
            actual.PayloadHash != expected.PayloadHash || actual.PolicyVersion != expected.PolicyVersion ||
            actual.ConsentMode != expected.ConsentMode || actual.Technician != expected.Technician ||
            actual.Purpose != expected.Purpose || actual.ExecuteAt != expected.ExecuteAt ||
            actual.ExpiresAt != expected.ExpiresAt || actual.WarningSeconds != expected.WarningSeconds)
            throw new InvalidDataException("The restart response did not match this request.");
        return actual;
    }
    internal static object Poll(Uri origin) => new { version = 1, operation = "restart_poll",
        expected_origin_hash = TicketComputerProtocol.OriginHash(origin) };
    internal static object Message(Uri origin, RestartState state, string decision, bool visibility)
    {
        if (state.CommandId is null || state.AttemptId is null ||
            TicketComputerProtocol.OriginHash(origin) != TicketComputerProtocol.OriginHash(state.Origin) ||
            !TicketComputerProtocol.Hex(state.PayloadHash) || !TicketComputerProtocol.Hex(state.ConsentToken) ||
            state.Status is not ("requested" or "countdown") ||
            (visibility && decision is not ("visible" or "hidden")) ||
            (!visibility && decision is not ("allow" or "decline" or "cancel")) ||
            (!visibility && (decision is "allow" or "decline") && state.Status != "requested") ||
            (!visibility && decision == "cancel" && !state.CanCancel) ||
            (decision != "hidden" && (state.ExpiresAt <= DateTimeOffset.UtcNow || state.MutationStarted)))
            throw new InvalidDataException("This restart decision is no longer available.");
        return new { version = 1, operation = visibility ? "restart_visible" : "restart_decide",
            expected_origin_hash = TicketComputerProtocol.OriginHash(origin),
            attempt_uuid = state.AttemptId.Value.ToString("D"), payload_hash = state.PayloadHash,
            consent_token = state.ConsentToken, decision };
    }
}
