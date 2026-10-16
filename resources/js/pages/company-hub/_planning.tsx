import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDate } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUpRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FileText,
    Megaphone,
    PencilLine,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubRequestError, hubRequest } from './_api';
import { HubDateField } from './_date-fields';
import { useHubListControls } from './_reading-navigation';
import { HubSelect } from './_select';
import type { HubContent, HubEvent } from './_types';

export type EditorialPlan = {
    id?: number;
    title: string;
    target_date: string;
    owner_id: number;
    owner?: string;
    template: string;
    brief: string;
    audience: HubContent['audience'];
    version: number;
    hub_page_id?: number | null;
    page_title?: string | null;
};
type CalendarItem = {
    key: string;
    type: string;
    date: string;
    title: string;
    owner: string;
    status: string;
    plan_id?: number;
    url?: string;
    event?: HubEvent;
};
export type PlanningData = {
    month: string;
    items: CalendarItem[];
    plans: EditorialPlan[];
    owners: { id: number; name: string }[];
    viewer_id: number;
    can_create: boolean;
    can_assign: boolean;
    sites: { id: number; name: string }[];
    departments: { id: number; name: string }[];
};
const templates = {
    story: 'Company story',
    leadership: 'Leadership update',
    guide: 'Guide & resource',
    'resource-collection': 'Resource collection',
    team: 'Team space',
    welcome: 'Welcome page',
    information: 'Information page',
    campaign: 'Campaign',
    blank: 'Blank page',
};
const kinds = {
    all: 'All content',
    plan: 'Plans',
    page: 'Pages & stories',
    event: 'Events',
    notice: 'Announcements',
};
const localToday = () =>
    new Date().toLocaleDateString('en-CA', { timeZone: 'Pacific/Auckland' });
