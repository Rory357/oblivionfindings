<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use Illuminate\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->previousFacadeApplication = Facade::getFacadeApplication();
    $container = new Container;
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $this->library = new HubMediaLibrary(Mockery::mock(HubAccess::class));
    $this->paths = [];
    $this->package = function (string $extension, array $extra = [], ?string $typesOverride = null): UploadedFile {
        $parts = ['docx' => ['word/document.xml', 'wordprocessingml.document'], 'xlsx' => ['xl/workbook.xml', 'spreadsheetml.sheet'], 'pptx' => ['ppt/presentation.xml', 'presentationml.presentation']];
        $path = tempnam(sys_get_temp_dir(), 'hub-document-unit-');
        $this->paths[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', $typesOverride ?? '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/'.$parts[$extension][0].'" ContentType="application/vnd.openxmlformats-officedocument.'.$parts[$extension][1].'.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships/>');
        $zip->addFromString($parts[$extension][0], '<document/>');
        foreach ($extra as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        return new UploadedFile($path, 'Work document.'.$extension, null, null, true);
    };
});

afterEach(function () {
    foreach ($this->paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousFacadeApplication);
    Mockery::close();
});

test('document uploads validate each macro free Office package and canonical MIME without requiring a database', function (string $extension) {
    $mime = $this->library->uploadMime(($this->package)($extension));
    expect(HubMediaLibrary::MIME_EXTENSIONS[$mime])->toBe($extension)->and(HubMediaLibrary::previewable($mime))->toBeFalse();
})->with(['docx', 'xlsx', 'pptx']);

test('document uploads reject executable encrypted or unsafe Office package structures', function (array $extra, ?string $types) {
    expect(fn () => $this->library->uploadMime(($this->package)('docx', $extra, $types)))->toThrow(ValidationException::class);
})->with([
    'macro' => [['word/vbaProject.bin' => 'macro'], null],
    'embedded program' => [['word/embeddings/program.bin' => 'program'], null],
    'path traversal' => [['../program.exe' => 'program'], null],
    'external entity declaration' => [[], '<!DOCTYPE Types [<!ENTITY source SYSTEM "file:///private">]><Types/>'],
    'wrong content type' => [[], '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'],
]);

test('document uploads retain actual PDF validation and reject disguised programs and oversized files', function () {
    expect($this->library->uploadMime(UploadedFile::fake()->createWithContent('Guide.pdf', "%PDF-1.7\n% a work document\n")))->toBe('application/pdf');
    $path = tempnam(sys_get_temp_dir(), 'hub-document-unit-');
    $this->paths[] = $path;
    file_put_contents($path, '<html>not a PDF</html>');
    $disguised = new UploadedFile($path, 'Guide.pdf', null, null, true);
    expect(fn () => $this->library->uploadMime($disguised))->toThrow(ValidationException::class);
    expect(fn () => $this->library->uploadMime(UploadedFile::fake()->create('Large.pdf', 10241, 'application/pdf')))->toThrow(ValidationException::class);
});

test('legacy document identities and file edition numbers remain safe before optional schema activation', function () {
    $legacy = (new HubMedia)->setRawAttributes(['id' => 12]);
    $replacement = (new HubMedia)->setRawAttributes(['id' => 25, 'document_root_id' => 12, 'document_sequence' => 3]);
    expect($legacy->documentId())->toBe(12)->and($legacy->documentSequence())->toBe(1)
        ->and($replacement->documentId())->toBe(12)->and($replacement->documentSequence())->toBe(3)
        ->and(HubMediaLibrary::previewable('application/pdf'))->toBeTrue()
        ->and(HubMediaLibrary::previewable('text/html'))->toBeFalse();
});
