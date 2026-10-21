import {
    ArrowRight,
    Check,
    Clock,
    FileText,
    Phone,
    ShieldCheck,
    Users,
    Wallet,
} from 'lucide-react';
import { usePrototype } from './context';
import { coverage, date, money } from './data';
import { followupsFor, knownNumber } from './model';
import { Button } from './ui';
export function CaseOverview({ r, onTab }) {
    const { can, request } = usePrototype();
    const tasks = followupsFor(r).filter((w) => !w.primary && !w.financial);
    const checks = [
        ['Identity', r.identity],
        ['Authority & sharing', r.consent],
        ['Provider choice', r.choice],
        ['Support suitability', r.suitability],
        ['Capacity', r.capacity],
        ['Safe start', r.safety],
    ];
    return (
        <div className="case-overview">
            <div className="case-main-column">
                <section className="person-brief">
                    <div className="person-brief-heading">
                        <div className="person-monogram">
                            {r.name
                                .split(' ')
                                .map((n) => n[0])
                                .slice(0, 2)
                                .join('')}
                        </div>
                        <div>
                            <p className="eyebrow text-caption">
                                GETTING TO KNOW{' '}
                                {r.preferred?.toUpperCase() || 'THE PERSON'}
                            </p>
                            <h2 className="text-section-title">
                                What matters to {r.preferred || r.name}
                            </h2>
                        </div>
                        {can('edit') && (
                            <Button
                                variant="ghost"
                                onClick={() => request('assessment', r)}
                            >
                                Edit details
                            </Button>
                        )}
                    </div>
                    <p className="person-statement">
                        {can('sensitive') ||
                        r.needsAudience === 'General intake'
                            ? r.needs
                            : r.generalSummary}
                    </p>
                    <div className="person-facts">
                        <div>
                            <span>Requested support</span>
                            <strong>{r.service}</strong>
                        </div>
                        <div>
                            <span>Receiving site</span>
                            <strong>{r.site}</strong>
                        </div>
                        <div>
                            <span>Referral source</span>
                            <strong>
                                {r.source} · {r.relationship}
                            </strong>
                        </div>
                        <div>
                            <span>Person’s choice</span>
                            <strong>
                                {r.choice
                                    ? 'Confirmed'
                                    : 'A conversation to have'}
                            </strong>
                        </div>
                    </div>
                    <div className="safe-contact">
                        <Phone />
                        <div>
                            <strong>
                                {r.safeContactPerson
                                    ? r.safeContactPerson + ' · '
                                    : ''}
                                {r.safeContactPhone ||
                                    r.phone ||
                                    r.email ||
                                    'Contact details to confirm'}
                            </strong>
                            <p className="text-subtle">{r.safe}</p>
                        </div>
                        {can('contact') && (
                            <Button
                                variant="outline"
                                onClick={() => request('contact', r)}
                            >
                                Log contact
                            </Button>
                        )}
                    </div>
                    <div className="authority-line">
                        <ShieldCheck />
                        <span>
                            Authority: <strong>{r.authority}</strong>
                        </span>
                        <Button
                            variant="link"
                            onClick={() => onTab('Contacts')}
                        >
                            Contacts & authority <ArrowRight />
                        </Button>
                    </div>
                </section>
                <section className="readiness-section">
                    <header>
                        <div>
                            <p className="eyebrow text-caption">
                                MOVE FORWARD WITH CONFIDENCE
                            </p>
                            <h2 className="text-section-title">
                                A safe, considered handover
                            </h2>
                        </div>
                        {can('edit') && (
                            <Button
                                variant="outline"
                                onClick={() => request('assessment', r)}
                            >
                                Review checks
                            </Button>
                        )}
                    </header>
                    {can('sensitive') ? (
                        <div className="readiness-items">
                            {checks.map(([label, done]) => (
                                <div
                                    className={
                                        done
                                            ? 'readiness-item is-complete'
                                            : 'readiness-item'
                                    }
                                    key={label}
                                >
                                    <span className="readiness-check">
                                        {done ? <Check /> : <Clock />}
                                    </span>
                                    <strong>{label}</strong>
                                    <span>
                                        {done ? 'Confirmed' : 'To review'}
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-subtle">
                            Assessment details are restricted for your current
                            role.
                        </p>
                    )}
                    <div className="readiness-foot">
                        <FileText />
                        <p className="text-subtle">
                            Evidence stays with the referral and keeps its
                            access rules after handover.
                        </p>
                        <Button
                            variant="link"
                            onClick={() => onTab('Evidence')}
                        >
                            View evidence <ArrowRight />
                        </Button>
                    </div>
                </section>
                <section className="case-followups">
                    <header>
                        <div>
                            <p className="eyebrow text-caption">
                                KEEP THE CONVERSATION MOVING
                            </p>
                            <h2 className="text-section-title">
                                Upcoming follow-ups{' '}
                                <span className="quiet-count">
                                    {tasks.length}
                                </span>
                            </h2>
                        </div>
                        {can('tasks') && (
                            <Button
                                variant="ghost"
                                onClick={() => request('task', r)}
                            >
                                Add follow-up
                            </Button>
                        )}
                    </header>
                    {tasks.length ? (
                        tasks.slice(0, 3).map((t) => (
                            <div className="followup-item" key={t.id}>
                                <div>
                                    <strong>{t.label}</strong>
                                    <p className="text-subtle">
                                        {t.owner} · {date(t.due)}
                                    </p>
                                </div>
                                {can('tasks') && (
                                    <Button
                                        variant="outline"
                                        aria-label={'Work on ' + t.label}
                                        onClick={() =>
                                            request('workEdit', r, { work: t })
                                        }
                                    >
                                        Review
                                    </Button>
                                )}
                            </div>
                        ))
                    ) : (
                        <p className="text-subtle">
                            All planned follow-ups are complete.
                        </p>
                    )}
                    <Button variant="link" onClick={() => onTab('Tasks')}>
                        All tasks & completed work <ArrowRight />
                    </Button>
                </section>
            </div>
            <aside className="case-action-column">
                <section className="next-step-card">
                    <div className="next-step-label">
                        <span className="settings-icon">
                            <ArrowRight />
                        </span>
                        <span>THE NEXT STEP</span>
                    </div>
                    <h2 className="text-section-title">{r.next}</h2>
                    <div className="next-step-owner">
                        <span className="small-avatar">
                            {r.owner === 'Intake team'
                                ? 'IT'
                                : r.owner
                                      .split(' ')
                                      .map((n) => n[0])
                                      .join('')}
                        </span>
                        <div>
                            <strong>{r.owner}</strong>
                            <p className="text-subtle">{r.team}</p>
                        </div>
                        {can('assign') && (
                            <Button
                                variant="ghost"
                                aria-label="Change owner"
                                onClick={() => request('assign', r)}
                            >
                                <Users />
                            </Button>
                        )}
                    </div>
                    <div className="next-step-due">
                        <Clock />
                        <span>
                            Follow up by <strong>{date(r.due)}</strong>
                        </span>
                    </div>
                    {can('tasks') && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                request('workEdit', r, {
                                    work: followupsFor(r, true).find(
                                        (w) => w.primary,
                                    ),
                                })
                            }
                        >
                            Work on next action
                        </Button>
                    )}
                    {can('contact') && (
                        <Button
                            className="w-full"
                            onClick={() => request('contact', r)}
                        >
                            Record an update <ArrowRight />
                        </Button>
                    )}
                    {can('assign') && r.owner === 'Intake team' && (
                        <Button
                            variant="outline"
                            className="w-full"
                            onClick={() => request('assign', r)}
                        >
                            Assign a coordinator
                        </Button>
                    )}
                </section>
                <section className="funding-snapshot">
                    <Wallet />
                    <h3 className="text-section-title">Funding at a glance</h3>
                    {can('fundingRead') ? (
                        <>
                            <strong className="funding-total">
                                {money(coverage(r))}
                                <span> / week verified</span>
                            </strong>
                            <p className="text-subtle">
                                {!knownNumber(r.proposal.weekly)
                                    ? 'Support proposal is not costed yet.'
                                    : coverage(r) < Number(r.proposal.weekly)
                                      ? money(r.proposal.weekly - coverage(r)) +
                                        ' remains to arrange against the current proposal.'
                                      : 'The current proposal is covered by verified funding.'}
                            </p>
                            <div className="coverage-track">
                                <span
                                    style={{
                                        width:
                                            Math.min(
                                                100,
                                                (coverage(r) /
                                                    Math.max(
                                                        1,
                                                        Number(
                                                            r.proposal.weekly,
                                                        ),
                                                    )) *
                                                    100,
                                            ) + '%',
                                    }}
                                />
                            </div>
                            <p className="text-caption">
                                {r.funding.length} assistance case(s) ·{' '}
                                {r.funding.filter((f) => !f.verified).length}{' '}
                                awaiting progress
                            </p>
                        </>
                    ) : (
                        <p className="text-subtle">
                            Financial details require additional permission.
                        </p>
                    )}
                    <Button variant="link" onClick={() => onTab('Funding')}>
                        Open funding workspace <ArrowRight />
                    </Button>
                </section>
                <details className="case-management">
                    <summary>
                        <SettingsIcon />
                        Case management
                    </summary>
                    <p className="text-subtle">
                        Change stage, arrange a hold or resolve a duplicate with
                        a recorded reason.
                    </p>
                    {can('edit') && (
                        <div className="stack">
                            <Button
                                variant="outline"
                                onClick={() => request('lifecycle', r)}
                            >
                                Stage / hold / close
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => request('duplicate', r)}
                            >
                                Review a duplicate
                            </Button>
                        </div>
                    )}
                </details>
            </aside>
        </div>
    );
}
function SettingsIcon() {
    return <ShieldCheck className="size-4" />;
}
