import { describe, expect, it } from 'vitest';
import {
    answerLabel,
    checkDueStatus,
    draftFromTemplate,
    estimateSummary,
    keepAnswers,
    missingAnswers,
    outcomeLabel,
    outcomeTone,
    questionSummary,
    recordsIssue,
    templateDraftError,
    versionLabel,
    type TemplateDraft,
} from './checks-model';
import type { CheckQuestion, CheckTemplate } from './checks-types';

const condition: CheckQuestion = {
    id: 'exterior',
    label: 'Exterior condition',
    kind: 'condition',
    required: true,
    options: [
        { value: 'pass', label: 'No issue recorded' },
        { value: 'fail', label: 'Issue recorded' },
        { value: 'unable', label: 'Unable to assess' },
    ],
};
const reading: CheckQuestion = {
    id: 'odometer',
    label: 'Odometer reading',
    kind: 'number',
    required: false,
    options: [],
};

const draft = (patch: Partial<TemplateDraft> = {}): TemplateDraft => ({
    name: 'Vehicle condition record',
    use: 'Before vehicle use',
    assignment: 'all_vehicles',
    evidence: false,
    questions: [
        {
            id: 'a',
            label: 'Exterior condition',
            kind: 'condition',
            required: true,
            options: [],
        },
    ],
    confirmed: true,
    ...patch,
});

describe('check labels', () => {
    it('describes versions, outcomes and questions in plain words', () => {
        expect(versionLabel(3)).toBe('Version 3');
        expect(versionLabel(null)).toBe('Version not recorded');
        expect(outcomeLabel('failed')).toBe('Failed');
        expect(outcomeLabel('needs_assessment')).toBe('Needs assessment');
        expect(outcomeLabel(null)).toBe('Needs assessment');
        expect(outcomeTone('passed')).toBe('success');
        expect(outcomeTone('failed')).toBe('critical');
        expect(outcomeTone('needs_assessment')).toBe('warning');
        // Daily checks are recorded observations, never Passed or Failed.
        expect(outcomeLabel('no_issue_recorded')).toBe('No issue recorded');
        expect(outcomeLabel('issue_recorded')).toBe('Issue recorded');
        expect(outcomeTone('no_issue_recorded')).toBe('success');
        expect(outcomeTone('issue_recorded')).toBe('warning');
        expect(questionSummary(condition)).toBe('Condition · Required');
        expect(questionSummary(reading)).toBe('Number / reading · Optional');
    });

    it('marks the next check overdue only once its date has passed', () => {
        expect(checkDueStatus('2026-09-21', '2026-09-22')).toEqual({
            label: 'Overdue',
            tone: 'critical',
        });
        expect(checkDueStatus('2026-09-22', '2026-09-22').label).toBe(
            'Scheduled',
        );
        expect(checkDueStatus(null, '2026-09-22').label).toBe('Not scheduled');
    });
});

describe('answers', () => {
    it('finds required answers still missing and recorded issues', () => {
        expect(missingAnswers([condition, reading], {})).toEqual(['exterior']);
        expect(
            missingAnswers([condition, reading], { exterior: '  ' }),
        ).toEqual(['exterior']);
        expect(
            missingAnswers([condition, reading], { exterior: 'pass' }),
        ).toEqual([]);
        expect(recordsIssue([condition], { exterior: 'fail' })).toBe(true);
        expect(recordsIssue([condition], { exterior: 'unable' })).toBe(false);
    });

    it('shows the chosen option and keeps only answers that still apply', () => {
        expect(answerLabel(condition, 'unable')).toBe('Unable to assess');
        expect(answerLabel(condition, '')).toBe('Not answered');
        expect(answerLabel(reading, '12500')).toBe('12500');
        expect(
            keepAnswers([condition], {
                exterior: 'fail',
                removed: 'pass',
            }),
        ).toEqual({ exterior: 'fail' });
        expect(keepAnswers([condition], { exterior: 'maybe' })).toEqual({});
    });
});

describe('checklist versions', () => {
    it('refuses a version that is incomplete, duplicated or unconfirmed', () => {
        expect(templateDraftError(draft())).toBe('');
        expect(templateDraftError(draft({ name: ' ' }))).toBe(
            'Name the checklist and choose its use.',
        );
        expect(
            templateDraftError(
                draft({
                    questions: [
                        ...draft().questions,
                        {
                            id: 'b',
                            label: '',
                            kind: 'text',
                            required: false,
                            options: [],
                        },
                    ],
                }),
            ),
        ).toBe('Give every question a label.');
        expect(
            templateDraftError(
                draft({
                    questions: [
                        ...draft().questions,
                        {
                            id: 'b',
                            label: ' exterior  CONDITION ',
                            kind: 'text',
                            required: false,
                            options: [],
                        },
                    ],
                }),
            ),
        ).toBe('Question labels must be unique.');
        expect(
            templateDraftError(
                draft({
                    questions: [
                        {
                            id: 'a',
                            label: 'Notes',
                            kind: 'text',
                            required: true,
                            options: [],
                        },
                    ],
                }),
            ),
        ).toMatch(/required condition question/);
        expect(templateDraftError(draft({ confirmed: false }))).toBe(
            'Confirm that this version should be published for new checks.',
        );
    });

    it('accepts an existing pass/fail choice list as the condition question', () => {
        expect(
            templateDraftError(
                draft({
                    questions: [
                        {
                            id: '0',
                            label: 'Tyres',
                            kind: 'select',
                            required: true,
                            options: ['pass', 'fail', 'na'],
                        },
                    ],
                }),
            ),
        ).toBe('');
    });

    it('starts a customisation from the published version, unconfirmed', () => {
        const template: CheckTemplate = {
            id: 7,
            version_id: 12,
            version: 3,
            name: 'Return condition record',
            use: 'After vehicle use',
            assignment: 'vehicle',
            assignment_label: 'This vehicle · VH-014',
            evidence_required: true,
            items_sha256: 'a'.repeat(64),
            questions: [condition],
            source: 'library',
            published_at: null,
            published_by: null,
            rule_version_id: null,
        };
        const copy = draftFromTemplate(template);
        expect(copy).toMatchObject({
            name: 'Return condition record',
            use: 'After vehicle use',
            assignment: 'vehicle',
            evidence: true,
            confirmed: false,
        });
        expect(copy.questions[0]).toMatchObject({
            id: 'exterior',
            kind: 'condition',
            options: ['pass', 'fail', 'unable'],
        });
        expect(draftFromTemplate(null).questions).toHaveLength(1);
    });
});

describe('maintenance window estimate', () => {
    it('summarises the optional range as the design does', () => {
        expect(estimateSummary(false, [null, null])).toBe(
            'Not known yet · no estimated dates will be recorded.',
        );
        expect(estimateSummary(true, [null, null])).toBe(
            'No dates selected. Choose the first day.',
        );
        expect(estimateSummary(true, ['2026-09-24', null])).toBe(
            'From 24 Sep 2026 · choose the final day to finish the range.',
        );
        expect(estimateSummary(true, ['2026-09-24', '2026-09-24'])).toBe(
            '24 Sep 2026 · single calendar day',
        );
        expect(estimateSummary(true, ['2026-09-24', '2026-09-26'])).toBe(
            '24 Sep 2026 – 26 Sep 2026 · both dates included',
        );
    });
});
