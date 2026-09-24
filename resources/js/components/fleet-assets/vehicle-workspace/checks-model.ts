import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, toDatetimeLocal } from '@/lib/datetime';
import type {
    CheckAssignment,
    CheckQuestion,
    CheckQuestionKind,
    CheckRun,
    CheckTemplate,
} from './checks-types';
import type { VehicleProfile } from './types';

/** "Version 3", or a plain statement for checks recorded before versions existed. */
export function versionLabel(version: number | null | undefined): string {
    return version ? `Version ${version}` : 'Version not recorded';
}

/**
 * Daily checks are recorded observations: no approved rule evaluates them, so
 * they read "No issue recorded" or "Issue recorded", never Passed or Failed.
 */
export const DAILY_CHECK_KIND = 'daily';

export function outcomeLabel(outcome: string | null | undefined): string {
    if (outcome === 'passed') return 'Passed';
    if (outcome === 'failed') return 'Failed';
    if (outcome === 'no_issue_recorded') return 'No issue recorded';
    if (outcome === 'issue_recorded') return 'Issue recorded';
    return 'Needs assessment';
}

export function outcomeTone(
    outcome: string | null | undefined,
): 'success' | 'critical' | 'warning' {
    if (outcome === 'passed' || outcome === 'no_issue_recorded')
        return 'success';
    if (outcome === 'failed') return 'critical';
    return 'warning';
}

/** The second badge on a check Maintenance released: no issue found. */
export const ASSESSED_LABEL = 'No issue found';

/** A check Maintenance released no longer needs attention; its outcome stays as recorded. */
export function runTone(run: Pick<CheckRun, 'outcome' | 'assessment'>) {
    return run.assessment ? 'success' : outcomeTone(run.outcome);
}

const KIND_LABELS: Record<CheckQuestionKind, string> = {
    condition: 'Condition',
    text: 'Written observation',
    number: 'Number / reading',
    select: 'Choice list',
    checkbox: 'Yes / No',
};

export function kindLabel(kind: CheckQuestionKind): string {
    return KIND_LABELS[kind] ?? 'Written observation';
}

/** "Condition · Required" — how a question is described in previews and reviews. */
export function questionSummary(question: {
    kind: CheckQuestionKind;
    required: boolean;
}): string {
    return `${kindLabel(question.kind)} · ${question.required ? 'Required' : 'Optional'}`;
}

/** The next check card's status: overdue once the due date has passed. */
export function checkDueStatus(
    due: string | null,
    today: string,
): { label: string; tone: StatusVariant } {
    if (!due) return { label: 'Not scheduled', tone: 'neutral' };
    return due < today
        ? { label: 'Overdue', tone: 'critical' }
        : { label: 'Scheduled', tone: 'info' };
}

/** Registration, fleet tag and site, as shown under the vehicle name. */
export function vehicleLine(vehicle: VehicleProfile): string {
    return [vehicle.asset_tag, vehicle.registration_number, vehicle.site?.name]
        .filter(Boolean)
        .join(' · ');
}

/** "Kōwhai van · VH-014" */
export function vehicleShort(vehicle: VehicleProfile): string {
    return [vehicle.name, vehicle.asset_tag].filter(Boolean).join(' · ');
}

/** Answers keyed by question id; blank answers are treated as not given. */
export type CheckAnswers = Record<string, string>;

export function missingAnswers(
    questions: CheckQuestion[],
    answers: CheckAnswers,
): string[] {
    return questions
        .filter(
            (question) => question.required && !answers[question.id]?.trim(),
        )
        .map((question) => question.id);
}

/** Whether any condition answer records an issue. */
export function recordsIssue(
    questions: CheckQuestion[],
    answers: CheckAnswers,
): boolean {
    return questions.some(
        (question) =>
            (question.kind === 'condition' || question.kind === 'select') &&
            answers[question.id] === 'fail',
    );
}

export function answerLabel(
    question: CheckQuestion,
    value: string | undefined,
): string {
    if (!value?.trim()) return 'Not answered';
    return (
        question.options.find((option) => option.value === value)?.label ??
        value
    );
}

/** Keep answers whose question is still in the checklist, e.g. after a reload. */
export function keepAnswers(
    questions: CheckQuestion[],
    answers: CheckAnswers,
): CheckAnswers {
    const kept: CheckAnswers = {};
    questions.forEach((question) => {
        const value = answers[question.id];
        if (
            value &&
            (!question.options.length ||
                question.options.some((option) => option.value === value))
        )
            kept[question.id] = value;
    });
    return kept;
}

