<?php

it('keeps static secret material confined to non serializable requests and the Vault transport', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $request = file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Data/SecretWriteRequest.php');
    $referenceRequest = file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Data/SecretReferenceRequest.php');
    $versionRequest = file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Data/SecretVersionRequest.php');
    $result = file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Data/StoredSecretVersion.php');
    $store = file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Services/HashicorpVaultSecretStore.php');
    $contract = file_get_contents($root.'/app/Domain/SecurityDevices/Credentials/Contracts/SecretManagerSecretStore.php');
    $provider = file_get_contents($root.'/app/Providers/AppServiceProvider.php');

    expect($request)->toContain('#[\\SensitiveParameter] string $opaqueReference')
        ->toContain('#[\\SensitiveParameter] array $material')
        ->toContain('function __serialize(): array')
        ->toContain('function destroyMaterial(): void')
        ->and($referenceRequest)->toContain('function __serialize(): array')
        ->and($versionRequest)->toContain('function __serialize(): array')
        ->and($result)->toContain("'opaque_reference'", "'version'", "'fingerprint'")
        ->not->toContain("'material'", "'token'", "'secret'")
        ->and($store)->toContain("'options' => ['cas'", "'versions' =>")
        ->not->toContain('Log::', 'logger(', 'report(', 'Crypt::')
        ->and($contract)->toContain(
            'function put(',
            'function metadata(',
            'function softDelete(',
            'function restore(',
            'function destroy(',
        )
        ->and($provider)->toContain('SecretManagerSecretStore::class');
});
