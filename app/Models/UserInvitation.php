<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'name',
        'user_type',
        'staff_id',
        'client_id',
        'next_of_kin_id',
        'token',
        'status',
        'role_ids',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_by',
    ];

    protected $casts = [
        'role_ids' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Who invited this user
     */
    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Who accepted the invitation
     */
    public function accepter()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * Associated staff record
     */
    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * Associated client record
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Associated next of kin record
     */
    public function nextOfKin()
    {
        return $this->belongsTo(NextOfKin::class);
    }

    /**
     * Scope: Pending invitations
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Expired invitations
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
            ->orWhere(function ($q) {
                $q->where('status', 'pending')
                    ->where('expires_at', '<', now());
            });
    }

    /**
     * Check if invitation is expired
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->expires_at < now();
    }

    /**
     * Check if invitation is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    /**
     * Mark as accepted
     */
    public function markAccepted(int $userId): void
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
            'accepted_by' => $userId,
        ]);
    }

    /**
     * Mark as expired
     */
    public function markExpired(): void
    {
        $this->update(['status' => 'expired']);
    }

    /**
     * Generate a unique token
     */
    public static function generateToken(): string
    {
        return hash('sha256', uniqid() . random_bytes(32));
    }
}
