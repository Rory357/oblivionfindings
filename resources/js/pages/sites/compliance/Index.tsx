import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { OpsStatCard } from '@/components/ops-stat-card';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Shield,
    AlertTriangle,
    CheckCircle2,
    Clock,
    Calendar,
    Plus,
    FileCheck,
    Pencil,
    Trash2,
    ChevronDown,
    ChevronUp,
} from 'lucide-react';

// ── Types ──────────────────────────────────────────────────────────────

type Certification = {
    id: number;
    certification_type: string;
    name: string;
    issuing_body?: string;
    reference_number?: string;
    status: string;
    issued_date?: string;
    expiry_date?: string;
    next_review_date?: string;
    notes?: string;
    reviewed_by?: { name: string };
    reviewed_at?: string;
};

type ComplianceCheck = {
    id: number;
    check_type: string;
    scheduled_date: string;
    completed_date?: string;
    completed_by?: { name: string };
    status: string;
    findings?: string;
    corrective_actions?: string;
    risk_rating?: string;
    follow_up_date?: string;
};

type Stats = {
    total_certs: number;
    current: number;
    expiring: number;
    expired: number;
    checks_scheduled: number;
    checks_overdue: number;
};

type Props = {
    site: { id: number; name: string; type: string };
    certifications: Certification[];
    compliance_checks: ComplianceCheck[];
    stats: Stats;
    filters: any;
};

// ── Constants ──────────────────────────────────────────────────────────

const CERT_TYPES = [
    'DSS',
    'HSWA',
    'Fire Safety',
    'Building WoF',
    'Food Safety',
    'First Aid',
    'Civil Defence',
    'Infection Control',
    'Medication Management',
    'Restraint Minimisation',
    'Cultural Safety',
    'Other',
];

const CHECK_TYPES = [
    'Fire Drill',
    'Evacuation Drill',
    'H&S Walkthrough',
    'Medication Audit',
    'Infection Control',
    'Restraint Review',
    'Cultural Review',
    'Environmental Check',
    'Food Safety',
    'Vehicle Check',
    'Other',
];

const RISK_RATINGS = ['Low', 'Medium', 'High', 'Critical'];

// ── Helpers ────────────────────────────────────────────────────────────

function fmtDate(d?: string | null): string {
    if (!d) return '\u2014';
    try {
        return new Date(d).toLocaleDateString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    } catch {
        return d;
    }
}

