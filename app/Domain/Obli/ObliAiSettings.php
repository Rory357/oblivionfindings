<?php

namespace App\Domain\Obli;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ObliAiSettings
{
    public const KEY = 'obli.ai.configuration';

    private const SECRET = 'obli.ai.provider_secret';

    public static function defaults(): array
    {
        return ['version' => 0, 'enabled' => false, 'provider' => 'openai', 'model' => '',
            'max_output_tokens' => 2048, 'daily_budget_usd' => '10.00', 'monthly_budget_usd' => '100.00',
            'input_price_usd' => '', 'output_price_usd' => '', 'chat_retention_days' => 7, 'diagnostic_categories' => [], 'knowledge_enabled' => false, 'technician_enabled' => false];
    }

    public function current(bool $lock = false): array
    {
        $row = AppSetting::query()->where('key', self::KEY)->when($lock, fn ($q) => $q->lockForUpdate())->first();

        return array_intersect_key((array) $row?->value, self::defaults()) + self::defaults();
    }

    public function administrator(User $actor): User
    {
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUser($actor, ['*']);
        abort_unless($actor->approved_at && $actor->email_verified_at && ! $actor->isExternalClinicianAccount()
            && $actor->canDo('settings.access.manage') && $actor->canDo('integrations.manage_secrets'), 403);

        return $actor;
    }

    public function present(): array
    {
        return $this->current() + [
            'key_saved' => AppSetting::query()->where('key', self::SECRET)->exists(),
            'helper_access_enabled' => (bool) (config('endpoint_management.enabled') && config('endpoint_management.obli.enabled')),
        ];
    }

    public function save(User $actor, #[\SensitiveParameter] array $input): array
    {
        $data = Validator::make($input, [
            'expected_actor_id' => ['required', 'integer', 'min:1'], 'expected_version' => ['required', 'integer', 'min:0'],
            'enabled' => ['required', 'boolean'], 'knowledge_enabled' => ['sometimes', 'boolean'], 'technician_enabled' => ['sometimes', 'boolean'], 'provider' => ['required', Rule::in(['openai', 'anthropic'])],
            'model' => [Rule::requiredIf((bool) ($input['enabled'] ?? false)), 'nullable', 'string', 'max:120', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,119}\z/'],
            'api_key' => ['nullable', 'string', 'min:12', 'max:2048', 'regex:/\A[\x21-\x7e]+\z/'],
            'clear_key' => ['required', 'boolean'], 'max_output_tokens' => ['required', 'integer', 'between:256,8192'],
            'daily_budget_usd' => ['required', 'string', 'regex:/\A[0-9]{1,5}(\.[0-9]{1,2})?\z/'],
            'monthly_budget_usd' => ['required', 'string', 'regex:/\A[0-9]{1,6}(\.[0-9]{1,2})?\z/'],
            'input_price_usd' => [Rule::requiredIf((bool) ($input['enabled'] ?? false)), 'nullable', 'string', 'regex:/\A[0-9]{1,4}(\.[0-9]{1,6})?\z/'],
            'output_price_usd' => [Rule::requiredIf((bool) ($input['enabled'] ?? false)), 'nullable', 'string', 'regex:/\A[0-9]{1,4}(\.[0-9]{1,6})?\z/'],
            'chat_retention_days' => ['required', 'integer', 'between:1,90'],
            'diagnostic_categories' => ['sometimes', 'array', 'max:3'],
            'diagnostic_categories.*' => ['required', 'string', 'distinct', Rule::in(ObliDiagnostics::CATEGORIES)],
        ])->validate();
        foreach (['enabled', 'clear_key'] as $field) {
            $data[$field] = (bool) $data[$field];
        }
        foreach (['max_output_tokens', 'chat_retention_days'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        abort_unless((int) $data['expected_actor_id'] === (int) $actor->id, 403);
        foreach (['model', 'input_price_usd', 'output_price_usd'] as $field) {
            $data[$field] = (string) ($data[$field] ?? '');
        }
        foreach (['daily_budget_usd', 'monthly_budget_usd', 'input_price_usd', 'output_price_usd'] as $field) {
            if (! $data['enabled'] && in_array($field, ['input_price_usd', 'output_price_usd'], true) && $data[$field] === '') {
                continue;
            }
            if (self::micros($data[$field]) <= 0) {
                throw ValidationException::withMessages([$field => 'Enter a positive amount.']);
            }
        }
        if ($data['clear_key'] && filled($data['api_key'] ?? null)) {
            throw ValidationException::withMessages(['api_key' => 'Choose replacement or removal, not both.']);
        }

        return DB::transaction(function () use ($actor, $data): array {
            $actor = $this->administrator($actor);
            AppSetting::query()->insertOrIgnore(['key' => self::KEY, 'value' => json_encode(self::defaults())]);
            $row = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->firstOrFail();
            $before = $this->current(true);
            abort_unless($before['version'] === (int) $data['expected_version'], 409);
            $changedProvider = $before['provider'] !== $data['provider'];
            if ($data['clear_key'] || $changedProvider) {
                AppSetting::query()->where('key', self::SECRET)->delete();
            }
            if (filled($data['api_key'] ?? null)) {
                AppSetting::query()->updateOrCreate(['key' => self::SECRET], ['value' => Crypt::encryptString($data['api_key'])]);
            }
            if ($data['enabled'] && ! AppSetting::query()->where('key', self::SECRET)->exists()) {
                throw ValidationException::withMessages(['api_key' => 'Add this provider’s key before enabling chat.']);
            }
            $settings = array_intersect_key($data, self::defaults());
            $settings['diagnostic_categories'] = array_values(array_intersect(ObliDiagnostics::CATEGORIES, $data['diagnostic_categories'] ?? $before['diagnostic_categories']));
            $settings['knowledge_enabled'] = (bool) ($data['knowledge_enabled'] ?? $before['knowledge_enabled']);
            $settings['technician_enabled'] = (bool) ($data['technician_enabled'] ?? $before['technician_enabled']);
            $settings['version'] = $before['version'] + 1;
            $row->update(['value' => $settings]);
            AuditLogger::logOrFail('obli.ai.settings_changed', null, [
                'actor_id' => (int) $actor->id, 'configuration_version' => $settings['version'],
                'provider' => $settings['provider'], 'enabled' => $settings['enabled'],
                'key_replaced' => filled($data['api_key'] ?? null), 'key_removed' => (bool) $data['clear_key'],
            ]);

            return $this->present();
        });
    }

    /** Called only while the settings row is locked in the request-reservation transaction. */
    public function secret(int $version): string
    {
        abort_unless($this->current(true)['version'] === $version, 409);
        $encrypted = AppSetting::query()->where('key', self::SECRET)->lockForUpdate()->value('value');
        abort_unless(is_string($encrypted) && $encrypted !== '', 503);

        return Crypt::decryptString($encrypted);
    }

    public static function micros(string $amount): int
    {
        if (! preg_match('/\A([0-9]{1,6})(?:\.([0-9]{1,6}))?\z/', $amount, $parts)) {
            throw new \InvalidArgumentException('Invalid USD amount.');
        }

        return ((int) $parts[1] * 1000000) + (int) str_pad($parts[2] ?? '', 6, '0');
    }

    public static function cost(int $input, int $output, int $inputRate, int $outputRate): int
    {
        return intdiv($input * $inputRate + $output * $outputRate + 999999, 1000000);
    }
}