const monthLabel = (month: string) =>
    new Date(`${month}-01T12:00:00Z`).toLocaleDateString('en-NZ', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
function ItemIcon({ type }: { type: string }) {
    const Icon =
        type === 'plan'
            ? PencilLine
            : type === 'event'
              ? CalendarDays
              : type === 'notice'
                ? Megaphone
                : FileText;
    return <Icon className="size-4 shrink-0" />;
}

export function EditorialCalendar({
    onEvent,
    onWorkspaceChange,
}: {
    onEvent: (event: HubEvent) => void;
    onWorkspaceChange?: (open: boolean) => void;
}) {
    const today = localToday();
    const [calendar, setCalendar] = useHubListControls('publishing:calendar', {
        month: today.slice(0, 7),
        day: today,
        kind: 'all',
    });
    const month = /^\d{4}-(0[1-9]|1[0-2])$/.test(calendar.month)
        ? calendar.month
        : today.slice(0, 7);
    const day =
        calendar.day.startsWith(`${month}-`) &&
        /^\d{4}-\d{2}-(0[1-9]|[12]\d|3[01])$/.test(calendar.day)
            ? calendar.day
            : `${month}-01`;
    const kind = Object.hasOwn(kinds, calendar.kind) ? calendar.kind : 'all';
    const [data, setData] = useState<PlanningData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    const [plan, setPlan] = useState<EditorialPlan | null>(null);
    const planOpen = plan !== null;
    const [message, setMessage] = useState('');
    const origin = useRef<{ focus: HTMLElement | null; scroll: number } | null>(
        null,
    );
    const returnFrame = useRef<number | null>(null);
    const calendarHeading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        onWorkspaceChange?.(planOpen);
        return () => onWorkspaceChange?.(false);
    }, [planOpen, onWorkspaceChange]);
    useEffect(
        () => () => {
            if (returnFrame.current !== null)
                cancelAnimationFrame(returnFrame.current);
        },
        [],
    );
    const openPlan = (next: EditorialPlan | null) => {
        if (!next) return;
        if (returnFrame.current !== null)
            cancelAnimationFrame(returnFrame.current);
        origin.current = {
            focus:
                document.activeElement instanceof HTMLElement
                    ? document.activeElement
                    : null,
            scroll: window.scrollY,
        };
        setPlan(next);
    };
    const closePlan = (refreshDate?: string) => {
        const previous = origin.current;
        origin.current = null;
        setPlan(null);
        if (refreshDate) {
            setCalendar((current) => ({
                ...current,
                month: refreshDate.slice(0, 7),
                day: refreshDate,
            }));
            setReload((value) => value + 1);
        }
        returnFrame.current = requestAnimationFrame(() => {
            returnFrame.current = null;
            if (
                previous?.focus?.isConnected &&
                !previous.focus.matches(':disabled')
            )
                previous.focus.focus({ preventScroll: true });
            else calendarHeading.current?.focus({ preventScroll: true });
            if (previous)
                window.scrollTo({ top: previous.scroll, behavior: 'instant' });
        });
    };
    useEffect(() => {
        let active = true;
        setLoading(true);
        setError('');
        hubRequest<PlanningData>(
            `/company-hub/editor/planning?month=${month}`,
            'GET',
        )
            .then((result) => {
                if (active) setData(result);
            })
            .catch((err: Error) => {
                if (active) {
                    setError(err.message);
                    setData(null);
                }
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [month, reload]);
    const move = (offset: number) => {
        const date = new Date(`${month}-01T12:00:00Z`);
        date.setUTCMonth(date.getUTCMonth() + offset);
        const next = date.toISOString().slice(0, 7);
        setCalendar((current) => ({
            ...current,
            month: next,
            day: `${next}-01`,
        }));
    };
    const items = loading
        ? []
        : (data?.items ?? []).filter(
              (item) => kind === 'all' || item.type === kind,
          );
    const offset = (new Date(`${month}-01T12:00:00Z`).getUTCDay() + 6) % 7;
    const [year, monthNumber] = month.split('-').map(Number);
    const days = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate();
    const selected = items.filter((item) => item.date === day);
    return (
        <div className="space-y-5">
            <div hidden={!!plan} className="space-y-5">
                <div className="hub-planning-intro">
                    <div>
                        <p className="text-xs font-semibold tracking-widest text-primary uppercase">
                            Editorial calendar
                        </p>
                        <h2
                            ref={calendarHeading}
                            tabIndex={-1}
                            className="outline-none"
                        >
                            The right story, at the right time.
                        </h2>
                        <p>
                            Give an idea an owner. Turn a plan into a page when
                            you are ready.
                        </p>
                    </div>
                    <Button
                        disabled={loading || !data?.can_create}
                        onClick={() =>
                            openPlan({
                                title: '',
                                target_date: day,
                                owner_id: data?.viewer_id ?? 0,
                                template: 'story',
                                brief: '',
                                audience: { type: 'all', ids: [] },
                                version: 1,
                            })
                        }
                    >
                        <Plus className="size-4" />
                        Plan content
                    </Button>
                </div>
                <div className="hub-calendar-toolbar">
                    <div className="flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Previous month"
                            onClick={() => move(-1)}
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                        <h3 className="min-w-40 text-center font-semibold">
                            {monthLabel(month)}
                        </h3>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Next month"
                            onClick={() => move(1)}
                        >
                            <ChevronRight className="size-4" />
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setCalendar((current) => ({
                                    ...current,
                                    month: today.slice(0, 7),
                                    day: today,
                                }));
                            }}
                        >
                            Today
                        </Button>
                    </div>
                    <div className="w-52 shrink-0">
                        <HubSelect
                            label="Editorial content type"
                            value={kind}
                            onChange={(kind) =>
                                setCalendar((current) => ({ ...current, kind }))
                            }
                            options={Object.entries(kinds).map(
                                ([value, label]) => ({ value, label }),
                            )}
                        />
                    </div>
                </div>
                {error && (
                    <div role="alert" className="hub-empty-note">
                        {error}{' '}
                        <Button
                            variant="outline"
                            onClick={() => setReload((n) => n + 1)}
                        >
                            Retry
                        </Button>
                    </div>
                )}
                {message && (
                    <p role="status" className="text-sm text-primary">
                        {message}
                    </p>
                )}
                <div className="grid grid-cols-[minmax(0,1fr)_320px] items-start gap-6">
                    <div
                        className="hub-month-grid"
                        aria-label="Editorial calendar"
                        aria-busy={loading}
                    >
                        {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(
                            (label) => (
                                <span className="hub-weekday" key={label}>
                                    {label}
                                </span>
                            ),
                        )}
                        {Array.from(
                            { length: Math.ceil((offset + days) / 7) * 7 },
                            (_, i) => {
                                const n = i - offset + 1;
                                if (n < 1 || n > days)
                                    return (
                                        <span
                                            className="hub-calendar-blank"
                                            key={i}
                                        />
                                    );
                                const date = `${month}-${String(n).padStart(2, '0')}`;
                                const rows = items.filter(
                                    (item) => item.date === date,
                                );
                                return (
                                    // Calendar cells are full-size selectors with a custom grid layout.
                                    // eslint-disable-next-line no-restricted-syntax
                                    <button
                                        type="button"
                                        className="hub-calendar-day"
                                        key={date}
                                        aria-label={`${formatDate(date)}, ${rows.length} ${rows.length === 1 ? 'item' : 'items'}`}
                                        aria-pressed={day === date}
                                        data-today={date === localToday()}
                                        onClick={() =>
                                            setCalendar((current) => ({
                                                ...current,
                                                day: date,
                                            }))
                                        }
                                    >
                                        <b>{n}</b>
                                        {rows.slice(0, 2).map((item) => (
                                            <span
                                                key={item.key}
                                                data-kind={item.type}
                                                title={item.title}
                                            >
                                                <ItemIcon type={item.type} />
                                                <span>{item.title}</span>
                                            </span>
                                        ))}
                                        {rows.length > 2 && (
                                            <small>
                                                +{rows.length - 2} more
                                            </small>
                                        )}
                                    </button>
                                );
                            },
                        )}
                    </div>
                    <section className="hub-calendar-detail">
                        <div className="flex items-center justify-between">
                            <h3 className="font-semibold">{formatDate(day)}</h3>
                            <span className="text-xs text-muted-foreground">
                                {loading
                                    ? 'Loading calendar…'
                                    : `${selected.length} ${selected.length === 1 ? 'item' : 'items'}`}
                            </span>
                        </div>
                        {selected.map((item) => (
                            <article key={item.key}>
                                <div
                                    className="hub-calendar-glyph"
                                    data-kind={item.type}
                                >
                                    <ItemIcon type={item.type} />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <h4 className="font-medium">
                                        {item.title}
                                    </h4>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {item.owner} ·{' '}
                                        {item.event?.extendedProps.cancellation
                                            ?.cancelled
                                            ? 'Cancelled'
                                            : item.status}
                                    </p>
                                </div>
                                {item.url ? (
                                    <Button asChild variant="ghost">
                                        <Link href={item.url}>
                                            Open{' '}
                                            <ArrowUpRight className="size-4" />
                                        </Link>
                                    </Button>
                                ) : (
                                    <Button
                                        variant="ghost"
                                        data-hub-event-key={item.event?.id}
                                        onClick={() => {
                                            if (item.event) onEvent(item.event);
                                            else
                                                openPlan(
                                                    data?.plans.find(
                                                        (p) =>
                                                            p.id ===
                                                            item.plan_id,
                                                    ) ?? null,
                                                );
                                        }}
                                    >
                                        {item.type === 'plan'
                                            ? 'View plan'
                                            : 'Open event'}
                                        <ArrowUpRight className="size-4" />
                                    </Button>
                                )}
                            </article>
                        ))}
                        {!loading && !selected.length && (
                            <p className="py-5 text-sm text-muted-foreground">
                                A clear day. Plan a useful update or choose
                                another date.
                            </p>
                        )}
                    </section>
                </div>
                <p className="text-xs text-muted-foreground">
                    Plans are private to their authors and authorised editors.
                    The calendar includes pages you can edit, visible company
                    events and announcements available with your permissions.
                    Planned dates do not publish content automatically.
                </p>
            </div>
            {plan && data && (
                <PlanWorkspace
                    key={`${data.viewer_id}:${plan.id ?? 'new'}`}
                    initial={plan}
                    data={data}
                    close={closePlan}
                    saved={(saved) => {
                        setMessage(
                            'Plan saved. It stays private until its linked page is published.',
                        );
                        closePlan(saved.target_date);
                    }}
                />
            )}
        </div>
    );
}

