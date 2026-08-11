<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ItSecurityDesktopReleaseFixturePack extends Model
{
    /** Historical pack retained because its immutable D16 evidence is still referenced. */
    public const string LEGACY_PACK_KEY = 'it-security-desktop-release-v1';

    /** Current disjoint fixture generation. */
    public const string PACK_KEY = 'it-security-desktop-release-v10';

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
