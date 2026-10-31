<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Services\DiscoveryCandidateService;
use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class DiscoveryCandidateReviewController extends Controller
{
    public function __construct(
        private readonly DiscoveryCandidateService $candidates,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function adopt(Request $request, DiscoveryCandidate $candidate): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && $user->canDo('securityDevices.integrations.manage')
            && $user->canDo('securityDevices.devices.create'),
            403,
        );

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', Rule::in(array_map(fn (DeviceDomain $d): string => $d->value, DeviceDomain::cases()))],
            'category' => ['nullable', 'string', 'max:100'],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($validated['domain']) && ! empty($validated['category'])) {
            if (! DeviceTaxonomy::isValid($validated['domain'], $validated['category'])) {
                throw ValidationException::withMessages([
                    'category' => 'Choose a valid category for the selected device domain.',
                ]);
            }
            if (! empty($validated['subcategory'])
                && ! DeviceTaxonomy::isValid($validated['domain'], $validated['category'], $validated['subcategory'])) {
                throw ValidationException::withMessages([
                    'subcategory' => 'Choose a valid subcategory for the selected category.',
                ]);
            }
        }

        try {
            $device = $this->candidates->adopt($candidate, (int) $user->id, $validated);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'candidate' => $exception->getMessage(),
            ]);
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'device_id' => $device->id,
                'name' => $device->name,
                'message' => "Added {$device->name} to Devices.",
            ]);
        }

        return back()->with('success', "Added {$device->name} to Devices.");
    }

    public function sameAs(Request $request, DiscoveryCandidate $candidate): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && $user->canDo('securityDevices.integrations.manage')
            && $user->canDo('securityDevices.devices.update'),
            403,
        );

        $validated = $request->validate([
            'device_id' => ['required', 'integer', 'min:1'],
        ]);

        $target = Device::query()->findOrFail((int) $validated['device_id']);
        $this->access->assertCanViewDevice($user, $target);

        try {
            $device = $this->candidates->sameAs($candidate, $target, (int) $user->id);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'device_id' => $exception->getMessage(),
            ]);
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'device_id' => $device->id,
                'name' => $device->name,
                'message' => "Linked candidate to {$device->name}.",
            ]);
        }

        return back()->with('success', "Linked candidate to {$device->name}.");
    }

    public function ignore(Request $request, DiscoveryCandidate $candidate): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.integrations.manage'), 403);

        try {
            $reviewed = $this->candidates->ignore($candidate, (int) $user->id);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'candidate' => $exception->getMessage(),
            ]);
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'candidate_id' => $reviewed->id,
                'review_action' => $reviewed->review_action,
                'message' => 'Candidate ignored.',
            ]);
        }

        return back()->with('success', 'Candidate ignored.');
    }

    public function adoptAll(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && $user->canDo('securityDevices.integrations.manage')
            && $user->canDo('securityDevices.devices.create'),
            403,
        );

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'],
            'scope_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        $scopeId = isset($validated['scope_id']) ? (int) $validated['scope_id'] : null;

        try {
            $adopted = $this->candidates->adoptAll((int) $user->id, $siteId, $scopeId);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'candidate' => $exception->getMessage(),
            ]);
        }

        $count = $adopted->count();
        $message = $count === 0
            ? 'No unreviewed candidates were waiting to be added.'
            : "Added {$count} ".($count === 1 ? 'device' : 'devices').' to Devices.';

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'adopted_count' => $count,
                'device_ids' => $adopted->pluck('id')->values()->all(),
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }
}
