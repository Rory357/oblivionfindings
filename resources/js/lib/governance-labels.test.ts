import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

import {
    GOVERNANCE_LABELS,
    GOVERNANCE_STATUS_VARIANTS,
    actionStatusLabel,
    auditEntityTypeLabel,
    auditEventLabel,
    boardRoleLabel,
    complianceFrameworkLabel,
    complianceStatusLabel,
    conflictTypeLabel,
    financialYearLabel,
    formatNzd,
    governanceLabel,
    governanceStatus,
    humaniseGovernanceValue,
    meetingTypeLabel,
    outcomeSentence,
    planLengthLabel,
    policyStatusLabel,
    refSuffix,
    resolutionChip,
    resolutionOutcomeLabel,
    resolutionPurposeLabel,
    resolutionStatusLabel,
    reviewCycleLabel,
    riskCategoryLabel,
    riskImpactLabel,
    riskLevelForScore,
    riskLikelihoodLabel,
    riskStrategyLabel,
    rsvpResponseLabel,
    sentenceCase,
    spendCategoryLabel,
    teTiritiPrincipleLabel,
    thresholdExplanation,
    votingThresholdLabel,
    type GovernanceLabelDomain,
    type GovernanceStatusDomain,
} from './governance-labels';

/** Shared with tests/Unit/Governance/GovernanceLabelsTest.php (server mirror). */
const fixture = JSON.parse(
    readFileSync(
        resolve(__dirname, '../../../tests/fixtures/governance/labels.json'),
        'utf8',
    ),
) as {
    labels: Record<string, Record<string, string>>;
    humanise: Record<string, string>;
    sentence: Record<string, string>;
};

describe('parity with the server mirror', () => {
    it('keeps the label maps identical to the shared fixture', () => {
        expect(GOVERNANCE_LABELS).toEqual(fixture.labels);
    });

    it('humanises and sentence-cases exactly like the server', () => {
        for (const [input, expected] of Object.entries(fixture.humanise)) {
            expect(humaniseGovernanceValue(input)).toBe(expected);
        }
        for (const [input, expected] of Object.entries(fixture.sentence)) {
            expect(sentenceCase(input)).toBe(expected);
        }
    });
});

describe('domain labels use the approved vocabulary', () => {
    it('resolutions and votes', () => {
        expect(resolutionStatusLabel('open')).toBe('Open for voting');
        expect(resolutionStatusLabel('closed')).toBe('Voting closed');
        expect(resolutionStatusLabel('implemented')).toBe('Done');
        expect(resolutionOutcomeLabel('carried')).toBe('Passed');
        expect(resolutionOutcomeLabel('defeated')).toBe('Not passed');
        expect(resolutionOutcomeLabel('no_quorum')).toBe(
            'No decision — not enough members took part',
        );
        expect(votingThresholdLabel('simple_majority')).toBe(
            'More For than Against',
        );
        expect(votingThresholdLabel('special')).toBe('At least two-thirds For');
        expect(votingThresholdLabel('unanimous')).toBe(
            'Everyone entitled votes For',
        );
        expect(resolutionPurposeLabel('information')).toBe('For information');
        expect(conflictTypeLabel('prejudicial')).toBe(
            'Bias or divided loyalty',
        );
        expect(governanceLabel('vote', 'abstain')).toBe('Abstain');
    });

    it('actions, meetings and risk', () => {
        expect(actionStatusLabel('complete')).toBe('Done');
        expect(meetingTypeLabel('full_board')).toBe('Full board meeting');
        expect(rsvpResponseLabel('declined')).toBe('Sent apologies');
        expect(riskCategoryLabel('it_cyber')).toBe('IT and cyber security');
        expect(riskStrategyLabel('treat')).toBe('Reduce it');
        expect(riskStrategyLabel('transfer')).toBe('Share it');
        expect(riskStrategyLabel('terminate')).toBe('Stop the activity');
        expect(riskStrategyLabel('tolerate')).toBe('Live with it and monitor');
        expect(riskLikelihoodLabel(5)).toBe('Almost certain');
        expect(riskImpactLabel('1')).toBe('Insignificant');
        expect(riskLevelForScore(20)).toBe('critical');
        expect(riskLevelForScore(9)).toBe('low');
        expect(riskLevelForScore(4)).toBe('minimal');
        expect(riskLevelForScore(null)).toBeNull();
    });

    it('compliance, policies, finance, strategy and board', () => {
        expect(complianceFrameworkLabel('hswa')).toBe(
            'Health and Safety at Work Act 2015',
        );
        expect(complianceStatusLabel('not_due')).toBe('Not due yet');
        expect(teTiritiPrincipleLabel('partnership')).toBe('Partnership');
        expect(policyStatusLabel('superseded')).toBe(
            'Replaced by a newer version',
        );
        expect(spendCategoryLabel('capex')).toBe(
            'Equipment or building purchase',
        );
        expect(planLengthLabel('5_year')).toBe('5-year plan');
        expect(boardRoleLabel('observer')).toBe("Observer (can't vote)");
        expect(reviewCycleLabel('2026-Q1')).toBe('Quarter 1, 2026');
        expect(reviewCycleLabel('2026-Annual')).toBe('Annual review 2026');
    });

    it('audit log entity types and events', () => {
        expect(
            auditEntityTypeLabel('App\\Domain\\Governance\\Models\\BoardPack'),
        ).toBe('Board pack');
        expect(auditEntityTypeLabel('GovernanceVotingProfile')).toBe(
            'Voting rules',
        );
        expect(auditEventLabel('resolution.voted')).toBe(
            'voted on a resolution',
        );
        expect(auditEventLabel('minutes.approved')).toBe('approved minutes');
        expect(auditEventLabel('resolution.voting_reopened')).toBe(
            'resolution voting reopened',
        );
        expect(auditEventLabel(null)).toBe('did something');
    });
});

