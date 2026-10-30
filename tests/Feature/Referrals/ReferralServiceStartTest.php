<?php

use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralStartDecision;
use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\Permission;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralStartFixtures.php';

it('separates scheduled approval from actual start and preserves funding work and canonical budgets', function () {
    $f = referralStartFixture();
    $service = app(ReferralServiceCommencement::class);
    $first = $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    expect($first['status'])->toBe('scheduled')->and($f['person']->fresh()->status)->toBe('onboarding');
    expect($service->schedule($f['manager'], $f['referral']->id, 5, $f['input']))->toBe($first);
    expect(fn () => $f['person']->fresh()->update(['status' => 'active']))->toThrow(HttpException::class);
    $this->travel(1)->minutes();
    $actual = referralCommenceInput();
    $receipt = $service->commence($f['manager'], $f['referral']->id, 6, $actual);
    expect($receipt['status'])->toBe('commenced')->and($f['person']->fresh()->status)->toBe('active')
        ->and($f['work']->fresh()->status)->toBe('open')->and($f['agreement']->fresh()->budget_used)->toBe('0.00')
        ->and($f['line']->fresh()->budget_used)->toBe('0.00')->and(ReferralStartDecision::count())->toBe(2);
    expect($service->commence($f['manager'], $f['referral']->id, 6, $actual))->toBe($receipt)
        ->and($f['referral']->fresh()->lock_version)->toBe(7)->and(Client::count())->toBe(1);
    $changed = [...$actual, 'note' => 'A different actual start instruction was supplied.'];
    expect(fn () => $service->commence($f['manager'], $f['referral']->id, 7, $changed))->toThrow(HttpException::class);
});

it('rejects early commencement and a changed agreement after approval', function () {
    $f = referralStartFixture();
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, [...$f['input'], 'agreed_date' => today('Pacific/Auckland')->addDay()->toDateString()]);
    expect(fn () => $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput()))->toThrow(HttpException::class);
    $this->travel(1)->days();
    $f['agreement']->update(['terms' => 'Different contractual terms after the reviewed signature']);
    expect(fn () => $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput()))->toThrow(HttpException::class);
    expect($f['person']->fresh()->status)->toBe('onboarding')->and(ReferralStartDecision::count())->toBe(1);
});

it('requires completed onboarding and valid mandatory consent instead of trusting a checklist', function () {
    $f = referralStartFixture();
    $service = app(ReferralServiceCommencement::class);
    $f['workflow']->update(['status' => 'in_progress']);
    expect(fn () => $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']))->toThrow(HttpException::class);
    $f['workflow']->update(['status' => 'completed']);
    ConsentType::factory()->create(['name' => 'Required support consent', 'category' => 'essential', 'active' => true, 'is_mandatory' => true]);
    expect(fn () => $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']))->toThrow(HttpException::class);
    expect(ReferralServiceStart::count())->toBe(0)->and($f['referral']->fresh()->lock_version)->toBe(5);
});

it('rejects ended future staffing and stale care evidence at actual start', function () {
    $f = referralStartFixture();
    $service = app(ReferralServiceCommencement::class);
    $f['author']->hrEmployeeProfile->update(['end_date' => today('Pacific/Auckland')]);
    expect(fn () => $service->schedule($f['manager'], $f['referral']->id, 5, [...$f['input'], 'agreed_date' => today('Pacific/Auckland')->addDay()->toDateString()]))->toThrow(HttpException::class);
    $f['author']->hrEmployeeProfile->update(['end_date' => null]);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    $f['plan']->update(['content' => ['domains' => [['key' => 'mobility', 'strategies' => [['text' => 'Changed care approach']]]]]]);
    $this->travel(1)->minutes();
    expect(fn () => $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput()))->toThrow(HttpException::class);
    expect($f['person']->fresh()->status)->toBe('onboarding');
});

it('requires independent approval and rechecks permission even for an idempotent replay', function () {
    $f = referralStartFixture();
    $service = app(ReferralServiceCommencement::class);
    expect(fn () => $service->schedule($f['author'], $f['referral']->id, 5, $f['input']))->toThrow(HttpException::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    $f['manager']->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.commence')->firstOrFail()->id, ['allowed' => false]);
    expect(fn () => $service->schedule($f['manager']->fresh(), $f['referral']->id, 5, $f['input']))->toThrow(HttpException::class);
    expect(ReferralStartDecision::count())->toBe(1);
});

it('keeps existing support active when a separate additional-service start is cancelled', function () {
    $f = referralStartFixture(true);
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    $service->cancel($f['manager'], $f['referral']->id, 6, ['submission_key' => (string) Str::uuid(), 'reason' => 'The person requested a later review of this additional support.']);
    expect($f['person']->fresh()->status)->toBe('active')->and($f['workflow']->scope)->toBe('additional_support')
        ->and(ReferralServiceStart::first()->status)->toBe('cancelled')->and($f['work']->fresh()->status)->toBe('open');
});
