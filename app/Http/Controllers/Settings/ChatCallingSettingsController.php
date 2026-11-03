<?php

namespace App\Http\Controllers\Settings;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteRelay;
use App\Http\Controllers\Controller;
use App\Services\Chat\ChatCallingSettings;
use App\Services\Chat\OblivionRelay;
use Illuminate\Http\Request;

/**
 * Settings › Chat › Chat calling settings: the call relay and voice-call server, kept in the
 * database instead of the server's .env. Administrators only (chat.calling.manage), and every
 * save asks for the person's password again.
 */
final class ChatCallingSettingsController extends Controller
{
    public function show(Request $request, ChatCallingSettings $settings)
    {
        $this->authorizeCalling($request);

        return response()->json($settings->page())->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, ChatCallingSettings $settings)
    {
        $this->authorizeCalling($request);
        $plain = array_values(array_diff(array_keys(ChatCallingSettings::FIELDS), ChatCallingSettings::SECRETS));
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'password' => ['required', 'string', 'current_password:web'],
            'values' => ['present', 'array:'.implode(',', $plain)],
            'values.relay_urls' => ['sometimes', 'nullable', 'array', 'list', 'min:1', 'max:4'],
            'values.relay_urls.*' => ['string', fn ($attribute, $value, $fail) => is_string($value) && EndpointRemoteRelay::isValidRelayUrl($value)
                ? null : $fail('Each relay address must look like turn:relay.example.com:3479?transport=udp or turns:relay.example.com:443?transport=tcp.')],
            'values.relay_credential_seconds' => ['sometimes', 'nullable', 'integer', 'between:60,3600'],
            'values.relay_health_url' => ['sometimes', 'nullable', 'string', 'regex:/\Ahttp:\/\/(127\.[0-9.]+|\[::1\]):[0-9]{1,5}\z/'],
            'values.relay_control_urls' => ['sometimes', 'nullable', 'array', 'list', 'min:1', 'max:4'],
            'values.relay_control_urls.*' => ['string', fn ($attribute, $value, $fail) => is_string($value) && OblivionRelay::isValidControlUrl($value)
                ? null : $fail('Each control address must be http:// with a private or loopback IP address and a port, such as http://127.0.0.1:9479.')],
            'values.relay_control_key_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[A-Za-z0-9._-]{1,64}\z/'],
            'values.calling_url' => ['sometimes', 'nullable', 'string', 'max:2048', fn ($attribute, $value, $fail) => self::ownServer($value, 'wss')
                ? null : $fail('The call server address must start with wss:// and be on your own server, not LiveKit Cloud.')],
            'values.calling_api_url' => ['sometimes', 'nullable', 'string', 'max:2048', fn ($attribute, $value, $fail) => self::ownServer($value, 'https')
                ? null : $fail('The call server API address must start with https:// and be on your own server, not LiveKit Cloud.')],
            'values.calling_api_key' => ['sometimes', 'nullable', 'string', 'regex:/\A[\x21-\x7E]{1,255}\z/'],
            'secrets' => ['sometimes', 'array:'.implode(',', ChatCallingSettings::SECRETS)],
            'secrets.*' => ['string', 'regex:/\A[\x21-\x7E]{32,512}\z/'],
            'clear_secrets' => ['sometimes', 'array', 'list'],
            'clear_secrets.*' => ['string', 'distinct', 'in:'.implode(',', ChatCallingSettings::SECRETS)],
        ], [
            'password.required' => 'Enter your password to save the calling settings.',
            'password.current_password' => 'That password isn’t right. Enter the password you sign in with.',
            'values.array' => 'Those settings aren’t ones this page can change.',
            'values.relay_urls.min' => 'Enter at least one relay address, or clear the field to use the server’s.',
            'values.relay_urls.max' => 'Enter up to four relay addresses.',
            'values.relay_credential_seconds.*' => 'A call pass must last between 1 and 60 minutes.',
            'values.relay_health_url.regex' => 'The status check address must be on this server, such as http://127.0.0.1:9478.',
            'values.relay_control_urls.min' => 'Enter at least one control address, or clear the field to use the server’s.',
            'values.relay_control_urls.max' => 'Enter up to four control addresses.',
            'values.relay_control_key_id.regex' => 'The control key name can use letters, numbers, dots, dashes and underscores, up to 64 characters, such as k2026a.',
            'values.calling_api_key.regex' => 'The call server API key can’t contain spaces.',
            'secrets.array' => 'Those keys aren’t ones this page can change.',
            'secrets.*.regex' => 'Each key must be 32 to 512 characters with no spaces. Use Generate to make one.',
            'clear_secrets.*.in' => 'Those keys aren’t ones this page can change.',
        ]);
        $values = [];
        foreach ($data['values'] as $field => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $values[$field] = $value === '' || $value === [] ? null : $value;
        }

        return response()->json($settings->save($request->user(), (int) $data['version'], $values, $data['secrets'] ?? [], $data['clear_secrets'] ?? []))
            ->header('Cache-Control', 'private, no-store');
    }

    private function authorizeCalling(Request $request): void
    {
        $user = $request->user();
        abort_unless($user?->canDo('settings.access.manage') && $user->canDo('chat.calling.manage'), 403, 'Only administrators can change the calling settings.');
    }

    /** The same rule SelfHostedCalling applies: the deployment's own server, never LiveKit Cloud. */
    private static function ownServer(mixed $url, string $scheme): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== $scheme) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && ! str_ends_with($host, '.livekit.cloud');
    }
}
