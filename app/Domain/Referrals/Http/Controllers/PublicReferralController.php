<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\PublicProviderWebsite;
use App\Domain\Referrals\Services\PublicReferralIntake;
use App\Domain\Referrals\Services\ReferralForms;
use App\Domain\Referrals\Services\ReferralIntakeFunding;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PublicReferralController extends Controller
{
    public function create(Request $request, ReferralSettings $settings): Response
    {
        $version = $settings->effective();
        $configuration = $version?->configuration ?? ReferralSettings::defaults();
        $response = Inertia::render('public-referrals/create', [
            'provider' => app(PublicProviderWebsite::class)->fromConfiguration($configuration),
            'initialPurpose' => in_array($request->query('purpose'), ['support', 'referral', 'funding', 'callback', 'business'], true) ? $request->query('purpose') : 'support',
            'initialService' => collect($configuration['services'])->contains(fn ($service) => $service['enabled'] && $service['id'] === $request->query('service')) ? $request->query('service') : 'unsure',
            'intake' => [
                'enabled' => (bool) $configuration['public']['enabled'],
                'provider_name' => $configuration['public']['provider_name'],
                'contact_phone' => $configuration['public']['contact_phone'] ?? '',
                'response_hours' => $configuration['public']['response_hours'],
                'notice' => $configuration['public']['notice'] ?? '',
                'notice_version' => $configuration['public']['notice_version'],
                'settings_version_id' => $version?->id,
                'fields' => app(ReferralForms::class)->publicFields($configuration),
                'pathways' => ReferralIntakeFunding::options($configuration),
                'services' => array_values(array_map(fn ($service) => ['id' => $service['id'], 'label' => $service['label']], array_filter($configuration['services'], fn ($service) => $service['enabled']))),
            ],
            'submissionToken' => (string) Str::uuid(),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function store(Request $request, PublicReferralIntake $intake): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 32768, 413, 'Please shorten your enquiry before submitting.');
        $receipt = $intake->submit($request->except('_token'));

        return response()->json($receipt, 201)->header('Cache-Control', 'no-store, private');
    }
}
