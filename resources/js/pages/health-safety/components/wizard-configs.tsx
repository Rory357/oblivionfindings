/* Declarative configs for the H&S workflow wizards (WS7), driven by HsFormWizard. Field keys
 * match each endpoint's validation contract so the collected values post directly (+ `stay`).
 * Add a workflow here + flip its launcher tile to in-place to convert it from navigate-away. */
import {
    Activity,
    ClipboardCheck,
    FileText,
    HeartPulse,
    ShieldCheck,
    Siren,
    User,
} from 'lucide-react';

import type { WizardConfig } from './form-wizard';

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const opt = (value: string, label?: string) => ({ value, label: label ?? titleCase(value) });

const DRILL_TYPES = [
    'fire',
    'fire_evacuation',
    'earthquake',
    'lockdown',
    'tsunami',
    'chemical_spill',
    'medical_emergency',
    'other',
].map((v) => opt(v));

const PERSON_TYPES = ['staff', 'client', 'visitor', 'contractor'].map((v) => opt(v));

const INJURY_TYPES = [
    'cut',
    'burn',
    'bruise',
    'sprain',
    'fracture',
    'fall',
    'head_injury',
    'eye_injury',
    'allergic_reaction',
    'breathing_difficulty',
    'chest_pain',
    'seizure',
    'fainting',
    'nausea',
    'sting',
    'choking',
    'other',
].map((v) => opt(v));

const OUTCOMES = [
    'returned_to_activity',
    'returned_to_work',
    'sent_home',
    'medical_centre',
    'sent_to_medical',
    'hospital',
    'sent_to_hospital',
    'ambulance_called',
    'ongoing_monitoring',
    'refused_treatment',
    'other',
].map((v) => opt(v));

const drillConfig: WizardConfig = {
    key: 'drill',
    title: 'Record emergency drill',
    description: 'Schedule an emergency evacuation drill.',
    railIcon: Siren,
    railTitle: 'Emergency drill',
    railSub: 'Drills register',
    endpoint: '/health-safety/drills',
    successTitle: 'Drill scheduled',
    successBlurb: 'The drill is in the register.',
    steps: [
        {
            key: 'drill',
            label: 'Drill',
            blurb: 'Type, site & schedule',
            icon: Siren,
            fields: [
                { key: 'site_id', label: 'Site', type: 'select', source: 'sites', required: true },
                { key: 'drill_type', label: 'Drill type', type: 'tiles', required: true, options: DRILL_TYPES },
                { key: 'title', label: 'Title', type: 'text', required: true, span: true, placeholder: 'e.g. Q2 fire evacuation — Maple House' },
                { key: 'scheduled_at', label: 'Scheduled', type: 'datetime', required: true },
            ],
        },
        {
            key: 'detail',
            label: 'Detail',
            blurb: 'Scenario & facilitator',
            icon: FileText,
            fields: [
                { key: 'scenario_description', label: 'Scenario', type: 'textarea', placeholder: 'Describe the scenario…' },
                { key: 'conducted_by', label: 'Conducted by', type: 'select', source: 'staff' },
            ],
        },
        { key: 'review', label: 'Review', blurb: 'Confirm & schedule', icon: ClipboardCheck, fields: [] },
    ],
};

const firstAidConfig: WizardConfig = {
    key: 'first_aid',
    title: 'Record first-aid treatment',
    description: 'Record a first-aid treatment in the register.',
    railIcon: HeartPulse,
    railTitle: 'First aid',
    railSub: 'First-aid register',
    endpoint: '/health-safety/first-aid',
    successTitle: 'First-aid recorded',
    successBlurb: 'The treatment is in the register.',
    steps: [
        {
            key: 'person',
            label: 'Person',
            blurb: 'Who was treated',
            icon: User,
            fields: [
                { key: 'site_id', label: 'Site', type: 'select', source: 'sites', required: true },
                { key: 'treated_person_name', label: 'Person treated', type: 'text', required: true },
                { key: 'treated_person_type', label: 'Person type', type: 'segmented', required: true, options: PERSON_TYPES },
                { key: 'treatment_date', label: 'Treatment date', type: 'date', required: true },
            ],
        },
        {
            key: 'injury',
            label: 'Injury & treatment',
            blurb: 'What happened & what was done',
            icon: Activity,
            fields: [
                { key: 'injury_illness_type', label: 'Injury / illness', type: 'select', required: true, options: INJURY_TYPES },
                { key: 'body_part', label: 'Body part', type: 'text' },
                { key: 'injury_illness_description', label: 'Description', type: 'textarea', required: true, placeholder: 'Describe the injury / illness…' },
                { key: 'treatment_given', label: 'Treatment given', type: 'textarea', required: true, placeholder: 'Describe the treatment…' },
                { key: 'treatment_outcome', label: 'Outcome', type: 'select', required: true, options: OUTCOMES },
            ],
        },
        {
            key: 'aider',
            label: 'First-aider & follow-up',
            blurb: 'Who treated & next steps',
            icon: ShieldCheck,
            fields: [
                { key: 'first_aider_id', label: 'First-aider', type: 'select', source: 'staff', required: true },
                { key: 'ambulance_called', label: 'Ambulance called', type: 'toggle' },
                { key: 'incident_reported', label: 'Incident reported', type: 'toggle' },
                { key: 'first_aider_notes', label: 'Notes', type: 'textarea' },
            ],
        },
        { key: 'review', label: 'Review', blurb: 'Confirm & file', icon: ClipboardCheck, fields: [] },
    ],
};

export const WIZARD_CONFIGS: Record<string, WizardConfig> = {
    drill: drillConfig,
    first_aid: firstAidConfig,
};
