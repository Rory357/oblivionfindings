<?php

namespace App\Domain\Obli;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

final class ObliChatGateway
{
    public function __construct(private readonly ObliSignIn $signIn, private readonly ObliAiSettings $settings, private readonly ObliTextProvider $provider, private readonly ObliKnowledge $knowledge) {}

    public function options(?string $token): array
    {
        return $this->signIn->withSession($token, function (): array {
            $settings = $this->settings->current(true);
            $settings['knowledge_enabled'] = (bool) ($settings['knowledge_enabled'] && $this->knowledge->ready());

            return array_intersect_key($settings, array_flip(['version', 'enabled', 'provider', 'model', 'chat_retention_days', 'diagnostic_categories', 'knowledge_enabled']));
        });
    }

    public function send(#[\SensitiveParameter] ?string $token, #[\SensitiveParameter] array $input): array
    {
        $data = $this->validateInput($input, false);

        return $this->sendScoped(new ObliPersonalChatScope($this->signIn, $token, $this->knowledge), $input, $data);
    }

    public function sendInvestigation(ObliTechnicianChatScope $scope, #[\SensitiveParameter] array $input): array
    {
        return $this->sendScoped($scope, $input);
    }

    private function sendScoped(ObliChatScope $scope, #[\SensitiveParameter] array $input, #[\SensitiveParameter] ?array $data = null): array
    {
        $technician = $scope->kind() === 'technician';
        $data ??= $this->validateInput($input, $technician);
        $payload = [$data['messages'], $data['configuration_version']];
        if ($technician) {
            $payload[] = $scope->identity();
        }
        $hash = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $prepared = $scope->within(function (User $user) use ($data, $hash, $scope): array {
            $policy = $this->settings->current(true);
            abort_unless($this->ready($scope), 503);
            $existing = DB::table('obli_ai_requests')->where('user_id', $user->id)->where('request_uuid', strtolower($data['request_uuid']))->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->context_kind === $scope->kind(), 404);
                abort_unless(hash_equals($existing->request_hash, $hash), 409);

                $cached = $this->result($existing, $user, $scope);
                abort_unless($policy['enabled'], 503);
                abort_unless($policy['version'] === (int) $data['configuration_version'], 409);

                return ['cached' => $cached];
            }
            if ($scope->kind() === 'personal') {
                $this->knowledge->assertBindings($user, array_values(array_map(fn ($message) => ['article_id' => (int) $message['knowledge']['article_id'], 'version' => $message['knowledge']['version']], array_filter($data['messages'], fn ($message) => isset($message['knowledge'])))));
            }
            abort_unless($policy['enabled'], 503);
            abort_unless($policy['version'] === (int) $data['configuration_version'], 409);
            [$providerMessages, $bindings, $context] = $scope->prepare($user, $data['messages'], $policy);
            // A conservative reservation. Prices are configured estimates, not an invoice guarantee.
            // Diagnostics and server-resolved references share one rendered byte budget.
            $bytes = array_sum(array_map(fn ($message) => strlen($message['content']), $providerMessages));
            $inputRate = ObliAiSettings::micros($policy['input_price_usd']);
            $outputRate = ObliAiSettings::micros($policy['output_price_usd']);
            $reserve = ObliAiSettings::cost($bytes + strlen(ObliProviderHttp::instructions($scope->kind())) + 4096, $policy['max_output_tokens'], $inputRate, $outputRate);
            $periods = ['day:'.now('UTC')->format('Y-m-d'), 'month:'.now('UTC')->format('Y-m')];
            $limits = [ObliAiSettings::micros($policy['daily_budget_usd']), ObliAiSettings::micros($policy['monthly_budget_usd'])];
            foreach ($periods as $index => $period) {
                DB::table('obli_ai_budget_periods')->insertOrIgnore(['period' => $period, 'committed_micros' => 0]);
                $budget = DB::table('obli_ai_budget_periods')->where('period', $period)->lockForUpdate()->first();
                abort_if((int) $budget->committed_micros + $reserve > $limits[$index], 429);
                DB::table('obli_ai_budget_periods')->where('period', $period)->increment('committed_micros', $reserve);
            }
            $id = (string) Str::uuid();
            DB::table('obli_ai_requests')->insert([
                'context_kind' => $scope->kind(),
                'investigation_context_encrypted' => $context !== null ? Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)) : null,
                'id' => $id, 'user_id' => $user->id, 'request_uuid' => strtolower($data['request_uuid']), 'request_hash' => $hash,
                'configuration_version' => $policy['version'], 'provider' => $policy['provider'], 'model' => $policy['model'],
                'status' => 'pending', 'periods' => json_encode($periods), 'reserved_micros' => $reserve,
                'input_rate' => $inputRate, 'output_rate' => $outputRate, 'created_at' => now(),
                'knowledge_bindings_encrypted' => $bindings !== [] ? Crypt::encryptString(json_encode($bindings, JSON_THROW_ON_ERROR)) : null,
                'expires_at' => now()->addDays($policy['chat_retention_days']),
            ]);

            return ['id' => $id, 'messages' => $providerMessages, 'configuration' => $policy + ['api_key' => $this->settings->secret($policy['version']), 'instruction_profile' => $scope->kind()]];
        });
        if (isset($prepared['cached'])) {
            return $prepared['cached'];
        }
        // No application DB locks are held during the external request.
        try {
            $answer = $this->provider->respond($prepared['configuration'], $prepared['messages']);
        } catch (Throwable) {
            $answer = null;
        } finally {
            unset($prepared['configuration'], $prepared['messages']);
        }
        $this->settle($prepared['id'], $answer);

