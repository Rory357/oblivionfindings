<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;

/** Private durable original-batch ledger; never serialize as recipient authority. */
final class BroadcastRecipientReadCommand extends Model
{
    protected $table = 'broadcast_recipient_read_commands';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['original_references', 'request_fingerprint', 'batch_digest', 'result'];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'original_references' => 'array',
            'result' => 'array',
            'captured_at' => 'immutable_datetime',
            'intended_read_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
