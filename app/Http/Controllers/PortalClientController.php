<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientIncident;
use App\Models\TimelineEvent;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Http\Request;

class PortalClientController extends Controller
{
    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $user = $request->user();
        abort_unless($user, 403);
        $sectionAccessService = app(PortalClientSectionAccess::class);
        $sectionAccess = $sectionAccessService->for($user, $client);
        $canViewFamilyInformation = $sectionAccess['has_family_information_consent'];
        $canViewMedical = $sectionAccess['can_view_medical'];
        $canViewMedications = $sectionAccess['can_view_medications'];

        $clientRelations = [];
        if ($canViewMedical) {
            $clientRelations[] = 'medicalProfile';
            $clientRelations[] = 'conditions';
            $clientRelations[] = 'emergencyContacts';
        }
        if ($canViewMedications) {
            $clientRelations[] = 'medications';
        }
        $client->load($clientRelations);

        $documents = $canViewFamilyInformation
            ? ClientDocument::query()
                ->where('client_id', $client->id)
                ->where('portal_visible', true)
                ->orderByDesc('created_at')
                ->get(['id', 'title', 'category', 'notes', 'original_name', 'mime_type', 'size_bytes', 'created_at'])
            : collect();

        $eventsQuery = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->where('visibility', 'portal')
            ->with(['actor:id,name', 'site:id,name']);
        $sectionAccessService->constrainTimeline($eventsQuery, $sectionAccess);
        $events = $eventsQuery->orderByDesc('occurred_at')
            ->limit(60)
            ->get();

        $incidents = collect();
        $canViewIncidents = $sectionAccess['can_view_incidents']
            && $user->canDo('incidents.view.portal');
        if ($canViewIncidents) {
            $incidents = ClientIncident::query()
                ->where('client_id', $client->id)
                ->where('portal_visible', true)
                ->whereNotNull('reviewed_at')
                ->orderByDesc('occurred_at')
                ->with(['attachments' => fn ($q) => $q->where('portal_visible', true)])
                ->limit(40)
                ->get();
        }

        $assets = $canViewFamilyInformation
            ? Asset::query()
                ->where('client_id', $client->id)
                ->orderBy('name')
                ->get(['id', 'name', 'asset_tag', 'status', 'risk_level'])
            : collect();

        $trackingConsent = $sectionAccessService->activeLocationTrackingConsent($client);

        $pageProps = [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'profile_photo_url' => $client->profile_photo_url,
                'avatar' => $client->avatar,
            ],
            'documents' => $documents,
            'incidents' => $incidents->map(fn ($i) => [
                'id' => $i->id,
                'type' => $i->type,
                'severity' => $i->severity,
                'occurred_at' => optional($i->occurred_at)->toISOString(),
                'description' => $i->description,
                'immediate_action_taken' => $i->immediate_action_taken,
                'requires_followup' => (bool) $i->requires_followup,
                'attachments' => $i->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'original_name' => $a->original_name,
                    'mime_type' => $a->mime_type ?? $a->mime,
                    'size' => $a->size,
                    'download_url' => $canViewIncidents && $user->canDo('incidents.attachments.view.portal')
                        ? route('portal.clients.incidents.attachments.download', ['client' => $client->id, 'incident' => $i->id, 'attachment' => $a->id])
                        : null,
                ])->values(),
            ])->values(),
            'events' => $events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'meta' => $e->meta ?? [],
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
            ])->values(),
            'assets' => $assets->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'asset_tag' => $a->asset_tag,
                'status' => $a->status,
                'risk_level' => $a->risk_level,
            ])->values(),
            'tracking_consent' => $trackingConsent ? [
                'id' => $trackingConsent->id,
                'status' => $trackingConsent->status,
                'given_at' => optional($trackingConsent->given_at)->toISOString(),
                'expires_at' => optional($trackingConsent->expires_at)->toISOString(),
            ] : null,
            'rag_answer' => session('rag_answer'),
            'can' => [
                'viewIncidents' => $canViewIncidents,
                'downloadIncidentAttachments' => $canViewIncidents
                    && $user->canDo('incidents.attachments.view.portal'),
                'askRag' => $sectionAccess['is_self']
                    && $user->canDo('rag.ask.self'),
            ],
        ];

        if ($canViewMedical) {
            $pageProps['profile'] = $client->medicalProfile;
            $pageProps['conditions'] = $client->conditions;
            $pageProps['emergency_contacts'] = $client->emergencyContacts;
        }
        if ($canViewMedications) {
            $pageProps['medications'] = $client->medications;
        }

        return inertia('portal/client', $pageProps);
    }
}
