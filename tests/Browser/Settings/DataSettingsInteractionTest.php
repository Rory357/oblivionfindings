<?php

use App\Models\AppSetting;
use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\User;
use Laravel\Dusk\Browser;

function clickDataSelector(Browser $browser, string $selector): void
{
    $browser->script(str_replace('__SELECTOR__', json_encode($selector, JSON_THROW_ON_ERROR), <<<'JS'
        const selector = __SELECTOR__;
        const element = document.querySelector(selector);

        if (!element) {
            throw new Error(`Element not found: ${selector}`);
        }

        element.scrollIntoView({ block: 'center' });
        const pointerOptions = {
            bubbles: true,
            cancelable: true,
            composed: true,
            button: 0,
            buttons: 1,
            pointerId: 1,
            pointerType: 'mouse',
        };

        if (typeof PointerEvent !== 'undefined') {
            element.dispatchEvent(new PointerEvent('pointerdown', pointerOptions));
            element.dispatchEvent(new PointerEvent('pointerup', pointerOptions));
        }

        element.dispatchEvent(new MouseEvent('mousedown', pointerOptions));
        element.dispatchEvent(new MouseEvent('mouseup', pointerOptions));
        element.click();
    JS));
}

function dataSelectorExists(Browser $browser, string $selector): bool
{
    return (bool) (($browser->script(
        'return !!document.querySelector(' . json_encode($selector, JSON_THROW_ON_ERROR) . ');'
    )[0]) ?? false);
}

function waitForDataSelector(Browser $browser, string $selector, int $seconds = 10): void
{
    $browser->waitUsing($seconds, 100, fn () => dataSelectorExists($browser, $selector));
}

function chooseDataOption(Browser $browser, string $triggerSelector, string $optionSelector): void
{
    clickDataSelector($browser, $triggerSelector);
    waitForDataSelector($browser, $optionSelector);
    clickDataSelector($browser, $optionSelector);
}

test('data settings persist retention privacy and compliance changes', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $privacyUrl = 'https://qa-' . now()->format('His') . '.example.com/privacy';
    $custodian = 'QA Custodian ' . now()->format('His');

    DataRetentionPolicy::query()->where('model_type', 'audit_logs')->delete();
    AppSetting::query()->whereIn('key', [
        'settings.data.privacy',
        'settings.data.compliance',
    ])->delete();

    $this->browse(function (Browser $browser) use ($admin, $privacyUrl, $custodian) {
        $browser->loginAs($admin)
            ->visit('/settings/data')
            ->waitForText('Data & Privacy', 10);

        clickDataSelector($browser, '[dusk="data-tab-retention"]');
        waitForDataSelector($browser, '[dusk="retention-audit-logs"]');
        clickDataSelector($browser, '[dusk="data-save-retention"]');
        $browser->waitForText('Retention policies saved.', 10);

        clickDataSelector($browser, '[dusk="data-tab-compliance"]');
        waitForDataSelector($browser, '[dusk="data-privacy-url"]');
        $browser->type('[dusk="data-privacy-url"]', $privacyUrl);
        clickDataSelector($browser, '[dusk="data-save-privacy"]');
        $browser->waitForText('Privacy settings saved.', 10);

        $browser->type('[dusk="data-compliance-custodian"]', $custodian);
        clickDataSelector($browser, '[dusk="data-save-compliance"]');
        $browser->waitForText('Compliance settings saved.', 10)
            ->refresh()
            ->waitForText('Data & Privacy', 10);

        clickDataSelector($browser, '[dusk="data-tab-retention"]');
        waitForDataSelector($browser, '[dusk="retention-audit-logs"]');
        $retentionText = $browser->script(
            "return document.querySelector('[dusk=\"retention-audit-logs\"]')?.textContent?.trim() ?? '';"
        )[0] ?? '';

        expect($retentionText)->toContain('5 years');

        clickDataSelector($browser, '[dusk="data-tab-compliance"]');
        waitForDataSelector($browser, '[dusk="data-privacy-url"]');
        $browser->assertInputValue('[dusk="data-privacy-url"]', $privacyUrl)
            ->assertInputValue('[dusk="data-compliance-custodian"]', $custodian);

    });

    $auditPolicy = DataRetentionPolicy::query()->where('model_type', 'audit_logs')->firstOrFail();
    $privacySettings = AppSetting::query()->where('key', 'settings.data.privacy')->value('value');
    $complianceSettings = AppSetting::query()->where('key', 'settings.data.compliance')->value('value');

    expect($auditPolicy->retention_conditions['setting_value'] ?? null)->toBe('5yr');
    expect($privacySettings['privacy_url'] ?? null)->toBe($privacyUrl);
    expect($complianceSettings['data_sovereignty'] ?? null)->toBe('nz-only');
    expect($complianceSettings['health_custodian'] ?? null)->toBe($custodian);
});

