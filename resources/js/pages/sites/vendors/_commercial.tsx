import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Check, FileText, History, Pencil, RefreshCcw } from 'lucide-react';
import { useEffect, useState } from 'react';

export type Choice = { id: number; name: string };
export type Agreement = {
    id: number;
    vendor_id: number;
    site_id: number;
    title: string;
    kind: string;
    reference: string | null;
    owner_user_id: number;
    asset_id: number | null;
    visibility: string;
    starts_on: string | null;
    renews_on: string | null;
    notice_days: number;
    amount: string | null;
    currency: string;
    terms: string | null;
    evidence: string | null;
    status: string;
    lock_version: number;
    vendor_name?: string;
    followup: {
        due_on: string | null;
        status: string;
        reviewed_at: string | null;
    } | null;
};
export async function commercialRequest(
    url: string,
    body: Record<string, unknown> | FormData,
    method = 'POST',
) {
    const multipart = body instanceof FormData;
    const result = await fetch(url, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            ),
            ...(!multipart ? { 'Content-Type': 'application/json' } : {}),
        },
        body: multipart ? body : JSON.stringify(body),
    });
    if (
        result.redirected ||
        !result.headers.get('content-type')?.includes('application/json')
    ) {
        throw new Error(
            'The result could not be confirmed. Sign in again and check the saved record before retrying.',
        );
    }
    if (!result.ok) {
        const data = (await result.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        throw new Error(
            Object.values(data.errors ?? {})
                .flat()
                .join(' ') ||
                (result.status === 401 || result.status === 419
                    ? 'Your session expired. Sign in and reopen this record.'
                    : data.message ||
                      'Could not save. Your input is retained.'),
        );
    }
}

export function AgreementTable({
    agreements,
    open,
}: {
    agreements: Agreement[];
    open: (a: Agreement) => void;
}) {
    const menu = useEntityContextMenu<Agreement>();
    const actions = (a: Agreement): MenuItem[] => [
        { label: 'Open agreement', icon: FileText, onClick: () => open(a) },
    ];
    if (!agreements.length)
        return (
            <EmptyState
                icon={FileText}
                title="No agreements in this view"
                description="Add an agreement to keep its terms, files and renewal follow-up together."
            />
        );
    return (
        <>
            <EntityTable
                rows={agreements}
                rowKey={(a) => a.id}
                identity={(a) => ({
                    icon: FileText,
                    name: a.title,
                    subline: a.vendor_name || a.kind,
                })}
                columns={[
                    {
                        key: 'state',
                        label: 'State',
                        width: '120px',
                        cell: (a) => (
                            <EntityStatusChip
                                variant={
                                    a.status === 'retired'
                                        ? 'neutral'
                                        : 'success'
                                }
                            >
                                {a.status}
                            </EntityStatusChip>
                        ),
                    },
                    {
                        key: 'renewal',
                        label: 'Renews',
                        width: '140px',
                        cell: (a) => (
                            <EntityChip>
                                {formatDateOnly(a.renews_on)}
                            </EntityChip>
                        ),
                    },
                    {
                        key: 'followup',
                        label: 'Owner follow-up',
                        width: '1fr',
                        cell: (a) => (
                            <EntityChip>
                                {a.followup?.status || 'Unscheduled'} ·{' '}
                                {formatDateOnly(a.followup?.due_on)}
                            </EntityChip>
                        ),
                    },
                ]}
                actionsFor={actions}
                onOpen={open}
                onRowContextMenu={menu.open}
                minWidth={750}
            />
            {menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={menu.ctx.record.title}
                    items={actions(menu.ctx.record)}
                    onClose={menu.close}
                />
            )}
        </>
    );
}

