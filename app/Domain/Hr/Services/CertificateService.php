<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Models\StaffTrainingRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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

        $certificateNumber = $this->certificateNumber($enrollment);
        $data = $this->getCertificateData($enrollment, $certificateNumber);

        $html = view('hr.certificate', $data)->render();

        $filename = sprintf(
            'hr/training/certificates/%d/certificate_%s_%s.html',
            $enrollment->id,
            Str::slug($enrollment->course->title ?? 'course'),
            now()->format('Y-m-d_His')
        );

        $written = false;
        $committed = false;
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(function () use (&$written, $filename): void {
                if ($written) {
                    $this->deleteWithoutThrowing($filename);
                }
            });
        }

        try {
            $written = Storage::disk('private')->put($filename, $html);
            if (! $written) {
                throw new RuntimeException('The generated certificate could not be stored.');
            }

            DB::transaction(function () use (
                $enrollment,
                $certificateNumber,
                $filename,
                &$committed,
            ): void {
                $enrollment->update([
                    'certificate_number' => $certificateNumber,
                    'certificate_path' => $filename,
                ]);
                StaffTrainingRecord::query()
                    ->where('user_id', $enrollment->user_id)
                    ->where('hr_course_id', $enrollment->course_id)
                    ->update([
                        'certificate_number' => $certificateNumber,
                        'certificate_path' => $filename,
                    ]);
                DB::afterCommit(function () use (&$committed): void {
                    $committed = true;
                });
            }, 1);
            $committed = true;
        } catch (Throwable $exception) {
            if (! $committed && $written) {
                $this->deleteWithoutThrowing($filename);
            }

            throw $exception;
        }

        return $filename;
    }

    /**
     * Returns array of data for the certificate template.
     */
    public function getCertificateData(HrCourseEnrollment $enrollment, ?string $certificateNumber = null): array
    {
        $enrollment->loadMissing(['user', 'course']);
        $certificateNumber ??= $this->certificateNumber($enrollment);

        return [
            'employee_name' => $enrollment->user?->name ?? 'Unknown',
            'course_title' => $enrollment->course?->title ?? 'Unknown Course',
            'course_code' => $enrollment->course?->code ?? '',
            'completion_date' => $enrollment->completed_at?->format('d F Y') ?? now()->format('d F Y'),
            'score' => $enrollment->score ? number_format((float) $enrollment->score, 1).'%' : null,
            'certificate_number' => $certificateNumber,
            'company_name' => config('app.name', 'Company'),
        ];
    }

    private function certificateNumber(HrCourseEnrollment $enrollment): string
    {
        if (filled($enrollment->certificate_number)) {
            return (string) $enrollment->certificate_number;
        }

        return 'CERT-'.strtoupper(substr(
            md5($enrollment->id.'-'.($enrollment->completed_at ?? now())->timestamp),
            0,
            12,
        ));
    }

    private function deleteWithoutThrowing(string $path): void
    {
        try {
            Storage::disk('private')->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
