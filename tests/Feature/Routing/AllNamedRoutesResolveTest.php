<?php

namespace Tests\Feature\Routing;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AllNamedRoutesResolveTest extends TestCase
{
    public function test_every_named_route_has_a_uri_action_and_generates_a_url(): void
    {
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name) {
                continue;
            }

            if ($route->uri() === '') {
                $failures[] = "{$name}: empty URI";
                continue;
            }

            try {
                $url = route($name, $this->placeholderParameters($route->parameterNames()));
            } catch (\Throwable $exception) {
                $failures[] = "{$name}: ".$exception->getMessage();
                continue;
            }

            if (! is_string($url) || $url === '') {
                $failures[] = "{$name}: generated empty URL";
            }
        }

        $this->assertSame([], $failures);
    }

    /**
     * @param  array<int, string>  $parameterNames
     * @return array<string, int|string>
     */
    private function placeholderParameters(array $parameterNames): array
    {
        return collect($parameterNames)
            ->mapWithKeys(fn (string $name) => [$name => str_contains($name, 'uuid') ? '00000000-0000-4000-8000-000000000001' : 1])
            ->all();
    }
}
