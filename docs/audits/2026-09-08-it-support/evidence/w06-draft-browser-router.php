<?php

/** Only the explicitly launched local verification PHP server uses this router. */

require __DIR__.'/w06-draft-browser-runtime.php';
try {
    $context = w06BrowserGuard(true);
    w06BrowserRequire(PHP_SAPI === 'cli-server' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
        && ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1:8766', 'Unexpected server host or client.');
    $public = realpath(W06_BROWSER_CHECKOUT.'/public');
    w06BrowserRequire($public !== false && ! is_file($public.'/hot')
        && ! is_file(W06_BROWSER_CHECKOUT.'/storage/framework/maintenance.php')
        && hash_file('sha256', $public.'/build/manifest.json') === $context['asset_manifest_sha256'], 'Checkout assets or maintenance state changed; review before resuming.');
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    w06BrowserRequire(! str_contains($path, "\0") && ! str_contains($path, '\\') && ! preg_match('~(?:^|/)\.\.(?:/|$)~', $path), 'Invalid public path.');
    $file = realpath($public.$path);
    if ($file !== false && is_file($file) && str_starts_with(w06BrowserPath($file), w06BrowserPath($public).'/')) {
        // Serve only static assets. PHP and storage symlinks never bypass guards.
        w06BrowserRequire(! is_link($public.$path) && preg_match('/\.(?:js|css|map|ico|png|jpe?g|gif|svg|webp|woff2?|ttf|txt)$/i', $file) === 1, 'This file is not an allowlisted static asset.');

        return false;
    }
    define('LARAVEL_START', microtime(true));
    $app = w06BrowserApplication($context);
    if ($path === '/__it-draft-verification') {
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        header('Content-Type: application/json');
        header('Cache-Control: no-store, private');
        echo json_encode(['checkout' => W06_BROWSER_CHECKOUT, 'database' => \Illuminate\Support\Facades\DB::scalar('SELECT DATABASE()'),
            'token' => $context['token'], 'storage_root' => $context['storage'], 'app_environment' => app()->environment(),
            'csrf_testing_bypass' => app()->runningUnitTests(), 'asset_manifest_sha256' => $context['asset_manifest_sha256'],
            'draft_recovery_enabled' => app(\App\Domain\It\Services\ItTicketDraftService::class)->enabled(),
            'retention_values_are_synthetic' => true, 'mail_driver' => config('mail.default'), 'queue_driver' => config('queue.default'),
            'session_cookie' => config('session.cookie'),
            'mailbox_provider_fixtures' => (bool) ($context['mailbox_fixtures'] ?? false),
            'synthetic_delivery_outcomes' => (bool) ($context['mailbox_fixtures'] ?? false),
            'synthetic_api_fixtures' => (bool) ($context['api_fixtures'] ?? false),
            'ssr_enabled' => config('inertia.ssr.enabled'),
            'php_upload_limits' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'), 'max_file_uploads' => (int) ini_get('max_file_uploads')]], JSON_THROW_ON_ERROR);

        return;
    }
    $app->handleRequest(\Illuminate\Http\Request::capture());
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private');
    echo '{"code":"isolated_verification_unavailable","message":"The isolated verification guard failed. Inspect its owned local evidence."}';
}
