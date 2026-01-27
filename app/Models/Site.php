<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'manager_name',
        'manager_phone',
        'after_hours_phone',
        'emergency_plan_location',
        'medication_storage_location',
        'notes',
        'address_line_1',
        'address_line_2',
        'suburb',
        'city',
        'postcode',
        'country',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function contacts()
    {
        return $this->hasMany(SiteContact::class);
    }

    public function documents()
    {
        return $this->hasMany(SiteDocument::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function getAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->suburb,
            $this->city,
            $this->postcode,
            $this->country,
        ], fn($v) => is_string($v) && trim($v) !== '');

        return implode(', ', $parts);
    }
}
