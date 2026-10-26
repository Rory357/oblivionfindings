<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\PublicProviderWebsite;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PublicProviderController extends Controller
{
    public function show(Request $request, PublicProviderWebsite $website): Response
    {
        $page = $request->route()->defaults['provider_page'] ?? 'home';
        $provider = $website->content();
        $serviceId = $request->route('service');
        $service = $serviceId ? collect($provider['services'])->firstWhere('id', $serviceId) : null;
        abort_if($serviceId && ! $service, 404);

        $response = Inertia::render('public-provider/show', [
            'provider' => $provider,
            'page' => $page,
            'service' => $service,
            'previousFormRetired' => $request->query('previous_form') === 'retired',
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** An old email form lacks the current notice and durable submission token. */
    public function retiredContact(Request $request): Response
    {
        $message = 'This earlier contact form is no longer accepting messages. Your message has not been sent. Please use the current enquiry form.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'enquiry_url' => '/referrals/new'], 409);
        }

        return redirect('/contact?previous_form=retired', 303);
    }
}
