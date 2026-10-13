<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointSoftwarePackage;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

final class EndpointSoftwarePackages
{
    public const UPLOAD = 'securityDevices.endpoints.softwareUpload';

    public const REVIEW = 'securityDevices.endpoints.softwareReview';

    private const METADATA = ['name', 'publisher', 'product_code', 'product_version', 'architecture', 'signer_sha256'];

    public static function canOpen(User $actor): bool
    {
        return $actor->isApproved() && $actor->email_verified_at && ! $actor->external_clinical_account && $actor->two_factor_confirmed_at
            && $actor->canDo('securityDevices.viewAny') && $actor->canDo('it.view')
            && collect([self::UPLOAD, self::REVIEW, EndpointSoftwarePolicy::SERVICE, EndpointSoftwarePolicy::MANAGE])->contains(fn ($key) => $actor->canDo($key));
    }

    public function actor(User $actor): User
    {
        $actor = app(ObliStaffAccess::class)->actor((int) $actor->id);
        abort_unless($actor->two_factor_confirmed_at && $actor->canDo('securityDevices.viewAny') && $actor->canDo('it.view'), 403);
        abort_unless(collect([self::UPLOAD, self::REVIEW, EndpointSoftwarePolicy::SERVICE, EndpointSoftwarePolicy::MANAGE])->contains(fn ($key) => $actor->canDo($key)), 403);

        return $actor;
    }

