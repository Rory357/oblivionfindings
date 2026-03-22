<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCourseEnrollment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CertificateService
{
    /**
     * Generate an HTML certificate that can be saved as PDF.
     *
     * Returns the file path of the saved certificate.
     */
    public function generateCertificate(HrCourseEnrollment $enrollment): string
    {
        $enrollment->loadMissing(['user', 'course']);

        $data = $this->getCertificateData($enrollment);

        $html = view('hr.certificate', $data)->render();

        $filename = sprintf(
            'hr-certificates/%d/certificate_%s_%s.html',
            $enrollment->user_id,
            Str::slug($enrollment->course->title ?? 'course'),
            now()->format('Y-m-d_His')
        );

        Storage::disk('private')->put($filename, $html);

        // Store path on enrollment for future downloads
        $enrollment->update(['certificate_path' => $filename]);

        return $filename;
    }

    /**
     * Returns array of data for the certificate template.
     */
    public function getCertificateData(HrCourseEnrollment $enrollment): array
    {
        $enrollment->loadMissing(['user', 'course']);

        $certificateNumber = strtoupper(
            substr(md5($enrollment->id . '-' . ($enrollment->completed_at ?? now())->timestamp), 0, 12)
        );

        return [
            'employee_name' => $enrollment->user?->name ?? 'Unknown',
            'course_title' => $enrollment->course?->title ?? 'Unknown Course',
            'course_code' => $enrollment->course?->code ?? '',
            'completion_date' => $enrollment->completed_at?->format('d F Y') ?? now()->format('d F Y'),
            'score' => $enrollment->score ? number_format((float) $enrollment->score, 1) . '%' : null,
            'certificate_number' => 'CERT-' . $certificateNumber,
            'company_name' => config('app.name', 'Company'),
        ];
    }
}
