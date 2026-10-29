<?php

namespace App\Domain\SecurityDevices\Iot\Http\Controllers;

use App\Domain\SecurityDevices\Iot\Milesight\MilesightGatewayIntakeService;
use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class MilesightGatewayWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        Site $site,
        MilesightGatewayIntakeService $intake,
    ): JsonResponse {
        // D23 (Stephan, 30 Oct): Milesight's HTTPS push is answered only over the private network, so
        // sensor readings never cross the public internet. Anyone else gets a plain 404.
        if (! self::fromPrivateNetwork($request)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $secretRow = IntegrationSiteSecret::query()
            ->where('site_id', $site->id)
            ->whereIn('provider', ['milesight', 'milesight_lorawan'])
            ->whereIn('capability', ['gateway_webhook', 'lorawan_uplink'])
            ->where('is_enabled', true)
            ->first();

        if (! $secretRow instanceof IntegrationSiteSecret || ! is_string($secretRow->secret_encrypted) || $secretRow->secret_encrypted === '') {
            return response()->json(['accepted' => false, 'reason_code' => 'webhook_not_configured'], 403);
        }

        try {
            $sharedSecret = trim((string) Crypt::decryptString($secretRow->secret_encrypted));
        } catch (Throwable) {
            return response()->json(['accepted' => false, 'reason_code' => 'webhook_secret_unavailable'], 403);
        }

        if ($sharedSecret === '' || strlen($sharedSecret) < 16) {
            return response()->json(['accepted' => false, 'reason_code' => 'webhook_secret_weak'], 403);
        }

        if (! $this->verifyAuthentication($request, $sharedSecret)) {
            return response()->json(['accepted' => false, 'reason_code' => 'unauthorised'], 401);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return response()->json(['accepted' => false, 'reason_code' => 'invalid_json'], 422);
        }

        $result = $intake->ingestUplink((int) $site->id, $payload);

        return response()->json($result, $result['accepted'] ? 200 : 422);
    }

    /**
     * Whether the sender is on a private network: 10/8, 172.16/12, 192.168/16, loopback, or IPv6
     * unique-local (fc00::/7).
     *
     * On the .com, nginx terminates TLS and hands every request to PHP from 127.0.0.1, setting
     * `X-Real-IP` to the true sender (its `proxy_set_header` always overwrites anything the sender
     * sent). So when PHP's caller is loopback and `X-Real-IP` is present, that header is the sender;
     * otherwise the caller itself is. A public sender is never treated as private.
     */
    public static function fromPrivateNetwork(Request $request): bool
    {
        $caller = (string) $request->server('REMOTE_ADDR', '');
        if (filter_var($caller, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $sender = $caller;
        $forwarded = trim((string) $request->headers->get('X-Real-IP', ''));
        if (self::isLoopback($caller) && $forwarded !== '') {
            if (filter_var($forwarded, FILTER_VALIDATE_IP) === false) {
                return false;
            }
            $sender = $forwarded;
        }

        if (self::isLoopback($sender)) {
            return true;
        }

        // FILTER_FLAG_NO_PRIV_RANGE fails for RFC 1918 and fc00::/7, which is exactly what is wanted.
        return filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
            && filter_var($sender, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function isLoopback(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    /**
     * Phase A, item A8 (docs/security-devices/GOAL.md). Milesight gateways don't sign what they forward:
     * their HTTP(S) forwarding lets the installer add an HTTP header or an account and password, and
     * nothing in Milesight's documents defines an `X-Milesight-Signature`, which the earlier code checked.
     * So the gateway proves itself with the Site's secret, typed into the gateway as one of:
     *
     * - `Authorization: Bearer <secret>`;
     * - `X-Oblivion-Webhook-Token: <secret>`;
     * - HTTP Basic, with the secret as the password (any account name).
     *
     * Only https should carry it; the secret is checked in constant time. In production this route is
     * reached over the private network only (Stephan, 30 Oct, GOAL.md R8), never from the internet.
     */
    private function verifyAuthentication(Request $request, #[\SensitiveParameter] string $sharedSecret): bool
    {
        $bearer = trim((string) $request->bearerToken());
        if ($bearer !== '') {
            return hash_equals($sharedSecret, $bearer);
        }

        $token = trim((string) $request->header('X-Oblivion-Webhook-Token', ''));
        if ($token !== '') {
            return hash_equals($sharedSecret, $token);
        }

        $password = $request->getPassword();
        if (is_string($password) && $password !== '') {
            return hash_equals($sharedSecret, $password);
        }

        return false;
    }
}
