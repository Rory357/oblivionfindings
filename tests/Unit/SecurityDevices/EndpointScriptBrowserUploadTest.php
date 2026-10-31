<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointScriptCatalogueController;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScripts;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EndpointScriptBrowserUploadTest extends TestCase
{
    public function test_original_browser_requests_pass_actual_server_body_binary_and_material_validation(): void
    {
        $path = getenv('ENDPOINT_SCRIPT_UPLOAD_CORPUS') ?: dirname(__DIR__, 2).'/fixtures/SecurityDevices/script-upload-browser.json';
        $cases = json_decode(file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
        self::assertCount(2, $cases);
        $controller = new EndpointScriptCatalogueController;
        $bodyReader = new ReflectionMethod($controller, 'body');
        $binaryReader = new ReflectionMethod($controller, 'binary');
        $prepare = new ReflectionMethod(EndpointScripts::class, 'prepare');
        foreach ($cases as $case) {
            $request = Request::create('https://localhost/security-devices/scripts', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $case->request_json);
            $body = $bodyReader->invoke($controller, $request, 1900000);
            $artifact = $binaryReader->invoke($controller, $body->artifact_base64, 262144);
            $runtime = $binaryReader->invoke($controller, $body->runtime_manifest_base64, 1048576);
            [$manifest, $review] = $prepare->invoke(new EndpointScripts, EndpointScriptContract::encode($body->metadata), EndpointScriptContract::encode($body->review), $artifact, $runtime);
            self::assertSame($case->artifact, $artifact);
            self::assertSame($case->expected_manifest->sha256, hash('sha256', $artifact));
            self::assertSame($case->expected_manifest->runtime_manifest_sha256, hash('sha256', $runtime));
            self::assertSame(strlen($artifact), $manifest->size_bytes);
            $serverIdentity = clone $manifest;
            $serverIdentity->script_uuid = $case->expected_manifest->script_uuid;
            self::assertEquals($case->expected_manifest, $serverIdentity);
            self::assertEquals($case->request->review, $review);
            self::assertSame($case->request->artifact_base64, base64_encode($artifact));
            self::assertSame($case->request->runtime_manifest_base64, base64_encode($runtime));
        }
    }
}
