/* Declarative configs for the H&S workflow wizards (WS7), driven by HsFormWizard. Field keys
 * match each endpoint's validation contract so the collected values post directly (+ `stay`).
 * Add a workflow here + flip its launcher tile to in-place to convert it from navigate-away. */
import {
    Activity,
    AlertOctagon,
    Clipboard,
    ClipboardCheck,
    FileText,
    FlaskConical,
    HeartPulse,
    PersonStanding,
    ShieldCheck,
    Siren,
    Users,
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

// First-aid record vocabulary now lives solely in
// resources/js/pages/health-safety/first-aid/options.ts — the bespoke FirstAidReportDialog
// replaced the config-driven first-aid wizard, so PERSON_TYPES/INJURY_TYPES/OUTCOMES were
// removed here to prevent FE/BE enum drift.

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

const RESTRAINT_TYPES = ['physical', 'chemical', 'mechanical', 'seclusion', 'environmental'].map((v) => opt(v));
const SEVERITY_LMHC = ['low', 'medium', 'high', 'critical'].map((v) => opt(v));
const SEVERITY_MMSC = ['minor', 'moderate', 'serious', 'critical'].map((v) => opt(v));
const INJURY_TYPES_WI = [
    'strain', 'laceration', 'fracture', 'burn', 'contusion', 'concussion', 'repetitive_strain',
    'chemical_exposure', 'biological_exposure', 'needle_stick', 'slip_trip_fall', 'manual_handling',
    'psychological', 'illness', 'other',
].map((v) => opt(v));
const TREATMENT_RTW = [
    'none', 'first_aid', 'gp_visit', 'hospital', 'emergency_department', 'hospitalisation', 'specialist', 'ongoing',
].map((v) => opt(v));
const PHYSICAL_FORM = ['solid', 'liquid', 'gas', 'powder', 'aerosol', 'paste', 'other'].map((v) => opt(v));
const HAZARD_CLASSES = ['Flammable', 'Oxidising', 'Toxic', 'Corrosive', 'Eco-toxic', 'Explosive', 'Compressed gas', 'Carcinogenic'].map(
    (v) => ({ value: v, label: v }),
);

const restraintConfig: WizardConfig = {
    key: 'restraint',
    title: 'Log restraint event',
    description: 'Record a restraint with its least-restrictive justification and debrief.',
    railIcon: Clipboard,
    railTitle: 'Restraint event',
    railSub: 'Restraint register',
    endpoint: '/health-safety/restraints/events',
    successTitle: 'Restraint event logged',
    successBlurb: 'The event is in the restraint register.',
    steps: [
        {
            key: 'event',
            label: 'Event',
            blurb: 'Client, type & severity',
            icon: Clipboard,
            fields: [
                { key: 'client_id', label: 'Client', type: 'select', source: 'clients', required: true },
                { key: 'site_id', label: 'Site', type: 'select', source: 'sites' },
                { key: 'restraint_type', label: 'Restraint type', type: 'tiles', required: true, options: RESTRAINT_TYPES },
                { key: 'severity', label: 'Severity', type: 'segmented', required: true, options: SEVERITY_LMHC },
                { key: 'started_at', label: 'Started', type: 'datetime', required: true },
                { key: 'ended_at', label: 'Ended', type: 'datetime' },
            ],
        },
        {
            key: 'justification',
            label: 'Justification & debrief',
            blurb: 'Least-restrictive basis',
            icon: ShieldCheck,
            fields: [
                { key: 'trigger_description', label: 'Trigger / antecedent', type: 'textarea', required: true, placeholder: 'What led to the restraint…' },
                { key: 'de_escalation_attempted', label: 'De-escalation attempted', type: 'textarea', required: true, placeholder: 'Least-restrictive options tried first…' },
                { key: 'restraint_description', label: 'Restraint used', type: 'textarea', required: true, placeholder: 'Describe the restraint applied…' },
                { key: 'person_response', label: 'Person’s response', type: 'textarea' },
                { key: 'post_incident_support', label: 'Post-incident support / debrief', type: 'textarea' },
            ],
        },
        {
            key: 'plan',
            label: 'Plan & follow-up',
            blurb: 'Support plan & authorisation',
            icon: FileText,
            fields: [
                { key: 'within_support_plan', label: 'Within behaviour-support plan', type: 'toggle', default: true },
                { key: 'deviation_reason', label: 'If outside the plan, why', type: 'textarea', hint: 'required when outside the plan' },
                { key: 'injury_occurred', label: 'Injury occurred', type: 'toggle' },
                { key: 'injury_details', label: 'Injury details', type: 'textarea', hint: 'required when an injury occurred' },
                { key: 'authorised_by', label: 'Authorised by', type: 'select', source: 'staff' },
            ],
        },
        { key: 'review', label: 'Review', blurb: 'Confirm & log', icon: ClipboardCheck, fields: [] },
    ],
};

const rtwConfig: WizardConfig = {
    key: 'rtw',
    title: 'Report injury → return-to-work',
    description: 'Record a workplace injury and its ACC / return-to-work detail.',
    railIcon: Activity,
    railTitle: 'Workplace injury',
    railSub: 'Injuries register',
    endpoint: '/health-safety/injuries',
    successTitle: 'Injury recorded',
    successBlurb: 'The injury is in the register — add the RTW plan from its record.',
    steps: [
        {
            key: 'injury',
            label: 'Injury',
            blurb: 'Worker, site & nature',
            icon: Activity,
            fields: [
                { key: 'user_id', label: 'Injured worker', type: 'select', source: 'staff', required: true },
                { key: 'site_id', label: 'Site', type: 'select', source: 'sites', required: true },
                { key: 'injury_date', label: 'Injury date', type: 'date', required: true },
                { key: 'injury_type', label: 'Injury type', type: 'select', required: true, options: INJURY_TYPES_WI },
                { key: 'body_part_affected', label: 'Body part', type: 'text', required: true },
                { key: 'severity', label: 'Severity', type: 'segmented', required: true, options: SEVERITY_MMSC },
            ],
        },
        {
            key: 'treatment',
            label: 'Treatment & ACC',
            blurb: 'Care given & claim',
            icon: HeartPulse,
            fields: [
                { key: 'description', label: 'Description', type: 'textarea', required: true, placeholder: 'How the injury happened…' },
                { key: 'medical_treatment_type', label: 'Medical treatment', type: 'select', required: true, options: TREATMENT_RTW },
                { key: 'immediate_treatment', label: 'Immediate treatment', type: 'textarea' },
                { key: 'worksafe_notifiable', label: 'WorkSafe notifiable', type: 'toggle' },
                { key: 'acc_claim_number', label: 'ACC claim number', type: 'text', placeholder: 'e.g. 26/123456' },
                { key: 'notes', label: 'Notes', type: 'textarea' },
            ],
        },
        { key: 'review', label: 'Review', blurb: 'Confirm & file', icon: ClipboardCheck, fields: [] },
    ],
};

const substanceConfig: WizardConfig = {
    key: 'substance',
    title: 'Add hazardous substance',
    description: 'Register a hazardous substance (Hazardous Substances Regs 2017).',
    railIcon: FlaskConical,
    railTitle: 'Hazardous substance',
    railSub: 'Chemical register',
    endpoint: '/health-safety/substances',
    successTitle: 'Substance registered',
    successBlurb: 'Add the SDS and storage locations from its record.',
    steps: [
        {
            key: 'substance',
            label: 'Substance',
            blurb: 'Identity & classification',
            icon: FlaskConical,
            fields: [
                { key: 'name', label: 'Name', type: 'text', required: true },
                { key: 'common_name', label: 'Common name', type: 'text' },
                { key: 'physical_form', label: 'Physical form', type: 'segmented', required: true, options: PHYSICAL_FORM },
                { key: 'hsno_classification', label: 'HSNO / EPA classification', type: 'text', placeholder: 'e.g. 3.1A Flammable liquid' },
                { key: 'hazard_classifications', label: 'Hazard classes', type: 'chips', options: HAZARD_CLASSES },
                { key: 'un_number', label: 'UN number', type: 'text' },
                { key: 'is_controlled_substance', label: 'Controlled substance', type: 'toggle' },
            ],
        },
        {
            key: 'controls',
            label: 'Controls',
            blurb: 'PPE, storage & first aid',
            icon: ShieldCheck,
            fields: [
                { key: 'ppe_required', label: 'PPE required', type: 'textarea' },
                { key: 'storage_requirements', label: 'Storage requirements', type: 'textarea' },
                { key: 'handling_precautions', label: 'Handling precautions', type: 'textarea' },
                { key: 'first_aid_measures', label: 'First-aid measures', type: 'textarea' },
                { key: 'spill_procedures', label: 'Spill procedures', type: 'textarea' },
            ],
        },
        { key: 'review', label: 'Review', blurb: 'Confirm & register', icon: ClipboardCheck, fields: [] },
    ],
};

const loneWorkerConfig: WizardConfig = {
    key: 'lone',
    title: 'Lone-worker check-in',
    description: 'Start a lone-worker session with an expected end and check-in interval.',
    railIcon: PersonStanding,
    railTitle: 'Lone worker',
    railSub: 'Lone-worker safety',
    endpoint: '/health-safety/lone-workers/sessions',
    successTitle: 'Session started',
    successBlurb: 'The lone-worker session is active and will prompt for check-ins.',
    steps: [
        {
            key: 'session',
            label: 'Session',
            blurb: 'Worker, site & schedule',
            icon: PersonStanding,
            fields: [
                { key: 'user_id', label: 'Lone worker', type: 'select', source: 'staff', required: true },
                { key: 'site_id', label: 'Site', type: 'select', source: 'sites' },
                { key: 'client_id', label: 'Client (if visiting)', type: 'select', source: 'clients' },
                { key: 'expected_end_at', label: 'Expected end', type: 'datetime', required: true, hint: 'must be in the future' },
                { key: 'check_in_interval_minutes', label: 'Check-in interval (mins)', type: 'number', placeholder: '15–480' },
                { key: 'activity_description', label: 'Activity', type: 'textarea' },
                { key: 'location', label: 'Location', type: 'text' },
            ],
        },
    ],
};

const LIKELIHOODS = ['rare', 'unlikely', 'possible', 'likely', 'almost_certain'].map((v) => opt(v));
const HAZARD_TYPES = [
    'slips_trips_falls', 'manual_handling', 'electrical', 'chemical', 'biological', 'machinery',
    'working_at_height', 'fire', 'vehicle', 'behaviour_aggression', 'noise', 'ergonomic', 'other',
].map((v) => opt(v));
const ELECTION_METHODS = ['elected', 'appointed', 'volunteered'].map((v) => opt(v));

const hazardConfig: WizardConfig = {
    key: 'hazard',
    title: 'Log hazard + risk assessment',
    description: 'Record a hazard with a likelihood × consequence risk rating (computed on save).',
    railIcon: AlertOctagon,
    railTitle: 'Hazard',
    railSub: 'Hazard register',
    endpoint: (v) => `/sites/${v.site_id}/hazards`,
    successTitle: 'Hazard logged',
    successBlurb: 'The hazard is in the register with its risk rating.',
    steps: [
        {
            key: 'hazard',
            label: 'Hazard',
            blurb: 'Site, type & description',
            icon: AlertOctagon,
            fields: [
                { key: 'site_id', label: 'Site', type: 'select', source: 'sites', required: true },
                { key: 'hazard_type', label: 'Hazard type', type: 'select', required: true, options: HAZARD_TYPES },
                { key: 'description', label: 'Description', type: 'textarea', required: true, placeholder: 'Describe the hazard…' },
            ],
        },
        {
            key: 'risk',
            label: 'Risk assessment',
            blurb: 'Likelihood × consequence',
            icon: Activity,
            fields: [
                { key: 'severity', label: 'Consequence (severity)', type: 'segmented', required: true, options: SEVERITY_LMHC },
                { key: 'likelihood', label: 'Likelihood', type: 'select', required: true, options: LIKELIHOODS },
            ],
        },
        {
            key: 'controls',
            label: 'Controls & follow-up',
            blurb: 'Actions & owner',
            icon: ShieldCheck,
            fields: [
                { key: 'immediate_action_applied', label: 'Immediate action applied', type: 'toggle' },
                { key: 'immediate_action_taken', label: 'Action taken', type: 'textarea' },
                { key: 'assigned_to_user_id', label: 'Assigned to', type: 'select', source: 'staff' },
                { key: 'due_date', label: 'Due date', type: 'date' },
            ],
        },
        { key: 'review', label: 'Review', blurb: 'Confirm & log', icon: ClipboardCheck, fields: [] },
    ],
};

const participationConfig: WizardConfig = {
    key: 'participation',
    title: 'Add H&S representative',
    description: 'Record an elected or appointed health & safety representative (worker participation).',
    railIcon: Users,
    railTitle: 'Worker participation',
    railSub: 'HSR register',
    endpoint: '/health-safety/worker-participation/representatives',
    successTitle: 'Representative added',
    successBlurb: 'The HSR is on record.',
    steps: [
        {
            key: 'rep',
            label: 'Representative',
            blurb: 'Worker, site & election',
            icon: Users,
            fields: [
                { key: 'user_id', label: 'Worker (HSR)', type: 'select', source: 'staff', required: true },
                { key: 'site_id', label: 'Site', type: 'select', source: 'sites', required: true },
                { key: 'election_method', label: 'How selected', type: 'segmented', required: true, options: ELECTION_METHODS },
                { key: 'elected_at', label: 'Elected / appointed on', type: 'date', required: true },
                { key: 'term_expires_at', label: 'Term expires', type: 'date' },
                { key: 'training_days_completed', label: 'Training days completed', type: 'number' },
                { key: 'notes', label: 'Notes', type: 'textarea' },
            ],
        },
        { key: 'review', label: 'Review', blurb: 'Confirm & add', icon: ClipboardCheck, fields: [] },
    ],
};

export const WIZARD_CONFIGS: Record<string, WizardConfig> = {
    drill: drillConfig,
    restraint: restraintConfig,
    rtw: rtwConfig,
    substance: substanceConfig,
    lone: loneWorkerConfig,
    hazard: hazardConfig,
    participation: participationConfig,
};
