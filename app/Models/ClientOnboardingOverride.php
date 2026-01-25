<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientOnboardingOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'key',
        'value',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
