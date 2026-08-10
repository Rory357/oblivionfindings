<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COURSE_CODE_KEY = 'application_code_key';

    private const COURSE_CODE_UNIQUE = 'hr_courses_code_key_uq';

    private const COURSE_READ_INDEX = 'hr_courses_active_category_title_idx';

    private const SESSION_READ_INDEX = 'hr_course_sessions_status_date_idx';

    private const ENROLLMENT_USER_INDEX = 'hr_course_enrollments_user_status_completed_idx';

    private const ENROLLMENT_COURSE_INDEX = 'hr_course_enrollments_course_status_completed_idx';

    private const ASSIGNMENT_IDENTITY = 'hr_course_assignments_user_course_uq';

    private const ASSIGNMENT_USER_INDEX = 'hr_course_assignments_user_status_due_idx';

    private const ASSIGNMENT_COURSE_INDEX = 'hr_course_assignments_course_status_due_idx';

    private const DRIVER_READ_INDEX = 'hr_driver_eligibility_status_expiry_idx';

    public function up(): void
    {
        $this->assertApplicationIdentitiesCanBeEnforced();
        $this->addCourseIdentity();

        $this->addIndex(
            'hr_courses',
            self::COURSE_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['is_active', 'category', 'title'],
                self::COURSE_READ_INDEX,
            ),
        );
        $this->addIndex(
            'hr_course_sessions',
            self::SESSION_READ_INDEX,
            fn (Blueprint $table) => $table->index(['status', 'session_date'], self::SESSION_READ_INDEX),
        );
        $this->addIndex(
            'hr_course_enrollments',
            self::ENROLLMENT_USER_INDEX,
            fn (Blueprint $table) => $table->index(
                ['user_id', 'status', 'completed_at'],
                self::ENROLLMENT_USER_INDEX,
            ),
        );
        $this->addIndex(
            'hr_course_enrollments',
            self::ENROLLMENT_COURSE_INDEX,
            fn (Blueprint $table) => $table->index(
                ['course_id', 'status', 'completed_at'],
                self::ENROLLMENT_COURSE_INDEX,
            ),
        );
        $this->addIndex(
            'hr_course_assignments',
            self::ASSIGNMENT_IDENTITY,
            fn (Blueprint $table) => $table->unique(
                ['user_id', 'hr_course_id'],
                self::ASSIGNMENT_IDENTITY,
            ),
        );
        $this->addIndex(
            'hr_course_assignments',
            self::ASSIGNMENT_USER_INDEX,
            fn (Blueprint $table) => $table->index(
                ['user_id', 'status', 'due_at'],
                self::ASSIGNMENT_USER_INDEX,
            ),
        );
        $this->addIndex(
            'hr_course_assignments',
            self::ASSIGNMENT_COURSE_INDEX,
            fn (Blueprint $table) => $table->index(
                ['hr_course_id', 'status', 'due_at'],
                self::ASSIGNMENT_COURSE_INDEX,
            ),
        );
        $this->addIndex(
            'hr_driver_eligibility',
            self::DRIVER_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['status', 'licence_expires_at'],
                self::DRIVER_READ_INDEX,
            ),
        );

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as $name => [, $unique]) {
                $this->dropIndex($table, $name, $unique);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as $name => [$columns, $unique]) {
                $this->addIndex(
                    $table,
                    $name,
                    $unique
                        ? fn (Blueprint $blueprint) => $blueprint->unique($columns, $name)
                        : fn (Blueprint $blueprint) => $blueprint->index($columns, $name),
                );
            }
        }

        $this->dropIndex('hr_driver_eligibility', self::DRIVER_READ_INDEX);
        $this->dropIndex('hr_course_assignments', self::ASSIGNMENT_COURSE_INDEX);
        $this->dropIndex('hr_course_assignments', self::ASSIGNMENT_USER_INDEX);
        $this->dropIndex('hr_course_assignments', self::ASSIGNMENT_IDENTITY, unique: true);
        $this->dropIndex('hr_course_enrollments', self::ENROLLMENT_COURSE_INDEX);
        $this->dropIndex('hr_course_enrollments', self::ENROLLMENT_USER_INDEX);
        $this->dropIndex('hr_course_sessions', self::SESSION_READ_INDEX);
        $this->dropIndex('hr_courses', self::COURSE_READ_INDEX);
        $this->dropIndex('hr_courses', self::COURSE_CODE_UNIQUE, unique: true);

        if (Schema::hasTable('hr_courses') && Schema::hasColumn('hr_courses', self::COURSE_CODE_KEY)) {
            Schema::table('hr_courses', fn (Blueprint $table) => $table->dropColumn(self::COURSE_CODE_KEY));
        }
    }

    private function assertApplicationIdentitiesCanBeEnforced(): void
    {
        if (Schema::hasTable('hr_courses')) {
            if (DB::table('hr_courses')->whereRaw("TRIM(COALESCE(code, '')) = ''")->exists()) {
                throw new RuntimeException('Cannot enforce application course code identity while blank codes exist.');
            }

            $duplicateCourse = DB::table('hr_courses')
                ->selectRaw('LOWER(TRIM(code)) AS canonical_code, COUNT(*) AS duplicate_count')
                ->groupByRaw('LOWER(TRIM(code))')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($duplicateCourse !== null) {
                throw new RuntimeException('Cannot enforce application course code identity while duplicate codes exist.');
            }
        }

        if (Schema::hasTable('hr_course_assignments')) {
            $duplicateAssignment = DB::table('hr_course_assignments')
                ->selectRaw('user_id, hr_course_id, COUNT(*) AS duplicate_count')
                ->groupBy('user_id', 'hr_course_id')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($duplicateAssignment !== null) {
                throw new RuntimeException('Cannot enforce application training assignment identity while duplicate rows exist.');
            }
        }
    }

    private function addCourseIdentity(): void
    {
        if (! Schema::hasTable('hr_courses')) {
            return;
        }

        if (! Schema::hasColumn('hr_courses', self::COURSE_CODE_KEY)) {
            $expression = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? 'lower(trim(`code`))'
                : 'lower(trim(code))';
            Schema::table('hr_courses', function (Blueprint $table) use ($expression): void {
                $table->string(self::COURSE_CODE_KEY, 50)->nullable()->virtualAs($expression);
            });
        }

        $this->addIndex(
            'hr_courses',
            self::COURSE_CODE_UNIQUE,
            fn (Blueprint $table) => $table->unique(self::COURSE_CODE_KEY, self::COURSE_CODE_UNIQUE),
        );
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $unique): void {
            $unique ? $table->dropUnique($name) : $table->dropIndex($name);
        });
    }

    /** @return array<string, array<string, array{list<string>, bool}>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_courses' => [
                'hr_courses_tenant_id_code_unique' => [['tenant_id', 'code'], true],
                'hr_courses_tenant_id_category_index' => [['tenant_id', 'category'], false],
                'hr_courses_tenant_id_index' => [['tenant_id'], false],
            ],
            'hr_course_sessions' => [
                'hr_course_sessions_tenant_id_session_date_index' => [['tenant_id', 'session_date'], false],
                'hr_course_sessions_tenant_id_index' => [['tenant_id'], false],
            ],
            'hr_course_enrollments' => [
                'hr_course_enrollments_tenant_id_user_id_index' => [['tenant_id', 'user_id'], false],
                'hr_course_enrollments_tenant_id_course_id_index' => [['tenant_id', 'course_id'], false],
                'hr_course_enrollments_tenant_id_index' => [['tenant_id'], false],
            ],
            'hr_course_assignments' => [
                'hr_course_assign_unique' => [['tenant_id', 'user_id', 'hr_course_id'], true],
                'hr_course_assignments_tenant_id_status_index' => [['tenant_id', 'status'], false],
                'hr_course_assignments_tenant_id_hr_course_id_index' => [['tenant_id', 'hr_course_id'], false],
                'hr_course_assignments_tenant_id_user_id_index' => [['tenant_id', 'user_id'], false],
                'hr_course_assignments_tenant_id_index' => [['tenant_id'], false],
            ],
            'hr_driver_eligibility' => [
                'hr_driver_eligibility_tenant_id_status_index' => [['tenant_id', 'status'], false],
                'hr_driver_eligibility_tenant_id_index' => [['tenant_id'], false],
            ],
        ];
    }
};
