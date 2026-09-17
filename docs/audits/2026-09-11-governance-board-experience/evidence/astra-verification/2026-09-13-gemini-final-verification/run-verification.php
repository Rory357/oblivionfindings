<?php
declare(strict_types=1);

/**
 * Gemini Final Governance Audit Verification Harness
 * Executed: 13 September 2026
 * 
 * Verifies all 16 open finding groups from astra-third-return-review-2026-09-13.md
 * against an isolated disposable database schema.
 */

$root = realpath(__DIR__ . '/../../../../../..');
if (! $root || ! is_file($root . '/phpunit.xml')) {
    throw new RuntimeException('Repository guard failed: unable to locate root phpunit.xml');
}

chdir($root);
require $root . '/vendor/autoload.php';

$xml = simplexml_load_file($root . '/phpunit.xml');
foreach ($xml->php->env as $entry) {
    $k = (string) $entry['name'];
    $v = (string) $entry['value'];
    putenv($k . '=' . $v);
    $_ENV[$k] = $_SERVER[$k] = $v;
}

$disposableDb = 'oblivion_gov_gemini_verify_20260913_' . getmypid();

// Assert strict disposable database isolation before bootstrapping
$overrides = [
    'DB_DATABASE' => $disposableDb,
    'APP_CONFIG_CACHE' => __DIR__ . '/unused-config.php',
    'APP_URL' => 'http://127.0.0.1:8783',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
];
foreach ($overrides as $k => $v) {
    putenv($k . '=' . $v);
    $_ENV[$k] = $_SERVER[$k] = $v;
}

$host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$user = (string) ($_ENV['DB_USERNAME'] ?? 'testUser');
$pass = (string) ($_ENV['DB_PASSWORD'] ?? 'test101');

$pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Cleanup registration
register_shutdown_function(static function () use ($host, $port, $user, $pass, $disposableDb): void {
    try {
        $p = new PDO("mysql:host={$host};port={$port}", $user, $pass);
        $p->exec("DROP DATABASE IF EXISTS `{$disposableDb}`");
    } catch (\Throwable) {}
});

