<?php

namespace Tests\Unit\Referrals;

use App\Domain\Referrals\Http\ProtectDocumentRequestResponses;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DocumentRequestResponsesTest extends TestCase
{
    private Container $previous;

    private mixed $previousLogger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $this->previousLogger = Log::getFacadeRoot();
        $container = new Container;
        Container::setInstance($container);
        $factory = Mockery::mock(ResponseFactory::class);
        $factory->shouldReceive('json')->andReturnUsing(fn ($data, $status) => new JsonResponse($data, $status));
        $container->instance(ResponseFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        if ($this->previousLogger !== null) {
            Log::swap($this->previousLogger);
        } else {
            Log::clearResolvedInstance('log');
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_unexpected_failures_never_echo_or_log_credentials_even_for_html_requests(): void
    {
        $secret = 'private-token-and-passcode';
        $logged = null;
        Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) use (&$logged): bool {
            $logged = [$message, $context];

            return $context['exception_type'] === RuntimeException::class && isset($context['incident']);
        });
        $request = Request::create('/referrals/documents/'.$secret, 'POST', ['passcode' => $secret], [], [], ['HTTP_ACCEPT' => 'text/html']);
        $response = (new ProtectDocumentRequestResponses)->handle($request, fn () => throw new RuntimeException($secret));
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString($secret, $response->getContent());
        self::assertStringNotContainsString($secret, json_encode($logged));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString('application/json', $response->headers->get('Content-Type'));
    }

    public function test_expected_denials_have_generic_private_non_indexable_responses(): void
    {
        $request = Request::create('/referrals/documents/fixture', 'POST');
        $response = (new ProtectDocumentRequestResponses)->handle($request, fn () => throw new HttpException(404, 'Private internal reason'));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Private internal reason', $response->getContent());
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_the_global_exception_hook_leaves_other_application_routes_alone(): void
    {
        self::assertNull(ProtectDocumentRequestResponses::render(new RuntimeException('unrelated'), Request::create('/operations/referrals')));
    }
}
