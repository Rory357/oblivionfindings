import { Head, Link, useForm } from '@inertiajs/react';
import { BookOpen, CheckCircle2, Clock, Tag } from 'lucide-react';
import { useMemo, useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { EntityChip, EntityStatusChip, ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
import { PageProps } from '@/types';

import { POLICY_CATEGORIES, policyCategoryLabel } from './_dialogs';

interface PolicySummary {
    id: number;
    title: string;
    category: string;
    version: number;
    effective_from: string | null;
    next_review_date: string | null;
    my_attestation: {
        acknowledged: boolean;
        acknowledged_at: string | null;
        notes: string | null;
    } | null;
    total_required: number;
    total_attested: number | null;
}

interface Props extends PageProps {
    outstanding: PolicySummary[];
    completed: PolicySummary[];
    canManage: boolean;
    summary: {
        outstanding_count: number;
        completed_count: number;
        board_member_count: number;
    };
}

type ShowFilter = 'all' | 'outstanding' | 'completed';

function AttestForm({ policy }: { policy: PolicySummary }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        acknowledged: true,
        notes: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/governance/policies/${policy.id}/attest`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    if (!open) {
        return (
            <Button size="sm" onClick={() => setOpen(true)}>
                Attest to this policy
            </Button>
        );
    }

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-3 rounded-lg border border-border bg-muted/30 p-3"
        >
            <label className="flex items-start gap-2 text-sm">
                <Checkbox
                    checked={form.data.acknowledged}
                    onCheckedChange={(v) =>
                        form.setData('acknowledged', v === true)
                    }
                />
                <span>I have read and understood this policy.</span>
            </label>
            <Textarea
                rows={3}
                aria-label="Attestation notes"
                placeholder="Optional notes (e.g. queries, clarifications)"
                value={form.data.notes}
                onChange={(e) => form.setData('notes', e.target.value)}
            />
            <div className="flex items-center justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setOpen(false)}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    disabled={!form.data.acknowledged || form.processing}
                >
                    {form.processing ? 'Recording…' : 'Confirm attestation'}
                </Button>
            </div>
        </form>
    );
}

export default function PolicyAttestations({
    auth,
    outstanding,
    completed,
    canManage,
    summary,
}: Props) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const [show, setShow] = useState<ShowFilter>('all');

    const matches = useMemo(() => {
        const q = search.trim().toLowerCase();
        return (policy: PolicySummary) =>
            (category === 'all' || policy.category === category) &&
            (q === '' || policy.title.toLowerCase().includes(q));
    }, [search, category]);

    const visibleOutstanding = outstanding.filter(matches);
    const visibleCompleted = completed.filter(matches);
    const total = summary.outstanding_count + summary.completed_count;
    const donePercent = total > 0 ? (summary.completed_count / total) * 100 : 0;
    const filtered = search.trim() !== '' || category !== 'all';

    const header = (
        <PageHeader
            icon={BookOpen}
            title="Policy attestations"
            subline="Record that you have read and understood each approved governance policy"
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search approved policies…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Outstanding"
                        tone={summary.outstanding_count > 0 ? 'warning' : 'success'}
                        ariaLabel="View outstanding attestations"
                        onClick={() => setShow('outstanding')}
                    >
                        <PageHeaderMeterBig>
                            {summary.outstanding_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            policies awaiting your sign-off
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Completed"
                        value={`${summary.completed_count}/${total}`}
                        ariaLabel="View completed attestations"
                        onClick={() => setShow('completed')}
                    >
                        <PageHeaderMeterBar percent={donePercent} />
                        <PageHeaderMeterCaption>
                            {Math.round(donePercent)}% of approved policies
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Board members"
                        ariaLabel={
                            auth.can?.governance?.meetings?.manage
                                ? 'View board members'
                                : 'View policies'
                        }
                        href={
                            auth.can?.governance?.meetings?.manage
                                ? '/governance/admin/board-members'
                                : '/governance/policies'
                        }
                    >
                        <PageHeaderMeterBig>
                            {summary.board_member_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            active members in scope
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Tag}
                        label="All categories"
                        value={category}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...POLICY_CATEGORIES.map((c) => ({
                                value: c.key,
                                label: c.label,
                            })),
                        ]}
                        onChange={setCategory}
                    />
                    <PageHeaderFilterSelect
                        label="Outstanding & completed"
                        value={show}
                        options={[
                            { value: 'all', label: 'Outstanding & completed' },
                            { value: 'outstanding', label: 'Outstanding only' },
                            { value: 'completed', label: 'Completed only' },
                        ]}
                        onChange={(v) => setShow(v as ShowFilter)}
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Policies', href: '/governance/policies' },
                {
                    title: 'Attestations',
                    href: '/governance/policies/attestations',
                },
            ]}
        >
            <Head title="Policy attestations" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {show !== 'completed' ? (
                        <section className="flex flex-col gap-3">
                            <ListCaption
                                title="Outstanding"
                                caption={`${visibleOutstanding.length} of ${outstanding.length} shown`}
                            />
                            {visibleOutstanding.length === 0 ? (
                                <EmptyState
                                    icon={filtered ? Clock : CheckCircle2}
                                    variant="compact"
                                    title={
                                        filtered
                                            ? 'No outstanding policies match'
                                            : "You're up to date"
                                    }
                                    description={
                                        filtered
                                            ? 'Try clearing the search or category.'
                                            : 'All approved policies have been attested to.'
                                    }
                                />
                            ) : (
                                <Card className="gap-0 overflow-hidden rounded-[14px] py-0">
                                    <ul className="divide-y divide-border">
                                        {visibleOutstanding.map((policy) => (
                                            <li
                                                key={policy.id}
                                                className="flex flex-col gap-3 p-4"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={`/governance/policies/${policy.id}`}
                                                        className="text-[13px] font-semibold text-foreground underline-offset-4 hover:underline"
                                                    >
                                                        {policy.title}
                                                    </Link>
                                                    <EntityChip>
                                                        v{policy.version}
                                                    </EntityChip>
                                                    <EntityChip icon={Tag}>
                                                        {policyCategoryLabel(
                                                            policy.category,
                                                        )}
                                                    </EntityChip>
                                                    {canManage &&
                                                    policy.total_attested !==
                                                        null ? (
                                                        <span className="ml-auto text-caption">
                                                            {policy.total_attested}/
                                                            {policy.total_required}{' '}
                                                            board members attested
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className="text-caption">
                                                    Effective{' '}
                                                    {formatDateOnly(
                                                        policy.effective_from,
                                                    )}{' '}
                                                    · Next review{' '}
                                                    {formatDateOnly(
                                                        policy.next_review_date,
                                                    )}
                                                </p>
                                                <div>
                                                    <AttestForm policy={policy} />
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </Card>
                            )}
                        </section>
                    ) : null}

                    {show !== 'outstanding' ? (
                        <section className="flex flex-col gap-3">
                            <ListCaption
                                title="Completed"
                                caption={`${visibleCompleted.length} of ${completed.length} shown`}
                            />
                            {visibleCompleted.length === 0 ? (
                                <EmptyState
                                    icon={Clock}
                                    variant="compact"
                                    title={
                                        filtered
                                            ? 'No completed attestations match'
                                            : 'No attestations recorded yet'
                                    }
                                    description={
                                        filtered
                                            ? 'Try clearing the search or category.'
                                            : 'Policies you have attested to will appear here.'
                                    }
                                />
                            ) : (
                                <Card className="gap-0 overflow-hidden rounded-[14px] py-0">
                                    <ul className="divide-y divide-border">
                                        {visibleCompleted.map((policy) => (
                                            <li key={policy.id}>
                                                <Link
                                                    href={`/governance/policies/${policy.id}`}
                                                    className="flex flex-wrap items-center gap-2 p-4 transition-colors hover:bg-primary/5"
                                                >
                                                    <span className="text-[13px] font-semibold">
                                                        {policy.title}
                                                    </span>
                                                    <EntityChip>
                                                        v{policy.version}
                                                    </EntityChip>
                                                    <EntityStatusChip variant="success">
                                                        Attested
                                                    </EntityStatusChip>
                                                    <span className="ml-auto text-caption">
                                                        {formatDateLong(
                                                            policy.my_attestation
                                                                ?.acknowledged_at,
                                                            '',
                                                        )}
                                                    </span>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </Card>
                            )}
                        </section>
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