test('data settings create privacy requests and breach records through the browser', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $requesterName = 'QA Requester ' . now()->format('His');
    $requesterEmail = 'qa-request-' . now()->format('His') . '@example.com';
    $breachDescription = 'QA privacy breach ' . now()->format('His');
    $discoveryDate = now()->subDay()->toDateString();

    DataSubjectRequest::query()->delete();
    DataBreachLog::query()->delete();

    $this->browse(function (Browser $browser) use ($admin, $requesterName, $requesterEmail, $breachDescription, $discoveryDate) {
        $browser->loginAs($admin)
            ->visit('/settings/data')
            ->waitForText('Data & Privacy', 10);

        clickDataSelector($browser, '[dusk="data-tab-requests"]');
        waitForDataSelector($browser, '[dusk="data-dsar-open"]');
        clickDataSelector($browser, '[dusk="data-dsar-open"]');
        $browser->waitForText('New Privacy Request', 10)
            ->type('[dusk="data-dsar-name"]', $requesterName)
            ->type('[dusk="data-dsar-email"]', $requesterEmail)
            ->type('[dusk="data-dsar-phone"]', '0210000000')
            ->type('[dusk="data-dsar-details"]', 'Created during browser QA coverage.');
        clickDataSelector($browser, '[dusk="data-dsar-submit"]');

        $browser->waitUsing(10, 250, fn () => DataSubjectRequest::query()->where('subject_email', $requesterEmail)->exists())
            ->waitForText('Privacy request created.', 10);

        $request = DataSubjectRequest::query()->where('subject_email', $requesterEmail)->firstOrFail();

        $browser->assertSeeIn(sprintf('[dusk="data-dsar-row-%s"]', $request->reference_number), $requesterName);

        clickDataSelector($browser, '[dusk="data-breach-open"]');
        $browser->waitForText('Report Data Breach', 10);
        $browser->type('[dusk="data-breach-description"]', $breachDescription)
            ->type('[dusk="data-breach-individuals"]', '7')
            ->type('[dusk="data-breach-discovery"]', $discoveryDate);
        clickDataSelector($browser, '[dusk="data-breach-submit"]');

        $browser->waitUsing(10, 250, fn () => DataBreachLog::query()->where('nature_of_breach', $breachDescription)->exists())
            ->waitForText('Data breach recorded.', 10);

        $breach = DataBreachLog::query()->where('nature_of_breach', $breachDescription)->firstOrFail();

        $browser->assertSeeIn(sprintf('[dusk="data-breach-row-%s"]', $breach->breach_reference), 'Unauthorised Access')
            ->assertSeeIn(sprintf('[dusk="data-breach-row-%s"]', $breach->breach_reference), 'Medium')
            ->assertSeeIn(sprintf('[dusk="data-breach-row-%s"]', $breach->breach_reference), 'Not required');
    });

    $request = DataSubjectRequest::query()->where('subject_email', $requesterEmail)->firstOrFail();
    $breach = DataBreachLog::query()->where('nature_of_breach', $breachDescription)->firstOrFail();

    expect($request->request_type)->toBe('access');
    expect($breach->breach_type)->toBe('unauthorised_access');
    expect($breach->severity)->toBe('medium');
    expect((int) $breach->approximate_individuals_affected)->toBe(7);
});

test('data settings create edit and remove processors through the browser', function () {
    $admin = User::where('email', 'admin@test.com')->firstOrFail();
    $company = 'QA Processor ' . now()->format('His');
    $updatedCompany = $company . ' Updated';
    $reviewDate = now()->addDays(14)->toDateString();

    AppSetting::query()->where('key', 'settings.data.processors')->delete();

    $this->browse(function (Browser $browser) use ($admin, $company, $updatedCompany, $reviewDate) {
        $browser->loginAs($admin)
            ->visit('/settings/data')
            ->waitForText('Data & Privacy', 10);

        clickDataSelector($browser, '[dusk="data-tab-compliance"]');
        waitForDataSelector($browser, '[dusk="data-processor-open"]');
        clickDataSelector($browser, '[dusk="data-processor-open"]');
        $browser->waitForText('Add Third-Party Data Processor', 10)
            ->type('[dusk="data-processor-company"]', $company)
            ->type('[dusk="data-processor-contact"]', 'QA Contact')
            ->type('[dusk="data-processor-email"]', 'processor@example.com')
            ->type('[dusk="data-processor-review"]', $reviewDate);
        chooseDataOption($browser, '[dusk="data-processor-purpose"]', '[dusk="data-processor-purpose-email"]');
        chooseDataOption($browser, '[dusk="data-processor-agreement"]', '[dusk="data-processor-agreement-standard-terms"]');
        clickDataSelector($browser, '[dusk="data-processor-submit"]');

        $processorId = null;

        $browser->waitUsing(10, 250, function () use (&$processorId, $company) {
            $processors = AppSetting::query()->where('key', 'settings.data.processors')->value('value');
            $record = collect(is_array($processors) ? $processors : [])->firstWhere('company', $company);
            $processorId = is_array($record) ? ($record['id'] ?? null) : null;

            return $processorId !== null;
        })->waitForText('Processor added.', 10);

        $browser->assertSeeIn(sprintf('[dusk="data-processor-row-%s"]', $processorId), $company);

        clickDataSelector($browser, sprintf('[dusk="data-processor-edit-%s"]', $processorId));
        $browser->waitForText('Edit Third-Party Data Processor', 10)
            ->clear('[dusk="data-processor-company"]')
            ->type('[dusk="data-processor-company"]', $updatedCompany);
        clickDataSelector($browser, '[dusk="data-processor-submit"]');

        $browser->waitUsing(10, 250, function () use ($updatedCompany) {
            $processors = AppSetting::query()->where('key', 'settings.data.processors')->value('value');

            return collect(is_array($processors) ? $processors : [])->contains(
                fn ($record) => is_array($record) && ($record['company'] ?? null) === $updatedCompany
            );
        })->waitForText('Processor updated.', 10);

        $browser->assertSeeIn(sprintf('[dusk="data-processor-row-%s"]', $processorId), $updatedCompany);

        clickDataSelector($browser, sprintf('[dusk="data-processor-remove-%s"]', $processorId));
        $browser->waitUsing(10, 250, function () {
            $processors = AppSetting::query()->where('key', 'settings.data.processors')->value('value');

            return count(is_array($processors) ? $processors : []) === 0;
        })->waitForText('Processor removed.', 10)
            ->assertDontSee($updatedCompany);
    });

    $processors = AppSetting::query()->where('key', 'settings.data.processors')->value('value');

    expect(is_array($processors) ? $processors : [])->toBe([]);
});
