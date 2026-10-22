<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientOnboardingWorkflow extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'client_id',
        'status',
        'started_at',
        'completed_at',
        'completed_by',
        'assigned_to',
        'notes',
        'created_by',
        'scope',
        'service_context_id',
        'site_id',
        'source_referral_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function steps()
    {
        return $this->hasMany(ClientOnboardingStep::class, 'workflow_id');
    }

    public static function createForClient(Client $client, int $createdBy, array $scope = []): self
    {
        // Callers preparing an existing person hold the canonical client lock first.
        $scope = array_intersect_key($scope, array_flip(['scope', 'service_context_id', 'site_id', 'source_referral_id']));
        $workflow = static::create([
            'client_id' => $client->id,
            'status' => 'in_progress',
            'started_at' => now(),
            'created_by' => $createdBy,
            ...$scope,
        ]);

        $defaultSteps = [
            'Referral Received',
            'Needs Assessment',
            'Consent Forms',
            'Care Plan Created',
            'Service Agreement Signed',
            'Staff Assigned',
            'Orientation Complete',
        ];

        foreach ($defaultSteps as $order => $stepName) {
            $workflow->steps()->create([
                'step_name' => $stepName,
                'step_order' => $order + 1,
                'status' => 'pending',
            ]);
        }

        return $workflow->load('steps');
    }
}
