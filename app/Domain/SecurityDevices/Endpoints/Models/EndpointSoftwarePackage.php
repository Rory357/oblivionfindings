<?php

namespace App\Domain\SecurityDevices\Endpoints\Models;

use Illuminate\Database\Eloquent\Model;

final class EndpointSoftwarePackage extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_key', 'request_key', 'request_hash'];

    protected $casts = ['package_version' => 'integer', 'status_version' => 'integer', 'size_bytes' => 'integer', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        self::updating(function (self $package): void {
            foreach (['uuid', 'package_version', 'name', 'publisher', 'product_code', 'product_version', 'architecture', 'signer_sha256', 'sha256', 'size_bytes', 'storage_key', 'metadata_hash', 'uploaded_by_user_id', 'request_key', 'request_hash'] as $field) {
                if ($package->isDirty($field)) {
                    throw new \LogicException('Published artifact identity and reviewed metadata are immutable. Upload a new release.');
                }
            }
        });
    }
}
