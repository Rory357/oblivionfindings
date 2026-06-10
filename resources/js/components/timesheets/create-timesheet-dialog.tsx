/* eslint-disable no-restricted-syntax -- The create-timesheet wizard mirrors the
 * bespoke Add-client modal surface (step header + scroll-contained body + custom
 * footer) and intentionally uses styled native controls for its shift/activity
 * tile pickers. Every colour is a semantic design token, per
 * docs/DESIGN_TOKENS.md. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { StepHead } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BookOpen,
    Calendar as CalendarIcon,
    CalendarDays,
    Car,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleEllipsis,
    ClipboardList,
    Clock,
    Coffee,
    FileText,
    FilePlus2,
    GraduationCap,
    Loader2,
    MapPin,
    Moon,
    Phone,
    Plus,
    Save,
    Search,
    Send,
    User,
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

// ─────────────────────────────────────────────────────────────────────
// Types — mirror the controller props (TimesheetController::index returns
// `availableShifts`, `clients`, `sites`).
// ─────────────────────────────────────────────────────────────────────
export type ShiftOption = {
    id: number;
    client: { id: number; first_name: string; last_name: string } | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    shift_type: string | null;
    status: string;
    service_context: string | null;
    expected_break_minutes: number;
    is_sleepover: boolean;
    is_on_call: boolean;
    client_id: number | null;
    tasks: Array<{
        id: number;
        label: string;
        completed: boolean;
        time?: string | null;
        minutes: number;
    }>;
};
export type ClientOption = { id: number; first_name: string; last_name: string };
export type SiteOption = { id: number; name: string };

export type CreateTimesheetDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shifts: ShiftOption[];
    clients: ClientOption[];
    sites: SiteOption[];
    // When supplied (e.g. opened from a shift detail page), the dialog skips
    // straight to the shift-mode picker pre-selected with this shift.
    initialShiftId?: number | null;
};

type ActivityKey =
    | 'training'
    | 'meeting'
    | 'admin'
    | 'travel'
    | 'handover'
    | 'supervision'
    | 'standby'
    | 'other';

const ACTIVITY_TYPES: Array<{
    key: ActivityKey;
    label: string;
    desc: string;
    Icon: typeof GraduationCap;
}> = [
    { key: 'training', label: 'Training', desc: 'Mandatory training or CPD', Icon: GraduationCap },
    { key: 'meeting', label: 'Team meeting', desc: 'Internal or external meeting', Icon: Users },
    { key: 'admin', label: 'Admin / paperwork', desc: 'Notes, reports, planning', Icon: FileText },
    { key: 'travel', label: 'Travel time', desc: 'Between shifts or sites', Icon: Car },
    { key: 'handover', label: 'Handover', desc: 'Inter-shift handover', Icon: ArrowLeftRight },
    { key: 'supervision', label: 'Supervision', desc: '1:1 or group supervision', Icon: UserCheck },
    { key: 'standby', label: 'Standby / on-call', desc: 'Available but not active', Icon: Phone },
    { key: 'other', label: 'Other', desc: 'Anything else billable', Icon: CircleEllipsis },
];

function fmtTime(iso: string) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
}
function fmtDate(iso: string) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

// ─────────────────────────────────────────────────────────────────────
// SearchableSelect — used in manual-entry mode for Linked client / site.
// Open on click, type to filter, ↑/↓ + Enter to pick.
// ─────────────────────────────────────────────────────────────────────
type SearchOption = { id: number | string; label: string; sub?: string };

function SearchableSelect({
    value,
    onChange,
    options,
    placeholder = 'Choose…',
    emptyLabel = '— None —',
    icon: Icon,
}: {
    value: number | string | '';
    onChange: (v: number | string | '') => void;
    options: SearchOption[];
    placeholder?: string;
    emptyLabel?: string;
    icon?: typeof User;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [hi, setHi] = useState(0);
    const wrapRef = useRef<HTMLDivElement | null>(null);
    const inputRef = useRef<HTMLInputElement | null>(null);

    const selected = options.find((o) => String(o.id) === String(value));

    useEffect(() => {
        if (!open) return;
        const onAway = (e: MouseEvent) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) {
                setOpen(false);
                setQuery('');
            }
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                setQuery('');
            }
        };
        document.addEventListener('mousedown', onAway);
        document.addEventListener('keydown', onKey);
        const timer = window.setTimeout(() => inputRef.current?.focus(), 0);
        return () => {
            document.removeEventListener('mousedown', onAway);
            document.removeEventListener('keydown', onKey);
            window.clearTimeout(timer);
        };
    }, [open]);

    const filtered = useMemo(() => {
        if (!query.trim()) return options;
        const q = query.toLowerCase();
        return options.filter((o) => o.label.toLowerCase().includes(q));
    }, [options, query]);

    useEffect(() => setHi(0), [query]);

    const pick = (o: SearchOption | null) => {
        onChange(o ? o.id : '');
        setOpen(false);
        setQuery('');
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setHi((i) => Math.min(filtered.length, i + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setHi((i) => Math.max(0, i - 1));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (hi === 0) pick(null);
            else if (filtered[hi - 1]) pick(filtered[hi - 1]);
        }
    };

    return (
        <div ref={wrapRef} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-haspopup="listbox"
                aria-expanded={open}
                className="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 text-sm shadow-sm hover:bg-accent/30"
            >
                <span className={cn('flex min-w-0 items-center gap-1.5', selected ? 'text-foreground' : 'text-muted-foreground')}>
                    {Icon ? <Icon className="h-3.5 w-3.5 text-muted-foreground" /> : null}
                    <span className="truncate">{selected ? selected.label : placeholder}</span>
                </span>
                <div className="flex items-center gap-1 text-muted-foreground">
                    {selected ? (
                        <span
                            role="button"
                            tabIndex={0}
                            aria-label="Clear selection"
                            onClick={(e) => {
                                e.stopPropagation();
                                pick(null);
                            }}
                            className="grid h-4 w-4 cursor-pointer place-items-center rounded hover:bg-muted hover:text-foreground"
                        >
                            <X className="h-3 w-3" />
                        </span>
                    ) : null}
                    <ChevronDown className={cn('h-3.5 w-3.5 transition-transform', open && 'rotate-180')} />
                </div>
            </button>

            {open ? (
                <div className="absolute left-0 right-0 z-50 mt-1 overflow-hidden rounded-lg border border-border bg-popover shadow-xl ring-1 ring-black/5">
                    <div className="border-b border-border/60 p-1.5">
                        <input
                            ref={inputRef}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder="Search…"
                            className="w-full rounded-md border border-input bg-background px-2.5 py-1.5 text-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/30"
                        />
                    </div>
                    <ul role="listbox" className="max-h-56 overflow-y-auto py-1 text-xs">
                        <li
                            role="option"
                            aria-selected={!value}
                            onMouseEnter={() => setHi(0)}
                            onClick={() => pick(null)}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 px-3 py-1.5 italic text-muted-foreground',
                                hi === 0 && 'bg-accent/40',
                            )}
                        >
                            {emptyLabel}
                        </li>
                        {filtered.length === 0 ? (
                            <li className="px-3 py-2 text-[11.5px] text-muted-foreground/70">No matches for "{query}"</li>
                        ) : (
                            filtered.map((o, idx) => {
                                const active = hi === idx + 1;
                                const selectedHere = String(o.id) === String(value);
                                return (
                                    <li
                                        key={o.id}
                                        role="option"
                                        aria-selected={selectedHere}
                                        onMouseEnter={() => setHi(idx + 1)}
                                        onClick={() => pick(o)}
                                        className={cn(
                                            'flex cursor-pointer items-center justify-between gap-2 px-3 py-1.5',
                                            active ? 'bg-status-info-bg text-foreground' : 'text-foreground/80',
                                        )}
                                    >
                                        <span className="min-w-0 truncate">
                                            {o.label}
                                            {o.sub ? <span className="ml-1.5 text-[11px] text-muted-foreground">{o.sub}</span> : null}
                                        </span>
                                        {selectedHere ? <Check className="h-3.5 w-3.5 text-primary" /> : null}
                                    </li>
                                );
                            })
                        )}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────
// ShiftTile — Step-1 shift mode picker tile.
// ─────────────────────────────────────────────────────────────────────
function ShiftTile({
    shift,
    active,
    onSelect,
}: {
    shift: ShiftOption;
    active: boolean;
    onSelect: (s: ShiftOption) => void;
}) {
    return (
        <button
            type="button"
            onClick={() => onSelect(shift)}
            className={cn(
                'rounded-xl border bg-card p-3 text-left transition hover:border-primary/40 hover:bg-status-info-bg/40',
                active && 'border-primary bg-status-info-bg shadow-sm ring-1 ring-primary/30',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold">
                            {shift.client ? `${shift.client.first_name} ${shift.client.last_name}` : 'Unassigned'}
                        </span>
                        {shift.shift_type === 'sleepover' ? (
                            <span className="inline-flex items-center gap-1 rounded-md border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-indigo-700">
                                <Moon className="h-3 w-3" /> Sleepover
                            </span>
                        ) : null}
                    </div>
                    <div className="mt-0.5 text-[11.5px] text-muted-foreground">
                        {shift.service_context ?? 'Care'} · #{shift.id}
                    </div>
                </div>
                <div className="rounded-full bg-muted/70 px-2 py-0.5 text-[10.5px] font-medium text-muted-foreground">
                    {shift.status.replace('_', ' ')}
                </div>
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                <span className="inline-flex items-center gap-1">
                    <Clock className="h-3 w-3" />
                    {fmtTime(shift.starts_at)} – {fmtTime(shift.ends_at)}
                </span>
                {shift.location ? (
                    <span className="inline-flex items-center gap-1">
                        <MapPin className="h-3 w-3" />
                        {shift.location}
                    </span>
                ) : null}
                <span className="tabular-nums">{shift.tasks.length} tasks scheduled</span>
            </div>
        </button>
    );
}

function Toggle({ label, value, onChange }: { label: string; value: boolean; onChange: (v: boolean) => void }) {
    return (
        <button
            type="button"
            onClick={() => onChange(!value)}
            aria-pressed={value}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11.5px] font-medium transition',
                value
                    ? 'border-primary bg-status-info-bg text-primary'
                    : 'border-input bg-background text-muted-foreground hover:border-border',
            )}
        >
            <span className={cn('h-1.5 w-1.5 rounded-full', value ? 'bg-primary' : 'bg-border')} />
            {label}
        </button>
    );
}

// ─────────────────────────────────────────────────────────────────────
// CreateTimesheetDialog — the single canonical create flow.
// Two steps:
//   1. Pick a shift OR choose a manual activity type.
//   2. Confirm hours + tasks (shift mode) / activity items (manual mode).
// ─────────────────────────────────────────────────────────────────────
export default function CreateTimesheetDialog({
    open,
    onOpenChange,
    shifts,
    clients,
    sites,
    initialShiftId,
}: CreateTimesheetDialogProps) {
    const [step, setStep] = useState<1 | 2>(1);
    const [mode, setMode] = useState<'shift' | 'manual'>('shift');
    const [search, setSearch] = useState('');
    const [shift, setShift] = useState<ShiftOption | null>(null);
    const [activityType, setActivityType] = useState<ActivityKey | null>(null);
    const [workDate, setWorkDate] = useState(() => new Date().toISOString().slice(0, 10));
    const [clientId, setClientId] = useState<number | string | ''>('');
    const [siteId, setSiteId] = useState<number | string | ''>('');
    const [tasks, setTasks] = useState<
        Array<{ id: number; label: string; completed: boolean; included: boolean; time?: string | null; minutes: number }>
    >([]);
    const [activityItems, setActivityItems] = useState<string[]>([]);
    const [newActivityItem, setNewActivityItem] = useState('');
    const [form, setForm] = useState({
        start: '',
        end: '',
        breakMin: 30,
        mileageKm: 0,
        sleepover: false,
        onCall: false,
        publicHoliday: false,
        notes: '',
    });
    const [submitting, setSubmitting] = useState(false);
    const [done, setDone] = useState<number | false>(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    // Reset on every open. When opened from a shift detail page with
    // initialShiftId set, jump straight to that shift in step 1 (still gives
    // the user a chance to confirm before continuing to step 2).
    useEffect(() => {
        if (!open) return;
        setStep(1);
        setMode('shift');
        setShift(null);
        setActivityType(null);
        setTasks([]);
        setActivityItems([]);
        setNewActivityItem('');
        setWorkDate(new Date().toISOString().slice(0, 10));
        setClientId('');
        setSiteId('');
        setSubmitting(false);
        setDone(false);
        setErrors({});
        setSearch('');
        setForm({ start: '', end: '', breakMin: 0, mileageKm: 0, sleepover: false, onCall: false, publicHoliday: false, notes: '' });

        if (initialShiftId) {
            const preselected = shifts.find((s) => s.id === initialShiftId);
            if (preselected) {
                handleShiftSelect(preselected);
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialShiftId]);

    const filteredShifts = useMemo(() => {
        if (!search) return shifts;
        const q = search.toLowerCase();
        return shifts.filter((s) => {
            const name = s.client ? `${s.client.first_name} ${s.client.last_name}` : '';
            return (
                name.toLowerCase().includes(q) ||
                (s.location ?? '').toLowerCase().includes(q) ||
                String(s.id).includes(q)
            );
        });
    }, [shifts, search]);

    function handleShiftSelect(s: ShiftOption) {
        setShift(s);
        setTasks(
            s.tasks.map((t) => ({
                id: t.id,
                label: t.label,
                completed: t.completed,
                included: true,
                time: t.time,
                minutes: t.minutes,
            })),
        );
        setForm({
            start: s.starts_at?.slice(11, 16) ?? '',
            end: s.ends_at?.slice(11, 16) ?? '',
            breakMin: s.expected_break_minutes ?? 0,
            mileageKm: 0,
            sleepover: !!s.is_sleepover,
            onCall: !!s.is_on_call,
            publicHoliday: false,
            notes: '',
        });
    }

    const liveHours = useMemo(() => {
        if (!form.start || !form.end) return null;
        const [sh, sm] = form.start.split(':').map(Number);
        const [eh, em] = form.end.split(':').map(Number);
        let mins = eh * 60 + em - (sh * 60 + sm);
        if (mins <= 0) mins += 24 * 60;
        mins -= Number(form.breakMin) || 0;
        return mins > 0 ? (mins / 60).toFixed(2) : '0.00';
    }, [form.start, form.end, form.breakMin]);

    const taskTotal = tasks.filter((t) => t.included).length;
    const taskCompleted = tasks.filter((t) => t.included && t.completed).length;

    const canAdvanceFromStep1 = mode === 'shift' ? !!shift : !!activityType;
    const activityMeta = activityType ? ACTIVITY_TYPES.find((a) => a.key === activityType) : null;
    const ActivityIcon = activityMeta?.Icon ?? CircleEllipsis;

    function submit(asDraft: boolean) {
        if (submitting) return;
        setSubmitting(true);
        setErrors({});

        // Build the work_date + starts_at + ends_at ISO strings the controller
        // expects. Manual mode uses workDate; shift mode pulls the date from
        // the linked shift.
        const baseDate = mode === 'shift' ? shift!.starts_at.slice(0, 10) : workDate;
        const startsAt = `${baseDate}T${form.start || '09:00'}:00`;
        const endsAtBase = `${baseDate}T${form.end || '17:00'}:00`;

        // Handle overnight: if end <= start treat as next day.
        let endsAt = endsAtBase;
        if (form.start && form.end) {
            const [sh, sm] = form.start.split(':').map(Number);
            const [eh, em] = form.end.split(':').map(Number);
            if (eh * 60 + em <= sh * 60 + sm) {
                const next = new Date(baseDate);
                next.setDate(next.getDate() + 1);
                endsAt = `${next.toISOString().slice(0, 10)}T${form.end}:00`;
            }
        }

        const payload: Record<string, any> = {
            mode,
            shift_id: mode === 'shift' ? shift!.id : null,
            activity_type: mode === 'manual' ? activityType : null,
            activity_items: mode === 'manual' ? activityItems : [],
            client_id: mode === 'shift' ? shift!.client_id : clientId || null,
            site_id: mode === 'manual' && siteId ? siteId : null,
            work_date: baseDate,
            starts_at: startsAt,
            ends_at: endsAt,
            break_minutes: Number(form.breakMin) || 0,
            mileage_km: Number(form.mileageKm) || 0,
            sleepover: !!form.sleepover,
            on_call: !!form.onCall,
            public_holiday: !!form.publicHoliday,
            notes: form.notes || null,
            submit: !asDraft,
            tasks: mode === 'shift' ? tasks.map((t) => ({ id: t.id, included: t.included, completed: t.completed })) : [],
        };

        router.post('/operations/timesheets', payload, {
            preserveScroll: true,
            onSuccess: () => {
                setSubmitting(false);
                setDone(Date.now());
            },
            onError: (errs) => {
                setSubmitting(false);
                setErrors(errs as Record<string, string>);
            },
        });
    }

    const subjectLabel =
        mode === 'shift'
            ? shift?.client
                ? `${shift.client.first_name} ${shift.client.last_name}`
                : ''
            : activityMeta?.label ?? '';

    const stepLabel = done
        ? 'Saved'
        : step === 1
          ? mode === 'shift'
              ? 'Pick shift'
              : 'Choose activity'
          : mode === 'shift'
            ? 'Confirm hours & tasks'
            : 'Log hours & details';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex max-h-[92vh] flex-col gap-0 overflow-hidden p-0 [&>button]:hidden"
                style={{ maxWidth: 'min(94vw, 920px)', width: 'min(94vw, 920px)' }}
            >
                <DialogTitle className="sr-only">Create timesheet</DialogTitle>
                <DialogDescription className="sr-only">
                    A guided two-step flow to log shift or activity time for
                    approval.
                </DialogDescription>

                {/* Wizard header — matches the Add Client chrome. */}
                <DialogHeader className="shrink-0 space-y-0">
                    <div className="flex items-center justify-between border-b border-border px-5 py-3.5">
                        <div className="text-[13px] font-semibold text-muted-foreground">
                            Step {done ? 2 : step} of 2 ·{' '}
                            <span className="text-foreground">{stepLabel}</span>
                        </div>
                        {/* eslint-disable-next-line no-restricted-syntax -- compact icon close matching the wizard header chrome. */}
                        <button
                            type="button"
                            onClick={() => onOpenChange(false)}
                            aria-label="Close"
                            className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>
                    <div className="h-[3px] shrink-0 bg-muted">
                        <div
                            className="h-full bg-primary transition-[width] duration-300"
                            style={{ width: done ? '100%' : `${(step / 2) * 100}%` }}
                        />
                    </div>
                </DialogHeader>

                {!done ? (
                    <div className="shrink-0 px-5 pt-4">
                        <StepHead
                            icon={FilePlus2}
                            title={
                                step === 1
                                    ? 'Pick what to log'
                                    : mode === 'shift'
                                      ? 'Confirm hours & tasks'
                                      : `Log ${activityMeta?.label ?? 'activity'}`
                            }
                            blurb={
                                step === 1
                                    ? 'Timesheets normally start from a real shift, but you can also log training, meetings, travel or other non-shift time.'
                                    : mode === 'shift' && shift
                                      ? `Linked to shift #${shift.id} · ${shift.client?.first_name} ${shift.client?.last_name} · ${shift.location ?? ''}`
                                      : `No shift attached · ${fmtDate(workDate)} · type "${activityMeta?.label ?? ''}"`
                            }
                        />
                    </div>
                ) : null}

                {/* Body */}
                <div className="min-h-0 flex-1 overflow-y-auto bg-muted/30">
                    {done ? (
                        <div className="grid place-items-center px-6 py-14 text-center">
                            <div className="grid h-14 w-14 place-items-center rounded-full bg-status-success-bg">
                                <CheckCircle2 className="h-7 w-7 text-status-success" />
                            </div>
                            <div className="mt-3 text-base font-semibold">Timesheet created</div>
                            <p className="mt-1 max-w-md text-[12.5px] text-muted-foreground">
                                {mode === 'shift' && shift
                                    ? `${taskTotal} task${taskTotal === 1 ? '' : 's'} pulled through from shift #${shift.id}. ${form.notes ? '' : 'You can edit it any time before payroll closes.'}`
                                    : `Logged as "${activityMeta?.label}" on ${fmtDate(workDate)}${
                                          activityItems.length ? ` with ${activityItems.length} activity item${activityItems.length === 1 ? '' : 's'}` : ''
                                      }. Goes to your manager once submitted.`}
                            </p>
                        </div>
                    ) : step === 1 ? (
                        <div className="p-5">
                            {/* Mode toggle */}
                            <div className="mb-4 inline-flex rounded-lg border border-border bg-background p-0.5 shadow-sm">
                                <button
                                    type="button"
                                    onClick={() => setMode('shift')}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[12.5px] font-semibold transition',
                                        mode === 'shift' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-foreground/70 hover:bg-muted',
                                    )}
                                    aria-pressed={mode === 'shift'}
                                >
                                    <CalendarDays className="h-3.5 w-3.5" /> From a shift
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setMode('manual');
                                        setShift(null);
                                    }}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[12.5px] font-semibold transition',
                                        mode === 'manual' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-foreground/70 hover:bg-muted',
                                    )}
                                    aria-pressed={mode === 'manual'}
                                >
                                    <BookOpen className="h-3.5 w-3.5" /> No shift — manual entry
                                </button>
                            </div>

                            {mode === 'shift' ? (
                                <>
                                    <div className="mb-3">
                                        <div className="relative">
                                            <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                            <Input
                                                value={search}
                                                onChange={(e) => setSearch(e.target.value)}
                                                placeholder="Search by client, location, or shift #"
                                                className="pl-8"
                                            />
                                        </div>
                                    </div>
                                    <div className="text-[11.5px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Today &amp; recent shifts
                                    </div>
                                    {filteredShifts.length === 0 ? (
                                        <div className="mt-2 rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                                            No upcoming shifts to log against. Switch to <span className="font-semibold">manual entry</span> to log
                                            training, meetings or other non-shift time.
                                        </div>
                                    ) : (
                                        <div className="mt-2 grid gap-2 md:grid-cols-2">
                                            {filteredShifts.map((s) => (
                                                <ShiftTile key={s.id} shift={s} active={shift?.id === s.id} onSelect={handleShiftSelect} />
                                            ))}
                                        </div>
                                    )}
                                </>
                            ) : (
                                <>
                                    <div className="mb-3 rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2.5 text-[12px] text-status-warning">
                                        <strong>Manual entry.</strong> Use this for time worked that isn't tied to a rostered shift — training,
                                        meetings, travel between sites, etc. Approval still flows through your manager and lands in payroll the same
                                        way.
                                    </div>

                                    <div className="mb-3 grid gap-3 sm:grid-cols-3">
                                        <div className="space-y-1">
                                            <Label className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                <CalendarIcon className="h-3 w-3" /> Date worked
                                            </Label>
                                            <Input type="date" value={workDate} onChange={(e) => setWorkDate(e.target.value)} className="h-9" />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                <User className="h-3 w-3" /> Linked client (optional)
                                            </Label>
                                            <SearchableSelect
                                                value={clientId}
                                                onChange={setClientId}
                                                options={clients.map((c) => ({
                                                    id: c.id,
                                                    label: `${c.first_name} ${c.last_name}`,
                                                    sub: `#${c.id}`,
                                                }))}
                                                placeholder="Search clients…"
                                                emptyLabel="— No client —"
                                                icon={User}
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                <MapPin className="h-3 w-3" /> Linked site (optional)
                                            </Label>
                                            <SearchableSelect
                                                value={siteId}
                                                onChange={setSiteId}
                                                options={sites.map((s) => ({ id: s.id, label: s.name }))}
                                                placeholder="Search sites…"
                                                emptyLabel="— No site —"
                                                icon={MapPin}
                                            />
                                        </div>
                                    </div>

                                    <div className="text-[11.5px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        What kind of time is this?
                                    </div>
                                    <div className="mt-2 grid gap-2 sm:grid-cols-2 md:grid-cols-4">
                                        {ACTIVITY_TYPES.map((a) => {
                                            const Ic = a.Icon;
                                            const active = activityType === a.key;
                                            return (
                                                <button
                                                    key={a.key}
                                                    type="button"
                                                    onClick={() => setActivityType(a.key)}
                                                    aria-pressed={active}
                                                    className={cn(
                                                        'flex items-start gap-2 rounded-xl border bg-card p-3 text-left transition hover:border-primary/40 hover:bg-status-info-bg/30',
                                                        active && 'border-primary bg-status-info-bg shadow-sm ring-1 ring-primary/30',
                                                    )}
                                                >
                                                    <span
                                                        className={cn(
                                                            'mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg',
                                                            active ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground/60',
                                                        )}
                                                    >
                                                        <Ic className="h-3.5 w-3.5" />
                                                    </span>
                                                    <span className="min-w-0">
                                                        <span className="block text-[12.5px] font-semibold">{a.label}</span>
                                                        <span className="block text-[11px] text-muted-foreground">{a.desc}</span>
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </div>
                    ) : mode === 'shift' ? (
                        <div className="grid gap-4 p-5 md:grid-cols-[1fr_320px]">
                            <div className="space-y-4">
                                <div className="rounded-xl border border-border bg-card p-4">
                                    <div className="mb-3 text-[11.5px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Actual times worked
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label className="text-[11.5px] text-muted-foreground">Start</Label>
                                            <Input type="time" value={form.start} onChange={(e) => setForm({ ...form, start: e.target.value })} className="h-9" />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-[11.5px] text-muted-foreground">End</Label>
                                            <Input type="time" value={form.end} onChange={(e) => setForm({ ...form, end: e.target.value })} className="h-9" />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                <Coffee className="h-3 w-3" /> Break (minutes)
                                            </Label>
                                            <Input
                                                type="number"
                                                min={0}
                                                value={form.breakMin}
                                                onChange={(e) => setForm({ ...form, breakMin: Number(e.target.value) })}
                                                className="h-9"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                <Car className="h-3 w-3" /> Mileage (km)
                                            </Label>
                                            <Input
                                                type="number"
                                                min={0}
                                                value={form.mileageKm}
                                                onChange={(e) => setForm({ ...form, mileageKm: Number(e.target.value) })}
                                                className="h-9"
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-3 flex items-center justify-between rounded-lg border border-border bg-muted/40 px-3 py-2 text-[12.5px]">
                                        <span className="inline-flex items-center gap-2 text-muted-foreground">
                                            <Clock className="h-4 w-4" /> Estimated billable hours
                                        </span>
                                        <span className="text-base font-semibold tabular-nums text-primary">{liveHours ?? '—'}h</span>
                                    </div>
                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                        <Toggle label="Sleepover" value={form.sleepover} onChange={(v) => setForm({ ...form, sleepover: v })} />
                                        <Toggle label="On-call" value={form.onCall} onChange={(v) => setForm({ ...form, onCall: v })} />
                                        <Toggle label="Public holiday" value={form.publicHoliday} onChange={(v) => setForm({ ...form, publicHoliday: v })} />
                                    </div>
                                </div>

                                <div className="rounded-xl border border-border bg-card p-4">
                                    <Label className="text-[11.5px] text-muted-foreground">Notes (optional)</Label>
                                    <Textarea
                                        rows={3}
                                        value={form.notes}
                                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                        placeholder="Anything payroll or your manager should know — overtime reason, missed break, mileage detail…"
                                        className="mt-1 min-h-[80px] resize-y"
                                    />
                                </div>
                            </div>

                            <aside className="space-y-4">
                                <div className="rounded-xl border border-primary/30 bg-status-info-bg p-3">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <ClipboardList className="h-4 w-4 text-primary" />
                                            <span className="text-[12.5px] font-semibold">Tasks pulled from shift</span>
                                        </div>
                                        <span className="text-[11.5px] font-medium tabular-nums text-muted-foreground">
                                            {taskCompleted}/{taskTotal}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11.5px] text-muted-foreground">
                                        All scheduled tasks from shift <strong>#{shift?.id}</strong> are attached. Untick any that didn't occur.
                                    </p>
                                </div>
                                <ul className="space-y-1.5">
                                    {tasks.length === 0 ? (
                                        <li className="rounded-lg border border-dashed border-border px-3 py-3 text-center text-[11.5px] text-muted-foreground">
                                            No tasks attached to this shift.
                                        </li>
                                    ) : (
                                        tasks.map((t, idx) => (
                                            <li
                                                key={t.id}
                                                className={cn(
                                                    'flex items-start gap-2.5 rounded-lg border border-border bg-card p-2.5 transition',
                                                    !t.included && 'opacity-50',
                                                )}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={t.included && t.completed}
                                                    onChange={(e) =>
                                                        setTasks(tasks.map((x, i) => (i === idx ? { ...x, completed: e.target.checked } : x)))
                                                    }
                                                    className="mt-0.5"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-[12.5px] font-medium leading-tight">{t.label}</div>
                                                    <div className="mt-0.5 text-[11px] tabular-nums text-muted-foreground">
                                                        {t.time ?? ''} {t.time ? '·' : ''} {t.minutes}m
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setTasks(tasks.map((x, i) => (i === idx ? { ...x, included: !x.included } : x)))
                                                    }
                                                    className="text-[11px] font-medium text-muted-foreground hover:text-status-critical"
                                                >
                                                    {t.included ? 'exclude' : 'include'}
                                                </button>
                                            </li>
                                        ))
                                    )}
                                </ul>
                            </aside>
                        </div>
                    ) : (
                        // ── Step 2B — manual mode ──
                        <div className="grid gap-4 p-5 md:grid-cols-[1fr_320px]">
                            <div className="space-y-4">
                                <div className="rounded-xl border border-primary/30 bg-status-info-bg p-3">
                                    <div className="flex items-center gap-2">
                                        <ActivityIcon className="h-4 w-4 text-primary" />
                                        <div className="min-w-0 flex-1">
                                            <div className="text-[12.5px] font-semibold">{activityMeta?.label}</div>
                                            <div className="text-[11.5px] text-muted-foreground">
                                                {activityMeta?.desc} · {fmtDate(workDate)}
                                                {clientId
                                                    ? ` · for ${clients.find((c) => String(c.id) === String(clientId))?.first_name ?? ''} ${
                                                          clients.find((c) => String(c.id) === String(clientId))?.last_name ?? ''
                                                      }`
                                                    : ''}
                                                {siteId ? ` · ${sites.find((s) => String(s.id) === String(siteId))?.name ?? ''}` : ''}
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setStep(1)}
                                            className="text-[11px] font-medium text-muted-foreground hover:text-primary"
                                        >
                                            Change
                                        </button>
                                    </div>
                                </div>

                                <div className="rounded-xl border border-border bg-card p-4">
                                    <div className="mb-3 text-[11.5px] font-semibold uppercase tracking-wider text-muted-foreground">
                                        Actual times worked
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label className="text-[11.5px] text-muted-foreground">Start</Label>
                                            <Input type="time" value={form.start} onChange={(e) => setForm({ ...form, start: e.target.value })} className="h-9" />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-[11.5px] text-muted-foreground">End</Label>
                                            <Input type="time" value={form.end} onChange={(e) => setForm({ ...form, end: e.target.value })} className="h-9" />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                <Coffee className="h-3 w-3" /> Break (minutes)
                                            </Label>
                                            <Input
                                                type="number"
                                                min={0}
                                                value={form.breakMin}
                                                onChange={(e) => setForm({ ...form, breakMin: Number(e.target.value) })}
                                                className="h-9"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                <Car className="h-3 w-3" /> Mileage (km)
                                            </Label>
                                            <Input
                                                type="number"
                                                min={0}
                                                value={form.mileageKm}
                                                onChange={(e) => setForm({ ...form, mileageKm: Number(e.target.value) })}
                                                className="h-9"
                                            />
                                        </div>
                                    </div>
                                    <div className="mt-3 flex items-center justify-between rounded-lg border border-border bg-muted/40 px-3 py-2 text-[12.5px]">
                                        <span className="inline-flex items-center gap-2 text-muted-foreground">
                                            <Clock className="h-4 w-4" /> Estimated billable hours
                                        </span>
                                        <span className="text-base font-semibold tabular-nums text-primary">{liveHours ?? '—'}h</span>
                                    </div>
                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                        <Toggle label="On-call" value={form.onCall} onChange={(v) => setForm({ ...form, onCall: v })} />
                                        <Toggle label="Public holiday" value={form.publicHoliday} onChange={(v) => setForm({ ...form, publicHoliday: v })} />
                                    </div>
                                </div>

                                <div className="rounded-xl border border-border bg-card p-4">
                                    <Label className="text-[11.5px] text-muted-foreground">Notes (recommended for manual entries)</Label>
                                    <Textarea
                                        rows={3}
                                        value={form.notes}
                                        onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                        placeholder={`Briefly describe — e.g. "${
                                            activityType === 'training'
                                                ? 'Manual handling refresher, room 2, with B. Stone'
                                                : activityType === 'meeting'
                                                    ? 'Monthly site huddle, Karori'
                                                    : (activityMeta?.label ?? '') + ' …'
                                        }"`}
                                        className="mt-1 min-h-[80px] resize-y"
                                    />
                                </div>
                            </div>

                            <aside className="space-y-4">
                                <div className="rounded-xl border border-border bg-card p-4">
                                    <div className="flex items-center gap-2">
                                        <ClipboardList className="h-4 w-4 text-primary" />
                                        <span className="text-[12.5px] font-semibold">
                                            Activity items <span className="font-normal text-muted-foreground">(optional)</span>
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11.5px] text-muted-foreground">
                                        Without a shift, add the items that made up your time so the approver can see what was done.
                                    </p>
                                    <ul className="mt-3 space-y-1.5">
                                        {activityItems.length === 0 ? (
                                            <li className="rounded-lg border border-dashed border-border px-3 py-3 text-center text-[11.5px] text-muted-foreground">
                                                No items yet — add one below.
                                            </li>
                                        ) : (
                                            activityItems.map((it, idx) => (
                                                <li key={idx} className="flex items-center gap-2 rounded-lg border border-border bg-card p-2">
                                                    <span className="grid h-5 w-5 place-items-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
                                                        {idx + 1}
                                                    </span>
                                                    <span className="min-w-0 flex-1 truncate text-[12.5px]">{it}</span>
                                                    <button
                                                        type="button"
                                                        onClick={() => setActivityItems(activityItems.filter((_, i) => i !== idx))}
                                                        aria-label="Remove item"
                                                        className="grid h-6 w-6 place-items-center rounded text-muted-foreground hover:bg-status-critical-bg hover:text-status-critical"
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                </li>
                                            ))
                                        )}
                                    </ul>
                                    <div className="mt-2 flex items-center gap-1.5">
                                        <Input
                                            type="text"
                                            value={newActivityItem}
                                            onChange={(e) => setNewActivityItem(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' && newActivityItem.trim()) {
                                                    e.preventDefault();
                                                    setActivityItems([...activityItems, newActivityItem.trim()]);
                                                    setNewActivityItem('');
                                                }
                                            }}
                                            placeholder="e.g. Manual handling module 2"
                                            className="h-9"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (newActivityItem.trim()) {
                                                    setActivityItems([...activityItems, newActivityItem.trim()]);
                                                    setNewActivityItem('');
                                                }
                                            }}
                                            disabled={!newActivityItem.trim()}
                                            className="grid h-9 w-9 place-items-center rounded-md bg-primary text-primary-foreground disabled:opacity-40"
                                            aria-label="Add activity item"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </aside>
                        </div>
                    )}

                    {Object.keys(errors).length > 0 ? (
                        <div className="border-t border-status-critical/30 bg-status-critical-bg p-3 text-[12px] text-status-critical">
                            {Object.entries(errors).map(([field, msg]) => (
                                <div key={field}>
                                    <strong>{field}:</strong> {msg}
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>

                {/* Footer — wizard contract: ghost Back left; Cancel + primary right. */}
                {!done ? (
                    <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-border bg-muted/30 px-5 py-3.5">
                        <div>
                            {step === 2 ? (
                                <Button
                                    variant="ghost"
                                    onClick={() => setStep(1)}
                                    className="gap-1.5"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    {mode === 'shift' ? 'Back to shifts' : 'Back to activity type'}
                                </Button>
                            ) : null}
                        </div>
                        <div className="flex items-center gap-2.5">
                            <Button variant="outline" onClick={() => onOpenChange(false)}>
                                Cancel
                            </Button>
                            {step === 1 ? (
                                <Button disabled={!canAdvanceFromStep1} onClick={() => setStep(2)} className="gap-1.5">
                                    {mode === 'shift' ? 'Pull tasks & continue' : 'Continue to hours'}
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            ) : (
                                <>
                                    <Button variant="secondary" onClick={() => submit(true)} disabled={submitting} className="gap-1.5">
                                        {submitting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Save className="h-3.5 w-3.5" />}
                                        Save as draft
                                    </Button>
                                    <Button onClick={() => submit(false)} disabled={submitting} className="gap-1.5">
                                        {submitting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
                                        Submit for approval
                                    </Button>
                                </>
                            )}
                        </div>
                    </footer>
                ) : (
                    <footer className="shrink-0 border-t border-border bg-muted/30 px-5 py-3.5 text-right">
                        <Button onClick={() => onOpenChange(false)}>Done</Button>
                    </footer>
                )}
            </DialogContent>
        </Dialog>
    );
}
