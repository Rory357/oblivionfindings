<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EmployeeImportExportService
{
    /**
     * CSV headers used for export and import.
     */
    private const HEADERS = [
        'employee_number',
        'name',
        'email',
        'position_title',
        'department',
        'employment_type',
        'start_date',
        'hours_per_week',
        'is_active',
    ];

    /**
     * Export all active employees to CSV string.
     */
    /**
     * Export employees to CSV. With no $userIds, exports all active employees;
     * with $userIds (the People table's multi-select), exports exactly those
     * people regardless of active state — "export selected".
     *
     * @param  array<int, int>|null  $userIds
     */
    public function exportToCsv(?int $tenantId, ?array $userIds = null): string
    {
        $profiles = HrEmployeeProfile::with('user')
            ->where('tenant_id', $tenantId)
            ->when($userIds === null, fn ($q) => $q->where('is_active', true))
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->orderBy('employee_number')
            ->get();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::HEADERS);

        foreach ($profiles as $profile) {
            fputcsv($output, [
                $profile->employee_number ?? '',
                $profile->user?->name ?? '',
                $profile->user?->email ?? '',
                $profile->position_title ?? '',
                $profile->department ?? '',
                $profile->employment_type ?? '',
                $profile->start_date?->format('Y-m-d') ?? '',
                $profile->hours_per_week ?? '',
                $profile->is_active ? '1' : '0',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Import employees from CSV content.
     *
     * @return array{created: int, updated: int, errors: array}
     */
    public function importFromCsv(string $csvContent, ?int $tenantId, int $createdBy): array
    {
        $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $csvContent)));

        if (count($lines) < 2) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['CSV file is empty or has no data rows.']];
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map(fn ($h) => strtolower(trim($h)), $headers);

        // Validate headers
        $requiredHeaders = ['name', 'email'];
        foreach ($requiredHeaders as $required) {
            if (!in_array($required, $headers)) {
                return ['created' => 0, 'updated' => 0, 'errors' => ["Missing required header: {$required}"]];
            }
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line);
            $data = [];
            foreach ($headers as $i => $header) {
                $data[$header] = $row[$i] ?? null;
            }

            $rowNumber = $lineNum + 2; // +1 for header, +1 for 1-based

            // Validate row
            $validator = Validator::make($data, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'position_title' => 'nullable|string|max:255',
                'department' => 'nullable|string|max:255',
                'employment_type' => 'nullable|string|in:full_time,part_time,casual,contractor,volunteer',
                'start_date' => 'nullable|date',
                'hours_per_week' => 'nullable|numeric|min:0|max:168',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNumber}: " . implode(', ', $validator->errors()->all());
                continue;
            }

            try {
                DB::transaction(function () use ($data, $tenantId, $createdBy, &$created, &$updated) {
                    // Find or create user by email
                    $user = User::where('email', $data['email'])->first();

                    if (!$user) {
                        $user = User::create([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'password' => bcrypt(str()->random(32)),
                        ]);
                    }

                    // Find or create employee profile
                    $profile = HrEmployeeProfile::where('tenant_id', $tenantId)
                        ->where('user_id', $user->id)
                        ->first();

                    $profileData = array_filter([
                        'tenant_id' => $tenantId,
                        'user_id' => $user->id,
                        'employee_number' => $data['employee_number'] ?? null,
                        'position_title' => $data['position_title'] ?? null,
                        'department' => $data['department'] ?? null,
                        'employment_type' => $data['employment_type'] ?? null,
                        'start_date' => $data['start_date'] ?? null,
                        'hours_per_week' => $data['hours_per_week'] ?? null,
                        'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                    ], fn ($v) => $v !== null);

                    if ($profile) {
                        $profile->update(array_merge($profileData, ['updated_by' => $createdBy]));
                        $updated++;
                    } else {
                        HrEmployeeProfile::create(array_merge($profileData, ['created_by' => $createdBy]));
                        $created++;
                    }

                    // Update user name if it changed
                    if ($user->name !== $data['name']) {
                        $user->update(['name' => $data['name']]);
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('Employee import row failed', ['row' => $rowNumber, 'error' => $e->getMessage()]);
                $errors[] = "Row {$rowNumber}: {$e->getMessage()}";
            }
        }

        return compact('created', 'updated', 'errors');
    }

    /**
     * Generate a blank CSV template with headers only.
     */
    public function generateTemplate(): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, self::HEADERS);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
