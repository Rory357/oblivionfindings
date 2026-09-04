<?php

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('event reporter and action owner cannot self-verify health and safety corrective actions', function () {
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $reporter = User::factory()->create([]);
    $assignee = User::factory()->create([]);
    $independentVerifier = User::factory()->create([]);

    $event = HsEvent::factory()->create([
        'reference_number' => 'HS-EVT-9001',
        'event_category' => HsEvent::CATEGORY_HAZARD,
        'severity' => HsEvent::SEVERITY_MEDIUM,
        'status' => 'open',
        'site_id' => $site->id,
        'occurred_at' => now()->subDays(2),
        'reported_at' => now()->subDays(2),
        'created_by' => $reporter->id,
    ]);

    $action = HsCorrectiveAction::create([
        'hs_event_id' => $event->id,
        'reference_number' => 'HS-CA-9001',
        'action_type' => HsCorrectiveAction::TYPE_CORRECTIVE,
        'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
        'title' => 'Install safety guard on conveyor',
        'status' => HsCorrectiveAction::STATUS_COMPLETED,
        'assigned_to_user_id' => $assignee->id,
        'completed_by_user_id' => $assignee->id,
        'completed_at' => now()->subHour(),
        'completion_notes' => 'Machine guard securely fitted and tested.',
    ]);

    $service = app(HsCorrectiveActionService::class);

    // 1. Action owner cannot verify (self-verification denied)
    expect(fn () => $service->verify($action, [
        'verified_by_user_id' => $assignee->id,
        'evidence_reviewed' => true,
        'effectiveness_confirmed' => true,
    ]))->toThrow(\InvalidArgumentException::class);

    // 2. Original event reporter cannot verify (separation of duties)
    expect(fn () => $service->verify($action, [
        'verified_by_user_id' => $reporter->id,
        'evidence_reviewed' => true,
        'effectiveness_confirmed' => true,
    ]))->toThrow(\InvalidArgumentException::class);

    // 3. Independent verifier can successfully verify
    $verified = $service->verify($action, [
        'verified_by_user_id' => $independentVerifier->id,
        'evidence_reviewed' => true,
        'effectiveness_confirmed' => true,
        'verification_notes' => 'Independent site walk confirmed guard is compliant.',
    ]);

    expect($verified->status)->toBe(HsCorrectiveAction::STATUS_VERIFIED)
        ->and((int) $verified->verified_by_user_id)->toBe($independentVerifier->id)
        ->and($verified->effectiveness_confirmed)->toBeTrue();
});
