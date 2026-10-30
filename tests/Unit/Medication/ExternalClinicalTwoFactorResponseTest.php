<?php

namespace Tests\Unit\Medication;

use App\Http\Responses\TwoFactorLoginResponse;
use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;

final class ExternalClinicalTwoFactorResponseTest extends TestCase
{
    private Container $originalContainer;

    private Store $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $this->session = new Store('external-response-test', new ArraySessionHandler(120));
        $redirect = new Redirector(new UrlGenerator(new RouteCollection, Request::create('http://localhost')));
        $redirect->setSession($this->session);
        $container->instance('redirect', $redirect);
        $container->instance('config', new Repository(['fortify' => ['home' => '/dashboard']]));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    private function request(User $user, bool $json = false): Request
    {
        $request = Request::create('http://localhost/two-factor-challenge', 'POST');
        $request->setLaravelSession($this->session);
        $request->setUserResolver(fn () => $user);
        if ($json) {
            $request->headers->set('Accept', 'application/json');
        }

        return $request;
    }

    public function test_external_mfa_completion_clears_internal_intended_and_opens_clinical_portal(): void
    {
        $user = new User(['role' => 'admin']);
        $user->forceFill(['external_clinical_account' => true]);
        $this->session->put('url.intended', '/settings/access');
        $response = (new TwoFactorLoginResponse)->toResponse($this->request($user));
        $this->assertSame('http://localhost/clinical-portal', $response->getTargetUrl());
        $this->assertFalse($this->session->has('url.intended'));
    }

    public function test_internal_mfa_completion_retains_fortify_intended_destination(): void
    {
        $user = new User(['role' => 'support_worker']);
        $this->session->put('url.intended', '/my-day');
        $response = (new TwoFactorLoginResponse)->toResponse($this->request($user));
        $this->assertSame('http://localhost/my-day', $response->getTargetUrl());
    }

    public function test_external_json_mfa_completion_keeps_fortify_no_content_response(): void
    {
        $user = new User(['role' => 'external_clinician']);
        $response = (new TwoFactorLoginResponse)->toResponse($this->request($user, true));
        $this->assertSame(204, $response->getStatusCode());
    }
}
