<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubPdfText;
use Dompdf\Dompdf;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

function extractHubPdfFixture(string $bytes): string
{
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M', dirname(__DIR__, 3).'/app/Domain/CompanyHub/Support/extract-pdf-text.php']);
    $process->setInput($bytes);
    $process->setTimeout(8);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

test('bounded PDF extraction reads real compressed text without application or database bootstrap', function () {
    $pdf = new Dompdf;
    $pdf->loadHtml('<h1>Meridian procedure</h1><p>Follow the calm handover sequence.</p>');
    $pdf->render();

    expect(extractHubPdfFixture($pdf->output()))->toContain('Meridian procedure', 'calm handover sequence');
});

test('bounded PDF extraction gives corrupt and oversized files no invented searchable text', function () {
    expect(extractHubPdfFixture('%PDF-1.7 corrupted input'))->toBe('')
        ->and(extractHubPdfFixture('%PDF-1.7'.str_repeat('x', 10 * 1024 * 1024)))->toBe('');
});

test('bounded PDF extraction leaves a PDF without text empty instead of promising OCR', function () {
    $pdf = new Dompdf;
    $pdf->loadHtml('<div style="width:100px;height:100px;background:#222"></div>');
    $pdf->render();

    expect(extractHubPdfFixture($pdf->output()))->toBe('');
});

test('bounded PDF extraction does not bypass PDF encryption', function () {
    $pdf = new Dompdf;
    $pdf->loadHtml('<p>Password protected procedure.</p>');
    $pdf->render();
    $pdf->getCanvas()->get_cpdf()->setEncryption('reader-password', 'owner-password');

    expect(extractHubPdfFixture($pdf->output()))->toBe('');
});

test('a spent search budget reports incomplete cold PDF coverage while cached text remains searchable', function () {
    $originalApplication = Facade::getFacadeApplication();
    Facade::setFacadeApplication(new Container);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('readStream')->twice()->andReturnUsing(function () {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '%PDF-1.7 bounded fixture');
        rewind($stream);

        return $stream;
    });
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('get')->once()->andReturn(null);
    $store->shouldReceive('get')->once()->andReturn('Cached Meridian procedure');
    $files = Mockery::mock();
    $files->shouldReceive('disk')->with('private')->twice()->andReturn($disk);
    $cache = Mockery::mock();
    $cache->shouldReceive('store')->with('file')->twice()->andReturn($store);
    Storage::swap($files);
    Cache::swap($cache);
    $media = new HubMedia;
    $media->setRawAttributes(['mime' => 'application/pdf', 'path' => 'private.pdf']);
    try {
        $cold = new HubPdfText;
        (new ReflectionProperty($cold, 'deadline'))->setValue($cold, microtime(true) - 1);
        expect($cold->text($media))->toBe('')->and($cold->incomplete())->toBeTrue();
        $cached = new HubPdfText;
        (new ReflectionProperty($cached, 'deadline'))->setValue($cached, microtime(true) - 1);
        expect($cached->text($media))->toBe('Cached Meridian procedure')->and($cached->incomplete())->toBeFalse();
    } finally {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($originalApplication);
    }
});
