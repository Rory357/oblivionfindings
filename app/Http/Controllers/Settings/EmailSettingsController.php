<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\MicrosoftGraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailSettingsController extends Controller
{
    private const SETTINGS_KEY = 'settings.email.configuration';

    private const SMTP_PASSWORD_KEY = 'settings.email.smtp_password';

    public function index(Request $request)
    {
        $this->authorizeAccess($request);

        return inertia('settings/email-settings', [
            'settings' => $this->loadSettings(),
            'connections' => $this->loadConnections($request),
            'smtp_password_saved' => AppSetting::query()->where('key', self::SMTP_PASSWORD_KEY)->exists(),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeAccess($request);

        $validated = $this->validateSettings($request);

        $settings = [
            'provider' => $validated['provider'],
            'smtp_host' => $validated['smtp_host'] ?? '',
            'smtp_port' => (int) ($validated['smtp_port'] ?? config('mail.mailers.smtp.port', 587)),
            'smtp_encryption' => $validated['smtp_encryption'],
            'smtp_username' => $validated['smtp_username'] ?? '',
            'from_address' => $validated['from_address'] ?? '',
            'from_name' => $validated['from_name'] ?? '',
        ];

        AppSetting::updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => $settings],
        );

        if (($validated['smtp_password'] ?? '') !== '') {
            AppSetting::updateOrCreate(
                ['key' => self::SMTP_PASSWORD_KEY],
                ['value' => Crypt::encryptString($validated['smtp_password'])],
            );
        }

        return back()->with('success', 'Email settings updated.');
    }

    public function test(Request $request)
    {
        $this->authorizeAccess($request);

        $validated = $this->validateSettings($request);
        $provider = $validated['provider'];

        try {
            if ($provider === 'smtp') {
                $this->sendSmtpTest($request, $validated);

                return back()->with('success', 'Test email sent to '.$request->user()->email.'.');
            }

            if ($provider === 'microsoft') {
                $identity = $request->user()
                    ->identities()
                    ->where('provider', 'microsoft')
                    ->first();

                if (! $identity) {
                    return back()->with('error', 'Connect a Microsoft account before sending a test email.');
                }

                $sent = (new MicrosoftGraphService($identity))->sendMail(
                    $request->user()->email,
                    'Oblivion Findings email test',
                    '<p>This is a test email from Oblivion Findings.</p>'
                );

                return $sent
                    ? back()->with('success', 'Test email sent to '.$request->user()->email.'.')
                    : back()->with('error', 'Microsoft could not send the test email.');
            }

            return back()->with('warning', 'Test email is not available for Google Workspace yet.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Test email failed: '.$exception->getMessage());
        }
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSettings(Request $request): array
    {
        return $request->validate([
            'provider' => ['required', 'in:smtp,microsoft,google'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:tls,ssl,none'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1000'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSettings(): array
    {
        $defaults = [
            'provider' => 'smtp',
            'smtp_host' => (string) config('mail.mailers.smtp.host', ''),
            'smtp_port' => (int) config('mail.mailers.smtp.port', 587),
            'smtp_encryption' => (string) (config('mail.mailers.smtp.scheme') ?: 'none'),
            'smtp_username' => (string) (config('mail.mailers.smtp.username') ?? ''),
            'from_address' => (string) (config('mail.from.address') ?? ''),
            'from_name' => (string) (config('mail.from.name') ?? ''),
        ];

        $stored = AppSetting::query()->where('key', self::SETTINGS_KEY)->value('value');

        if (! is_array($stored)) {
            return $defaults;
        }

        return [
            'provider' => in_array($stored['provider'] ?? null, ['smtp', 'microsoft', 'google'], true)
                ? $stored['provider']
                : $defaults['provider'],
            'smtp_host' => (string) ($stored['smtp_host'] ?? $defaults['smtp_host']),
            'smtp_port' => (int) ($stored['smtp_port'] ?? $defaults['smtp_port']),
            'smtp_encryption' => in_array($stored['smtp_encryption'] ?? null, ['tls', 'ssl', 'none'], true)
                ? $stored['smtp_encryption']
                : $defaults['smtp_encryption'],
            'smtp_username' => (string) ($stored['smtp_username'] ?? $defaults['smtp_username']),
            'from_address' => (string) ($stored['from_address'] ?? $defaults['from_address']),
            'from_name' => (string) ($stored['from_name'] ?? $defaults['from_name']),
        ];
    }

    /**
     * @return array<string, array{connected: bool, email: string|null}>
     */
    private function loadConnections(Request $request): array
    {
        $identities = $request->user()
            ->identities()
            ->whereIn('provider', ['microsoft', 'google'])
            ->get()
            ->keyBy('provider');

        return [
            'microsoft' => [
                'connected' => $identities->has('microsoft'),
                'email' => $identities->get('microsoft')?->email,
            ],
            'google' => [
                'connected' => $identities->has('google'),
                'email' => $identities->get('google')?->email,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function sendSmtpTest(Request $request, array $validated): void
    {
        $scheme = $validated['smtp_encryption'] === 'none'
            ? null
            : $validated['smtp_encryption'];

        $passwordSetting = AppSetting::query()->where('key', self::SMTP_PASSWORD_KEY)->value('value');
        $password = ($validated['smtp_password'] ?? '') !== ''
            ? $validated['smtp_password']
            : ($passwordSetting ? Crypt::decryptString((string) $passwordSetting) : config('mail.mailers.smtp.password'));

        $defaultMailer = (string) config('mail.default', 'smtp');

        if (in_array($defaultMailer, ['array', 'log'], true)) {
            Mail::mailer($defaultMailer)->raw('This is a test email from Oblivion Findings.', function ($message) use ($request, $validated) {
                $message->to($request->user()->email)
                    ->subject('Oblivion Findings email test');

                if (($validated['from_address'] ?? '') !== '') {
                    $message->from(
                        $validated['from_address'],
                        $validated['from_name'] ?: config('mail.from.name')
                    );
                }
            });

            return;
        }

        config([
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $validated['smtp_host'] ?: config('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => (int) ($validated['smtp_port'] ?: config('mail.mailers.smtp.port', 587)),
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.username' => $validated['smtp_username'] ?: config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => $password,
            'mail.from.address' => $validated['from_address'] ?: config('mail.from.address'),
            'mail.from.name' => $validated['from_name'] ?: config('mail.from.name'),
        ]);

        Mail::mailer('smtp')->raw('This is a test email from Oblivion Findings.', function ($message) use ($request, $validated) {
            $message->to($request->user()->email)
                ->subject('Oblivion Findings email test');

            if (($validated['from_address'] ?? '') !== '') {
                $message->from(
                    $validated['from_address'],
                    $validated['from_name'] ?: config('mail.from.name')
                );
            }
        });
    }
}
