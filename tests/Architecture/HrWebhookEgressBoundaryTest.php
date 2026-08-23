<?php

it('keeps HR webhook delivery behind canonical public destination authorization and pinned transport', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $job = file_get_contents($root.'/app/Domain/Hr/Jobs/DeliverHrWebhookJob.php');
    $service = file_get_contents($root.'/app/Domain/Hr/Services/HrWebhookService.php');
    $policy = file_get_contents($root.'/app/Domain/Hr/Services/HrWebhookDestinationPolicy.php');
    $controller = file_get_contents($root.'/app/Http/Controllers/Hr/HrWebhookController.php');

    expect($job)
        ->toContain(
            'AuthorizedHrWebhookDestination $target',
            "'allow_redirects' => false",
            "'proxy' => ''",
            "'verify' => true",
            "constant('CURLOPT_RESOLVE')",
            'JSON_THROW_ON_ERROR',
            "->withBody(\$payloadJson, 'application/json')",
            '$destinationPolicy->authorize(',
            '$destinationPolicy->authorizeRedirect(',
        )
        ->not->toContain('->post($endpoint->target_url', 'report($exception)')
        ->and($service)->toContain(
            '$this->assertActorCanManage($actor)',
            '$this->normalizedDestination(',
            '$this->destinationPolicy->authorize($preflightTargetUrl)',
        )
        ->and($policy)->toContain(
            'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE',
            'ProbeTarget::http($url)',
            "\$target->scheme !== 'https'",
        )
        ->and($controller)->toContain(
            'public function update(Request $request, string $endpoint)',
            'public function retryDelivery(Request $request, string $delivery)',
            '$this->authorizedActor($request)',
        )
        ->not->toContain(
            'public function update(Request $request, HrWebhookEndpoint $endpoint)',
            'public function retryDelivery(Request $request, HrWebhookDelivery $delivery)',
        );
});
