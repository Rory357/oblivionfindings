<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class ControlledWitnessRequest extends Model
{
    protected $guarded = [];
    protected $casts = ['answered_at' => 'datetime', 'closed_at' => 'datetime'];
}
