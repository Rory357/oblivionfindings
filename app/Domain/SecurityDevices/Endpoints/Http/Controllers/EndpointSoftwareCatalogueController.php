<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwareIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePackages;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

final class EndpointSoftwareCatalogueController extends Controller
{
    public function confirmIdentity(Request $request, EndpointSoftwarePackages $packages)
    {
        abort_unless($request->isSecure(), 400);
        $input = Validator::make($request->query(), ['expected_actor_id' => ['required', 'integer', 'min:1']])->validate();
        abort_unless(array_keys($request->query()) === ['expected_actor_id'], 400);
        $actor = DB::transaction(fn () => $packages->actor($request->user()));
        abort_unless((int) $input['expected_actor_id'] === (int) $actor->id, 409);
        abort_unless($actor->canDo(EndpointSoftwarePackages::REVIEW), 403);
        app(EndpointSoftwareIdentity::class)->begin($request, $actor);

        return redirect()->route('password.confirm');
    }

    public function index(Request $request, EndpointSoftwarePackages $packages)
    {
        abort_unless($request->isSecure(), 400);
        $dto = $packages->index($request->user(), $request->query());

        return $request->wantsJson() ? response()->json($dto)->header('Cache-Control', 'private, no-store') : Inertia::render('security-devices/software/index', $dto);
    }

    public function upload(Request $request, EndpointSoftwarePackages $packages): JsonResponse
    {
        abort_unless($request->isSecure(), 400);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json($packages->upload($request->user(), $request->except('file'), $file))->header('Cache-Control', 'private, no-store');
    }

    public function recovery(Request $request, string $request_key, EndpointSoftwarePackages $packages): JsonResponse
    {
        return response()->json($packages->recovery($request->user(), $request_key, $request->query()))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $package, EndpointSoftwarePackages $packages): JsonResponse
    {
        return response()->json($packages->show($request->user(), $package, $request->query()))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, string $package, EndpointSoftwarePackages $packages): JsonResponse
    {
        return $this->write($request, $package, 'submit', $packages);
    }

    public function review(Request $request, string $package, EndpointSoftwarePackages $packages): JsonResponse
    {
        return $this->write($request, $package, 'review', $packages);
    }

    public function withdraw(Request $request, string $package, EndpointSoftwarePackages $packages): JsonResponse
    {
        return $this->write($request, $package, 'withdraw', $packages);
    }

    private function write(Request $request, string $package, string $action, EndpointSoftwarePackages $packages): JsonResponse
    {
        abort_unless($request->isSecure(), 400);

        return response()->json($packages->transition($request->user(), $package, $action, $request->json()->all()))->header('Cache-Control', 'private, no-store');
    }
}
