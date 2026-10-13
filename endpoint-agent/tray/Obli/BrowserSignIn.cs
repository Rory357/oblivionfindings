using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

namespace Oblivion.Obli;

/// <summary>External browser + S256 PKCE. Listens only on an ephemeral IPv4 loopback port.</summary>
public static class BrowserSignIn
{
    internal static string Base64Url(byte[] bytes) => Convert.ToBase64String(bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_');
    public static Task<StaffSession> RunAsync(ObliClient client, CancellationToken cancellation)
        => RunAsync(client, url => Process.Start(new ProcessStartInfo(url) { UseShellExecute = true }), cancellation);

    internal static async Task<StaffSession> RunAsync(ObliClient client, Action<string> openBrowser, CancellationToken cancellation)
    {
        using var deadline = CancellationTokenSource.CreateLinkedTokenSource(cancellation);
        deadline.CancelAfter(TimeSpan.FromMinutes(5));
        using var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.ExclusiveAddressUse = true;
        listener.Start(4);
        int port = ((IPEndPoint)listener.LocalEndpoint).Port;
        string redirect = "http://127.0.0.1:" + port + "/obli/callback";
        string verifier = Base64Url(RandomNumberGenerator.GetBytes(32));
        string challenge = Base64Url(SHA256.HashData(Encoding.ASCII.GetBytes(verifier)));
        string state = Base64Url(RandomNumberGenerator.GetBytes(32));
        string url = client.Origin.GetLeftPart(UriPartial.Authority) + "/obli/authorize?" + Query(new Dictionary<string, string> {
            ["client_id"] = "obli-windows", ["response_type"] = "code", ["code_challenge_method"] = "S256",
            ["code_challenge"] = challenge, ["redirect_uri"] = redirect, ["state"] = state
        });
        openBrowser(url);
        while (true)
        {
            using var socket = await listener.AcceptTcpClientAsync(deadline.Token);
            using var shortDeadline = CancellationTokenSource.CreateLinkedTokenSource(deadline.Token);
            shortDeadline.CancelAfter(TimeSpan.FromSeconds(3));
            string? code = null;
            bool denied = false;
            try
            {
                using var stream = socket.GetStream();
                string header = await ReadHeaderAsync(stream, shortDeadline.Token);
                (code, denied) = ParseCallback(header, port, state, client.Origin);
                string message = denied ? "Sign-in cancelled. You can return to Obli." : "Return to Obli to finish signing in.";
                byte[] body = Encoding.UTF8.GetBytes(message);
                byte[] response = Encoding.ASCII.GetBytes("HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\nCache-Control: no-store\r\nReferrer-Policy: no-referrer\r\nContent-Security-Policy: default-src 'none'\r\nConnection: close\r\nContent-Length: " + body.Length + "\r\n\r\n");
                await stream.WriteAsync(response, shortDeadline.Token);
                await stream.WriteAsync(body, shortDeadline.Token);
            }
            catch (Exception error) when (error is IOException or InvalidDataException or OperationCanceledException or UriFormatException or FormatException)
            {
                deadline.Token.ThrowIfCancellationRequested();
                continue; // Stray localhost traffic does not approve or cancel the genuine request.
            }
            if (denied) throw new OperationCanceledException("Sign-in was declined.");
            listener.Stop();
            return await client.ExchangeAsync(code!, verifier, redirect, deadline.Token);
        }
    }
    static string Query(Dictionary<string, string> values) => string.Join("&", values.Select(p => Uri.EscapeDataString(p.Key) + "=" + Uri.EscapeDataString(p.Value)));
    internal static async Task<string> ReadHeaderAsync(Stream stream, CancellationToken cancellation)
    {
        var bytes = new List<byte>();
        var one = new byte[1];
        while (bytes.Count < 8192)
        {
            if (await stream.ReadAsync(one, cancellation) == 0) throw new InvalidDataException();
            if (one[0] > 127 || one[0] == 0) throw new InvalidDataException();
            bytes.Add(one[0]);
            int n = bytes.Count;
            if (n >= 4 && bytes[n-4] == 13 && bytes[n-3] == 10 && bytes[n-2] == 13 && bytes[n-1] == 10)
                return Encoding.ASCII.GetString(bytes.ToArray());
        }
        throw new InvalidDataException("Invalid callback size.");
    }
    internal static (string? Code, bool Denied) ParseCallback(string header, int port, string state, Uri issuer)
    {
        var lines = header.Split("\r\n");
        var request = lines[0].Split(' ');
        if (request.Length != 3 || request[0] != "GET" || request[2] != "HTTP/1.1" || !request[1].StartsWith("/obli/callback?", StringComparison.Ordinal))
            throw new InvalidDataException();
        var hosts = lines.Skip(1).Where(s => s.StartsWith("Host:", StringComparison.OrdinalIgnoreCase)).ToArray();
        if (hosts.Length != 1 || hosts[0][5..].Trim() != "127.0.0.1:" + port || lines.Any(s => s.StartsWith("Content-Length:", StringComparison.OrdinalIgnoreCase) || s.StartsWith("Transfer-Encoding:", StringComparison.OrdinalIgnoreCase)))
            throw new InvalidDataException();
        var query = new Dictionary<string, string>(StringComparer.Ordinal);
        foreach (var item in request[1]["/obli/callback?".Length..].Split('&'))
        {
            var pair = item.Split('=', 2);
            if (pair.Length != 2 || pair[0] is not ("code" or "state" or "iss" or "error") || !query.TryAdd(pair[0], Uri.UnescapeDataString(pair[1]))) throw new InvalidDataException();
        }
        if (!query.TryGetValue("state", out var received) || received != state || !query.TryGetValue("iss", out var origin) || origin != issuer.GetLeftPart(UriPartial.Authority))
            throw new InvalidDataException();
        if (query.TryGetValue("error", out var error))
        { if (error != "access_denied" || query.ContainsKey("code")) throw new InvalidDataException(); return (null, true); }
        if (!query.TryGetValue("code", out var code) || code.Length != 64 || code.Any(c => !char.IsAsciiHexDigit(c))) throw new InvalidDataException();
        return (code, false);
    }
}
