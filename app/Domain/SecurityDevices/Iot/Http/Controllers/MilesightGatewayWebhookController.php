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

    private function verifyAuthentication(Request $request, #[\SensitiveParameter] string $sharedSecret): bool
    {
        $signature = trim((string) $request->header('X-Milesight-Signature', ''));
        if ($signature !== '') {
            $cleanSig = str_starts_with(strtolower($signature), 'sha256=')
                ? substr($signature, 7)
                : $signature;
            $expected = hash_hmac('sha256', (string) $request->getContent(), $sharedSecret);

            return hash_equals($expected, strtolower($cleanSig));
        }

        $bearer = trim((string) $request->bearerToken());
        if ($bearer !== '') {
            return hash_equals($sharedSecret, $bearer);
        }

        return false;
    }
}
