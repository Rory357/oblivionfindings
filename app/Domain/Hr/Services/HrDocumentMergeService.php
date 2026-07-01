<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDocumentTemplate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $profile->loadMissing(['user', 'primarySite']);
        $content = $template->content ?? '';

        $mergeData = $this->buildMergeData($profile, $offer, $extra);

        foreach ($mergeData as $placeholder => $value) {
            $content = str_replace($placeholder, $this->normalizeValue($value), $content);
        }

        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $content, $matches)) {
            $unresolved = array_values(array_unique($matches[0]));
            $content = str_replace($unresolved, '', $content);

            Log::warning('Unresolved merge fields in template', [
                'template_id' => $template->id,
                'employee_profile_id' => $profile->id,
                'unresolved' => $unresolved,
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
        $mergedContent = $this->mergeTemplate($template, $profile, $offer, $extra);
        $slug = Str::slug((string) ($template->name ?: 'template'));
        $stamp = now()->format('Ymd_His');

        $title = $template->name . ' - ' . now()->format('d M Y');
        $pdf = $this->renderPdf($this->wrapHtml($title, $mergedContent));

        $filename = "hr-documents/{$profile->tenant_id}/{$profile->id}/generated_{$slug}_{$stamp}.pdf";

        Storage::disk('private')->put($filename, $pdf);
        $sizeBytes = (int) (Storage::disk('private')->size($filename) ?: strlen($pdf));

        return HrDocument::create([
            'tenant_id' => $profile->tenant_id,
            'employee_profile_id' => $profile->id,
            'template_id' => $template->id,
            'title' => $title,
            'category' => $template->category,
            'storage_disk' => 'private',
            'storage_path' => $filename,
            'original_name' => $slug . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $sizeBytes,
            'is_restricted' => false,
            'generated_from_template' => true,
            'created_by' => $generatedBy,
            'uploaded_by' => $generatedBy,
        ]);
    }

    /**
     * Render an HTML string to a PDF binary using dompdf.
     */
    public function renderPdf(string $html): string
    {
        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /**
     * Wrap merged content (which may be plain text or partial HTML) in a
     * minimal, print-friendly HTML shell so dompdf produces a tidy A4 page.
     */
    public function wrapHtml(string $title, string $content): string
    {
        $looksLikeHtml = preg_match('/<\s*(html|body|p|div|h[1-6]|table)\b/i', $content) === 1;
        $body = $looksLikeHtml ? $content : nl2br(e($content));
        $safeTitle = e($title);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en"><head><meta charset="utf-8"><style>
        @page { margin: 28mm 22mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.7; color: #1a1523; }
        h1 { font-size: 18px; margin: 0 0 18px; }
        </style></head><body><h1>{$safeTitle}</h1>{$body}</body></html>
        HTML;
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
     * Preview a merged template and report which tokens were resolved vs left
     * unresolved, for the live preview step of the Generate wizard.
     *
     * @return array{content: string, resolved: list<string>, unresolved: list<string>}
     */
    public function previewReport(HrDocumentTemplate $template, HrEmployeeProfile $profile, ?HrOffer $offer = null, array $extra = []): array
    {
        $rawContent = $template->content ?? '';

        // Tokens present in the source template.
        preg_match_all('/\{\{\s*[a-zA-Z0-9_.-]+\s*\}\}/', $rawContent, $sourceMatches);
        $sourceTokens = array_values(array_unique($sourceMatches[0]));

        $merged = $this->mergeTemplate($template, $profile, $offer, $extra);

        // Whatever {{...}} survive the merge are unresolved.
        preg_match_all('/\{\{\s*[a-zA-Z0-9_.-]+\s*\}\}/', $rawContent, $afterMatches);
        $mergeData = $this->buildMergeData($profile, $offer, $extra);
        $resolved = [];
        $unresolved = [];
        foreach ($sourceTokens as $token) {
            $normalised = preg_replace('/\s+/', '', $token);
            if (array_key_exists($normalised, $mergeData) && $this->normalizeValue($mergeData[$normalised]) !== '') {
                $resolved[] = $token;
            } else {
                $unresolved[] = $token;
            }
        }

        return [
            'content' => $merged,
            'resolved' => $resolved,
            'unresolved' => $unresolved,
        ];
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
        $fields = self::MERGE_FIELDS;

        $offerCategories = ['offer', 'offer_letter', 'employment_offer'];

        if (! in_array($category, $offerCategories, true)) {
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
        $profile->loadMissing(['user', 'primarySite']);

        $currentDate = now()->format('d F Y');

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
            '{{current_date}}' => $currentDate,
            '{{date}}' => $currentDate,
            '{{current_year}}' => (string) now()->year,
        ];

        if ($offer) {
            $data['{{proposed_start_date}}'] = $offer->proposed_start_date?->format('d F Y');
            $data['{{offer_hours_per_week}}'] = $offer->hours_per_week;
            $data['{{offer_hourly_rate}}'] = $offer->hourly_rate;
            $data['{{offer_annual_salary}}'] = $offer->annual_salary;
        }

        foreach ($extra as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            $placeholder = str_starts_with($normalizedKey, '{{') ? $normalizedKey : '{{' . $normalizedKey . '}}';
            $data[$placeholder] = $this->normalizeValue($value);
        }

        return $data;
    }

    /**
     * @param  mixed  $value
     */
    protected function normalizeValue($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => $this->normalizeValue($item), $value));
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
