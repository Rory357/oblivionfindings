<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable delivery evidence only. P08a remains the sole follow-up lifecycle. */
class MedicationSupportTriggerOutbox extends Model
{
    protected $table = 'medication_support_trigger_outbox';

    protected $fillable = ['client_id', 'assessment_id', 'source_key', 'kind', 'reason', 'occurred_at', 'attempts', 'last_attempt_at', 'delivered_at', 'last_error'];

    protected $casts = ['occurred_at' => 'immutable_datetime', 'last_attempt_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
}