/** The Auckland wall time now, as "YYYY-MM-DDTHH:mm". */
export function localNow(): string {
    return toDatetimeLocal(new Date().toISOString());
}

/** "21 Sep 2026" for an instant, on the Auckland calendar. */
export function aucklandDay(iso: string | null | undefined): string {
    if (!iso) return 'Not recorded';
    return formatDateOnly(toDatetimeLocal(iso).slice(0, 10), 'Not recorded');
}

export function runObservedDay(run: CheckRun): string {
    return aucklandDay(run.observed_at ?? run.submitted_at);
}

export const USE_PRESETS = [
    'Before vehicle use',
    'After vehicle use',
    'Scheduled inspection',
    'Accessibility equipment',
    'Post-repair verification',
];

export const PROBLEM_TYPES = [
    'Engine warning',
    'Vehicle damage',
    'Tyre or wheel concern',
    'Brake concern',
    'Accessibility equipment',
    'Fluid leak',
    'Electrical fault',
    'Other vehicle concern',
];

export function assignmentOptions(
    vehicle: VehicleProfile,
): Array<{ value: CheckAssignment; label: string }> {
    return [
        { value: 'all_vehicles', label: 'All vehicles' },
        { value: 'accessible_vehicles', label: 'Accessible vehicles' },
        {
            value: 'vehicle',
            label: `This vehicle · ${vehicle.asset_tag || vehicle.name}`,
        },
    ];
}

export type DraftQuestion = {
    id: string;
    label: string;
    kind: CheckQuestionKind;
    required: boolean;
    /** Choices of an existing choice-list question; kept as they are. */
    options: string[];
};

export type TemplateDraft = {
    name: string;
    use: string;
    assignment: CheckAssignment;
    evidence: boolean;
    questions: DraftQuestion[];
    confirmed: boolean;
};

export function draftFromTemplate(
    template: CheckTemplate | null,
): TemplateDraft {
    if (!template)
        return {
            name: '',
            use: 'Before vehicle use',
            assignment: 'all_vehicles',
            evidence: false,
            questions: [newQuestion()],
            confirmed: false,
        };
    return {
        name: template.name,
        use: template.use,
        assignment: template.assignment,
        evidence: template.evidence_required,
        questions: template.questions.map((question) => ({
            id: question.id,
            label: question.label,
            kind: question.kind,
            required: question.required,
            options: question.options.map((option) => option.value),
        })),
        confirmed: false,
    };
}

export function newQuestion(): DraftQuestion {
    return {
        id: crypto.randomUUID(),
        label: '',
        kind: 'condition',
        required: true,
        options: [],
    };
}

const normaliseLabel = (value: string) =>
    value.trim().replace(/\s+/g, ' ').toLowerCase();

/** A condition question — or an existing pass/fail choice list — can record an issue. */
function canRecordIssue(question: DraftQuestion): boolean {
    return (
        question.kind === 'condition' ||
        (question.kind === 'select' &&
            question.options.includes('pass') &&
            question.options.includes('fail'))
    );
}

/** The first problem that stops a checklist version from being published. */
export function templateDraftError(draft: TemplateDraft): string {
    if (!draft.name.trim() || !draft.use.trim())
        return 'Name the checklist and choose its use.';
    if (
        !draft.questions.length ||
        draft.questions.some((question) => !question.label.trim())
    )
        return 'Give every question a label.';
    if (
        new Set(
            draft.questions.map((question) => normaliseLabel(question.label)),
        ).size !== draft.questions.length
    )
        return 'Question labels must be unique.';
    if (
        !draft.questions.some(
            (question) => question.required && canRecordIssue(question),
        )
    )
        return 'Include at least one required condition question, so a check can record an issue.';
    if (!draft.confirmed)
        return 'Confirm that this version should be published for new checks.';
    return '';
}

/** The estimate line under the optional maintenance window. */
export function estimateSummary(
    show: boolean,
    range: [string | null, string | null],
): string {
    if (!show) return 'Not known yet · no estimated dates will be recorded.';
    const [start, end] = range;
    if (!start) return 'No dates selected. Choose the first day.';
    if (!end)
        return `From ${formatDateOnly(start)} · choose the final day to finish the range.`;
    if (start === end) return `${formatDateOnly(start)} · single calendar day`;
    return `${formatDateOnly(start)} – ${formatDateOnly(end)} · both dates included`;
}
