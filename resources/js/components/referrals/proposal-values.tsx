import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { ClipboardList } from 'lucide-react';
import type { SupportProposal } from './proposal-types';
import { label } from './types';

export function ProposalValues({ proposal }: { proposal: SupportProposal }) {
    return (
        <div className="space-y-4">
            <ReviewCard title={proposal.terms.title} icon={ClipboardList}>
                <ReviewRow
                    label="Proposed dates"
                    value={`${formatDateOnly(proposal.terms.starts_on)} – ${formatDateOnly(proposal.terms.ends_on)}`}
                />
                <ReviewRow
                    label="Support rationale"
                    value={proposal.terms.support_rationale}
                />
                <ReviewRow
                    label="Person’s participation"
                    value={proposal.terms.person_participation}
                />
                <ReviewRow
                    label="Capacity plan"
                    value={proposal.terms.capacity_plan}
                />
                <ReviewRow
                    label="Funding position"
                    value={proposal.terms.funding_position}
                />
            </ReviewCard>
            {proposal.terms.lines.map((line) => (
                <ReviewCard
                    key={line.key}
                    title={line.description}
                    icon={ClipboardList}
                >
                    <ReviewRow
                        label="Support quantity"
                        value={`${line.quantity ?? 'Unknown'} ${line.unit === 'other' ? line.unit_label : line.unit} / ${label(line.period)}`}
                    />
                    <ReviewRow
                        label="Unit price"
                        value={
                            line.unit_rate === null
                                ? 'Not yet costed'
                                : `${line.currency} ${line.unit_rate} · ${label(line.price_basis)}`
                        }
                    />
                    <ReviewRow label="Price source" value={line.price_source} />
                    <ReviewRow
                        label="Tax treatment"
                        value={
                            line.tax
                                ? `${line.tax.name} · ${label(line.tax.type)}`
                                : 'Not yet reviewed'
                        }
                    />
                    <ReviewRow
                        label="Estimated total for this period"
                        value={
                            line.estimate?.known
                                ? `${line.currency} ${line.estimate.gross} / ${label(line.period)}`
                                : 'Not yet costed'
                        }
                    />
                </ReviewCard>
            ))}
            <ReviewCard title="Estimates by budget period" icon={ClipboardList}>
                {proposal.terms.totals.map((total) => (
                    <ReviewRow
                        key={`${total.currency}-${total.period}`}
                        label={`${total.currency} / ${label(total.period)}`}
                        value={
                            total.known
                                ? total.known_gross
                                : `${total.known_gross} known · ${total.unknown_lines} lines not yet costed`
                        }
                    />
                ))}
                <p className="text-subtle">
                    These are proposal estimates. Different periods and
                    currencies are kept separate. Actual billing remains in the
                    service agreement and finance workflow.
                </p>
            </ReviewCard>
        </div>
    );
}
