/**
 * "Your follow-ups" (decision 4, Stephan 1 Nov 2026; board G4-5): the restraint follow-ups a person owns, such as
 * "Tell Aroha's family/whānau what happened", and recording the outcome on one. A support worker reaches it with
 * restraints.followups.own: it shows only their own follow-ups — the follow-up's words, when it's due and its outcome —
 * never the restraint record, its plan, its people or its other follow-ups. The server re-checks ownership, Site and
 * person access on every save.
 */
import { ActualEventTimeField, actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { FOLLOWUP_OUTCOME_LABEL } from '@/components/health-safety/restraints/followup-outcomes';
import type { OwnRestraintFollowup } from '@/components/health-safety/restraints/types';
import { RecordIdentity, RecordTable, type RecordTableColumn } from '@/components/incidents/record-table';
import { compactMenu } from '@/components/lists/entity-menu';
import { PageHeader, PageLayout } from '@/components/page';
import { PopupField, PopupForm } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime, toDatetimeLocal } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { ClipboardCheck, ClipboardList } from 'lucide-react';
import { useState } from 'react';

const PAGE = '/health-safety/restraints/my-followups';
const OUTCOMES = ['contacted', 'attempted', 'unable_to_contact', 'declined', 'not_required', 'recorded', 'other'];

type Props = { followups: OwnRestraintFollowup[] };

export default function RestraintOwnFollowups({ followups }: Props) {
    const [open, setOpen] = useState<OwnRestraintFollowup | null>(null);
    const columns: RecordTableColumn<OwnRestraintFollowup>[] = [
        {
            key: 'followup',
            label: 'Follow-up',
            className: 'min-w-[16rem]',
            cell: (item) => <RecordIdentity title={<span className="whitespace-pre-wrap">{item.title}</span>} sub={item.outcome_notes ?? undefined} />,
        },
        {
            key: 'due',
            label: 'Due',
            nowrap: true,
            cell: (item) =>
                item.due_at ? <RecordIdentity title={formatDate(item.due_at)} sub={formatTime(item.due_at)} /> : <span className="text-muted-foreground">No due time</span>,
        },
        {
            key: 'status',
            label: 'Outcome',
            nowrap: true,
            cell: (item) =>
                item.completed_at ? (
                    <RecordIdentity
                        title={<StatusBadge variant="success">{item.outcome ? (FOLLOWUP_OUTCOME_LABEL[item.outcome] ?? 'Done') : 'Done'}</StatusBadge>}
                        sub={formatDate(item.completed_at)}
                    />
                ) : (
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge variant="warning">Open</StatusBadge>
                        {item.can_complete ? (
                            <Button type="button" size="sm" variant="outline" onClick={() => setOpen(item)}>
                                Record outcome
                            </Button>
                        ) : null}
                    </div>
                ),
        },
    ];
    return (
        <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Your follow-ups', href: PAGE }]}>
            <Head title="Your follow-ups" />
            <PageLayout hero={<PageHeader title="Your follow-ups" icon={ClipboardList} subline="Restraint follow-ups you own · only you record the outcome" />}>
                <RecordTable
                    title="Follow-ups"
                    sub="Open ones first · done ones stay here for 30 days"
                    ariaLabel="Your follow-ups"
                    columns={columns}
                    rows={followups}
                    rowKey={(item) => item.id}
                    actionsFor={(item) => compactMenu([item.can_complete && { label: 'Record outcome', icon: ClipboardCheck, onClick: () => setOpen(item) }])}
                    menuTitle={(item) => item.title}
                    menuIcon={ClipboardList}
                    empty={
                        <p className="sg-quiet px-4 py-5">
                            <span>You have no restraint follow-ups right now.</span>
                        </p>
                    }
                />
            </PageLayout>
            {open ? <RecordOutcome key={open.id} followup={open} onClose={() => setOpen(null)} /> : null}
        </AppLayout>
    );
}

function RecordOutcome({ followup, onClose }: { followup: OwnRestraintFollowup; onClose: () => void }) {
    // One key per opened form, so a retry after a lost response can never record the outcome twice.
    const [key] = useState(() => crypto.randomUUID());
    const [when, setWhen] = useState(() => ({ value: toDatetimeLocal(new Date()), offset: '' }));
    const [outcome, setOutcome] = useState('');
    const [notes, setNotes] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const instant = actualEventInstant(when.value, when.offset);
    const general = errors.expected_version || errors.command || errors.followup_id;
    const submit = () => {
        setProcessing(true);
        router.post(
            `${PAGE}/${followup.id}/complete`,
            { completed_at: instant, outcome, notes, idempotency_key: key, expected_version: followup.expected_version },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (next) => setErrors(next),
                onFinish: () => setProcessing(false),
            },
        );
    };
    return (
        <PopupForm
            open
            onClose={onClose}
            icon={ClipboardCheck}
            title="Record outcome"
            description="Say what happened. Once it’s saved, only someone who reviews restraints can correct it."
            context={{ title: followup.title, icon: ClipboardList }}
            error={general ? 'This follow-up changed since you opened the page. Reload the page and try again; your words are kept here.' : undefined}
            submitLabel="Save outcome"
            processingLabel="Saving…"
            processing={processing}
            submitDisabled={!instant || !outcome || !notes.trim()}
            onSubmit={submit}
            dirty={Boolean(outcome || notes)}
            draftName="this outcome"
        >
            <ActualEventTimeField
                id="followup-completed-at"
                label="When it was done"
                value={when.value}
                offset={when.offset}
                error={errors.completed_at}
                onChange={(value, offset) => setWhen({ value, offset })}
            />
            <PopupField label="Outcome" htmlFor="followup-outcome" required error={errors.outcome}>
                <SelectInput
                    id="followup-outcome"
                    value={outcome}
                    onChange={setOutcome}
                    placeholder="Choose the outcome"
                    options={OUTCOMES.map((value) => ({ value, label: FOLLOWUP_OUTCOME_LABEL[value] }))}
                />
            </PopupField>
            <PopupField label="What happened" htmlFor="followup-notes" required error={errors.notes}>
                <Textarea id="followup-notes" value={notes} maxLength={10000} rows={4} onChange={(event) => setNotes(event.target.value)} />
            </PopupField>
        </PopupForm>
    );
}