function PlanWorkspace({
    initial,
    data,
    close,
    saved,
}: {
    initial: EditorialPlan;
    data: PlanningData;
    close: (refreshDate?: string) => void;
    saved: (plan: EditorialPlan) => void;
}) {
    const [plan, setPlan] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const [baseline, setBaseline] = useState(initial);
    const [uncertain, setUncertain] = useState(false);
    const [retryStart, setRetryStart] = useState<EditorialPlan | null>(null);
    const pending = useRef(false);
    const attempted = useRef(false);
    const alive = useRef(false);
    const heading = useRef<HTMLHeadingElement>(null);
    const dirty = JSON.stringify(plan) !== JSON.stringify(baseline);
    const navigation = useSettingsLeaveConfirmation(
        dirty || busy || uncertain,
        'Leave this editorial plan?',
        'Your unsaved brief and choices will be discarded. A save already sent may still finish; check the calendar before creating the same plan again.',
    );
    useEffect(() => {
        alive.current = true;
        heading.current?.focus({ preventScroll: true });
        heading.current?.scrollIntoView({
            block: 'center',
            behavior: 'instant',
        });
        return () => {
            alive.current = false;
        };
    }, []);
    const linked = Boolean(plan.hub_page_id);
    const patch = (changes: Partial<EditorialPlan>) =>
        setPlan((current) => ({ ...current, ...changes }));
    const closeWorkspace = () =>
        close(attempted.current ? plan.target_date : undefined);
    const attemptClose = () => {
        if (!pending.current) {
            if (dirty || uncertain) setDiscard(true);
            else closeWorkspace();
        }
    };
    const openDraft = async (savedPlan: EditorialPlan) => {
        const draft = await hubRequest<{
            url: string;
            page_id: number;
            actor_id: number;
        }>(`/company-hub/editor/plans/${savedPlan.id}/start`, 'POST', {
            version: savedPlan.version,
            actor_id: data.viewer_id,
        });
        if (!alive.current) return;
        if (
            draft.actor_id !== data.viewer_id ||
            !Number.isSafeInteger(draft.page_id) ||
            draft.page_id < 1 ||
            draft.url !== `/company-hub/manage/${draft.page_id}/edit`
        )
            throw new Error(
                'The linked draft could not be confirmed. Try opening it again.',
            );
        navigation.discardAndRun(() => router.visit(draft.url));
    };
    const submit = async (start: boolean) => {
        if (pending.current || discard || uncertain) return;
        if (
            !retryStart &&
            (plan.title.trim().length < 4 ||
                !plan.brief?.trim() ||
                !plan.target_date ||
                (plan.audience.type !== 'all' && !plan.audience.ids.length))
        ) {
            setError(
                'Add a title of at least 4 characters, a short brief, a target date and an audience.',
            );
            return;
        }
        pending.current = true;
        attempted.current = true;
        setBusy(true);
        setError('');
        let savedPlan: EditorialPlan | null = retryStart;
        try {
            if (retryStart) {
                await openDraft(retryStart);
                return;
            }
            const result = await hubRequest<
                EditorialPlan & { actor_id: number }
            >(
                `/company-hub/editor/plans${plan.id ? `/${plan.id}` : ''}`,
                plan.id ? 'PUT' : 'POST',
                { ...plan, actor_id: data.viewer_id },
            );
            if (!alive.current) return;
            if (
                result.actor_id !== data.viewer_id ||
                !Number.isSafeInteger(result.id) ||
                (result.id ?? 0) < 1 ||
                (plan.id && result.id !== plan.id) ||
                result.version !== (plan.id ? plan.version + 1 : 1)
            ) {
                setUncertain(true);
                setError(
                    'We couldn’t confirm the saved plan. Return to the calendar and check before saving again.',
                );
                return;
            }
            savedPlan = result;
            setPlan(result);
            setBaseline(result);
            if (start) {
                await openDraft(result);
            } else saved(result);
        } catch (err) {
            if (!alive.current) return;
            if (savedPlan && start) setRetryStart(savedPlan);
            else if (
                !(err instanceof HubRequestError) ||
                ![403, 404, 409, 422, 429].includes(err.status)
            )
                setUncertain(true);
            setError((err as Error).message);
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const audienceChoices =
        plan.audience.type === 'sites' ? data.sites : data.departments;
    return (
        <>
            <section
                aria-label="Editorial plan"
                className="overflow-hidden rounded-2xl border bg-card"
            >
                <header className="space-y-3 border-b px-7 py-6">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="-ml-2"
                        disabled={busy}
                        onClick={attemptClose}
                    >
                        <ArrowLeft className="size-4" /> Back to calendar
                    </Button>
                    <p className="text-xs font-semibold tracking-widest text-primary uppercase">
                        Editorial planning
                    </p>
                    <h2
                        ref={heading}
                        tabIndex={-1}
                        className="text-2xl font-semibold tracking-tight outline-none"
                    >
                        Make space for the next good story
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Give the work an owner and a target date. Your brief
                        remains private to editors.
                    </p>
                </header>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        void submit(false);
                    }}
                    className="space-y-6 px-7 py-6"
                >
                    <fieldset
                        disabled={busy || discard || uncertain || !!retryStart}
                        className="grid grid-cols-[minmax(0,1fr)_340px] items-start gap-8"
                    >
                        <div className="space-y-6">
                            <div className="space-y-2">
                                <Label htmlFor="plan-title">
                                    Working title
                                </Label>
                                <Input
                                    id="plan-title"
                                    value={plan.title}
                                    minLength={4}
                                    maxLength={160}
                                    required
                                    onChange={(e) =>
                                        patch({ title: e.target.value })
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="plan-brief">Brief</Label>
                                <Textarea
                                    id="plan-brief"
                                    rows={10}
                                    maxLength={2000}
                                    required
                                    value={plan.brief ?? ''}
                                    onChange={(e) =>
                                        patch({ brief: e.target.value })
                                    }
                                    placeholder="What should this help our people understand or do?"
                                />
                                <div className="flex justify-between gap-4 text-xs text-muted-foreground">
                                    <span>
                                        Give the author a useful starting point.
                                    </span>
                                    <span>
                                        {(plan.brief ?? '').length} / 2,000
                                    </span>
                                </div>
                            </div>
                            <div className="flex items-start gap-3 rounded-xl bg-primary/5 p-4 text-sm text-muted-foreground">
                                <ShieldCheck className="mt-0.5 size-5 shrink-0 text-primary" />
                                <p>
                                    Saving a plan keeps it in the editorial
                                    calendar. Start a draft when you are ready;
                                    its publication is a separate step.
                                </p>
                            </div>
                        </div>
                        <aside className="space-y-5 rounded-xl border bg-muted/30 p-5">
                            <h3 className="font-semibold">
                                The plan at a glance
                            </h3>
                            <div className="grid gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="plan-date">
                                        Target date
                                    </Label>
                                    <HubDateField
                                        id="plan-date"
                                        label="Target date"
                                        allowClear={false}
                                        value={plan.target_date}
                                        onChange={(value) =>
                                            patch({
                                                target_date: value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="plan-owner">Owner</Label>
                                    <HubSelect
                                        id="plan-owner"
                                        label="Owner"
                                        disabled={linked || !data.can_assign}
                                        value={String(plan.owner_id)}
                                        onChange={(value) =>
                                            patch({
                                                owner_id: Number(value),
                                            })
                                        }
                                        options={[
                                            ...(!data.owners.some(
                                                (owner) =>
                                                    owner.id === plan.owner_id,
                                            )
                                                ? [
                                                      {
                                                          value: String(
                                                              plan.owner_id,
                                                          ),
                                                          label:
                                                              plan.owner ??
                                                              'Choose an eligible author',
                                                          disabled: true,
                                                      },
                                                  ]
                                                : []),
                                            ...data.owners.map((owner) => ({
                                                value: String(owner.id),
                                                label: owner.name,
                                            })),
                                        ]}
                                    />
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="plan-template">
                                    Starting point
                                </Label>
                                <HubSelect
                                    id="plan-template"
                                    label="Starting point"
                                    disabled={linked}
                                    value={plan.template}
                                    onChange={(template) => patch({ template })}
                                    options={Object.entries(templates).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="plan-audience">
                                    Intended audience
                                </Label>
                                <HubSelect
                                    id="plan-audience"
                                    label="Intended audience"
                                    disabled={linked}
                                    value={plan.audience.type}
                                    onChange={(value) =>
                                        patch({
                                            audience: {
                                                type: value as HubContent['audience']['type'],
                                                ids: [],
                                            },
                                        })
                                    }
                                    options={[
                                        { value: 'all', label: 'All staff' },
                                        {
                                            value: 'sites',
                                            label: 'Selected sites',
                                        },
                                        {
                                            value: 'departments',
                                            label: 'Selected departments',
                                        },
                                    ]}
                                />
                                {plan.audience.type !== 'all' && (
                                    <div className="flex max-h-32 flex-wrap gap-3 overflow-y-auto rounded-md border p-3">
                                        {audienceChoices.map((choice) => (
                                            <label
                                                className="flex items-center gap-2 text-sm"
                                                key={choice.id}
                                            >
                                                <input
                                                    type="checkbox"
                                                    disabled={linked}
                                                    checked={plan.audience.ids.includes(
                                                        choice.id,
                                                    )}
                                                    onChange={(e) =>
                                                        patch({
                                                            audience: {
                                                                ...plan.audience,
                                                                ids: e.target
                                                                    .checked
                                                                    ? [
                                                                          ...plan
                                                                              .audience
                                                                              .ids,
                                                                          choice.id,
                                                                      ]
                                                                    : plan.audience.ids.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              choice.id,
                                                                      ),
                                                            },
                                                        })
                                                    }
                                                />
                                                {choice.name}
                                            </label>
                                        ))}
                                        {!audienceChoices.length && (
                                            <p className="text-sm text-muted-foreground">
                                                No available{' '}
                                                {plan.audience.type}.
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </aside>
                    </fieldset>
                    {linked && (
                        <p className="rounded-lg bg-muted p-3 text-sm">
                            Linked page: <strong>{plan.page_title}</strong>. Use
                            the page editor to change its author, audience or
                            publication settings. Changes to this plan do not
                            overwrite the draft.
                        </p>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    {uncertain && (
                        <p className="text-sm text-muted-foreground">
                            Saving is paused to avoid creating a duplicate. Your
                            writing is still here to copy; return to the
                            calendar to check the saved result.
                        </p>
                    )}
                    {retryStart && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Your plan is saved. Retry opening its linked draft
                            without saving the plan again.
                        </p>
                    )}
                    {discard && (
                        <div className="rounded-lg border p-3">
                            <p className="text-sm">
                                Discard your unsaved plan changes?
                            </p>
                            <div className="mt-2 flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setDiscard(false)}
                                >
                                    Keep editing
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={closeWorkspace}
                                >
                                    Discard changes
                                </Button>
                            </div>
                        </div>
                    )}
                    <footer className="flex items-center justify-end gap-3 border-t pt-5">
                        <span className="mr-auto text-xs text-muted-foreground">
                            Private planning ·{' '}
                            {dirty
                                ? 'Unsaved changes'
                                : plan.id
                                  ? 'Saved plan'
                                  : 'New plan'}
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy}
                            onClick={attemptClose}
                        >
                            Cancel
                        </Button>
                        {!uncertain && (plan.id || data.can_create) && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    busy ||
                                    discard ||
                                    (!data.can_create && !linked)
                                }
                                onClick={() => void submit(true)}
                            >
                                {retryStart
                                    ? 'Retry opening draft'
                                    : linked
                                      ? 'Save & open page'
                                      : 'Save & start draft'}
                            </Button>
                        )}
                        <Button
                            type="submit"
                            disabled={
                                busy || discard || uncertain || !!retryStart
                            }
                        >
                            {busy ? 'Saving…' : 'Save plan'}
                        </Button>
                    </footer>
                </form>
            </section>
            {navigation.confirmation}
        </>
    );
}
