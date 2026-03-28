import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState, useCallback } from 'react';
import {
    Building2,
    Home,
    Warehouse,
    MapPin,
    AlertTriangle,
    AlertCircle,
    CheckCircle2,
    Calendar,
    ClipboardCheck,
    ShieldAlert,
    Truck,
    Package,
    Cpu,
    BedDouble,
    DoorOpen,
    LayoutGrid,
    FileText,
    Users,
    Settings,
    PlayCircle,
    Circle,
    GraduationCap,
    Plus,
    Shield,
    Award,
    Star,
    MessageSquare,
    Layers,
    ChevronDown,
    ChevronUp,
} from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility';
    display_type: string;
    phone?: string | null;
    email?: string | null;
    manager_name?: string | null;
    manager_phone?: string | null;
    after_hours_phone?: string | null;
    emergency_plan_location?: string | null;
    medication_storage_location?: string | null;
    notes?: string | null;
    address?: string;
    region?: string | null;
    latitude?: string | null;
    longitude?: string | null;
    access_instructions?: string | null;
    is_active: boolean;
    is_high_risk: boolean;
    is_high_needs: boolean;
    risk_notes?: string | null;
    risk_review_date?: string | null;
    primary_contact?: { id: number; name: string } | null;
    onboarding_completed_at?: string | null;
    onboarding_progress?: Record<string, { completed?: boolean; data?: any; completed_at?: string }>;
    service_contexts?: Array<{ id: number; name: string; type?: string; is_active: boolean; description?: string }>;
};

type Contact = {
    id: number;
    type?: string | null;
    name: string;
    role?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
    notes?: string | null;
};

type Doc = {
    id: number;
    title?: string | null;
    category?: string | null;
    version?: string | null;
    effective_date?: string | null;
    expiry_date?: string | null;
    notes?: string | null;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    created_at?: string | null;
    uploaded_by?: { id: number; name: string; email: string } | null;
};

type AssetLite = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    status: string;
    risk_level: string;
    location?: string | null;
    owner: { type: 'site' | 'client'; label: string; id: number };
    updated_at?: string | null;
};

type ClientLite = { id: number; first_name: string; last_name: string; status: string };
type ChecklistItem = { key: string; label: string; done: boolean };

type TypeSpecificData = {
    rooms?: Array<{ id: number; name: string; assigned_client?: { id: number; name: string } | null }>;
    resources?: Array<{ id: number; name: string; type: string; capacity?: number }>;
    zones?: Array<{ id: number; name: string; type?: string }>;
};

type VendorLite = {
    id: number;
    company_name: string;
    service_type: string;
    phone?: string | null;
    is_preferred: boolean;
};

type StaffRequirement = {
    id: number;
    requirement_name: string;
    category: 'mandatory' | 'recommended' | 'specialist';
    description?: string | null;
    certification_required: boolean;
    expiry_period_months?: number | null;
};

type Props = {
    site: Site;
    clients: ClientLite[];
    contacts: Contact[];
    documents: Doc[];
    assets: AssetLite[];
    checklist: ChecklistItem[];
    typeSpecificData: TypeSpecificData;
    vendors?: VendorLite[];
    staffRequirements?: StaffRequirement[];
    credentialCount?: number;
    hardwareCount?: number;
    integrationStatus?: Array<{ provider: string; status: string }>;
    can_edit: boolean;
    can?: { createAsset?: boolean };
};

const typeIcons = {
    head_office: Building2,
    house: Home,
    facility: Warehouse,
};

const typeColors = {
    head_office: 'bg-blue-500/10 text-blue-400 border-blue-500/30',
    house: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    facility: 'bg-amber-500/10 text-amber-400 border-amber-500/30',
};

function bytes(n?: number | null): string {
    if (!n || n <= 0) return '—';
    const kb = n / 1024;
    if (kb < 1024) return `${kb.toFixed(1)} KB`;
    const mb = kb / 1024;
    return `${mb.toFixed(1)} MB`;
}

