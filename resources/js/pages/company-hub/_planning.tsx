import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FileText,
    Megaphone,
    PencilLine,
    Plus,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { hubRequest } from './_api';
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
}: {
    onEvent: (event: HubEvent) => void;
}) {
    const [month, setMonth] = useState(() => localToday().slice(0, 7));
    const [day, setDay] = useState(localToday);
    const [kind, setKind] = useState('all');
    const [data, setData] = useState<PlanningData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    const [plan, setPlan] = useState<EditorialPlan | null>(null);
    const [message, setMessage] = useState('');
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
        setMonth(next);
        setDay(`${next}-01`);
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
            <div className="hub-planning-intro">
                <div>
                    <p className="text-xs font-semibold tracking-widest text-primary uppercase">
                        Editorial calendar
                    </p>
                    <h2>The right story, at the right time.</h2>
                    <p>
                        Give an idea an owner. Turn a plan into a page when you
                        are ready.
                    </p>
                </div>
                <Button
                    disabled={loading || !data?.can_create}
                    onClick={() =>
                        setPlan({
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
                            setMonth(localToday().slice(0, 7));
                            setDay(localToday());
                        }}
                    >
                        Today
                    </Button>
                </div>
                <select
                    className="hub-native-select"
                    aria-label="Editorial content type"
                    value={kind}
                    onChange={(e) => setKind(e.target.value)}
                >
                    {Object.entries(kinds).map(([id, label]) => (
                        <option key={id} value={id}>
                            {label}
                        </option>
                    ))}
                </select>
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
                                <span className="hub-calendar-blank" key={i} />
                            );
                        const date = `${month}-${String(n).padStart(2, '0')}`;
                        const rows = items.filter((item) => item.date === date);
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
                                onClick={() => setDay(date)}
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
                                    <small>+{rows.length - 2} more</small>
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
                            <h4 className="font-medium">{item.title}</h4>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {item.owner} · {item.status}
                            </p>
                        </div>
                        {item.url ? (
                            <Button asChild variant="ghost">
                                <Link href={item.url}>
                                    Open <ArrowUpRight className="size-4" />
                                </Link>
                            </Button>
                        ) : (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    if (item.event) onEvent(item.event);
                                    else
                                        setPlan(
                                            data?.plans.find(
                                                (p) => p.id === item.plan_id,
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
                        A clear day. Plan a useful update or choose another
                        date.
                    </p>
                )}
            </section>
            <p className="text-xs text-muted-foreground">
                Plans are private to their authors and authorised editors. The
                calendar includes pages you can edit, visible company events and
                announcements available with your permissions. Planned dates do
                not publish content automatically.
            </p>
            {plan && data && (
                <PlanDialog
                    key={plan.id ?? 'new'}
                    initial={plan}
                    data={data}
                    close={() => setPlan(null)}
                    saved={(saved) => {
                        setMonth(saved.target_date.slice(0, 7));
                        setDay(saved.target_date);
                        setReload((n) => n + 1);
                        setMessage(
                            'Plan saved. It stays private until its linked page is published.',
                        );
                        setPlan(null);
                    }}
                />
            )}
        </div>
    );
}

function PlanDialog({
    initial,
    data,
    close,
    saved,
}: {
    initial: EditorialPlan;
    data: PlanningData;
    close: () => void;
    saved: (plan: EditorialPlan) => void;
}) {
    const [plan, setPlan] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const [baseline, setBaseline] = useState(initial);
    const dirty = JSON.stringify(plan) !== JSON.stringify(baseline);
    const linked = Boolean(plan.hub_page_id);
    const patch = (changes: Partial<EditorialPlan>) =>
        setPlan((current) => ({ ...current, ...changes }));
    const attemptClose = () => {
        if (!busy) {
            if (dirty) setDiscard(true);
            else close();
        }
    };
    const submit = async (start: boolean) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<EditorialPlan>(
                `/company-hub/editor/plans${plan.id ? `/${plan.id}` : ''}`,
                plan.id ? 'PUT' : 'POST',
                plan,
            );
            setPlan(result);
            setBaseline(result);
            if (start) {
                const draft = await hubRequest<{ url: string }>(
                    `/company-hub/editor/plans/${result.id}/start`,
                    'POST',
                    { version: result.version },
                );
                router.visit(draft.url);
            } else saved(result);
        } catch (err) {
            setError((err as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const audienceChoices =
        plan.audience.type === 'sites' ? data.sites : data.departments;
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) attemptClose();
            }}
        >
            <DialogContent className="max-h-[85vh] overflow-x-hidden overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {plan.id ? 'Editorial plan' : 'Plan a company update'}
                    </DialogTitle>
                    <DialogDescription>
                        Give the work an owner and a target date. Your brief
                        remains private to editors.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        void submit(false);
                    }}
                    className="space-y-4"
                >
                    <fieldset disabled={busy} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="plan-title">Working title</Label>
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
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="plan-date">Target date</Label>
                                <Input
                                    id="plan-date"
                                    type="date"
                                    required
                                    value={plan.target_date}
                                    onChange={(e) =>
                                        patch({ target_date: e.target.value })
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="plan-owner">Owner</Label>
                                <select
                                    id="plan-owner"
                                    className="hub-native-select w-full"
                                    disabled={linked || !data.can_assign}
                                    value={plan.owner_id}
                                    onChange={(e) =>
                                        patch({
                                            owner_id: Number(e.target.value),
                                        })
                                    }
                                >
                                    {!data.owners.some(
                                        (o) => o.id === plan.owner_id,
                                    ) && (
                                        <option value={plan.owner_id}>
                                            {plan.owner ??
                                                'Choose an eligible author'}
                                        </option>
                                    )}
                                    {data.owners.map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="plan-template">
                                Starting point
                            </Label>
                            <select
                                id="plan-template"
                                className="hub-native-select w-full"
                                disabled={linked}
                                value={plan.template}
                                onChange={(e) =>
                                    patch({ template: e.target.value })
                                }
                            >
                                {Object.entries(templates).map(
                                    ([id, label]) => (
                                        <option key={id} value={id}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="plan-audience">
                                Intended audience
                            </Label>
                            <select
                                id="plan-audience"
                                className="hub-native-select w-full"
                                disabled={linked}
                                value={plan.audience.type}
                                onChange={(e) =>
                                    patch({
                                        audience: {
                                            type: e.target
                                                .value as HubContent['audience']['type'],
                                            ids: [],
                                        },
                                    })
                                }
                            >
                                <option value="all">All staff</option>
                                <option value="sites">Selected sites</option>
                                <option value="departments">
                                    Selected departments
                                </option>
                            </select>
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
                                                                      (id) =>
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
                                            No available {plan.audience.type}.
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="plan-brief">Brief</Label>
                            <Textarea
                                id="plan-brief"
                                rows={4}
                                maxLength={2000}
                                value={plan.brief ?? ''}
                                onChange={(e) =>
                                    patch({ brief: e.target.value })
                                }
                                placeholder="What should this help our people understand or do?"
                            />
                        </div>
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
                                    onClick={close}
                                >
                                    Discard changes
                                </Button>
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy}
                            onClick={attemptClose}
                        >
                            Cancel
                        </Button>
                        {plan.id && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={busy || (!data.can_create && !linked)}
                                onClick={() => void submit(true)}
                            >
                                {linked
                                    ? 'Save & open page'
                                    : 'Save & start draft'}
                            </Button>
                        )}
                        <Button type="submit" disabled={busy}>
                            {busy ? 'Saving…' : 'Save plan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
