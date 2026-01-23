<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedication extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'dosage',
        'frequency',
        'route',
        'prescriber',
        'start_date',
        'end_date',
        'instructions',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
