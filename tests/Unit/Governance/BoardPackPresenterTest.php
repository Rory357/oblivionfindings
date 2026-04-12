<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Support\BoardPackPresenter;
use Tests\TestCase;

class BoardPackPresenterTest extends TestCase
{
    public function test_it_normalizes_legacy_mixed_manifest_shape(): void
    {
        $presenter = new BoardPackPresenter();

        $normalized = $presenter->normalizeManifest([
            ['id' => 'cover', 'title' => 'Cover & Meeting Overview', 'type' => 'auto', 'included' => true],
            ['id' => 'agenda', 'title' => 'Agenda', 'type' => 'auto', 'included' => true],
            'content' => [
                'cover' => ['type' => 'Full Board Meeting', 'date' => '2026-04-12'],
                'agenda' => [['title' => 'Opening karakia']],
            ],
        ]);

        $this->assertCount(2, $normalized['manifest_sections']);
        $this->assertSame('cover', $normalized['manifest_sections'][0]['id']);
        $this->assertCount(2, $normalized['content_sections']);
        $this->assertSame('cover', $normalized['content_sections'][0]['key']);
        $this->assertSame('agenda', $normalized['content_sections'][1]['key']);
    }

    public function test_it_normalizes_new_manifest_and_content_section_shape(): void
    {
        $presenter = new BoardPackPresenter();

        $normalized = $presenter->normalizeManifest([
            'manifest_sections' => [
                ['id' => 'finance_report', 'title' => 'Financial Summary', 'type' => 'auto', 'included' => true],
            ],
            'content_sections' => [
                'finance_report' => ['variance' => '2.5%'],
            ],
        ]);

        $this->assertCount(1, $normalized['manifest_sections']);
        $this->assertSame('finance_report', $normalized['manifest_sections'][0]['id']);
        $this->assertCount(1, $normalized['content_sections']);
        $this->assertSame('finance_report', $normalized['content_sections'][0]['key']);
        $this->assertSame('Variance 2.5%', $normalized['content_sections'][0]['summary']);
    }
}
