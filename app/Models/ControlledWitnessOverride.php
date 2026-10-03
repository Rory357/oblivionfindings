<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class ControlledWitnessOverride extends Model
{
    protected $guarded = [];
    protected $casts = ['medicine_ids' => 'array', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'decided_at' => 'datetime', 'followup_due_at' => 'datetime', 'signed_off_at' => 'datetime'];
}
