<?php

namespace App\Domain\SecurityDevices\WebPages\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page request waiting for the hub (S&D Phase G). Its answer is kept only until it is served.
 *
 * @property string $uuid
 * @property int $session_id
 * @property string $method
 * @property string $path
 * @property array<string, string> $headers
 * @property string|null $body_base64
 * @property string $state queued | sent | done | failed
 * @property int|null $response_status
 * @property array<string, string>|null $response_headers
 * @property string|null $response_body_base64
 * @property string|null $failure
 */
final class WebRequest extends Model
{
    protected $table = 'security_device_web_requests';

    protected $fillable = [
        'uuid', 'session_id', 'method', 'path', 'headers', 'body_base64', 'state', 'response_status',
        'response_headers', 'response_body_base64', 'failure', 'done_at',
    ];

    protected $casts = [
        'headers' => 'array',
        'response_headers' => 'array',
        'response_status' => 'integer',
        'done_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<WebSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WebSession::class, 'session_id');
    }
}
