import { FleetPageMenu } from '@/components/fleet-assets/fleet-page-menu';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    BarChart3,
    BookOpen,
    CalendarDays,
    Columns3,
    Copy,
    Database,
    Download,
    FileText,
    Filter,
    History,
    LayoutTemplate,
    Play,
    RotateCcw,
    Save,
    Search,
    ShieldCheck,
    SlidersHorizontal,
    Trash2,
    Upload,
} from 'lucide-react';
import {
    Component,
    useEffect,
    useRef,
    useState,
    type ErrorInfo,
    type ReactNode,
} from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import '../../../css/maintenance-date-time.css';
import '../../../css/report-studio.css';
import { ReportDialog } from './_dialogs';
import {
    api,
    definitionKey,
    displayNumber,
    downloadBlob,
    initialDefinition,
    parseDefinitionFile,
    type Definition,
    type Group,
    type Payload,
    type Saved,
    type Source,
    type Template,
} from './model';
import {
    ColumnEditor,
    GroupedFilters,
    ReportRange,
    reportDate,
} from './studio-controls';
import { StudioLibrary, fleetViews } from './studio-library';

type Props = {
    initialView?: string;
    domain: string;
    sources: Record<string, Source>;
    templates: Template[];
    saved: Saved[];
    shared?: {
        id: number;
        name: string;
        source: string;
        definition: Definition;
    }[];
    initialSubject?: number | null;
    viewerId: number;
    canExport?: boolean;
    exportFormats?: string[];
};
const steps = ['Data', 'Columns', 'Filters', 'Measures', 'Layout', 'Delivery'];
const stepIcons = [
    Database,
    Columns3,
    Filter,
    BarChart3,
    LayoutTemplate,
    CalendarDays,
];
const operations = [
    'count',
    'known',
    'distinct',
    'sum',
    'avg',
    'min',
    'max',
    'p50',
    'p95',
    'formula',
];
function Choice({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            <Select
                value={value || '_none'}
                onValueChange={(v) => onChange(v === '_none' ? '' : v)}
            >
                <SelectTrigger aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((o) => (
                        <SelectItem
                            key={o.value || '_none'}
                            value={o.value || '_none'}
                        >
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
function Toggle({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <Label className="flex min-h-11 cursor-pointer items-center gap-3">
            <Checkbox
                checked={checked}
                onCheckedChange={(v) => onChange(v === true)}
            />
            {label}
        </Label>
    );
}
class BuilderBoundary extends Component<
    { children: ReactNode },
    { failed: boolean }
> {
    state = { failed: false };
    static getDerivedStateFromError() {
        return { failed: true };
    }
    componentDidCatch(_error: Error, _info: ErrorInfo) {
        /* The saved definition remains on the server. */
    }
    render() {
        return this.state.failed ? (
            <Card className="p-6">
                <h2 className="text-section-title">
                    The report could not be displayed
                </h2>
                <p>
                    Your saved reports are safe. Reload to recover the last
                    validated draft.
                </p>
                <Button onClick={() => window.location.reload()}>
                    Reload builder
                </Button>
            </Card>
        ) : (
            this.props.children
        );
    }
}

export function ReportWorkspace(props: Props) {
    const { domain, sources, templates, viewerId } = props;
    const [activeView, setActiveView] = useState(
        props.initialView ?? 'library',
    );
    const focusedView =
        domain === 'fleet'
            ? fleetViews.find((v) => v.key === activeView && sources[v.source])
            : undefined;
    const focusView = focusedView?.key ?? null;
    const tab = focusView ? 'builder' : activeView;
    const navigateView = (value: string) => {
        setActiveView(value);
        if (props.initialView === undefined) return;
        const url = new URL(window.location.href);
        if (domain === 'fleet')
            url.pathname =
                value === 'builder'
                    ? '/fleet-assets/reports/builder'
                    : '/fleet-assets/reports';
        if (value === 'library' || (domain === 'fleet' && value === 'builder'))
            url.searchParams.delete('view');
        else url.searchParams.set('view', value);
        router.push({
            url: url.pathname + url.search,
            props: (current) => ({ ...current, initialView: value }),
            preserveState: true,
            preserveScroll: true,
        });
    };
    const [definition, setDefinition] = useState<Definition>(() => ({
        ...initialDefinition(
            focusedView?.source ?? Object.keys(sources)[0],
            sources,
            focusedView?.title,
        ),
        subject_id: props.initialSubject ?? null,
    }));
    const [saved, setSaved] = useState(props.saved);
    const [selected, setSelected] = useState<Saved | null>(null);
    const [livePreview, setLivePreview] = useState(true);
    const [exportOpen, setExportOpen] = useState(false);
    const [saveOpen, setSaveOpen] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const requestedPreview = useRef('');
    const generateRef = useRef<() => void>(() => {});
    const [step, setStep] = useState(0);
    const [scopeOpen, setScopeOpen] = useState(false);
    const [history, setHistory] = useState<Definition[]>([]);
    const [future, setFuture] = useState<Definition[]>([]);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [query, setQuery] = useState('');
    const [targets, setTargets] = useState<{ id: number; label: string }[]>([]);
    const [siteOptions, setSiteOptions] = useState<
        { id: number; label: string }[]
    >([]);
    const [targetQuery, setTargetQuery] = useState('');
    const [reason, setReason] = useState('');
    const [folder, setFolder] = useState('');
    const [recipientEmail, setRecipientEmail] = useState('');
    const [favourite, setFavourite] = useState(false);
    const [versions, setVersions] = useState<
        { version: number; definition: Definition }[]
    >([]);
    const [run, setRun] = useState<{ id: string; status: string } | null>(null);
    const [payload, setPayload] = useState<Payload | null>(null);
    const [runDefinition, setRunDefinition] = useState<Definition | null>(null);
    const [csvSection, setCsvSection] = useState('summary');
    const [format, setFormat] = useState(props.exportFormats?.[0] ?? 'xlsx');
    const [frequency, setFrequency] = useState('weekly');
    const [view, setView] = useState('summary');
    const [page, setPage] = useState(0);
    const [recent, setRecent] = useState<
        {
            id: string;
            definition: Definition;
            status: string;
            created_at: string;
        }[]
    >([]);
    const serial = useRef(0);
    const [runsRefresh, setRunsRefresh] = useState(0);
    const [runsLoading, setRunsLoading] = useState(false);
    const [runsError, setRunsError] = useState('');
    const importInput = useRef<HTMLInputElement>(null);
    const recoveredDraft = useRef<string | null | undefined>(undefined);
    const editedDraft = useRef(false);
    const [hasDraft, setHasDraft] = useState(false);
    const [draftStored, setDraftStored] = useState(false);
    const key = 'operational-report-draft:' + viewerId + ':' + domain;
    const source = sources[definition.source];
    const fields = Object.entries(source.fields).map(([value, f]) => ({
        value,
        label: f.label,
    }));
    const dirtyResult =
        runDefinition !== null &&
        definitionKey(runDefinition) !== definitionKey(definition);

    const change = (patch: Partial<Definition>) => {
        editedDraft.current = activeView === 'builder';
        setHistory((items) => [...items.slice(-29), definition]);
        setFuture([]);
        setDefinition({ ...definition, ...patch });
        setNotice('');
        setError('');
    };
    const perform = async (action: () => Promise<void>) => {
        setError('');
        setBusy(true);
        try {
            await action();
        } catch (e) {
            setError(e instanceof Error ? e.message : 'The request failed.');
        } finally {
            setBusy(false);
        }
    };
    const validate = async (candidate: unknown): Promise<Definition> =>
        (
            await (
                await api('/report-builder/validate', 'POST', {
                    definition: candidate,
                })
            ).json()
        ).definition;
    const restoreDraft = () =>
        perform(async () => {
            const draft = recoveredDraft.current;
            if (!draft)
                throw new Error('There is no recovered draft in this tab.');
            const valid = await validate(parseDefinitionFile(draft));
            if (!sources[valid.source])
                throw new Error(
                    'This draft belongs to another reporting area.',
                );
            change(valid);
            editedDraft.current = true;
            navigateView('builder');
            setNotice('Recovered and validated the draft.');
        });
    useEffect(() => {
        try {
            if (recoveredDraft.current === undefined) {
                recoveredDraft.current = sessionStorage.getItem(key);
                setHasDraft(Boolean(recoveredDraft.current));
            }
            if (editedDraft.current && activeView === 'builder') {
                const draft = JSON.stringify(definition);
                sessionStorage.setItem(key, draft);
                recoveredDraft.current = draft;
                setHasDraft(true);
                setDraftStored(true);
            }
        } catch {
            setDraftStored(false);
            /* Storage can be unavailable; saved reports still work. */
        }
    }, [definition, key, activeView]);
    useEffect(() => {
        if (props.initialView !== undefined) setActiveView(props.initialView);
    }, [props.initialView]);
    useEffect(() => {
        // Back/Forward can return to a different focused source without remounting.
        if (!focusedView || definition.source === focusedView.source) return;
        serial.current++;
        requestedPreview.current = '';
        setDefinition((current) => ({
            ...initialDefinition(
                focusedView.source,
                sources,
                focusedView.title,
            ),
            date_from: current.date_from,
            date_to: current.date_to,
            site_ids: current.site_ids,
        }));
        setRun(null);
        setPayload(null);
        setRunDefinition(null);
        setSelected(null);
    }, [focusedView, definition.source, sources]);
    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await api(
                    '/report-builder/targets?source=' +
                        encodeURIComponent(definition.source) +
                        '&q=' +
                        encodeURIComponent(targetQuery),
                    'GET',
                    undefined,
                    controller.signal,
                );
                const data = await response.json();
                setTargets(data.targets);
                setSiteOptions(data.sites ?? []);
            } catch (e) {
                if (!controller.signal.aborted) {
                    setTargets([]);
                    setError(
                        e instanceof Error
                            ? e.message
                            : 'Search is unavailable.',
                    );
                }
            }
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [definition.source, targetQuery]);

    useEffect(() => {
        if (!run || !['queued', 'running'].includes(run.status)) return;
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout>;
        const poll = async () => {
            try {
                const data = await (
                    await api('/report-builder/runs/' + run.id)
                ).json();
                if (cancelled) return;
                setRun({ id: data.id, status: data.status });
                if (data.payload) {
                    setPayload(data.payload);
                    setPage(0);
                }
                if (data.status === 'failed')
                    setError(
                        data.failure_code === 'access_changed'
                            ? 'Access changed during generation. Check your scope and try again.'
                            : 'The report could not be generated. Check the period and scope, then retry.',
                    );
                if (['queued', 'running'].includes(data.status))
                    timer = setTimeout(poll, 1500);
            } catch (e) {
                if (!cancelled) {
                    setError(
                        e instanceof Error
                            ? e.message
                            : 'Could not load the report.',
                    );
                    setRun(null);
                    setPayload(null);
                }
            }
        };
        timer = setTimeout(poll, 500);
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [run]);

    const load = (
        d: Definition,
        savedReport: Saved | null = null,
        nextView = 'builder',
    ) => {
        serial.current++;
        requestedPreview.current = '';
        setRun(null);
        change(d);
        editedDraft.current = nextView === 'builder';
        setDraftStored(false);
        setSelected(savedReport);
        setFolder(savedReport?.folder ?? '');
        setFavourite(savedReport?.favourite ?? false);
        setPayload(null);
        setRunDefinition(null);
        setVersions([]);
        navigateView(nextView);
        setStep(0);
        setPage(0);
    };
    const save = () =>
        perform(async () => {
            const valid = await validate(definition);
            const response = await (
                await api(
                    selected
                        ? '/report-builder/reports/' + selected.id
                        : '/report-builder/reports',
                    selected ? 'PUT' : 'POST',
                    {
                        definition: valid,
                        version: selected?.version,
                        folder,
                        favourite,
                    },
                )
            ).json();
            setSelected(response.report);
            setSaved((items) => [
                response.report,
                ...items.filter((item) => item.id !== response.report.id),
            ]);
            setNotice('Saved version ' + response.report.version + '.');
            setSaveOpen(false);
        });
    const archive = (item: Saved) =>
        perform(async () => {
            const response = await (
                await api(
                    '/report-builder/reports/' + item.id + '/archive',
                    'POST',
                    { archived: !item.archived_at },
                )
            ).json();
            setSaved((items) =>
                items.map((r) => (r.id === item.id ? response.report : r)),
            );
            setNotice(
                item.archived_at
                    ? 'Report restored.'
                    : 'Report archived. Use Restore to undo.',
            );
        });
    const generate = () =>
        perform(async () => {
            if (reason.trim().length < 3) {
                setStep(5);
                throw new Error('Add a short purpose for this report.');
            }
            requestedPreview.current =
                definitionKey(definition) + '|' + reason.trim();
            const token = ++serial.current;
            const valid = await validate(definition);
            const data = await (
                await api('/report-builder/runs', 'POST', {
                    definition: valid,
                    reason,
                    report_id: selected?.id,
                })
            ).json();
            if (token !== serial.current) return;
            setPayload(null);
            setRun({ id: data.id, status: 'queued' });
            setRunDefinition(valid);
            setView('summary');
            setNotice('');
        });
    const exportResult = () =>
        perform(async () => {
            if (!run || dirtyResult)
                throw new Error('Run the current definition before exporting.');
            const response = await api(
                '/report-builder/runs/' + run.id + '/export',
                'POST',
                { format, reason, section: csvSection },
            );
            downloadBlob(
                await response.blob(),
                'report-' + run.id + '.' + format,
            );
            setNotice('The authorised report has been downloaded.');
        });
    const importFile = (file?: File) =>
        perform(async () => {
            if (!file) return;
            if (file.size > 65536)
                throw new Error('Definition files must be smaller than 64 KB.');
            const valid = await validate(
                parseDefinitionFile(await file.text()),
            );
            if (!sources[valid.source])
                throw new Error(
                    'Open this definition in its authorised reporting area.',
                );
            load(valid);
            setNotice(
                'Definition validated and imported. Save it to create a version.',
            );
        });
    const running = run !== null && ['queued', 'running'].includes(run.status);
    const visibleSaved = saved.filter((r) =>
        (r.name + ' ' + (r.folder ?? ''))
            .toLowerCase()
            .includes(query.toLowerCase()),
    );
    const shownRows =
        (view === 'rows' ? payload?.result.rows : payload?.result.groups) ?? [];

    const openStarter = (sourceKey: string, name?: string, focus?: string) => {
        const next = initialDefinition(sourceKey, sources, name);
        load(
            {
                ...next,
                date_from: definition.date_from,
                date_to: definition.date_to,
                site_ids: definition.site_ids,
                resource_ids: definition.resource_ids,
                subject_id:
                    definition.subject_id ?? props.initialSubject ?? null,
            },
            null,
            focus ?? 'builder',
        );
    };
    useEffect(() => {
        generateRef.current = generate;
    });
    useEffect(() => {
        if (!['library', 'saved'].includes(tab)) return;
        const controller = new AbortController();
        setRunsLoading(true);
        setRunsError('');
        api('/report-builder/runs', 'GET', undefined, controller.signal)
            .then((response) => response.json())
            .then((data) => {
                if (!controller.signal.aborted)
                    setRecent(
                        data.runs.filter((item: { definition: Definition }) =>
                            Boolean(sources[item.definition.source]),
                        ),
                    );
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setRunsError(
                        'Recent runs could not be loaded. Try Refresh runs.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setRunsLoading(false);
            });
        return () => controller.abort();
    }, [tab, sources, runsRefresh]);
    const previewKey = definitionKey(definition) + '|' + reason.trim();
    useEffect(() => {
        if (
            tab !== 'builder' ||
            !livePreview ||
            running ||
            busy ||
            reason.trim().length < 3 ||
            requestedPreview.current === previewKey
        )
            return;
        const timer = setTimeout(() => {
            requestedPreview.current = previewKey;
            generateRef.current();
        }, 3500);
        return () => clearTimeout(timer);
    }, [tab, livePreview, running, busy, reason, previewKey]);
    return (
        <div className="report-workspace space-y-5">
            <Head
                title={
                    focusedView?.title ??
                    (tab === 'builder'
                        ? 'Report builder'
                        : tab === 'saved'
                          ? 'Saved reports'
                          : 'Reports')
                }
            />
            <ReportDialog
                open={scopeOpen}
                onClose={() => setScopeOpen(false)}
                title="Report scope"
                description="This scope carries into the report you open. Source permissions still apply."
                footer={
                    <Button onClick={() => setScopeOpen(false)}>Done</Button>
                }
            >
                <ReportRange definition={definition} onChange={change} />
                {siteOptions.length > 0 && (
                    <div className="space-y-2">
                        <Label>Approved sites</Label>
                        {siteOptions.map((site) => (
                            <Toggle
                                key={site.id}
                                label={site.label}
                                checked={definition.site_ids.includes(site.id)}
                                onChange={(checked) =>
                                    change({
                                        site_ids: checked
                                            ? [...definition.site_ids, site.id]
                                            : definition.site_ids.filter(
                                                  (id) => id !== site.id,
                                              ),
                                    })
                                }
                            />
                        ))}
                        <Button
                            variant="outline"
                            onClick={() => change({ site_ids: [] })}
                        >
                            Use all permitted sites
                        </Button>
                    </div>
                )}
                <p className="text-subtle">
                    Resource and person selection is available in each report's
                    Data panel. The selected source determines which records you
                    can use.
                </p>
            </ReportDialog>
            <ReportDialog
                open={saveOpen}
                onClose={() => setSaveOpen(false)}
                title="Save report design"
                description="Save a reusable definition. Generated results remain private."
                error={error}
                footer={
                    <>
                        <Button
                            variant="outline"
                            onClick={() => setSaveOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={save}>
                            Save{' '}
                            {selected
                                ? 'version ' + (selected.version + 1)
                                : 'report'}
                        </Button>
                    </>
                }
            >
                <Label htmlFor="save-report-name">Name</Label>
                <Input
                    id="save-report-name"
                    value={definition.name}
                    maxLength={120}
                    onChange={(e) => change({ name: e.target.value })}
                />
                <Label htmlFor="save-report-folder">Folder</Label>
                <Input
                    id="save-report-folder"
                    value={folder}
                    maxLength={80}
                    onChange={(e) => setFolder(e.target.value)}
                />
                <Toggle
                    label="Favourite"
                    checked={favourite}
                    onChange={setFavourite}
                />
            </ReportDialog>
            <ReportDialog
                open={historyOpen}
                onClose={() => setHistoryOpen(false)}
                title="Version history"
                description="Restore a version into your draft. Save to create a new version."
                error={error}
            >
                {!selected ? (
                    <p>Save this report to start its version history.</p>
                ) : versions.length === 0 ? (
                    <p>Loading versions…</p>
                ) : (
                    versions.map((v) => (
                        <div
                            className="flex items-center justify-between gap-3 rounded-lg border p-3"
                            key={v.version}
                        >
                            <span>
                                Version {v.version} · {v.definition.name}
                            </span>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    change(v.definition);
                                    setHistoryOpen(false);
                                    setNotice(
                                        'Restored version ' +
                                            v.version +
                                            ' into the draft. Save to create a new version.',
                                    );
                                }}
                            >
                                Restore to draft
                            </Button>
                        </div>
                    ))
                )}
            </ReportDialog>
            <ReportDialog
                open={exportOpen}
                onClose={() => setExportOpen(false)}
                title="Export report"
                description="Download the permitted result with its scope and evidence."
                error={error}
            >
                {payload && runDefinition ? (
                    <>
                        <h3 className="text-section-title">
                            {runDefinition.name}
                        </h3>
                        <p className="text-subtle">
                            {reportDate(runDefinition.date_from)} –{' '}
                            {reportDate(runDefinition.date_to)} ·
                            Pacific/Auckland · {payload.result.row_count} source
                            rows
                        </p>
                        <p className="text-subtle">{payload.source.coverage}</p>
                        {dirtyResult && (
                            <p role="status" className="text-status-warning">
                                Your design has changed. Run the current preview
                                before downloading.
                            </p>
                        )}
                        <div className="report-export-controls">
                            <Choice
                                label="Download format"
                                value={format}
                                options={(
                                    props.exportFormats ?? [
                                        'xlsx',
                                        'pdf',
                                        'csv',
                                        'json',
                                    ]
                                ).map((v) => ({
                                    value: v,
                                    label: v.toUpperCase(),
                                }))}
                                onChange={setFormat}
                            />
                            {format === 'csv' && (
                                <Choice
                                    label="CSV contents"
                                    value={csvSection}
                                    options={[
                                        {
                                            value: 'summary',
                                            label: 'Grouped measures',
                                        },
                                        {
                                            value: 'rows',
                                            label: 'Source evidence',
                                        },
                                    ]}
                                    onChange={setCsvSection}
                                />
                            )}
                            <Button
                                onClick={exportResult}
                                disabled={
                                    busy ||
                                    dirtyResult ||
                                    props.canExport === false
                                }
                            >
                                <Download className="size-4" />
                                Download
                            </Button>
                        </div>
                    </>
                ) : (
                    <p>Run a preview before exporting this report.</p>
                )}
            </ReportDialog>

            <input
                className="hidden"
                ref={importInput}
                type="file"
                accept=".json,application/json"
                onChange={(e) => {
                    void importFile(e.target.files?.[0]);
                    e.target.value = '';
                }}
            />
            <PageHeader
                icon={
                    focusedView?.icon ??
                    (tab === 'builder'
                        ? SlidersHorizontal
                        : tab === 'saved'
                          ? FileText
                          : BarChart3)
                }
                title={
                    tab === 'builder'
                        ? focusView
                            ? (fleetViews.find((v) => v.key === focusView)
                                  ?.title ?? 'Report builder')
                            : 'Report builder'
                        : tab === 'saved'
                          ? 'Saved reports'
                          : domain === 'fleet'
                            ? 'Reports'
                            : domain === 'client'
                              ? 'Client tracker reports'
                              : domain === 'staff'
                                ? 'Staff safety reports'
                                : domain === 'medication'
                                  ? 'Medication reports'
                                  : 'My safety history'
                }
                subline={
                    focusedView?.note ??
                    (tab === 'builder'
                        ? 'Design, explore and save a report'
                        : tab === 'saved'
                          ? 'Saved designs, versions and private scheduled runs'
                          : 'Trusted reports and a flexible report studio')
                }
                actions={
                    <>
                        {domain === 'medication' ? (
                            <PageHeaderGlassButton
                                icon={ArrowLeft}
                                onClick={() => router.visit('/emar/reports')}
                            >
                                Reports & audit
                            </PageHeaderGlassButton>
                        ) : (
                            <FleetPageMenu />
                        )}
                        {focusedView ? (
                            <>
                                <PageHeaderGlassButton
                                    icon={ArrowLeft}
                                    onClick={() => navigateView('library')}
                                >
                                    Report library
                                </PageHeaderGlassButton>
                                <PageHeaderGlassButton
                                    icon={SlidersHorizontal}
                                    onClick={() => navigateView('builder')}
                                >
                                    Customise
                                </PageHeaderGlassButton>
                                <PageHeaderPrimaryButton
                                    icon={Download}
                                    disabled={
                                        !payload ||
                                        dirtyResult ||
                                        busy ||
                                        running ||
                                        props.canExport === false
                                    }
                                    onClick={() => setExportOpen(true)}
                                >
                                    Export
                                </PageHeaderPrimaryButton>
                            </>
                        ) : tab === 'builder' ? (
                            <PageHeaderGlassButton
                                icon={ArrowLeft}
                                onClick={() => navigateView('library')}
                            >
                                Report library
                            </PageHeaderGlassButton>
                        ) : (
                            <>
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder="Find a saved report…"
                                    ariaLabel="Search saved reports"
                                />
                                <PageHeaderPrimaryButton
                                    icon={SlidersHorizontal}
                                    onClick={() =>
                                        load(
                                            initialDefinition(
                                                Object.keys(sources)[0],
                                                sources,
                                            ),
                                        )
                                    }
                                >
                                    Create report
                                </PageHeaderPrimaryButton>
                            </>
                        )}
                    </>
                }
                meters={
                    focusedView ? (
                        <div className="report-library-meters">
                            {definition.measures.slice(0, 4).map((measure) => (
                                <PageHeaderMeterBlock
                                    key={measure.id}
                                    label={measure.label}
                                    onClick={() => {
                                        setView('rows');
                                        setPage(0);
                                        document
                                            .getElementById('report-preview')
                                            ?.scrollIntoView({
                                                block: 'start',
                                            });
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {payload && !dirtyResult
                                            ? displayNumber(
                                                  payload.result.totals[
                                                      measure.id
                                                  ],
                                                  measure.decimals,
                                              )
                                            : '—'}
                                        {payload &&
                                        !dirtyResult &&
                                        !['count', 'number'].includes(
                                            measure.unit,
                                        )
                                            ? ` ${measure.unit}`
                                            : ''}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {payload && !dirtyResult
                                            ? `${measure.operation} · ${payload.result.row_count} permitted source rows`
                                            : 'Run this scope for figures'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </div>
                    ) : tab !== 'builder' && domain === 'fleet' ? (
                        <div className="report-library-meters">
                            {fleetViews
                                .filter((v) => sources[v.source])
                                .map((v) => (
                                    <PageHeaderMeterBlock
                                        key={v.key}
                                        label={v.title}
                                        onClick={() =>
                                            openStarter(
                                                v.source,
                                                v.title,
                                                v.key,
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {payload &&
                                            runDefinition?.source ===
                                                v.source &&
                                            !dirtyResult
                                                ? displayNumber(
                                                      payload.result.totals[
                                                          runDefinition
                                                              .measures[0].id
                                                      ],
                                                      runDefinition.measures[0]
                                                          .decimals,
                                                  )
                                                : '—'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {payload &&
                                            runDefinition?.source ===
                                                v.source &&
                                            !dirtyResult
                                                ? `${runDefinition.measures[0].label} · last private result`
                                                : 'Open and run for scoped figures'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ))}
                        </div>
                    ) : undefined
                }
                filters={
                    tab !== 'builder' || focusedView ? (
                        <>
                            <span className="text-xs text-primary-foreground/80">
                                Pacific/Auckland
                            </span>
                            <div className="ml-auto flex flex-wrap gap-2">
                                <PageHeaderFilterButton
                                    icon={CalendarDays}
                                    onClick={() => setScopeOpen(true)}
                                >
                                    {reportDate(definition.date_from)} –{' '}
                                    {reportDate(definition.date_to)}
                                </PageHeaderFilterButton>
                                <PageHeaderFilterButton
                                    icon={ShieldCheck}
                                    onClick={() => setScopeOpen(true)}
                                >
                                    {definition.site_ids.length
                                        ? definition.site_ids.length +
                                          ' selected sites'
                                        : 'All permitted sites'}
                                </PageHeaderFilterButton>
                                <PageHeaderFilterButton
                                    icon={SlidersHorizontal}
                                    onClick={() => setScopeOpen(true)}
                                >
                                    More filters
                                </PageHeaderFilterButton>
                            </div>
                        </>
                    ) : undefined
                }
                rail={
                    <PageHeaderRail
                        items={[
                            {
                                key: 'library',
                                label: 'Report library',
                                icon: BookOpen,
                            },
                            {
                                key: 'builder',
                                label: 'Report builder',
                                icon: SlidersHorizontal,
                            },
                            {
                                key: 'saved',
                                label: 'Saved reports',
                                icon: FileText,
                            },
                            ...(domain === 'fleet'
                                ? fleetViews
                                      .filter((v) => sources[v.source])
                                      .map((v) => ({
                                          key: v.key,
                                          label: v.label,
                                          icon: v.icon,
                                      }))
                                : []),
                        ]}
                        value={focusView ?? tab}
                        onSelect={(value) => {
                            const focused = fleetViews.find(
                                (v) => v.key === value,
                            );
                            if (focused)
                                openStarter(
                                    focused.source,
                                    focused.title,
                                    focused.key,
                                );
                            else {
                                navigateView(value);
                            }
                        }}
                    />
                }
            />
            <p className="report-data-note">
                Private reports · Pacific/Auckland · Source access checked on
                each run
            </p>
            {error && (
                <div
                    role="alert"
                    className="rounded-lg border border-status-critical bg-status-critical-bg p-4 text-status-critical-foreground"
                >
                    {error}
                </div>
            )}
            {notice && (
                <div
                    role="status"
                    className="rounded-lg border bg-muted p-3 text-sm"
                >
                    {notice}
                </div>
            )}
            {tab !== 'builder' ? (
                <>
                    {tab === 'library' && (
                        <StudioLibrary
                            domain={domain}
                            sources={sources}
                            templates={templates}
                            onStart={openStarter}
                            onCreate={() =>
                                load(
                                    initialDefinition(
                                        Object.keys(sources)[0],
                                        sources,
                                    ),
                                )
                            }
                            onImport={() => importInput.current?.click()}
                        />
                    )}
                    <div className="flex justify-end">
                        <Button
                            variant="outline"
                            onClick={restoreDraft}
                            disabled={!hasDraft}
                        >
                            <RotateCcw className="size-4" />
                            Recover draft
                        </Button>
                    </div>
                    <Card className="space-y-3 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-section-title">
                                Recent and scheduled runs
                            </h2>
                            <Button
                                variant="outline"
                                disabled={runsLoading}
                                onClick={() =>
                                    setRunsRefresh((value) => value + 1)
                                }
                            >
                                Refresh runs
                            </Button>
                        </div>
                        {runsLoading && (
                            <p role="status">Loading recent runs…</p>
                        )}
                        {runsError && <p role="alert">{runsError}</p>}
                        {!runsLoading && !runsError && recent.length === 0 && (
                            <p className="text-subtle">
                                No recent runs in this reporting area. Run a
                                report to create a private result.
                            </p>
                        )}
                        {recent.map((r) => (
                            <div
                                className="flex flex-wrap items-center gap-3 border-t pt-3"
                                key={r.id}
                            >
                                <span className="flex-1">
                                    {r.definition.name} · {r.status} ·{' '}
                                    {new Date(r.created_at).toLocaleString(
                                        'en-NZ',
                                    )}
                                </span>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        perform(async () => {
                                            load(r.definition);
                                            if (r.status === 'ready') {
                                                const data = await (
                                                    await api(
                                                        '/report-builder/runs/' +
                                                            r.id,
                                                    )
                                                ).json();
                                                setRun({
                                                    id: r.id,
                                                    status: data.status,
                                                });
                                                setRunDefinition(r.definition);
                                                setPayload(data.payload);
                                            } else
                                                setNotice(
                                                    'Run this definition to retry.',
                                                );
                                        })
                                    }
                                >
                                    {r.status === 'ready'
                                        ? 'View result'
                                        : 'Open definition'}
                                </Button>
                            </div>
                        ))}
                        <p className="text-caption text-muted-foreground">
                            Private results expire after 24 hours, or sooner
                            when access or retention changes.
                        </p>
                    </Card>
                    {(props.shared ?? []).length > 0 && (
                        <Card className="space-y-3 p-5">
                            <h2 className="text-section-title">
                                Definitions shared with you
                            </h2>
                            <p className="text-subtle">
                                Choose your own scope. Results and access remain
                                private to your account.
                            </p>
                            {props.shared!.map((r) => (
                                <Button
                                    key={r.id}
                                    variant="outline"
                                    onClick={() =>
                                        load({
                                            ...r.definition,
                                            name: r.name + ' copy',
                                        })
                                    }
                                >
                                    {r.name} · Use a copy
                                </Button>
                            ))}
                        </Card>
                    )}
                    <h2 className="text-section-title">Saved reports</h2>
                    {visibleSaved.length === 0 ? (
                        <EmptyState
                            icon={FileText}
                            title={
                                query
                                    ? 'No reports match your search'
                                    : 'No saved reports yet'
                            }
                            description={
                                query
                                    ? 'Try another report name or folder.'
                                    : 'Create a report or choose a starting point, then save its design here.'
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={visibleSaved}
                            rowKey={(r) => r.id}
                            identityLabel="Report"
                            identity={(r) => ({
                                icon: BarChart3,
                                name: r.name,
                                subline: sources[r.source]?.label,
                            })}
                            columns={[
                                {
                                    key: 'version',
                                    label: 'Version',
                                    width: '90px',
                                    cell: (r) => r.version,
                                },
                                {
                                    key: 'folder',
                                    label: 'Folder',
                                    width: '1fr',
                                    cell: (r) => r.folder ?? 'Unfiled',
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1fr',
                                    cell: (r) =>
                                        r.archived_at
                                            ? 'Archived'
                                            : r.favourite
                                              ? 'Favourite'
                                              : 'Saved',
                                },
                            ]}
                            onOpen={(r) => load(r.definition, r)}
                            actionsFor={(r) => [
                                {
                                    label: 'Open',
                                    onClick: () => load(r.definition, r),
                                },
                                {
                                    label: 'Duplicate',
                                    icon: Copy,
                                    onClick: () =>
                                        load({
                                            ...r.definition,
                                            name: r.name + ' copy',
                                        }),
                                },
                                {
                                    label: r.archived_at
                                        ? 'Restore'
                                        : 'Archive',
                                    icon: Trash2,
                                    onClick: () => archive(r),
                                },
                            ]}
                        />
                    )}
                </>
            ) : (
                <>
                    <div className="report-design-toolbar">
                        <BarChart3 className="size-6 shrink-0 text-primary" />
                        <div className="report-design-identity">
                            <Input
                                aria-label="Report name"
                                className="report-name"
                                value={definition.name}
                                maxLength={120}
                                onChange={(e) =>
                                    change({ name: e.target.value })
                                }
                            />
                            <p className="text-caption text-muted-foreground">
                                {draftStored && activeView === 'builder'
                                    ? 'Draft saved in this tab'
                                    : 'Report design'}{' '}
                                ·{' '}
                                {selected
                                    ? 'Version ' + selected.version
                                    : 'Unsaved report'}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            disabled={!history.length}
                            onClick={() => {
                                setFuture([definition, ...future]);
                                setDefinition(history[history.length - 1]);
                                setHistory(history.slice(0, -1));
                            }}
                        >
                            Undo
                        </Button>
                        <Button
                            variant="outline"
                            disabled={!future.length}
                            onClick={() => {
                                setHistory([...history, definition]);
                                setDefinition(future[0]);
                                setFuture(future.slice(1));
                            }}
                        >
                            Redo
                        </Button>

                        <Button
                            variant="outline"
                            onClick={() => {
                                setHistoryOpen(true);
                                if (selected)
                                    void perform(async () =>
                                        setVersions(
                                            (
                                                await (
                                                    await api(
                                                        '/report-builder/reports/' +
                                                            selected.id +
                                                            '/versions',
                                                    )
                                                ).json()
                                            ).versions,
                                        ),
                                    );
                            }}
                        >
                            <History className="size-4" />
                            History
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setExportOpen((v) => !v);
                            }}
                        >
                            <Download className="size-4" />
                            Export
                        </Button>
                        <Button
                            variant="default"
                            onClick={() => setSaveOpen(true)}
                            disabled={busy}
                        >
                            <Save className="size-4" />
                            Save{selected ? ' v' + (selected.version + 1) : ''}
                        </Button>
                        {focusView && (
                            <Button
                                variant="outline"
                                onClick={() => navigateView('builder')}
                            >
                                <SlidersHorizontal className="size-4" />
                                Customise
                            </Button>
                        )}
                        {running && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    perform(async () => {
                                        await api(
                                            '/report-builder/runs/' +
                                                run.id +
                                                '/cancel',
                                            'POST',
                                        );
                                        setRun({ ...run, status: 'cancelled' });
                                        setPayload(null);
                                        setLivePreview(false);
                                        setNotice(
                                            'Generation cancelled. Live preview paused.',
                                        );
                                    })
                                }
                            >
                                Cancel generation
                            </Button>
                        )}
                    </div>
                    <div
                        className={
                            'report-studio' +
                            (focusView ? ' report-focused' : '')
                        }
                    >
                        <aside className="report-editor">
                            <nav
                                aria-label="Builder steps"
                                className="report-step-grid"
                            >
                                {steps.map((label, index) => {
                                    const Icon = stepIcons[index];
                                    return (
                                        <Button
                                            key={label}
                                            variant={
                                                step === index
                                                    ? 'secondary'
                                                    : 'ghost'
                                            }
                                            aria-current={
                                                step === index
                                                    ? 'step'
                                                    : undefined
                                            }
                                            onClick={() => setStep(index)}
                                        >
                                            <Icon className="size-4" />
                                            <span>{label}</span>
                                        </Button>
                                    );
                                })}
                            </nav>
                            <div className="report-editor-body space-y-5">
                                <div>
                                    <p className="text-caption text-muted-foreground">
                                        Step {step + 1} of 6
                                    </p>
                                    <h2 className="text-section-title">
                                        {steps[step]}
                                    </h2>
                                </div>
                                {step === 0 && (
                                    <>
                                        <Choice
                                            label="Report source"
                                            value={definition.source}
                                            options={Object.entries(
                                                sources,
                                            ).map(([value, s]) => ({
                                                value,
                                                label: s.label,
                                            }))}
                                            onChange={(value) => {
                                                change(
                                                    initialDefinition(
                                                        value,
                                                        sources,
                                                    ),
                                                );
                                                setTargets([]);
                                            }}
                                        />
                                        <p className="rounded-lg bg-muted p-4 text-sm">
                                            {source.note}
                                        </p>
                                        <ReportRange
                                            definition={definition}
                                            onChange={change}
                                        />
                                        {domain !== 'self' && (
                                            <>
                                                <Label htmlFor="target-search">
                                                    {domain === 'fleet'
                                                        ? 'Find a resource'
                                                        : 'Find an authorised ' +
                                                          (domain ===
                                                          'medication'
                                                              ? 'person'
                                                              : domain ===
                                                                  'client'
                                                                ? 'client'
                                                                : 'session')}
                                                </Label>
                                                <div className="flex items-center gap-2">
                                                    <Search className="size-4" />
                                                    <Input
                                                        id="target-search"
                                                        value={targetQuery}
                                                        onChange={(e) =>
                                                            setTargetQuery(
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="Type to search"
                                                    />
                                                </div>
                                                {domain === 'fleet' ? (
                                                    <div className="space-y-3">
                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                            <Label>
                                                                Resources ·{' '}
                                                                {definition
                                                                    .resource_ids
                                                                    .length
                                                                    ? definition
                                                                          .resource_ids
                                                                          .length +
                                                                      ' selected'
                                                                    : 'all authorised'}
                                                            </Label>
                                                            <Button
                                                                variant="outline"
                                                                onClick={() =>
                                                                    change({
                                                                        resource_ids:
                                                                            [],
                                                                    })
                                                                }
                                                            >
                                                                Use all
                                                                authorised
                                                                resources
                                                            </Button>
                                                        </div>
                                                        <div className="max-h-48 overflow-auto rounded-lg border px-3">
                                                            {targets.map(
                                                                (target) => (
                                                                    <Toggle
                                                                        key={
                                                                            target.id
                                                                        }
                                                                        label={
                                                                            target.label
                                                                        }
                                                                        checked={definition.resource_ids.includes(
                                                                            target.id,
                                                                        )}
                                                                        onChange={(
                                                                            checked,
                                                                        ) =>
                                                                            change(
                                                                                {
                                                                                    resource_ids:
                                                                                        checked
                                                                                            ? [
                                                                                                  ...definition.resource_ids,
                                                                                                  target.id,
                                                                                              ].slice(
                                                                                                  0,
                                                                                                  100,
                                                                                              )
                                                                                            : definition.resource_ids.filter(
                                                                                                  (
                                                                                                      id,
                                                                                                  ) =>
                                                                                                      id !==
                                                                                                      target.id,
                                                                                              ),
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                ),
                                                            )}
                                                            {targets.length ===
                                                                0 && (
                                                                <p className="text-subtle p-3">
                                                                    No matching
                                                                    authorised
                                                                    resources.
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <Choice
                                                        label="Report subject"
                                                        value={String(
                                                            definition.subject_id ??
                                                                '',
                                                        )}
                                                        options={[
                                                            {
                                                                value: '',
                                                                label:
                                                                    domain ===
                                                                    'staff'
                                                                        ? 'All authorised sessions'
                                                                        : 'Choose a client',
                                                            },
                                                            ...targets.map(
                                                                (t) => ({
                                                                    value: String(
                                                                        t.id,
                                                                    ),
                                                                    label: t.label,
                                                                }),
                                                            ),
                                                        ]}
                                                        onChange={(v) =>
                                                            change({
                                                                subject_id: v
                                                                    ? Number(v)
                                                                    : null,
                                                            })
                                                        }
                                                    />
                                                )}{' '}
                                                {(domain === 'fleet' ||
                                                    domain === 'staff') &&
                                                    siteOptions.length > 0 && (
                                                        <div className="space-y-2">
                                                            <Label>
                                                                Sites ·{' '}
                                                                {definition
                                                                    .site_ids
                                                                    .length
                                                                    ? definition
                                                                          .site_ids
                                                                          .length +
                                                                      ' selected'
                                                                    : 'all authorised'}
                                                            </Label>
                                                            <div className="flex max-h-36 flex-wrap gap-x-6 overflow-auto">
                                                                {siteOptions.map(
                                                                    (site) => (
                                                                        <Toggle
                                                                            key={
                                                                                site.id
                                                                            }
                                                                            label={
                                                                                site.label
                                                                            }
                                                                            checked={definition.site_ids.includes(
                                                                                site.id,
                                                                            )}
                                                                            onChange={(
                                                                                checked,
                                                                            ) =>
                                                                                change(
                                                                                    {
                                                                                        site_ids:
                                                                                            checked
                                                                                                ? [
                                                                                                      ...definition.site_ids,
                                                                                                      site.id,
                                                                                                  ].slice(
                                                                                                      0,
                                                                                                      100,
                                                                                                  )
                                                                                                : definition.site_ids.filter(
                                                                                                      (
                                                                                                          id,
                                                                                                      ) =>
                                                                                                          id !==
                                                                                                          site.id,
                                                                                                  ),
                                                                                    },
                                                                                )
                                                                            }
                                                                        />
                                                                    ),
                                                                )}
                                                            </div>
                                                            <p className="text-caption text-muted-foreground">
                                                                Leaving Sites
                                                                unselected uses
                                                                your full
                                                                authorised
                                                                scope.
                                                            </p>
                                                        </div>
                                                    )}
                                            </>
                                        )}
                                        <Toggle
                                            label="Compare with the preceding period"
                                            checked={definition.comparison}
                                            onChange={(comparison) =>
                                                change({ comparison })
                                            }
                                        />
                                    </>
                                )}
                                {step === 1 && (
                                    <ColumnEditor
                                        definition={definition}
                                        source={source}
                                        onChange={change}
                                    />
                                )}
                                {step === 2 && (
                                    <GroupedFilters
                                        definition={definition}
                                        source={source}
                                        onChange={change}
                                    />
                                )}
                                {step === 3 && (
                                    <>
                                        <p className="text-subtle">
                                            Calculations use known source
                                            values. Percentiles use linear
                                            interpolation. Arithmetic formulas
                                            refer to base measure IDs (for
                                            example m2 / m3); a missing or zero
                                            divisor returns unknown.
                                        </p>
                                        {definition.measures.map(
                                            (measure, i) => (
                                                <div
                                                    key={measure.id}
                                                    className="space-y-3 rounded-lg border p-4"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <span className="text-caption font-mono">
                                                            {measure.id}
                                                        </span>
                                                        <Input
                                                            aria-label={
                                                                'Measure name ' +
                                                                measure.id
                                                            }
                                                            value={
                                                                measure.label
                                                            }
                                                            onChange={(e) =>
                                                                change({
                                                                    measures:
                                                                        definition.measures.map(
                                                                            (
                                                                                m,
                                                                                j,
                                                                            ) =>
                                                                                j ===
                                                                                i
                                                                                    ? {
                                                                                          ...m,
                                                                                          label: e
                                                                                              .target
                                                                                              .value,
                                                                                      }
                                                                                    : m,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                        <Button
                                                            variant="ghost"
                                                            disabled={
                                                                definition
                                                                    .measures
                                                                    .length ===
                                                                1
                                                            }
                                                            aria-label={
                                                                'Remove measure ' +
                                                                measure.id
                                                            }
                                                            onClick={() =>
                                                                change({
                                                                    measures:
                                                                        definition.measures.filter(
                                                                            (
                                                                                _,
                                                                                j,
                                                                            ) =>
                                                                                j !==
                                                                                i,
                                                                        ),
                                                                })
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </div>
                                                    <div className="grid gap-3 md:grid-cols-4">
                                                        <Choice
                                                            label="Calculation"
                                                            value={
                                                                measure.operation
                                                            }
                                                            options={operations.map(
                                                                (v) => ({
                                                                    value: v,
                                                                    label: v,
                                                                }),
                                                            )}
                                                            onChange={(v) =>
                                                                change({
                                                                    measures:
                                                                        definition.measures.map(
                                                                            (
                                                                                m,
                                                                                j,
                                                                            ) =>
                                                                                j ===
                                                                                i
                                                                                    ? {
                                                                                          ...m,
                                                                                          operation:
                                                                                              v,
                                                                                          ...(v ===
                                                                                          'formula'
                                                                                              ? {
                                                                                                    where: null,
                                                                                                }
                                                                                              : {}),
                                                                                      }
                                                                                    : m,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                        <Choice
                                                            label="Field"
                                                            value={
                                                                measure.field ??
                                                                ''
                                                            }
                                                            options={[
                                                                {
                                                                    value: '',
                                                                    label: 'All rows',
                                                                },
                                                                ...fields,
                                                            ]}
                                                            onChange={(v) =>
                                                                change({
                                                                    measures:
                                                                        definition.measures.map(
                                                                            (
                                                                                m,
                                                                                j,
                                                                            ) =>
                                                                                j ===
                                                                                i
                                                                                    ? {
                                                                                          ...m,
                                                                                          field:
                                                                                              v ||
                                                                                              null,
                                                                                      }
                                                                                    : m,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                        <Choice
                                                            label="Unit"
                                                            value={measure.unit}
                                                            options={[
                                                                'count',
                                                                'number',
                                                                'percent',
                                                                'NZD',
                                                                'km',
                                                                'hours',
                                                                'minutes',
                                                            ].map((v) => ({
                                                                value: v,
                                                                label: v,
                                                            }))}
                                                            onChange={(v) =>
                                                                change({
                                                                    measures:
                                                                        definition.measures.map(
                                                                            (
                                                                                m,
                                                                                j,
                                                                            ) =>
                                                                                j ===
                                                                                i
                                                                                    ? {
                                                                                          ...m,
                                                                                          unit: v,
                                                                                      }
                                                                                    : m,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                        <div className="space-y-2">
                                                            <Label>
                                                                Decimal places
                                                            </Label>
                                                            <Input
                                                                aria-label={
                                                                    'Decimal places ' +
                                                                    measure.id
                                                                }
                                                                type="number"
                                                                min={0}
                                                                max={6}
                                                                value={
                                                                    measure.decimals
                                                                }
                                                                onChange={(e) =>
                                                                    change({
                                                                        measures:
                                                                            definition.measures.map(
                                                                                (
                                                                                    m,
                                                                                    j,
                                                                                ) =>
                                                                                    j ===
                                                                                    i
                                                                                        ? {
                                                                                              ...m,
                                                                                              decimals:
                                                                                                  Number(
                                                                                                      e
                                                                                                          .target
                                                                                                          .value,
                                                                                                  ),
                                                                                          }
                                                                                        : m,
                                                                            ),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                    {measure.operation !==
                                                        'formula' && (
                                                        <div className="space-y-3">
                                                            <Choice
                                                                label={
                                                                    'Count or calculate only where · ' +
                                                                    measure.id
                                                                }
                                                                value={
                                                                    measure
                                                                        .where
                                                                        ?.field ??
                                                                    ''
                                                                }
                                                                options={[
                                                                    {
                                                                        value: '',
                                                                        label: 'All matching report rows',
                                                                    },
                                                                    ...fields,
                                                                ]}
                                                                onChange={(v) =>
                                                                    change({
                                                                        measures:
                                                                            definition.measures.map(
                                                                                (
                                                                                    m,
                                                                                    j,
                                                                                ) =>
                                                                                    j ===
                                                                                    i
                                                                                        ? {
                                                                                              ...m,
                                                                                              where: v
                                                                                                  ? {
                                                                                                        field: v,
                                                                                                        operator:
                                                                                                            'eq',
                                                                                                        value: '',
                                                                                                    }
                                                                                                  : null,
                                                                                          }
                                                                                        : m,
                                                                            ),
                                                                    })
                                                                }
                                                            />
                                                            {measure.where && (
                                                                <div className="grid gap-3 md:grid-cols-2">
                                                                    <Choice
                                                                        label={
                                                                            'Condition · ' +
                                                                            measure.id
                                                                        }
                                                                        value={
                                                                            measure
                                                                                .where
                                                                                .operator
                                                                        }
                                                                        options={[
                                                                            'eq',
                                                                            'ne',
                                                                            'gt',
                                                                            'gte',
                                                                            'lt',
                                                                            'lte',
                                                                            'contains',
                                                                            'missing',
                                                                            'known',
                                                                        ].map(
                                                                            (
                                                                                v,
                                                                            ) => ({
                                                                                value: v,
                                                                                label: v,
                                                                            }),
                                                                        )}
                                                                        onChange={(
                                                                            v,
                                                                        ) =>
                                                                            change(
                                                                                {
                                                                                    measures:
                                                                                        definition.measures.map(
                                                                                            (
                                                                                                m,
                                                                                                j,
                                                                                            ) =>
                                                                                                j ===
                                                                                                i
                                                                                                    ? {
                                                                                                          ...m,
                                                                                                          where: {
                                                                                                              ...m.where!,
                                                                                                              operator:
                                                                                                                  v,
                                                                                                          },
                                                                                                      }
                                                                                                    : m,
                                                                                        ),
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                    <Input
                                                                        aria-label={
                                                                            'Condition value ' +
                                                                            measure.id
                                                                        }
                                                                        disabled={[
                                                                            'known',
                                                                            'missing',
                                                                        ].includes(
                                                                            measure
                                                                                .where
                                                                                .operator,
                                                                        )}
                                                                        value={
                                                                            measure
                                                                                .where
                                                                                .value ??
                                                                            ''
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            change(
                                                                                {
                                                                                    measures:
                                                                                        definition.measures.map(
                                                                                            (
                                                                                                m,
                                                                                                j,
                                                                                            ) =>
                                                                                                j ===
                                                                                                i
                                                                                                    ? {
                                                                                                          ...m,
                                                                                                          where: {
                                                                                                              ...m.where!,
                                                                                                              value: e
                                                                                                                  .target
                                                                                                                  .value,
                                                                                                          },
                                                                                                      }
                                                                                                    : m,
                                                                                        ),
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                    {measure.operation ===
                                                        'formula' && (
                                                        <Input
                                                            aria-label={
                                                                'Formula ' +
                                                                measure.id
                                                            }
                                                            value={
                                                                measure.formula ??
                                                                ''
                                                            }
                                                            placeholder="m2 / m3"
                                                            onChange={(e) =>
                                                                change({
                                                                    measures:
                                                                        definition.measures.map(
                                                                            (
                                                                                m,
                                                                                j,
                                                                            ) =>
                                                                                j ===
                                                                                i
                                                                                    ? {
                                                                                          ...m,
                                                                                          formula:
                                                                                              e
                                                                                                  .target
                                                                                                  .value,
                                                                                      }
                                                                                    : m,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            ),
                                        )}
                                        <Button
                                            variant="outline"
                                            disabled={
                                                definition.measures.length >= 8
                                            }
                                            onClick={() => {
                                                const id = Array.from(
                                                    { length: 8 },
                                                    (_, i) => 'm' + (i + 1),
                                                ).find(
                                                    (id) =>
                                                        !definition.measures.some(
                                                            (m) => m.id === id,
                                                        ),
                                                )!;
                                                change({
                                                    measures: [
                                                        ...definition.measures,
                                                        {
                                                            id,
                                                            label: 'New measure',
                                                            operation: 'count',
                                                            field: null,
                                                            formula: null,
                                                            decimals: 0,
                                                            unit: 'count',
                                                        },
                                                    ],
                                                });
                                            }}
                                        >
                                            Add measure
                                        </Button>
                                    </>
                                )}
                                {step === 4 && (
                                    <>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <Choice
                                                label="Display"
                                                value={definition.layout}
                                                options={[
                                                    'table',
                                                    'bar',
                                                    'line',
                                                    'donut',
                                                    'summary',
                                                    'pivot',
                                                ].map((v) => ({
                                                    value: v,
                                                    label:
                                                        v === 'line'
                                                            ? 'Time series'
                                                            : v,
                                                }))}
                                                onChange={(v) =>
                                                    change({
                                                        layout: v as Definition['layout'],
                                                        ...(v === 'line'
                                                            ? {
                                                                  groups: [
                                                                      'date',
                                                                  ],
                                                              }
                                                            : v === 'pivot'
                                                              ? {
                                                                    groups: [
                                                                        'date',
                                                                        'resource',
                                                                    ],
                                                                }
                                                              : {}),
                                                    })
                                                }
                                            />
                                            <Choice
                                                label="Group by"
                                                value={
                                                    definition.groups[0] ?? ''
                                                }
                                                options={[
                                                    {
                                                        value: '',
                                                        label: 'Whole report',
                                                    },
                                                    ...fields,
                                                ]}
                                                onChange={(v) =>
                                                    change({
                                                        groups: v ? [v] : [],
                                                    })
                                                }
                                            />
                                            <Choice
                                                label="Second group"
                                                value={
                                                    definition.groups[1] ?? ''
                                                }
                                                options={[
                                                    {
                                                        value: '',
                                                        label: 'None',
                                                    },
                                                    ...fields.filter(
                                                        (f) =>
                                                            f.value !==
                                                            definition
                                                                .groups[0],
                                                    ),
                                                ]}
                                                onChange={(v) =>
                                                    change({
                                                        groups: definition
                                                            .groups[0]
                                                            ? [
                                                                  definition
                                                                      .groups[0],
                                                                  ...(v
                                                                      ? [v]
                                                                      : []),
                                                              ]
                                                            : [],
                                                    })
                                                }
                                            />
                                            <Choice
                                                label="Table sort"
                                                value={definition.sort}
                                                options={[
                                                    {
                                                        value: 'group',
                                                        label: 'Group label',
                                                    },
                                                    {
                                                        value: 'value',
                                                        label: 'First measure',
                                                    },
                                                ]}
                                                onChange={(v) =>
                                                    change({
                                                        sort: v as Definition['sort'],
                                                    })
                                                }
                                            />
                                            <Choice
                                                label="Direction"
                                                value={definition.direction}
                                                options={[
                                                    {
                                                        value: 'asc',
                                                        label: 'Ascending',
                                                    },
                                                    {
                                                        value: 'desc',
                                                        label: 'Descending',
                                                    },
                                                ]}
                                                onChange={(v) =>
                                                    change({
                                                        direction:
                                                            v as Definition['direction'],
                                                    })
                                                }
                                            />
                                            <div className="space-y-2">
                                                <Label>
                                                    Chart groups (up to 500)
                                                </Label>
                                                <Input
                                                    aria-label="Chart groups"
                                                    type="number"
                                                    min={1}
                                                    max={500}
                                                    value={definition.limit}
                                                    onChange={(e) =>
                                                        change({
                                                            limit: Number(
                                                                e.target.value,
                                                            ),
                                                        })
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <Choice
                                            label="Date buckets"
                                            value={
                                                definition.date_bucket ?? 'day'
                                            }
                                            options={[
                                                { value: 'day', label: 'Day' },
                                                {
                                                    value: 'week',
                                                    label: 'Week (Monday start)',
                                                },
                                                {
                                                    value: 'month',
                                                    label: 'Month',
                                                },
                                            ]}
                                            onChange={(v) =>
                                                change({
                                                    date_bucket:
                                                        v as Definition['date_bucket'],
                                                })
                                            }
                                        />
                                        <Choice
                                            label="Source row sort"
                                            value={definition.detail_sort ?? ''}
                                            options={[
                                                {
                                                    value: '',
                                                    label: 'Source order',
                                                },
                                                ...fields,
                                            ]}
                                            onChange={(v) =>
                                                change({
                                                    detail_sort: v || null,
                                                })
                                            }
                                        />
                                        <Choice
                                            label="Source row direction"
                                            value={
                                                definition.detail_direction ??
                                                'asc'
                                            }
                                            options={[
                                                {
                                                    value: 'asc',
                                                    label: 'Ascending',
                                                },
                                                {
                                                    value: 'desc',
                                                    label: 'Descending',
                                                },
                                            ]}
                                            onChange={(v) =>
                                                change({
                                                    detail_direction: v as
                                                        | 'asc'
                                                        | 'desc',
                                                })
                                            }
                                        />
                                        <Toggle
                                            label="Highlight matching measures"
                                            checked={!!definition.highlight}
                                            onChange={(enabled) =>
                                                change({
                                                    highlight: enabled
                                                        ? {
                                                              measure:
                                                                  definition
                                                                      .measures[0]
                                                                      .id,
                                                              operator: 'gt',
                                                              value: 0,
                                                          }
                                                        : null,
                                                })
                                            }
                                        />
                                        {definition.highlight && (
                                            <div className="space-y-3 rounded-lg border p-3">
                                                <Choice
                                                    label="Highlight measure"
                                                    value={
                                                        definition.highlight
                                                            .measure
                                                    }
                                                    options={definition.measures.map(
                                                        (m) => ({
                                                            value: m.id,
                                                            label: m.label,
                                                        }),
                                                    )}
                                                    onChange={(measure) =>
                                                        change({
                                                            highlight: {
                                                                ...definition.highlight!,
                                                                measure,
                                                            },
                                                        })
                                                    }
                                                />
                                                <Choice
                                                    label="Highlight condition"
                                                    value={
                                                        definition.highlight
                                                            .operator
                                                    }
                                                    options={[
                                                        {
                                                            value: 'gt',
                                                            label: 'Greater than',
                                                        },
                                                        {
                                                            value: 'gte',
                                                            label: 'At least',
                                                        },
                                                        {
                                                            value: 'lt',
                                                            label: 'Less than',
                                                        },
                                                        {
                                                            value: 'lte',
                                                            label: 'At most',
                                                        },
                                                    ]}
                                                    onChange={(v) =>
                                                        change({
                                                            highlight: {
                                                                ...definition.highlight!,
                                                                operator:
                                                                    v as 'gt',
                                                            },
                                                        })
                                                    }
                                                />
                                                <Input
                                                    type="number"
                                                    aria-label="Highlight threshold"
                                                    value={
                                                        definition.highlight
                                                            .value
                                                    }
                                                    onChange={(e) =>
                                                        change({
                                                            highlight: {
                                                                ...definition.highlight!,
                                                                value: Number(
                                                                    e.target
                                                                        .value,
                                                                ),
                                                            },
                                                        })
                                                    }
                                                />
                                            </div>
                                        )}
                                        <p className="text-subtle">
                                            Time series always follows calendar
                                            order and displays gaps across the
                                            whole selected period. Table sorting
                                            never changes the time axis.
                                        </p>
                                        <Choice
                                            label="Location precision"
                                            value={definition.precision}
                                            options={[
                                                {
                                                    value: 'redacted',
                                                    label: 'Hide coordinates',
                                                },
                                                {
                                                    value: 'approximate',
                                                    label: 'Approximate (2 decimal places)',
                                                },
                                                {
                                                    value: 'exact',
                                                    label: 'Exact reported coordinates',
                                                },
                                            ]}
                                            onChange={(v) =>
                                                change({
                                                    precision:
                                                        v as Definition['precision'],
                                                })
                                            }
                                        />
                                        <p className="text-caption text-muted-foreground">
                                            Precision applies before filtering
                                            and calculations. Approximate
                                            coordinates are not anonymised.
                                        </p>
                                    </>
                                )}
                                {step === 5 && (
                                    <>
                                        <Label htmlFor="report-purpose">
                                            Purpose for running and exporting
                                            this report
                                        </Label>
                                        <Textarea
                                            id="report-purpose"
                                            value={reason}
                                            maxLength={500}
                                            onChange={(e) =>
                                                setReason(e.target.value)
                                            }
                                            placeholder="For example: review transport planning for the weekly team meeting"
                                        />
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="report-folder">
                                                    Folder
                                                </Label>
                                                <Input
                                                    id="report-folder"
                                                    value={folder}
                                                    onChange={(e) =>
                                                        setFolder(
                                                            e.target.value,
                                                        )
                                                    }
                                                    maxLength={80}
                                                />
                                            </div>
                                            <Toggle
                                                label="Favourite report"
                                                checked={favourite}
                                                onChange={setFavourite}
                                            />
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    downloadBlob(
                                                        new Blob(
                                                            [
                                                                JSON.stringify(
                                                                    {
                                                                        definition,
                                                                    },
                                                                    null,
                                                                    2,
                                                                ),
                                                            ],
                                                            {
                                                                type: 'application/json',
                                                            },
                                                        ),
                                                        'report-definition.json',
                                                    )
                                                }
                                            >
                                                <Download className="size-4" />
                                                Export definition
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    importInput.current?.click()
                                                }
                                            >
                                                <Upload className="size-4" />
                                                Import definition
                                            </Button>

                                            <Button
                                                variant="outline"
                                                disabled={!selected}
                                                onClick={() =>
                                                    perform(async () =>
                                                        setVersions(
                                                            (
                                                                await (
                                                                    await api(
                                                                        '/report-builder/reports/' +
                                                                            selected!
                                                                                .id +
                                                                            '/versions',
                                                                    )
                                                                ).json()
                                                            ).versions,
                                                        ),
                                                    )
                                                }
                                            >
                                                <History className="size-4" />
                                                Version history
                                            </Button>
                                        </div>
                                        {versions.length > 0 && (
                                            <div className="flex flex-wrap gap-2">
                                                {versions.map((v) => (
                                                    <Button
                                                        key={v.version}
                                                        variant="outline"
                                                        onClick={() => {
                                                            change(
                                                                v.definition,
                                                            );
                                                            setNotice(
                                                                'Version ' +
                                                                    v.version +
                                                                    ' restored into the draft. Save to create a new version.',
                                                            );
                                                        }}
                                                    >
                                                        Restore version{' '}
                                                        {v.version}
                                                    </Button>
                                                ))}
                                            </div>
                                        )}
                                        <div className="space-y-3 rounded-lg border p-4">
                                            <h3 className="text-section-title">
                                                Share the definition
                                            </h3>
                                            <p className="text-subtle">
                                                The recipient gets the layout
                                                and measures. Person, resource
                                                and filter selections are
                                                cleared; your results are never
                                                shared. They can save a copy
                                                with their own authorised scope
                                                and schedule.
                                            </p>
                                            <Label htmlFor="recipient-email">
                                                Existing staff account email
                                            </Label>
                                            <Input
                                                id="recipient-email"
                                                type="email"
                                                value={recipientEmail}
                                                onChange={(e) =>
                                                    setRecipientEmail(
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <div className="flex gap-2">
                                                <Button
                                                    variant="outline"
                                                    disabled={!selected || busy}
                                                    onClick={() =>
                                                        perform(async () => {
                                                            await api(
                                                                '/report-builder/reports/' +
                                                                    selected!
                                                                        .id +
                                                                    '/share',
                                                                'POST',
                                                                {
                                                                    email: recipientEmail,
                                                                },
                                                            );
                                                            setNotice(
                                                                'Definition access granted. No external message was sent.',
                                                            );
                                                        })
                                                    }
                                                >
                                                    Share definition
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    disabled={!selected || busy}
                                                    onClick={() =>
                                                        perform(async () => {
                                                            await api(
                                                                '/report-builder/reports/' +
                                                                    selected!
                                                                        .id +
                                                                    '/share',
                                                                'POST',
                                                                {
                                                                    email: recipientEmail,
                                                                    remove: true,
                                                                },
                                                            );
                                                            setNotice(
                                                                'Definition access removed.',
                                                            );
                                                        })
                                                    }
                                                >
                                                    Remove access
                                                </Button>
                                            </div>
                                        </div>
                                        <div className="space-y-3 rounded-lg border p-4">
                                            <h3 className="text-section-title">
                                                Scheduled reports
                                            </h3>
                                            <p className="text-subtle">
                                                Generate a private report for
                                                your account at 7 am Auckland
                                                time. Access is checked for each
                                                run. No external email is sent.
                                            </p>
                                            <Choice
                                                label="Frequency"
                                                value={frequency}
                                                options={[
                                                    'daily',
                                                    'weekly',
                                                    'monthly',
                                                ].map((v) => ({
                                                    value: v,
                                                    label: v,
                                                }))}
                                                onChange={setFrequency}
                                            />
                                            <div className="flex gap-2">
                                                <Button
                                                    variant="outline"
                                                    disabled={!selected || busy}
                                                    onClick={() =>
                                                        perform(async () => {
                                                            await api(
                                                                '/report-builder/reports/' +
                                                                    selected!
                                                                        .id +
                                                                    '/subscription',
                                                                'POST',
                                                                {
                                                                    frequency,
                                                                    active: true,
                                                                    reason,
                                                                },
                                                            );
                                                            setNotice(
                                                                'Schedule enabled. A queue worker and application scheduler must be running.',
                                                            );
                                                        })
                                                    }
                                                >
                                                    Enable schedule
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    disabled={!selected || busy}
                                                    onClick={() =>
                                                        perform(async () => {
                                                            await api(
                                                                '/report-builder/reports/' +
                                                                    selected!
                                                                        .id +
                                                                    '/subscription',
                                                                'POST',
                                                                {
                                                                    frequency,
                                                                    active: false,
                                                                    reason,
                                                                },
                                                            );
                                                            setNotice(
                                                                'Schedule paused.',
                                                            );
                                                        })
                                                    }
                                                >
                                                    Pause schedule
                                                </Button>
                                            </div>
                                        </div>
                                    </>
                                )}
                                <div className="flex justify-between border-t pt-4">
                                    <Button
                                        variant="outline"
                                        disabled={step === 0}
                                        onClick={() => setStep(step - 1)}
                                    >
                                        Back
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={step === 5}
                                        onClick={() => setStep(step + 1)}
                                    >
                                        Next: {steps[step + 1] ?? 'Done'}
                                    </Button>
                                </div>
                            </div>
                        </aside>
                        <section
                            className="report-preview"
                            id="report-preview"
                            aria-label="Live report preview"
                        >
                            <div className="report-preview-toolbar">
                                <span className="text-caption flex items-center gap-2">
                                    <span className="size-1.5 rounded-full bg-primary" />
                                    Report preview
                                </span>
                                <div className="flex flex-wrap items-center gap-3">
                                    <Toggle
                                        label="Live preview"
                                        checked={livePreview}
                                        onChange={(v) => {
                                            setLivePreview(v);
                                            requestedPreview.current = '';
                                        }}
                                    />
                                    <Button
                                        variant="outline"
                                        onClick={generate}
                                        disabled={busy || running}
                                    >
                                        <Play className="size-4" />
                                        {running
                                            ? 'Generating…'
                                            : 'Run preview'}
                                    </Button>
                                </div>
                            </div>
                            <div className="report-preview-body">
                                <p className="text-caption font-semibold tracking-widest uppercase">
                                    {source.label}
                                </p>
                                <h2 className="text-page-title">
                                    {definition.name}
                                </h2>
                                <p className="text-caption flex flex-wrap items-center gap-2 text-muted-foreground">
                                    <CalendarDays className="size-4" />
                                    {reportDate(definition.date_from)} –{' '}
                                    {reportDate(definition.date_to)} ·{' '}
                                    {definition.site_ids.length
                                        ? definition.site_ids.length +
                                          ' selected sites'
                                        : 'All authorised sites'}
                                </p>
                                <details
                                    className="report-purpose-details"
                                    open={!payload}
                                >
                                    <summary className="text-caption cursor-pointer">
                                        Purpose:{' '}
                                        {reason ||
                                            'add a purpose for this report'}
                                    </summary>
                                    <div className="report-purpose">
                                        <Label htmlFor="preview-purpose">
                                            Report purpose
                                        </Label>
                                        <Input
                                            id="preview-purpose"
                                            placeholder="Why you need this report"
                                            value={reason}
                                            maxLength={500}
                                            onChange={(e) =>
                                                setReason(e.target.value)
                                            }
                                        />
                                        <p className="text-caption text-muted-foreground">
                                            {reason.trim().length < 3
                                                ? 'Add a purpose to load authorised results.'
                                                : livePreview
                                                  ? 'Live preview refreshes after you pause editing.'
                                                  : 'Preview paused. Run preview to apply changes.'}
                                        </p>
                                    </div>
                                </details>
                                {!payload && (
                                    <div className="report-preview-empty">
                                        <BarChart3 className="size-8 text-primary" />
                                        <h3 className="text-section-title">
                                            {running
                                                ? 'Preparing your report'
                                                : 'Your report takes shape here'}
                                        </h3>
                                        <p className="text-subtle">
                                            {running
                                                ? 'Checking current access and gathering the selected records.'
                                                : 'Choose your data and add a purpose. Run the preview to see measures, charts and the records behind them.'}
                                        </p>
                                    </div>
                                )}
                                {payload && (
                                    <section
                                        className="space-y-4"
                                        aria-label="Report result"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <h2 className="text-section-title">
                                                    Generated result
                                                </h2>
                                                <p className="text-subtle">
                                                    {payload.result.row_count.toLocaleString()}{' '}
                                                    matching source rows ·{' '}
                                                    {payload.result.group_count.toLocaleString()}{' '}
                                                    groups
                                                </p>
                                            </div>
                                        </div>
                                        {props.canExport === false && (
                                            <p className="text-subtle">
                                                Your account can view this
                                                report. Personal report
                                                downloads require export
                                                permission.
                                            </p>
                                        )}
                                        {dirtyResult && (
                                            <div
                                                role="status"
                                                className="rounded-lg bg-status-warning-bg p-4 text-status-warning-foreground"
                                            >
                                                The definition has changed. Run
                                                it again to refresh these
                                                results and enable download.
                                            </div>
                                        )}
                                        <div className="report-result-metrics">
                                            {runDefinition!.measures.map(
                                                (m) => (
                                                    <Card
                                                        key={m.id}
                                                        className="space-y-2 p-4"
                                                    >
                                                        <p className="text-subtle">
                                                            {m.label}
                                                        </p>
                                                        <p className="text-page-title">
                                                            {displayNumber(
                                                                payload.result
                                                                    .totals[
                                                                    m.id
                                                                ],
                                                                m.decimals,
                                                            )}{' '}
                                                            <span className="text-caption">
                                                                {m.unit}
                                                            </span>
                                                        </p>
                                                        {payload.comparison && (
                                                            <p className="text-caption text-muted-foreground">
                                                                Previous period:{' '}
                                                                {displayNumber(
                                                                    payload
                                                                        .comparison
                                                                        .totals[
                                                                        m.id
                                                                    ],
                                                                    m.decimals,
                                                                )}
                                                            </p>
                                                        )}
                                                    </Card>
                                                ),
                                            )}
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                variant={
                                                    view === 'summary'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                onClick={() => {
                                                    setView('summary');
                                                    setPage(0);
                                                }}
                                            >
                                                Report
                                            </Button>
                                            <Button
                                                variant={
                                                    view === 'rows'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                onClick={() => {
                                                    setView('rows');
                                                    setPage(0);
                                                }}
                                            >
                                                Source evidence
                                            </Button>
                                        </div>
                                        {view === 'summary' &&
                                            ['bar', 'line', 'donut'].includes(
                                                runDefinition!.layout,
                                            ) && (
                                                <ReportChart
                                                    groupCount={
                                                        payload.result
                                                            .group_count
                                                    }
                                                    definition={runDefinition!}
                                                    groups={
                                                        payload.result.chart
                                                    }
                                                />
                                            )}
                                        {runDefinition!.layout === 'pivot' &&
                                            view === 'summary' && (
                                                <PivotTable
                                                    margins={
                                                        payload.result
                                                            .pivot_totals
                                                    }
                                                    totals={
                                                        payload.result.totals
                                                    }
                                                    definition={runDefinition!}
                                                    source={
                                                        sources[
                                                            runDefinition!
                                                                .source
                                                        ]
                                                    }
                                                    groups={
                                                        payload.result.groups
                                                    }
                                                />
                                            )}
                                        {shownRows.length === 0 ? (
                                            <EmptyState
                                                title="No matching source records"
                                                description="No permitted records match this period and scope. Adjust the report filters to check another scope."
                                            />
                                        ) : (
                                            <ResultTable
                                                definition={runDefinition!}
                                                source={
                                                    sources[
                                                        runDefinition!.source
                                                    ]
                                                }
                                                rows={shownRows.slice(
                                                    page * 50,
                                                    page * 50 + 50,
                                                )}
                                                detail={view === 'rows'}
                                            />
                                        )}
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <p className="text-caption text-muted-foreground">
                                                Browser preview: up to{' '}
                                                {payload.preview_limit} rows or
                                                groups. Downloads include the
                                                complete matching result. Blank
                                                values are unknown.
                                            </p>
                                            <div className="flex gap-2">
                                                <Button
                                                    variant="outline"
                                                    disabled={page === 0}
                                                    onClick={() =>
                                                        setPage(page - 1)
                                                    }
                                                >
                                                    Previous
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    disabled={
                                                        (page + 1) * 50 >=
                                                        shownRows.length
                                                    }
                                                    onClick={() =>
                                                        setPage(page + 1)
                                                    }
                                                >
                                                    Next
                                                </Button>
                                            </div>
                                        </div>
                                        <details className="report-provenance">
                                            <summary>
                                                Scope, coverage and missing
                                                values
                                            </summary>
                                            <div className="space-y-3 pt-3">
                                                <div className="flex items-center gap-2">
                                                    <ShieldCheck className="size-5 text-primary" />
                                                    <h3 className="text-section-title">
                                                        Scope and coverage
                                                    </h3>
                                                </div>
                                                <p className="text-sm">
                                                    {payload.source.coverage}
                                                </p>
                                                <p className="text-caption text-muted-foreground">
                                                    {new Date(
                                                        payload.source.window
                                                            .from,
                                                    ).toLocaleString('en-NZ', {
                                                        timeZone:
                                                            'Pacific/Auckland',
                                                    })}{' '}
                                                    to{' '}
                                                    {new Date(
                                                        payload.source.window
                                                            .to,
                                                    ).toLocaleString('en-NZ', {
                                                        timeZone:
                                                            'Pacific/Auckland',
                                                    })}{' '}
                                                    ·{' '}
                                                    {
                                                        payload.source.window
                                                            .timezone
                                                    }
                                                    . Generated{' '}
                                                    {new Date(
                                                        payload.generated_at,
                                                    ).toLocaleString('en-NZ', {
                                                        timeZone:
                                                            'Pacific/Auckland',
                                                    })}
                                                    .
                                                </p>
                                                <p className="text-caption">
                                                    {Object.entries(
                                                        payload.result.missing,
                                                    )
                                                        .filter(
                                                            ([, n]) => n > 0,
                                                        )
                                                        .map(
                                                            ([field, n]) =>
                                                                (sources[
                                                                    runDefinition!
                                                                        .source
                                                                ].fields[field]
                                                                    ?.label ??
                                                                    field) +
                                                                ': ' +
                                                                n +
                                                                ' unknown',
                                                        )
                                                        .join(' · ') ||
                                                        'Selected fields have no missing values in the matching rows.'}
                                                </p>
                                            </div>
                                        </details>{' '}
                                    </section>
                                )}
                            </div>
                        </section>
                    </div>
                </>
            )}
        </div>
    );
}

function ResultTable({
    definition,
    source,
    rows,
    detail,
}: {
    definition: Definition;
    source: Source;
    rows: (Group | Record<string, string | number | null>)[];
    detail: boolean;
}) {
    const records = detail
        ? (rows as Record<string, string | number | null>[])
        : (rows as Group[]).map((r) =>
              Object.fromEntries([
                  ...definition.groups.map((g, i) => [
                      g,
                      r.dimensions[i] ?? 'Unknown',
                  ]),
                  ...definition.measures.map((m) => [m.id, r.values[m.id]]),
              ]),
          );
    const keys = detail
        ? definition.columns
        : [...definition.groups, ...definition.measures.map((m) => m.id)];
    const label = (key: string) =>
        source.fields[key]?.label ??
        definition.measures.find((m) => m.id === key)?.label ??
        key;
    const show = (value: string | number | null | undefined) =>
        value == null
            ? 'Unknown'
            : typeof value === 'number'
              ? displayNumber(value, 3)
              : String(value);
    const cell = (key: string, value: string | number | null | undefined) => {
        const rule = definition.highlight;
        const matched =
            !detail &&
            rule?.measure === key &&
            typeof value === 'number' &&
            {
                gt: value > rule.value,
                gte: value >= rule.value,
                lt: value < rule.value,
                lte: value <= rule.value,
            }[rule.operator];
        return matched ? (
            <span className="report-highlight" title="Matches highlight rule">
                <span aria-label="Matches highlight rule">◆</span>
                {show(value)}
            </span>
        ) : (
            show(value)
        );
    };
    return (
        <EntityTable
            rows={records.map((r, i) => ({ ...r, _key: i }))}
            rowKey={(r) => Number(r._key)}
            identityLabel={label(keys[0])}
            identity={(r) => ({ name: show(r[keys[0]]) })}
            identityWidth="180px"
            columns={keys.slice(1).map((key) => ({
                key,
                label: label(key),
                width: '160px',
                cell: (row: Record<string, string | number | null>) =>
                    cell(key, row[key]),
            }))}
            minWidth={Math.max(600, keys.length * 160)}
            actionsFor={(row) => [
                {
                    label: 'Copy row',
                    icon: Copy,
                    onClick: () => {
                        void navigator.clipboard.writeText(
                            keys
                                .map(
                                    (key) => label(key) + ': ' + show(row[key]),
                                )
                                .join('\n'),
                        );
                    },
                },
            ]}
        />
    );
}
function PivotTable({
    definition,
    source,
    groups,
    margins,
    totals,
}: {
    definition: Definition;
    source: Source;
    groups: Group[];
    margins?: { rows: Group[]; columns: Group[] };
    totals: Record<string, number | null>;
}) {
    const [measureId, setMeasureId] = useState(definition.measures[0].id);
    const measure =
        definition.measures.find((m) => m.id === measureId) ??
        definition.measures[0];
    const rowKeys = [
        ...new Set(groups.map((g) => JSON.stringify(g.dimensions[0] ?? null))),
    ];
    const columnKeys = [
        ...new Set(groups.map((g) => JSON.stringify(g.dimensions[1] ?? null))),
    ];
    const cells = new Map(
        groups.map((g) => [JSON.stringify(g.dimensions), g.values[measure.id]]),
    );
    const rowTotals = new Map(
        margins?.rows.map((g) => [
            JSON.stringify(g.dimensions[0] ?? null),
            g.values[measure.id],
        ]),
    );
    const columnTotals = new Map(
        margins?.columns.map((g) => [
            JSON.stringify(g.dimensions[0] ?? null),
            g.values[measure.id],
        ]),
    );
    const label = (key: string) => JSON.parse(key) ?? 'Unknown';
    return (
        <Card className="space-y-3 p-4">
            <Choice
                label="Pivot measure"
                value={measure.id}
                options={definition.measures.map((m) => ({
                    value: m.id,
                    label: m.label + ' (' + m.unit + ')',
                }))}
                onChange={setMeasureId}
            />
            <p className="text-caption">
                Rows: {source.fields[definition.groups[0]].label} · Columns:{' '}
                {source.fields[definition.groups[1]].label}. A dash means no
                source rows; Unknown means records exist without the measure.
                Totals are recalculated from all matching source rows, including
                averages. Only the available preview groups are shown.
            </p>
            <div
                className="max-h-96 overflow-auto"
                tabIndex={0}
                aria-label="Pivot results"
            >
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th className="border-b p-3 whitespace-nowrap">
                                {source.fields[definition.groups[0]].label}
                            </th>
                            {columnKeys.map((key) => (
                                <th
                                    key={key}
                                    className="border-b p-3 whitespace-nowrap"
                                >
                                    {label(key)}
                                </th>
                            ))}
                            <th className="border-b p-3">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rowKeys.map((row) => (
                            <tr key={row}>
                                <th className="border-b p-3 whitespace-nowrap">
                                    {label(row)}
                                </th>
                                {columnKeys.map((col) => {
                                    const key = JSON.stringify([
                                        JSON.parse(row),
                                        JSON.parse(col),
                                    ]);
                                    return (
                                        <td
                                            key={col}
                                            className="border-b p-3 whitespace-nowrap"
                                        >
                                            {cells.has(key)
                                                ? displayNumber(
                                                      cells.get(key),
                                                      measure.decimals,
                                                  )
                                                : '—'}
                                        </td>
                                    );
                                })}
                                <td className="border-b p-3 font-semibold">
                                    {displayNumber(
                                        rowTotals.get(row),
                                        measure.decimals,
                                    )}
                                </td>
                            </tr>
                        ))}
                        <tr>
                            <th className="p-3">Total</th>
                            {columnKeys.map((col) => (
                                <td key={col} className="p-3 font-semibold">
                                    {displayNumber(
                                        columnTotals.get(col),
                                        measure.decimals,
                                    )}
                                </td>
                            ))}
                            <td className="p-3 font-semibold">
                                {displayNumber(
                                    totals[measure.id],
                                    measure.decimals,
                                )}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    );
}
function ReportChart({
    definition,
    groups,
    groupCount,
}: {
    definition: Definition;
    groups: Group[];
    groupCount: number;
}) {
    const data = groups.map((g) => ({
        name:
            g.dimensions.map((v) => v ?? 'Unknown').join(' · ') ||
            'Whole report',
        timestamp: g.timestamp,
        ...g.values,
    }));
    const first = definition.measures[0];
    if (
        definition.layout === 'donut' &&
        (groups.length < groupCount ||
            data.some((row) => row[first.id] == null || row[first.id]! < 0) ||
            data.reduce((n, row) => n + (row[first.id] ?? 0), 0) <= 0)
    )
        return (
            <Card className="p-4">
                Share of total requires every group, known non-negative values
                and a positive total. Use a bar chart or the report table.
            </Card>
        );
    return (
        <Card className="p-5">
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-section-title">{first.label}</h3>
                <span className="text-caption text-muted-foreground">
                    {first.unit} · {groups.length} of {groupCount} groups
                </span>
            </div>
            <div
                className="h-72 min-w-0"
                role="img"
                aria-label={
                    definition.layout +
                    ' chart. Equivalent values are available in the grouped table.'
                }
            >
                <ResponsiveContainer width="100%" height="100%">
                    {definition.layout === 'line' ? (
                        <LineChart data={data}>
                            <CartesianGrid
                                stroke="var(--border)"
                                vertical={false}
                            />
                            <XAxis
                                dataKey="timestamp"
                                type="number"
                                scale="time"
                                domain={['dataMin', 'dataMax']}
                                tickFormatter={(v) =>
                                    new Date(v).toLocaleDateString('en-NZ', {
                                        timeZone: 'Pacific/Auckland',
                                        day: 'numeric',
                                        month: 'short',
                                    })
                                }
                            />
                            <YAxis />
                            <Tooltip
                                labelFormatter={(v) =>
                                    new Date(Number(v)).toLocaleDateString(
                                        'en-NZ',
                                        { timeZone: 'Pacific/Auckland' },
                                    )
                                }
                            />
                            {definition.measures.slice(0, 1).map((m, i) => (
                                <Line
                                    key={m.id}
                                    name={m.label}
                                    type="linear"
                                    dataKey={m.id}
                                    stroke={
                                        'var(--chart-' + ((i % 5) + 1) + ')'
                                    }
                                    connectNulls={false}
                                    isAnimationActive={false}
                                />
                            ))}
                        </LineChart>
                    ) : definition.layout === 'donut' ? (
                        <PieChart>
                            <Pie
                                data={data}
                                dataKey={first.id}
                                nameKey="name"
                                innerRadius="50%"
                                outerRadius="80%"
                                isAnimationActive={false}
                            >
                                {data.map((_, i) => (
                                    <Cell
                                        key={i}
                                        fill={
                                            'var(--chart-' + ((i % 5) + 1) + ')'
                                        }
                                    />
                                ))}
                            </Pie>
                            <Tooltip />
                        </PieChart>
                    ) : (
                        <BarChart data={data}>
                            <CartesianGrid
                                stroke="var(--border)"
                                vertical={false}
                            />
                            <XAxis dataKey="name" />
                            <YAxis />
                            <Tooltip />
                            {definition.measures.slice(0, 1).map((m, i) => (
                                <Bar
                                    key={m.id}
                                    name={m.label}
                                    dataKey={m.id}
                                    fill={'var(--chart-' + ((i % 5) + 1) + ')'}
                                    isAnimationActive={false}
                                />
                            ))}
                        </BarChart>
                    )}
                </ResponsiveContainer>
            </div>
        </Card>
    );
}
export default function Workspace(props: Props) {
    const base =
        props.domain === 'medication'
            ? '/emar/reports'
            : props.domain === 'fleet'
              ? '/fleet-assets'
              : props.domain === 'self'
                ? '/my-day'
                : '/operations';
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title:
                        props.domain === 'medication'
                            ? 'Medication'
                            : props.domain === 'fleet'
                              ? 'Fleet & Assets'
                              : props.domain === 'self'
                                ? 'My Day'
                                : 'Operations',
                    href: base,
                },
                {
                    title:
                        props.domain === 'medication'
                            ? 'Reports & audit'
                            : 'Reports',
                    href:
                        props.domain === 'medication'
                            ? '/emar/reports'
                            : props.domain === 'fleet'
                              ? '/fleet-assets/reports'
                              : props.domain === 'self'
                                ? '/my-day/safety-reports'
                                : '/operations/people-location-reports/' +
                                  props.domain,
                },
            ]}
        >
            <BuilderBoundary>
                <ReportWorkspace {...props} />
            </BuilderBoundary>
        </AppLayout>
    );
}
