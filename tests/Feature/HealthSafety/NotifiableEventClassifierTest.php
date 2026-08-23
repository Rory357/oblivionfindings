<?php

namespace Tests\Feature\HealthSafety;

use App\Services\HealthSafety\NotifiableEventClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotifiableEventClassifierTest extends TestCase
{
    private NotifiableEventClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new NotifiableEventClassifier;
    }

    public function test_work_related_death_is_a_notifiable_death(): void
    {
        $result = $this->classifier->assess(array_replace(
            $this->completeNegativeAnswers(),
            ['death' => true],
        ));

        $this->assertSame(NotifiableEventClassifier::STATUS_NOTIFIABLE, $result['status']);
        $this->assertTrue($result['notifiable']);
        $this->assertFalse($result['needs_review']);
        $this->assertSame(NotifiableEventClassifier::CATEGORY_DEATH, $result['category']);
        $this->assertSame('worksafe', $result['authority']);
    }

    public function test_immediate_inpatient_admission_is_a_notifiable_injury_or_illness(): void
    {
        $result = $this->classifier->assess(array_replace(
            $this->completeNegativeAnswers(),
            ['hospital_admission' => true],
        ));

        $this->assertTrue($result['notifiable']);
        $this->assertSame(NotifiableEventClassifier::CATEGORY_INJURY_OR_ILLNESS, $result['category']);
    }

    #[DataProvider('specifiedInjuryOrIllnessProvider')]
    public function test_each_specified_injury_or_illness_is_notifiable(string $trigger): void
    {
        $result = $this->classifier->assess(array_replace(
            $this->completeNegativeAnswers(),
            [
                'specified_injury_or_illness' => $trigger,
                'regulation_reference' => 'Applicable regulation and provision',
            ],
        ));

        $this->assertSame(NotifiableEventClassifier::STATUS_NOTIFIABLE, $result['status']);
        $this->assertTrue($result['notifiable']);
        $this->assertSame(NotifiableEventClassifier::CATEGORY_INJURY_OR_ILLNESS, $result['category']);
    }

    public static function specifiedInjuryOrIllnessProvider(): array
    {
        return array_combine(
            NotifiableEventClassifier::SPECIFIED_INJURY_OR_ILLNESS,
            array_map(
                static fn (string $trigger): array => [$trigger],
                NotifiableEventClassifier::SPECIFIED_INJURY_OR_ILLNESS,
            ),
        );
    }

    #[DataProvider('dangerousIncidentProvider')]
    public function test_each_dangerous_incident_is_notifiable_only_with_both_thresholds(string $trigger): void
    {
        $result = $this->classifier->assess(array_replace(
            $this->completeNegativeAnswers(),
            [
                'dangerous_incident' => $trigger,
                'regulation_reference' => 'Applicable regulation and provision',
                'unplanned_or_uncontrolled' => true,
                'serious_risk_from_immediate_or_imminent_exposure' => true,
            ],
        ));

        $this->assertSame(NotifiableEventClassifier::STATUS_NOTIFIABLE, $result['status']);
        $this->assertTrue($result['notifiable']);
        $this->assertSame(NotifiableEventClassifier::CATEGORY_INCIDENT, $result['category']);
    }

    public static function dangerousIncidentProvider(): array
    {
        return array_combine(
            NotifiableEventClassifier::DANGEROUS_INCIDENTS,
            array_map(
                static fn (string $trigger): array => [$trigger],
                NotifiableEventClassifier::DANGEROUS_INCIDENTS,
            ),
        );
    }

    public function test_non_work_related_event_is_not_notifiable_even_when_the_harm_is_serious(): void
    {
        $result = $this->classifier->assess([
            'work_related' => false,
            'death' => true,
            'hospital_admission' => true,
        ]);

        $this->assertSame(NotifiableEventClassifier::STATUS_NOT_NOTIFIABLE, $result['status']);
        $this->assertFalse($result['notifiable']);
        $this->assertNull($result['category']);
    }

    #[DataProvider('dangerousIncidentThresholdFailureProvider')]
    public function test_dangerous_incident_requires_unplanned_and_serious_risk(
        bool $unplanned,
        bool $seriousRisk,
    ): void {
        $result = $this->classifier->assess(array_replace(
            $this->completeNegativeAnswers(),
            [
                'dangerous_incident' => NotifiableEventClassifier::INCIDENT_EXPLOSION_OR_FIRE,
                'unplanned_or_uncontrolled' => $unplanned,
                'serious_risk_from_immediate_or_imminent_exposure' => $seriousRisk,
            ],
        ));

        $this->assertSame(NotifiableEventClassifier::STATUS_NOT_NOTIFIABLE, $result['status']);
        $this->assertFalse($result['notifiable']);
    }

    public static function dangerousIncidentThresholdFailureProvider(): array
    {
        return [
            'controlled incident' => [false, true],
            'no serious risk' => [true, false],
            'neither threshold' => [false, false],
        ];
    }

    public function test_complete_explicit_negative_matrix_is_not_notifiable(): void
    {
        $result = $this->classifier->assess($this->completeNegativeAnswers());

        $this->assertSame(NotifiableEventClassifier::STATUS_NOT_NOTIFIABLE, $result['status']);
        $this->assertFalse($result['notifiable']);
        $this->assertFalse($result['needs_review']);
        $this->assertNull($result['category']);
    }

    #[DataProvider('uncertainAssessmentProvider')]
    public function test_incomplete_or_uncertain_answers_require_qualified_review(array $answers): void
    {
        $result = $this->classifier->assess($answers);

        $this->assertSame(NotifiableEventClassifier::STATUS_NEEDS_REVIEW, $result['status']);
        $this->assertNull($result['notifiable']);
        $this->assertTrue($result['needs_review']);
        $this->assertNull($result['category']);
    }

    public static function uncertainAssessmentProvider(): array
    {
        $negative = [
            'work_related' => true,
            'death' => false,
            'hospital_admission' => false,
            'specified_injury_or_illness' => NotifiableEventClassifier::ANSWER_NONE,
            'dangerous_incident' => NotifiableEventClassifier::ANSWER_NONE,
        ];

        return [
            'work relatedness omitted' => [array_replace($negative, ['work_related' => null])],
            'death unanswered' => [array_replace($negative, ['death' => null])],
            'hospital admission unanswered' => [array_replace($negative, ['hospital_admission' => null])],
            'injury or illness unsure' => [array_replace($negative, [
                'specified_injury_or_illness' => NotifiableEventClassifier::ANSWER_UNSURE,
            ])],
            'dangerous incident unsure' => [array_replace($negative, [
                'dangerous_incident' => NotifiableEventClassifier::ANSWER_UNSURE,
            ])],
            'dangerous incident risk unanswered' => [array_replace($negative, [
                'dangerous_incident' => NotifiableEventClassifier::INCIDENT_SUBSTANCE_ESCAPE,
                'unplanned_or_uncontrolled' => true,
                'serious_risk_from_immediate_or_imminent_exposure' => null,
            ])],
            'unrecognised category' => [array_replace($negative, [
                'specified_injury_or_illness' => 'generic_critical',
            ])],
            'regulation-declared injury without regulation' => [array_replace($negative, [
                'specified_injury_or_illness' => NotifiableEventClassifier::INJURY_OR_ILLNESS_DECLARED_BY_REGULATION,
            ])],
            'regulation-declared incident without regulation' => [array_replace($negative, [
                'dangerous_incident' => NotifiableEventClassifier::INCIDENT_DECLARED_BY_REGULATION,
                'unplanned_or_uncontrolled' => true,
                'serious_risk_from_immediate_or_imminent_exposure' => true,
            ])],
        ];
    }

    public function test_reduced_critical_severity_is_needs_review_not_an_incident_classification(): void
    {
        $result = $this->classifier->classify('medical', 'critical');

        $this->assertSame(NotifiableEventClassifier::STATUS_NEEDS_REVIEW, $result['status']);
        $this->assertNull($result['notifiable']);
        $this->assertNull($result['category']);
        $this->assertFalse($this->classifier->isNotifiable('medical', 'critical'));
    }

    #[DataProvider('reducedNegativeProvider')]
    public function test_reduced_negative_inputs_never_emit_a_definitive_below_threshold(
        ?string $harm,
        ?string $severity,
    ): void {
        $result = $this->classifier->classify($harm, $severity);

        $this->assertSame(NotifiableEventClassifier::STATUS_NEEDS_REVIEW, $result['status']);
        $this->assertNull($result['notifiable']);
    }

    public static function reducedNegativeProvider(): array
    {
        return [
            'first aid and minor' => ['first_aid', 'minor'],
            'medical and serious' => ['medical', 'serious'],
            'none and moderate' => ['none', 'moderate'],
            'missing values' => [null, null],
        ];
    }

    public function test_exact_legacy_death_and_hospital_triggers_still_escalate_case_insensitively(): void
    {
        $this->assertTrue($this->classifier->isNotifiable('Death', 'Minor'));
        $this->assertTrue($this->classifier->isNotifiable('HOSPITALISATION', null));
        $this->assertFalse($this->classifier->isNotifiable('Medical', 'Serious'));
    }

    public function test_every_result_records_the_approved_version_and_source_dates(): void
    {
        $result = $this->classifier->assess($this->completeNegativeAnswers());
        $policy = $this->classifier->policy();

        $this->assertSame(NotifiableEventClassifier::DECISION_TREE_VERSION, $result['decision_tree_version']);
        $this->assertSame(NotifiableEventClassifier::SOURCE_EFFECTIVE_DATE, $result['source_effective_date']);
        $this->assertSame(NotifiableEventClassifier::SOURCE_REVIEWED_DATE, $result['source_reviewed_date']);
        $this->assertSame(NotifiableEventClassifier::CONTENT_OWNER, $result['content_owner']);
        $this->assertSame(NotifiableEventClassifier::SOURCE_URL, $policy['source_url']);
        $this->assertSame(
            NotifiableEventClassifier::NEXT_MANDATORY_REVIEW_DATE,
            $policy['next_mandatory_review_date'],
        );
        $this->assertSame(NotifiableEventClassifier::SPECIFIED_INJURY_OR_ILLNESS, $policy['specified_injury_or_illness']);
        $this->assertSame(NotifiableEventClassifier::DANGEROUS_INCIDENTS, $policy['dangerous_incidents']);
        $this->assertCount(11, array_unique($policy['specified_injury_or_illness']));
        $this->assertCount(11, $policy['specified_injury_or_illness_labels']);
        $this->assertCount(13, array_unique($policy['dangerous_incidents']));
        $this->assertCount(13, $policy['dangerous_incident_labels']);
    }

    /** @return array<string, bool|string> */
    private function completeNegativeAnswers(): array
    {
        return [
            'work_related' => true,
            'death' => false,
            'hospital_admission' => false,
            'specified_injury_or_illness' => NotifiableEventClassifier::ANSWER_NONE,
            'dangerous_incident' => NotifiableEventClassifier::ANSWER_NONE,
        ];
    }
}