$pdo->exec("DROP DATABASE IF EXISTS `{$disposableDb}`");
$pdo->exec("CREATE DATABASE `{$disposableDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

use App\Domain\Governance\Models as M;
use App\Domain\Governance\Services as S;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;

class GeminiVerificationHost extends Tests\TestCase {
    use Tests\Support\GovernanceTestHelpers;

    public function prepare(): array {
        $this->app = $this->createApplication();
        $this->seedGovernance();
        Mail::fake();
        Notification::fake();
        $this->withoutVite();

        $actors = [];
        foreach ([
            'chair' => ['board_chair', 'chair'],
            'secretary' => ['board_secretary', 'secretary'],
            'member' => ['board_member', 'member'],
            'finance' => ['board_member', 'member'],
            'other' => ['board_member', 'member'],
            'ceo' => ['ceo', null],
            'observer' => ['board_observer', 'observer'],
        ] as $key => [$role, $seat]) {
            $u = $this->createUserWithRole($role, [
                'name' => in_array($key, ['member', 'other'], true) ? 'Review Alex Morgan' : 'Review ' . ucfirst($key),
                'email' => 'gemini-verify-' . $key . '@example.test',
                'password' => \Illuminate\Support\Facades\Hash::make('Gemini-Verify-2026!'),
                'email_verified_at' => now(),
            ]);
            $bm = $seat ? $this->createBoardMember($u, ['board_role' => $seat]) : null;
            $actors[$key] = ['user_id' => $u->id, 'board_member_id' => $bm?->id, 'email' => $u->email, 'role' => $role];
        }

        $chair = App\Models\User::findOrFail($actors['chair']['user_id']);
        $member = App\Models\User::findOrFail($actors['member']['user_id']);

        $meeting = $this->createMeeting($chair, [
            'title' => 'Gemini Verified September Board',
            'scheduled_at' => now()->addDays(4)->setTime(22, 0),
            'chair_id' => $actors['chair']['board_member_id'],
            'secretary_id' => $actors['secretary']['board_member_id'],
        ]);

        $private = $this->createMeeting($chair, [
            'title' => 'GEMINI PRIVATE personnel matter',
            'meeting_type' => 'executive_session',
            'scheduled_at' => now()->addDays(2),
            'chair_id' => $actors['chair']['board_member_id'],
        ]);

        M\MeetingAgendaItem::create([
            'governance_meeting_id' => $meeting->id,
            'order' => 1,
            'title' => 'GEMINI CONFIDENTIAL agenda',
            'item_type' => 'decision',
            'is_confidential' => true,
            'duration_minutes' => 15,
        ]);

        $res = $this->createResolution($chair, [
            'title' => 'Equipment renewal decision',
            'governance_meeting_id' => $meeting->id,
            'deadline' => now()->addDays(3),
            'quorum_required' => true,
        ]);

        $hidden = $this->createResolution($chair, [
            'title' => 'GEMINI PRIVATE resolution',
            'governance_meeting_id' => $private->id,
            'quorum_required' => true,
        ]);

        $action = $this->createActionItem($chair, $member, [
            'source_type' => 'resolution',
            'source_id' => $hidden->id,
            'description' => 'GEMINI PRIVATE assigned action',
            'evidence_required' => true,
        ]);

        for ($i = 0; $i < 40; $i++) {
            $this->createActionItem($chair, $i === 39 ? $member : $chair, [
                'source_type' => 'meeting',
                'source_id' => $meeting->id,
                'description' => 'Ordinary follow-up ' . $i,
                'due_date' => now()->subDays($i + 1),
            ]);
        }

        $this->createActionItem($chair, App\Models\User::find($actors['other']['user_id']), [
            'source_type' => 'meeting',
            'source_id' => $meeting->id,
            'description' => 'SAME NAME other owner',
            'due_date' => now()->subDays(50),
        ]);

        for ($i = 0; $i < 12; $i++) {
            $this->createRisk($chair, [
                'title' => 'Tracked risk ' . $i,
                'likelihood_score' => 5,
                'impact_score' => 5,
                'control_effectiveness' => 'weak',
            ]);
        }

        $committee = M\BoardCommittee::create([
            'name' => 'Finance Committee',
            'committee_type' => 'finance',
            'chair_id' => $actors['finance']['board_member_id'],
            'is_active' => true,
        ]);
        $committee->members()->attach($actors['finance']['board_member_id'], [
            'role' => 'chair',
            'appointed_at' => '2026-01-01',
            'term_end' => '2027-01-01',
            'is_active' => true,
        ]);

        $review = M\PerformanceReview::create([
            'reviewee_id' => $actors['ceo']['user_id'],
            'review_cycle' => 'Annual 2026',
            'review_type' => 'annual',
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'status' => 'board_review',
            'overall_assessment' => 'GEMINI PRIVATE raw appraisal',
            'created_by' => $chair->id,
        ]);

        $minute = M\MeetingMinute::create([
            'governance_meeting_id' => $meeting->id,
            'content_blocks' => [['heading' => 'Record', 'content' => 'Approved synthetic minutes']],
            'status' => 'approved',
            'drafted_by' => $chair->id,
            'drafted_at' => now(),
            'version_number' => 1,
        ]);

        $obligation = $this->createComplianceObligation($chair, [
            'obligation_title' => 'Annual assurance review',
            'due_date' => now()->subDay(),
            'evidence_required' => false,
        ]);

        $normalAction = $this->createActionItem($chair, $member, [
            'description' => 'Evidence-required normal action',
            'evidence_required' => true,
        ]);

        return [
            'database' => config('database.connections.mysql.database'),
            'pid' => getmypid(),
            'actors' => $actors,
            'meeting_id' => $meeting->id,
            'private_meeting_id' => $private->id,
            'resolution_id' => $res->id,
            'private_resolution_id' => $hidden->id,
            'private_action_id' => $action->id,
            'normal_action_id' => $normalAction->id,
            'review_id' => $review->id,
            'minute_id' => $minute->id,
            'obligation_id' => $obligation->id,
            'committee_id' => $committee->id,
        ];
    }

    public function paper(App\Models\User $u, array $attributes = []): M\Resolution {
        return $this->createResolution($u, $attributes);
    }

    public function budget(App\Models\User $u, array $attributes = []): M\Budget {
        return $this->createBudget($u, $attributes);
    }
}

$hostObj = new GeminiVerificationHost('prepare');
$state = $hostObj->prepare();

$actors = [];
foreach ($state['actors'] as $k => $a) {
    $actors[$k] = App\Models\User::findOrFail($a['user_id']);
}

file_put_contents(__DIR__ . '/runtime-state.json', json_encode($state, JSON_PRETTY_PRINT));

echo "State initialized in {$state['database']}\n";
