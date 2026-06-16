<?php

namespace Tests\Feature\HealthSafety;

use App\Services\HealthSafety\NotifiableEventClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * G2 — WorkSafe NZ notifiable-event classifier (HSWA 2015 ss.23–25). Pure logic, no DB.
 */
class NotifiableEventClassifierTest extends TestCase
{
    private NotifiableEventClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new NotifiableEventClassifier;
    }

    public function test_death_is_a_notifiable_death(): void
    {
        $result = $this->classifier->classify('death', 'serious');

        $this->assertTrue($result['notifiable']);
        $this->assertEquals(NotifiableEventClassifier::CATEGORY_DEATH, $result['category']);
        $this->assertEquals('worksafe', $result['authority']);
        $this->assertNotEmpty($result['reason']);
    }

    public function test_hospitalisation_is_a_notifiable_injury_or_illness(): void
    {
        $result = $this->classifier->classify('hospitalisation', 'moderate');

        $this->assertTrue($result['notifiable']);
        $this->assertEquals(NotifiableEventClassifier::CATEGORY_INJURY_OR_ILLNESS, $result['category']);
    }

    public function test_critical_severity_is_a_notifiable_incident(): void
    {
        $result = $this->classifier->classify('medical', 'critical');

        $this->assertTrue($result['notifiable']);
        $this->assertEquals(NotifiableEventClassifier::CATEGORY_INCIDENT, $result['category']);
    }

    public function test_death_takes_precedence_over_critical_severity(): void
    {
        $result = $this->classifier->classify('death', 'critical');

        $this->assertEquals(NotifiableEventClassifier::CATEGORY_DEATH, $result['category']);
    }

    #[DataProvider('nonNotifiableProvider')]
    public function test_below_threshold_is_not_notifiable(?string $harm, ?string $severity): void
    {
        $result = $this->classifier->classify($harm, $severity);

        $this->assertFalse($result['notifiable']);
        $this->assertNull($result['category']);
        $this->assertStringContainsString('5 years', $result['reason']);
    }

    public static function nonNotifiableProvider(): array
    {
        return [
            'first aid + minor' => ['first_aid', 'minor'],
            'medical + serious (not critical, not hospitalised)' => ['medical', 'serious'],
            'none + moderate' => ['none', 'moderate'],
            'null harm + null severity' => [null, null],
        ];
    }

    public function test_classification_is_case_insensitive(): void
    {
        $this->assertTrue($this->classifier->isNotifiable('Death', 'Minor'));
        $this->assertTrue($this->classifier->isNotifiable('HOSPITALISATION', null));
        $this->assertTrue($this->classifier->isNotifiable('none', 'Critical'));
        $this->assertFalse($this->classifier->isNotifiable('Medical', 'Serious'));
    }

    public function test_is_notifiable_convenience_matches_classify(): void
    {
        $this->assertTrue($this->classifier->isNotifiable('death', null));
        $this->assertFalse($this->classifier->isNotifiable('first_aid', 'minor'));
    }
}
