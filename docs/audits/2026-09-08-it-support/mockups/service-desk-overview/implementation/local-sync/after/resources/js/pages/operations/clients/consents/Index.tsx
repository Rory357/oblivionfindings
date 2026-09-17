import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Download,
    FileCheck,
    Layers,
    Pen,
    Plus,
    Shield,
    ShieldCheck,
    Upload,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

const STATUS_STYLES: Record<string, { bg: string; icon: typeof CheckCircle2 }> =
    {
        given: {
            bg: 'bg-status-success-bg text-status-success',
            icon: CheckCircle2,
        },
        refused: {
            bg: 'bg-status-critical-bg text-status-critical',
            icon: XCircle,
        },
        withdrawn: { bg: 'bg-muted text-muted-foreground', icon: XCircle },
        expired: {
            bg: 'bg-status-warning-bg text-status-warning',
            icon: Clock,
        },
        governance_review_required: {
            bg: 'bg-status-warning-bg text-status-warning',
            icon: AlertTriangle,
        },
        informational_acknowledgement: {
            bg: 'bg-status-info-bg text-status-info',
            icon: FileCheck,
        },
    };

const STATUS_META: Record<string, { label: string; variant: StatusVariant }> = {
    given: { label: 'Given', variant: 'success' },
    refused: { label: 'Refused', variant: 'critical' },
    withdrawn: { label: 'Withdrawn', variant: 'neutral' },
    expired: { label: 'Expired', variant: 'warning' },
    governance_review_required: {
        label: 'Governance review',
        variant: 'warning',
    },
    informational_acknowledgement: {
        label: 'Acknowledgement',
        variant: 'info',
    },
};

const CATEGORY_COLORS: Record<string, string> = {
    medical: 'bg-status-critical-bg border-status-critical/30',
    care: 'bg-primary/10 border-primary',
    communication: 'bg-status-info-bg border-status-info/30',
    data_protection: 'bg-primary/10 border-primary',
    safety: 'bg-status-warning-bg border-status-warning/30',
    activities: 'bg-status-success-bg border-status-success/30',
    safeguarding: 'bg-status-warning-bg border-status-warning/30',
    essential: 'bg-status-info-bg border-status-info/30',
};

const METHOD_OPTIONS = [
    { value: 'all', label: 'All methods' },
    { value: 'written', label: 'Written' },
    { value: 'verbal', label: 'Verbal' },
    { value: 'electronic', label: 'Electronic' },
];

type Props = {
    client: { id: number; first_name: string; last_name: string };
    consents: any[];
    stats: {
        total: number;
        active: number;
        expiring_soon: number;
        expired: number;
        withdrawn: number;
    };
    consent_types: any[];
};

type ViewKey = 'all' | 'active' | 'expiring' | 'expired' | 'withdrawn';

type DirectConsentFormData = {
    consent_type_id: string;
    status: string;
    given_method: string;
    given_at: string;
    given_by_relationship: string;
    given_notes: string;
    expires_at: string;
    evidence_type: string;
    refusal_reason: string;
};

export function buildDirectConsentPayload(
    formData: DirectConsentFormData,
    signedDocument: File | null,
) {
    return {
        consent_type_id: formData.consent_type_id,
        status: formData.status,
        given_method: formData.given_method,
        given_at: formData.given_at,
        given_by_relationship: formData.given_by_relationship,
        given_notes: formData.given_notes,
        expires_at: formData.expires_at,
        evidence_type: formData.evidence_type,
        refusal_reason: formData.refusal_reason,
        ...(signedDocument ? { signed_document: signedDocument } : {}),
    };
}

function categoryOf(c: any): string {
    return c.consent_type?.category ?? c.category ?? 'other';
}

