<?php

namespace App\Domain\It\Services;

use DateTimeImmutable;
use DomainException;

/** Shared document requirements; saving an incomplete draft remains allowed. */
final class ItKnowledgeDocumentDefinition
{
    public const TAG_LIMIT = 12;

    public const FIELD_LABELS = [
        'symptoms' => 'Symptoms and when to use this document',
        'system_purpose' => 'Purpose and scope',
        'prerequisites' => 'Prerequisites and access',
        'procedure' => 'Procedure',
        'verification' => 'Verification',
        'rollback' => 'Rollback',
        'support_contact' => 'Support and escalation',
        'recovery_notes' => 'Recovery notes',
        'software_version' => 'Software version and supported releases',
        'deployment_notes' => 'Installation and deployment',
        'licensing_model' => 'Licensing model and entitlement ownership',
        'renewal_notes' => 'Renewal schedule and responsible owner',
        'hosting_notes' => 'Hosting and data location',
        'backup_notes' => 'Backup coverage and restore checks',
        'recovery_objectives' => 'Recovery time and data-loss objectives',
        'network_notes' => 'Network addressing and connectivity',
        'integration_notes' => 'Integrations and dependencies',
    ];

    private const TEMPLATES = [
        'guide' => ['label' => 'Guide', 'description' => 'Explain a routine task in clear steps.', 'required' => []],
        'runbook' => ['label' => 'Runbook', 'description' => 'Prepare, perform, verify and safely reverse an operational procedure.', 'required' => ['symptoms', 'prerequisites', 'procedure', 'verification', 'rollback', 'support_contact']],
        'system' => ['label' => 'System documentation', 'description' => 'Describe the system, its dependencies and how to recover it.', 'required' => ['system_purpose', 'hosting_notes', 'backup_notes', 'support_contact']],
        'software' => ['label' => 'Software documentation', 'description' => 'Record supported releases, deployment, verification and ownership.', 'required' => ['system_purpose', 'software_version', 'deployment_notes', 'verification', 'support_contact']],
        'network' => ['label' => 'Network documentation', 'description' => 'Document connectivity, dependencies and safe recovery.', 'required' => ['system_purpose', 'network_notes', 'verification', 'support_contact']],
        'troubleshooting' => ['label' => 'Troubleshooting', 'description' => 'Recognise symptoms, perform safe checks and escalate unresolved issues.', 'required' => ['symptoms', 'prerequisites', 'procedure', 'verification', 'support_contact']],
    ];

    public function templates(): array
    {
        $templates = [];
        foreach (self::TEMPLATES as $type => $definition) {
            $templates[] = ['type' => $type, 'version' => 1, ...$definition];
        }

        return $templates;
    }

    /** A tag is a short display label, never HTML or an automatically inferred secret. */
    public function normaliseTags(array $tags): array
    {
        if (count($tags) > self::TAG_LIMIT) {
            throw new DomainException('Choose up to 12 tags.');
        }
        $normalised = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                throw new DomainException('Each tag must be plain text.');
            }
            $tag = preg_replace('/\s+/u', ' ', trim($tag));
            if (! is_string($tag) || $tag === '' || mb_strlen($tag) > 40 || preg_match('/[\p{C}<>]/u', $tag)) {
                throw new DomainException('Use plain-text tags between 1 and 40 characters.');
            }
            $normalised[mb_strtolower($tag)] ??= $tag;
        }

        return array_values($normalised);
    }

    public function requiredSections(string $type): array
    {
        if (! isset(self::TEMPLATES[$type])) {
            throw new DomainException('Choose a supported document template.');
        }

        return self::TEMPLATES[$type]['required'];
    }

    /** Only document-owned facts appear here; inaccessible related records are never described. */
    public function issues(array $content, string $today): array
    {
        $issues = [];
        if (empty($content['owner_user_id'])) {
            $issues[] = ['code' => 'owner_missing', 'message' => 'Assign a document owner.', 'section' => 'ownership'];
        }
        $reviewDue = $content['review_due_at'] ?? null;
        if (! $reviewDue) {
            $issues[] = ['code' => 'review_unscheduled', 'message' => 'Set the next review date.', 'section' => 'ownership'];
        } elseif (substr((string) $reviewDue, 0, 10) < $today) {
            $issues[] = ['code' => 'review_overdue', 'message' => 'Review this document and record its next review date.', 'section' => 'ownership'];
        }
        $structured = $content['structured_content'] ?? [];
        foreach ($this->requiredSections($content['document_type'] ?? 'guide') as $field) {
            if (! is_string($structured[$field] ?? null) || trim($structured[$field]) === '') {
                $issues[] = ['code' => 'section_missing', 'field' => $field, 'message' => 'Complete '.self::FIELD_LABELS[$field].'.', 'section' => 'content'];
            }
        }

        return $issues;
    }

    public function assertReviewable(array $content): void
    {
        if (empty($content['owner_user_id'])) {
            throw new DomainException('Assign a documentation owner before sending this document for review.');
        }
        $missing = array_filter($this->issues($content, (new DateTimeImmutable)->format('Y-m-d')), fn (array $issue) => $issue['code'] === 'section_missing');
        if ($missing !== []) {
            throw new DomainException(implode(' ', array_column($missing, 'message')));
        }
    }
}
