<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Client extends Model
{
    use HasFactory;

    protected $fillable = ['first_name', 'last_name', 'status'];

    public function supportWorkers()
    {
        return $this->belongsToMany(\App\Models\User::class)->withTimestamps();
    }
}
