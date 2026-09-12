import AppLayout from '@/layouts/app-layout';
import { createMyCalendarAdapter } from '@/lib/my-calendar-adapter';
import {
    personalCalendarRequest,
    type PersonalCalendarEntry,
} from '@/lib/personal-calendar';
import SiteCalendar, {
    type CreateSeed,
} from '@/pages/sites/calendar/SiteCalendar';
import type { Decorated } from '@/pages/sites/calendar/_parts';
import PersonalEntryDialog, {
    PERSONAL_KINDS,
} from '@/pages/sites/calendar/personal-entry-dialog';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type CSSProperties,
} from 'react';
import { toast } from 'sonner';

export default function MyCalendar({
    workCalendarStatus,
    canManagePersonalCalendar = false,
}: {
    workCalendarStatus?: string;
    canManagePersonalCalendar?: boolean;
}) {
    const { auth } = usePage<SharedData>().props;
    const [revision, setRevision] = useState(0);
    const [editor, setEditor] = useState<{
        key: string;
        entry?: PersonalCalendarEntry;
        seed?: CreateSeed;
    } | null>(null);
    const pendingMoves = useRef(new Set<number>());
    const refresh = useCallback(() => setRevision((r) => r + 1), []);
    const openEntry = useCallback((id: number) => {
        void personalCalendarRequest(`/${id}`)
            .then((entry) => setEditor({ key: crypto.randomUUID(), entry }))
            .catch((err: Error) => toast.error(err.message));
    }, []);
    useEffect(() => {
        const id = new URLSearchParams(window.location.search).get('entry');
        if (id && /^\d+$/.test(id) && canManagePersonalCalendar)
            openEntry(Number(id));
    }, [canManagePersonalCalendar, openEntry]);
    const move = useCallback(
        (item: Decorated, date: Date, end?: Date) => {
            if (
                !item.recordId ||
                !item.version ||
                pendingMoves.current.has(item.recordId)
            )
                return;
            const start = new Date(date);
            if (!end) {
                start.setHours(
                    item._start.getHours(),
                    item._start.getMinutes(),
                    0,
                    0,
                );
                end = item._end
                    ? new Date(
                          start.getTime() +
                              item._end.getTime() -
                              item._start.getTime(),
                      )
                    : undefined;
                if (item.allDay && item._end && end) {
                    const days = Math.round(
                        (Date.UTC(
                            item._end.getFullYear(),
                            item._end.getMonth(),
                            item._end.getDate(),
                        ) -
                            Date.UTC(
                                item._start.getFullYear(),
                                item._start.getMonth(),
                                item._start.getDate(),
                            )) /
                            86400000,
                    );
                    end = new Date(start);
                    end.setDate(end.getDate() + days);
                }
            }
            const id = item.recordId;
            pendingMoves.current.add(id);
            void personalCalendarRequest(`/${id}`, 'PUT', {
                version: item.version,
                start_at: start.toISOString(),
                end_at: end?.toISOString() ?? null,
            })
                .then((updated) => {
                    refresh();
                    toast.success('Calendar entry rescheduled', {
                        duration: 10000,
                        action: {
                            label: 'Undo',
                            onClick: () => {
                                void personalCalendarRequest(`/${id}`, 'PUT', {
                                    version: updated.version,
                                    start_at: item.start,
                                    end_at: item.end,
                                })
                                    .then(() => {
                                        refresh();
                                        toast.success('Previous time restored');
                                    })
                                    .catch((err: Error) =>
                                        toast.error(err.message),
                                    );
                            },
                        },
                    });
                })
                .catch((err: Error) => {
                    toast.error(err.message);
                    refresh();
                })
                .finally(() => pendingMoves.current.delete(id));
        },
        [refresh],
    );
    const adapter = useMemo(
        () => ({
            ...createMyCalendarAdapter(auth.user, workCalendarStatus),
            revision,
            onCreate: (seed: CreateSeed) =>
                setEditor({ key: crypto.randomUUID(), seed }),
            onOpenItem: (item: Decorated) => {
                if (!item.recordId) return false;
                openEntry(item.recordId);
                return true;
            },
            onMove: move,
        }),
        [auth.user, workCalendarStatus, revision, openEntry, move],
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'My Calendar', href: '/my-calendar' },
            ]}
        >
            <Head title="My Calendar" />
            <div
                style={
                    Object.fromEntries(
                        Object.entries({
                            shift: 'event',
                            medication_round: 'medication',
                            leave: 'respite',
                            alert_task: 'checklist',
                            personal_task: 'checklist',
                            personal_meeting: 'event',
                            personal_appointment: 'compliance',
                            personal_reminder: 'credential',
                        }).flatMap(([source, palette]) =>
                            ['', '-bg', '-ln'].map((suffix) => [
                                `--src-${source}${suffix}`,
                                `var(--src-${palette}${suffix})`,
                            ]),
                        ),
                    ) as CSSProperties
                }
            >
                <SiteCalendar
                    context="page"
                    scope="global"
                    canCreate={canManagePersonalCalendar}
                    canManage={canManagePersonalCalendar}
                    eventTypes={PERSONAL_KINDS}
                    dataAdapter={adapter}
                />
                {editor && (
                    <PersonalEntryDialog
                        key={editor.key}
                        entry={editor.entry}
                        seed={editor.seed}
                        onClose={() => setEditor(null)}
                        onSaved={refresh}
                    />
                )}
            </div>
        </AppLayout>
    );
}
