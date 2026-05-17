<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMealDislike extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'product_id',
        'free_text_name',
        'notes',
        'created_by',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MealProduct::class, 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function displayName(): string
    {
        if ($this->product) {
            return $this->product->name;
        }
        return $this->free_text_name ?? 'Unnamed dislike';
    }

    public function matchTerm(): string
    {
        return $this->product ? $this->product->name : ($this->free_text_name ?? '');
    }
}
