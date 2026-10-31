<?php

namespace App\Http\Requests\HealthSafety;

use App\Models\Client;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\Site;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use App\Services\UserSiteAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Validation for creating a risk assessment (also the base for Edit + Supersede).
 * Mirrors the wizard's per-step validateStep. The 5×5 matrix scalars are 1–5;
 * the assessable is a Site / Client / H&S event, or standalone.
 */
class StoreHsRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission remains route-gated. Route-model actions additionally deny
        // direct objects outside the viewer's canonical Site scope before validation.
        $assessment = $this->route('assessment');
        if ($assessment === null) { return true; }
        $id = $assessment instanceof HsRiskAssessment ? $assessment->getKey() : $assessment;
        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id) || filter_var($id, FILTER_VALIDATE_INT) === false) { return false; }
        return HealthSafetyReadContext::within($this->user(), RiskAssessmentPresenter::readPermissions(), fn ($context) => $context->consumeSourceViewer(RiskAssessmentPresenter::readPermissions(), function ($current) use ($id, $context): bool {
            if (! $current->canDo('hazards.manage')) { return false; }
            $query = RiskAssessmentPresenter::applySiteScopeInContext(HsRiskAssessment::query()->whereKey((int) $id), $current, $context);
            $query->whereExists(app(HsRiskAssessmentService::class)->authorityQueryInContext($current, $context, ['hazards.manage'])->selectRaw('1')->toBase());
            return app(HsEventAccessService::class)->applyFirstAidRiskSourceScope($query, $current, $context)->exists();
        }));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'risk_description' => ['nullable', 'string', 'max:5000'],

            'attach_type' => ['required', 'in:standalone,site,client,event'],
            'attach_id' => ['nullable', 'integer', 'required_unless:attach_type,standalone'],

            'likelihood' => ['required', 'integer', 'between:1,5'],
            'consequence' => ['required', 'integer', 'between:1,5'],

            'existing_controls' => ['nullable', 'string', 'max:5000'],
            'additional_controls' => ['nullable', 'string', 'max:5000'],

            'residual_likelihood' => ['nullable', 'required_with:residual_consequence', 'integer', 'between:1,5'],
            'residual_consequence' => ['nullable', 'required_with:residual_likelihood', 'integer', 'between:1,5'],
            'risk_acceptable' => ['nullable', 'boolean'],

            'review_frequency_days' => ['nullable', 'integer', 'in:30,90,180,365'],
            'review_due_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            HealthSafetyReadContext::within($this->user(), RiskAssessmentPresenter::readPermissions(), fn ($context) => $context->consumeSourceViewer(RiskAssessmentPresenter::readPermissions(), function ($current, $reads) use ($validator, $context): void {
            $type = $this->input('attach_type');
            $id = $this->input('attach_id');
            $siteAccess = app(UserSiteAccessService::class);
            $siteIds = $siteAccess->accessibleSiteIds($current, ['healthSafety.viewAllSites'], $reads);

            if ($type === 'standalone') {
                if (! $siteAccess->canBypass($current, ['healthSafety.viewAllSites'])) {
                    $validator->errors()->add('attach_type', 'Application-wide assessments require application-wide H&S access.');
                }

                return;
            }

            if (! $id) {
                return;
            }

            $exists = match ($type) {
                'site' => Site::query()->whereIn('id', $siteIds)->whereKey($id)->exists(),
                'client' => Client::query()->whereIn('site_id', $siteIds)->whereKey($id)->exists(),
                'event' => app(HsEventAccessService::class)->applyFirstAidSourceScope($siteAccess->applyHsEventScope(HsEvent::query(), $current, ['healthSafety.viewAllSites'])->whereNotNull('site_id')->whereKey($id), $current, $context)->exists(),
                default => false,
            };

            if (! $exists) {
                $validator->errors()->add('attach_id', 'The selected '.$type.' is unavailable for your Site access.');
            }
            }));
        });
    }

    public function messages(): array
    {
        return [
            'attach_id.required_unless' => 'Choose what this assessment is attached to.',
            'likelihood.between' => 'Pick a likelihood between 1 and 5.',
            'consequence.between' => 'Pick a consequence between 1 and 5.',
        ];
    }
}
