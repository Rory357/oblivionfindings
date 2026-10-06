<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Http\Controllers\Controller;
use App\Models\MedicationBackupDelivery;
use App\Models\MedicationBackupSchedule;
use App\Models\Site;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Medication\BackupDelivery\BackupDeliveryAccess;
use App\Services\Medication\BackupDelivery\BackupDeliveryService;
use App\Services\Medication\BackupDelivery\BackupPdfEncryption;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\UserSiteAccessService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MedicationBackupDeliveryController extends Controller
{
    public function __construct(private readonly BackupDeliveryService $backups, private readonly BackupDeliveryAccess $access) {}

    public function index(Request $request)
    {
        $request->validate(['deliveries_page' => 'sometimes|integer|min:1']);
        $actor = $request->user();
        abort_unless($actor->isApproved() && $actor->canDo('medications.reports.view') && $actor->canDo('medications.reports.export') && app(HrCurrentStaffService::class)->isCurrent($actor), 403);
        $manager = $actor->canDo('medications.backups.manage');
        $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
        $schedules = MedicationBackupSchedule::query()->whereIn('site_id', $siteIds)->when(! $manager, fn ($q) => $q->whereHas('recipients', fn ($r) => $r->where('user_id', $actor->id)->whereNull('revoked_at')))->with('recipients.user')->orderBy('site_id')->get();
        $allowedIds = $manager ? $siteIds : $schedules->pluck('site_id')->all();
        $deliveries = MedicationBackupDelivery::query()->whereIn('site_id', $allowedIds)->orderByDesc('id')->paginate(25, ['*'], 'deliveries_page', $request->integer('deliveries_page', 1));
        $ready = app(BackupPdfEncryption::class)->ready();

        return Inertia::render('emar/backups/index', [
            'sites' => Site::query()->whereIn('id', $allowedIds)->where('is_active', true)->where('archived', false)->get(['id', 'name'])->toArray(),
            'schedules' => $schedules->map(fn ($s) => ['id' => (int) $s->id, 'site_id' => (int) $s->site_id, 'timezone' => $s->timezone, 'local_time' => $s->local_time, 'enabled' => $s->enabled, 'version' => $s->version, 'retention_days' => $s->retention_days, 'recipients' => $s->recipients->map(fn ($r) => ['user_id' => (int) $r->user_id, 'name' => $r->user?->name, 'email' => $manager ? $r->user?->email : null, 'status' => $r->revoked_at ? 'revoked' : ($r->user?->email_verified_at && hash_equals($r->email_sha256, $this->access->emailHash($r->user)) ? 'approved' : 'review_required')])->all()])->all(),
            'recipient_candidates' => [], 'deliveries_meta' => ['current_page' => $deliveries->currentPage(), 'last_page' => $deliveries->lastPage(), 'total' => $deliveries->total()], 'deliveries' => $deliveries->getCollection()->map(fn ($d) => $this->backups->dto($d, $actor))->all(),
            'readiness' => ['encryption_ready' => $ready, 'send_enabled' => (bool) config('emar-catalogue-backups.send_enabled', false), 'reason' => ! $ready ? 'Configure a reviewed qpdf executable for AES-256 backups.' : (! config('emar-catalogue-backups.send_enabled', false) ? 'Email delivery is disabled until the owner approves the transport.' : null)],
            'can_manage' => $manager, 'notice' => 'Whole-house charts are encrypted with AES-256. Passwords are available separately after your own password and personal authenticator check. A submission with an unknown result is never resent automatically.',
        ]);
    }

    public function recipientSearch(Request $request, int $site)
    {
        $request->validate(['search' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);
        DB::transaction(fn () => $this->access->manager($request->user(), $site), 1);
        $search = trim((string) $request->query('search', ''));
        $matches = [];
        $query = app(HrCurrentStaffService::class)->currentUsersQuery()->whereNotNull('email_verified_at');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $pattern)->orWhere('email', 'like', $pattern));
        }
        // Filter the whole candidate population before pagination: later eligible staff stay reachable.
        $query->chunkById(100, function ($users) use ($site, &$matches): void {
            foreach ($users as $user) {
                try {
                    DB::transaction(fn () => $this->access->recipient($user, $site), 1);
                    $matches[] = ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email, 'site_ids' => [$site]];
                } catch (HttpExceptionInterface) {
                    // Do not reveal why another account is excluded.
                }
            }
        });
        $page = $request->integer('page', 1);

        return response()->json(['recipient_candidates' => array_slice($matches, ($page - 1) * 25, 25), 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($matches) / 25)), 'total' => count($matches)]])->header('Cache-Control', 'private, no-store');
    }

    public function schedule(Request $request, int $site)
    {
        $data = $request->validate(['version' => 'required|integer|min:0', 'local_time' => ['required', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'], 'enabled' => 'required|boolean', 'retention_days' => 'required|integer|min:1|max:30']);
        $data['enabled'] = $request->boolean('enabled');
        $data['retention_days'] = (int) $data['retention_days'];
        $row = $this->backups->schedule($request->user(), $site, (int) $data['version'], $data);

        return response()->json(['id' => $row->id, 'version' => $row->version]);
    }

    public function recipient(Request $request, int $schedule)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'user_id' => 'required|integer|min:1', 'approved' => 'required|boolean']);
        $row = $this->backups->approveRecipient($request->user(), $schedule, (int) $data['version'], (int) $data['user_id'], $request->boolean('approved'));

        return response()->json(['id' => $row->id, 'version' => $row->version]);
    }

    public function prepare(Request $request, int $site)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'nz_date' => 'required|date_format:Y-m-d']);
        $row = $this->backups->prepare($request->user(), $site, $data['nz_date'], (int) $data['version']);

        return response()->json($this->backups->dto($row, $request->user()));
    }

    public function send(Request $request, int $delivery)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $row = $this->backups->send($request->user(), $delivery, (int) $data['version']);

        return response()->json($this->backups->dto($row, $request->user()));
    }

    public function retry(Request $request, int $delivery)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $row = $this->backups->retry($request->user(), $delivery, (int) $data['version']);

        return response()->json($this->backups->dto($row, $request->user()));
    }

    public function download(Request $request, int $delivery)
    {
        $row = $this->backups->readable($request->user(), $delivery);

        return response(Storage::disk('private')->get($row->artifact_path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="chart-backup-'.$row->nz_date.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function password(Request $request, int $delivery)
    {
        $data = $request->validate(['password' => 'required|string|max:4096', 'verification_code' => ['required', 'regex:/^\d{6}$/']]);
        $password = DB::transaction(function () use ($request, $delivery, $data): string {
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($request->user(), ['*']);
            $row = $this->backups->readable($actor, $delivery);
            $valid = $actor->two_factor_confirmed_at && $actor->two_factor_secret && is_string($actor->password) && Hash::check($data['password'], $actor->password);
            $window = false;
            if ($valid) {
                try {
                    $secret = Fortify::currentEncrypter()->decrypt($actor->two_factor_secret);
                    $window = (new Google2FA)->verifyKeyNewer($secret, $data['verification_code'], 0, 1);
                } catch (DecryptException) {
                    $window = false;
                }
            }
            if (! is_int($window) || $window < 1 || DB::table('medication_backup_step_up_uses')->insertOrIgnore(['user_id' => $actor->id, 'authenticator_window' => $window, 'created_at' => now()]) !== 1) {
                throw ValidationException::withMessages(['verification_code' => 'Use your own current account password and a new personal authenticator code.']);
            }

            return $row->password;
        }, 1);

        return response()->json(['password' => $password])->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }
}
