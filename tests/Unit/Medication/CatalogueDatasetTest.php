<?php

namespace Tests\Unit\Medication;

use App\Services\Medication\MedicineCatalogue\CatalogueDataset;
use Illuminate\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogueDatasetTest extends TestCase
{
    private $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $translator = new Translator(new ArrayLoader, 'en');
        $container->instance('validator', new Factory($translator));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    private function product(): array
    {
        return ['code_system' => 'nzulm', 'code' => 'FICT-001', 'name' => 'Fictional medicine', 'strength' => '10 mg', 'form' => 'tablet'];
    }

    public function test_exact_identity_normalizes_only_space_and_case(): void
    {
        $dataset = new CatalogueDataset;
        $product = $this->product();
        $normalized = $product;
        $normalized['name'] = '  FICTIONAL   medicine  ';
        $this->assertSame($dataset->identity($product), $dataset->identity($normalized));
        foreach (['code_system', 'code', 'name', 'strength', 'form'] as $field) {
            $other = $product;
            $other[$field] .= '-different';
            $this->assertNotSame($dataset->identity($product), $dataset->identity($other));
        }
    }

    #[DataProvider('invalidDatasets')]
    public function test_dataset_rejects_unbounded_or_ambiguous_input(string $input): void
    {
        $this->expectException(ValidationException::class);
        (new CatalogueDataset)->parse($input);
    }

    public static function invalidDatasets(): array
    {
        $product = ['code_system' => 'nzulm', 'code' => 'X', 'name' => 'Fictional', 'strength' => '10 mg', 'form' => 'tablet'];

        return [
            'invalid JSON' => ['{'], 'not list' => [json_encode(['product' => $product])], 'empty' => ['[]'],
            'unknown remote image field' => [json_encode([$product + ['url' => 'https://example.test/image']])],
            'missing strength' => [json_encode([array_diff_key($product, ['strength' => true])])],
            'blank form' => [json_encode([array_replace($product, ['form' => ' '])])],
            'control character' => [json_encode([array_replace($product, ['name' => "Fictional\nmedicine"])])],
            'duplicate product' => [json_encode([$product, $product])],
            'too many products' => [json_encode(array_fill(0, 1001, $product))],
            'too many bytes' => [str_repeat(' ', CatalogueDataset::MAX_BYTES + 1)],
        ];
    }

    public function test_bounded_dataset_retains_exact_source_strength_and_hash(): void
    {
        $rows = (new CatalogueDataset)->parse(json_encode([$this->product()]));
        $this->assertCount(1, $rows);
        $this->assertSame('10 mg', $rows[0]['strength']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rows[0]['identity_sha256']);
    }

    public function test_svg_or_mislabelled_bytes_are_not_a_medicine_photo(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'emar-image-');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        try {
            $file = new UploadedFile($path, 'medicine.png', 'image/png', null, true);
            $this->expectException(ValidationException::class);
            (new CatalogueDataset)->image($file);
        } finally {
            unlink($path);
        }
    }
}
