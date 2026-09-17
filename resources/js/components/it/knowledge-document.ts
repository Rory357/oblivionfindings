export const KNOWLEDGE_DOCUMENT_TYPES = [
    { value: 'guide', label: 'Guide' },
    { value: 'runbook', label: 'Runbook' },
    { value: 'system', label: 'System documentation' },
    { value: 'software', label: 'Software documentation' },
    { value: 'network', label: 'Network documentation' },
    { value: 'troubleshooting', label: 'Troubleshooting' },
];

export const KNOWLEDGE_SECTIONS = [
    {
        key: 'symptoms',
        label: 'Symptoms and when to use this document',
        types: ['runbook', 'troubleshooting'],
    },
    { key: 'system_purpose', label: 'Purpose and scope' },
    { key: 'prerequisites', label: 'Prerequisites and access' },
    { key: 'procedure', label: 'Procedure' },
    { key: 'verification', label: 'Verification' },
    { key: 'rollback', label: 'Rollback' },
    { key: 'support_contact', label: 'Support and escalation' },
    { key: 'recovery_notes', label: 'Recovery notes' },
    {
        key: 'software_version',
        label: 'Software version and supported releases',
        types: ['software'],
    },
    {
        key: 'deployment_notes',
        label: 'Installation and deployment',
        types: ['software'],
    },
    {
        key: 'licensing_model',
        label: 'Licensing model and entitlement ownership',
        types: ['software'],
    },
    {
        key: 'renewal_notes',
        label: 'Renewal schedule and responsible owner',
        types: ['software'],
    },
    {
        key: 'hosting_notes',
        label: 'Hosting and data location',
        types: ['system', 'software'],
    },
    {
        key: 'backup_notes',
        label: 'Backup coverage and restore checks',
        types: ['system', 'software'],
    },
    {
        key: 'recovery_objectives',
        label: 'Recovery time and data-loss objectives',
        types: ['system', 'software', 'runbook'],
    },
    {
        key: 'network_notes',
        label: 'Network addressing and connectivity',
        types: ['system', 'network'],
    },
    {
        key: 'integration_notes',
        label: 'Integrations and dependencies',
        types: ['system', 'software', 'network'],
    },
] as const;

export const knowledgeSectionsFor = (documentType: string) =>
    KNOWLEDGE_SECTIONS.filter(
        (field) =>
            !('types' in field) ||
            (field.types as readonly string[]).includes(documentType),
    );