    public function upload(User $actor, array $input, UploadedFile $file): array
    {
        abort_if(array_diff(array_keys($input), ['expected_actor_id', 'request_key', 'reason', ...self::METADATA]) !== [], 422);
        $data = Validator::make($input, ['expected_actor_id' => ['required', 'integer', 'min:1'], 'request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u']])->validate();
        $metadata = EndpointSoftwareContract::metadata(array_intersect_key($input, array_flip(self::METADATA)));
        DB::transaction(function () use ($actor, $data): void {
            $current = $this->actor($actor);
            abort_unless($current->canDo(self::UPLOAD), 403);
            abort_unless((int) $data['expected_actor_id'] === (int) $current->id, 409);
        });
        abort_unless($file->isValid() && strtolower($file->getClientOriginalExtension()) === 'msi' && $file->getSize() > 8 && $file->getSize() <= $this->uploadLimit(), 422);
        $stream = fopen($file->getRealPath(), 'rb');
        abort_unless($stream !== false, 422);
        try {
            abort_unless(fread($stream, 8) === hex2bin('d0cf11e0a1b11ae1'), 422);
        } finally {
            fclose($stream);
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $size = (int) $file->getSize();
        $metadataHash = $this->hash($metadata + ['sha256' => $sha, 'size_bytes' => $size]);
        $requestHash = $this->hash($metadata + ['sha256' => $sha, 'size_bytes' => $size, 'reason' => $data['reason']]);
        $uuid = (string) Str::uuid();
        $key = $uuid.'.msi';
        $root = $this->root();
        if (! is_dir($root)) {
            abort_unless(mkdir($root, 0700, true), 503);
        }$file->move($root, $key);
        $newPath = $root.DIRECTORY_SEPARATOR.$key;
        try {
            $result = DB::transaction(function () use ($actor, $data, $metadata, $sha, $size, $metadataHash, $requestHash, $uuid, $key): array {
                $current = $this->actor($actor);
                abort_unless($current->canDo(self::UPLOAD), 403);
                abort_unless((int) $data['expected_actor_id'] === (int) $current->id, 409);
                // The current actor mutex also serializes retries before this unique receipt insert.
                $existing = EndpointSoftwarePackage::query()->where('uploaded_by_user_id', $current->id)->where('request_key', $data['request_key'])->lock('for update nowait')->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $requestHash), 409);

                    return ['dto' => $this->writeDto($current, $existing), 'key' => $existing->storage_key];
                }
                $package = EndpointSoftwarePackage::query()->create($metadata + ['uuid' => $uuid, 'package_version' => 1, 'status' => 'uploaded', 'status_version' => 1, 'sha256' => $sha, 'size_bytes' => $size, 'storage_key' => $key, 'metadata_hash' => $metadataHash, 'uploaded_by_user_id' => $current->id, 'request_key' => $data['request_key'], 'request_hash' => $requestHash]);
                $this->event($package, $current, 'uploaded', $data['reason']);

                return ['dto' => $this->writeDto($current, $package), 'key' => $key];
            });
        } catch (Throwable $e) {
            if (is_file($newPath)) {
                unlink($newPath);
            }throw $e;
        }
        if ($result['key'] !== $key && is_file($newPath)) {
            unlink($newPath);
        }

        return $result['dto'];
    }

    public function recovery(User $actor, string $requestKey, array $input): array
    {
        abort_unless(Str::isUuid($requestKey), 404);

        return DB::transaction(function () use ($actor, $requestKey, $input): array {
            $current = $this->readActor($actor, $input);
            abort_unless($current->canDo(self::UPLOAD), 403);
            $package = EndpointSoftwarePackage::query()->where('uploaded_by_user_id', $current->id)->where('request_key', $requestKey)->lock('for update nowait')->firstOrFail();

            return $this->writeDto($current, $package);
        });
    }

    public function show(User $actor, string $uuid, array $input): array
    {
        return DB::transaction(function () use ($actor, $uuid, $input): array {
            $current = $this->readActor($actor, $input);
            $package = $this->visible($current)->where('uuid', $uuid)->lock('for update nowait')->firstOrFail();

            return $this->writeDto($current, $package);
        });
    }

    public function transition(User $actor, string $uuid, string $action, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['expected_actor_id', 'expected_version', 'reason']) !== [], 422);
        $data = Validator::make($input, ['expected_actor_id' => ['required', 'integer:strict', 'min:1'], 'expected_version' => ['required', 'integer:strict', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u']])->validate();

        $verifiedPackage = null;
        if ($action === 'review') {
            $verifiedPackage = DB::transaction(function () use ($actor, $uuid, $data): EndpointSoftwarePackage {
                $current = $this->actor($actor);
                abort_unless((int) $current->id === $data['expected_actor_id'], 409);
                abort_unless($current->canDo(self::REVIEW) && app(EndpointSoftwareIdentity::class)->confirmed($current), 403);
                $package = $this->visible($current)->where('uuid', $uuid)->lock('for update nowait')->firstOrFail();
                abort_unless((int) $package->uploaded_by_user_id !== (int) $current->id, 403);
                abort_unless($package->status === 'pending_review' && $package->status_version === $data['expected_version'], 409);
                $this->assertMetadata($package);

                return $package;
            });
            // Verify large immutable bytes outside actor/package locks. Publication rechecks the exact row below.
            $verified = $this->copy($verifiedPackage);
            fclose($verified);
        }

        return DB::transaction(function () use ($actor, $uuid, $action, $data, $verifiedPackage): array {
            $current = $this->actor($actor);
            abort_unless((int) $current->id === $data['expected_actor_id'], 409);
            $package = $this->visible($current)->where('uuid', $uuid)->lock('for update nowait')->firstOrFail();
            abort_unless($package->status_version === $data['expected_version'], 409);
            if ($action === 'submit') {
                abort_unless($current->canDo(self::UPLOAD) && (int) $package->uploaded_by_user_id === (int) $current->id, 403);
                abort_unless($package->status === 'uploaded', 409);
                $package->status = 'pending_review';
                $package->submitted_at = now();
            } elseif ($action === 'review') {
                abort_unless($current->canDo(self::REVIEW) && (int) $package->uploaded_by_user_id !== (int) $current->id, 403);
                abort_unless($package->status === 'pending_review', 409);
                abort_unless(app(EndpointSoftwareIdentity::class)->confirmed($current), 403);
                $this->assertMetadata($package);
                abort_unless($verifiedPackage && $package->getAttributes() === $verifiedPackage->getAttributes(), 409);
                $package->status = 'published';
                $package->reviewed_by_user_id = $current->id;
                $package->reviewed_at = now();
                $package->published_at = now();
            } elseif ($action === 'withdraw') {
                abort_unless($current->canDo(self::REVIEW) && app(EndpointSoftwareIdentity::class)->confirmed($current), 403);
                abort_unless(in_array($package->status, ['uploaded', 'pending_review', 'published'], true), 409);
                $package->status = 'withdrawn';
                $package->withdrawn_at = now();
            } else {
                abort(404);
            }
            $package->status_version++;
            $package->save();
            $this->event($package, $current, $action, $data['reason']);

            return $this->writeDto($current, $package);
        });
    }

    public function index(User $actor, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['expected_actor_id', 'device_id', 'status', 'search', 'software_cursor']) !== [], 400);
        $status = $input['status'] ?? 'published';
        abort_unless(is_string($status) && in_array($status, ['uploaded', 'pending_review', 'published', 'withdrawn', 'all'], true), 400);
        $search = $input['search'] ?? '';
        abort_unless(is_string($search) && mb_check_encoding($search, 'UTF-8') && mb_strlen($search) <= 200 && ! preg_match('/[\p{Cc}\p{Cf}]/u', $search), 400);
        $search = trim($search);
        $cursor = array_key_exists('software_cursor', $input) ? $this->cursor($input['software_cursor']) : null;
        foreach (['expected_actor_id', 'device_id'] as $key) {
            if (array_key_exists($key, $input)) {
                abort_unless(is_scalar($input[$key]) && preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $input[$key]), 400);
            }
        }

        return DB::transaction(function () use ($actor, $input, $status, $search, $cursor): array {
            $current = $this->actor($actor);
            if (isset($input['expected_actor_id'])) {
                abort_unless((int) $input['expected_actor_id'] === (int) $current->id, 409);
            }
            $deviceId = isset($input['device_id']) ? (int) $input['device_id'] : null;
            if ($deviceId !== null) {
                $device = Device::query()->whereKey($deviceId)->lock('for update nowait')->firstOrFail();
                $current = app(EndpointRemoteAccess::class)->actor((int) $current->id, $device, 'securityDevices.devices.view');
            }
            $query = $this->visible($current);
            $counts = (clone $query)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
            $summary = [];
            foreach (['published', 'pending_review', 'uploaded', 'withdrawn'] as $key) {
                $summary[$key] = (int) ($counts[$key] ?? 0);
            }if ($status !== 'all') {
                $query->where('status', $status);
            }if ($search !== '') {
                $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$literal])->orWhereRaw("publisher LIKE ? ESCAPE '!'", [$literal]));
            }
            $page = function ($position) use ($query) {
                $q = clone $query;
                if ($position) {
                    $q->where('id', $position['_pointsToNextItems'] ? '>' : '<', $position['id']);
                }$rows = $q->orderBy('id', $position && ! $position['_pointsToNextItems'] ? 'desc' : 'asc')->limit(21)->get();
                $rows = $rows->take(20);

                return $position && ! $position['_pointsToNextItems'] ? $rows->reverse()->values() : $rows->values();
            };
            $rows = $page($cursor);
            $reset = $cursor !== null && $rows->isEmpty();
            if ($reset) {
                $cursor = null;
                $rows = $page(null);
            }
            $before = $rows->isNotEmpty() && (clone $query)->where('id', '<', $rows->first()->id)->exists();
            $after = $rows->isNotEmpty() && (clone $query)->where('id', '>', $rows->last()->id)->exists();
            $base = ['expected_actor_id' => (int) $current->id, 'status' => $status];
            if ($deviceId !== null) {
                $base['device_id'] = $deviceId;
            }if ($search !== '') {
                $base['search'] = $search;
            }
            $href = fn ($position) => '/security-devices/software?'.http_build_query($base + ($position ? ['software_cursor' => (new Cursor($position, $position['_pointsToNextItems']))->encode()] : []), '', '&', PHP_QUERY_RFC3986);

            return ['actor_id' => (int) $current->id, 'summary' => $summary, 'available' => app(EndpointSoftwarePolicy::class)->available(), 'max_upload_bytes' => $this->uploadLimit(), 'can_upload' => $current->canDo(self::UPLOAD), 'can_confirm_identity' => $current->canDo(self::REVIEW), 'identity_confirmed' => app(EndpointSoftwareIdentity::class)->confirmed($current), 'identity_confirm_href' => '/security-devices/software/confirm-identity?expected_actor_id='.$current->id, 'upload_href' => '/security-devices/software', 'recovery_href_template' => '/security-devices/software/uploads/{request_key}?expected_actor_id='.$current->id, 'query' => ['device_id' => $deviceId, 'status' => $status, 'search' => $search], 'pagination' => ['page_key' => hash('sha256', json_encode([$base, $cursor])), 'limit' => 20, 'shown' => $rows->count(), 'has_more' => $after, 'previous_href' => $before ? $href(['id' => $rows->first()->id, '_pointsToNextItems' => false]) : null, 'next_href' => $after ? $href(['id' => $rows->last()->id, '_pointsToNextItems' => true]) : null, 'reset_href' => $href(null), 'position_reset' => $reset], 'packages' => $rows->map(fn ($p) => $this->dto($current, $p))->all()];
        });
    }

    public function published(string $uuid): EndpointSoftwarePackage
    {
        $package = EndpointSoftwarePackage::query()->where('uuid', $uuid)->where('status', 'published')->lock('for update nowait')->firstOrFail();
        $this->assertMetadata($package);

        return $package;
    }

    public function signedParameters(EndpointSoftwarePackage $p): array
    {
        return ['package_uuid' => $p->uuid, 'package_version' => $p->package_version, 'sha256' => $p->sha256, 'size_bytes' => $p->size_bytes, 'signer_sha256' => $p->signer_sha256, 'product_code' => $p->product_code, 'product_version' => $p->product_version, 'architecture' => $p->architecture, 'display_name' => $p->name, 'publisher_label' => $p->publisher];
    }

    public function copy(EndpointSoftwarePackage $p)
    {
        $root = realpath($this->root());
        $path = $this->root().DIRECTORY_SEPARATOR.$p->storage_key;
        $resolved = realpath($path);
        abort_unless($root && $resolved && dirname($resolved) === $root && ! is_link($path) && preg_match('/\A[0-9a-f-]{36}\.msi\z/D', $p->storage_key), 503);
        $in = fopen($resolved, 'rb');
        abort_unless($in !== false, 503);
        $out = tmpfile();
        if ($out === false) {
            fclose($in);
            abort(503);
        }
        try {
            $size = 0;
            $hash = hash_init('sha256');
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                abort_unless(is_string($chunk) && ($chunk !== '' || feof($in)), 503);
                $size += strlen($chunk);
                abort_unless($size <= $p->size_bytes, 503);
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($out, substr($chunk, $offset));
                    abort_unless($written > 0, 503);
                    $offset += $written;
                }
            }abort_unless($size === $p->size_bytes && hash_equals($p->sha256, hash_final($hash)) && fflush($out) && rewind($out), 503);
            fclose($in);

            return $out;
        } catch (Throwable $e) {
            fclose($in);
            fclose($out);
            throw $e;
        }
    }

    private function uploadLimit(): int
    {
        $limits = [EndpointSoftwareContract::MAX_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $raw = trim((string) ini_get($key));
            if (preg_match('/\A([0-9]+)([KMG])?\z/i', $raw, $match)) {
                $size = (int) $match[1] * (1024 ** array_search(strtoupper($match[2] ?? ''), ['', 'K', 'M', 'G'], true));
                if ($size > 0) {
                    $limits[] = max(0, $size - ($key === 'post_max_size' ? 65536 : 0));
                }
            }
        }

        return min($limits);
    }

    private function root(): string
    {
        return (string) config('endpoint_management.software.package_directory', storage_path('app/private/endpoint-software'));
    }

    private function readActor(User $actor, array $input): User
    {
        abort_if(array_diff(array_keys($input), ['expected_actor_id']) !== [], 422);
        $data = Validator::make($input, ['expected_actor_id' => ['required', 'integer', 'min:1']])->validate();
        $current = $this->actor($actor);
        abort_unless((int) $data['expected_actor_id'] === (int) $current->id, 409);

        return $current;
    }

    private function visible(User $actor): Builder
    {
        return EndpointSoftwarePackage::query()->where(fn ($q) => $q->where('status', 'published')->orWhere('uploaded_by_user_id', $actor->id)->when($actor->canDo(self::REVIEW), fn ($review) => $review->orWhereIn('status', ['uploaded', 'pending_review', 'withdrawn'])));
    }

    private function hash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function assertMetadata(EndpointSoftwarePackage $p): void
    {
        $data = array_intersect_key($p->getAttributes(), array_flip(self::METADATA)) + ['sha256' => $p->sha256, 'size_bytes' => $p->size_bytes];
        abort_unless(hash_equals($p->metadata_hash, $this->hash($data)), 409);
    }

    private function event(EndpointSoftwarePackage $p, User $actor, string $type, string $reason): void
    {
        DB::table('endpoint_software_package_events')->insert(['endpoint_software_package_id' => $p->id, 'actor_user_id' => $actor->id, 'event_type' => $type, 'status_version' => $p->status_version, 'reason' => $reason, 'occurred_at' => now()]);
    }

    private function writeDto(User $actor, EndpointSoftwarePackage $p): array
    {
        return ['actor_id' => (int) $actor->id, 'package' => $this->dto($actor, $p)];
    }

    private function dto(User $actor, EndpointSoftwarePackage $p): array
    {
        $owner = (int) $p->uploaded_by_user_id === (int) $actor->id;
        $submit = $owner && $actor->canDo(self::UPLOAD) && $p->status === 'uploaded';
        $review = ! $owner && app(EndpointSoftwareIdentity::class)->confirmed($actor) && $actor->canDo(self::REVIEW) && $p->status === 'pending_review';
        $withdraw = app(EndpointSoftwareIdentity::class)->confirmed($actor) && $actor->canDo(self::REVIEW) && $p->status !== 'withdrawn';
        $base = '/security-devices/software/'.$p->uuid;
        $user = fn ($id) => $id === null ? null : User::query()->whereKey($id)->first(['id', 'name']);
        $uploaded = $user($p->uploaded_by_user_id);
        $reviewed = $user($p->reviewed_by_user_id);

        return ['uuid' => $p->uuid, 'version' => $p->package_version, 'status' => $p->status, 'status_version' => $p->status_version, 'name' => $p->name, 'publisher' => $p->publisher, 'product_code' => $p->product_code, 'product_version' => $p->product_version, 'architecture' => $p->architecture, 'signer_sha256' => $p->signer_sha256, 'sha256' => $p->sha256, 'size_bytes' => $p->size_bytes, 'service' => null, 'uploaded_by' => $uploaded ? ['id' => (int) $uploaded->id, 'name' => $uploaded->name] : null, 'reviewed_by' => $reviewed ? ['id' => (int) $reviewed->id, 'name' => $reviewed->name] : null, 'uploaded_at' => $p->created_at?->toISOString(), 'submitted_at' => $p->submitted_at?->toISOString(), 'reviewed_at' => $p->reviewed_at?->toISOString(), 'published_at' => $p->published_at?->toISOString(), 'withdrawn_at' => $p->withdrawn_at?->toISOString(), 'can_submit' => $submit, 'can_review' => $review, 'can_withdraw' => $withdraw, 'submit_href' => $submit ? $base.'/submit' : null, 'review_href' => $review ? $base.'/review' : null, 'withdraw_href' => $withdraw ? $base.'/withdraw' : null, 'show_href' => $base.'?expected_actor_id='.$actor->id];
    }

    private function cursor(mixed $raw): array
    {
        abort_unless(is_string($raw) && strlen($raw) <= 128 && preg_match('/\A[A-Za-z0-9_-]+\z/D', $raw), 400);
        try {
            $data = json_decode(base64_decode(strtr($raw, '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(400);
        }
        abort_unless(is_array($data) && array_keys($data) === ['id', '_pointsToNextItems'] && is_int($data['id']) && $data['id'] > 0 && is_bool($data['_pointsToNextItems']), 400);
        abort_unless((new Cursor(['id' => $data['id']], $data['_pointsToNextItems']))->encode() === $raw, 400);

        return $data;
    }
}