export default function SiteShow({ site, clients, assets, contacts, documents, checklist, typeSpecificData, vendors = [], staffRequirements = [], credentialCount = 0, hardwareCount = 0, integrationStatus = [], can_edit, can: assetCan }: Props) {
    const TypeIcon = typeIcons[site.type];
    const percent = Math.round((checklist.filter((c) => c.done).length / Math.max(1, checklist.length)) * 100);
    const page = usePage<any>();
    const canGlobal = page.props?.auth?.can;
    const canSeeVendorsCredentials = !!(canGlobal?.vendors?.view || canGlobal?.credentials?.view);

    // Checklist for onboarding
    const isOnboardingComplete = !!site.onboarding_completed_at;

    // Collapsible setup completeness — default collapsed when 100%, expanded otherwise
    const [setupExpanded, setSetupExpanded] = useState(!isOnboardingComplete || percent < 100);

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }]}>
            <Head title={site.name} />

            <PageShell>
                {/* Header with badges */}
                <div className="flex flex-col gap-4">
                    <PageHeader
                        title={site.name}
                        description={site.address || '—'}
                        actions={
                            <div className="flex items-center gap-2">
                                <Badge variant="outline" className={typeColors[site.type]}>
                                    <TypeIcon className="w-3 h-3 mr-1" />
                                    {site.display_type}
                                </Badge>
                                {site.is_high_risk && (
                                    <Badge variant="outline" className="border-orange-500/50 text-orange-400 bg-orange-500/10">
                                        <AlertTriangle className="w-3 h-3 mr-1" />
                                        High Risk
                                    </Badge>
                                )}
                                {site.is_high_needs && (
                                    <Badge variant="outline" className="border-yellow-500/50 text-yellow-400 bg-yellow-500/10">
                                        <AlertCircle className="w-3 h-3 mr-1" />
                                        High Needs
                                    </Badge>
                                )}
                                <Badge
                                    variant="outline"
                                    className={site.is_active
                                        ? 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10'
                                        : 'border-slate-500/30 text-slate-400'
                                    }
                                >
                                    {site.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                                {can_edit && (
                                    <Button asChild variant="secondary" size="sm">
                                        <Link href={`/sites/${site.id}/edit`}>Edit</Link>
                                    </Button>
                                )}
                            </div>
                        }
                    />

                    {/* Onboarding progress banner */}
                    {!isOnboardingComplete && (
                        <Card className="bg-indigo-500/5 border-indigo-500/20">
                            <CardContent className="flex items-center justify-between py-4">
                                <div className="flex items-center gap-3">
                                    <PlayCircle className="w-8 h-8 text-indigo-400" />
                                    <div>
                                        <div className="font-medium text-indigo-200">Site Onboarding in Progress</div>
                                        <div className="text-sm text-slate-400">
                                            Complete the onboarding wizard to set up this site fully
                                        </div>
                                    </div>
                                </div>
                                <Button asChild>
                                    <Link href={`/sites/${site.id}/onboarding`}>Continue Onboarding</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Setup completeness */}
                <Card className={isOnboardingComplete ? 'border-emerald-500/30 bg-emerald-500/5' : ''}>
                    <CardHeader className="pb-3 cursor-pointer select-none" onClick={() => setSetupExpanded((v) => !v)}>
                        <div className="flex items-center justify-between">
                            <CardTitle className="flex items-center gap-2">
                                {isOnboardingComplete ? (
                                    <>
                                        <CheckCircle2 className="w-5 h-5 text-emerald-400" />
                                        <span>Setup Complete</span>
                                    </>
                                ) : (
                                    'Setup Completeness'
                                )}
                            </CardTitle>
                            <div className="flex items-center gap-3">
                                <span className={`text-sm font-medium ${isOnboardingComplete ? 'text-emerald-400' : 'text-slate-300'}`}>
                                    {checklist.filter((c) => c.done).length} of {checklist.length} items ({percent}%)
                                </span>
                                {isOnboardingComplete && (
                                    <Badge variant="outline" className="border-emerald-500/30 text-emerald-400 bg-emerald-500/10">
                                        Ready
                                    </Badge>
                                )}
                                {setupExpanded ? (
                                    <ChevronUp className="w-4 h-4 text-muted-foreground" />
                                ) : (
                                    <ChevronDown className="w-4 h-4 text-muted-foreground" />
                                )}
                            </div>
                        </div>
                        {/* Always-visible progress bar */}
                        <div className="w-full mt-3">
                            <div className="h-2.5 w-full rounded-full bg-muted overflow-hidden">
                                <div
                                    className={`h-full rounded-full transition-all duration-500 ${
                                        percent === 100
                                            ? 'bg-emerald-500'
                                            : percent >= 70
                                                ? 'bg-indigo-500'
                                                : percent >= 40
                                                    ? 'bg-amber-500'
                                                    : 'bg-slate-500'
                                    }`}
                                    style={{ width: `${percent}%` }}
                                />
                            </div>
                        </div>
                    </CardHeader>
                    {setupExpanded && (
                        <CardContent>
                            <div className="space-y-4">
                                {/* Checklist items */}
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {checklist.map((item) => (
                                        <div
                                            key={item.key}
                                            className={`flex items-center gap-2 text-sm ${
                                                item.done ? 'text-emerald-300' : 'text-slate-500'
                                            }`}
                                        >
                                            <div className={`flex-shrink-0 w-5 h-5 rounded-full flex items-center justify-center ${
                                                item.done
                                                    ? 'bg-emerald-500/20 text-emerald-400'
                                                    : 'bg-muted text-muted-foreground'
                                            }`}>
                                                {item.done ? (
                                                    <CheckCircle2 className="w-3.5 h-3.5" />
                                                ) : (
                                                    <Circle className="w-3.5 h-3.5" />
                                                )}
                                            </div>
                                            <span className={item.done ? '' : 'opacity-70'}>
                                                {item.label}
                                            </span>
                                        </div>
                                    ))}
                                </div>

                                {/* Summary */}
                                <div className="pt-3 border-t flex items-center justify-between text-xs text-muted-foreground">
                                    <span>
                                        {checklist.filter((c) => c.done).length} of {checklist.length} items completed
                                    </span>
                                    {isOnboardingComplete && (
                                        <span className="text-emerald-400 flex items-center gap-1">
                                            <CheckCircle2 className="w-3.5 h-3.5" />
                                            Site is fully configured
                                        </span>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    )}
                </Card>

                {/* Main Tabs */}
                <Tabs defaultValue="overview" className="space-y-4">
                    <TabsList className="flex h-auto gap-1 overflow-x-auto pb-1 scrollbar-hide w-full justify-start">
                        <TabsTrigger value="overview" className="flex items-center gap-1">
                            <LayoutGrid className="w-4 h-4" />
                            Overview
                        </TabsTrigger>
                        <TabsTrigger value="clients" className="flex items-center gap-1">
                            <Users className="w-4 h-4" />
                            Clients ({clients.length})
                        </TabsTrigger>
                        <TabsTrigger value="assets" className="flex items-center gap-1">
                            <Package className="w-4 h-4" />
                            Assets ({assets.length})
                        </TabsTrigger>
                        <TabsTrigger value="contacts" className="flex items-center gap-1">
                            <FileText className="w-4 h-4" />
                            Contacts ({contacts.length})
                        </TabsTrigger>
                        <TabsTrigger value="documents" className="flex items-center gap-1">
                            <FileText className="w-4 h-4" />
                            Documents ({documents.length})
                        </TabsTrigger>
                        <TabsTrigger value="calendar" className="flex items-center gap-1">
                            <Calendar className="w-4 h-4" />
                            Calendar
                        </TabsTrigger>
                        <TabsTrigger value="checklists" className="flex items-center gap-1">
                            <ClipboardCheck className="w-4 h-4" />
                            Checklists
                        </TabsTrigger>
                        <TabsTrigger value="hazards" className="flex items-center gap-1">
                            <ShieldAlert className="w-4 h-4" />
                            Hazards
                        </TabsTrigger>
                        {canSeeVendorsCredentials && (
                            <TabsTrigger value="vendors-credentials" className="flex items-center gap-1">
                                <Truck className="w-4 h-4" />
                                Vendors & Credentials
                            </TabsTrigger>
                        )}
                        <TabsTrigger value="hardware" className="flex items-center gap-1">
                            <Cpu className="w-4 h-4" />
                            Hardware
                            {hardwareCount > 0 && (
                                <Badge variant="outline" className="ml-1 text-xs px-1.5 py-0">{hardwareCount}</Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="type-specific" className="flex items-center gap-1">
                            {site.type === 'house' && <BedDouble className="w-4 h-4" />}
                            {site.type === 'head_office' && <DoorOpen className="w-4 h-4" />}
                            {site.type === 'facility' && <LayoutGrid className="w-4 h-4" />}
                            {site.type === 'house' ? 'Rooms' : site.type === 'head_office' ? 'Resources' : 'Zones'}
                        </TabsTrigger>
                        <TabsTrigger value="staff-requirements" className="flex items-center gap-1">
                            <GraduationCap className="w-4 h-4" />
                            Staff Requirements
                            {staffRequirements.length > 0 && (
                                <Badge variant="outline" className="ml-1 text-xs px-1.5 py-0">{staffRequirements.length}</Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="service-contexts" className="flex items-center gap-1">
                            <Layers className="w-4 h-4" />
                            Services
                            {(site.service_contexts ?? []).length > 0 && (
                                <Badge variant="outline" className="ml-1 text-xs px-1.5 py-0">{(site.service_contexts ?? []).length}</Badge>
                            )}
                        </TabsTrigger>
                    </TabsList>

                    {/* Overview Tab */}
                    <TabsContent value="overview" className="space-y-4">
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Contact Information</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-0 text-sm">
                                    <div className="flex items-center justify-between py-3 border-b border-slate-700/50 last:border-0">
                                        <div className="text-slate-400">Phone</div>
                                        <div>{site.phone || <span className="italic text-slate-500">—</span>}</div>
                                    </div>
                                    <div className="flex items-center justify-between py-3 border-b border-slate-700/50 last:border-0">
                                        <div className="text-slate-400">Email</div>
                                        <div>{site.email || <span className="italic text-slate-500">—</span>}</div>
                                    </div>
                                    <div className="flex items-center justify-between py-3 border-b border-slate-700/50 last:border-0">
                                        <div className="text-slate-400">Site Lead</div>
                                        <div>{site.primary_contact?.name || site.manager_name || <span className="italic text-slate-500">—</span>}</div>
                                    </div>
                                    <div className="flex items-center justify-between py-3 border-b border-slate-700/50 last:border-0">
                                        <div className="text-slate-400">Manager Phone</div>
                                        <div>{site.manager_phone || <span className="italic text-slate-500">—</span>}</div>
                                    </div>
                                    <div className="flex items-center justify-between py-3 border-b border-slate-700/50 last:border-0">
                                        <div className="text-slate-400">After hours</div>
                                        <div>{site.after_hours_phone || <span className="italic text-slate-500">—</span>}</div>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Location</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div>
                                        <div className="text-slate-400">Address</div>
                                        <div className="mt-1">{site.address || <span className="italic text-slate-500">—</span>}</div>
                                    </div>
                                    {site.region && (
                                        <div>
                                            <div className="text-slate-400">Region</div>
                                            <div className="mt-1">{site.region}</div>
                                        </div>
                                    )}
                                    {(site.latitude && site.longitude) && (
                                        <div>
                                            <div className="text-slate-400">GPS Coordinates</div>
                                            <div className="mt-1 font-mono text-xs">
                                                {site.latitude}, {site.longitude}
                                            </div>
                                        </div>
                                    )}
                                    {site.access_instructions && (
                                        <div>
                                            <div className="text-slate-400">Access Instructions</div>
                                            <div className="mt-1 text-slate-300 whitespace-pre-wrap">{site.access_instructions}</div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Safety & Medication</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div>
                                        <div className="text-slate-400">Emergency plan location</div>
                                        <div className="mt-1">{site.emergency_plan_location || <span className="italic text-slate-500">—</span>}</div>
                                    </div>
                                    <div>
                                        <div className="text-slate-400">Medication storage location</div>
                                        <div className="mt-1">{site.medication_storage_location || <span className="italic text-slate-500">—</span>}</div>
                                    </div>
                                    {(site.is_high_risk || site.is_high_needs) && (
                                        <>
                                            <div className="pt-2 border-t">
                                                <div className="text-amber-400 font-medium flex items-center gap-1">
                                                    <AlertTriangle className="w-4 h-4" />
                                                    Risk Information
                                                </div>
                                                {site.risk_notes && (
                                                    <div className="mt-1 text-slate-300">{site.risk_notes}</div>
                                                )}
                                                {site.risk_review_date && (
                                                    <div className="mt-1 text-xs text-slate-400">
                                                        Review due: {site.risk_review_date}
                                                    </div>
                                                )}
                                            </div>
                                        </>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="text-sm whitespace-pre-wrap text-slate-300">
                                        {site.notes || 'No notes recorded.'}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Clients Tab */}
                    <TabsContent value="clients">
                        <Card>
                            <CardHeader>
                                <CardTitle>Clients at this site</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {clients.length === 0 ? (
                                    <div className="text-sm text-slate-400">No clients linked to this site yet.</div>
                                ) : (
                                    <div className="overflow-hidden rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-slate-50/5">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">Client</th>
                                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                                    <th className="px-4 py-3" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {clients.map((c) => (
                                                    <tr key={c.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                        <td className="px-4 py-3 font-medium">{`${c.first_name} ${c.last_name}`.trim()}</td>
                                                        <td className="px-4 py-3 text-slate-300">{c.status}</td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link href={`/clients/${c.id}`} className="text-indigo-300 hover:text-indigo-200">
                                                                View
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Assets Tab */}
                    <TabsContent value="assets">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Assets at this site</CardTitle>
                                {assetCan?.createAsset && (
                                    <Button asChild variant="secondary" size="sm">
                                        <Link href={`/assets/create?site_id=${site.id}`}>Add Asset</Link>
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {assets.length === 0 ? (
                                    <div className="text-sm text-slate-400">No assets linked to this site yet.</div>
                                ) : (
                                    <div className="overflow-hidden rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-slate-50/5">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">Asset</th>
                                                    <th className="px-4 py-3 text-left font-medium">Owner</th>
                                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                                    <th className="px-4 py-3 text-left font-medium">Risk</th>
                                                    <th className="px-4 py-3" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {assets.map((a) => (
                                                    <tr key={a.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                        <td className="px-4 py-3">
                                                            <div className="font-medium">{a.name}</div>
                                                            <div className="text-xs text-slate-400">
                                                                {[a.asset_tag, a.category, a.location].filter(Boolean).join(' • ') || '—'}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-300">
                                                            <Badge variant="outline" className={a.owner.type === 'client' ? 'border-indigo-500/30 text-indigo-200' : 'border-slate-500/30 text-slate-300'}>
                                                                {a.owner.type === 'client' ? `Client: ${a.owner.label}` : 'Site-owned'}
                                                            </Badge>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-300">{a.status}</td>
                                                        <td className="px-4 py-3 text-slate-300">{a.risk_level}</td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link href={`/assets/${a.id}`} className="text-indigo-300 hover:text-indigo-200">
                                                                View
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Contacts Tab */}
                    <TabsContent value="contacts">
                        <ContactsTab site={site} contacts={contacts} can_edit={can_edit} />
                    </TabsContent>

                    {/* Documents Tab */}
                    <TabsContent value="documents">
                        <DocumentsTab site={site} documents={documents} can_edit={can_edit} />
                    </TabsContent>

                    {/* Calendar Tab */}
                    <TabsContent value="calendar">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Site Calendar</CardTitle>
                                <Button asChild>
                                    <Link href={`/sites/${site.id}/calendar`}>View Full Calendar</Link>
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <div className="text-center py-8 text-slate-400">
                                    <Calendar className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                    <p>Calendar events will appear here</p>
                                    <Button asChild variant="outline" className="mt-4">
                                        <Link href={`/sites/${site.id}/calendar`}>Open Calendar</Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Checklists Tab */}
                    <TabsContent value="checklists">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Checklists & Walkthroughs</CardTitle>
                                <Button asChild>
                                    <Link href={`/sites/${site.id}/checklists`}>View All Checklists</Link>
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <div className="text-center py-8 text-slate-400">
                                    <ClipboardCheck className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                    <p>Scheduled checklists and completed runs</p>
                                    <Button asChild variant="outline" className="mt-4">
                                        <Link href={`/sites/${site.id}/checklists`}>Manage Checklists</Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Hazards Tab */}
                    <TabsContent value="hazards">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Hazards Register</CardTitle>
                                <Button asChild>
                                    <Link href={`/sites/${site.id}/hazards`}>View All Hazards</Link>
                                </Button>
                            </CardHeader>
                            <CardContent>
                                <div className="text-center py-8 text-slate-400">
                                    <ShieldAlert className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                    <p>Logged hazards and risk assessments</p>
                                    <div className="flex justify-center gap-2 mt-4">
                                        <Button asChild variant="outline">
                                            <Link href={`/sites/${site.id}/hazards`}>View Hazards</Link>
                                        </Button>
                                        <Button asChild>
                                            <Link href={`/sites/${site.id}/hazards?action=add`}>Log Hazard</Link>
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Vendors & Credentials Tab */}
                    {canSeeVendorsCredentials && (
                        <TabsContent value="vendors-credentials">
                            <div className="space-y-4">
                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <CardTitle className="text-base">Vendors ({vendors.length})</CardTitle>
                                        {canGlobal?.vendors?.view && (
                                            <Button asChild size="sm">
                                                <Link href={`/sites/${site.id}/vendors`}>Manage Vendors</Link>
                                            </Button>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        {vendors.length === 0 ? (
                                            <p className="text-sm text-slate-400">No vendors registered for this site.</p>
                                        ) : (
                                            <div className="space-y-2">
                                                {vendors.map((v) => (
                                                    <div key={v.id} className="flex items-center justify-between p-2 rounded-lg border">
                                                        <div>
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-medium text-sm">{v.company_name}</span>
                                                                {v.is_preferred && (
                                                                    <Badge variant="outline" className="border-yellow-500/30 text-yellow-400 text-xs">Preferred</Badge>
                                                                )}
                                                            </div>
                                                            <div className="text-xs text-slate-400">{v.service_type}</div>
                                                        </div>
                                                        {v.phone && (
                                                            <a href={`tel:${v.phone}`} className="text-sm text-indigo-400 hover:text-indigo-300">{v.phone}</a>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader className="flex flex-row items-center justify-between">
                                        <CardTitle className="text-base">Credentials ({credentialCount})</CardTitle>
                                        {canGlobal?.credentials?.view && (
                                            <Button asChild size="sm" variant="secondary">
                                                <Link href={`/sites/${site.id}/credentials`}>Manage Credentials</Link>
                                            </Button>
                                        )}
                                    </CardHeader>
                                    <CardContent>
                                        {credentialCount === 0 ? (
                                            <p className="text-sm text-slate-400">No credentials stored for this site.</p>
                                        ) : (
                                            <p className="text-sm text-slate-300">
                                                {credentialCount} credential{credentialCount !== 1 ? 's' : ''} securely stored. Open the Credentials Vault to view or manage them.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        </TabsContent>
                    )}

                    {/* Hardware Tab */}
                    <TabsContent value="hardware">
                        <Card>
                            <CardContent className="p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <div>
                                        <h3 className="font-medium flex items-center gap-2">
                                            <Cpu className="w-4 h-4" />
                                            Location Hardware & Configuration
                                        </h3>
                                        <p className="text-sm text-slate-400 mt-1">
                                            {hardwareCount} device{hardwareCount !== 1 ? 's' : ''} registered
                                            {integrationStatus.length > 0 && (
                                                <> · {integrationStatus.length} integration{integrationStatus.length !== 1 ? 's' : ''} active</>
                                            )}
                                        </p>
                                    </div>
                                    <Button asChild>
                                        <Link href={`/sites/${site.id}/hardware`}>
                                            Manage Hardware
                                        </Link>
                                    </Button>
                                </div>
                                {integrationStatus.length > 0 && (
                                    <div className="flex gap-2 mb-4">
                                        {integrationStatus.map((i) => (
                                            <Badge key={i.provider} variant="outline" className={
                                                i.status === 'hybrid' ? 'border-emerald-500/30 text-emerald-400' :
                                                i.status === 'tenant_only' ? 'border-blue-500/30 text-blue-400' :
                                                'border-slate-500/30 text-slate-400'
                                            }>
                                                {i.provider.charAt(0).toUpperCase() + i.provider.slice(1)}: {i.status.replace('_', ' ')}
                                            </Badge>
                                        ))}
                                    </div>
                                )}
                                {hardwareCount === 0 && integrationStatus.length === 0 && (
                                    <div className="text-center py-8 text-slate-400">
                                        <Cpu className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                        <p>No hardware registered for this site</p>
                                        <p className="text-sm mt-1">Add devices manually or connect an integration to auto-discover hardware</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Type-Specific Tab */}
                    <TabsContent value="type-specific">
                        <TypeSpecificTab site={site} data={typeSpecificData} />
                    </TabsContent>

                    {/* Staff Requirements Tab */}
                    <TabsContent value="staff-requirements">
                        <StaffRequirementsTab site={site} requirements={staffRequirements} can_edit={can_edit} />
                    </TabsContent>

                    {/* Service Contexts Tab */}
                    <TabsContent value="service-contexts">
                        <Card>
                            <CardHeader>
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="flex items-center gap-2">
                                            <Layers className="h-5 w-5 text-violet-600" />
                                            Service Contexts
                                        </CardTitle>
                                        <p className="text-sm text-muted-foreground mt-1">Services delivered from this site</p>
                                    </div>
                                    <Link href="/settings/service-contexts">
                                        <Button variant="outline" size="sm">Manage in Settings</Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                {(site.service_contexts ?? []).length === 0 ? (
                                    <div className="text-center py-8 text-muted-foreground">
                                        <Layers className="h-10 w-10 mx-auto mb-2 opacity-30" />
                                        <p className="font-medium">No service contexts linked</p>
                                        <p className="text-sm mt-1">Link service contexts to this site in Settings → Service Contexts</p>
                                    </div>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {(site.service_contexts ?? []).map((ctx: any) => (
                                            <div key={ctx.id} className="rounded-lg border border-l-4 border-l-violet-500 p-4 space-y-2">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">{ctx.name}</span>
                                                    {ctx.is_active ? (
                                                        <Badge className="bg-emerald-100 text-emerald-700 text-xs">Active</Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-xs">Inactive</Badge>
                                                    )}
                                                </div>
                                                {ctx.type && (
                                                    <Badge variant="secondary" className="text-xs">{ctx.type.replace(/_/g, ' ')}</Badge>
                                                )}
                                                {ctx.description && (
                                                    <p className="text-sm text-muted-foreground line-clamp-2">{ctx.description}</p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}

// Sub-components for cleaner code
function ContactsTab({ site, contacts, can_edit }: { site: Site; contacts: Contact[]; can_edit: boolean }) {
    const [editingContactId, setEditingContactId] = useState<number | null>(null);
    const contactForm = useForm({
        type: 'emergency',
        name: '',
        role: '',
        phone: '',
        email: '',
        is_primary: false,
        notes: '',
    });

    function startEditContact(c: Contact) {
        setEditingContactId(c.id);
        contactForm.setData({
            type: c.type || '',
            name: c.name || '',
            role: c.role || '',
            phone: c.phone || '',
            email: c.email || '',
            is_primary: !!c.is_primary,
            notes: c.notes || '',
        });
    }

    function resetContactForm() {
        setEditingContactId(null);
        contactForm.reset();
    }

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Site contacts</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {contacts.length === 0 ? (
                        <div className="text-sm text-slate-400">No contacts yet.</div>
                    ) : (
                        <div className="space-y-2">
                            {contacts.map((c) => (
                                <div key={c.id} className="rounded-xl border p-3 text-sm">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <div className="font-medium">
                                                {c.name}{' '}
                                                {c.is_primary && (
                                                    <Badge variant="outline" className="ml-2 border-emerald-500/30 text-emerald-300">
                                                        Primary
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="text-slate-400">{[c.type, c.role].filter(Boolean).join(' • ') || '—'}</div>
                                        </div>
                                        {can_edit && (
                                            <div className="flex items-center gap-2">
                                                <Button variant="secondary" size="sm" onClick={() => startEditContact(c)}>
                                                    Edit
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    <div className="mt-2 grid gap-1 text-slate-300">
                                        <div>{c.phone || '—'}</div>
                                        <div>{c.email || '—'}</div>
                                        {c.notes && <div className="mt-1 whitespace-pre-wrap text-slate-400">{c.notes}</div>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            {can_edit && (
                <Card>
                    <CardHeader>
                        <CardTitle>{editingContactId ? 'Edit contact' : 'Add contact'}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                const url = editingContactId
                                    ? `/sites/${site.id}/contacts/${editingContactId}`
                                    : `/sites/${site.id}/contacts`;
                                const method = editingContactId ? contactForm.put : contactForm.post;
                                method(url, {
                                    preserveScroll: true,
                                    onSuccess: () => resetContactForm(),
                                });
                            }}
                            className="space-y-3"
                        >
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label>Type</Label>
                                    <Input value={contactForm.data.type} onChange={(e) => contactForm.setData('type', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Role</Label>
                                    <Input value={contactForm.data.role} onChange={(e) => contactForm.setData('role', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Name</Label>
                                    <Input value={contactForm.data.name} onChange={(e) => contactForm.setData('name', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Phone</Label>
                                    <Input value={contactForm.data.phone} onChange={(e) => contactForm.setData('phone', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Email</Label>
                                    <Input value={contactForm.data.email} onChange={(e) => contactForm.setData('email', e.target.value)} />
                                </div>
                                <div className="flex items-end gap-2">
                                    <input
                                        type="checkbox"
                                        checked={contactForm.data.is_primary}
                                        onChange={(e) => contactForm.setData('is_primary', e.target.checked)}
                                    />
                                    <span className="text-sm">Primary</span>
                                </div>
                            </div>
                            <div>
                                <Label>Notes</Label>
                                <Textarea value={contactForm.data.notes} onChange={(e) => contactForm.setData('notes', e.target.value)} />
                            </div>
                            <div className="flex items-center gap-2">
                                <Button type="submit" disabled={contactForm.processing}>
                                    {contactForm.processing ? 'Saving…' : editingContactId ? 'Save changes' : 'Add contact'}
                                </Button>
                                {editingContactId && (
                                    <Button type="button" variant="secondary" onClick={() => resetContactForm()}>
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

function DocumentsTab({ site, documents, can_edit }: { site: Site; documents: Doc[]; can_edit: boolean }) {
    const docForm = useForm({
        file: null as File | null,
        title: '',
        category: 'evacuation_plan',
        version: '',
        effective_date: '',
        expiry_date: '',
        notes: '',
    });

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Site documents</CardTitle>
                </CardHeader>
                <CardContent>
                    {documents.length === 0 ? (
                        <div className="text-sm text-slate-400">No documents uploaded yet.</div>
                    ) : (
                        <div className="overflow-hidden rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-slate-50/5">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">Title</th>
                                        <th className="px-4 py-3 text-left font-medium">Category</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {documents.map((d) => (
                                        <tr key={d.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                            <td className="px-4 py-3 font-medium">{d.title || d.original_name}</td>
                                            <td className="px-4 py-3 text-slate-300">{d.category || '—'}</td>
                                            <td className="px-4 py-3 text-right">
                                                <Link href={`/sites/${site.id}/documents/${d.id}/download`} className="text-indigo-300 hover:text-indigo-200">
                                                    Download
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {can_edit && (
                <Card>
                    <CardHeader>
                        <CardTitle>Upload document</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                docForm.post(`/sites/${site.id}/documents`, {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => docForm.reset(),
                                });
                            }}
                            className="space-y-3"
                        >
                            <div>
                                <Label>File</Label>
                                <Input type="file" onChange={(e) => docForm.setData('file', e.target.files?.[0] || null)} />
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label>Title</Label>
                                    <Input value={docForm.data.title} onChange={(e) => docForm.setData('title', e.target.value)} />
                                </div>
                                <div>
                                    <Label>Category</Label>
                                    <Input value={docForm.data.category} onChange={(e) => docForm.setData('category', e.target.value)} />
                                </div>
                            </div>
                            <Button type="submit" disabled={docForm.processing}>
                                {docForm.processing ? 'Uploading…' : 'Upload'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

function TypeSpecificTab({ site, data }: { site: Site; data: TypeSpecificData }) {
    if (site.type === 'house') {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <CardTitle className="flex items-center gap-2">
                        <BedDouble className="w-5 h-5" />
                        Bedrooms
                    </CardTitle>
                    <Button asChild variant="outline" size="sm">
                        <Link href={`/sites/${site.id}/rooms`}>Manage Rooms</Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    {!data.rooms || data.rooms.length === 0 ? (
                        <div className="text-center py-8 text-slate-400">
                            <BedDouble className="w-12 h-12 mx-auto mb-3 opacity-50" />
                            <p>No bedrooms configured yet</p>
                            <Button asChild className="mt-4">
                                <Link href={`/sites/${site.id}/onboarding?step=rooms`}>Add Bedrooms</Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {data.rooms.map((room) => (
                                <Card key={room.id} className="bg-muted/50">
                                    <CardContent className="p-4">
                                        <div className="font-medium">{room.name}</div>
                                        {room.assigned_client ? (
                                            <Badge variant="outline" className="mt-2 border-indigo-500/30 text-indigo-300">
                                                Assigned: {room.assigned_client.name}
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline" className="mt-2 border-slate-500/30 text-slate-400">
                                                Available
                                            </Badge>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        );
    }

    if (site.type === 'head_office') {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <CardTitle className="flex items-center gap-2">
                        <DoorOpen className="w-5 h-5" />
                        Rooms & Resources
                    </CardTitle>
                    <Button asChild variant="outline" size="sm">
                        <Link href={`/sites/${site.id}/resources`}>Manage Resources</Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    {!data.resources || data.resources.length === 0 ? (
                        <div className="text-center py-8 text-slate-400">
                            <DoorOpen className="w-12 h-12 mx-auto mb-3 opacity-50" />
                            <p>No rooms or resources configured yet</p>
                            <Button asChild className="mt-4">
                                <Link href={`/sites/${site.id}/onboarding?step=resources`}>Add Resources</Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {data.resources.map((resource) => (
                                <Card key={resource.id} className="bg-muted/50">
                                    <CardContent className="p-4">
                                        <div className="font-medium">{resource.name}</div>
                                        <div className="text-sm text-slate-400 mt-1 capitalize">{resource.type.replace('_', ' ')}</div>
                                        {resource.capacity && (
                                            <div className="text-xs text-slate-500 mt-1">Capacity: {resource.capacity}</div>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        );
    }

    // Facility zones
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle className="flex items-center gap-2">
                    <LayoutGrid className="w-5 h-5" />
                    Areas & Zones
                </CardTitle>
                <Button asChild variant="outline" size="sm">
                    <Link href={`/sites/${site.id}/zones`}>Manage Zones</Link>
                </Button>
            </CardHeader>
            <CardContent>
                {!data.zones || data.zones.length === 0 ? (
                    <div className="text-center py-8 text-slate-400">
                        <LayoutGrid className="w-12 h-12 mx-auto mb-3 opacity-50" />
                        <p>No zones configured yet</p>
                        <Button asChild className="mt-4">
                            <Link href={`/sites/${site.id}/onboarding?step=zones`}>Add Zones</Link>
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {data.zones.map((zone) => (
                            <Card key={zone.id} className="bg-muted/50">
                                <CardContent className="p-4">
                                    <div className="font-medium">{zone.name}</div>
                                    {zone.type && <div className="text-sm text-slate-400 mt-1">{zone.type}</div>}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

const PRESET_REQUIREMENTS = [
    { requirement_name: 'First Aid Certificate', category: 'mandatory' as const, description: 'Current first aid certificate (NZQA Level 2 or equivalent)', certification_required: true, expiry_period_months: 24 },
    { requirement_name: 'Medication Competency', category: 'mandatory' as const, description: 'Competency assessment for medication administration', certification_required: true, expiry_period_months: 12 },
    { requirement_name: 'Manual Handling', category: 'mandatory' as const, description: 'Safe manual handling and transfer techniques training', certification_required: true, expiry_period_months: 24 },
    { requirement_name: 'Positive Behaviour Support', category: 'specialist' as const, description: 'PBS training for managing challenging behaviours', certification_required: true, expiry_period_months: 12 },
    { requirement_name: 'Cultural Safety', category: 'mandatory' as const, description: 'Cultural competency training including Te Tiriti o Waitangi awareness', certification_required: true, expiry_period_months: 36 },
    { requirement_name: 'Restricted Practices', category: 'specialist' as const, description: 'Training in use and minimisation of restricted practices', certification_required: true, expiry_period_months: 12 },
];

const categoryConfig = {
    mandatory: { label: 'Mandatory', color: 'border-red-500/30 text-red-300 bg-red-500/10', icon: Shield },
    recommended: { label: 'Recommended', color: 'border-amber-500/30 text-amber-300 bg-amber-500/10', icon: Star },
    specialist: { label: 'Specialist', color: 'border-indigo-500/30 text-indigo-300 bg-indigo-500/10', icon: Award },
};

function StaffRequirementsTab({ site, requirements, can_edit }: { site: Site; requirements: StaffRequirement[]; can_edit: boolean }) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const form = useForm({
        requirement_name: '',
        category: 'mandatory',
        description: '',
        certification_required: false,
        expiry_period_months: '' as string | number,
    });

    const grouped = useMemo(() => {
        const groups: Record<string, StaffRequirement[]> = { mandatory: [], recommended: [], specialist: [] };
        requirements.forEach((r) => {
            if (groups[r.category]) groups[r.category].push(r);
        });
        return groups;
    }, [requirements]);

    function applyPreset(preset: typeof PRESET_REQUIREMENTS[0]) {
        form.setData({
            requirement_name: preset.requirement_name,
            category: preset.category,
            description: preset.description,
            certification_required: preset.certification_required,
            expiry_period_months: preset.expiry_period_months,
        });
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/sites/${site.id}/staff-requirements`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setDialogOpen(false);
            },
        });
    }

    function deleteRequirement(id: number) {
        if (!confirm('Remove this requirement?')) return;
        form.delete(`/sites/${site.id}/staff-requirements/${id}`, { preserveScroll: true });
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h3 className="text-lg font-medium">Staff Competency Requirements</h3>
                {can_edit && (
                    <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                        <DialogTrigger asChild>
                            <Button size="sm">
                                <Plus className="w-4 h-4 mr-1" />
                                Add Requirement
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="max-w-lg">
                            <DialogHeader>
                                <DialogTitle>Add Staff Requirement</DialogTitle>
                            </DialogHeader>

                            {/* Preset buttons */}
                            <div className="space-y-2">
                                <Label className="text-xs text-slate-400">Quick-add common NZ requirements:</Label>
                                <div className="flex flex-wrap gap-1">
                                    {PRESET_REQUIREMENTS.filter(
                                        (p) => !requirements.some((r) => r.requirement_name === p.requirement_name)
                                    ).map((p) => (
                                        <Button key={p.requirement_name} variant="outline" size="sm" className="text-xs" onClick={() => applyPreset(p)}>
                                            {p.requirement_name}
                                        </Button>
                                    ))}
                                </div>
                            </div>

                            <form onSubmit={submit} className="space-y-3 mt-2">
                                <div>
                                    <Label>Requirement Name</Label>
                                    <Input value={form.data.requirement_name} onChange={(e) => form.setData('requirement_name', e.target.value)} required />
                                </div>
                                <div>
                                    <Label>Category</Label>
                                    <Select value={form.data.category} onValueChange={(v) => form.setData('category', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="mandatory">Mandatory</SelectItem>
                                            <SelectItem value="recommended">Recommended</SelectItem>
                                            <SelectItem value="specialist">Specialist</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={2} />
                                </div>
                                <div className="flex items-center gap-3">
                                    <Switch checked={form.data.certification_required} onCheckedChange={(v) => form.setData('certification_required', v)} />
                                    <Label>Certification Required</Label>
                                </div>
                                <div>
                                    <Label>Expiry Period (months)</Label>
                                    <Input
                                        type="number"
                                        min={1}
                                        value={form.data.expiry_period_months}
                                        onChange={(e) => form.setData('expiry_period_months', e.target.value ? parseInt(e.target.value) : '')}
                                        placeholder="e.g. 12, 24"
                                    />
                                </div>
                                <Button type="submit" disabled={form.processing} className="w-full">
                                    {form.processing ? 'Adding...' : 'Add Requirement'}
                                </Button>
                            </form>
                        </DialogContent>
                    </Dialog>
                )}
            </div>

            {requirements.length === 0 ? (
                <Card>
                    <CardContent className="py-8 text-center">
                        <GraduationCap className="w-12 h-12 mx-auto mb-3 text-slate-500 opacity-50" />
                        <p className="text-slate-400">No staff requirements configured for this site</p>
                        <p className="text-sm text-slate-500 mt-1">Add mandatory, recommended, and specialist competency requirements</p>
                    </CardContent>
                </Card>
            ) : (
                (['mandatory', 'recommended', 'specialist'] as const).map((cat) => {
                    const items = grouped[cat];
                    if (items.length === 0) return null;
                    const config = categoryConfig[cat];
                    const CatIcon = config.icon;
                    return (
                        <Card key={cat}>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <CatIcon className="w-4 h-4" />
                                    {config.label} Requirements
                                    <Badge variant="outline" className={config.color}>{items.length}</Badge>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {items.map((req) => (
                                        <div key={req.id} className="rounded-lg border p-3 flex items-start justify-between gap-3">
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <span className="font-medium text-sm">{req.requirement_name}</span>
                                                    {req.certification_required && (
                                                        <Badge variant="outline" className="text-xs border-emerald-500/30 text-emerald-300 bg-emerald-500/10">
                                                            <Award className="w-3 h-3 mr-1" />
                                                            Certification
                                                        </Badge>
                                                    )}
                                                    {req.expiry_period_months && (
                                                        <Badge variant="outline" className="text-xs border-slate-500/30 text-slate-400">
                                                            Renew every {req.expiry_period_months}mo
                                                        </Badge>
                                                    )}
                                                </div>
                                                {req.description && (
                                                    <p className="text-sm text-slate-400 mt-1">{req.description}</p>
                                                )}
                                            </div>
                                            {can_edit && (
                                                <Button variant="ghost" size="sm" className="text-slate-500 hover:text-red-400 shrink-0" onClick={() => deleteRequirement(req.id)}>
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    );
                })
            )}
        </div>
    );
}
