/* eslint-disable no-restricted-syntax -- The assessment cards + breakdown rows are
 * bespoke layout surfaces on semantic design tokens, not generic Card content. */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    HealthClinicalShell,
    RegisterStatStrip,
    type HealthClinicalKpis,
} from '@/pages/health-clinical/components/health-clinical-shell';
import { RecordAssessmentDialog } from '@/pages/health-clinical/components/record-assessment-dialog';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronDown,
    ChevronUp,
    ClipboardCheck,
    Clock,
    Filter,
    Paperclip,
    Plus,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useState } from 'react';

type PaginatedData<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type BreakdownRow = {
    key: string;
    label: string;
    detail: string;
    points: number | null;
};

type AssessmentRow = {
    id: number;
    assessment_type: string;
    type_label: string;
    type_short: string;
    domain: string;
    assessed_at: string | null;
    total_score: number | null;
    risk_band: string | null;
    band_label: string | null;
    band_tone: string | null;
    summary: string;
    advice: string | null;
    breakdown: BreakdownRow[];
    tool_version: string;
    notes: string | null;
    review_due_at: string | null;
    review_due: boolean;
    needs_action: boolean;
    attachments_count: number;
    assessor: { id: number; name: string } | null;
    client: {
        id: number;
        first_name: string;
        last_name: string;
        site: string | null;
    } | null;
};

type Stats = {
    total: number;
    high_risk: number;
    review_due: number;
    by_type: Record<string, number>;
};
type Option = { value: string; label: string };
type TypeOption = Option & {
    short: string;
    domain: string;
    scored: boolean;
    tool_version: string;
};
type BandOption = Option & { tone: string };
type FilterOptions = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    types: TypeOption[];
    bands: BandOption[];
};
type Filters = {
    client_id?: string;
    assessment_type?: string;
    risk_band?: string;
    review_due?: string | boolean;
};

type Props = {
    records: PaginatedData<AssessmentRow>;
    stats: Stats;
    filters: Filters;
    filter_options: FilterOptions;
    kpis: HealthClinicalKpis;
    tab_counts?: Record<string, number>;
};

const ALL = '__all__';

