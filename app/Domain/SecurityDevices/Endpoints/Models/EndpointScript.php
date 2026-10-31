<?php

namespace App\Domain\SecurityDevices\Endpoints\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EndpointScript extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['artifact_ciphertext', 'runtime_ciphertext', 'manifest_json', 'review_json', 'request_key', 'request_hash'];

    protected $casts = ['script_version' => 'integer', 'status_version' => 'integer', 'submitted_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(function (self $script): void {
            foreach (['uuid', 'script_version', 'manifest_json', 'review_json', 'content_hash', 'artifact_ciphertext', 'runtime_ciphertext', 'uploaded_by_user_id', 'request_key', 'request_hash'] as $field) {
                if ($script->isDirty($field)) {
                    throw new LogicException('Script identity and review material are immutable. Upload a new version.');
                }
            }
        });
        self::deleting(fn () => throw new LogicException('Withdraw scripts without deleting their evidence.'));
    }
}
