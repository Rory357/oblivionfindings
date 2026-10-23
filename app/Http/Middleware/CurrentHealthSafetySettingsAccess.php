<?php

namespace App\Http\Middleware;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Dedicated route gate; owning services still recheck at read/write acceptance. */
final class CurrentHealthSafetySettingsAccess
{
    private const PERMISSION = 'healthSafety.settings.manage';

    public function __construct(
        private readonly AuthorizationEvidenceLockService $authorization,
        private readonly HrCurrentStaffService $staff,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 403);
        DB::transaction(function () use ($request): void {
            CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($request): void {
                $actor = $this->authorization->lockForUserWithoutWaiting($request->user(), [self::PERMISSION]);
                abort_unless($actor->canDo(self::PERMISSION), 403);
                abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($actor->id)->exists(), 403);
            });
        });

        return $next($request);
    }
}
