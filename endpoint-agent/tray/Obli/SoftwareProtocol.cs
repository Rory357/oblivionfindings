using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace Oblivion.Obli;

public sealed record SoftwareState(Uri Origin, string Status, Guid? CommandId, Guid? AttemptId,
    string PayloadHash, string ConsentToken, long PolicyVersion, string ConsentMode,
    string Technician, string Purpose, string PackageName, long PackageVersion, string ProductVersion,
    string Publisher, string Architecture, long SizeBytes, DateTimeOffset? ExpiresAt,
    string ReasonCode, bool MutationStarted, bool RebootRequired, bool CanDecline)
{
    public override string ToString() => "Private approved software request";
}

internal static class SoftwareProtocol
{
    static readonly string[] Fields = {
        "protocol", "server_origin", "status", "command_uuid", "attempt_uuid", "payload_hash",
        "consent_token", "policy_version", "consent_mode", "technician", "purpose", "package_name",
        "package_version", "product_version", "publisher", "architecture", "size_bytes", "expires_at",
        "reason_code", "mutation_started", "reboot_required", "can_decline"
    };
    static readonly string[] Reasons = {
        "", "staging", "ready", "installing", "installed", "installed_restart_required",
        "installer_busy", "installer_failed", "restart_initiated", "artifact_unavailable",
        "artifact_invalid", "signature_invalid", "product_mismatch", "architecture_mismatch",
        "already_installed", "downgrade_denied", "policy_unavailable", "consent_lost", "declined",
        "expired", "interrupted", "state_unavailable", "operation_failed"
    };

    internal static SoftwareState Parse(byte[] bytes, Uri origin, DateTimeOffset now)
    {
        try { return ParseCore(bytes, origin, now); }
        catch (Exception error) when (error is JsonException or InvalidOperationException or FormatException or KeyNotFoundException or OverflowException or ArgumentException)
        { throw new InvalidDataException("The software request could not be verified."); }
    }

    static SoftwareState ParseCore(byte[] bytes, Uri origin, DateTimeOffset now)
    {
        if (bytes.Length is 0 or >32768) throw new InvalidDataException();
        using var document = JsonDocument.Parse(bytes, new JsonDocumentOptions { MaxDepth = 2 });
        var map = new Dictionary<string, JsonElement>(StringComparer.Ordinal);
        if (document.RootElement.ValueKind != JsonValueKind.Object) throw new InvalidDataException();
        foreach (var field in document.RootElement.EnumerateObject())
            if (!Fields.Contains(field.Name) || !map.TryAdd(field.Name, field.Value)) throw new InvalidDataException();
        if (map.Count != Fields.Length || map["protocol"].GetInt32() != 1) throw new InvalidDataException();
        string Text(string key, int max)
        {
            string text = map[key].GetString() ?? throw new InvalidDataException();
            int count = 0;
            foreach (var rune in text.EnumerateRunes())
            {
                if (Rune.GetUnicodeCategory(rune) is UnicodeCategory.Control or UnicodeCategory.Format || rune.Value == 0xfffd)
                    throw new InvalidDataException();
                count++;
            }
            if (count > max) throw new InvalidDataException();
            return text;
        }
        var actualOrigin = ObliClient.ValidateOrigin(Text("server_origin", 2048));
        if (TicketComputerProtocol.OriginHash(actualOrigin) != TicketComputerProtocol.OriginHash(origin)) throw new InvalidDataException();
        string status = Text("status", 24), command = Text("command_uuid", 36), attempt = Text("attempt_uuid", 36),
            hash = Text("payload_hash", 64), token = Text("consent_token", 64), mode = Text("consent_mode", 10),
            technician = Text("technician", 200), purpose = Text("purpose", 500), name = Text("package_name", 200),
            productVersion = Text("product_version", 13), publisher = Text("publisher", 200), architecture = Text("architecture", 5),
            expiry = Text("expires_at", 20), reason = Text("reason_code", 40);
        long policyVersion = map["policy_version"].GetInt64(), packageVersion = map["package_version"].GetInt64(),
            size = map["size_bytes"].GetInt64();
        bool started = map["mutation_started"].GetBoolean(), reboot = map["reboot_required"].GetBoolean(),
            canDecline = map["can_decline"].GetBoolean();
        if (status is not ("idle" or "staging" or "requested" or "installing" or "awaiting_observation" or "succeeded" or "failed" or "uncertain" or "declined" or "expired"))
            throw new InvalidDataException();
        if (status == "idle")
        {
            if (new[] { command, attempt, hash, token, mode, technician, purpose, name, productVersion, publisher, architecture, expiry, reason }.Any(x => x != "") ||
                policyVersion != 0 || packageVersion != 0 || size != 0 || started || reboot || canDecline) throw new InvalidDataException();
            return new(actualOrigin, status, null, null, "", "", 0, "", "", "", "", 0, "", "", "", 0, null, "", false, false, false);
        }
        if (!Guid.TryParseExact(command, "D", out var commandId) || commandId == Guid.Empty ||
            !Guid.TryParseExact(attempt, "D", out var attemptId) || attemptId == Guid.Empty ||
            !TicketComputerProtocol.Hex(hash) || policyVersion is < 1 or > 9007199254740991 ||
            packageVersion is < 1 or > 9007199254740991 || size is < 1 or > 536870912 ||
            mode is not ("attended" or "unattended") || architecture is not ("amd64" or "arm64") ||
            new[] { technician, purpose, name, publisher }.Any(string.IsNullOrWhiteSpace) ||
            !VersionValid(productVersion) || !Reasons.Contains(reason) ||
            !DateTimeOffset.TryParseExact(expiry, "yyyy-MM-dd'T'HH:mm:ss'Z'", CultureInfo.InvariantCulture,
                DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out var expires)) throw new InvalidDataException();
        if (status == "requested")
        {
            if (mode != "attended" || !TicketComputerProtocol.Hex(token) || !canDecline || started || reboot ||
                reason != "ready" || expires <= now) throw new InvalidDataException();
        }
        else if (token != "" || canDecline) throw new InvalidDataException();
        if (status == "staging" && (started || reboot || reason != "staging")) throw new InvalidDataException();
        if (status == "installing" && reason != "installing") throw new InvalidDataException();
        if (status == "succeeded" && (reason is not ("installed" or "installed_restart_required") || !started ||
            reboot != (reason == "installed_restart_required"))) throw new InvalidDataException();
        if (status is "declined" or "expired" && (started || reboot || reason != status)) throw new InvalidDataException();
        if (status == "uncertain" && reason is not ("interrupted" or "state_unavailable" or "restart_initiated" or "operation_failed"))
            throw new InvalidDataException();
        if (reason == "restart_initiated" && (status != "uncertain" || !started)) throw new InvalidDataException();
        return new(actualOrigin, status, commandId, attemptId, hash, token, policyVersion, mode, technician, purpose,
            name, packageVersion, productVersion, publisher, architecture, size, expires, reason, started, reboot, canDecline);
    }

