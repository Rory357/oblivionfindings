import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
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
import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    FileCheck,
    Plus,
    Shield,
    ShieldCheck,
    Upload,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

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

    // Group by category
    const grouped: Record<string, any[]> = {};
    consents.forEach((c) => {
        const cat = c.consent_type?.category ?? c.category ?? 'other';
        if (!grouped[cat]) grouped[cat] = [];
        grouped[cat].push(c);
    });

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/operations/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
                { title: 'Consents' },
            ]}
        >
            <Head title={`Consents - ${name}`} />
            <PageHero
                icon={Shield}
                title="Consent Management"
                description={`Manage consent records for ${name}.`}
                backHref={`/operations/clients/${client.id}`}
                stats={[
                    { label: 'Total', value: s.total },
                    { label: 'Active', value: s.active },
                    { label: 'Expiring', value: s.expiring_soon },
                    { label: 'Expired', value: s.expired },
                ]}
                actions={
                    <Button
                        className="gap-1.5 bg-primary hover:bg-primary"
                        onClick={() => setShowRecord(true)}
                        disabled={!hasConsentTypes}
                    >
                        <Plus className="h-4 w-4" /> Record Consent
                    </Button>
                }
            />
            <PageShell>
                {!hasConsentTypes && (
                    <Card className="border-status-warning/30 bg-status-warning-bg">
                        <CardContent className="flex items-start gap-3 p-4 text-sm text-status-warning">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                            <div>
                                Consent types have not been configured yet, so
                                new consent records are temporarily unavailable
                                on this page.
                            </div>
                        </CardContent>
                    </Card>
                )}
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {[
                        {
                            label: 'Total',
                            value: s.total,
                            color: 'text-primary',
                            bg: 'from-primary/10 to-primary/10',
                        },
                        {
                            label: 'Active',
                            value: s.active,
                            color: 'text-status-success',
                            bg: 'from-status-success-bg to-status-success-bg',
                        },
                        {
                            label: 'Expiring',
                            value: s.expiring_soon,
                            color:
                                s.expiring_soon > 0
                                    ? 'text-status-warning'
                                    : 'text-muted-foreground',
                            bg: 'from-status-warning-bg to-status-warning-bg',
                        },
                        {
                            label: 'Expired',
                            value: s.expired,
                            color:
                                s.expired > 0
                                    ? 'text-status-critical'
                                    : 'text-muted-foreground',
                            bg: 'from-status-critical-bg to-status-critical-bg',
                        },
                        {
                            label: 'Withdrawn',
                            value: s.withdrawn,
                            color: 'text-muted-foreground',
                            bg: 'from-muted to-muted',
                        },
                    ].map((st) => (
                        <div
                            key={st.label}
                            className={`rounded-xl border bg-gradient-to-br ${st.bg} p-3 text-center`}
                        >
                            <div className={`text-xl font-bold ${st.color}`}>
                                {st.value}
                            </div>
                            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                {st.label}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Consent Cards grouped by category */}
                {consents.length === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                <ShieldCheck className="h-8 w-8 text-primary" />
                            </div>
                            <p className="font-medium">No Consent Records</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Record the first consent for {client.first_name}
                                .
                            </p>
                            <Button
                                className="mt-4 gap-1.5 bg-primary hover:bg-primary"
                                size="sm"
                                onClick={() => setShowRecord(true)}
                                disabled={!hasConsentTypes}
                            >
                                <Plus className="h-3.5 w-3.5" /> Record Consent
                            </Button>
                        </CardContent>
                    </Card>
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
                                    const displayStatus = c.is_expired
                                        ? 'expired'
                                        : c.status;
                                    const style =
                                        STATUS_STYLES[displayStatus] ??
                                        STATUS_STYLES.given;
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
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex items-start gap-3">
                                                        <div
                                                            className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${style.bg}`}
                                                        >
                                                            <StatusIcon className="h-4 w-4" />
                                                        </div>
                                                        <div>
                                                            <div className="flex items-center gap-2">
                                                                <span className="text-sm font-semibold">
                                                                    {c
                                                                        .consent_type
                                                                        ?.name ??
                                                                        c.consent_type_name ??
                                                                        'Consent'}
                                                                </span>
                                                                <Badge
                                                                    className={`border-0 text-[10px] capitalize ${style.bg}`}
                                                                >
                                                                    {
                                                                        displayStatus
                                                                    }
                                                                </Badge>
                                                                {c.capacity_assessed && (
                                                                    <Badge className="border-0 bg-primary/10 text-[10px] text-primary">
                                                                        Capacity
                                                                        Assessed
                                                                    </Badge>
                                                                )}
                                                                {c.is_expiring_soon &&
                                                                    !c.is_expired && (
                                                                        <Badge className="animate-pulse border-0 bg-status-warning-bg text-[10px] text-status-warning">
                                                                            Expiring
                                                                            Soon
                                                                        </Badge>
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
                                                    {c.status === 'given' &&
                                                        !c.is_expired && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="h-7 border-status-critical/30 text-xs text-status-critical hover:bg-status-critical-bg"
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
                                            </CardContent>
                                        </Card>
                                    );
                                })}
                            </div>
                        </div>
                    ))
                )}
            </PageShell>

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
                                        PDF, Image, or scanned document
                                    </p>
                                </div>
                                <input
                                    type="file"
                                    className="hidden"
                                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
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
