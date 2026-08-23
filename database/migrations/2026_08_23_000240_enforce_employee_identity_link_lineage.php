<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $blockers = [
            'duplicate offer links' => $this->duplicateGroupCount('offer_id'),
            'duplicate candidate links' => $this->duplicateGroupCount('candidate_id'),
            'offer links missing candidate lineage' => DB::table('hr_employee_profiles')
                ->whereNotNull('offer_id')
                ->whereNull('candidate_id')
                ->count(),
            'missing offers' => DB::table('hr_employee_profiles as profiles')
                ->leftJoin('hr_offers as offers', 'offers.id', '=', 'profiles.offer_id')
                ->whereNotNull('profiles.offer_id')
                ->whereNull('offers.id')
                ->count(),
            'missing candidates' => DB::table('hr_employee_profiles as profiles')
                ->leftJoin('hr_candidates as candidates', 'candidates.id', '=', 'profiles.candidate_id')
                ->whereNotNull('profiles.candidate_id')
                ->whereNull('candidates.id')
                ->count(),
            'offer/candidate mismatches' => DB::table('hr_employee_profiles as profiles')
                ->join('hr_offers as offers', 'offers.id', '=', 'profiles.offer_id')
                ->join('hr_applications as applications', 'applications.id', '=', 'offers.application_id')
                ->whereNotNull('profiles.candidate_id')
                ->whereColumn('applications.candidate_id', '!=', 'profiles.candidate_id')
                ->count(),
        ];

        $blocking = collect($blockers)->filter(fn (int $count): bool => $count > 0);
        if ($blocking->isNotEmpty()) {
            throw new RuntimeException('Cannot enforce employee identity-link lineage: '.$blocking
                ->map(fn (int $count, string $category): string => "{$category}={$count}")
                ->implode(', ').'.');
        }

        Schema::table('hr_employee_profiles', function (Blueprint $table): void {
            $table->unique('offer_id', 'hr_employee_profiles_offer_uq');
            $table->unique('candidate_id', 'hr_employee_profiles_candidate_uq');
            $table->foreign('offer_id', 'hr_employee_profiles_offer_fk')
                ->references('id')
                ->on('hr_offers')
                ->restrictOnDelete();
            $table->foreign('candidate_id', 'hr_employee_profiles_candidate_fk')
                ->references('id')
                ->on('hr_candidates')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table): void {
            $table->dropForeign('hr_employee_profiles_offer_fk');
            $table->dropForeign('hr_employee_profiles_candidate_fk');
            $table->dropUnique('hr_employee_profiles_offer_uq');
            $table->dropUnique('hr_employee_profiles_candidate_uq');
        });
    }

    private function duplicateGroupCount(string $column): int
    {
        $duplicateGroups = DB::table('hr_employee_profiles')
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1');

        return (int) DB::query()
            ->fromSub($duplicateGroups, 'duplicate_employee_identity_links')
            ->count();
    }
};
