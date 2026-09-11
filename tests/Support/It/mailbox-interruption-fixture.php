<?php

use App\Models\ItMailboxConnection;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** One synthetic message, shared by the killed worker and its isolated replacement. */
function configureMailboxInterruptionFixture(ItMailboxConnection $connection, string $sender, string $key, array &$calls): void
{
    if (getenv('APP_ENV') !== 'testing' || ! preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN'))
        || DB::connection()->getDatabaseName() !== 'oblivion_it_support_test_'.getenv('TEST_TOKEN')) {
        throw new RuntimeException('Mailbox interruption fixtures require the exact isolated schema.');
    }
    Notification::fake();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    app()->instance(MalwareScanner::class, new class extends MalwareScanner
    {
        public function scanPath(string $path, array $settings): MalwareScanResult
        {
            if (! is_file($path) || is_link($path)) {
                throw new RuntimeException('The interruption fixture requires a real private file.');
            }

            return new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic-interruption-scanner');
        }
    });
    $google = $connection->provider === 'google';
    $host = $google ? 'gmail.googleapis.com' : 'graph.microsoft.com';
    $base = $google ? '/gmail/v1/users/me/messages' : '/v1.0/users/support%40demo.test/messages';
    $detail = $base.'/'.$key;
    $bytes = 'Synthetic private interruption evidence '.$key;
    Http::fake(function ($request) use ($google, $host, $base, $detail, $key, $sender, $bytes, &$calls) {
        if (parse_url($request->url(), PHP_URL_HOST) !== $host || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider fixture escaped its host or ran inside a transaction.');
        }
        $path = parse_url($request->url(), PHP_URL_PATH);
        $calls[] = [$request->method(), $path];
        if (($google && $path === $detail.'/modify' && $request->method() === 'POST')
            || (! $google && $path === $detail && $request->method() === 'PATCH')) {
            return Http::response([]);
        }
        if ($request->method() !== 'GET') {
            throw new RuntimeException('Unexpected synthetic provider mutation.');
        }
        if ($path === ($google ? $base : '/v1.0/users/support%40demo.test/mailFolders/inbox/messages')) {
            return Http::response($google ? ['messages' => [['id' => $key]]] : ['value' => [['id' => $key]]]);
        }
        $file = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'evidence', 'name' => 'evidence.txt',
            'contentType' => 'text/plain', 'size' => strlen($bytes), 'isInline' => false];
        if ($path === $detail.'/attachments') {
            return Http::response(['value' => [$file]]);
        }
        if ($path === $detail.'/attachments/evidence') {
            return Http::response($google ? ['size' => strlen($bytes), 'data' => base64_encode($bytes)] : [...$file, 'contentBytes' => base64_encode($bytes)]);
        }
        if ($path === $detail) {
            $headers = [['name' => 'From', 'value' => $sender], ['name' => 'Subject', 'value' => $key], ['name' => 'Message-ID', 'value' => '<'.$key.'@demo.test>']];

            return Http::response($google ? ['id' => $key, 'payload' => [
                'mimeType' => 'multipart/mixed', 'headers' => $headers, 'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => base64_encode('Synthetic interruption report'), 'size' => 29]],
                    ['mimeType' => 'text/plain', 'filename' => 'evidence.txt', 'body' => ['attachmentId' => 'evidence', 'size' => strlen($bytes)]],
                ],
            ]] : ['id' => $key, 'subject' => $key, 'from' => ['emailAddress' => ['address' => $sender]],
                'internetMessageId' => '<'.$key.'@demo.test>', 'internetMessageHeaders' => $headers,
                'body' => ['contentType' => 'text', 'content' => 'Synthetic interruption report']]);
        }
        throw new RuntimeException('Unexpected synthetic provider path.');
    });
}
