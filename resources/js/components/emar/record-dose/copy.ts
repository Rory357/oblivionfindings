/* eMAR P01 — the words for each block the server reports (P00 v5 BLOCKS,
 * NF-07): why, the next step, and what can still be recorded. The server
 * sends keys and facts; the wording lives here so every screen says the
 * same thing. */
import type { Block, BlockAllKey, BlockGivenKey, CompetencyState, DoseLabel, DoseRequirements } from './types';

export type Still = 'none' | 'notgiven' | 'withheld' | 'withheld-always' | 'note';

export interface BlockCopy {
    title: string;
    text: string;
    next: string[];
    still: Still;
    /** Sentence shown instead of the "still" line when nothing can be recorded. */
    stillText?: string;
    tone: 'warning' | 'critical';
    /** A safety block (allergy) — the panel leads with the match line. */
    safety?: boolean;
    /** Show the roster and clock-in evidence (no eligible witness). */
    roster?: boolean;
    /** The panel's action, when there is a real one. */
    action?: 'clock-in' | 'eligibility' | 'message-lead';
}

/** "{NC}" renders as the "Not configured" chip (no on-call rule set for the house). */
export const NOT_CONFIGURED = '{NC}';

/**
 * The house's on-call contact as it reads in a sentence: "**Rangi Parata**
 * (021 555 0142)", why there's nobody, or the Not configured chip when the
 * house has no on-call rule (Settings › On-call).
 */
export function onCallText(req: DoseRequirements): string {
    const c = req.on_call;
    if (!c.configured) return NOT_CONFIGURED;
    if (c.name) return `**${c.name}**${c.phone ? ` (${c.phone})` : ''}`;

    return c.warning ?? 'nobody right now';
}

export const STILL_LINE: Record<Exclude<Still, 'none' | 'note'>, string> = {
    notgiven: 'You can still record a refusal, a withhold or an absence.',
    withheld: 'You can still record this dose as withheld and say why.',
    'withheld-always': 'You can always record this dose as withheld.',
};

const time = (iso: unknown) =>
    typeof iso === 'string' && iso
        ? new Date(iso).toLocaleTimeString('en-NZ', {
              hour: 'numeric',
              minute: '2-digit',
              timeZone: 'Pacific/Auckland',
          }).replace(/\s?([ap])\.?m\.?$/i, (_, p: string) => ` ${p.toLowerCase()}m`)
        : null;

// A date-only value is that calendar day: read at noon UTC and shown in UTC,
// so NZ's offset never moves it to the next day.
const day = (iso: unknown) =>
    typeof iso === 'string' && iso
        ? new Date(iso.length === 10 ? `${iso}T12:00:00Z` : iso).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'long',
              year: 'numeric',
              timeZone: iso.length === 10 ? 'UTC' : 'Pacific/Auckland',
          })
        : null;

const str = (v: unknown) => (typeof v === 'string' && v.trim() ? v.trim() : null);

/**
 * "Daniel Ahn, Mere Kahu and Jordan Tipene (from the roster and who is
 * clocked in)" — or the coordinator on call when nobody can (P01 v2 gap 6).
 */
export function whoSentence(req: DoseRequirements): string {
    const names = req.who_can_give.map((p) => `**${p.name}**`);
    if (names.length === 0) {
        return `Nobody else on shift can give it now — contact the coordinator on call: ${onCallText(req)}`;
    }
    const list = names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
    return `${list} (from the roster and who is clocked in).`;
}

/**
 * Nothing can be recorded. The server sends only the reason and the house
 * (P0-1), so the person and medicine come from the row that opened the
 * dialog — names the worker can already see there.
 */
