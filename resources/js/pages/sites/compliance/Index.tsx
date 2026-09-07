import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
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
import {
    type AssuranceStatus,
    useNzsAssurance,
} from '@/pages/health-safety/components/hs-hero-kit';
import { Head, router } from '@inertiajs/react';
import {
    Calendar,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    FileCheck,
    Pencil,
    Plus,
    Shield,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '../_confirm-action';

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
    can?: { manage_compliance: boolean };
};

// ── Constants ──────────────────────────────────────────────────────────

const CERT_TYPES = [
    { value: 'healthcert_certification', label: 'HealthCERT certification' },
    { value: 'hswa_compliance', label: 'HSWA compliance' },
    { value: 'fire_safety', label: 'Fire safety' },
    { value: 'building_wof', label: 'Building WoF' },
    { value: 'food_safety', label: 'Food safety' },
    { value: 'first_aid', label: 'First aid' },
    { value: 'civil_defence', label: 'Civil defence' },
    { value: 'infection_control', label: 'Infection control' },
    { value: 'medication_management', label: 'Medication management' },
    { value: 'restraint_minimisation', label: 'Restraint minimisation' },
    { value: 'cultural_safety', label: 'Cultural safety' },
    { value: 'other', label: 'Other' },
];

const CHECK_TYPES = [
    { value: 'fire_drill', label: 'Fire drill' },
    { value: 'evacuation_drill', label: 'Evacuation drill' },
    { value: 'health_safety_walkthrough', label: 'H&S walkthrough' },
    { value: 'medication_audit', label: 'Medication audit' },
    { value: 'infection_control_audit', label: 'Infection control audit' },
    { value: 'restraint_review', label: 'Restraint review' },
    { value: 'cultural_review', label: 'Cultural review' },
    { value: 'environmental_check', label: 'Environmental check' },
    { value: 'food_safety_check', label: 'Food safety check' },
    { value: 'vehicle_check', label: 'Vehicle check' },
    { value: 'other', label: 'Other' },
];

const RISK_RATINGS = ['low', 'medium', 'high', 'critical'];

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
    return Math.ceil(
        (target.getTime() - now.getTime()) / (1000 * 60 * 60 * 24),
    );
}

function statusBorderColor(status: string): string {
    switch (status.toLowerCase()) {
        case 'current':
        case 'active':
            return 'border-l-status-success';
        case 'expiring':
        case 'expiring_soon':
        case 'action_required':
            return 'border-l-status-warning';
        case 'expired':
            return 'border-l-status-critical';
        default:
            return 'border-l-border';
    }
}

function statusBadgeClass(status: string): string {
    switch (status.toLowerCase()) {
        case 'current':
        case 'active':
        case 'completed':
            return 'border-status-success/30 text-status-success bg-status-success';
        case 'expiring':
        case 'expiring_soon':
        case 'action_required':
            return 'border-status-warning/30 text-status-warning bg-status-warning';
        case 'expired':
        case 'overdue':
            return 'border-status-critical/30 text-status-critical bg-status-critical';
        case 'scheduled':
            return 'border-status-info/30 text-status-info bg-status-info';
        default:
            return 'border-border/30 text-muted-foreground';
    }
}

function riskBadgeClass(rating?: string): string {
    switch (rating?.toLowerCase()) {
        case 'critical':
            return 'border-status-critical/30 text-status-critical bg-status-critical';
        case 'high':
            return 'border-status-warning/30 text-status-warning bg-status-warning';
        case 'medium':
            return 'border-status-warning/30 text-status-warning bg-status-warning';
        case 'low':
            return 'border-status-success/30 text-status-success bg-status-success';
        default:
            return 'border-border/30 text-muted-foreground';
    }
}

