<?php

use App\Domain\Referrals\Http\Controllers\InternalSupportPreparationController;
use App\Domain\Referrals\Http\Controllers\PublicReferralController;
use App\Domain\Referrals\Http\Controllers\PublicReferralDocumentController;
use App\Domain\Referrals\Http\Controllers\ReferralCalendarController;
use App\Domain\Referrals\Http\Controllers\ReferralCaseController;
use App\Domain\Referrals\Http\Controllers\ReferralChecklistController;
use App\Domain\Referrals\Http\Controllers\ReferralCommunicationController;
use App\Domain\Referrals\Http\Controllers\ReferralDocumentRequestController;
use App\Domain\Referrals\Http\Controllers\ReferralEvidenceController;
use App\Domain\Referrals\Http\Controllers\ReferralFormsController;
use App\Domain\Referrals\Http\Controllers\ReferralFundingController;
use App\Domain\Referrals\Http\Controllers\ReferralHandoverController;
use App\Domain\Referrals\Http\Controllers\ReferralIntakeDraftController;
use App\Domain\Referrals\Http\Controllers\ReferralInternalFundingController;
use App\Domain\Referrals\Http\Controllers\ReferralPeopleContactController;
use App\Domain\Referrals\Http\Controllers\ReferralProposalController;
use App\Domain\Referrals\Http\Controllers\ReferralReportController;
use App\Domain\Referrals\Http\Controllers\ReferralServiceAgreementController;
use App\Domain\Referrals\Http\Controllers\ReferralSettingsController;
use App\Domain\Referrals\Http\Controllers\ReferralSettingsMigrationController;
use App\Domain\Referrals\Http\Controllers\ReferralStartController;
use App\Domain\Referrals\Http\ProtectDocumentRequestResponses;
use Illuminate\Support\Facades\Route;

Route::get('/referrals/new', [PublicReferralController::class, 'create'])
    ->middleware('throttle:60,1')->name('referrals.public.create');
Route::post('/referrals', [PublicReferralController::class, 'store'])
    ->middleware('throttle:6,1')->name('referrals.public.store');
Route::prefix('referrals/documents/{token}')->middleware(ProtectDocumentRequestResponses::class)->where(['token' => '[a-f0-9]{64}'])->group(function (): void {
    Route::get('/', [PublicReferralDocumentController::class, 'show'])->middleware('throttle:30,1')->name('referrals.public.documents.show');
    Route::post('/check', [PublicReferralDocumentController::class, 'inspect'])->middleware('throttle:10,1')->name('referrals.public.documents.check');
    Route::post('/', [PublicReferralDocumentController::class, 'store'])->middleware('throttle:6,1')->name('referrals.public.documents.store');
});

Route::middleware('auth')->prefix('operations/clients/{client}/support-preparation')->whereNumber('client')->group(function (): void {
    Route::get('/', [InternalSupportPreparationController::class, 'show'])->name('referrals.internal.show');
    Route::post('/', [InternalSupportPreparationController::class, 'store'])->middleware('throttle:15,1')->name('referrals.internal.store');
});

Route::middleware('auth')->prefix('settings/referrals')->name('referrals.settings.')->group(function (): void {
    Route::get('/', [ReferralSettingsController::class, 'index'])->name('index');
    Route::post('/drafts', [ReferralSettingsController::class, 'draft'])->name('draft');
    Route::post('/preview', [ReferralSettingsController::class, 'preview'])->middleware('throttle:30,1')->name('preview');
    Route::post('/routing-preview', [ReferralSettingsController::class, 'routingPreview'])->name('routing-preview');
    Route::get('/migration-candidates', [ReferralSettingsController::class, 'migrationCandidates'])->name('migration-candidates');
    Route::post('/{version}/publish', [ReferralSettingsController::class, 'publish'])->whereNumber('version')->name('publish');
    Route::post('/{version}/discard', [ReferralSettingsController::class, 'discard'])->whereNumber('version')->name('discard');
    Route::post('/{version}/restore', [ReferralSettingsController::class, 'restore'])->whereNumber('version')->name('restore');
});