        return $scope->within(function (User $user) use ($prepared, $scope): array {
            // Match preparation order before receipt and publication revalidation.
            $this->settings->current(true);
            $request = DB::table('obli_ai_requests')->where('id', $prepared['id'])->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            return $this->result($request, $user, $scope);
        });
    }

    private function validateInput(#[\SensitiveParameter] array $input, bool $technician): array
    {
        abort_if(array_diff(array_keys($input), ['request_uuid', 'configuration_version', 'messages']) !== [], 422);
        $data = Validator::make($input, [
            'request_uuid' => ['required', 'uuid'], 'configuration_version' => ['required', 'integer', 'min:1'],
            'messages' => ['required', 'array', 'min:1', 'max:15'],
            'messages.*' => ['required', $technician ? 'array:role,content,computer_diagnostics' : 'array:role,content,diagnostics,knowledge'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:12000'],
            'messages.*.diagnostics' => ['sometimes', 'array'],
            'messages.*.knowledge' => ['sometimes', 'required', 'array:article_id,version', 'size:2'],
            'messages.*.knowledge.article_id' => ['required_with:messages.*.knowledge', 'integer', 'min:1'],
            'messages.*.knowledge.version' => ['required_with:messages.*.knowledge', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'messages.*.computer_diagnostics' => ['sometimes', 'required', 'array:snapshot_version,groups,source_request_uuid'],
            'messages.*.computer_diagnostics.snapshot_version' => ['required_with:messages.*.computer_diagnostics', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'messages.*.computer_diagnostics.groups' => ['required_with:messages.*.computer_diagnostics', 'array', 'min:1', 'max:3'],
            'messages.*.computer_diagnostics.groups.*' => ['required', 'string', 'distinct', 'in:system,software,services'],
            'messages.*.computer_diagnostics.source_request_uuid' => ['sometimes', 'required', 'uuid'],
        ])->validate();
        $bytes = 0;
        foreach ($data['messages'] as $index => $message) {
            abort_unless($message['role'] === ($index % 2 === 0 ? 'user' : 'assistant'), 422);
            abort_if((isset($message['knowledge']) || isset($message['computer_diagnostics'])) && $message['role'] !== 'user', 422);
            if (isset($message['computer_diagnostics'])) {
                abort_unless(array_is_list($message['computer_diagnostics']['groups']), 422);
                $data['messages'][$index]['computer_diagnostics']['groups'] = array_values(array_intersect(ObliDiagnostics::CATEGORIES, $message['computer_diagnostics']['groups']));
            }
            abort_if(preg_match($technician ? '/(?![\t\r\n])\p{Cc}/u' : '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message['content']), 422);
            $bytes += strlen($message['content']);
        }
        abort_if(count($data['messages']) % 2 !== 1 || $bytes > 12000, 422);

        return $data;
    }

    public function outcome(?string $token, string $uuid): array
    {
        return $this->outcomeScoped(new ObliPersonalChatScope($this->signIn, $token, $this->knowledge), $uuid);
    }

    public function outcomeInvestigation(ObliTechnicianChatScope $scope, string $uuid): array
    {
        return $this->outcomeScoped($scope, $uuid);
    }

    private function outcomeScoped(ObliChatScope $scope, string $uuid): array
    {
        abort_unless(Str::isUuid($uuid), 404);

        return $scope->within(function (User $user) use ($uuid, $scope): array {
            abort_unless($this->ready($scope), 503);
            // Settlement takes budget then receipt; never wait for settings
            // or publication locks while already holding an outcome receipt.
            $this->settings->current(true);
            $request = DB::table('obli_ai_requests')->where('user_id', $user->id)->where('request_uuid', strtolower($uuid))->lockForUpdate()->first();
            abort_unless($request && $request->context_kind === $scope->kind(), 404);

            return $this->result($request, $user, $scope);
        });
    }

    public function investigationReady(): bool
    {
        return Schema::hasTable('obli_ai_budget_periods') && Schema::hasColumn('obli_ai_requests', 'knowledge_bindings_encrypted')
            && Schema::hasColumn('obli_ai_requests', 'context_kind') && Schema::hasColumn('obli_ai_requests', 'investigation_context_encrypted');
    }

    private function ready(ObliChatScope $scope): bool
    {
        return $this->investigationReady() && ($scope->kind() !== 'personal' || $this->knowledge->ready());
    }

    private function result(object $row, User $actor, ObliChatScope $scope): array
    {
        $scope->revalidate($actor, $row);
        abort_unless(CarbonImmutable::parse($row->expires_at)->isFuture(), 410);
        if ($row->knowledge_bindings_encrypted !== null) {
            try {
                $encoded = Crypt::decryptString($row->knowledge_bindings_encrypted);
                if (strlen($encoded) > 2048) {
                    throw new ObliKnowledgeUnavailable;
                }
                $bindings = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($bindings)) {
                    throw new ObliKnowledgeUnavailable;
                }
            } catch (Throwable) {
                throw new ObliKnowledgeUnavailable;
            }
            $this->knowledge->assertBindings($actor, $bindings);
        }
        if ($row->status === 'pending' && CarbonImmutable::parse($row->created_at)->lt(now()->subMinutes(2))) {
            DB::table('obli_ai_requests')->where('id', $row->id)->where('status', 'pending')->update(['status' => 'unconfirmed']);
            $row->status = 'unconfirmed';
        }

        $result = ['request_uuid' => $row->request_uuid, 'status' => $row->status,
            'text' => $row->status === 'completed' ? Crypt::decryptString($row->answer_encrypted) : null];
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 120000) {
            // Older stored answers also respect the native response cap; never resend.
            return ['request_uuid' => $row->request_uuid, 'status' => 'unconfirmed', 'text' => null];
        }

        return $result;
    }

    private function settle(string $id, #[\SensitiveParameter] ?array $answer): void
    {
        DB::transaction(function () use ($id, $answer): void {
            $candidate = DB::table('obli_ai_requests')->where('id', $id)->firstOrFail();
            $periods = json_decode($candidate->periods, true, flags: JSON_THROW_ON_ERROR);
            foreach ($periods as $period) {
                DB::table('obli_ai_budget_periods')->where('period', $period)->lockForUpdate()->firstOrFail();
            }
            $row = DB::table('obli_ai_requests')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'pending') {
                return;
            }
            $valid = $answer !== null && isset($answer['text'], $answer['input_tokens'], $answer['output_tokens'])
                && is_string($answer['text']) && is_int($answer['input_tokens']) && is_int($answer['output_tokens'])
                && $answer['input_tokens'] >= 0 && $answer['input_tokens'] <= 1000000
                && $answer['output_tokens'] >= 0 && $answer['output_tokens'] <= 1000000
                && strlen($answer['text']) <= 65536 && trim($answer['text']) !== ''
                && mb_check_encoding($answer['text'], 'UTF-8') && ! preg_match('/(?![\t\r\n])\p{Cc}/u', $answer['text']);
            $valid = $valid && strlen(json_encode(['request_uuid' => $row->request_uuid, 'status' => 'completed', 'text' => $answer['text']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= 120000;
            $cost = $valid ? ObliAiSettings::cost($answer['input_tokens'], $answer['output_tokens'], (int) $row->input_rate, (int) $row->output_rate) : (int) $row->reserved_micros;
            $delta = $cost - (int) $row->reserved_micros;
            foreach ($periods as $period) {
                DB::table('obli_ai_budget_periods')->where('period', $period)->increment('committed_micros', $delta);
            }
            DB::table('obli_ai_requests')->where('id', $id)->update([
                'status' => $valid ? 'completed' : 'unconfirmed', 'charged_micros' => $cost,
                'answer_encrypted' => $valid ? Crypt::encryptString($answer['text']) : null,
                'input_tokens' => $valid ? $answer['input_tokens'] : null, 'output_tokens' => $valid ? $answer['output_tokens'] : null,
            ]);
        });
    }
}