function certTypeLabel(t: string): string {
    return t.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function displayedCertificationStatus(
    certification: Certification,
    nzsStatus: AssuranceStatus,
): string {
    return certification.certification_type === 'healthcert_certification' &&
        nzsStatus !== 'certified'
        ? nzsStatus
        : certification.status;
}

function certificationStatusLabel(status: string): string {
    return status === 'unknown'
        ? 'Evidence unknown'
        : status === 'action_required'
          ? 'Action required'
          : certTypeLabel(status);
}

// ── Component ──────────────────────────────────────────────────────────

export default function SiteComplianceIndex({
    site,
    certifications = [],
    compliance_checks = [],
    stats: rawStats,
    can = { manage_compliance: false },
}: Props) {
    const assurance = useNzsAssurance();
    const stats: Stats = rawStats ?? {
        total_certs: 0,
        current: 0,
        expiring: 0,
        expired: 0,
        checks_scheduled: 0,
        checks_overdue: 0,
    };

    // Filter state (driven from the header's filter row and meter blocks)
    const [certStatusFilter, setCertStatusFilter] = useState<string>('all');
    const [checkStatusFilter, setCheckStatusFilter] = useState<string>('all');
    // Header scoped search — narrows both columns by name/type/reference.
    const [q, setQ] = useState('');

    // Dialog state
    const [showAddCert, setShowAddCert] = useState(false);
    const [editingCertification, setEditingCertification] =
        useState<Certification | null>(null);
    const [showScheduleCheck, setShowScheduleCheck] = useState(false);
    const [showCompleteCheck, setShowCompleteCheck] = useState(false);
    const [completingCheckId, setCompletingCheckId] = useState<number | null>(
        null,
    );

    // Expanded findings
    const [expandedChecks, setExpandedChecks] = useState<Set<number>>(
        new Set(),
    );

    // Add Certification form
    const [certForm, setCertForm] = useState({
        certification_type: '',
        name: '',
        status: 'current',
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

    // Filtered certifications + checks
    const search = q.trim().toLowerCase();

    const filteredCerts = certifications.filter((c) => {
        const statusOk =
            certStatusFilter === 'all' ||
            displayedCertificationStatus(
                c,
                assurance.certification_status,
            ).toLowerCase() === certStatusFilter.toLowerCase();
        const searchOk =
            search === '' ||
            [
                c.name,
                certTypeLabel(c.certification_type),
                c.issuing_body,
                c.reference_number,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search);
        return statusOk && searchOk;
    });

    const filteredChecks = (compliance_checks ?? []).filter((c) => {
        const statusOk =
            checkStatusFilter === 'all' ||
            c.status?.toLowerCase() === checkStatusFilter;
        const searchOk =
            search === '' ||
            [certTypeLabel(c.check_type), c.findings, c.completed_by?.name]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search);
        return statusOk && searchOk;
    });

    // ── Handlers ───────────────────────────────────────────────────────

    function handleSaveCert() {
        const route = editingCertification
            ? `/sites/${site.id}/certifications/${editingCertification.id}`
            : `/sites/${site.id}/certifications`;
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setShowAddCert(false);
                setEditingCertification(null);
                setCertForm({
                    certification_type: '',
                    name: '',
                    status: 'current',
                    issuing_body: '',
                    reference_number: '',
                    issued_date: '',
                    expiry_date: '',
                    next_review_date: '',
                    notes: '',
                });
            },
        };

        if (editingCertification) {
            router.put(route, certForm, options);
        } else {
            router.post(route, certForm, options);
        }
    }

    function handleScheduleCheck() {
        router.post(`/sites/${site.id}/compliance-checks`, checkForm, {
            preserveScroll: true,
            onSuccess: () => {
                setShowScheduleCheck(false);
                setCheckForm({ check_type: '', scheduled_date: '', notes: '' });
            },
        });
    }

    function handleCompleteCheck() {
        if (!completingCheckId) return;
        router.patch(
            `/sites/${site.id}/compliance-checks/${completingCheckId}/complete`,
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
        router.delete(`/sites/${site.id}/certifications/${id}`, {
            preserveScroll: true,
        });
    }

    function openCertificationDialog(certification?: Certification) {
        setEditingCertification(certification ?? null);
        setCertForm(
            certification
                ? {
                      certification_type: certification.certification_type,
                      name: certification.name,
                      status: certification.status,
                      issuing_body: certification.issuing_body ?? '',
                      reference_number: certification.reference_number ?? '',
                      issued_date:
                          certification.issued_date?.slice(0, 10) ?? '',
                      expiry_date:
                          certification.expiry_date?.slice(0, 10) ?? '',
                      next_review_date:
                          certification.next_review_date?.slice(0, 10) ?? '',
                      notes: certification.notes ?? '',
                  }
                : {
                      certification_type: '',
                      name: '',
                      status: 'current',
                      issuing_body: '',
                      reference_number: '',
                      issued_date: '',
                      expiry_date: '',
                      next_review_date: '',
                      notes: '',
                  },
        );
        setShowAddCert(true);
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

    const header = (
        <PageHeader
            variant="profile"
            icon={ShieldCheck}
            backHref={`/sites/${site.id}`}
            title={site.name ?? 'Site'}
            titleChip={
                alertCount > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {alertCount} need action
                    </PageHeaderStatusChip>
                ) : (stats.expiring ?? 0) > 0 ? (
                    <PageHeaderStatusChip variant="warning">
                        {stats.expiring} expiring
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        All current
                    </PageHeaderStatusChip>
                )
            }
            subline="Site compliance \u00b7 certifications, checks and regulatory requirements"
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder="Search certifications, checks\u2026"
                    />
                    {can.manage_compliance ? (
                        <>
                            <PageHeaderGlassButton
                                icon={Calendar}
                                onClick={() => setShowScheduleCheck(true)}
                            >
                                Schedule check
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                onClick={() => openCertificationDialog()}
                            >
                                Add certification
                            </PageHeaderPrimaryButton>
                        </>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Certifications"
                        ariaLabel="View all certifications"
                        onClick={() => setCertStatusFilter('all')}
                    >
                        <PageHeaderMeterBig>
                            {stats.total_certs ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            held by this site
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Current"
                        ariaLabel="View current certifications"
                        onClick={() => setCertStatusFilter('current')}
                    >
                        {(stats.total_certs ?? 0) > 0 ? (
                            <PageHeaderMeterDonut
                                percent={
                                    ((stats.current ?? 0) / stats.total_certs) *
                                    100
                                }
                                caption={
                                    <>
                                        {stats.current ?? 0} of{' '}
                                        {stats.total_certs}
                                        <br />
                                        certifications
                                    </>
                                }
                            />
                        ) : (
                            <>
                                <PageHeaderMeterBig>0</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    none held yet
                                </PageHeaderMeterCaption>
                            </>
                        )}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Expiring"
                        tone={(stats.expiring ?? 0) > 0 ? 'warning' : 'success'}
                        ariaLabel="View expiring certifications"
                        onClick={() => setCertStatusFilter('expiring')}
                    >
                        <PageHeaderMeterBig>
                            {stats.expiring ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            renewal due soon
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Expired"
                        tone={(stats.expired ?? 0) > 0 ? 'critical' : 'success'}
                        ariaLabel="View expired certifications"
                        onClick={() => setCertStatusFilter('expired')}
                    >
                        <PageHeaderMeterBig>
                            {stats.expired ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            need replacing
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Checks scheduled"
                        ariaLabel="View scheduled compliance checks"
                        onClick={() => setCheckStatusFilter('scheduled')}
                    >
                        <PageHeaderMeterBig>
                            {stats.checks_scheduled ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            upcoming checks
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Checks overdue"
                        tone={
                            (stats.checks_overdue ?? 0) > 0
                                ? 'critical'
                                : 'success'
                        }
                        ariaLabel="View overdue compliance checks"
                        onClick={() => setCheckStatusFilter('overdue')}
                    >
                        <PageHeaderMeterBig>
                            {stats.checks_overdue ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            past their date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Shield}
                        label="All statuses"
                        value={certStatusFilter}
                        options={[
                            { value: 'all', label: 'All statuses' },
                            { value: 'current', label: 'Current' },
                            { value: 'expiring', label: 'Expiring' },
                            { value: 'expired', label: 'Expired' },
                            { value: 'pending', label: 'Pending' },
                        ]}
                        onChange={setCertStatusFilter}
                    />
                    <PageHeaderFilterSelect
                        icon={FileCheck}
                        label="All checks"
                        value={checkStatusFilter}
                        options={[
                            { value: 'all', label: 'All checks' },
                            { value: 'scheduled', label: 'Scheduled' },
                            { value: 'overdue', label: 'Overdue' },
                            { value: 'completed', label: 'Completed' },
                        ]}
                        onChange={setCheckStatusFilter}
                    />
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: site.name ?? 'Site', href: `/sites/${site.id}` },
                { title: 'Compliance', href: `/sites/${site.id}/compliance` },
            ]}
        >
            <Head title={`${site.name ?? 'Site'} \u2014 Compliance`} />

            <PageLayout hero={header}>
                {/* Two-column layout */}
                <div className="grid gap-5 lg:grid-cols-5">
                    {/* Left: Certifications (60%) */}
                    <div className="lg:col-span-3">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2">
                                    <Shield className="h-5 w-5 text-primary" />
                                    Certifications & Accreditations
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {filteredCerts.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No certifications found.
                                    </p>
                                ) : (
                                    filteredCerts.map((cert) => {
                                        const days = daysUntil(
                                            cert.expiry_date,
                                        );
                                        const displayedStatus =
                                            displayedCertificationStatus(
                                                cert,
                                                assurance.certification_status,
                                            );
                                        return (
                                            <div
                                                key={cert.id}
                                                className={`rounded-lg border border-l-4 ${statusBorderColor(displayedStatus)} bg-card p-4`}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-semibold">
                                                                {cert.name ||
                                                                    '\u2014'}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className="border-primary/30 bg-primary/10 text-primary"
                                                            >
                                                                {certTypeLabel(
                                                                    cert.certification_type,
                                                                )}
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className={statusBadgeClass(
                                                                    displayedStatus,
                                                                )}
                                                            >
                                                                {certificationStatusLabel(
                                                                    displayedStatus,
                                                                )}
                                                            </Badge>
                                                        </div>

                                                        <div className="mt-2 space-y-1 text-sm text-muted-foreground">
                                                            {cert.issuing_body && (
                                                                <div>
                                                                    <span className="font-medium">
                                                                        Issuing
                                                                        Body:
                                                                    </span>{' '}
                                                                    {
                                                                        cert.issuing_body
                                                                    }
                                                                </div>
                                                            )}
                                                            {cert.reference_number && (
                                                                <div>
                                                                    <span className="font-medium">
                                                                        Ref:
                                                                    </span>{' '}
                                                                    {
                                                                        cert.reference_number
                                                                    }
                                                                </div>
                                                            )}
                                                            <div className="flex flex-wrap gap-4">
                                                                <span>
                                                                    <span className="font-medium">
                                                                        Issued:
                                                                    </span>{' '}
                                                                    {fmtDate(
                                                                        cert.issued_date,
                                                                    )}
                                                                </span>
                                                                <span>
                                                                    <span className="font-medium">
                                                                        Expires:
                                                                    </span>{' '}
                                                                    {fmtDate(
                                                                        cert.expiry_date,
                                                                    )}
                                                                    {days !==
                                                                        null && (
                                                                        <span
                                                                            className={`ml-1 text-xs font-medium ${
                                                                                days <
                                                                                0
                                                                                    ? 'text-status-critical'
                                                                                    : days <=
                                                                                        30
                                                                                      ? 'text-status-warning'
                                                                                      : 'text-status-success'
                                                                            }`}
                                                                        >
                                                                            {days <
                                                                            0
                                                                                ? `${Math.abs(days)} day${Math.abs(days) !== 1 ? 's' : ''} overdue`
                                                                                : `${days} day${days !== 1 ? 's' : ''} remaining`}
                                                                        </span>
                                                                    )}
                                                                </span>
                                                            </div>
                                                            {cert.next_review_date && (
                                                                <div>
                                                                    <span className="font-medium">
                                                                        Next
                                                                        Review:
                                                                    </span>{' '}
                                                                    {fmtDate(
                                                                        cert.next_review_date,
                                                                    )}
                                                                </div>
                                                            )}
                                                            {cert.reviewed_by && (
                                                                <div className="text-xs">
                                                                    Reviewed by{' '}
                                                                    {
                                                                        cert
                                                                            .reviewed_by
                                                                            .name
                                                                    }
                                                                    {cert.reviewed_at && (
                                                                        <span>
                                                                            {' '}
                                                                            on{' '}
                                                                            {fmtDate(
                                                                                cert.reviewed_at,
                                                                            )}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {can.manage_compliance && (
                                                        <div className="flex shrink-0 gap-1">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() =>
                                                                    openCertificationDialog(
                                                                        cert,
                                                                    )
                                                                }
                                                                aria-label={`Edit ${cert.name}`}
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                            <ConfirmAction
                                                                title="Delete certification?"
                                                                description={`Delete "${cert.name || certTypeLabel(cert.certification_type)}" from this site?`}
                                                                confirmLabel="Delete"
                                                                onConfirm={() =>
                                                                    handleDeleteCert(
                                                                        cert.id,
                                                                    )
                                                                }
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="text-status-critical hover:text-status-critical"
                                                                    aria-label={`Delete ${cert.name}`}
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            </ConfirmAction>
                                                        </div>
                                                    )}
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
                                <CardTitle className="flex items-center gap-2">
                                    <FileCheck className="h-5 w-5 text-primary" />
                                    Compliance Checks
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {filteredChecks.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground">
                                        No compliance checks found.
                                    </p>
                                ) : (
                                    filteredChecks.map((check) => {
                                        const isExpanded = expandedChecks.has(
                                            check.id,
                                        );
                                        const isCompleted =
                                            check.status?.toLowerCase() ===
                                            'completed';
                                        const canComplete = [
                                            'scheduled',
                                            'overdue',
                                            'missed',
                                        ].includes(
                                            check.status?.toLowerCase() ?? '',
                                        );
                                        return (
                                            <Card
                                                key={check.id}
                                                className="p-3"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Badge
                                                        variant="outline"
                                                        className="border-primary/30 bg-primary/10 text-primary"
                                                    >
                                                        {certTypeLabel(
                                                            check.check_type,
                                                        )}
                                                    </Badge>
                                                    {check.risk_rating && (
                                                        <Badge
                                                            variant="outline"
                                                            className={riskBadgeClass(
                                                                check.risk_rating,
                                                            )}
                                                        >
                                                            {check.risk_rating}
                                                        </Badge>
                                                    )}
                                                    <Badge
                                                        variant="outline"
                                                        className={statusBadgeClass(
                                                            check.status,
                                                        )}
                                                    >
                                                        {certTypeLabel(
                                                            check.status,
                                                        )}
                                                    </Badge>
                                                </div>

                                                <div className="mt-2 space-y-1 text-sm text-muted-foreground">
                                                    {isCompleted &&
                                                    check.completed_date ? (
                                                        <div className="flex items-center gap-1">
                                                            <CheckCircle2 className="h-3.5 w-3.5 text-status-success" />
                                                            <span>
                                                                Completed{' '}
                                                                {fmtDate(
                                                                    check.completed_date,
                                                                )}
                                                                {check.completed_by && (
                                                                    <span>
                                                                        {' '}
                                                                        by{' '}
                                                                        {
                                                                            check
                                                                                .completed_by
                                                                                .name
                                                                        }
                                                                    </span>
                                                                )}
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <div className="flex items-center gap-1">
                                                            <Calendar className="h-3.5 w-3.5" />
                                                            <span>
                                                                Scheduled:{' '}
                                                                {fmtDate(
                                                                    check.scheduled_date,
                                                                )}
                                                            </span>
                                                        </div>
                                                    )}

                                                    {check.follow_up_date && (
                                                        <div className="text-xs">
                                                            Follow-up:{' '}
                                                            {fmtDate(
                                                                check.follow_up_date,
                                                            )}
                                                        </div>
                                                    )}
                                                </div>

                                                {/* Findings (expandable) */}
                                                {check.findings && (
                                                    <div className="mt-2">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-auto gap-1 p-0 text-xs text-muted-foreground hover:text-foreground"
                                                            onClick={() =>
                                                                toggleExpanded(
                                                                    check.id,
                                                                )
                                                            }
                                                            type="button"
                                                        >
                                                            {isExpanded ? (
                                                                <ChevronUp className="h-3 w-3" />
                                                            ) : (
                                                                <ChevronDown className="h-3 w-3" />
                                                            )}
                                                            {isExpanded
                                                                ? 'Hide'
                                                                : 'Show'}{' '}
                                                            Findings
                                                        </Button>
                                                        {isExpanded && (
                                                            <div className="mt-1 rounded bg-muted/30 p-2 text-xs text-muted-foreground">
                                                                <p>
                                                                    {
                                                                        check.findings
                                                                    }
                                                                </p>
                                                                {check.corrective_actions && (
                                                                    <p className="mt-1">
                                                                        <span className="font-medium">
                                                                            Corrective
                                                                            Actions:
                                                                        </span>{' '}
                                                                        {
                                                                            check.corrective_actions
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                )}

                                                {/* Mark Complete button for non-completed */}
                                                {can.manage_compliance &&
                                                    !isCompleted &&
                                                    canComplete && (
                                                        <div className="mt-2">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                className="border-status-success/30 text-status-success hover:bg-status-success"
                                                                onClick={() =>
                                                                    openCompleteDialog(
                                                                        check.id,
                                                                    )
                                                                }
                                                            >
                                                                <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
                                                                Mark Complete
                                                            </Button>
                                                        </div>
                                                    )}
                                            </Card>
                                        );
                                    })
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* ── Add Certification Dialog ──────────────────────────────── */}
                <Dialog
                    open={showAddCert}
                    onOpenChange={(open) => {
                        setShowAddCert(open);
                        if (!open) setEditingCertification(null);
                    }}
                >
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>
                                {editingCertification
                                    ? 'Edit Certification'
                                    : 'Add Certification'}
                            </DialogTitle>
                        </DialogHeader>
                        <div className="space-y-4">
                            <div>
                                <Label>Type</Label>
                                <Select
                                    value={certForm.certification_type}
                                    onValueChange={(v) =>
                                        setCertForm((f) => ({
                                            ...f,
                                            certification_type: v,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CERT_TYPES.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
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
                                        setCertForm((f) => ({
                                            ...f,
                                            name: e.target.value,
                                        }))
                                    }
                                    placeholder="Certification name"
                                />
                            </div>
                            <div>
                                <Label>Status</Label>
                                <Select
                                    value={certForm.status}
                                    onValueChange={(value) =>
                                        setCertForm((form) => ({
                                            ...form,
                                            status: value,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="current">
                                            Current
                                        </SelectItem>
                                        <SelectItem value="expiring">
                                            Expiring
                                        </SelectItem>
                                        <SelectItem value="expired">
                                            Expired
                                        </SelectItem>
                                        <SelectItem value="pending">
                                            Pending
                                        </SelectItem>
                                        <SelectItem value="not_applicable">
                                            Not applicable
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
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
                                    placeholder="e.g. Te Whatu Ora"
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
                                                next_review_date:
                                                    e.target.value,
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
                                        setCertForm((f) => ({
                                            ...f,
                                            notes: e.target.value,
                                        }))
                                    }
                                    rows={3}
                                    placeholder="Additional notes..."
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setShowAddCert(false);
                                    setEditingCertification(null);
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                className="bg-primary hover:bg-primary"
                                onClick={handleSaveCert}
                            >
                                {editingCertification
                                    ? 'Save Changes'
                                    : 'Add Certification'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* ── Schedule Check Dialog ─────────────────────────────────── */}
                <Dialog
                    open={showScheduleCheck}
                    onOpenChange={setShowScheduleCheck}
                >
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
                                        setCheckForm((f) => ({
                                            ...f,
                                            check_type: v,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select check type..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CHECK_TYPES.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
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
                                        setCheckForm((f) => ({
                                            ...f,
                                            notes: e.target.value,
                                        }))
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
                <Dialog
                    open={showCompleteCheck}
                    onOpenChange={setShowCompleteCheck}
                >
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
                                        setCompleteForm((f) => ({
                                            ...f,
                                            risk_rating: v,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select risk rating..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {RISK_RATINGS.map((r) => (
                                            <SelectItem key={r} value={r}>
                                                {certTypeLabel(r)}
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
                                className="bg-status-success hover:bg-status-success"
                                onClick={handleCompleteCheck}
                            >
                                <CheckCircle2 className="mr-1 h-4 w-4" />
                                Complete Check
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageLayout>
        </AppLayout>
    );
}
