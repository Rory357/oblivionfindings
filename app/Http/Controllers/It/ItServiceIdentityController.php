<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItServiceIdentityCommandService;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Models\ItServiceIdentity;
use App\Models\Site;
use Closure;
use DomainException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ItServiceIdentityController extends Controller
{
    public function __construct(
        private readonly ItServiceIdentityCommandService $commands,
        private readonly ItServiceIdentityCredentialService $credentials,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            $viewer = $request->user();
            try {
                $this->credentials->guardManager($viewer);
            } catch (DomainException) {
                abort(403);
            }
            $identities = ItServiceIdentity::query()
                ->where(fn ($query) => $query->where('created_by_user_id', $viewer->id)->orWhere('actor_user_id', $viewer->id))
                ->with(['actor:id,name', 'creator:id,name'])->latest('id')->get()
                ->filter(fn (ItServiceIdentity $identity): bool => $this->credentials->canManage($viewer, $identity))
                ->map(fn (ItServiceIdentity $identity): array => $this->credentials->present($identity))->values();
            $siteIds = app(ItWorkAccessService::class)->approvedSiteIds($viewer);

            return [
                'viewer_user_id' => $viewer->id, 'can_manage' => true, 'identities' => $identities,
                'agents' => $this->credentials->delegableExecutionAccounts($viewer)->map(fn ($user): array => $user->only(['id', 'name']))->values(),
                'sites' => Site::query()->whereKey($siteIds)->orderBy('name')->get(['id', 'name'])->toArray(),
            ];
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->commands->execute($request->user(), 'issue', $request->all()));
    }

    public function update(Request $request, ItServiceIdentity $identity): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->commands->execute($request->user(), 'update', $request->all(), $identity));
    }

    public function rotate(Request $request, ItServiceIdentity $identity): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->commands->execute($request->user(), 'rotate', $request->all(), $identity));
    }

    public function revoke(Request $request, ItServiceIdentity $identity): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->commands->execute($request->user(), 'revoke', $request->all(), $identity));
    }

    public function recover(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->commands->recover($request->user(), $request->all()));
    }

    public function cancel(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->commands->recover($request->user(), $request->all(), true));
    }

    private function respond(Request $request, Closure $action): JsonResponse
    {
        // No redirects, flash or Inertia history may carry a reusable credential.
        $json = $request->wantsJson() && ! $request->header('X-Inertia');
        $request->headers->set('Accept', 'application/json');
        if (! $json) {
            return response()->json(['message' => 'Use the direct JSON identity-management interface.'], 400)
                ->header('Cache-Control', 'no-store, private');
        }
        try {
            $response = response()->json($action());
        } catch (ValidationException $exception) {
            $response = response()->json(['message' => 'Check the identity settings.', 'errors' => $exception->errors()], 422);
        } catch (DomainException $exception) {
            $response = response()->json(['message' => $exception->getMessage(), 'errors' => ['identity' => [$exception->getMessage()]]], 422);
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
            if (! $response instanceof JsonResponse) {
                throw $exception;
            }
            $response->setData([...$response->getData(true), 'viewer_user_id' => $request->user()->id]);
        }

        return $response->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
