<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointScriptCatalogueController;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EndpointScriptQueryTest extends TestCase
{
    public function test_raw_query_preserves_empty_and_spaced_search_after_framework_normalization(): void
    {
        $method = new ReflectionMethod(EndpointScriptCatalogueController::class, 'query');
        foreach (['' => null, ' exact ' => 'exact'] as $raw => $normalized) {
            $request = Request::create('https://localhost/security-devices/scripts?expected_actor_id=7&status=all&search='.rawurlencode($raw));
            $request->query->set('search', $normalized);
            $value = $method->invoke(new EndpointScriptCatalogueController, $request, ['expected_actor_id', 'status', 'search'], false);
            self::assertSame(['expected_actor_id' => 7, 'status' => 'all', 'search' => $raw], $value);
        }
    }

    public function test_duplicate_and_extra_raw_keys_are_not_hidden_by_framework_parsing(): void
    {
        $previous = Container::getInstance();
        new Application;
        $method = new ReflectionMethod(EndpointScriptCatalogueController::class, 'query');
        try {
            foreach (['expected_actor_id=7&%65xpected_actor_id=7', 'expected_actor_id=7&search=&search=', 'expected_actor_id=7&unknown=1', 'expected_actor_id=7&search%5B%5D=value'] as $raw) {
                $request = Request::create('https://localhost/security-devices/scripts?'.$raw);
                try {
                    $method->invoke(new EndpointScriptCatalogueController, $request, ['expected_actor_id', 'search'], false);
                    self::fail('Ambiguous raw query accepted.');
                } catch (HttpException $error) {
                    self::assertSame(400, $error->getStatusCode());
                }
            }
        } finally {
            Container::setInstance($previous);
        }
    }
}
