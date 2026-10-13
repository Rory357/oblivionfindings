<?php

use App\Domain\It\Services\ItObliDiagnosticSource;
use App\Domain\Obli\ObliAiSettings;
use App\Domain\Obli\ObliChatGateway;
use App\Domain\Obli\ObliChatRejected;
use App\Domain\Obli\ObliTechnicianChatScope;
use App\Http\Controllers\It\ItObliInvestigationController;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

uses(TestCase::class);

it('rejects invalid technician source bindings and Unicode controls before authority or database access', function () {
    $gateway = (new ReflectionClass(ObliChatGateway::class))->newInstanceWithoutConstructor();
    $scope = (new ReflectionClass(ObliTechnicianChatScope::class))->newInstanceWithoutConstructor();
    $input = ['request_uuid' => '11111111-1111-4111-8111-111111111111', 'configuration_version' => 1];
    foreach ([[], null, ['snapshot_version' => str_repeat('a', 64)], ['groups' => ['system']], ['snapshot_version' => str_repeat('a', 64), 'groups' => ['system', 'system']], ['snapshot_version' => str_repeat('a', 64), 'groups' => ['system'], 'snapshot' => ['password' => 'forged']]] as $binding) {
        try {
            $gateway->sendInvestigation($scope, [...$input, 'messages' => [['role' => 'user', 'content' => 'Question', 'computer_diagnostics' => $binding]]]);
            $this->fail('Invalid source binding was accepted.');
        } catch (ValidationException $error) {
            expect($error->status)->toBe(422);
        }
    }
    foreach (["Question\u{0085}inside", "Question\u{0085}"] as $content) {
        try {
            $gateway->sendInvestigation($scope, [...$input, 'messages' => [['role' => 'user', 'content' => $content]]]);
            $this->fail('Unicode control was accepted.');
        } catch (HttpExceptionInterface $error) {
            expect($error->getStatusCode())->toBe(422);
        }
    }
});

it('rejects embedded C1 through the controller after normalization before source access', function () {
    $actor = new User;
    $actor->id = 1;
    $input = ['request_uuid' => '11111111-1111-4111-8111-111111111111', 'expected_actor_id' => 1, 'configuration_version' => 1, 'messages' => [['role' => 'user', 'content' => "Question\u{0085}inside"]]];
    $request = Request::create('https://localhost/it/remote-support/devices/1/obli/chat', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($input));
    $request->setUserResolver(fn () => $actor);
    $source = (new ReflectionClass(ItObliDiagnosticSource::class))->newInstanceWithoutConstructor();
    $settings = (new ReflectionClass(ObliAiSettings::class))->newInstanceWithoutConstructor();
    $gateway = (new ReflectionClass(ObliChatGateway::class))->newInstanceWithoutConstructor();
    try {
        (new TrimStrings)->handle($request, fn ($request) => (new ItObliInvestigationController)->store($request, 1, $source, $settings, $gateway));
        $this->fail('Embedded control survived controller validation.');
    } catch (ObliChatRejected $error) {
        expect($error->codeName)->toBe('invalid_request')->and($error->statusCode)->toBe(422);
    }
});
it('preserves framework normalization at the actual nested field while rejecting embedded C1 controls', function () {
    $gateway = (new ReflectionClass(ObliChatGateway::class))->newInstanceWithoutConstructor();
    $validate = new ReflectionMethod(ObliChatGateway::class, 'validateInput');
    foreach ([false, true] as $embedded) {
        $content = $embedded ? "Question\u{0085}inside" : "Question\u{0085}";
        $input = ['request_uuid' => '11111111-1111-4111-8111-111111111111', 'configuration_version' => 1, 'messages' => [['role' => 'user', 'content' => $content]]];
        $request = Request::create('https://localhost/it/remote-support/devices/1/obli/chat', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($input));
        (new TrimStrings)->handle($request, function ($request) use ($content, $embedded, $gateway, $validate) {
            $normalized = $request->json()->all();
            expect($normalized['messages'][0]['content'])->toBe($embedded ? $content : 'Question');
            if ($embedded) {
                try {
                    $validate->invoke($gateway, $normalized, true);
                    $this->fail('Embedded control survived gateway validation.');
                } catch (HttpExceptionInterface $error) {
                    expect($error->getStatusCode())->toBe(422);
                }
            } else {
                $validated = $validate->invoke($gateway, $normalized, true);
                expect($validated['messages'][0]['content'])->toBe('Question');
            }

            return response('', 204);
        });
    }
});
