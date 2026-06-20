/**
 * Privacy command-centre — the five create wizards as data.
 *
 * Each config drives the shared PrivacyWizard engine. Field names + option
 * values match the backend store validation exactly (FE = BE). NZ/IPP framing.
 */
import {
    ASSESSMENT_TYPE_TILES,
    BREACH_DATA_CATEGORIES,
    DPIA_DATA_TYPES,
    DPIA_SUBJECTS,
    HOLD_TYPE_TILES,
    REQUEST_TYPE_TILES,
    RISK_TILES,
    VERIFICATION_METHODS,
} from '@/pages/privacy/privacy-shared';
import { type PrivacyWizardConfig } from '@/components/privacy/privacy-wizard';
import {
    AlertTriangle,
    Calendar,
    Clock,
    Fingerprint,
    FileText,
    ListChecks,
    Lock,
    Scale,
    ShieldAlert,
    ShieldCheck,
    Users,
} from 'lucide-react';

export type PrivacyWizardDomain = 'request' | 'breach' | 'hold' | 'retention' | 'dpia';

const today = (): string => new Date().toLocaleDateString('en-CA'); // local YYYY-MM-DD

const splitLines = (v: unknown): string[] =>
    typeof v === 'string'
        ? v.split(/\r?\n/).map((s) => s.trim()).filter(Boolean)
        : Array.isArray(v)
          ? (v as string[])
          : [];