export default function ConsentsIndex({
    client,
    consents = [],
    stats = {} as any,
    consent_types = [],
}: Props) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`;
    const hasConsentTypes = consent_types.length > 0;

    const [showRecord, setShowRecord] = useState(false);
    const [showWithdraw, setShowWithdraw] = useState<number | null>(null);
    const [formData, setFormData] = useState({
        consent_type_id: '',
        status: 'given',
        given_method: 'written',
        given_at: new Date().toISOString().split('T')[0],
        given_by_relationship: '',
        given_notes: '',
        expires_at: '',
        evidence_type: '',
        refusal_reason: '',
    });
    const [withdrawReason, setWithdrawReason] = useState('');
    const [consentFile, setConsentFile] = useState<File | null>(null);

    const [view, setView] = useState<ViewKey>('all');
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const [method, setMethod] = useState('all');

    const s = {
        total: stats?.total ?? 0,
        active: stats?.active ?? 0,
        expiring_soon: stats?.expiring_soon ?? 0,
        expired: stats?.expired ?? 0,
        withdrawn: stats?.withdrawn ?? 0,
    };

    const submitConsent = () => {
        const payload = buildDirectConsentPayload(formData, consentFile);
        router.post(`/operations/clients/${client.id}/consents`, payload, {
            forceFormData: !!consentFile,
            preserveScroll: true,
            onSuccess: () => {
                setShowRecord(false);
                setConsentFile(null);
                setFormData({
                    ...formData,
                    consent_type_id: '',
                    given_notes: '',
                    expires_at: '',
                    refusal_reason: '',
                });
            },
        });
    };

    const submitWithdraw = () => {
        if (!showWithdraw) return;
        router.post(
            `/operations/clients/${client.id}/consents/${showWithdraw}/withdraw`,
            { withdrawal_reason: withdrawReason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowWithdraw(null);
                    setWithdrawReason('');
                },
            },
        );
    };

    const categories = useMemo(
        () => Array.from(new Set(consents.map(categoryOf))).sort(),
        [consents],
    );

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        return consents.filter((c) => {
            switch (view) {
                case 'active':
                    if (!c.is_consumable) return false;
                    break;
                case 'expiring':
                    if (!c.is_expiring_soon || c.is_expired) return false;
                    break;
                case 'expired':
                    if (!c.is_expired) return false;
                    break;
                case 'withdrawn':
                    if (c.status !== 'withdrawn') return false;
                    break;
            }
            if (category !== 'all' && categoryOf(c) !== category) return false;
            if (method !== 'all' && c.given_method !== method) return false;
            if (q) {
                const hay = `${
                    c.consent_type?.name ?? c.consent_type_name ?? ''
                } ${categoryOf(c)} ${c.status ?? ''} ${c.given_method ?? ''} ${
                    c.given_notes ?? ''
                }`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [consents, view, search, category, method]);

    const hasNarrowing =
        search.trim() !== '' || category !== 'all' || method !== 'all';

    // Group the narrowed list by category
    const grouped = useMemo(() => {
        const map: Record<string, any[]> = {};
        shown.forEach((c) => {
            const cat = categoryOf(c);
            if (!map[cat]) map[cat] = [];
            map[cat].push(c);
        });
        return map;
    }, [shown]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All consents', icon: Layers, count: s.total },
        {
            key: 'active',
            label: 'Active',
            icon: CheckCircle2,
            count: s.active,
        },
        {
            key: 'expiring',
            label: 'Expiring soon',
            icon: Clock,
            count: s.expiring_soon,
        },
        {
            key: 'expired',
            label: 'Expired',
            icon: AlertTriangle,
            count: s.expired,
            alert: true,
        },
        {
            key: 'withdrawn',
            label: 'Withdrawn',
            icon: XCircle,
            count: s.withdrawn,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All consents';

    const titleChip =
        s.expired > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {s.expired} expired
            </PageHeaderStatusChip>
        ) : s.expiring_soon > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {s.expiring_soon} expiring soon
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                {s.active} active
            </PageHeaderStatusChip>
        );

    const categoryOptions = [
        { value: 'all', label: 'All categories' },
        ...categories.map((c) => ({
            value: c,
            label: c.replace(/_/g, ' ').replace(/^\w/, (m) => m.toUpperCase()),
        })),
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref={`/operations/clients/${client.id}`}
            icon={Shield}
            title={name}
            titleChip={titleChip}
            subline={`Consent management · ${s.total} ${
                s.total === 1 ? 'record' : 'records'
            } · ${consent_types.length} ${
                consent_types.length === 1 ? 'type' : 'types'
            } configured`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search consents…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setShowRecord(true)}
                        disabled={!hasConsentTypes}
                        className="disabled:pointer-events-none disabled:opacity-50"
                    >
                        Record consent
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total consents"
                        ariaLabel="View all consents"
                        onClick={() => setView('all')}
                    >
                        <PageHeaderMeterBig>{s.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {categories.length}{' '}
                            {categories.length === 1
                                ? 'category'
                                : 'categories'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {s.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Active"
                            ariaLabel="View active consents"
                            onClick={() => setView('active')}
                        >
                            <PageHeaderMeterDonut
                                percent={(s.active / s.total) * 100}
                                caption={
                                    <>
                                        {s.active} of {s.total}
                                        <br />
                                        valid
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Expiring soon"
                        tone={s.expiring_soon > 0 ? 'warning' : 'success'}
                        ariaLabel="View consents expiring soon"
                        onClick={() => setView('expiring')}
                    >
                        <PageHeaderMeterBig>
                            {s.expiring_soon}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            renewals coming up
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Expired"
                        tone={s.expired > 0 ? 'critical' : 'success'}
                        ariaLabel="View expired consents"
                        onClick={() => setView('expired')}
                    >
                        <PageHeaderMeterBig>{s.expired}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            need re-consent
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Withdrawn"
                        ariaLabel="View withdrawn consents"
                        onClick={() => setView('withdrawn')}
                    >
                        <PageHeaderMeterBig>{s.withdrawn}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            consent withdrawn
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={FileCheck}
                        label="All categories"
                        value={category}
                        options={categoryOptions}
                        onChange={setCategory}
                    />
                    <PageHeaderFilterSelect
                        icon={Pen}
                        label="All methods"
                        value={method}
                        options={METHOD_OPTIONS}
                        onChange={setMethod}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={setView}
                    ariaLabel="Consent views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/operations/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
                {
                    title: 'Consents',
                    href: `/operations/clients/${client.id}/consents`,
                },
            ]}
        >
            <Head title={`Consents · ${name}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {!hasConsentTypes && (
                        <Card className="border-status-warning/30 bg-status-warning-bg">
                            <CardContent className="flex items-start gap-3 p-4 text-sm text-status-warning">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                                <div>
                                    Consent types have not been configured yet,
                                    so new consent records are temporarily
                                    unavailable on this page.
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <ListCaption
                        title={currentViewLabel}
                        caption={`${shown.length} of ${s.total} ${
                            s.total === 1 ? 'record' : 'records'
                        } shown`}
                    />

                    {shown.length === 0 ? (
                        <EmptyState
                            icon={ShieldCheck}
                            title="No consent records"
                            description={
                                hasNarrowing || view !== 'all'
                                    ? 'Try a different view or clear your filters.'
                                    : `Record the first consent for ${client.first_name}.`
                            }
                            action={
                                hasNarrowing || view !== 'all' ? undefined : (
                                    <Button
                                        size="sm"
                                        onClick={() => setShowRecord(true)}
                                        disabled={!hasConsentTypes}
                                    >
                                        <Plus className="h-3.5 w-3.5" /> Record
                                        consent
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        Object.entries(grouped).map(([cat, items]) => (
                            <div key={cat}>
                                <div className="mb-2 flex items-center gap-2">
                                    <FileCheck className="h-4 w-4 text-primary" />
                                    <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        {cat.replace(/_/g, ' ')}
                                    </span>
                                    <Badge
                                        variant="secondary"
                                        className="text-[10px]"
                                    >
                                        {items.length}
                                    </Badge>
                                </div>
                                <div className="space-y-2">
                                    {items.map((c: any) => {
                                        const displayStatus =
                                            c.decision_state ===
                                                'governance_review_required' ||
                                            c.decision_state ===
                                                'informational_acknowledgement'
                                                ? c.decision_state
                                                : c.is_expired
                                                  ? 'expired'
                                                  : c.status;
                                        const style =
                                            STATUS_STYLES[displayStatus] ??
                                            STATUS_STYLES.given;
                                        const meta = STATUS_META[displayStatus];
                                        const StatusIcon = style.icon;
                                        const catColor =
                                            CATEGORY_COLORS[cat] ??
                                            'bg-muted border-border';
                                        return (
                                            <Card
                                                key={c.id}
                                                className={`overflow-hidden border ${catColor}`}
                                            >
                                                <CardContent className="p-4">
                                                    <div className="flex flex-col items-start gap-3 sm:flex-row sm:justify-between">
                                                        <div className="flex items-start gap-3">
                                                            <div
                                                                className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${style.bg}`}
                                                            >
                                                                <StatusIcon className="h-4 w-4" />
                                                            </div>
                                                            <div>
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="text-sm font-semibold">
                                                                        {c
                                                                            .consent_type
                                                                            ?.name ??
                                                                            c.consent_type_name ??
                                                                            'Consent'}
                                                                    </span>
                                                                    <StatusBadge
                                                                        variant={
                                                                            meta?.variant ??
                                                                            'neutral'
                                                                        }
                                                                    >
                                                                        {meta?.label ??
                                                                            displayStatus.replace(
                                                                                /_/g,
                                                                                ' ',
                                                                            )}
                                                                    </StatusBadge>
                                                                    {c.capacity_assessed && (
                                                                        <Badge className="border-0 bg-primary/10 text-[10px] text-primary">
                                                                            Capacity
                                                                            assessed
                                                                        </Badge>
                                                                    )}
                                                                    {c.is_expiring_soon &&
                                                                        !c.is_expired && (
                                                                            <StatusBadge variant="warning">
                                                                                Expiring
                                                                                soon
                                                                            </StatusBadge>
                                                                        )}
                                                                </div>
                                                                <div className="mt-1 flex flex-wrap gap-3 text-xs text-muted-foreground">
                                                                    {c.given_at && (
                                                                        <span>
                                                                            Given:{' '}
                                                                            {new Date(
                                                                                c.given_at,
                                                                            ).toLocaleDateString(
                                                                                'en-NZ',
                                                                            )}
                                                                        </span>
                                                                    )}
                                                                    {c.given_method && (
                                                                        <span>
                                                                            Method:{' '}
                                                                            {
                                                                                c.given_method
                                                                            }
                                                                        </span>
                                                                    )}
                                                                    {c.expires_at && (
                                                                        <span
                                                                            className={
                                                                                c.is_expired
                                                                                    ? 'font-medium text-status-critical'
                                                                                    : c.is_expiring_soon
                                                                                      ? 'font-medium text-status-warning'
                                                                                      : ''
                                                                            }
                                                                        >
                                                                            Expires:{' '}
                                                                            {new Date(
                                                                                c.expires_at,
                                                                            ).toLocaleDateString(
                                                                                'en-NZ',
                                                                            )}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                {c.conditions && (
                                                                    <p className="mt-1.5 text-xs text-muted-foreground">
                                                                        {typeof c.conditions ===
                                                                        'string'
                                                                            ? c.conditions
                                                                            : JSON.stringify(
                                                                                  c.conditions,
                                                                              )}
                                                                    </p>
                                                                )}
                                                                {c.withdrawal_reason && (
                                                                    <p className="mt-1.5 text-xs text-status-critical">
                                                                        Withdrawn:{' '}
                                                                        {
                                                                            c.withdrawal_reason
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                        <div className="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto sm:justify-end">
                                                            {c.signed_document_download_url && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="h-10 gap-1.5 text-xs sm:h-8"
                                                                    asChild
                                                                >
                                                                    <a
                                                                        href={
                                                                            c.signed_document_download_url
                                                                        }
                                                                    >
                                                                        <Download className="h-3.5 w-3.5" />
                                                                        Signed
                                                                        document
                                                                    </a>
                                                                </Button>
                                                            )}
                                                            {c.status ===
                                                                'given' &&
                                                                !c.is_expired && (
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        className="h-10 border-status-critical/30 text-xs text-status-critical hover:bg-status-critical-bg sm:h-8"
                                                                        onClick={() => {
                                                                            setShowWithdraw(
                                                                                c.id,
                                                                            );
                                                                            setWithdrawReason(
                                                                                '',
                                                                            );
                                                                        }}
                                                                    >
                                                                        Withdraw
                                                                    </Button>
                                                                )}
                                                        </div>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        );
                                    })}
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </PageLayout>

            {/* Record Consent Dialog */}
            <Dialog open={showRecord} onOpenChange={setShowRecord}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Record Consent</DialogTitle>
                        <DialogDescription>
                            Record a new consent for {client.first_name}.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label>Consent Type *</Label>
                            <Select
                                value={formData.consent_type_id}
                                onValueChange={(v) =>
                                    setFormData({
                                        ...formData,
                                        consent_type_id: v,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select type..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {consent_types.map((t: any) => (
                                        <SelectItem
                                            key={t.id}
                                            value={String(t.id)}
                                        >
                                            {t.name}{' '}
                                            {t.is_mandatory && (
                                                <span className="text-status-critical">
                                                    *
                                                </span>
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Status *</Label>
                                <Select
                                    value={formData.status}
                                    onValueChange={(v) =>
                                        setFormData({ ...formData, status: v })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="given">
                                            Given
                                        </SelectItem>
                                        <SelectItem value="refused">
                                            Refused
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Method *</Label>
                                <Select
                                    value={formData.given_method}
                                    onValueChange={(v) =>
                                        setFormData({
                                            ...formData,
                                            given_method: v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="written">
                                            Written
                                        </SelectItem>
                                        <SelectItem value="verbal">
                                            Verbal
                                        </SelectItem>
                                        <SelectItem value="electronic">
                                            Electronic
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Date Given *</Label>
                                <Input
                                    type="date"
                                    value={formData.given_at}
                                    onChange={(e) =>
                                        setFormData({
                                            ...formData,
                                            given_at: e.target.value,
                                        })
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Expiry Date</Label>
                                <Input
                                    type="date"
                                    value={formData.expires_at}
                                    onChange={(e) =>
                                        setFormData({
                                            ...formData,
                                            expires_at: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        </div>
                        {formData.status === 'refused' && (
                            <div className="space-y-1.5">
                                <Label>Reason for Refusal</Label>
                                <Textarea
                                    value={formData.refusal_reason}
                                    onChange={(e) =>
                                        setFormData({
                                            ...formData,
                                            refusal_reason: e.target.value,
                                        })
                                    }
                                    placeholder="Document why consent was refused..."
                                />
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea
                                value={formData.given_notes}
                                onChange={(e) =>
                                    setFormData({
                                        ...formData,
                                        given_notes: e.target.value,
                                    })
                                }
                                placeholder="Additional notes..."
                                className="min-h-[60px]"
                            />
                        </div>
                        {/* Signed Document Upload */}
                        <div className="space-y-1.5">
                            <Label>Signed Document</Label>
                            <label className="flex cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed border-primary bg-primary/5 p-4 transition-colors hover:bg-primary/10">
                                <Upload className="h-5 w-5 text-primary" />
                                <div>
                                    <p className="text-sm font-medium text-primary">
                                        {consentFile
                                            ? consentFile.name
                                            : 'Click to upload signed consent form'}
                                    </p>
                                    <p className="text-[10px] text-primary">
                                        PDF, JPEG, or PNG up to 10 MB
                                    </p>
                                </div>
                                <input
                                    type="file"
                                    className="hidden"
                                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    onChange={(e) =>
                                        setConsentFile(
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            </label>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowRecord(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            className="bg-primary hover:bg-primary"
                            onClick={submitConsent}
                            disabled={!formData.consent_type_id}
                        >
                            Record Consent
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Withdraw Dialog */}
            <Dialog
                open={!!showWithdraw}
                onOpenChange={(open) => !open && setShowWithdraw(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Withdraw Consent</DialogTitle>
                        <DialogDescription>
                            This action will mark the consent as withdrawn.
                            Please provide a reason.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="py-2">
                        <Label>Reason for Withdrawal *</Label>
                        <Textarea
                            value={withdrawReason}
                            onChange={(e) => setWithdrawReason(e.target.value)}
                            placeholder="Explain why consent is being withdrawn..."
                            className="mt-1.5 min-h-[80px]"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setShowWithdraw(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submitWithdraw}
                            disabled={!withdrawReason.trim()}
                        >
                            Withdraw Consent
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
