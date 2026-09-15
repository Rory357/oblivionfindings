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
        // "Variance" on its own is banned wording (vocabulary.md).
        $this->assertSame('Difference from budget: 2.5%', $normalized['content_sections'][0]['summary']);
        $this->assertSame('Finance summary', $normalized['content_sections'][0]['title']);
        $this->assertSame('Finance summary', $normalized['manifest_sections'][0]['title']);
    }

    public function test_it_uses_plain_section_names_and_counts(): void
    {
        $presenter = new BoardPackPresenter();

        $normalized = $presenter->normalizeManifest([
            'manifest_sections' => [
                ['id' => 'cover', 'title' => 'Cover & Meeting Overview', 'type' => 'auto', 'included' => true],
                ['id' => 'res_4', 'title' => 'Paper: Approve the 2026/27 budget', 'type' => 'paper', 'included' => true],
            ],
            'content_sections' => [
                'agenda' => [['title' => 'Welcome'], ['title' => 'Finance']],
                'resolutions' => ['items' => [['id' => 4, 'title' => 'Approve the 2026/27 budget']]],
                'ceo_report' => ['status' => 'submitted'],
            ],
        ]);

        $this->assertSame('Meeting details', $normalized['manifest_sections'][0]['title']);
        $this->assertSame('Approve the 2026/27 budget', $normalized['manifest_sections'][1]['title']);
        $this->assertSame('2 agenda items', $normalized['content_sections'][0]['summary']);
        $this->assertSame('Resolutions', $normalized['content_sections'][1]['title']);
        $this->assertSame('1 resolution', $normalized['content_sections'][1]['summary']);
        $this->assertSame('Waiting for the board', $normalized['content_sections'][2]['summary']);
    }
}