Route::middleware(['auth'])->prefix('operations/referrals')->name('referrals.')->group(function (): void {
    Route::get('/', [ReferralCaseController::class, 'workspace'])->name('index');
    Route::get('/reports', [ReferralReportController::class, 'index'])->name('reports');
    Route::post('/reports/export', [ReferralReportController::class, 'export'])->middleware('throttle:10,1')->name('reports.export');
    Route::get('/calendar', [ReferralCalendarController::class, 'index'])->name('calendar');
    Route::get('/calendar/events', [ReferralCalendarController::class, 'events'])->name('calendar.events');
    Route::get('/calendar/referral-options', [ReferralCalendarController::class, 'options'])->name('calendar.options');
    Route::get('/staff-options', [ReferralCaseController::class, 'staffOptions'])->name('staff-options');
    Route::get('/drafts', [ReferralIntakeDraftController::class, 'index'])->name('drafts.index');
    Route::post('/drafts', [ReferralIntakeDraftController::class, 'store'])->middleware('throttle:30,1')->name('drafts.store');
    Route::get('/drafts/{draft}', [ReferralIntakeDraftController::class, 'show'])->whereUlid('draft')->name('drafts.show');
    Route::put('/drafts/{draft}', [ReferralIntakeDraftController::class, 'update'])->whereUlid('draft')->middleware('throttle:30,1')->name('drafts.update');
    Route::delete('/drafts/{draft}', [ReferralIntakeDraftController::class, 'destroy'])->whereUlid('draft')->name('drafts.destroy');
    Route::get('/{reference}',[ReferralCaseController::class, 'profile'])->whereUlid('reference')->name('show');
    Route::post('/', [ReferralCaseController::class, 'store'])->name('store');
    Route::get('/data', [ReferralCaseController::class, 'index'])->name('data');
    Route::get('/{reference}/data', [ReferralCaseController::class, 'show'])->whereUlid('reference')->name('show.data');
    Route::get('/{reference}/duplicate-preview', [ReferralCaseController::class, 'duplicatePreview'])->whereUlid('reference')->middleware('throttle:30,1')->name('duplicate-preview');
    Route::get('/{reference}/settings-migration', [ReferralSettingsMigrationController::class, 'show'])->whereUlid('reference')->name('settings-migration.show');
    Route::post('/{reference}/settings-migration', [ReferralSettingsMigrationController::class, 'update'])->whereUlid('reference')->name('settings-migration.update');
    Route::get('/{reference}/handover', [ReferralHandoverController::class, 'preview'])->whereUlid('reference')->name('handover.preview');
    Route::post('/{reference}/handover', [ReferralHandoverController::class, 'store'])->whereUlid('reference')->name('handover.store');
    Route::get('/{reference}/handover/care', [ReferralHandoverController::class, 'carePreview'])->whereUlid('reference')->name('handover.care.preview');
    Route::post('/{reference}/handover/care', [ReferralHandoverController::class, 'publishCare'])->whereUlid('reference')->name('handover.care.publish');
    Route::get('/{reference}/service-start', [ReferralStartController::class, 'show'])->whereUlid('reference')->name('service-start.show');
    Route::post('/{reference}/service-start/check', [ReferralStartController::class, 'check'])->whereUlid('reference')->name('service-start.check');
    Route::post('/{reference}/service-start/{operation}', [ReferralStartController::class, 'update'])->whereUlid('reference')->whereIn('operation', ['schedule', 'commence', 'cancel'])->name('service-start.update');
    Route::post('/{reference}/assign', [ReferralCaseController::class, 'assign'])->whereUlid('reference')->name('assign');
    Route::post('/{reference}/contacts', [ReferralCaseController::class, 'contact'])->whereUlid('reference')->name('contacts.store');
    Route::post('/{reference}/people-contacts', [ReferralPeopleContactController::class, 'store'])->whereUlid('reference')->name('people-contacts.store');
    Route::get('/{reference}/communications', [ReferralCommunicationController::class, 'index'])->whereUlid('reference')->name('communications.index');
    Route::get('/{reference}/communications/compose', [ReferralCommunicationController::class, 'compose'])->whereUlid('reference')->name('communications.compose');
    Route::post('/{reference}/communications', [ReferralCommunicationController::class, 'store'])->whereUlid('reference')->middleware('throttle:10,1')->name('communications.store');
    Route::post('/{reference}/communications/{outbox}', [ReferralCommunicationController::class, 'update'])->whereUlid('reference')->whereNumber('outbox')->middleware('throttle:10,1')->name('communications.update');
    Route::post('/{reference}/lifecycle/{operation}', [ReferralCaseController::class, 'lifecycle'])->whereUlid('reference')->whereIn('operation', ['transition', 'hold', 'close', 'reopen', 'priority'])->name('lifecycle');
    Route::post('/{reference}/reviews/{review}', [ReferralCaseController::class, 'review'])->whereUlid('reference')->whereIn('review', ['authority', 'assessment'])->name('reviews.store');
    Route::patch('/{reference}/intake', [ReferralCaseController::class, 'correct'])->whereUlid('reference')->name('intake.update');
    Route::patch('/{reference}/forms', [ReferralFormsController::class, 'update'])->whereUlid('reference')->name('forms.update');
    Route::patch('/{reference}/checklists', [ReferralChecklistController::class, 'update'])->whereUlid('reference')->name('checklists.update');
    Route::post('/{reference}/funding', [ReferralFundingController::class, 'store'])->whereUlid('reference')->name('funding.store');
    Route::get('/{reference}/proposals', [ReferralProposalController::class, 'index'])->whereUlid('reference')->name('proposals.index');
    Route::post('/{reference}/proposals', [ReferralProposalController::class, 'store'])->whereUlid('reference')->name('proposals.store');
    Route::get('/{reference}/proposals/{proposal}/internal-funding', [ReferralInternalFundingController::class, 'show'])->whereUlid('reference')->whereNumber('proposal')->name('internal-funding.show');
    Route::post('/{reference}/proposals/{proposal}/internal-funding', [ReferralInternalFundingController::class, 'store'])->whereUlid('reference')->whereNumber('proposal')->name('internal-funding.store');
    Route::post('/{reference}/internal-funding/{arrangement}/review', [ReferralInternalFundingController::class, 'review'])->whereUlid('reference')->whereNumber('arrangement')->name('internal-funding.review');
    Route::get('/{reference}/proposals/{proposal}/reviews', [ReferralProposalController::class, 'reviews'])->whereUlid('reference')->whereNumber('proposal')->name('proposals.reviews');
    Route::post('/{reference}/proposals/{proposal}/{operation}', [ReferralProposalController::class, 'review'])->whereUlid('reference')->whereNumber('proposal')->whereIn('operation', ['acceptance', 'approval'])->name('proposals.review');
    Route::get('/{reference}/service-agreement', [ReferralServiceAgreementController::class, 'show'])->whereUlid('reference')->name('service-agreement.show');
    Route::post('/{reference}/service-agreement', [ReferralServiceAgreementController::class, 'store'])->whereUlid('reference')->middleware('throttle:10,1')->name('service-agreement.store');
    Route::get('/{reference}/documents', [ReferralEvidenceController::class, 'index'])->whereUlid('reference')->name('documents.index');
    Route::get('/{reference}/document-requests', [ReferralDocumentRequestController::class, 'index'])->whereUlid('reference')->name('document-requests.index');
    Route::post('/{reference}/document-requests', [ReferralDocumentRequestController::class, 'store'])->whereUlid('reference')->middleware('throttle:10,1')->name('document-requests.store');
    Route::post('/{reference}/document-requests/{documentRequest}/revoke', [ReferralDocumentRequestController::class, 'revoke'])->whereUlid('reference')->whereNumber('documentRequest')->name('document-requests.revoke');
    Route::post('/{reference}/documents', [ReferralEvidenceController::class, 'store'])->whereUlid('reference')->name('documents.store');
    Route::post('/{reference}/documents/{evidence}/rescan', [ReferralEvidenceController::class, 'rescan'])->whereUlid('reference')->whereNumber('evidence')->name('documents.rescan');
    Route::get('/{reference}/documents/{evidence}/download', [ReferralEvidenceController::class, 'download'])->whereUlid('reference')->whereNumber('evidence')->name('documents.download');
    Route::get('/{reference}/funding/{case}/decisions', [ReferralFundingController::class, 'history'])->whereUlid('reference')->whereNumber('case')->name('funding.decisions');
    Route::post('/{reference}/funding/{case}/{operation}', [ReferralFundingController::class, 'update'])->whereUlid('reference')->whereNumber('case')->whereIn('operation', ['reassign', 'prepare', 'submission', 'progress', 'decision', 'verification'])->name('funding.update');
    Route::patch('/{reference}/next-action', [ReferralCaseController::class, 'nextAction'])->whereUlid('reference')->name('next-action');
    Route::post('/{reference}/work', [ReferralCaseController::class, 'saveWork'])->whereUlid('reference')->name('work.store');
    Route::patch('/{reference}/work/{work}', [ReferralCaseController::class, 'saveWork'])->whereUlid('reference')->whereNumber('work')->name('work.update');
    Route::post('/{reference}/work/{work}/finish', [ReferralCaseController::class, 'finishWork'])->whereUlid('reference')->whereNumber('work')->name('work.finish');
});
