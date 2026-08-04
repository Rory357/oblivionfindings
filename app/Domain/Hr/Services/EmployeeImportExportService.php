<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeeImportExportService
{
    use SanitizesCsvOutput;

    /**
     * CSV headers used for export and import.
     */
    private const HEADERS = [
        'employee_number',
        'name',
        'email',
        'position_title',
        'position_role',
        'department',
        'primary_site_id',
        'employment_type',
        'start_date',
        'hours_per_week',
        'is_active',
    ];

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /**
     * Export employees to CSV. With no $userIds, exports all active employees;
     * with $userIds (the People table's multi-select), exports exactly those
     * people regardless of active state — "export selected".
     *
     * @param  array<int, int>|null  $userIds
     */
    public function exportToCsv(User $viewer, ?array $userIds = null): string
    {
        $query = HrEmployeeProfile::query()->with('user');
        $profiles = ($userIds === null
            ? $this->siteAccess->applyCurrentStaffProfileScope($query, $viewer)
            : $this->siteAccess->applyHistoricalStaffProfileScope($query, $viewer)
                ->whereIn('user_id', collect($userIds)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()))
            ->orderBy('employee_number')
            ->get();

        $output = fopen('php://temp', 'r+');
        $this->putCsv($output, self::HEADERS);

        foreach ($profiles as $profile) {
            $this->putCsv($output, [
                $profile->employee_number ?? '',
                $profile->user?->name ?? '',
                $profile->user?->email ?? '',
                $profile->position_title ?? '',
                $profile->position_role ?? '',
                $profile->department ?? '',
                $profile->primary_site_id ?? '',
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
    public function importFromCsv(string $csvContent, User $actor): array
    {
        $this->siteAccess
            ->applyStaffScope(User::query(), $actor)
            ->findOrFail($actor->getKey());

        $rows = $this->parseCsv($csvContent);
        if (count($rows) < 2) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['CSV file is empty or has no data rows.']];
        }
        if (count($rows) > 5001) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['CSV files are limited to 5,000 data rows per import.']];
        }

        $headers = array_map(
            fn (mixed $header): string => strtolower(trim((string) $header, " \t\n\r\0\x0B\xEF\xBB\xBF")),
            array_shift($rows),
        );
        if (count($headers) !== count(array_unique($headers))) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['CSV headers must be unique.']];
        }

        $requiredHeaders = ['name', 'email', 'position_role', 'primary_site_id'];
        foreach ($requiredHeaders as $required) {
            if (! in_array($required, $headers, true)) {
                return ['created' => 0, 'updated' => 0, 'errors' => ["Missing required header: {$required}"]];
            }
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $lineNum => $row) {
            if (count($row) === 1 && blank($row[0] ?? null)) {
                continue;
            }

            $data = [];
            foreach ($headers as $i => $header) {
                $value = trim((string) ($row[$i] ?? ''));
                $data[$header] = $value === '' ? null : $value;
            }

            $rowNumber = $lineNum + 2; // +1 for header, +1 for 1-based

            $validator = Validator::make($data, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'employee_number' => 'nullable|string|max:100',
                'position_title' => 'nullable|string|max:255',
                'position_role' => 'required|string|max:100',
                'department' => 'nullable|string|max:255',
                'primary_site_id' => 'required|integer|min:1',
                'employment_type' => 'nullable|string|in:full_time,part_time,casual,contractor,volunteer',
                'start_date' => 'nullable|date',
                'hours_per_week' => 'nullable|numeric|min:0|max:168',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                $errors[] = "Row {$rowNumber}: ".implode(', ', $validator->errors()->all());

                continue;
            }

            try {
                $validated = $validator->validated();
                DB::transaction(function () use ($validated, $actor, &$created, &$updated): void {
                    $site = $this->siteAccess
                        ->applySiteScope(
                            Site::query()->active()->notArchived()->whereNull('archived_at'),
                            $actor,
                        )
                        ->lockForUpdate()
                        ->findOrFail((int) $validated['primary_site_id']);

                    $email = strtolower(trim((string) $validated['email']));
                    $user = User::query()
                        ->whereRaw('LOWER(email) = ?', [$email])
                        ->lockForUpdate()
                        ->first();

                    if ($user && ! User::query()->staff()->whereKey($user->getKey())->exists()) {
                        throw ValidationException::withMessages([
                            'email' => 'That email belongs to a non-staff account and cannot be imported.',
                        ]);
                    }

                    if (! $user) {
                        $user = User::create([
                            'name' => $validated['name'],
                            'email' => $email,
                            'password' => bcrypt(str()->random(32)),
                            'role' => 'staff',
                        ]);
                    }

                    $profile = HrEmployeeProfile::withTrashed()
                        ->where('user_id', $user->id)
                        ->lockForUpdate()
                        ->first();
                    if ($profile?->trashed()) {
                        throw ValidationException::withMessages([
                            'email' => 'An archived employee profile must be restored from People before import.',
                        ]);
                    }
                    if ($profile && ! $this->siteAccess
                        ->applyHistoricalStaffProfileScope(HrEmployeeProfile::query(), $actor)
                        ->whereKey($profile->getKey())
                        ->exists()
                    ) {
                        throw (new ModelNotFoundException)->setModel(HrEmployeeProfile::class);
                    }

                    $profileData = array_filter([
                        'user_id' => $user->id,
                        'employee_number' => $validated['employee_number'] ?? null,
                        'work_email' => $email,
                        'position_title' => $validated['position_title'] ?? null,
                        'position_role' => $validated['position_role'],
                        'department' => $validated['department'] ?? null,
                        'primary_site_id' => $site->getKey(),
                        'employment_type' => $validated['employment_type'] ?? null,
                        'start_date' => $validated['start_date'] ?? null,
                        'hours_per_week' => $validated['hours_per_week'] ?? null,
                    ], fn (mixed $value): bool => $value !== null);
                    if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
                        $profileData['is_active'] = filter_var($validated['is_active'], FILTER_VALIDATE_BOOL);
                    }

                    if ($profile) {
                        $profile->update([...$profileData, 'updated_by' => $actor->id]);
                        $updated++;
                    } else {
                        HrEmployeeProfile::create([
                            ...$profileData,
                            'is_active' => $profileData['is_active'] ?? true,
                            'created_by' => $actor->id,
                        ]);
                        $created++;
                    }

                    if ($user->name !== $validated['name']) {
                        $user->update(['name' => $validated['name']]);
                    }
                }, attempts: 1);
            } catch (ValidationException $exception) {
                $errors[] = "Row {$rowNumber}: ".($exception->validator->errors()->first() ?: 'The row is invalid.');
            } catch (ModelNotFoundException) {
                $errors[] = "Row {$rowNumber}: The selected Site or employee record is not available.";
            } catch (\Throwable $e) {
                Log::warning('Employee import row failed', ['row' => $rowNumber, 'error' => $e->getMessage()]);
                $errors[] = "Row {$rowNumber}: The employee could not be imported safely.";
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
        $this->putCsv($output, self::HEADERS);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /** @return array<int, array<int, string|null>> */
    private function parseCsv(string $csvContent): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }

        fwrite($stream, $csvContent);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