export function blockAllCopy(block: Block<BlockAllKey>, label: DoseLabel | null): BlockCopy {
    const p = label?.person ?? 'this person';
    const med = label?.medicine ?? 'This medicine';
    const house = str(block.facts.house) ?? 'this house';
    switch (block.key) {
        case 'notClockedIn':
            return {
                title: 'You’re not clocked in',
                text: `To record for ${p}, you need to be clocked in on a shift that includes ${p}.`,
                next: ['Clock in from the top bar.', 'Can’t clock in? Contact the coordinator on call for your house.'],
                still: 'none',
                stillText: 'Nothing can be recorded until you’re clocked in.',
                tone: 'warning',
                action: 'clock-in',
            };
        case 'notOnShift':
            return {
                title: `${p} isn’t on your shift`,
                text: `You’re clocked in at ${house}, but your shift doesn’t include ${p}.`,
                next: [`Ask the house lead to add ${p} to your shift.`, 'Or contact the coordinator on call for your house.'],
                still: 'none',
                stillText: `You can’t record for ${p} until ${p} is on your shift.`,
                tone: 'warning',
            };
        case 'siteNotApproved':
            return {
                title: `Your access doesn’t include ${house}`,
                text: `${p} is at ${house}. Your account isn’t approved for that house.`,
                next: [
                    `If you work at ${house}, ask your manager to approve it for your account.`,
                    `Tell the ${house} lead that ${p}’s dose is still to be recorded.`,
                ],
                still: 'none',
                stillText: 'You can’t record for people at houses outside your access.',
                tone: 'warning',
            };
        case 'controlledNotAllowed':
            return {
                title: 'You can’t record controlled medicines',
                text: `${med} is a controlled medicine. Your account isn’t set up to record controlled medicines, so nothing can be recorded for this dose from your account.`,
                next: ['Ask a colleague who records controlled medicines to record this dose.', 'If you should be able to, ask your manager to check your account.'],
                still: 'none',
                stillText: 'Nothing can be recorded from your account for this dose.',
                tone: 'warning',
            };
        case 'awaitingVerification':
            return {
                title: 'This order is waiting to be checked',
                text: `${med} is a new or changed order. Someone who can check orders must verify it before anything is recorded for it.`,
                next: ['Ask the house lead to check it.'],
                still: 'none',
                stillText: 'Nothing can be recorded until the order is checked.',
                tone: 'warning',
            };
        case 'prnLimit':
        default: {
            const interval = block.facts.type === 'prn_interval';
            const last = time(block.facts.last_at);
            const max = typeof block.facts.max_24h === 'number' ? block.facts.max_24h : null;
            const count = typeof block.facts.count_24h === 'number' ? block.facts.count_24h : 0;
            const gap = typeof block.facts.min_hours_between === 'number' ? block.facts.min_hours_between : null;
            return {
                title: interval ? 'Too soon for another dose' : 'As-needed limit reached',
                text: interval
                    ? `${med}: the prescription asks for at least ${gap ?? '—'} hours between doses.${last ? ` The most recent was at ${last}.` : ''}`
                    : `${med}: the prescription allows ${max ?? '—'} doses in 24 hours. ${count} have been given in the last 24 hours${last ? ` — the most recent at ${last}` : ''}.`,
                next: ['Don’t give another dose.', `If ${p} still needs relief, contact the prescriber or the on-call contact for your house.`],
                still: 'note',
                stillText: `Add a note to the shift notes about what ${p} asked for.`,
                tone: 'critical',
            };
        }
    }
}

