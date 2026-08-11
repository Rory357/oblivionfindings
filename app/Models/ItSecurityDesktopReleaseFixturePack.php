<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ItSecurityDesktopReleaseFixturePack extends Model
{
    public const string PACK_KEY = 'it-security-desktop-release-v1';

    public const string STATE_READY = 'ready';

    public const string STATE_CLEANUP_FILES_PENDING = 'cleanup_files_pending';

    protected $fillable = [
        'pack_key',
        'release_revision',
        'state',
        'manifest',
        'manifest_sha256',
        'prepared_at',
        'last_verified_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'prepared_at' => 'immutable_datetime',
        'last_verified_at' => 'immutable_datetime',
    ];
}