const BAND_BADGE: Record<string, string> = {
    success: 'border-status-success/40 text-status-success',
    warning: 'border-status-warning/40 text-status-warning',
    critical: 'border-status-critical/40 text-status-critical',
    neutral: 'border-border text-muted-foreground',
};

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function Assessments({
    records,
    stats,
    filters,
    filter_options,
    kpis,
    tab_counts,
}: Props) {
    const page = usePage<{
        auth?: { can?: { clinical?: { assessmentsRecord?: boolean } } };
    }>();
    const canRecord = !!page.props.auth?.can?.clinical?.assessmentsRecord;

    const [recordOpen, setRecordOpen] = useState(false);
    const [expanded, setExpanded] = useState<number | null>(null);
    const [local, setLocal] = useState<Filters>({
        client_id: filters.client_id ?? '',
        assessment_type: filters.assessment_type ?? '',
        risk_band: filters.risk_band ?? '',
        review_due: filters.review_due ? '1' : '',
    });

    const apply = (overrides?: Partial<Filters>) => {
        const merged = { ...local, ...overrides };
        const clean = Object.fromEntries(
            Object.entries(merged).filter(
                ([, v]) => v !== '' && v !== undefined && v !== false,
            ),
        );
        router.get('/health-clinical/assessments', clean, {
            preserveState: true,
            replace: true,
        });
    };
    const clear = () => {
        setLocal({});
        router.get(
            '/health-clinical/assessments',
            {},
            { preserveState: true, replace: true },
        );
    };
    const hasFilters = Object.values(local).some(
        (v) => v !== '' && v !== undefined && v !== false,
    );

    return (
        <HealthClinicalShell
            activeTab="assessments"
            kpis={kpis}
            tabCounts={tab_counts}
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <RegisterStatStrip
                    stats={[
                        { label: 'Assessments', value: stats.total },
                        {
                            label: 'High risk',
                            value: stats.high_risk,
                            tone: stats.high_risk > 0 ? 'critical' : 'default',
                        },
                        {
                            label: 'Review due',
                            value: stats.review_due,
                            tone: stats.review_due > 0 ? 'warning' : 'default',
                        },
                    ]}
                />
                {canRecord ? (
                    <Button size="sm" onClick={() => setRecordOpen(true)}>
                        <Plus className="mr-1.5 h-4 w-4" /> Record assessment
                    </Button>
                ) : null}
            </div>

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <Filter className="h-4 w-4" /> Filters
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        <FilterSelect
                            label="Client"
                            value={local.client_id}
                            placeholder="All clients"
                            onChange={(v) =>
                                setLocal((c) => ({ ...c, client_id: v }))
                            }
                            options={filter_options.clients.map((c) => ({
                                value: String(c.id),
                                label: `${c.first_name} ${c.last_name}`,
                            }))}
                        />
                        <FilterSelect
                            label="Tool"
                            value={local.assessment_type}
                            placeholder="All tools"
                            onChange={(v) =>
                                setLocal((c) => ({ ...c, assessment_type: v }))
                            }
                            options={filter_options.types.map((t) => ({
                                value: t.value,
                                label: t.label,
                            }))}
                        />
                        <FilterSelect
                            label="Risk band"
                            value={local.risk_band}
                            placeholder="Any band"
                            onChange={(v) =>
                                setLocal((c) => ({ ...c, risk_band: v }))
                            }
                            options={filter_options.bands.map((b) => ({
                                value: b.value,
                                label: b.label,
                            }))}
                        />
                        <div className="flex items-end">
                            <label className="flex items-center gap-2 pb-1.5">
                                <Switch
                                    checked={local.review_due === '1'}
                                    onCheckedChange={(v) =>
                                        setLocal((c) => ({
                                            ...c,
                                            review_due: v ? '1' : '',
                                        }))
                                    }
                                />
                                <span className="text-[13px] font-medium">
                                    Review due only
                                </span>
                            </label>
                        </div>
                        <div className="flex items-end gap-2">
                            <Button size="sm" onClick={() => apply()}>
                                Apply
                            </Button>
                            {hasFilters ? (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={clear}
                                    className="gap-1"
                                >
                                    <X className="h-3 w-3" /> Clear
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </CardContent>
            </Card>

            {records.data.length === 0 ? (
                <Card>
                    <CardContent className="p-12 text-center">
                        <ClipboardCheck className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                        <p className="font-medium text-muted-foreground">
                            No clinical risk assessments here
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground/70">
                            {canRecord
                                ? 'Use “Record assessment” to complete a FRAT, Braden, MUST or IDDSI assessment.'
                                : 'No assessments match the current filters.'}
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="flex flex-col gap-3">
                    {records.data.map((a) => {
                        const isOpen = expanded === a.id;
                        const tone =
                            BAND_BADGE[a.band_tone ?? 'neutral'] ??
                            BAND_BADGE.neutral;
                        const clientName = a.client
                            ? `${a.client.first_name} ${a.client.last_name}`.trim()
                            : 'No client';
                        return (
                            <div
                                key={a.id}
                                className="rounded-xl border border-border bg-card p-4 shadow-sm"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant="outline"
                                        className="border-primary/30 text-[11px] font-semibold text-primary"
                                    >
                                        {a.type_short}
                                    </Badge>
                                    {a.client ? (
                                        <Link
                                            href={`/operations/clients/${a.client.id}`}
                                            className="text-sm font-semibold text-status-info hover:underline"
                                        >
                                            {clientName}
                                        </Link>
                                    ) : (
                                        <span className="text-sm font-semibold">
                                            {clientName}
                                        </span>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {a.client?.site ?? a.domain}
                                    </span>
                                    {a.risk_band ? (
                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                                tone,
                                            )}
                                        >
                                            {a.needs_action ? (
                                                <AlertTriangle className="h-3 w-3" />
                                            ) : (
                                                <ShieldCheck className="h-3 w-3" />
                                            )}
                                            {a.band_label}
                                            {a.total_score !== null
                                                ? ` · ${a.total_score}`
                                                : ''}
                                        </span>
                                    ) : (
                                        <Badge
                                            variant="outline"
                                            className="border-status-info/40 text-[11px] text-status-info"
                                        >
                                            {a.summary.replace('IDDSI · ', '')}
                                        </Badge>
                                    )}
                                    {a.review_due ? (
                                        <Badge
                                            variant="outline"
                                            className="border-status-warning/40 text-[10px] text-status-warning"
                                        >
                                            <Clock className="mr-0.5 h-3 w-3" />
                                            Review due
                                        </Badge>
                                    ) : null}
                                    {a.attachments_count > 0 ? (
                                        <span className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                            <Paperclip className="h-3 w-3" />
                                            {a.attachments_count}
                                        </span>
                                    ) : null}
                                    <span className="ml-auto inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                        <Clock className="h-3 w-3" />
                                        {formatDate(a.assessed_at)}
                                    </span>
                                </div>

                                <div className="mt-2 flex items-center justify-between gap-3">
                                    <p className="text-[13px] text-foreground">
                                        {a.summary}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setExpanded(isOpen ? null : a.id)
                                        }
                                        aria-expanded={isOpen}
                                        aria-controls={`assessment-breakdown-${a.id}`}
                                        className="inline-flex items-center gap-1 text-[12px] font-medium text-muted-foreground hover:text-foreground"
                                    >
                                        {isOpen ? (
                                            <>
                                                Hide breakdown{' '}
                                                <ChevronUp className="h-3.5 w-3.5" />
                                            </>
                                        ) : (
                                            <>
                                                View breakdown{' '}
                                                <ChevronDown className="h-3.5 w-3.5" />
                                            </>
                                        )}
                                    </button>
                                </div>

                                {isOpen ? (
                                    <div
                                        id={`assessment-breakdown-${a.id}`}
                                        className="mt-3 rounded-lg border border-border bg-muted/20 p-3"
                                    >
                                        <div className="flex flex-col gap-1.5">
                                            {a.breakdown.map((row) => (
                                                <div
                                                    key={row.key}
                                                    className="flex items-center justify-between gap-3 text-[13px]"
                                                >
                                                    <span className="text-foreground/80">
                                                        {row.label}
                                                    </span>
                                                    <span className="flex items-center gap-2">
                                                        <span className="text-muted-foreground">
                                                            {row.detail}
                                                        </span>
                                                        {row.points !== null ? (
                                                            <span className="min-w-[20px] rounded bg-background px-1 text-center font-semibold tabular-nums">
                                                                {row.points}
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                        {a.advice ? (
                                            <p className="mt-2.5 border-t pt-2 text-[13px] font-medium">
                                                {a.advice}
                                            </p>
                                        ) : null}
                                        {a.notes ? (
                                            <p className="mt-2 text-[13px] text-muted-foreground">
                                                <span className="font-medium text-foreground">
                                                    Notes:
                                                </span>{' '}
                                                {a.notes}
                                            </p>
                                        ) : null}
                                        <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                                            <span>{a.tool_version}</span>
                                            {a.assessor ? (
                                                <span>
                                                    Assessed by{' '}
                                                    {a.assessor.name}
                                                </span>
                                            ) : null}
                                            {a.review_due_at ? (
                                                <span>
                                                    Review due{' '}
                                                    {formatDate(
                                                        a.review_due_at,
                                                    )}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            )}

            {records.last_page > 1 ? (
                <div className="flex items-center justify-between px-1">
                    <p className="text-xs text-muted-foreground">
                        Page {records.current_page} of {records.last_page} (
                        {records.total} total)
                    </p>
                    <div className="flex gap-1">
                        {records.links.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </div>
            ) : null}

            {/* No onSaved close — let the wizard show its success pane (parity with the
                sibling record dialogs); the register refreshes via the controller's back(). */}
            <RecordAssessmentDialog
                open={recordOpen}
                onClose={() => setRecordOpen(false)}
            />
        </HealthClinicalShell>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    placeholder,
    options,
}: {
    label: string;
    value?: string | boolean;
    onChange: (v: string) => void;
    placeholder: string;
    options: Option[];
}) {
    return (
        <div>
            <Label className="text-xs">{label}</Label>
            <Select
                value={(typeof value === 'string' && value) || ALL}
                onValueChange={(v) => onChange(v === ALL ? '' : v)}
            >
                <SelectTrigger className="h-8 text-xs">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>{placeholder}</SelectItem>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
