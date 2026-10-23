<?php

use App\Support\LegacyStorageContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three fictional open roles for the "Oblivion Living" client demo website
 * (mockup v2, approved by Stephan 23 Oct 2026), so its careers page, job pages
 * and apply wizard have something to show.
 *
 * Runs only when config('demo.site') is true (DEMO_SITE in .env). Even then it
 * adds jobs only when the organisation has no open job postings, never edits
 * or removes an existing posting, and skips a role whose slug is already
 * taken, so running it again changes nothing.
 *
 * Each role is placed at a Site so applications can be made (every
 * application needs a target Site): the first active Site named after the
 * role's place (or in that town), otherwise the first active Site in the
 * Wellington region, otherwise the first active Site. When the Site isn't
 * named after the place, the place goes in the job title so the public page
 * still says where the work is. No Site is created. With no active Site at
 * all, no jobs are added.
 *
 * The roles stay open until filled (no closing date), so the demo doesn't
 * expire. Pay shows on the ad.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('demo.site')) {
            return;
        }

        if (! Schema::hasTable('hr_job_requisitions') || ! Schema::hasTable('sites')) {
            return;
        }

        $openPostings = DB::table('hr_job_requisitions')
            ->where('status', 'published')
            ->where(function ($query) {
                $query->whereNull('closing_at')->orWhereDate('closing_at', '>=', now()->toDateString());
            })
            ->exists();
        if ($openPostings) {
            return;
        }

        $now = now();

        foreach ($this->roles() as $role) {
            if (DB::table('hr_job_requisitions')->where('slug', $role['slug'])->exists()) {
                continue;
            }

            $site = $this->siteFor($role['places']);
            if ($site === null) {
                return;
            }

            DB::table('hr_job_requisitions')->insert([
                'tenant_id' => LegacyStorageContext::id(),
                'title' => $site['named_for_place'] ? $role['title'] : "{$role['title']}, {$role['place']}",
                'slug' => $role['slug'],
                'position_role' => $role['position_role'],
                'site_id' => $site['id'],
                'employment_type' => $role['employment_type'],
                'salary_range_min' => $role['pay'][0],
                'salary_range_max' => $role['pay'][1],
                'show_salary' => true,
                'screening_questions' => json_encode($role['screening_questions']),
                'requires_approval' => false,
                'openings' => $role['openings'],
                'status' => 'published',
                'summary' => $role['summary'],
                'description' => $role['description'],
                'requirements' => implode("\n", $role['requirements']),
                'responsibilities' => implode("\n", $role['responsibilities']),
                'posting_channels' => json_encode(['career_page']),
                'external_posting_status' => 'not_posted',
                'published_at' => $now,
                'closing_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Demo content is left in place: a client may have edited these roles. */
    public function down(): void {}

    /**
     * @param  list<string>  $places
     * @return array{id: int, named_for_place: bool}|null
     */
    private function siteFor(array $places): ?array
    {
        $active = fn () => DB::table('sites')
            ->where('is_active', true)
            ->when(Schema::hasColumn('sites', 'archived'), fn ($query) => $query->where('archived', false))
            ->when(Schema::hasColumn('sites', 'archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when(Schema::hasColumn('sites', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'));

        foreach ($places as $place) {
            $named = $active()->where('name', 'like', "%{$place}%")->orderBy('id')->value('id');
            if ($named) {
                return ['id' => (int) $named, 'named_for_place' => true];
            }
        }

        foreach ($places as $place) {
            $inTown = $active()
                ->where(fn ($query) => $query->where('city', 'like', "%{$place}%")->orWhere('suburb', 'like', "%{$place}%"))
                ->orderBy('id')
                ->value('id');
            if ($inTown) {
                return ['id' => (int) $inTown, 'named_for_place' => false];
            }
        }

        $fallback = $active()->where('region', 'like', '%Wellington%')->orderBy('id')->value('id')
            ?? $active()->orderBy('id')->value('id');

        return $fallback ? ['id' => (int) $fallback, 'named_for_place' => false] : null;
    }

    /** @return list<array<string, mixed>> */
    private function roles(): array
    {
        return [
            [
                'slug' => 'support-worker-kapiti-coast',
                'title' => 'Support Worker',
                'place' => 'Kāpiti Coast',
                'places' => ['Kāpiti', 'Kapiti', 'Paraparaumu'],
                'position_role' => 'support_worker',
                'employment_type' => 'part_time',
                'openings' => 2,
                'pay' => [28.50, 31.00],
                'summary' => 'Support three adults in their shared home in Paraparaumu to live the lives they choose.',
                'description' => "You’ll join a small, steady team supporting three adults who share a home in Paraparaumu. Every day is different: cooking together, getting to work and appointments, a swim at the aquatic centre or a quiet night in.\n\nThe role is 20 to 30 hours a week, including some weekends. No experience? That’s fine. We’re looking for kindness, patience and good sense, and we’ll train you.",
                'responsibilities' => [
                    'Support people with everyday living, personal care and medicines, the way they prefer',
                    'Help people get out and about, and keep in touch with friends and whānau',
                    'Write clear daily notes and handovers in our app',
                    'Notice when something isn’t right and speak up',
                ],
                'requirements' => [
                    'A full or restricted New Zealand driver licence',
                    'The right to work in New Zealand',
                    'Kindness, reliability and a sense of humour',
                    'Experience is welcome but not needed. Paid training includes the Level 2 Health and Wellbeing certificate',
                ],
                'screening_questions' => [
                    'Which days and times can you usually work?',
                    'Tell us about a time you helped someone do something for themselves.',
                ],
            ],
            [
                'slug' => 'team-leader-porirua',
                'title' => 'Team Leader',
                'place' => 'Porirua',
                'places' => ['Porirua'],
                // The app's role key (RbacSeeder), so a hire maps to a real role.
                'position_role' => 'team_lead',
                'employment_type' => 'full_time',
                'openings' => 1,
                'pay' => [68000, 76000],
                'summary' => 'Lead a team of 12 support workers across two homes in Porirua.',
                'description' => "You’ll lead a team of 12 support workers across two homes in Porirua, making sure the people we support get consistent, respectful support. You’ll coach your team, build rosters, keep support plans up to date with each person and their whānau, and be a calm voice when things get busy.\n\nThe role is 40 hours a week, Monday to Friday, with a share of the on-call roster.",
                'responsibilities' => [
                    'Lead, coach and roster a team of 12 support workers',
                    'Review support plans with each person and their whānau',
                    'Check notes, medicines records and incident reports, and follow up',
                    'Share the on-call roster with the other team leaders',
                ],
                'requirements' => [
                    'At least two years in disability or community support',
                    'Experience leading or coaching a team',
                    'A Level 4 Health and Wellbeing certificate, or willingness to work towards it',
                    'A full New Zealand driver licence',
                ],
                'screening_questions' => [
                    'Tell us about a time you helped a team through a difficult change.',
                ],
            ],
            [
                'slug' => 'night-support-lower-hutt',
                'title' => 'Night Support',
                'place' => 'Lower Hutt',
                'places' => ['Lower Hutt', 'Hutt'],
                'position_role' => 'support_worker',
                'employment_type' => 'part_time',
                'openings' => 1,
                'pay' => [29.50, 32.00],
                'summary' => 'Keep a home in Lower Hutt calm, safe and settled overnight.',
                'description' => "You’ll be the awake night support for four adults who share a home in Lower Hutt. Nights are mostly quiet: checks, a hand back to sleep, medicines when they’re needed and a careful handover in the morning. You’ll work alongside our on-call team leader, so you’re never on your own with a problem.\n\nThe role is three awake nights a week, 10pm to 7am.",
                'responsibilities' => [
                    'Do overnight checks the way each person has agreed',
                    'Respond calmly when someone wakes or needs help',
                    'Give overnight medicines when they’re needed, once you’re trained',
                    'Write a clear handover for the morning team',
                ],
                'requirements' => [
                    'Comfortable working awake nights',
                    'The right to work in New Zealand',
                    'A calm, steady approach',
                    'Experience is welcome but not needed. We’ll train you',
                ],
                'screening_questions' => [
                    'Have you worked night shifts before?',
                ],
            ],
        ];
    }
};