    static bool VersionValid(string text)
    {
        if (!Regex.IsMatch(text, @"\A(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,4})\z")) return false;
        var parts = text.Split('.').Select(int.Parse).ToArray();
        return parts[0] <= 255 && parts[1] <= 255 && parts[2] <= 65535;
    }

    internal static SoftwareState ParseReply(byte[] bytes, Uri origin, SoftwareState expected, DateTimeOffset now)
    {
        var actual = Parse(bytes, origin, now);
        if (actual.CommandId != expected.CommandId || actual.AttemptId != expected.AttemptId ||
            actual.PayloadHash != expected.PayloadHash || actual.PolicyVersion != expected.PolicyVersion ||
            actual.ConsentMode != expected.ConsentMode || actual.Technician != expected.Technician ||
            actual.Purpose != expected.Purpose || actual.PackageName != expected.PackageName ||
            actual.PackageVersion != expected.PackageVersion || actual.ProductVersion != expected.ProductVersion ||
            actual.Publisher != expected.Publisher || actual.Architecture != expected.Architecture ||
            actual.SizeBytes != expected.SizeBytes || actual.ExpiresAt != expected.ExpiresAt)
            throw new InvalidDataException("The reply did not match this software request.");
        return actual;
    }

    internal static object Poll(Uri origin) => new { version = 1, operation = "software_poll", expected_origin_hash = TicketComputerProtocol.OriginHash(origin) };
    internal static object Answer(Uri origin, SoftwareState state, string decision)
    {
        if (state.Status != "requested" || !state.CanDecline || state.ConsentMode != "attended" ||
            state.AttemptId is null || state.AttemptId == Guid.Empty || state.CommandId is null || state.CommandId == Guid.Empty ||
            state.ExpiresAt is null || state.ExpiresAt <= DateTimeOffset.UtcNow || state.MutationStarted ||
            !TicketComputerProtocol.Hex(state.PayloadHash) || !TicketComputerProtocol.Hex(state.ConsentToken) ||
            TicketComputerProtocol.OriginHash(state.Origin) != TicketComputerProtocol.OriginHash(origin) ||
            decision is not ("allow" or "decline")) throw new InvalidDataException("This software request is no longer available for consent.");
        return new { version = 1, operation = "software_decide", expected_origin_hash = TicketComputerProtocol.OriginHash(origin),
            attempt_uuid = state.AttemptId.Value.ToString("D"), payload_hash = state.PayloadHash, consent_token = state.ConsentToken, decision };
    }
}
