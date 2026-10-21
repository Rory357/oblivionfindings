<?php

use App\Http\Middleware\EnsureChatActor;
use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ChatActorTest extends TestCase
{
    public static function invalidActors(): array
    {
        return [['11'], [null], [''], ['022'], ['22.0'], ['2.2e1'], ['22,11'], ['-22'], [' 22'], ['22 ']];
    }

    #[DataProvider('invalidActors')]
    public function test_stale_or_missing_actor_cannot_read_or_mutate_the_current_account(?string $actor): void
    {
        $request = Request::create('/chat/api/calls', 'GET');
        $request->setUserResolver(fn () => new GenericUser(['id' => 22]));
        if ($actor !== null) {
            $request->headers->set('X-Chat-Actor', $actor);
        }
        $reached = false;
        $response = (new EnsureChatActor)->handle($request, function () use (&$reached) {
            $reached = true;

            return new Response;
        });
        $this->assertFalse($reached);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('chat_actor_changed', json_decode($response->getContent(), true)['code']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('22', $response->getContent());
    }

    public function test_matching_actor_continues_to_normal_authorization(): void
    {
        $request = Request::create('/chat/api/calls', 'GET');
        $request->headers->set('X-Chat-Actor', '22');
        $request->setUserResolver(fn () => new GenericUser(['id' => 22]));
        $downstream = new Response('', 204);
        $this->assertSame($downstream, (new EnsureChatActor)->handle($request, fn () => $downstream));
        $request->setUserResolver(fn () => null);
        $this->assertSame(403, (new EnsureChatActor)->handle($request, fn () => $downstream)->getStatusCode());
    }

    public function test_file_links_require_the_expected_account_and_never_override_a_conflicting_header(): void
    {
        $response = new Response('', 204);
        foreach ([['22', null, 204], ['22', '22', 204], [null, '22', 204], ['11', null, 403], ['22', '11', 403], ['11', '22', 403], [null, null, 403], [['22'], null, 403], ['022', null, 403]] as [$query, $header, $status]) {
            $request = Request::create('/chat/api/attachments/9', 'GET', $query === null ? [] : ['_chat_actor' => $query]);
            $request->setUserResolver(fn () => new GenericUser(['id' => 22]));
            if ($header !== null) {
                $request->headers->set('X-Chat-Actor', $header);
            }
            $reached = false;
            $actual = (new EnsureChatActor)->handle($request, function () use (&$reached, $response) {
                $reached = true;

                return $response;
            }, 'file');
            $this->assertSame($status, $actual->getStatusCode());
            $this->assertSame($status === 204, $reached);
        }
        $request = Request::create('/chat/api/conversations', 'GET', ['_chat_actor' => '22']);
        $request->setUserResolver(fn () => new GenericUser(['id' => 22]));
        $this->assertSame(403, (new EnsureChatActor)->handle($request, fn () => $response)->getStatusCode());
    }

    public function test_call_and_contact_routes_require_the_rendered_actor(): void
    {
        $previous = Facade::getFacadeApplication();
        $container = new Container;
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::clearResolvedInstance('router');
        Facade::setFacadeApplication($container);
        try {
            require dirname(__DIR__, 3).'/routes/chat.php';
            $router->getRoutes()->refreshNameLookups();
            foreach (['message-alerts', 'uploads.index', 'uploads.show', 'uploads.store', 'uploads.retry', 'uploads.destroy', 'attachment.metadata', 'attachment.publication.options', 'attachment.publication.review', 'attachment.publication.store', 'family-inbox', 'queue', 'family-work.index', 'family-work.update', 'followups.review', 'followups.store', 'conversations', 'messages', 'changes', 'send', 'send.outcome', 'audience-review', 'read', 'preferences', 'action', 'messages.edit', 'search', 'collections', 'realtime', 'realtime.auth', 'notifications.previews', 'preferences.show', 'preferences.update', 'calls.status', 'availability', 'calls.start', 'calls.start-outcome', 'calls.cancel-start', 'calls.outcome', 'calls.options', 'calls.history', 'calls.join', 'calls.connected', 'calls.leave', 'calls.record', 'calls.people', 'calls.invite', 'calls.remove', 'create', 'callbacks.index', 'callbacks.store', 'callbacks.update', 'people.options', 'people.store', 'people.history', 'invitations', 'invitations.respond', 'invitations.history', 'management'] as $name) {
                $route = $router->getRoutes()->getByName('chat.'.$name);
                $this->assertNotNull($route, $name);
                $this->assertContains('auth', $route->middleware(), $name);
                $this->assertContains(EnsureChatActor::class, $route->middleware(), $name);
            }
            foreach (['uploads.preview', 'uploads.download', 'attachment', 'attachment.preview'] as $name) {
                $route = $router->getRoutes()->getByName('chat.'.$name);
                $this->assertNotNull($route, $name);
                $this->assertContains('auth', $route->middleware(), $name);
                $this->assertContains(EnsureChatActor::class.':file', $route->middleware(), $name);
            }
            foreach (['configuration', 'service-health', 'calling-health', 'update'] as $name) {
                $route = $router->getRoutes()->getByName('settings.chat.'.$name);
                $this->assertNotNull($route, $name);
                $this->assertContains('auth', $route->middleware(), $name);
                $this->assertContains(EnsureChatActor::class, $route->middleware(), $name);
            }
            $this->assertNotContains(EnsureChatActor::class, $router->getRoutes()->getByName('settings.chat')->middleware());
        } finally {
            Facade::clearResolvedInstance('router');
            Facade::setFacadeApplication($previous);
        }
    }
}
