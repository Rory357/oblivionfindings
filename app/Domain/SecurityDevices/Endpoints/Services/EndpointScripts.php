<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointScript;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** One organisation's immutable catalogue. This service never queues or executes code. */
final class EndpointScripts
{
    public const UPLOAD = 'securityDevices.endpoints.scriptUpload';

    public const REVIEW = 'securityDevices.endpoints.scriptReview';

    /**
     * Revalidate a reviewed candidate while all authority/material locks remain held.
     * The callback must add command approval, fresh identity, installed-agent/runtime
     * and release checks. This is neither a launch grant nor a public material read.
     */
    public function withExecutionCandidate(
        User $actor,
        Device $device,
        int $expectedActor,
        string $uuid,
        string $contentHash,
        int $policyVersion,
        stdClass $inputs,
        Closure $callback,
        bool $includeArtifact = false,
    ): mixed {
        abort_unless(Str::isUuid($uuid) && preg_match('/\A[a-f0-9]{64}\z/', $contentHash)
            && $policyVersion > 0 && $policyVersion <= 4294967295, 422);

        return DB::transaction(function () use ($actor, $device, $expectedActor, $uuid, $contentHash, $policyVersion, $inputs, $callback, $includeArtifact): mixed {
            // Only immutable identity is trusted from this preliminary read.
            // Recheck it after locking all three participants in canonical order.
            $seed = EndpointScript::query()->where('uuid', $uuid)->firstOrFail(['id', 'uploaded_by_user_id', 'reviewed_by_user_id']);
            abort_unless($seed->reviewed_by_user_id && (int) $seed->uploaded_by_user_id !== (int) $seed->reviewed_by_user_id, 409);
            $users = app(AuthorizationEvidenceLockService::class)->lockForUsersWithoutWaiting([
                (int) $actor->id, (int) $seed->uploaded_by_user_id, (int) $seed->reviewed_by_user_id,
            ], ['*']);
            app(ItWorkAccessService::class)->lockApprovedSiteEvidenceForUsers($users);
            $current = $this->actor((int) $actor->id, $expectedActor, EndpointScriptPolicy::RUN);
            $this->actor((int) $seed->uploaded_by_user_id, (int) $seed->uploaded_by_user_id, self::UPLOAD);
            $this->actor((int) $seed->reviewed_by_user_id, (int) $seed->reviewed_by_user_id, self::REVIEW);
            $locked = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
            $current = app(EndpointRemoteAccess::class)->actor((int) $current->id, $locked, EndpointScriptPolicy::RUN);
            $script = EndpointScript::query()->whereKey($seed->id)->lock('for update nowait')->firstOrFail();
            abort_unless((int) $script->uploaded_by_user_id === (int) $seed->uploaded_by_user_id
                && (int) $script->reviewed_by_user_id === (int) $seed->reviewed_by_user_id
                && $script->status === 'published' && $script->published_at && ! $script->withdrawn_at
                && hash_equals($contentHash, $script->content_hash), 409);
            [$manifest, $review] = $this->integrity($script);
            $event = DB::table('endpoint_script_events')->where('endpoint_script_id', $script->id)
                ->where('status_version', $script->status_version)->lock('for update nowait')->first();
            abort_unless($event && $event->event_type === 'review'
                && (int) $event->actor_user_id === (int) $script->reviewed_by_user_id, 409);
            $this->publishable($manifest, $review);
            [$artifact] = $this->bytes($script, $manifest);
            try {
                EndpointScriptContract::inputs($manifest->parameter_schema, $inputs);
            } catch (InvalidArgumentException) {
                abort(422, 'Invalid reviewed script inputs.');
            }
            $effective = app(EndpointScriptPolicy::class)->current($locked);
            $policy = $effective['policy'];
            abort_unless($effective['assignment_matches'] && $policy['enabled'] && $policy['version'] === $policyVersion, 409);
            abort_unless($policy['consent_mode'] !== 'unattended' || $current->canDo(EndpointScriptPolicy::UNATTENDED), 403);
            $assignment = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads): string => app(CommandAssignmentFingerprint::class)->forDevice($locked, reads: $reads));

            return $callback($current, $locked, [
                ...($includeArtifact ? ['artifact' => $artifact] : []),
                'content_hash' => $script->content_hash,
                'assignment_fingerprint' => $assignment,
                'policy' => $policy,
                'parameters' => (object) [
                    'script_uuid' => $script->uuid, 'script_version' => $script->script_version,
                    'manifest' => $manifest, 'inputs' => EndpointScriptContract::decode(EndpointScriptContract::encode($inputs)),
                ],
            ]);
        });
    }

    /**
     * Navigation uses the same fresh account, role and approved-Site boundary
     * as reads. The fresh check takes row locks, so people who plainly hold no
     * script permission are answered from the loaded user first: the Remote
     * support page calls this on every load.
     */
    public function canOpen(User $actor): bool
    {
        if (! $actor->two_factor_confirmed_at || ! $actor->canDo('securityDevices.viewAny') || ! $actor->canDo('it.view')
            || (! $actor->canDo(self::UPLOAD) && ! $actor->canDo(self::REVIEW))) {
            return false;
        }

        try {
            DB::transaction(fn () => $this->actor((int) $actor->id, (int) $actor->id));

            return true;
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return false;
        } catch (QueryException $error) {
            // A busy authority row only hides the link for this load; the
            // catalogue itself re-checks on open. Anything else is a real error.
            if (! self::isLockContention($error)) {
                throw $error;
            }

            return false;
        }
    }

    /** MySQL lock-not-available (NOWAIT), lock-wait timeout or deadlock. */
    public static function isLockContention(QueryException $error): bool
    {
        return in_array((int) ($error->errorInfo[1] ?? 0), [3572, 1205, 1213], true);
    }

    public function index(User $actor, array $query): array
    {
        $status = $query['status'] ?? 'all';
        $search = $query['search'] ?? '';
        $expected = $query['expected_actor_id'] ?? (int) $actor->id;
        abort_unless(array_diff(array_keys($query), ['expected_actor_id', 'status', 'search', 'cursor']) === []
            && is_int($expected) && $expected > 0 && in_array($status, ['all', 'uploaded', 'pending_review', 'published', 'withdrawn'], true)
            && $this->label($search, 0, 200), 400);
        $cursor = $this->cursor($query['cursor'] ?? null);

        return DB::transaction(function () use ($actor, $expected, $status, $search, $cursor): array {
            $current = $this->actor((int) $actor->id, $expected);
            $visible = EndpointScript::query()->when(! $current->canDo(self::REVIEW), fn ($q) => $q->where('uploaded_by_user_id', $current->id));
            $counts = (clone $visible)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
            $summary = [];
            foreach (['uploaded', 'pending_review', 'published', 'withdrawn'] as $key) {
                $summary[$key] = (int) ($counts[$key] ?? 0);
            }
            $filter = clone $visible;
            if ($status !== 'all') {
                $filter->where('status', $status);
            }
            if ($search !== '') {
                $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $filter->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(manifest_json, '$.name')) LIKE ? ESCAPE '!'", [$literal]);
            }
            $read = function (?array $position) use ($filter) {
                $q = clone $filter;
                if ($position) {
                    $q->where('id', $position['forward'] ? '>' : '<', $position['id']);
                }
                $rows = $q->orderBy('id', $position && ! $position['forward'] ? 'desc' : 'asc')->limit(20)->lock('for update nowait')->get();

                return $position && ! $position['forward'] ? $rows->reverse()->values() : $rows;
            };
            $rows = $read($cursor);
            $reset = $cursor !== null && $rows->isEmpty();
            if ($reset) {
                $rows = $read(null);
            }
            foreach ($rows as $row) {
                $this->integrity($row);
            }
            $before = $rows->isNotEmpty() && (clone $filter)->where('id', '<', $rows->first()->id)->exists();
            $after = $rows->isNotEmpty() && (clone $filter)->where('id', '>', $rows->last()->id)->exists();
            $base = ['expected_actor_id' => (int) $current->id, 'status' => $status, 'search' => $search];
            $href = fn (?array $position = null) => '/security-devices/scripts?'.http_build_query($base + ($position ? ['cursor' => $this->encodeCursor($position)] : []), '', '&', PHP_QUERY_RFC3986);

            return ['actor_id' => (int) $current->id, 'can_upload' => $current->canDo(self::UPLOAD), 'identity_confirmed' => app(EndpointScriptIdentity::class)->confirmed($current),
                'identity_confirm_href' => '/security-devices/scripts/confirm-identity?expected_actor_id='.$current->id, 'execution_available' => false,
                'summary' => $summary, 'query' => ['status' => $status, 'search' => $search],
                'pagination' => ['limit' => 20, 'shown' => $rows->count(), 'previous_href' => $before ? $href(['id' => (int) $rows->first()->id, 'forward' => false]) : null,
                    'next_href' => $after ? $href(['id' => (int) $rows->last()->id, 'forward' => true]) : null, 'reset_href' => $href(), 'position_reset' => $reset],
                'scripts' => $rows->map(fn ($row) => $this->dto($row, $current))->all()];
        });
    }

    public function beginIdentity(Request $request, User $actor, int $expectedActor): void
    {
        DB::transaction(function () use ($request, $actor, $expectedActor): void {
            $current = $this->actor((int) $actor->id, $expectedActor);
            app(EndpointScriptIdentity::class)->begin($request, $current);
        });
    }

    public function upload(User $actor, array $input, string $metadata, string $review, string $artifact, string $runtime): array
    {
        $this->request($input, false);
        $this->preflight($actor, $input['expected_actor_id'], self::UPLOAD);
        [$manifest, $reviewObject] = $this->prepare($metadata, $review, $artifact, $runtime);
        // The generated publication UUID is not part of upload retry intent.
        $identity = get_object_vars($manifest);
        unset($identity['script_uuid']);
        $requestHash = $this->hash([$identity, $reviewObject, $input['reason']]);

        return DB::transaction(function () use ($actor, $input, $manifest, $reviewObject, $requestHash, $artifact, $runtime): array {
            $current = $this->actor((int) $actor->id, $input['expected_actor_id'], self::UPLOAD);
            $existing = EndpointScript::query()->where('uploaded_by_user_id', $current->id)->where('request_key', $input['request_key'])->lock('for update nowait')->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409);
                $this->integrity($existing);
                $receipt = $this->receipt($current, $input['request_key']);
                abort_unless($receipt && (int) $receipt->endpoint_script_id === (int) $existing->id && $receipt->request_hash === $requestHash, 409);

                return $this->response($current, $existing, $receipt);
            }
            abort_if($this->receipt($current, $input['request_key']) !== null, 409);
            $manifestJson = $this->canonical($manifest);
            $reviewJson = $this->canonical($reviewObject);
            $script = EndpointScript::query()->create([
                'uuid' => $manifest->script_uuid, 'script_version' => 1, 'status' => 'uploaded', 'status_version' => 1,
                'manifest_json' => $manifestJson, 'review_json' => $reviewJson, 'content_hash' => hash('sha256', $manifestJson."\n".$reviewJson),
                'artifact_ciphertext' => Crypt::encryptString($artifact), 'runtime_ciphertext' => Crypt::encryptString($runtime),
                'uploaded_by_user_id' => $current->id, 'request_key' => $input['request_key'], 'request_hash' => $requestHash,
            ]);
            $this->event($script, $current, 'upload', $input, $requestHash);

            return $this->response($current, $script, $this->receipt($current, $input['request_key']));
        });
    }

    public function show(User $actor, string $uuid, int $expectedActor): array
    {
        return DB::transaction(function () use ($actor, $uuid, $expectedActor): array {
            $current = $this->actor((int) $actor->id, $expectedActor);
            $script = $this->visible($current, $uuid);
            $this->integrity($script);

            return ['actor_id' => (int) $current->id, 'script' => $this->dto($script, $current)];
        });
    }

    public function recoverUpload(User $actor, string $key, int $expectedActor): array
    {
        abort_unless(Str::isUuid($key), 404);

        return DB::transaction(function () use ($actor, $key, $expectedActor): array {
            $current = $this->actor((int) $actor->id, $expectedActor, self::UPLOAD);
            $script = EndpointScript::query()->where('uploaded_by_user_id', $current->id)->where('request_key', $key)->lock('for update nowait')->firstOrFail();
            $this->integrity($script);

            return $this->response($current, $script, $this->receipt($current, $key));
        });
    }

    public function recoverAction(User $actor, string $uuid, string $key, int $expectedActor): array
    {
        abort_unless(Str::isUuid($key), 404);

        return DB::transaction(function () use ($actor, $uuid, $key, $expectedActor): array {
            $current = $this->actor((int) $actor->id, $expectedActor);
            $script = $this->visible($current, $uuid);
            $this->integrity($script);
            $receipt = $this->receipt($current, $key);
            abort_unless($receipt && (int) $receipt->endpoint_script_id === (int) $script->id, 404);

            return $this->response($current, $script, $receipt);
        });
    }

    /** Explicit review material; ordinary DTOs never contain executable bytes. */
    public function material(User $actor, string $uuid, int $expectedActor): array
    {
        return DB::transaction(function () use ($actor, $uuid, $expectedActor): array {
            $current = $this->actor((int) $actor->id, $expectedActor);
            abort_unless(app(EndpointScriptIdentity::class)->confirmed($current), 403);
            $script = $this->visible($current, $uuid);
            [$manifest, $review] = $this->integrity($script);
            [$artifact, $runtime] = $this->bytes($script, $manifest);
            DB::table('endpoint_script_material_reads')->insert(['endpoint_script_id' => $script->id, 'actor_user_id' => $current->id, 'content_hash' => $script->content_hash, 'occurred_at' => now()]);

            return ['actor_id' => (int) $current->id, 'content_hash' => $script->content_hash, 'manifest' => $manifest, 'review' => $review, 'artifact' => $artifact, 'runtime_manifest' => $runtime];
        });
    }

    public function transition(User $actor, string $uuid, string $action, array $input): array
    {
        abort_unless(in_array($action, ['submit', 'review', 'withdraw'], true), 404);
        $this->request($input, true, $action === 'review');
        $permission = $action === 'submit' ? self::UPLOAD : self::REVIEW;
        $this->preflight($actor, $input['expected_actor_id'], $permission);

        return DB::transaction(function () use ($actor, $uuid, $action, $input, $permission): array {
            // Read only immutable owner identity before locking all involved
            // users in canonical order; never nest inverse author/reviewer locks.
            $seed = EndpointScript::query()->where('uuid', $uuid)->firstOrFail(['id', 'uploaded_by_user_id']);
            $ids = $action === 'review' ? [(int) $actor->id, (int) $seed->uploaded_by_user_id] : [(int) $actor->id];
            $users = app(AuthorizationEvidenceLockService::class)->lockForUsers($ids, ['*']);
            app(ItWorkAccessService::class)->lockApprovedSiteEvidenceForUsers($users);
            $current = $this->actor((int) $actor->id, $input['expected_actor_id'], $permission);
            if ($action === 'review') {
                abort_unless((int) $seed->uploaded_by_user_id !== (int) $current->id, 403);
                $this->actor((int) $seed->uploaded_by_user_id, (int) $seed->uploaded_by_user_id, self::UPLOAD);
            }
            $script = $this->visible($current, $uuid);
            abort_unless((int) $script->uploaded_by_user_id === (int) $seed->uploaded_by_user_id, 409);
            [$manifest, $review] = $this->integrity($script);
            if ($action === 'submit') {
                abort_unless((int) $script->uploaded_by_user_id === (int) $current->id, 403);
            } else {
                abort_unless(app(EndpointScriptIdentity::class)->confirmed($current), 403);
            }
            $requestHash = $this->hash([$uuid, $action, $input]);
            $receipt = $this->receipt($current, $input['request_key']);
            if ($receipt) {
                abort_unless((int) $receipt->endpoint_script_id === (int) $script->id && hash_equals($receipt->request_hash, $requestHash), 409);

                return $this->response($current, $script, $receipt);
            }
            abort_unless($script->status_version === $input['expected_version'], 409);
            if ($action === 'submit') {
                abort_unless($script->status === 'uploaded', 409);
                $this->bytes($script, $manifest);
                $script->status = 'pending_review';
                $script->submitted_at = now();
            } elseif ($action === 'review') {
                abort_unless($script->status === 'pending_review' && hash_equals($script->content_hash, $input['reviewed_content_hash']), 409);
                $this->bytes($script, $manifest);
                $this->publishable($manifest, $review);
                $script->status = 'published';
                $script->reviewed_by_user_id = $current->id;
                $script->published_at = now();
            } else {
                abort_unless(in_array($script->status, ['uploaded', 'pending_review', 'published'], true), 409);
                // Withdrawal must remain possible after runtime/author revocation
                // or damaged encrypted bytes. It grants no recovery execution.
                $script->status = 'withdrawn';
                $script->withdrawn_at = now();
            }
            $script->status_version++;
            $script->save();
            $this->event($script, $current, $action, $input, $requestHash);

            return $this->response($current, $script, $this->receipt($current, $input['request_key']));
        });
    }

    private function actor(int $id, int $expected, ?string $permission = null): User
    {
        $actor = app(ObliStaffAccess::class)->actor($id);
        abort_unless($id === $expected, 409);
        abort_unless($actor->two_factor_confirmed_at && $actor->canDo('securityDevices.viewAny') && $actor->canDo('it.view'), 403);
        abort_unless($permission ? $actor->canDo($permission) : ($actor->canDo(self::UPLOAD) || $actor->canDo(self::REVIEW)), 403);

        return $actor;
    }

    private function preflight(User $actor, int $expected, string $permission): void
    {
        DB::transaction(fn () => $this->actor((int) $actor->id, $expected, $permission));
    }

    private function visible(User $actor, string $uuid): EndpointScript
    {
        abort_unless(Str::isUuid($uuid), 404);

        return EndpointScript::query()->where('uuid', $uuid)
            ->when(! $actor->canDo(self::REVIEW), fn ($q) => $q->where('uploaded_by_user_id', $actor->id))
            ->lock('for update nowait')->firstOrFail();
    }

    private function prepare(string $metadata, string $review, string $artifact, string $runtime): array
    {
        try {
            $m = EndpointScriptContract::decode($metadata);
            $this->keys($m, ['name', 'publisher_label', 'interpreter', 'interpreter_version', 'wrapper_sha256', 'architecture', 'parameter_schema', 'execution_policy']);
            $manifest = (object) [...get_object_vars($m), 'script_uuid' => (string) Str::uuid(), 'script_version' => 1, 'sha256' => hash('sha256', $artifact), 'size_bytes' => strlen($artifact), 'runtime_manifest_sha256' => hash('sha256', $runtime)];
            EndpointScriptContract::artifact($manifest, $artifact);
            EndpointScriptContract::runtime($manifest, $runtime);
            EndpointScriptContract::decode($this->canonical($manifest));
            $reviewObject = EndpointScriptContract::decode($review);
            $this->review($reviewObject);

            return [$manifest, $reviewObject];
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid script review material.');
        }
    }

    /** Retained identity for historical receipts; never publication or execution authority. */
    public function retainedManifest(string $uuid, string $contentHash): stdClass
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $script = EndpointScript::query()->where('uuid', $uuid)->lock('for update nowait')->firstOrFail();
        abort_unless(hash_equals($script->content_hash, $contentHash), 409);
        [$manifest] = $this->integrity($script);

        return $manifest;
    }

    private function integrity(EndpointScript $script): array
    {
        abort_unless(hash_equals($script->content_hash, hash('sha256', $script->manifest_json."\n".$script->review_json)), 409);
        try {
            $manifest = EndpointScriptContract::decode($script->manifest_json);
            EndpointScriptContract::manifest($manifest);
            $review = EndpointScriptContract::decode($script->review_json);
            $this->review($review);
        } catch (InvalidArgumentException) {
            abort(409, 'Script review material is unavailable.');
        }
        abort_unless($manifest->script_uuid === $script->uuid && $manifest->script_version === $script->script_version, 409);
        $event = DB::table('endpoint_script_events')->where('endpoint_script_id', $script->id)->where('status_version', $script->status_version)->lock('for update nowait')->first();
        abort_unless($event && $event->status === $script->status && $event->content_hash === $script->content_hash, 409);

        return [$manifest, $review];
    }

    private function bytes(EndpointScript $script, stdClass $manifest): array
    {
        try {
            $artifact = Crypt::decryptString($script->artifact_ciphertext);
            $runtime = Crypt::decryptString($script->runtime_ciphertext);
            EndpointScriptContract::artifact($manifest, $artifact);
            EndpointScriptContract::runtime($manifest, $runtime);

            return [$artifact, $runtime];
        } catch (Throwable) {
            abort(409, 'Script bytes are unavailable.');
        }
    }

    private function publishable(stdClass $manifest, stdClass $review): void
    {
        $issue = $this->publicationIssue($manifest, $review);
        abort_unless($issue === null, 503, $issue === 'runtime_unavailable' ? 'Reviewed runtime unavailable.' : 'Independent observation unavailable.');
    }

    private function publicationIssue(stdClass $manifest, stdClass $review): ?string
    {
        $runtime = config('endpoint_management.scripts.reviewed_runtimes.'.$manifest->runtime_manifest_sha256);
        if (! (is_array($runtime) && count($runtime) === 3
            && ($runtime['architecture'] ?? null) === $manifest->architecture
            && ($runtime['interpreter_version'] ?? null) === $manifest->interpreter_version
            && ($runtime['wrapper_sha256'] ?? null) === $manifest->wrapper_sha256)) {
            return 'runtime_unavailable';
        }

        // No independent native observation schema is implemented yet. Never
        // accept a supplied digest or a script's stdout as observation authority.
        return $review->effect === 'read_only' && $manifest->execution_policy->expected_observation_schema === null ? null : 'observation_unavailable';
    }

    private function request(array $input, bool $transition, bool $review = false): void
    {
        $keys = ['expected_actor_id', 'request_key', 'reason', ...($transition ? ['expected_version'] : []), ...($review ? ['reviewed_content_hash'] : [])];
        $actual = array_keys($input);
        sort($keys);
        sort($actual);
        abort_unless($actual === $keys && is_int($input['expected_actor_id']) && $input['expected_actor_id'] > 0
            && is_string($input['request_key']) && preg_match('/\A[0-9a-f-]{36}\z/', $input['request_key']) && Str::isUuid($input['request_key'])
            && $this->label($input['reason'], 10, 500), 422);
        abort_if($transition && (! is_int($input['expected_version']) || $input['expected_version'] < 1), 422);
        abort_if($review && (! is_string($input['reviewed_content_hash']) || ! preg_match('/\A[0-9a-f]{64}\z/', $input['reviewed_content_hash'])), 422);
    }

    private function review(stdClass $r): void
    {
        $this->keys($r, ['effect', 'child_processes', 'network_destinations', 'output_sensitivity', 'compensation', 'restart_behavior', 'no_detached_work', 'no_dynamic_code']);
        $this->requireReview(in_array($r->effect, ['read_only', 'mutating'], true) && in_array($r->output_sensitivity, ['none', 'operational', 'sensitive'], true)
            && in_array($r->restart_behavior, ['none', 'may_request_restart'], true) && $r->no_detached_work === true && $r->no_dynamic_code === true
            && $this->label($r->compensation, 1, 1000));
        foreach (['child_processes', 'network_destinations'] as $key) {
            $this->requireReview(is_array($r->{$key}) && array_is_list($r->{$key}) && count($r->{$key}) <= 32);
            foreach ($r->{$key} as $value) {
                $this->requireReview($this->label($value, 1, 256));
            }
        }
        $this->requireReview($r->effect !== 'read_only' || $r->restart_behavior === 'none');
    }

    private function requireReview(bool $valid): void
    {
        if (! $valid) {
            throw new InvalidArgumentException('invalid_script_contract');
        }
    }

    private function label(mixed $value, int $min, int $max): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) >= $min && mb_strlen($value) <= $max && ! preg_match('/[\p{Cc}\p{Cf}\x{FFFD}]/u', $value);
    }

    private function keys(stdClass $object, array $expected): void
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('invalid_script_contract');
        }
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $v) use (&$sort): mixed {
            if ($v instanceof stdClass || is_array($v)) {
                $object = $v instanceof stdClass;
                $v = $object ? get_object_vars($v) : $v;
                if ($object || ! array_is_list($v)) {
                    ksort($v);
                }
                $v = array_map($sort, $v);

                return $object ? (object) $v : $v;
            }

            return $v;
        };

        return EndpointScriptContract::encode($sort($value));
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', $this->canonical($value));
    }

    private function receipt(User $actor, string $key): ?stdClass
    {
        return DB::table('endpoint_script_events')->where('actor_user_id', $actor->id)->where('request_key', $key)->lock('for update nowait')->first();
    }

    private function event(EndpointScript $script, User $actor, string $type, array $input, string $hash): void
    {
        DB::table('endpoint_script_events')->insert(['endpoint_script_id' => $script->id, 'actor_user_id' => $actor->id, 'request_key' => $input['request_key'], 'request_hash' => $hash,
            'content_hash' => $script->content_hash, 'event_type' => $type, 'status' => $script->status, 'status_version' => $script->status_version, 'reason' => $input['reason'], 'occurred_at' => now()]);
    }

    private function response(User $actor, EndpointScript $script, ?stdClass $receipt): array
    {
        abort_unless($receipt, 409);

        return ['actor_id' => (int) $actor->id, 'script' => $this->dto($script, $actor), 'receipt' => ['request_key' => $receipt->request_key, 'event' => $receipt->event_type, 'status' => $receipt->status, 'status_version' => (int) $receipt->status_version, 'content_hash' => $receipt->content_hash]];
    }

    private function dto(EndpointScript $script, User $actor): array
    {
        $manifest = EndpointScriptContract::decode($script->manifest_json);
        $review = EndpointScriptContract::decode($script->review_json);
        $owner = (int) $script->uploaded_by_user_id === (int) $actor->id;
        $confirmed = app(EndpointScriptIdentity::class)->confirmed($actor);
        $reviewer = $actor->canDo(self::REVIEW);
        $issue = $this->publicationIssue($manifest, $review);

        return ['uuid' => $script->uuid, 'script_version' => $script->script_version, 'status' => $script->status, 'status_version' => $script->status_version,
            'content_hash' => $script->content_hash, 'manifest' => $manifest, 'review' => $review,
            'uploaded_by_user_id' => (int) $script->uploaded_by_user_id, 'reviewed_by_user_id' => $script->reviewed_by_user_id === null ? null : (int) $script->reviewed_by_user_id,
            'can_submit' => $owner && $actor->canDo(self::UPLOAD) && $script->status === 'uploaded',
            'can_review' => ! $owner && $reviewer && $confirmed && $script->status === 'pending_review' && $issue === null,
            'can_withdraw' => $reviewer && $confirmed && $script->status !== 'withdrawn', 'can_read_material' => $confirmed,
            'publication_issue' => $issue,
            'submitted_at' => $script->submitted_at?->toISOString(), 'published_at' => $script->published_at?->toISOString(), 'withdrawn_at' => $script->withdrawn_at?->toISOString()];
    }

    private function encodeCursor(array $cursor): string
    {
        return rtrim(strtr(base64_encode(EndpointScriptContract::encode($cursor)), '+/', '-_'), '=');
    }

    private function cursor(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        abort_unless(is_string($value) && strlen($value) <= 128 && preg_match('/\A[A-Za-z0-9_-]+\z/', $value), 400);
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        try {
            $object = $raw === false ? null : EndpointScriptContract::decode($raw, 128);
        } catch (InvalidArgumentException) {
            abort(400);
        }
        abort_unless($object instanceof stdClass && array_keys(get_object_vars($object)) === ['id', 'forward']
            && is_int($object->id) && $object->id > 0 && is_bool($object->forward), 400);
        $cursor = get_object_vars($object);
        abort_unless($this->encodeCursor($cursor) === $value, 400);

        return $cursor;
    }
}
