<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItRemoteSupportAccess;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScripts;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePackages;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePolicy;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use App\Models\ItTicket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class ItRemoteSupportController extends Controller
{
    public function __invoke(Request $request, ItRemoteSupportAccess $access, ItWorkAccessService $work): Response
    {
        $actor = $request->user();
        abort_unless($access->canOpen($actor), 403);
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'device' => ['nullable', 'integer', 'min:1'],
            'ticket' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $access->computers($actor);
        $selected = isset($input['device']) ? (clone $query)->findOrFail($input['device']) : null;
        $ticket = null;
        if (isset($input['ticket'])) {
            $ticket = ItTicket::query()->findOrFail($input['ticket']);
            abort_unless($selected && ! $ticket->isMerged() && $work->canWork($actor, $ticket), 404);
            abort_unless($ticket->links()->where('relationship', 'affected_device')
                ->where('linkable_type', $selected->getMorphClass())->where('linkable_id', $selected->id)->exists(), 404);
        }
        $search = trim($input['q'] ?? '');
        $computers = (clone $query)->when($search !== '', fn ($q) => $q->where(function ($names) use ($search) {
            $names->where('name', 'like', "%{$search}%")->orWhere('device_uid', 'like', "%{$search}%");
        }))->orderBy('name')->orderBy('id')->paginate(20)->withQueryString()
            ->through(fn (Device $device): array => $this->computer($device));
        // Read/suggest investigation is independently gated. Installed attended
        // remote-control acceptance remains outstanding.
        $response = Inertia::render('it/remote-support/index', [
            'actor_id' => $actor->id,
            'can_view_software_catalogue' => EndpointSoftwarePackages::canOpen($actor),
            'can_view_script_catalogue' => app(EndpointScripts::class)->canOpen($actor),
            'can_manage_software_policy' => $actor->canDo(EndpointSoftwarePolicy::MANAGE),
            'can_manage_restart_policy' => $actor->canDo(EndpointRestartPolicy::MANAGE),
            'can_manage_action_policy' => $actor->canDo(EndpointActionPolicy::MANAGE),
            'can_manage_remote_policy' => $actor->canDo('securityDevices.remote.managePolicy'),
            'computers' => $computers,
            'selected' => $selected ? $this->computer($selected) : null,
            'ticket' => $ticket ? ['id' => $ticket->id, 'reference' => $ticket->reference, 'href' => "/it/tickets/{$ticket->id}"] : null,
            'filters' => ['q' => $search],
            'summary' => [
                'computers' => (clone $query)->count(),
                'endpoints' => (clone $query)->where('category', 'endpoint')->count(),
                'servers' => (clone $query)->where('category', 'server')->count(),
            ],
            'availability' => [
                'remote_support' => false,
                'obli' => (bool) (config('endpoint_management.enabled') && config('endpoint_management.obli.technician_enabled') && ($actor->canDo('securityDevices.endpoints.diagnostics') || $actor->canDo('securityDevices.obli.assist'))),
                'script_execution' => app(EndpointScriptPolicy::class)->available(),
            ],
        ]);
        $response = $response->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    private function computer(Device $device): array
    {
        return [
            'id' => $device->id, 'name' => $device->name, 'uid' => $device->device_uid,
            'category' => $device->category, 'status' => $device->status->value,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'href' => route('security-devices.devices.show', $device),
            'management_href' => route('security-devices.devices.show', $device).'?section=management',
        ];
    }
}
