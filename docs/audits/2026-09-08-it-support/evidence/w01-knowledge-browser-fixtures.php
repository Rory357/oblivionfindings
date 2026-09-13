<?php

/**
 * Local-only additive KB browser fixtures. Default mode reports read-only
 * guards/counts; --create-fixtures is required after root review. Existing
 * articles, identities and permissions are never updated or repaired.
 */
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItKbArticle;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

const W01_KB_PREFIX = 'IT Support W01 KB 20260909';
const W01_KB_SLUG_PREFIX = 'it-support-w01-kb-20260909-';

function w01KbRequire(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException($reason);
    }
}

function w01KbCounts(): array
{
    $counts = [];
    foreach (['users', 'roles', 'sites', 'it_tickets', 'it_kb_articles', 'it_kb_interactions', 'it_email_deliveries'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

function w01KbExistingDigest(array $fixtureIds): string
{
    // Existing content is hashed in memory only; neither bodies nor secrets
    // from any existing record are written to output/evidence.
    return hash('sha256', json_encode(
        DB::table('it_kb_articles')->whereNotIn('id', $fixtureIds)->orderBy('id')->get()->all(),
        JSON_THROW_ON_ERROR,
    ));
}

$locked = false;
try {
    $expectedRoot = 'C:/Users/steph/Herd/oblivionfindings';
    $connection = config('database.connections.mysql');
    w01KbRequire(
        app()->environment('local')
        && strcasecmp(str_replace('\\', '/', realpath(base_path())), $expectedRoot) === 0
        && parse_url(config('app.url'), PHP_URL_HOST) === 'oblivionfindings.test'
        && config('database.default') === 'mysql'
        && $connection['host'] === '127.0.0.1'
        && (string) $connection['port'] === '3306'
        && $connection['database'] === 'oblivion_findings_codex_test'
        && empty($connection['url'])
        && ! app()->configurationIsCached()
        && config('mail.default') === 'array'
        && config('mail.mailers.array.transport') === 'array'
        && config('queue.default') === 'sync'
        && in_array(config('broadcasting.default'), [null, 'null'], true)
        && config('filesystems.disks.private.driver') === 'local'
        && strcasecmp(str_replace('\\', '/', config('filesystems.disks.private.root')), $expectedRoot.'/storage/app/private') === 0,
        'Local checkout/database/notification/private-storage guard failed.',
    );
    Notification::fake();
    Mail::fake();
    Bus::fake();
    Http::preventStrayRequests();

    $tech = User::query()->findOrFail(230);
    $requester = User::query()->findOrFail(231);
    $workAccess = app(ItWorkAccessService::class);
    foreach (['tech' => $tech, 'requester' => $requester] as $key => $user) {
        w01KbRequire($user->email === 'it-support-w01-20260909-'.$key.'@demo.test'
            && $user->approved_at !== null
            && $workAccess->approvedSiteIds($user) === [9403, 9404]
            && $user->roles()->pluck('name')->all() === ['it-support-w01-20260909-'.$key]
            && ! $user->permissionOverrides()->exists()
            && $user->canDo('it.request')
            && ! $user->canDo('it.viewSensitive')
            && ! $user->canDo('it.organisationWide')
            && $user->canDo('it.view') === ($key === 'tech')
            && $user->canDo('it.manage') === ($key === 'tech'),
            'Existing synthetic actor identity or authority differs.',
        );
    }
    foreach ([9403 => 'A', 9404 => 'B', 9405 => 'C'] as $id => $letter) {
        $site = Site::query()->findOrFail($id);
        w01KbRequire($site->name === 'IT Support W01 20260909 Site '.$letter && $site->is_active && ! $site->archived,
            'Existing synthetic Site identity or state differs.');
    }

    $definitions = [
        'site-a-draft' => ['Site A draft guide', 'draft', 'specific_sites', [9403], 'W01 KB approved-site draft marker.'],
        'site-a-published' => ['Site A published guide', 'published', 'specific_sites', [9403], 'W01 KB approved-site published marker.'],
        'site-c-draft' => ['Site C hidden draft guide', 'draft', 'specific_sites', [9405], 'W01 KB unapproved-site draft marker must stay hidden.'],
        'site-c-published' => ['Site C hidden published guide', 'published', 'specific_sites', [9405], 'W01 KB unapproved-site published marker must stay hidden.'],
        'agents-published' => ['Technician internal guide', 'published', 'it_agents', null, 'W01 KB technician-only audience marker.'],
        'all-staff-published' => ['General staff guide', 'published', 'all_staff', null, 'W01 KB general staff published marker.'],
    ];
    $fixtureQuery = fn () => ItKbArticle::query()->where(function ($query): void {
        $query->where('slug', 'like', W01_KB_SLUG_PREFIX.'%')->orWhere('title', 'like', W01_KB_PREFIX.'%');
    });
    $existing = $fixtureQuery()->get()->keyBy('slug');
    w01KbRequire($existing->isEmpty() || $existing->count() === count($definitions),
        'Partial or colliding fixture set exists; refusing to overwrite or repair.');
    $beforeCounts = w01KbCounts();
    $beforeDigest = w01KbExistingDigest($existing->pluck('id')->all());

    if (($argv[1] ?? null) !== '--create-fixtures') {
        echo json_encode([
            'guard_passed' => true, 'mutation_performed' => false,
            'prefix' => W01_KB_PREFIX, 'proposed_articles' => count($definitions),
            'existing_fixture_articles' => $existing->count(),
            'immutable_before_counts' => $beforeCounts, 'existing_articles_sha256' => $beforeDigest,
            'actor_ids' => ['tech' => 230, 'requester' => 231],
            'cases' => array_keys($definitions), 'requires_reviewed_create_flag' => true,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    $locked = (int) DB::scalar('SELECT GET_LOCK(?, 0)', [W01_KB_SLUG_PREFIX.'fixtures']) === 1;
    w01KbRequire($locked, 'Another knowledge fixture operation is active.');
    $manifest = DB::transaction(function () use ($definitions, $existing, $fixtureQuery, $beforeCounts, $beforeDigest, $tech, $requester): array {
        w01KbRequire(w01KbCounts() === $beforeCounts
            && w01KbExistingDigest($existing->pluck('id')->all()) === $beforeDigest,
            'Database changed after the immutable before snapshot; retry after inspection.');
        $created = $existing->isEmpty();
        if ($created) {
            foreach ($definitions as $key => [$label, $status, $audience, $scope, $body]) {
                ItKbArticle::query()->create([
                    'title' => W01_KB_PREFIX.' — '.$label,
                    'slug' => W01_KB_SLUG_PREFIX.$key,
                    'category' => 'other', 'body' => $body,
                    'status' => $status, 'audience' => $audience, 'site_scope' => $scope,
                    // Synthetic stale-author rows at Site C prove ownership
                    // cannot bypass revoked/unapproved audience authority.
                    'author_user_id' => $tech->id, 'owner_user_id' => $tech->id,
                    'reviewed_by_user_id' => $status === 'published' ? $tech->id : null,
                    'published_at' => $status === 'published' ? now() : null,
                    'view_count' => 0, 'helpful_yes' => 0, 'helpful_no' => 0, 'deflection_count' => 0,
                ]);
            }
        }
        $articles = $fixtureQuery()->get()->keyBy('slug');
        w01KbRequire($articles->count() === count($definitions), 'Fixture count differs.');
        foreach ($definitions as $key => [$label, $status, $audience, $scope, $body]) {
            $article = $articles->get(W01_KB_SLUG_PREFIX.$key);
            w01KbRequire($article && $article->title === W01_KB_PREFIX.' — '.$label
                && $article->body === $body && $article->status === $status
                && $article->audience === $audience && $article->site_scope === $scope
                && (int) $article->author_user_id === 230 && (int) $article->owner_user_id === 230,
                'Existing fixture changed; refusing to overwrite or repair.');
        }
        $afterCounts = w01KbCounts();
        $expectedCounts = $beforeCounts;
        $expectedCounts['it_kb_articles'] += $created ? count($definitions) : 0;
        w01KbRequire($afterCounts === $expectedCounts
            && w01KbExistingDigest($articles->pluck('id')->all()) === $beforeDigest,
            'Existing record counts or article state changed unexpectedly.');
        if ($created) {
            AuditLogger::logOrFail('it.test_knowledge_fixtures_created', null, [
                'fixture_prefix' => W01_KB_PREFIX, 'article_ids' => $articles->pluck('id')->all(),
            ]);
        }
        $access = app(ItKbAccessService::class);

        return [
            'prefix' => W01_KB_PREFIX, 'created' => $created,
            'existing_records_modified' => false, 'notifications_sent' => false,
            'immutable_before_counts' => $beforeCounts, 'after_counts' => $afterCounts,
            'existing_articles_unchanged_sha256' => $beforeDigest,
            'articles' => $articles->mapWithKeys(fn (ItKbArticle $article): array => [
                substr($article->slug, strlen(W01_KB_SLUG_PREFIX)) => [
                    'id' => $article->id, 'title' => $article->title, 'status' => $article->status,
                    'audience' => $article->audience, 'site_scope' => $article->site_scope,
                    'tech_catalog_visible' => $access->applyViewScope(ItKbArticle::query(), $tech)->whereKey($article->id)->exists(),
                    'tech_can_manage' => $access->canManage($tech, $article),
                    'tech_can_read_published' => $access->canReadPublished($tech, $article),
                    'requester_can_read_published' => $access->canReadPublished($requester, $article),
                    'interaction_url' => '/it/kb/'.$article->id.'/view',
                ],
            ])->all(),
        ];
    });
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Knowledge fixture operation refused ('.get_class($exception)."). No existing rows repaired or private values emitted.\n");
    exit(1);
} finally {
    if ($locked) {
        DB::select('SELECT RELEASE_LOCK(?)', [W01_KB_SLUG_PREFIX.'fixtures']);
    }
}