function daysUntil(d?: string | null): number | null {
    if (!d) return null;
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const target = new Date(d);
    target.setHours(0, 0, 0, 0);
    return Math.ceil((target.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
}

function statusBorderColor(status: string): string {
    switch (status.toLowerCase()) {
        case 'current':
        case 'active':
            return 'border-l-emerald-500';
        case 'expiring':
        case 'expiring_soon':
            return 'border-l-amber-500';
        case 'expired':
            return 'border-l-red-500';
        default:
            return 'border-l-slate-500';
    }
}

function statusBadgeClass(status: string): string {
    switch (status.toLowerCase()) {
        case 'current':
        case 'active':
        case 'completed':
            return 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10';
        case 'expiring':
        case 'expiring_soon':
            return 'border-amber-500/30 text-amber-400 bg-amber-500/10';
        case 'expired':
        case 'overdue':
            return 'border-red-500/30 text-red-400 bg-red-500/10';
        case 'scheduled':
            return 'border-blue-500/30 text-blue-400 bg-blue-500/10';
        default:
            return 'border-slate-500/30 text-muted-foreground';
    }
}

function riskBadgeClass(rating?: string): string {
    switch (rating?.toLowerCase()) {
        case 'critical':
            return 'border-red-500/30 text-red-400 bg-red-500/10';
        case 'high':
            return 'border-orange-500/30 text-orange-400 bg-orange-500/10';
        case 'medium':
            return 'border-amber-500/30 text-amber-400 bg-amber-500/10';
        case 'low':
            return 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10';
        default:
            return 'border-slate-500/30 text-muted-foreground';
    }
}

function certTypeLabel(t: string): string {
    return t
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

// ── Component ──────────────────────────────────────────────────────────

export default function SiteComplianceIndex({
    site,
    certifications = [],
    compliance_checks = [],
    stats: rawStats,
    filters = {},
}: Props) {
    const stats: Stats = rawStats ?? {
        total_certs: 0,
        current: 0,
        expiring: 0,
        expired: 0,
        checks_scheduled: 0,
        checks_overdue: 0,
    };

    // Filter state
    const [certStatusFilter, setCertStatusFilter] = useState<string>('all');

    // Dialog state
    const [showAddCert, setShowAddCert] = useState(false);
    const [showScheduleCheck, setShowScheduleCheck] = useState(false);
    const [showCompleteCheck, setShowCompleteCheck] = useState(false);
    const [completingCheckId, setCompletingCheckId] = useState<number | null>(null);

    // Expanded findings
    const [expandedChecks, setExpandedChecks] = useState<Set<number>>(new Set());

    // Add Certification form
    const [certForm, setCertForm] = useState({
        certification_type: '',
        name: '',
        issuing_body: '',
        reference_number: '',
        issued_date: '',
        expiry_date: '',
        next_review_date: '',
        notes: '',
    });

    // Schedule Check form
    const [checkForm, setCheckForm] = useState({
        check_type: '',
        scheduled_date: '',
        notes: '',
    });

    // Complete Check form
    const [completeForm, setCompleteForm] = useState({
        findings: '',
        corrective_actions: '',
        risk_rating: '',
        follow_up_date: '',
        follow_up_notes: '',
    });

    // Filtered certifications
    const filteredCerts = certStatusFilter === 'all'
        ? certifications
        : certifications.filter(
              (c) => c.status.toLowerCase() === certStatusFilter.toLowerCase(),
          );

    // ── Handlers ───────────────────────────────────────────────────────

    function handleSaveCert() {
        router.post(`/sites/${site.id}/compliance/certifications`, certForm, {
            preserveScroll: true,
            onSuccess: () => {
                setShowAddCert(false);
                setCertForm({
                    certification_type: '',
                    name: '',
                    issuing_body: '',
                    reference_number: '',
                    issued_date: '',
                    expiry_date: '',
                    next_review_date: '',
                    notes: '',
                });
            },
        });
    }

    function handleScheduleCheck() {
        router.post(`/sites/${site.id}/compliance/checks`, checkForm, {
            preserveScroll: true,
            onSuccess: () => {
                setShowScheduleCheck(false);
                setCheckForm({ check_type: '', scheduled_date: '', notes: '' });
            },
        });
    }

    function handleCompleteCheck() {
        if (!completingCheckId) return;
        router.put(
            `/sites/${site.id}/compliance/checks/${completingCheckId}/complete`,
            completeForm,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowCompleteCheck(false);
                    setCompletingCheckId(null);
                    setCompleteForm({
                        findings: '',
                        corrective_actions: '',
                        risk_rating: '',
                        follow_up_date: '',
                        follow_up_notes: '',
                    });
                },
            },
        );
    }

    function handleDeleteCert(id: number) {
        if (!confirm('Are you sure you want to delete this certification?')) return;
        router.delete(`/sites/${site.id}/compliance/certifications/${id}`, {
            preserveScroll: true,
        });
    }

    function toggleExpanded(id: number) {
        setExpandedChecks((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function openCompleteDialog(checkId: number) {
        setCompletingCheckId(checkId);
        setCompleteForm({
            findings: '',
            corrective_actions: '',
            risk_rating: '',
            follow_up_date: '',
            follow_up_notes: '',
        });
        setShowCompleteCheck(true);
    }

    // ── Render ─────────────────────────────────────────────────────────

    const alertCount = (stats.expired ?? 0) + (stats.checks_overdue ?? 0);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name ?? 'Site', href: `/sites/${site.id}` },
                { title: 'Compliance', href: `/sites/${site.id}/compliance` },
            ]}
        >
            <Head title={`${site.name ?? 'Site'} \u2014 Compliance`} />

            <PageShell>
                {/* Header */}
                <PageHeader
                    title={`${site.name ?? 'Site'} \u2014 Compliance`}
                    description="Track certifications, compliance checks, and regulatory requirements"
                    backHref={`/sites/${site.id}`}
                    backLabel="Back to site"
                />

                {/* Stats Row */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <OpsStatCard
                        label="Total Certifications"
                        value={stats.total_certs ?? 0}
                        icon={Shield}
                        color="indigo"
                    />
                    <OpsStatCard
                        label="Current"
                        value={stats.current ?? 0}
                        icon={CheckCircle2}
                        color="emerald"
                    />
                    <OpsStatCard
                        label="Expiring Soon"
                        value={stats.expiring ?? 0}
                        icon={stats.expiring > 0 ? AlertTriangle : Clock}
                        color="amber"
                        subtitle={stats.expiring > 0 ? 'Needs attention' : undefined}
                    />
                    <OpsStatCard
                        label="Expired"
                        value={stats.expired ?? 0}
                        icon={stats.expired > 0 ? AlertTriangle : Clock}
                        color="red"
                        subtitle={stats.expired > 0 ? 'Immediate action' : undefined}
                    />
                    <OpsStatCard
                        label="Checks Scheduled"
                        value={stats.checks_scheduled ?? 0}
                        icon={Calendar}
                        color="blue"
                    />
                    <OpsStatCard
                        label="Checks Overdue"
                        value={stats.checks_overdue ?? 0}
                        icon={stats.checks_overdue > 0 ? AlertTriangle : FileCheck}
                        color="red"
                        subtitle={stats.checks_overdue > 0 ? 'Overdue' : undefined}
                    />
                </div>

                {/* Alert Banner */}
                {alertCount > 0 && (
                    <div className="flex items-center gap-3 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3">
                        <AlertTriangle className="h-5 w-5 shrink-0 text-red-400" />
                        <p className="text-sm font-medium text-red-300">
                            {stats.expired > 0 && (
                                <span>
                                    {stats.expired} certification{stats.expired !== 1 ? 's' : ''} expired
                                </span>
                            )}
                            {stats.expired > 0 && stats.checks_overdue > 0 && ', '}
                            {stats.checks_overdue > 0 && (
                                <span>
                                    {stats.checks_overdue} compliance check{stats.checks_overdue !== 1 ? 's' : ''} overdue
                                </span>
                            )}
                            {' \u2014 immediate attention required'}
                        </p>
                    </div>
                )}

                {/* Two-column layout */}
                <div className="grid gap-6 lg:grid-cols-5">
                    {/* Left: Certifications (60%) */}
                    <div className="lg:col-span-3">
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="flex items-center gap-2">
                                        <Shield className="h-5 w-5 text-primary" />
                                        Certifications & Accreditations
                                    </CardTitle>
                                    <Button
                                        size="sm"
                                        className="bg-primary hover:bg-primary"
                                        onClick={() => setShowAddCert(true)}
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        Add Certification
                                    </Button>
                                </div>
                                {/* Status filter */}
                                <div className="mt-3">
                                    <Select
                                        value={certStatusFilter}
                                        onValueChange={setCertStatusFilter}
                                    >
                                        <SelectTrigger className="w-[160px]">
                                            <SelectValue placeholder="All Statuses" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Statuses</SelectItem>
                                            <SelectItem value="current">Current</SelectItem>
                                            <SelectItem value="expiring">Expiring</SelectItem>
                                            <SelectItem value="expired">Expired</SelectItem>
                                            <SelectItem value="pending">Pending</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {filteredCerts.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No certifications found.
                                    </p>
                                ) : (
                                    filteredCerts.map((cert) => {
                                        const days = daysUntil(cert.expiry_date);
                                        return (
                                            <div
                                                key={cert.id}
                                                className={`rounded-lg border border-l-4 ${statusBorderColor(cert.status)} bg-card p-4`}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold">
                                                                {cert.name || '\u2014'}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className="border-primary/30 bg-primary/10 text-primary"
                                                            >
                                                                {certTypeLabel(cert.certification_type)}
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className={statusBadgeClass(cert.status)}
                                                            >
                                                                {certTypeLabel(cert.status)}
                                                            </Badge>
                                                        </div>

                                                        <div className="mt-2 space-y-1 text-sm text-muted-foreground">
                                                            {cert.issuing_body && (
                                                                <div>
                                                                    <span className="font-medium">Issuing Body:</span>{' '}
                                                                    {cert.issuing_body}
                                                                </div>
                                                            )}
                                                            {cert.reference_number && (
                                                                <div>
                                                                    <span className="font-medium">Ref:</span>{' '}
                                                                    {cert.reference_number}
                                                                </div>
                                                            )}
                                                            <div className="flex flex-wrap gap-4">
                                                                <span>
                                                                    <span className="font-medium">Issued:</span>{' '}
                                                                    {fmtDate(cert.issued_date)}
                                                                </span>
                                                                <span>
                                                                    <span className="font-medium">Expires:</span>{' '}
                                                                    {fmtDate(cert.expiry_date)}
                                                                    {days !== null && (
                                                                        <span
                                                                            className={`ml-1 text-xs font-medium ${
                                                                                days < 0
                                                                                    ? 'text-red-400'
                                                                                    : days <= 30
                                                                                      ? 'text-amber-400'
                                                                                      : 'text-emerald-400'
                                                                            }`}
                                                                        >
                                                                            {days < 0
                                                                                ? `${Math.abs(days)} day${Math.abs(days) !== 1 ? 's' : ''} overdue`
                                                                                : `${days} day${days !== 1 ? 's' : ''} remaining`}
                                                                        </span>
                                                                    )}
                                                                </span>
                                                            </div>
                                                            {cert.next_review_date && (
                                                                <div>
                                                                    <span className="font-medium">Next Review:</span>{' '}
                                                                    {fmtDate(cert.next_review_date)}
                                                                </div>
                                                            )}
                                                            {cert.reviewed_by && (
                                                                <div className="text-xs">
                                                                    Reviewed by{' '}
                                                                    {cert.reviewed_by.name}
                                                                    {cert.reviewed_at && (
                                                                        <span>
                                                                            {' '}
                                                                            on {fmtDate(cert.reviewed_at)}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>

                                                    <div className="flex shrink-0 gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.get(
                                                                    `/sites/${site.id}/compliance/certifications/${cert.id}/edit`,
                                                                )
                                                            }
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-red-400 hover:text-red-300"
                                                            onClick={() => handleDeleteCert(cert.id)}
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right: Compliance Checks (40%) */}
                    <div className="lg:col-span-2">
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="flex items-center gap-2">
                                        <FileCheck className="h-5 w-5 text-primary" />
                                        Compliance Checks
                                    </CardTitle>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setShowScheduleCheck(true)}
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        Schedule Check
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {(compliance_checks ?? []).length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No compliance checks found.
                                    </p>
                                ) : (
                                    (compliance_checks ?? []).map((check) => {
                                        const isExpanded = expandedChecks.has(check.id);
                                        const isCompleted =
                                            check.status?.toLowerCase() === 'completed';
                                        return (
                                            <div
                                                key={check.id}
                                                className="rounded-lg border bg-card p-3"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Badge
                                                        variant="outline"
                                                        className="border-primary/30 bg-primary/10 text-primary"
                                                    >
                                                        {certTypeLabel(check.check_type)}
                                                    </Badge>
                                                    {check.risk_rating && (
                                                        <Badge
                                                            variant="outline"
                                                            className={riskBadgeClass(check.risk_rating)}
                                                        >
                                                            {check.risk_rating}
                                                        </Badge>
                                                    )}
                                                    <Badge
                                                        variant="outline"
                                                        className={statusBadgeClass(check.status)}
                                                    >
                                                        {certTypeLabel(check.status)}
                                                    </Badge>
                                                </div>

                                                <div className="mt-2 space-y-1 text-sm text-muted-foreground">
                                                    {isCompleted && check.completed_date ? (
                                                        <div className="flex items-center gap-1">
                                                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                                                            <span>
                                                                Completed {fmtDate(check.completed_date)}
                                                                {check.completed_by && (
                                                                    <span>
                                                                        {' '}
                                                                        by {check.completed_by.name}
                                                                    </span>
                                                                )}
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <div className="flex items-center gap-1">
                                                            <Calendar className="h-3.5 w-3.5" />
                                                            <span>
                                                                Scheduled: {fmtDate(check.scheduled_date)}
                                                            </span>
                                                        </div>
                                                    )}

                                                    {check.follow_up_date && (
                                                        <div className="text-xs">
                                                            Follow-up: {fmtDate(check.follow_up_date)}
                                                        </div>
                                                    )}
                                                </div>

                                                {/* Findings (expandable) */}
                                                {check.findings && (
                                                    <div className="mt-2">
                                                        <button
                                                            className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                                            onClick={() => toggleExpanded(check.id)}
                                                            type="button"
                                                        >
                                                            {isExpanded ? (
                                                                <ChevronUp className="h-3 w-3" />
                                                            ) : (
                                                                <ChevronDown className="h-3 w-3" />
                                                            )}
                                                            {isExpanded ? 'Hide' : 'Show'} Findings
                                                        </button>
                                                        {isExpanded && (
                                                            <div className="mt-1 rounded bg-muted/30 p-2 text-xs text-muted-foreground">
                                                                <p>{check.findings}</p>
                                                                {check.corrective_actions && (
                                                                    <p className="mt-1">
                                                                        <span className="font-medium">
                                                                            Corrective Actions:
                                                                        </span>{' '}
                                                                        {check.corrective_actions}
                                                                    </p>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}

                                                {/* Mark Complete button for non-completed */}
                                                {!isCompleted && (
                                                    <div className="mt-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/10"
                                                            onClick={() =>
                                                                openCompleteDialog(check.id)
                                                            }
                                                        >
                                                            <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
                                                            Mark Complete
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ── Add Certification Dialog ──────────────────────────────── */}
                <Dialog open={showAddCert} onOpenChange={setShowAddCert}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Add Certification</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div>
                                <Label>Type</Label>
                                <Select
                                    value={certForm.certification_type}
                                    onValueChange={(v) =>
                                        setCertForm((f) => ({ ...f, certification_type: v }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CERT_TYPES.map((t) => (
                                            <SelectItem key={t} value={t}>
                                                {t}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Name</Label>
                                <Input
                                    value={certForm.name}
                                    onChange={(e) =>
                                        setCertForm((f) => ({ ...f, name: e.target.value }))
                                    }
                                    placeholder="Certification name"
                                />
                            </div>
                            <div>
                                <Label>Issuing Body</Label>
                                <Input
                                    value={certForm.issuing_body}
                                    onChange={(e) =>
                                        setCertForm((f) => ({
                                            ...f,
                                            issuing_body: e.target.value,
                                        }))
                                    }
                                    placeholder="e.g. Ministry of Health"
                                />
                            </div>
                            <div>
                                <Label>Reference Number</Label>
                                <Input
                                    value={certForm.reference_number}
                                    onChange={(e) =>
                                        setCertForm((f) => ({
                                            ...f,
                                            reference_number: e.target.value,
                                        }))
                                    }
                                    placeholder="Certificate reference"
                                />
                            </div>
                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <Label>Issued Date</Label>
                                    <Input
                                        type="date"
                                        value={certForm.issued_date}
                                        onChange={(e) =>
                                            setCertForm((f) => ({
                                                ...f,
                                                issued_date: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Expiry Date</Label>
                                    <Input
                                        type="date"
                                        value={certForm.expiry_date}
                                        onChange={(e) =>
                                            setCertForm((f) => ({
                                                ...f,
                                                expiry_date: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div>
                                    <Label>Next Review</Label>
                                    <Input
                                        type="date"
                                        value={certForm.next_review_date}
                                        onChange={(e) =>
                                            setCertForm((f) => ({
                                                ...f,
                                                next_review_date: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                            </div>
                            <div>
                                <Label>Notes</Label>
                                <Textarea
                                    value={certForm.notes}
                                    onChange={(e) =>
                                        setCertForm((f) => ({ ...f, notes: e.target.value }))
                                    }
                                    rows={3}
                                    placeholder="Additional notes..."
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="ghost" onClick={() => setShowAddCert(false)}>
                                Cancel
                            </Button>
                            <Button
                                className="bg-primary hover:bg-primary"
                                onClick={handleSaveCert}
                            >
                                Save Certification
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ── Schedule Check Dialog ─────────────────────────────────── */}
                <Dialog open={showScheduleCheck} onOpenChange={setShowScheduleCheck}>
                    <DialogContent className="max-w-md">
                        <DialogHeader>
                            <DialogTitle>Schedule Compliance Check</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div>
                                <Label>Check Type</Label>
                                <Select
                                    value={checkForm.check_type}
                                    onValueChange={(v) =>
                                        setCheckForm((f) => ({ ...f, check_type: v }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select check type..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CHECK_TYPES.map((t) => (
                                            <SelectItem key={t} value={t}>
                                                {t}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Scheduled Date</Label>
                                <Input
                                    type="date"
                                    value={checkForm.scheduled_date}
                                    onChange={(e) =>
                                        setCheckForm((f) => ({
                                            ...f,
                                            scheduled_date: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div>
                                <Label>Notes</Label>
                                <Textarea
                                    value={checkForm.notes}
                                    onChange={(e) =>
                                        setCheckForm((f) => ({ ...f, notes: e.target.value }))
                                    }
                                    rows={3}
                                    placeholder="Additional notes..."
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="ghost"
                                onClick={() => setShowScheduleCheck(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                className="bg-primary hover:bg-primary"
                                onClick={handleScheduleCheck}
                            >
                                Schedule Check
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ── Complete Check Dialog ─────────────────────────────────── */}
                <Dialog open={showCompleteCheck} onOpenChange={setShowCompleteCheck}>
                    <DialogContent className="max-w-md">
                        <DialogHeader>
                            <DialogTitle>Complete Compliance Check</DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div>
                                <Label>Findings</Label>
                                <Textarea
                                    value={completeForm.findings}
                                    onChange={(e) =>
                                        setCompleteForm((f) => ({
                                            ...f,
                                            findings: e.target.value,
                                        }))
                                    }
                                    rows={3}
                                    placeholder="Describe findings..."
                                />
                            </div>
                            <div>
                                <Label>Corrective Actions</Label>
                                <Textarea
                                    value={completeForm.corrective_actions}
                                    onChange={(e) =>
                                        setCompleteForm((f) => ({
                                            ...f,
                                            corrective_actions: e.target.value,
                                        }))
                                    }
                                    rows={3}
                                    placeholder="Required corrective actions..."
                                />
                            </div>
                            <div>
                                <Label>Risk Rating</Label>
                                <Select
                                    value={completeForm.risk_rating}
                                    onValueChange={(v) =>
                                        setCompleteForm((f) => ({ ...f, risk_rating: v }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select risk rating..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {RISK_RATINGS.map((r) => (
                                            <SelectItem key={r} value={r}>
                                                {r}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Follow-up Date</Label>
                                <Input
                                    type="date"
                                    value={completeForm.follow_up_date}
                                    onChange={(e) =>
                                        setCompleteForm((f) => ({
                                            ...f,
                                            follow_up_date: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div>
                                <Label>Follow-up Notes</Label>
                                <Textarea
                                    value={completeForm.follow_up_notes}
                                    onChange={(e) =>
                                        setCompleteForm((f) => ({
                                            ...f,
                                            follow_up_notes: e.target.value,
                                        }))
                                    }
                                    rows={2}
                                    placeholder="Follow-up notes..."
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="ghost"
                                onClick={() => setShowCompleteCheck(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                className="bg-emerald-600 hover:bg-emerald-700"
                                onClick={handleCompleteCheck}
                            >
                                <CheckCircle2 className="mr-1 h-4 w-4" />
                                Complete Check
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