describe('fallbacks never leak raw values', () => {
    const unknowns = [
        'brand_new_status',
        'some.dotted-key',
        'App\\Models\\SomethingNew',
        'escalated_to_board',
    ];

    it('humanises unknown values in every domain', () => {
        for (const domain of Object.keys(
            GOVERNANCE_LABELS,
        ) as GovernanceLabelDomain[]) {
            for (const value of unknowns) {
                const label = governanceLabel(domain, value);
                expect(label, `${domain}:${value}`).not.toMatch(/[_\\]/);
                expect(label, `${domain}:${value}`).toMatch(/^\p{Lu}/u);
            }
        }
        expect(complianceStatusLabel('escalated_to_board')).toBe(
            'Escalated to board',
        );
    });

    it('never ships a label containing an underscore', () => {
        for (const [domain, map] of Object.entries(GOVERNANCE_LABELS)) {
            for (const [value, label] of Object.entries(map)) {
                expect(label, `${domain}.${value}`).not.toContain('_');
            }
        }
    });

    it('reads "Not set" for missing values', () => {
        expect(governanceLabel('priority', null)).toBe('Not set');
        expect(governanceLabel('priority', '  ')).toBe('Not set');
    });
});

describe('governanceStatus', () => {
    it('follows the vocabulary status chips', () => {
        expect(governanceStatus('resolution_status', 'draft')).toEqual({
            label: 'Draft',
            variant: 'neutral',
        });
        expect(governanceStatus('resolution_status', 'open')).toEqual({
            label: 'Open for voting',
            variant: 'info',
        });
        expect(governanceStatus('budget_status', 'proposed')).toEqual({
            label: 'Waiting for the board',
            variant: 'warning',
        });
        expect(governanceStatus('resolution_outcome', 'carried')).toEqual({
            label: 'Passed',
            variant: 'success',
        });
        expect(governanceStatus('resolution_outcome', 'defeated')).toEqual({
            label: 'Not passed',
            variant: 'critical',
        });
        expect(governanceStatus('compliance_status', 'overdue')).toEqual({
            label: 'Overdue',
            variant: 'critical',
        });
        expect(governanceStatus('policy_status', 'superseded')).toEqual({
            label: 'Replaced by a newer version',
            variant: 'neutral',
        });
    });

    it('shows "No data yet" for missing and neutral for unknown', () => {
        expect(governanceStatus('risk_status', null)).toEqual({
            label: 'No data yet',
            variant: 'neutral',
        });
        expect(governanceStatus('risk_status', 'under_investigation')).toEqual({
            label: 'Under investigation',
            variant: 'neutral',
        });
    });

    it('has a label for every status value it colours', () => {
        for (const [domain, variants] of Object.entries(
            GOVERNANCE_STATUS_VARIANTS,
        )) {
            const labels = GOVERNANCE_LABELS[
                domain as GovernanceStatusDomain
            ] as Record<string, string>;
            for (const value of Object.keys(variants)) {
                expect(labels[value], `${domain}.${value}`).toBeDefined();
            }
        }
    });

    it('gives a closed resolution one chip — its outcome', () => {
        expect(resolutionChip('closed', 'no_quorum')).toEqual({
            label: 'No decision — not enough members took part',
            variant: 'warning',
        });
        expect(resolutionChip('open', null).label).toBe('Open for voting');
    });
});