export function AgreementEditor({
    vendorId,
    agreement,
    owners,
    assets,
    close,
}: {
    vendorId: number;
    agreement?: Agreement;
    owners: Choice[];
    assets: Choice[];
    close: () => void;
}) {
    const [creationKey] = useState(() => crypto.randomUUID());
    const [data, setData] = useState({
        title: agreement?.title ?? '',
        kind: agreement?.kind ?? 'contract',
        reference: agreement?.reference ?? '',
        owner_user_id: String(agreement?.owner_user_id ?? ''),
        asset_id: String(agreement?.asset_id ?? ''),
        visibility: agreement?.visibility ?? 'site',
        starts_on: agreement?.starts_on ?? '',
        renews_on: agreement?.renews_on ?? '',
        notice_days: agreement?.notice_days ?? 30,
        amount: agreement?.amount ?? '',
        currency: agreement?.currency ?? 'NZD',
        terms: agreement?.terms ?? '',
        evidence: agreement?.evidence ?? '',
    });
    const [initial] = useState(JSON.stringify(data));
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [discard, setDiscard] = useState(false);
    const dirty = JSON.stringify(data) !== initial;
    useEffect(() => {
        const guard = (e: BeforeUnloadEvent) => {
            if (dirty) e.preventDefault();
        };
        window.addEventListener('beforeunload', guard);
        return () => window.removeEventListener('beforeunload', guard);
    }, [dirty]);
    const field = (key: keyof typeof data, label: string, type = 'text') => (
        <div className="space-y-1">
            <Label htmlFor={'agreement-' + key}>{label}</Label>
            <Input
                id={'agreement-' + key}
                type={type}
                value={data[key]}
                onChange={(e) => setData({ ...data, [key]: e.target.value })}
            />
        </div>
    );
    const save = async () => {
        setBusy(true);
        setError('');
        try {
            await commercialRequest(
                agreement
                    ? '/vendor-agreements/' + agreement.id
                    : '/vendors/' + vendorId + '/agreements',
                {
                    ...data,
                    ...(!agreement ? { creation_key: creationKey } : {}),
                    owner_user_id: Number(data.owner_user_id),
                    asset_id: data.asset_id ? Number(data.asset_id) : null,
                    starts_on: data.starts_on || null,
                    renews_on: data.renews_on || null,
                    amount: data.amount || null,
                    lock_version: agreement?.lock_version,
                },
                agreement ? 'PATCH' : 'POST',
            );
            close();
            router.reload();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => !busy && (dirty ? setDiscard(true) : close())}
                title={agreement ? 'Edit agreement' : 'Add agreement'}
                description="Record protected terms and the responsible renewal owner."
                railIcon={FileText}
                railTitle="Commercial agreement"
                railSub="Restricted Finance and Management access"
                steps={[
                    {
                        key: 'identity',
                        label: 'Agreement',
                        blurb: 'Record and owner',
                        icon: FileText,
                    },
                    {
                        key: 'terms',
                        label: 'Dates and terms',
                        blurb: 'Renewal notice and evidence',
                        icon: History,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Confirm the record',
                        icon: Check,
                    },
                ]}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ([data.title.trim(), data.owner_user_id, data.kind].filter(
                        Boolean,
                    ).length /
                        3) *
                        100,
                )}
                maxWidth="min(92vw, 900px)"
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => (dirty ? setDiscard(true) : close())}
                        >
                            Cancel
                        </Button>
                        {step < 2 ? (
                            <Button
                                type="button"
                                onClick={() => setStep(step + 1)}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                disabled={
                                    busy ||
                                    !data.title.trim() ||
                                    !data.owner_user_id
                                }
                                onClick={save}
                            >
                                {busy ? 'Saving…' : 'Save agreement'}
                            </Button>
                        )}
                    </>
                }
            >
                <div className="space-y-5 p-5">
                    {error && (
                        <p role="alert" className="text-status-critical">
                            {error}
                        </p>
                    )}
                    {step === 0 && (
                        <>
                            {field('title', 'Agreement title')}
                            <Label>
                                Coverage type
                                <select
                                    className="select mt-1 w-full"
                                    value={data.kind}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            kind: e.target.value,
                                        })
                                    }
                                >
                                    {[
                                        'contract',
                                        'licence',
                                        'warranty',
                                        'support',
                                        'domain',
                                        'certificate',
                                    ].map((k) => (
                                        <option key={k}>{k}</option>
                                    ))}
                                </select>
                            </Label>
                            {field('reference', 'Agreement reference')}
                            <Label>
                                Responsible owner
                                <select
                                    className="select mt-1 w-full"
                                    value={data.owner_user_id}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            owner_user_id: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">Choose an owner</option>
                                    {owners.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.name}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                            <Label>
                                Canonical asset
                                <select
                                    className="select mt-1 w-full"
                                    value={data.asset_id}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            asset_id: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">No asset link</option>
                                    {assets.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.name}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                            <Label>
                                Visibility
                                <select
                                    className="select mt-1 w-full"
                                    value={data.visibility}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            visibility: e.target.value,
                                        })
                                    }
                                >
                                    <option value="site">This site only</option>
                                    <option value="all_approved_sites">
                                        All approved sites
                                    </option>
                                </select>
                            </Label>
                            <p className="text-subtle">
                                Only permitted Finance and Management users can
                                access this agreement.
                            </p>
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <div className="grid grid-cols-2 gap-5">
                                {field('starts_on', 'Starts on', 'date')}
                                {field(
                                    'renews_on',
                                    'Renews or expires on',
                                    'date',
                                )}
                                {field(
                                    'notice_days',
                                    'Notice period in days',
                                    'number',
                                )}
                                {field('amount', 'Amount, if known', 'number')}
                                {field('currency', 'Currency')}
                            </div>
                            <Label>
                                Commercial terms
                                <Textarea
                                    value={data.terms}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            terms: e.target.value,
                                        })
                                    }
                                />
                            </Label>
                            <Label>
                                Source and evidence
                                <Textarea
                                    value={data.evidence}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            evidence: e.target.value,
                                        })
                                    }
                                />
                            </Label>
                            <p className="text-subtle">
                                Upload Word or PDF files after saving. A single
                                owner follow-up is scheduled from the renewal
                                date and notice period.
                            </p>
                        </>
                    )}
                    {step === 2 && (
                        <dl className="grid grid-cols-2 gap-3">
                            <dt>Agreement</dt>
                            <dd>{data.title || 'Required'}</dd>
                            <dt>Owner</dt>
                            <dd>
                                {owners.find(
                                    (o) => String(o.id) === data.owner_user_id,
                                )?.name || 'Required'}
                            </dd>
                            <dt>Renewal</dt>
                            <dd>{formatDateOnly(data.renews_on)}</dd>
                            <dt>Notice</dt>
                            <dd>{data.notice_days} days</dd>
                            <dt>Terms</dt>
                            <dd className="whitespace-pre-wrap">
                                {data.terms || 'None recorded'}
                            </dd>
                        </dl>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={close}
                title="Discard unsaved agreement?"
                description="Your unsaved changes will be removed."
                confirmText="Discard changes"
            />
        </>
    );
}

