import { dateKey } from '@/lib/calendar/recur';
import SiteCalendar from '@/pages/sites/calendar/SiteCalendar';
import { CalendarDays } from 'lucide-react';
import { useMemo, useState } from 'react';
import { usePrototype } from './context';
import { TODAY, date } from './data';
import { followupsFor, staffDirectory } from './model';
import {
    Button,
    Empty,
    Field,
    Modal,
    Notice,
    ReviewCard,
    ReviewRow,
} from './ui';
const sources = [
    {
        key: 'checklist',
        label: 'Follow-ups',
        short: 'Follow-up',
        group: 'auto',
        icon: 'CheckSquare',
        origin: 'Referrals',
        note: 'Referral next actions and tasks',
    },
    {
        key: 'event',
        label: 'Assessments',
        short: 'Assessment',
        group: 'auto',
        icon: 'Users',
        origin: 'Referrals',
        note: 'Scheduled and completed appointments',
    },
    {
        key: 'compliance',
        label: 'Funding reviews',
        short: 'Funding review',
        group: 'auto',
        icon: 'ClipboardList',
        origin: 'Funding assistance',
        note: 'Independent case deadlines',
    },
];
export function ReferralCalendar() {
    const { data, scoped, can, role, actor, request, navigate } =
        usePrototype();
    const [dialog, setDialog] = useState(null),
        [choice, setChoice] = useState('Follow-up'),
        [referral, setReferral] = useState('');
    const sites = data.config.regions.map((name, i) => ({
        id: i + 1,
        name,
        type: 'supported_living',
    }));
    const events = useMemo(
        () =>
            scoped.flatMap((r) => {
                const make = (work, appointment = false) => ({
                    id: r.id + '-' + work.id,
                    source: appointment
                        ? 'event'
                        : work.financial
                          ? 'compliance'
                          : 'checklist',
                    group: 'auto',
                    title:
                        r.name +
                        ' · ' +
                        (appointment ? work.title : work.label),
                    typeLabel: appointment
                        ? 'Assessment'
                        : work.financial
                          ? 'Funding review'
                          : 'Follow-up',
                    start:
                        (work.due || work.date) +
                        'T' +
                        (work.time || '00:00') +
                        ':00',
                    end:
                        appointment && work.time
                            ? new Date(
                                  new Date(
                                      work.date + 'T' + work.time + ':00',
                                  ).getTime() +
                                      Number(work.duration) * 60000,
                              ).toISOString()
                            : null,
                    allDay: !work.time,
                    status:
                        work.status !== 'Cancelled' &&
                        work.status !== 'Did not attend' &&
                        (work.done || work.status === 'Completed')
                            ? 'completed'
                            : work.status === 'Cancelled' ||
                                work.status === 'Did not attend'
                              ? 'cancelled'
                              : (work.due || work.date) < TODAY
                                ? 'overdue'
                                : 'scheduled',
                    owner: {
                        id:
                            staffDirectory.find((p) => p.name === work.owner)
                                ?.id || 1,
                        name: work.owner,
                    },
                    site: sites.find((s) => s.name === r.site) || null,
                    ref: r.id,
                    link: null,
                    editable: can(work.financial ? 'funding' : 'tasks'),
                    desc: r.safe,
                    referralId: r.id,
                    work,
                    appointment,
                });
                return [
                    ...followupsFor(r, true)
                        .filter(
                            (w) =>
                                (!w.financial || can('fundingRead')) &&
                                (r.stage !== 'Closed' || w.done),
                        )
                        .map((w) => make(w)),
                    ...(r.appointments || [])
                        .filter((a) => !a.fundingId || can('fundingRead'))
                        .map((a) => make(a, true)),
                ].filter((e) => e.start && !e.start.startsWith('undefined'));
            }),
        [data.records, data.config.regions, role, data.roles],
    );
    const openWork = (entry, dateOverride) => {
        const r = scoped.find((r) => r.id === entry.referralId);
        setDialog(null);
        request(
            entry.appointment ? 'appointment' : 'workEdit',
            r,
            entry.appointment
                ? { appointment: entry.work, ...dateOverride }
                : { work: entry.work, ...dateOverride },
        );
    };
    const adapter = useMemo(
        () => ({
            title: 'Referral calendar',
            subline:
                'Follow-ups, assessments and funding reviews · Pacific/Auckland',
            mobileSummary: 'Your referral schedule',
            responsiveAgenda: true,
            currentUserId:
                staffDirectory.find((p) => p.name === actor)?.id || null,
            allowSubscriptions: false,
            allowExport: can('export'),
            showApprovalMeter: false,
            sourceFilters: can('fundingRead') ? sources : sources.slice(0, 2),
            initialSources: sources.map((s) => s.key),
            initialView: matchMedia('(max-width:640px)').matches
                ? 'agenda'
                : 'month',
            searchPlaceholder: 'Search permitted referral work',
            mineLink: {
                href: '#referral-follow-ups',
                label: 'Open my calendar work',
            },
            onOpenMine: (items) => setDialog({ type: 'mine', items }),
            exportFilename: 'fictional-referral-calendar.ics',
            loadItems: async ({ start, end }) => ({
                events: events.filter(
                    (e) =>
                        new Date(e.start) >= start && new Date(e.start) < end,
                ),
            }),
            onOpenItem: (entry) => {
                setDialog({ type: 'detail', entry });
                return true;
            },
            onCreate: (seed) => {
                setReferral(
                    scoped.find(
                        (r) => !['Closed', 'Converted'].includes(r.stage),
                    )?.id ||
                        scoped[0]?.id ||
                        '',
                );
                setDialog({ type: 'create', date: dateKey(seed.date) });
            },
            onMove: (entry, start) =>
                openWork(entry, { date: dateKey(start), due: dateKey(start) }),
        }),
        [events, actor, data.roles],
    );
    if (!can('view')) return <Empty title="Calendar access is restricted" />;
    const entry = dialog?.entry,
        r = entry && scoped.find((r) => r.id === entry.referralId);
    return (
        <>
            <SiteCalendar
                key={role}
                context="page"
                scope="global"
                sites={sites.filter((s) =>
                    scoped.some((r) => r.site === s.name),
                )}
                canCreate={can('tasks') && scoped.length > 0}
                canManage={can('tasks') || can('funding')}
                canApprove={false}
                dataAdapter={adapter}
            />
            {dialog?.type === 'detail' && r && (
                <Modal
                    title={r.name}
                    description={entry.title.slice(r.name.length + 3)}
                    onClose={() => setDialog(null)}
                    actions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setDialog(null);
                                    navigate('Referrals', r.id, 'Tasks');
                                }}
                            >
                                Open referral
                            </Button>
                            <Button
                                disabled={!entry.editable}
                                onClick={() => openWork(entry)}
                            >
                                {entry.appointment
                                    ? 'Review appointment'
                                    : 'Work on follow-up'}
                            </Button>
                        </>
                    }
                >
                    <ReviewCard icon={CalendarDays} title="Scheduled work">
                        <ReviewRow
                            label="When"
                            value={
                                date(entry.start.slice(0, 10)) +
                                ' · ' +
                                (entry.work.time || 'All day')
                            }
                        />
                        <ReviewRow label="Owner" value={entry.owner.name} />
                        <ReviewRow label="State" value={entry.status} />
                        <ReviewRow label="Safe contact" value={r.safe} />
                        {entry.appointment && (
                            <>
                                <ReviewRow
                                    label="Location / participants"
                                    value={
                                        entry.work.location +
                                        ' · ' +
                                        entry.work.attendees
                                    }
                                />
                                <ReviewRow
                                    label="Outcome"
                                    value={entry.work.outcome || 'Not recorded'}
                                />
                            </>
                        )}
                    </ReviewCard>
                </Modal>
            )}
            {dialog?.type === 'mine' && (
                <Modal
                    title="My calendar work"
                    description="Exactly the entries counted by Mine, with the current calendar date, site, source and search filters."
                    onClose={() => setDialog(null)}
                >
                    {dialog.items.length ? (
                        dialog.items.map((entry) => (
                            <div className="work-row" key={entry.id}>
                                <div className="grow">
                                    <strong>{entry.title}</strong>
                                    <p className="text-subtle">
                                        {entry.owner.name} ·{' '}
                                        {date(entry.start.slice(0, 10))} ·{' '}
                                        {entry.status}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setDialog({ type: 'detail', entry })
                                    }
                                >
                                    Open entry
                                </Button>
                            </div>
                        ))
                    ) : (
                        <Empty title="No entries assigned to you in this view" />
                    )}
                </Modal>
            )}
            {dialog?.type === 'create' && (
                <Modal
                    title="New calendar entry"
                    onClose={() => setDialog(null)}
                    actions={
                        <Button
                            disabled={!referral}
                            onClick={() => {
                                const r = scoped.find((x) => x.id === referral),
                                    date = dialog.date;
                                setDialog(null);
                                request(
                                    choice === 'Assessment appointment'
                                        ? 'appointment'
                                        : 'task',
                                    r,
                                    { date, due: date },
                                );
                            }}
                        >
                            Continue
                        </Button>
                    }
                >
                    <Field
                        label="Entry type"
                        type="select"
                        options={['Follow-up', 'Assessment appointment']}
                        value={choice}
                        onChange={setChoice}
                    />
                    <Field
                        label="Referral"
                        type="select"
                        options={scoped
                            .filter((r) => r.stage !== 'Closed')
                            .map((r) => ({
                                value: r.id,
                                label: r.name + ' · ' + r.id,
                            }))}
                        value={referral}
                        onChange={setReferral}
                    />
                    <Notice>
                        Choose the person first, then review date, owner and
                        outcome. Completed work stays visible through the
                        calendar’s status filters.
                    </Notice>
                </Modal>
            )}
        </>
    );
}
