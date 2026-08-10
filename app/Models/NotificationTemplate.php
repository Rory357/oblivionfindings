<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'type',
        'key',
        'name',
        'category',
        'subject',
        'body',
        'merge_fields',
        'is_active',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'merge_fields' => 'array',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function scopeEmail($query)
    {
        return $query->where('type', 'email');
    }

    public function scopeSms($query)
    {
        return $query->where('type', 'sms');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public static function findByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }
}