/** "Given" can't be recorded; a refusal, withhold or absence still can. */
export function blockCopy(block: Block<BlockGivenKey>, req: DoseRequirements): BlockCopy {
    const p = req.person.preferred_name;
    const med = req.order.name;
    const house = req.person.house ?? 'this house';
    switch (block.key) {
        case 'covertMissing': {
            const due = day(block.facts.review_date);
            return {
                title: 'No current covert plan',
                text: `${med} for ${p} is given covertly (hidden in food or drink, without ${p} knowing), but there’s no current authorisation on file${due ? ` — its review was due on ${due}` : ''}.`,
                next: ['Don’t give it covertly.', 'Contact the clinical lead.'],
                still: 'withheld',
                tone: 'critical',
            };
        }
        case 'noWitness':
            return {
                title: 'No eligible witness on shift',
                text: `${med} needs a witness: a different person, clocked in on a shift covering ${house} now, with controlled-medicine witness competency and a witness PIN set. Checked against the roster and clock-ins at ${time(req.checked_at) ?? 'now'}: nobody meets all of them.`,
                next: [`Contact the coordinator on call: ${onCallText(req)}`, 'Don’t ask someone who isn’t eligible to witness.'],
                still: 'withheld',
                tone: 'warning',
                roster: true,
            };
        case 'allergyBlocked': {
            const allergen = str(block.facts.allergen) ?? 'a recorded';
            const severe = ['severe', 'life_threatening'].includes(String(block.facts.severity ?? ''));
            return {
                title: 'Allergy match — this can’t be recorded as given',
                text: severe
                    ? `${p} has a ${String(block.facts.severity).replace('_', '-')} ${allergen} allergy on the allergy register.`
                    : `Your organisation doesn’t allow “given” when a medicine matches a recorded allergy.`,
                next: [`Ask the prescriber${req.order.prescriber ? `, ${req.order.prescriber},` : ''} to review the order.`, 'Record this dose as withheld.'],
                still: 'withheld-always',
                tone: 'critical',
                safety: true,
            };
        }
        case 'safetyBlocked':
        default:
            return {
                title: 'This dose can’t be recorded as given',
                text: str(block.facts.reason) ?? 'A safety check stops this dose being recorded as given.',
                next: ['Check with the house lead or the prescriber.'],
                still: 'withheld',
                tone: 'critical',
            };
    }
}

/** Competency blocks (P00 v5): "given" stops; refusal, withhold and absence stay open. */
export function competencyCopy(state: CompetencyState, req: DoseRequirements): BlockCopy | null {
    const message = req.competency.message;
    switch (state) {
        case 'expired':
            return {
                title: message?.replace(/^Medication competency/, 'Your medication competency').replace(/\.$/, '') ?? 'Your medication competency has expired',
                text: 'You can’t sign doses as given until you’re reassessed.',
                next: [`Ask a competent colleague to give this dose. ${whoSentence(req)}`, 'Book a reassessment with your assessor.'],
                still: 'notgiven',
                tone: 'critical',
                action: 'eligibility',
            };
        case 'not_current':
            return {
                title: 'Your medication competency isn’t current',
                text: message ?? 'You can’t sign doses as given until a competency assessor confirms your competency.',
                next: [`Ask a competent colleague to give this dose. ${whoSentence(req)}`, 'Ask a competency assessor to assess you.'],
                still: 'notgiven',
                tone: 'critical',
                action: 'eligibility',
            };
        case 'restricted':
            return {
                title: 'You can’t sign doses as given',
                text: 'Your medication competency is restricted. A competent colleague must give doses.',
                next: [`Ask a competent colleague to give this dose. ${whoSentence(req)}`, 'Ask a competency assessor to review the restriction.'],
                still: 'notgiven',
                tone: 'critical',
                action: 'eligibility',
            };
        case 'area':
            return {
                title: 'You can’t sign this dose as given',
                text: message ?? 'Your competency assessment doesn’t cover this medicine.',
                next: [`Ask a competent colleague to give this dose. ${whoSentence(req)}`],
                still: 'notgiven',
                tone: 'critical',
                action: 'eligibility',
            };
        default:
            return null;
    }
}

/** Why the MAR doesn't offer one-click "Mark given" (P01 Q6), in plain words. */
export const NOT_SIMPLE_LABEL: Record<string, string> = {
    blocked: 'Nothing can be recorded right now',
    blocked_given: 'A check stops “given”',
    as_needed: 'As-needed doses always use the full record',
    not_yet_due: 'Not yet due',
    outside_window: 'Outside today’s window — needs a reason',
    competency: 'Your competency doesn’t cover “given”',
    witness: 'Controlled or witnessed medicine — needs a witness',
    rules: 'Medication rules ask for a reading or a second person',
    cosigner: 'Your restricted competency needs a co-signer',
    support: 'Support isn’t “Administer” — record how it was taken',
    variable: 'Variable amount — choose the amount',
    covert: 'Covert — check the covert plan',
    allergy_unavailable: 'The allergy record couldn’t be loaded',
    allergy_match: 'Possible allergy match — check first',
    order_check: 'The order is waiting to be checked',
};