type CommercialFile = {
    id: number;
    name: string;
    series_id: string;
    version: number;
    state: string;
    href: string | null;
};
export function AgreementViewer({
    agreement: a,
    canManage,
    close,
    edit,
}: {
    agreement: Agreement;
    canManage: boolean;
    close: () => void;
    edit: () => void;
}) {
    const [files, setFiles] = useState<CommercialFile[]>([]);
    const [events, setEvents] = useState<
        {
            id: number;
            action: string;
            evidence: string | null;
            created_at: string;
            actor_name?: string | null;
        }[]
    >([]);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [evidence, setEvidence] = useState('');
    const [date, setDate] = useState('');
    const [action, setAction] = useState('review');
    const [upload, setUpload] = useState<File | null>(null);
    const [replace, setReplace] = useState('');
    const [loaded, setLoaded] = useState(false);
    const [reload, setReload] = useState(0);
    const [discard, setDiscard] = useState(false);
    const [afterDiscard, setAfterDiscard] = useState<'close' | 'edit'>('close');
    const dirty = Boolean(evidence || date || upload || replace);
    const requestClose = (next: 'close' | 'edit' = 'close') => {
        if (busy) return;
        if (dirty) {
            setAfterDiscard(next);
            setDiscard(true);
        } else if (next === 'edit') edit();
        else close();
    };
    useEffect(() => {
        const leave = (event: BeforeUnloadEvent) => {
            if (dirty) event.preventDefault();
        };
        window.addEventListener('beforeunload', leave);
        return () => window.removeEventListener('beforeunload', leave);
    }, [dirty]);
    useEffect(() => {
        let current = true;
        setLoaded(false);
        fetch('/vendor-agreements/' + a.id + '/files', {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        })
            .then(async (r) => {
                if (!r.ok)
                    throw new Error(
                        'Files could not be loaded. Check your current access and try again.',
                    );
                return r.json();
            })
            .then((d) => {
                if (current) {
                    setFiles(d.files);
                    setEvents(d.events);
                    setLoaded(true);
                }
            })
            .catch((e) => current && setError(e.message));
        return () => {
            current = false;
        };
    }, [a.id, reload]);
    const run = async (
        url: string,
        body: Record<string, unknown> | FormData,
    ) => {
        setBusy(true);
        setError('');
        try {
            await commercialRequest(url, body);
            close();
            router.reload();
        } catch (e) {
            setError((e as Error).message);
            setReload((value) => value + 1);
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog open onOpenChange={(v) => !v && requestClose()}>
            <DialogContent
                style={{ maxWidth: 'min(92vw, 900px)' }}
                className="max-h-[88vh] overflow-y-auto"
            >
                <DialogHeader>
                    <DialogTitle>{a.title}</DialogTitle>
                    <DialogDescription>
                        Restricted commercial record · {a.kind} · {a.status}
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-status-critical">
                        {error}
                    </p>
                )}
                <dl className="grid grid-cols-2 gap-3 text-sm">
                    <dt>Reference</dt>
                    <dd>{a.reference || '—'}</dd>
                    <dt>Renews</dt>
                    <dd>{formatDateOnly(a.renews_on)}</dd>
                    <dt>Notice</dt>
                    <dd>{a.notice_days} days</dd>
                    <dt>Amount</dt>
                    <dd>
                        {a.amount
                            ? a.currency + ' ' + a.amount
                            : 'Not recorded'}
                    </dd>
                    <dt>Terms</dt>
                    <dd className="whitespace-pre-wrap">
                        {a.terms || 'Not recorded'}
                    </dd>
                    <dt>Source evidence</dt>
                    <dd className="whitespace-pre-wrap">
                        {a.evidence || 'Not recorded'}
                    </dd>
                </dl>
                <h2 className="text-section-title">
                    Files and retained versions
                </h2>
                <p className="text-subtle">
                    Open original downloads the file for your browser or
                    Word/PDF viewer. No online preview is available.
                </p>
                {files.length ? (
                    <ul className="space-y-2">
                        {files.map((f) => (
                            <li
                                key={f.id}
                                className="flex items-center justify-between gap-3 rounded-lg border p-3"
                            >
                                <span>
                                    {f.name} · version {f.version} ·{' '}
                                    {f.state.replaceAll('_', ' ')}
                                </span>
                                {f.href && (
                                    <Button asChild variant="outline">
                                        <a href={f.href}>Open original</a>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : !loaded ? (
                    <p role="status">
                        {error
                            ? 'File history is unavailable. Reopen this record to check current access.'
                            : 'Loading file history…'}
                    </p>
                ) : (
                    <EmptyState title="No files uploaded" variant="compact" />
                )}
                {canManage && a.status !== 'retired' && (
                    <div className="space-y-3 rounded-lg border p-4">
                        <Label>
                            Word or PDF file
                            <Input
                                type="file"
                                accept=".doc,.docx,.pdf"
                                onChange={(e) =>
                                    setUpload(e.target.files?.[0] ?? null)
                                }
                            />
                        </Label>
                        <Label>
                            Version
                            <select
                                className="select mt-1 w-full"
                                value={replace}
                                onChange={(e) => setReplace(e.target.value)}
                            >
                                <option value="">
                                    Add a new supporting file
                                </option>
                                {files
                                    .filter(
                                        (f) =>
                                            f.state === 'ready' &&
                                            !files.some(
                                                (other) =>
                                                    other.series_id ===
                                                        f.series_id &&
                                                    other.state === 'ready' &&
                                                    other.version > f.version,
                                            ),
                                    )
                                    .map((f) => (
                                        <option key={f.id} value={f.id}>
                                            Replace {f.name} · version{' '}
                                            {f.version}
                                        </option>
                                    ))}
                            </select>
                        </Label>
                        <Button
                            disabled={!upload || busy}
                            onClick={() => {
                                if (!upload) return;
                                const body = new FormData();
                                body.append('file', upload);
                                body.append(
                                    'lock_version',
                                    String(a.lock_version),
                                );
                                if (replace)
                                    body.append('replace_file_id', replace);
                                void run(
                                    '/vendor-agreements/' + a.id + '/files',
                                    body,
                                );
                            }}
                        >
                            Upload file
                        </Button>
                    </div>
                )}
                {canManage && (
                    <div className="space-y-3 rounded-lg border p-4">
                        <h2 className="text-section-title">
                            Review and renewal
                        </h2>
                        <Label>
                            Action
                            <select
                                className="select mt-1 w-full"
                                value={
                                    a.status === 'retired' ? 'restore' : action
                                }
                                onChange={(e) => setAction(e.target.value)}
                            >
                                {(a.status === 'retired'
                                    ? ['restore']
                                    : ['review', 'renew', 'retire']
                                ).map((k) => (
                                    <option key={k}>{k}</option>
                                ))}
                            </select>
                        </Label>
                        {action === 'renew' && a.status !== 'retired' && (
                            <Label>
                                New renewal date
                                <Input
                                    type="date"
                                    value={date}
                                    onChange={(e) => setDate(e.target.value)}
                                />
                            </Label>
                        )}
                        <Label>
                            Evidence for this action
                            <Textarea
                                value={evidence}
                                onChange={(e) => setEvidence(e.target.value)}
                                placeholder="Record what was reviewed or agreed, with the evidence reference."
                            />
                        </Label>
                        <Button
                            disabled={busy || evidence.trim().length < 10}
                            onClick={() =>
                                void run(
                                    '/vendor-agreements/' +
                                        a.id +
                                        '/transition',
                                    {
                                        action:
                                            a.status === 'retired'
                                                ? 'restore'
                                                : action,
                                        evidence,
                                        renews_on: date || null,
                                        lock_version: a.lock_version,
                                    },
                                )
                            }
                        >
                            <RefreshCcw className="size-4" />
                            Record action
                        </Button>
                    </div>
                )}
                <h2 className="text-section-title">History</h2>
                <ul className="space-y-2">
                    {events.map((e) => (
                        <li key={e.id} className="text-sm">
                            {e.action.replaceAll('_', ' ')} ·{' '}
                            {formatDateTime(e.created_at)}
                            {e.actor_name ? ' · ' + e.actor_name : ''}
                            <p className="text-muted-foreground">
                                {e.evidence}
                            </p>
                        </li>
                    ))}
                </ul>
                <div className="flex justify-end gap-3">
                    {canManage && a.status !== 'retired' && (
                        <Button
                            variant="outline"
                            onClick={() => requestClose('edit')}
                        >
                            <Pencil className="size-4" />
                            Edit agreement
                        </Button>
                    )}
                    <Button variant="outline" onClick={() => requestClose()}>
                        Close
                    </Button>
                </div>
                <ConfirmDialog
                    open={discard}
                    onClose={() => setDiscard(false)}
                    onConfirm={() =>
                        afterDiscard === 'edit' ? edit() : close()
                    }
                    title="Discard unsaved agreement action?"
                    description="Unsaved evidence and file selections will be removed."
                    confirmText="Discard changes"
                />
            </DialogContent>
        </Dialog>
    );
}
