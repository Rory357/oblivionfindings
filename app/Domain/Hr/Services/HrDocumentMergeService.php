<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HrDocumentMergeService
{
    /**
     * Standard merge fields available for all document templates.
     *
     * Each key is the placeholder token (e.g. {{employee_name}}) and the
     * value describes the data source.
     */
    public const MERGE_FIELDS = [
        // Employee profile fields
        '{{employee_name}}'        => 'HrEmployeeProfile -> user.name',
        '{{employee_number}}'      => 'HrEmployeeProfile -> employee_number',
        '{{position_title}}'       => 'HrEmployeeProfile -> position_title',
        '{{position_role}}'        => 'HrEmployeeProfile -> position_role',
        '{{employment_type}}'      => 'HrEmployeeProfile -> employment_type',
        '{{hours_per_week}}'       => 'HrEmployeeProfile -> hours_per_week',
        '{{hourly_rate}}'          => 'HrEmployeeProfile -> hourly_rate',
        '{{annual_salary}}'        => 'HrEmployeeProfile -> annual_salary',
        '{{start_date}}'           => 'HrEmployeeProfile -> start_date (formatted)',
        '{{end_date}}'             => 'HrEmployeeProfile -> end_date (formatted)',
        '{{probation_end_date}}'   => 'HrEmployeeProfile -> probation_end_date (formatted)',
        '{{personal_email}}'       => 'HrEmployeeProfile -> personal_email',
        '{{work_email}}'           => 'HrEmployeeProfile -> work_email',
        '{{home_address}}'         => 'HrEmployeeProfile -> home_address',

        // Site fields
        '{{site_name}}'            => 'HrEmployeeProfile -> primarySite.name',
        '{{site_address}}'         => 'HrEmployeeProfile -> primarySite.address',

        // Offer fields (for offer letters)
        '{{proposed_start_date}}'  => 'HrOffer -> proposed_start_date (formatted)',
        '{{offer_hours_per_week}}' => 'HrOffer -> hours_per_week',
        '{{offer_hourly_rate}}'    => 'HrOffer -> hourly_rate',
        '{{offer_annual_salary}}'  => 'HrOffer -> annual_salary',

        // Organisation fields
        '{{company_name}}'         => 'Tenant -> name (from config or tenant model)',
        '{{current_date}}'         => 'now() formatted',
        '{{current_year}}'         => 'now()->year',
    ];

    /**
     * Merge a document template with employee/offer data, replacing placeholders.
     *
     * Takes a template's content (HTML or text with {{placeholders}}) and
     * replaces them with actual values from the employee profile and
     * optional offer record.
     *
     * @param  HrDocumentTemplate  $template
     * @param  HrEmployeeProfile   $profile
     * @param  HrOffer|null        $offer    Optional offer record for offer-specific fields
     * @param  array               $extra    Additional key-value pairs for custom merge fields
     * @return string  The merged content with all placeholders replaced
     */
    public function mergeTemplate(HrDocumentTemplate $template, HrEmployeeProfile $profile, ?HrOffer $offer = null, array $extra = []): string
    {
        // TODO: Load employee profile with user and primarySite relationships
        // TODO: Build the merge data map from profile, offer, and extras
        // TODO: Replace all {{placeholder}} tokens in the template content
        // TODO: Handle missing/null values gracefully (replace with empty string or '[NOT SET]')
        // TODO: Validate that no unreplaced placeholders remain
        // TODO: Log a warning if any placeholders were not resolved

        $profile->loadMissing(['user', 'primarySite']);
        $content = $template->content ?? '';

        $mergeData = $this->buildMergeData($profile, $offer, $extra);

        foreach ($mergeData as $placeholder => $value) {
            $content = str_replace($placeholder, (string) ($value ?? ''), $content);
        }

        // Check for unresolved placeholders
        if (preg_match_all('/\{\{(\w+)\}\}/', $content, $matches)) {
            Log::warning('Unresolved merge fields in template', [
                'template_id' => $template->id,
                'unresolved' => $matches[1],
            ]);
        }

        return $content;
    }

    /**
     * Generate a document from a template and store it on disk.
     *
     * Merges the template, stores the resulting content as a file, and
     * creates an HrDocument record linked to the employee profile.
     *
     * @param  HrDocumentTemplate  $template
     * @param  HrEmployeeProfile   $profile
     * @param  int                 $generatedBy  User ID
     * @param  HrOffer|null        $offer
     * @param  array               $extra        Additional merge field values
     * @return HrDocument
     */
    public function generateDocument(HrDocumentTemplate $template, HrEmployeeProfile $profile, int $generatedBy, ?HrOffer $offer = null, array $extra = []): HrDocument
    {
        // TODO: Call mergeTemplate() to produce the merged content
        // TODO: Determine the output format (HTML, PDF, or plain text) based on template category
        // TODO: If PDF generation is needed, use a PDF library (or fall back to HTML)
        // TODO: Store the file on the 'private' disk under hr-documents/{tenant_id}/{employee_id}/
        // TODO: Create an HrDocument record with:
        //       - employee_profile_id
        //       - template_id
        //       - title (template name + date)
        //       - category (from template)
        //       - storage_disk, storage_path, original_name, mime_type, size_bytes
        //       - generated_from_template = true
        //       - created_by = generatedBy
        // TODO: Fire DocumentGenerated event
        // TODO: Log audit trail entry
        // TODO: Return the HrDocument

        $mergedContent = $this->mergeTemplate($template, $profile, $offer, $extra);

        $filename = sprintf(
            'hr-documents/%d/%d/%s_%s.html',
            $profile->tenant_id,
            $profile->id,
            str($template->name)->slug(),
            now()->format('Y-m-d_His')
        );

        Storage::disk('private')->put($filename, $mergedContent);
        $sizeBytes = Storage::disk('private')->size($filename);

        return HrDocument::create([
            'tenant_id' => $profile->tenant_id,
            'employee_profile_id' => $profile->id,
            'template_id' => $template->id,
            'title' => $template->name . ' - ' . now()->format('d M Y'),
            'category' => $template->category,
            'storage_disk' => 'private',
            'storage_path' => $filename,
            'original_name' => basename($filename),
            'mime_type' => 'text/html',
            'size_bytes' => $sizeBytes,
            'is_restricted' => false,
            'generated_from_template' => true,
            'created_by' => $generatedBy,
            'uploaded_by' => $generatedBy,
        ]);
    }

    /**
     * Preview a merged template without storing it.
     *
     * @param  HrDocumentTemplate  $template
     * @param  HrEmployeeProfile   $profile
     * @param  HrOffer|null        $offer
     * @param  array               $extra
     * @return string  The merged content
     */
    public function preview(HrDocumentTemplate $template, HrEmployeeProfile $profile, ?HrOffer $offer = null, array $extra = []): string
    {
        return $this->mergeTemplate($template, $profile, $offer, $extra);
    }

    /**
     * Get available merge fields for a template category.
     *
     * Returns the subset of MERGE_FIELDS relevant to the given category
     * (e.g. offer letters include offer fields, general documents do not).
     *
     * @param  string  $category
     * @return array<string, string>
     */
    public function getAvailableFields(string $category): array
    {
        // TODO: Filter MERGE_FIELDS based on category
        // TODO: 'offer_letter' category includes all fields
        // TODO: 'general' excludes offer-specific fields
        // TODO: Add any custom fields defined on the template

        $fields = self::MERGE_FIELDS;

        if ($category !== 'offer_letter') {
            $fields = array_filter($fields, function ($key) {
                return ! str_starts_with($key, '{{offer_');
            }, ARRAY_FILTER_USE_KEY);
        }

        return $fields;
    }

    /**
     * Build the merge data map from employee profile, offer, and extra values.
     *
     * @return array<string, string|null>
     */
    protected function buildMergeData(HrEmployeeProfile $profile, ?HrOffer $offer, array $extra): array
    {
        $data = [
            '{{employee_name}}' => $profile->user?->name,
            '{{employee_number}}' => $profile->employee_number,
            '{{position_title}}' => $profile->position_title,
            '{{position_role}}' => $profile->position_role,
            '{{employment_type}}' => $profile->employment_type,
            '{{hours_per_week}}' => $profile->hours_per_week,
            '{{hourly_rate}}' => $profile->hourly_rate,
            '{{annual_salary}}' => $profile->annual_salary,
            '{{start_date}}' => $profile->start_date?->format('d F Y'),
            '{{end_date}}' => $profile->end_date?->format('d F Y'),
            '{{probation_end_date}}' => $profile->probation_end_date?->format('d F Y'),
            '{{personal_email}}' => $profile->personal_email,
            '{{work_email}}' => $profile->work_email,
            '{{home_address}}' => $profile->home_address,
            '{{site_name}}' => $profile->primarySite?->name,
            '{{site_address}}' => $profile->primarySite?->address,
            '{{company_name}}' => config('app.name'),
            '{{current_date}}' => now()->format('d F Y'),
            '{{current_year}}' => (string) now()->year,
        ];

        if ($offer) {
            $data['{{proposed_start_date}}'] = $offer->proposed_start_date?->format('d F Y');
            $data['{{offer_hours_per_week}}'] = $offer->hours_per_week;
            $data['{{offer_hourly_rate}}'] = $offer->hourly_rate;
            $data['{{offer_annual_salary}}'] = $offer->annual_salary;
        }

        foreach ($extra as $key => $value) {
            $placeholder = str_starts_with($key, '{{') ? $key : '{{' . $key . '}}';
            $data[$placeholder] = $value;
        }

        return $data;
    }
}
