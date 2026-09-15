/**
 * Governance glossary — the "What's this?" definitions behind
 * `<GovernanceTermHint term="…" />`.
 *
 * Written for a volunteer board member of an NZ supported-living provider:
 * one or two short sentences, sentence case, NZ English, no jargon that
 * vocabulary.md bans. Wording source of truth:
 *   docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md
 */

export interface GovernanceGlossaryEntry {
    /** The term as it appears on screen, sentence case. */
    term: string;
    /** One or two short, plain sentences. */
    definition: string;
}

export const GOVERNANCE_GLOSSARY = {
    quorum: {
        term: 'Quorum',
        definition:
            'The minimum number of voting members who must take part for a vote to count. If too few take part, there is no decision.',
    },
    voting_members: {
        term: 'Voting members',
        definition:
            'Board members who are allowed to vote on resolutions. Observers and people whose term has ended or not yet started cannot vote.',
    },
    resolution: {
        term: 'Resolution',
        definition:
            'A matter the board decides by voting. It records exactly what was agreed and whether it passed.',
    },
    resolution_wording: {
        term: 'Resolution wording',
        definition:
            'The exact words the board votes on. If it passes, these words become the board’s decision.',
    },
    written_resolution: {
        term: 'Written resolution',
        definition:
            'A vote held outside a meeting, where members read the paper and vote in the app instead of in the room. Your governing document may require everyone to agree.',
    },
    passed: {
        term: 'Passed',
        definition:
            'Enough members voted For under the voting rule, and enough members took part, so the board’s decision stands.',
    },
    not_passed: {
        term: 'Not passed',
        definition:
            'Not enough members voted For under the voting rule, so the board did not agree to it.',
    },
    abstain: {
        term: 'Abstain',
        definition:
            'Taking part in the vote without voting For or Against. It usually doesn’t count either way — but if everyone must vote For, abstaining means the resolution does not pass.',
    },
    conflict_of_interest: {
        term: 'Conflict of interest',
        definition:
            'When you, your family or an organisation you’re involved with could gain or lose from a decision. You declare it so the board can decide how to handle it.',
    },
    step_aside: {
        term: 'Step aside',
        definition:
            'Leaving the discussion and vote on a matter because of a conflict of interest. You don’t vote or count as taking part, but the number of voting members stays the same.',
    },
    board_pack: {
        term: 'Board pack',
        definition:
            'The reading for a meeting — the agenda, reports and papers — put together in one place. If it changes, the board gets a new version.',
    },
    action: {
        term: 'Action',
        definition:
            'A piece of follow-up work the board has asked someone to do, with an owner and a due date.',
    },
    evidence: {
        term: 'Evidence',
        definition:
            'A file showing the work is done — for example, the signed document or a completed report.',
    },
    read_and_confirm: {
        term: 'Read and confirm',
        definition:
            'Some policies ask each board member to confirm they have read the current version. Your confirmation is recorded with the version and date.',
    },
    risk_before_controls: {
        term: 'Risk before controls',
        definition:
            'How serious a risk would be if nothing were in place to manage it. It is the likelihood score multiplied by the impact score.',
    },
    risk_after_controls: {
        term: 'Risk after controls',
        definition:
            'How serious a risk is once the steps already in place to manage it are taken into account. This is the score the board compares with its limit.',
    },
    board_limit: {
        term: 'The board’s limit',
        definition:
            'The most risk the board has agreed to accept for this kind of risk. Risks above the limit need more action or a board decision to accept them.',
    },
    controls: {
        term: 'Controls',
        definition:
            'The steps already in place to make a risk less likely or less harmful, such as training, checks or insurance.',
    },
    control_effectiveness: {
        term: 'Control effectiveness',
        definition:
            'How well the controls actually reduce a risk — from no controls in place, to weak, partly effective or strong.',
    },
    likelihood: {
        term: 'Likelihood',
        definition:
            'How likely the risk is to happen, scored from 1 (rare) to 5 (almost certain).',
    },
    impact: {
        term: 'Impact',
        definition:
            'How much harm it would cause if it happened, scored from 1 (insignificant) to 5 (catastrophic).',
    },
    requirement: {
        term: 'Requirement',
        definition:
            'Something the law, a standard or a funding contract says the organisation must do, such as a report, audit or certificate, and when it is due.',
    },
    commitment: {
        term: 'Commitment (Te Tiriti)',
        definition:
            'A specific thing the organisation has promised to do to honour Te Tiriti o Waitangi, with an owner and a way to track progress.',
    },
    care_quality_indicators: {
        term: 'Care quality indicators',
        definition:
            'Regular measures of the safety and quality of support — such as medication errors, falls, skin injuries and infections — compared with agreed targets.',
    },
    budget: {
        term: 'Budget',
        definition:
            'The money the board has approved for the financial year, split into lines such as staffing and operations.',
    },
    budget_change: {
        term: 'Budget change',
        definition:
            'A request to increase, decrease or move money between lines of an approved budget. It needs approval before it takes effect.',
    },
    spend_request: {
        term: 'Spend request',
        definition:
            'A request for approval before spending money above a set amount or outside the budget. Bigger amounts need a more senior approver.',
    },
    over_under_budget: {
        term: 'Over or under budget',
        definition:
            'The difference between what was budgeted and what has actually been spent so far. Over budget means more has been spent than planned.',
    },
    financial_year: {
        term: 'Financial year',
        definition:
            'The organisation’s accounting year, from 1 July to 30 June. For example, 2025/26 runs from 1 July 2025 to 30 June 2026.',
    },
    strategic_plan: {
        term: 'Strategic plan',
        definition:
            'The board’s plan for what the organisation wants to achieve over the next few years, and how it will know it is getting there.',
    },
    theme: {
        term: 'Theme',
        definition:
            'A broad area the plan focuses on, such as safety, people or finance. Each theme groups related goals.',
    },
    measures_of_success: {
        term: 'Measures of success',
        definition:
            'The specific results that show a goal has been met, such as “no medication errors causing harm this year”.',
    },
    plan_length: {
        term: 'Plan length',
        definition:
            'How many years the strategic plan covers — usually three or five.',
    },
    performance_review: {
        term: 'Performance review',
        definition:
            'The board’s regular review of how the CEO is doing against agreed goals. It ends with the board’s overall rating and decision.',
    },
    self_assessment: {
        term: 'Self-assessment',
        definition:
            'The CEO’s own view of how they did against each goal, written before the board completes its review.',
    },
    interest: {
        term: 'Interest',
        definition:
            'A role, relationship or financial link outside the organisation — like a directorship or a family member working for a supplier. Declaring it helps spot conflicts early.',
    },
    board_evaluation: {
        term: 'Board evaluation',
        definition:
            'A short survey where members rate how well the board works, so it can agree what to improve.',
    },
    minutes: {
        term: 'Minutes',
        definition:
            'The official written record of a meeting — who attended, what was discussed and what was decided. The board approves them at a later meeting.',
    },
    apologies: {
        term: 'Apologies',
        definition:
            'Letting the board know in advance that you can’t attend a meeting. It is recorded in the minutes.',
    },
    governing_document: {
        term: 'Governing document',
        definition:
            'The trust deed, constitution or rules that set out how the organisation is run — including how the board meets and votes.',
    },
} as const satisfies Record<string, GovernanceGlossaryEntry>;

export type GovernanceTermKey = keyof typeof GOVERNANCE_GLOSSARY;

/** The glossary entry for a term key. */
export function glossaryEntry(
    term: GovernanceTermKey,
): GovernanceGlossaryEntry {
    return GOVERNANCE_GLOSSARY[term];
}
