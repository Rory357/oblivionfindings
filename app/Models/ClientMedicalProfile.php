<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientMedicalProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'medical_history',
        'disabilities',
        'allergies',
        'notes',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