export function getPrivacyWizardConfig(domain: PrivacyWizardDomain): PrivacyWizardConfig {
    switch (domain) {
        case 'breach':
            return {
                domain,
                railIcon: AlertTriangle,
                railTitle: 'Log data breach',
                railSub: 'Notifiable breach',
                storeUrl: '/privacy/breaches',
                verb: 'Log breach',
                successTitle: 'Breach logged!',
                successBlurb: 'The breach was recorded. If serious harm is likely, notify the Privacy Commissioner (OPC) as soon as practicable.',
                initial: {
                    nature_of_breach: '',
                    discovered_at: today(),
                    approximate_individuals_affected: '',
                    affected_data_categories: [],
                    likely_consequences: '',
                    measures_taken: '',
                    requires_authority_notification: false,
                    requires_subject_notification: false,
                },
                steps: [
                    {
                        key: 'what',
                        label: 'What happened',
                        blurb: 'Nature & discovery',
                        icon: AlertTriangle,
                        headTitle: 'What happened?',
                        headBlurb: 'Describe the breach and when it was discovered.',
                        fields: [
                            { type: 'textarea', name: 'nature_of_breach', label: 'Nature of breach', required: true, placeholder: 'Describe what happened…' },
                            { type: 'date', name: 'discovered_at', label: 'Discovered at', required: true, span: true },
                        ],
                    },
                    {
                        key: 'impact',
                        label: 'Impact',
                        blurb: 'Who & what is affected',
                        icon: Users,
                        headTitle: 'Who and what is affected?',
                        headBlurb: 'The scale and the categories of information involved.',
                        fields: [
                            { type: 'number', name: 'approximate_individuals_affected', label: 'Approx. individuals affected', placeholder: '0' },
                            { type: 'chips', name: 'affected_data_categories', label: 'Affected data categories', options: BREACH_DATA_CATEGORIES },
                            { type: 'textarea', name: 'likely_consequences', label: 'Likely consequences', placeholder: 'Risk of harm to affected individuals…' },
                        ],
                    },
                    {
                        key: 'response',
                        label: 'Response',
                        blurb: 'Containment & notification',
                        icon: ShieldCheck,
                        headTitle: 'Containment & notification',
                        headBlurb: 'What you have done, and whether notification is required.',
                        fields: [
                            { type: 'textarea', name: 'measures_taken', label: 'Measures taken', placeholder: 'Containment & remediation steps…' },
                            {
                                type: 'info',
                                icon: ShieldAlert,
                                tone: 'warn',
                                text: 'If the breach is likely to cause serious harm it is notifiable — notify the Privacy Commissioner (OPC) via NotifyUs as soon as practicable, and tell the affected individuals.',
                            },
                            { type: 'toggle', name: 'requires_authority_notification', label: 'OPC-notifiable', placeholder: 'Serious harm likely — notify the Privacy Commissioner', span: true, reviewLabel: 'OPC notification' },
                            { type: 'toggle', name: 'requires_subject_notification', label: 'Notify affected individuals', placeholder: 'Affected individuals must be told', span: true, reviewLabel: 'Subject notification' },
                        ],
                    },
                ],
            };

        case 'hold':
            return {
                domain,
                railIcon: Scale,
                railTitle: 'New legal hold',
                railSub: 'Preservation order',
                storeUrl: '/privacy/legal-holds',
                verb: 'Create hold',
                successTitle: 'Hold created!',
                successBlurb: 'The legal hold is active and will preserve the in-scope records from deletion.',
                initial: { hold_type: '', reason: '', legal_authority: '', review_date: '' },
                steps: [
                    {
                        key: 'basis',
                        label: 'Basis',
                        blurb: 'Type & reason',
                        icon: Scale,
                        headTitle: 'Why preserve this data?',
                        headBlurb: 'The kind of hold and the reason it applies.',
                        fields: [
                            { type: 'tiles', name: 'hold_type', label: 'Hold type', required: true, tiles: HOLD_TYPE_TILES, cols: 3 },
                            { type: 'textarea', name: 'reason', label: 'Reason', required: true, placeholder: 'Why must this data be preserved…' },
                        ],
                    },
                    {
                        key: 'scope',
                        label: 'Scope',
                        blurb: 'Authority & review',
                        icon: ListChecks,
                        headTitle: 'Authority & review',
                        headBlurb: 'The legal authority for the hold and when to review it.',
                        fields: [
                            { type: 'text', name: 'legal_authority', label: 'Legal authority', placeholder: 'e.g. Employment Relations Authority', span: true },
                            { type: 'date', name: 'review_date', label: 'Review date' },
                        ],
                    },
                ],
            };

        case 'retention':
            return {
                domain,
                railIcon: Lock,
                railTitle: 'New retention policy',
                railSub: 'Lifecycle rule',
                storeUrl: '/privacy/retention',
                verb: 'Create policy',
                successTitle: 'Policy created!',
                successBlurb: 'The retention policy is saved and will govern the data lifecycle.',
                initial: {
                    policy_name: '',
                    model_type: '',
                    description: '',
                    retention_period_years: '',
                    archive_after_years: '',
                    hard_delete_after_years: '',
                    next_review_at: '',
                    legal_basis: '',
                    business_justification: '',
                    active: true,
                    applies_to_soft_deleted: true,
                    legal_hold_exemption: true,
                    active_case_exemption: true,
                },
                steps: [
                    {
                        key: 'policy',
                        label: 'Policy',
                        blurb: 'Name & scope',
                        icon: Lock,
                        headTitle: 'Name & scope',
                        headBlurb: 'What this policy is called and which records it governs.',
                        fields: [
                            { type: 'text', name: 'policy_name', label: 'Policy name', required: true, placeholder: 'e.g. Client records' },
                            { type: 'text', name: 'model_type', label: 'Applies to (record type)', required: true, placeholder: 'e.g. Client' },
                            { type: 'textarea', name: 'description', label: 'Description', placeholder: 'What this policy covers…' },
                        ],
                    },
                    {
                        key: 'periods',
                        label: 'Periods',
                        blurb: 'Retain, archive, delete',
                        icon: Calendar,
                        headTitle: 'Retention periods',
                        headBlurb: 'How long to keep, archive and delete the data.',
                        fields: [
                            { type: 'number', name: 'retention_period_years', label: 'Retention period (years)', required: true, placeholder: '7' },
                            { type: 'number', name: 'archive_after_years', label: 'Archive after (years)', placeholder: 'optional' },
                            { type: 'number', name: 'hard_delete_after_years', label: 'Hard-delete after (years)', placeholder: 'optional' },
                            { type: 'date', name: 'next_review_at', label: 'Next review date' },
                            { type: 'text', name: 'legal_basis', label: 'Legal basis', placeholder: 'e.g. Privacy Act 2020 IPP 9, Health (Retention of Health Information) Regs 1996', span: true },
                            { type: 'textarea', name: 'business_justification', label: 'Business justification', placeholder: 'Why this retention period is appropriate…' },
                            { type: 'toggle', name: 'active', label: 'Active', placeholder: 'Policy is in force', span: true },
                        ],
                    },
                ],
            };

        case 'dpia':
            return {
                domain,
                railIcon: ShieldCheck,
                railTitle: 'New DPIA',
                railSub: 'Impact assessment',
                storeUrl: '/privacy/pia',
                verb: 'Create DPIA',
                successTitle: 'DPIA created!',
                successBlurb: 'The assessment is saved and pending Privacy Officer review.',
                transform: (d) => ({ ...d, identified_risks: splitLines(d.identified_risks), mitigation_measures: splitLines(d.mitigation_measures) }),
                initial: {
                    assessment_name: '',
                    project_or_process: '',
                    assessment_type: '',
                    processing_purpose: '',
                    legal_basis: '',
                    personal_data_types: [],
                    data_subjects: [],
                    overall_risk_level: '',
                    identified_risks: '',
                    mitigation_measures: '',
                    residual_risk_level: '',
                },
                steps: [
                    {
                        key: 'assessment',
                        label: 'Assessment',
                        blurb: 'Project & type',
                        icon: ShieldCheck,
                        headTitle: 'What is being assessed?',
                        headBlurb: 'The project or process and the kind of assessment.',
                        fields: [
                            { type: 'text', name: 'assessment_name', label: 'Assessment name', required: true, placeholder: 'e.g. New client portal' },
                            { type: 'text', name: 'project_or_process', label: 'Project or process', required: true, placeholder: 'What is being assessed' },
                            { type: 'tiles', name: 'assessment_type', label: 'Assessment type', required: true, tiles: ASSESSMENT_TYPE_TILES, cols: 2 },
                        ],
                    },
                    {
                        key: 'processing',
                        label: 'Processing',
                        blurb: 'Purpose & basis',
                        icon: ListChecks,
                        headTitle: 'Processing purpose & basis',
                        headBlurb: 'Why personal information is processed and the data involved.',
                        fields: [
                            { type: 'textarea', name: 'processing_purpose', label: 'Processing purpose', required: true, placeholder: 'Why personal data is processed…' },
                            { type: 'text', name: 'legal_basis', label: 'Legal basis', required: true, placeholder: 'e.g. Privacy Act 2020 IPP 1–4', span: true },
                            { type: 'chips', name: 'personal_data_types', label: 'Personal data types', options: DPIA_DATA_TYPES },
                            { type: 'chips', name: 'data_subjects', label: 'Who is affected', options: DPIA_SUBJECTS },
                        ],
                    },
                    {
                        key: 'risk',
                        label: 'Risk',
                        blurb: 'Rating & mitigation',
                        icon: AlertTriangle,
                        headTitle: 'Risk & mitigation',
                        headBlurb: 'The overall risk and how it is reduced.',
                        fields: [
                            { type: 'tiles', name: 'overall_risk_level', label: 'Overall risk level', required: true, tiles: RISK_TILES, cols: 2 },
                            { type: 'textarea', name: 'identified_risks', label: 'Identified risks', hint: 'one per line', placeholder: 'List the key privacy risks, one per line…' },
                            { type: 'textarea', name: 'mitigation_measures', label: 'Mitigation measures', hint: 'one per line', placeholder: 'How each risk is reduced, one per line…' },
                            { type: 'tiles', name: 'residual_risk_level', label: 'Residual risk level', tiles: RISK_TILES, cols: 2 },
                        ],
                    },
                ],
            };

        default: // request
            return {
                domain: 'request',
                railIcon: FileText,
                railTitle: 'New privacy request',
                railSub: 'IPP 6 / IPP 7',
                storeUrl: '/privacy/requests',
                verb: 'Create request',
                successTitle: 'Request logged!',
                successBlurb: 'The privacy request was created with a statutory deadline of +20 working days (IPP 6).',
                initial: {
                    request_type: '',
                    received_at: today(),
                    subject_name: '',
                    subject_email: '',
                    client_id: '',
                    verification_method: '',
                    request_details: '',
                    assigned_to_user_id: '',
                },
                steps: [
                    {
                        key: 'request',
                        label: 'Request',
                        blurb: 'Type & received date',
                        icon: FileText,
                        headTitle: 'What is being requested?',
                        headBlurb: 'The right being exercised, and when we received it.',
                        fields: [
                            { type: 'tiles', name: 'request_type', label: 'Request type', required: true, tiles: REQUEST_TYPE_TILES, cols: 2 },
                            { type: 'date', name: 'received_at', label: 'Received date', required: true, span: true },
                        ],
                    },
                    {
                        key: 'subject',
                        label: 'Data subject',
                        blurb: 'Who is asking',
                        icon: Users,
                        headTitle: 'Who is the request about?',
                        headBlurb: 'The individual and, if applicable, their client record.',
                        fields: [
                            { type: 'text', name: 'subject_name', label: 'Subject name', required: true, placeholder: 'Full name of the person' },
                            { type: 'email', name: 'subject_email', label: 'Subject email', required: true, placeholder: 'name@example.co.nz' },
                            { type: 'client', name: 'client_id', label: 'Link to client record', hint: 'optional — enables a full IPP 6 export', span: true, reviewLabel: 'Linked client' },
                            { type: 'subhead', label: 'Identity', icon: Fingerprint },
                            { type: 'select', name: 'verification_method', label: 'Verification method', options: VERIFICATION_METHODS, span: true },
                        ],
                    },
                    {
                        key: 'scope',
                        label: 'Scope & assignment',
                        blurb: 'Detail, owner & deadline',
                        icon: ListChecks,
                        headTitle: 'Scope, owner & deadline',
                        headBlurb: 'What is being requested, who owns it, and the statutory clock.',
                        fields: [
                            {
                                type: 'info',
                                icon: Clock,
                                text: 'The statutory deadline is set automatically to +20 working days from the received date (IPP 6), skipping weekends and NZ public holidays. Use “Extend” later to change it with a recorded reason.',
                            },
                            { type: 'textarea', name: 'request_details', label: 'Request details', placeholder: 'What is being requested, and any specific records…' },
                            { type: 'staff', name: 'assigned_to_user_id', label: 'Assigned to', span: true },
                        ],
                    },
                ],
            };
    }
}