describe('formatNzd', () => {
    it('formats NZD with separators and cents only when present', () => {
        expect(formatNzd(85000)).toBe('$85,000');
        expect(formatNzd(85000.5)).toBe('$85,000.50');
        expect(formatNzd('1234.56')).toBe('$1,234.56');
        expect(formatNzd(-1200)).toBe('-$1,200');
        expect(formatNzd(0)).toBe('$0');
    });

    it('can force cents on or off', () => {
        expect(formatNzd(85000, { cents: true })).toBe('$85,000.00');
        expect(formatNzd(85000.5, { cents: false })).toBe('$85,001');
    });

    it('says "Not stated" when there is no amount', () => {
        expect(formatNzd(null)).toBe('Not stated');
        expect(formatNzd(undefined)).toBe('Not stated');
        expect(formatNzd('')).toBe('Not stated');
        expect(formatNzd('abc')).toBe('Not stated');
    });
});

describe('references and financial years', () => {
    it('puts references last as "Ref …"', () => {
        expect(refSuffix('RES-2026-004')).toBe('Ref RES-2026-004');
        expect(refSuffix(null)).toBe('');
    });

    it('uses the NZ 1 July – 30 June financial year', () => {
        expect(financialYearLabel(2026)).toBe('2025/26');
        expect(financialYearLabel('2025-2026')).toBe('2025/26');
        expect(financialYearLabel('FY2026')).toBe('2025/26');
        expect(financialYearLabel('2025/26')).toBe('2025/26');
        expect(financialYearLabel('2026-06')).toBe('2025/26');
        expect(financialYearLabel('2026-07')).toBe('2026/27');
        expect(financialYearLabel('2026-06-30')).toBe('2025/26');
        expect(financialYearLabel('2026-07-01')).toBe('2026/27');
        // 30 June 20:00 UTC is already 1 July in Auckland.
        expect(financialYearLabel(new Date('2026-06-30T20:00:00Z'))).toBe(
            '2026/27',
        );
        expect(financialYearLabel(2100)).toBe('2099/00');
        expect(financialYearLabel(null)).toBe('Not set');
    });
});

describe('thresholdExplanation', () => {
    it('explains each voting rule in one sentence', () => {
        expect(thresholdExplanation('simple_majority')).toBe(
            "It passes if more voting members vote For than Against — abstentions don't count either way.",
        );
        expect(thresholdExplanation('two_thirds')).toContain('two-thirds');
        expect(thresholdExplanation('unanimous')).toContain(
            'every voting member votes For',
        );
        expect(thresholdExplanation(null)).toBe(
            thresholdExplanation('simple_majority'),
        );
    });
});

describe('outcomeSentence', () => {
    it('describes a passed resolution', () => {
        expect(
            outcomeSentence({
                outcome: 'carried',
                for: 5,
                against: 1,
                abstain: 1,
                participating: 7,
                required: 4,
                votingMembers: 7,
                threshold: 'simple_majority',
            }),
        ).toBe(
            'Passed. 5 voted For and 1 Against (1 abstained). It needed more For than Against, and at least 4 of 7 voting members taking part (7 did).',
        );
    });

    it('describes a resolution that did not pass', () => {
        expect(
            outcomeSentence({
                outcome: 'defeated',
                for: 3,
                against: 3,
                participating: 6,
                required: 4,
                votingMembers: 7,
                threshold: 'two_thirds',
            }),
        ).toBe(
            'Not passed. 3 voted For and 3 Against. It needed at least two-thirds of the For and Against votes to be For, and at least 4 of 7 voting members taking part (6 did).',
        );
    });

    it('describes no decision when quorum was not met', () => {
        expect(
            outcomeSentence({
                outcome: 'no_quorum',
                for: 2,
                against: 0,
                participating: 2,
                required: 4,
                votingMembers: 7,
                threshold: 'simple_majority',
            }),
        ).toBe(
            'No decision — not enough members took part. 2 voted For and 0 Against. It needed more For than Against, and at least 4 of 7 voting members taking part (2 did).',
        );
    });

    it('explains why an abstention defeats a unanimous resolution', () => {
        expect(
            outcomeSentence({
                outcome: 'defeated',
                for: 6,
                against: 0,
                abstain: 1,
                participating: 7,
                required: 4,
                votingMembers: 7,
                threshold: 'unanimous',
            }),
        ).toBe(
            'Not passed. 6 voted For and 0 Against (1 abstained). It needed every voting member to vote For, and at least 4 of 7 voting members taking part (7 did).',
        );
    });

    it('handles no votes and no quorum figures', () => {
        expect(outcomeSentence({ outcome: null, for: 0, against: 0 })).toBe(
            'No result yet. No votes were cast. It needed more For than Against.',
        );
    });
});
