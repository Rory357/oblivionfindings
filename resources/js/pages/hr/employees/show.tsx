import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { type BreadcrumbItem } from '@/types';
import { User, Shield, CalendarDays, ClipboardCheck, FileText, CheckCircle2, AlertTriangle, Clock, XCircle } from 'lucide-react';

interface ComplianceStatus {
    id: number;
    requirement_name: string;
    requirement_type: string;
    status: 'compliant' | 'expiring_soon' | 'expired' | 'not_started';
    expiry_date: string | null;
    completed_date: string | null;
    evidence_url: string | null;
}

interface LeaveBalance {
    id: number;
    leave_type: string;
    accrued_hours: number;
    used_hours: number;
    balance_hours: number;
    as_at_date: string;
}

interface OnboardingChecklist {
    id: number;
    name: string;
    items: Array<{
        key: string;
        label: string;
        done: boolean;
        completed_at: string | null;
    }>;
    completed_at: string | null;
}

interface Document {
    id: number;
    title: string;
    category: string | null;
    original_name: string;
    created_at: string;
}

interface Profile {
    id: number;
    employee_number: string | null;
    position_title: string;
    employment_type: string;
    contract_type: string | null;
    is_active: boolean;
    start_date: string | null;
    end_date: string | null;
    probation_end_date: string | null;
    hours_per_week: number | null;
    pay_rate: number | null;
    pay_frequency: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    emergency_contact_relationship: string | null;
    notes: string | null;
    user: { id: number; name: string; email: string };
    primary_site: { id: number; name: string } | null;
    documents: Document[];
    offer: {
        id: number;
        position_title: string;
        status: string;
        proposed_start_date: string | null;
    } | null;
}

interface ComplianceSummary {
    compliant: number;
    expiring_soon: number;
    expired: number;
    not_started: number;
    total: number;
}

interface Props {
    profile: Profile;
    complianceStatuses: ComplianceStatus[];
    complianceSummary: ComplianceSummary;
    leaveBalances: LeaveBalance[];
    onboardingChecklists: OnboardingChecklist[];
    can: { manage: boolean; viewSensitive: boolean };
}

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline'; icon: typeof CheckCircle2 }> = {
    compliant: { label: 'Compliant', variant: 'default', icon: CheckCircle2 },
    expiring_soon: { label: 'Expiring Soon', variant: 'outline', icon: Clock },
    expired: { label: 'Expired', variant: 'destructive', icon: AlertTriangle },
    not_started: { label: 'Not Started', variant: 'secondary', icon: XCircle },
};

export default function EmployeeShow({ profile, complianceStatuses, complianceSummary, leaveBalances, onboardingChecklists, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'People', href: '/hr/people' },
        { title: profile.user.name, href: `/hr/people/${profile.id}` },
    ];

    const compliancePercent = complianceSummary.total > 0
        ? Math.round((complianceSummary.compliant / complianceSummary.total) * 100)
        : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={profile.user.name} />
            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-4">
                        <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
                            <User className="h-7 w-7 text-primary" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold">{profile.user.name}</h1>
                            <p className="text-muted-foreground">{profile.position_title}</p>
                            <div className="mt-1 flex items-center gap-2">
                                <Badge variant={profile.is_active ? 'default' : 'secondary'}>
                                    {profile.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                                <Badge variant="outline">{profile.employment_type?.replace('_', ' ')}</Badge>
                                {profile.primary_site && (
                                    <Badge variant="outline">{profile.primary_site.name}</Badge>
                                )}
                            </div>
                        </div>
                    </div>
                    {can.manage && (
                        <Button asChild>
                            <Link href={`/hr/people/${profile.id}/edit`}>Edit</Link>
                        </Button>
                    )}
                </div>

                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Compliance</p>
                                    <p className="text-2xl font-bold">{compliancePercent}%</p>
                                </div>
                                <Shield className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Expired Items</p>
                                    <p className="text-2xl font-bold text-destructive">{complianceSummary.expired}</p>
                                </div>
                                <AlertTriangle className="h-8 w-8 text-destructive" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Expiring Soon</p>
                                    <p className="text-2xl font-bold text-yellow-600">{complianceSummary.expiring_soon}</p>
                                </div>
                                <Clock className="h-8 w-8 text-yellow-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-muted-foreground">Documents</p>
                                    <p className="text-2xl font-bold">{profile.documents.length}</p>
                                </div>
                                <FileText className="h-8 w-8 text-muted-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Tabs */}
                <Tabs defaultValue="overview">
                    <TabsList>
                        <TabsTrigger value="overview" className="flex items-center gap-1.5">
                            <User className="h-4 w-4" />
                            Overview
                        </TabsTrigger>
                        <TabsTrigger value="compliance" className="flex items-center gap-1.5">
                            <Shield className="h-4 w-4" />
                            Compliance
                        </TabsTrigger>
                        <TabsTrigger value="leave" className="flex items-center gap-1.5">
                            <CalendarDays className="h-4 w-4" />
                            Leave
                        </TabsTrigger>
                        <TabsTrigger value="onboarding" className="flex items-center gap-1.5">
                            <ClipboardCheck className="h-4 w-4" />
                            Onboarding
                        </TabsTrigger>
                    </TabsList>

                    {/* Overview Tab */}
                    <TabsContent value="overview" className="space-y-4">
                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Personal Information</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="text-muted-foreground">Email</div>
                                        <div className="col-span-2">{profile.user.email}</div>
                                        <div className="text-muted-foreground">Employee #</div>
                                        <div className="col-span-2">{profile.employee_number || '\u2014'}</div>
                                        <div className="text-muted-foreground">Start Date</div>
                                        <div className="col-span-2">{profile.start_date || '\u2014'}</div>
                                        {profile.end_date && (
                                            <>
                                                <div className="text-muted-foreground">End Date</div>
                                                <div className="col-span-2">{profile.end_date}</div>
                                            </>
                                        )}
                                        {profile.probation_end_date && (
                                            <>
                                                <div className="text-muted-foreground">Probation Ends</div>
                                                <div className="col-span-2">{profile.probation_end_date}</div>
                                            </>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Employment Details</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="text-muted-foreground">Position</div>
                                        <div className="col-span-2">{profile.position_title}</div>
                                        <div className="text-muted-foreground">Type</div>
                                        <div className="col-span-2 capitalize">{profile.employment_type?.replace('_', ' ')}</div>
                                        {profile.contract_type && (
                                            <>
                                                <div className="text-muted-foreground">Contract</div>
                                                <div className="col-span-2 capitalize">{profile.contract_type.replace('_', ' ')}</div>
                                            </>
                                        )}
                                        <div className="text-muted-foreground">Hours/Week</div>
                                        <div className="col-span-2">{profile.hours_per_week ?? '\u2014'}</div>
                                        <div className="text-muted-foreground">Site</div>
                                        <div className="col-span-2">
                                            {profile.primary_site ? (
                                                <Link href={`/sites/${profile.primary_site.id}`} className="text-primary hover:underline">
                                                    {profile.primary_site.name}
                                                </Link>
                                            ) : '\u2014'}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {can.viewSensitive && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Financial</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3 text-sm">
                                        <div className="grid grid-cols-3 gap-2">
                                            <div className="text-muted-foreground">Pay Rate</div>
                                            <div className="col-span-2">{profile.pay_rate ? `$${profile.pay_rate}` : '\u2014'}</div>
                                            <div className="text-muted-foreground">Pay Frequency</div>
                                            <div className="col-span-2 capitalize">{profile.pay_frequency?.replace('_', ' ') || '\u2014'}</div>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            <Card>
                                <CardHeader>
                                    <CardTitle>Emergency Contact</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="text-muted-foreground">Name</div>
                                        <div className="col-span-2">{profile.emergency_contact_name || '\u2014'}</div>
                                        <div className="text-muted-foreground">Phone</div>
                                        <div className="col-span-2">{profile.emergency_contact_phone || '\u2014'}</div>
                                        <div className="text-muted-foreground">Relationship</div>
                                        <div className="col-span-2">{profile.emergency_contact_relationship || '\u2014'}</div>
                                    </div>
                                </CardContent>
                            </Card>

                            {profile.notes && (
                                <Card className="lg:col-span-2">
                                    <CardHeader>
                                        <CardTitle>Notes</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm whitespace-pre-wrap">{profile.notes}</p>
                                    </CardContent>
                                </Card>
                            )}

                            {profile.documents.length > 0 && (
                                <Card className="lg:col-span-2">
                                    <CardHeader>
                                        <CardTitle>Documents</CardTitle>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <table className="w-full text-sm">
                                            <thead className="border-b bg-muted/50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">Title</th>
                                                    <th className="px-4 py-3 text-left font-medium">Category</th>
                                                    <th className="px-4 py-3 text-left font-medium">Uploaded</th>
                                                    <th className="px-4 py-3" />
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {profile.documents.map((doc) => (
                                                    <tr key={doc.id} className="hover:bg-muted/30">
                                                        <td className="px-4 py-3 font-medium">{doc.title || doc.original_name}</td>
                                                        <td className="px-4 py-3 text-muted-foreground">{doc.category || '\u2014'}</td>
                                                        <td className="px-4 py-3 text-muted-foreground">{doc.created_at}</td>
                                                        <td className="px-4 py-3 text-right">
                                                            <Link href={`/hr/people/${profile.id}/documents/${doc.id}/download`} className="text-primary hover:underline">
                                                                Download
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* Compliance Tab */}
                    <TabsContent value="compliance" className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-4">
                            <Card>
                                <CardContent className="pt-6 text-center">
                                    <CheckCircle2 className="mx-auto h-6 w-6 text-green-500" />
                                    <p className="mt-2 text-2xl font-bold">{complianceSummary.compliant}</p>
                                    <p className="text-sm text-muted-foreground">Compliant</p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6 text-center">
                                    <Clock className="mx-auto h-6 w-6 text-yellow-500" />
                                    <p className="mt-2 text-2xl font-bold">{complianceSummary.expiring_soon}</p>
                                    <p className="text-sm text-muted-foreground">Expiring Soon</p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6 text-center">
                                    <AlertTriangle className="mx-auto h-6 w-6 text-destructive" />
                                    <p className="mt-2 text-2xl font-bold">{complianceSummary.expired}</p>
                                    <p className="text-sm text-muted-foreground">Expired</p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6 text-center">
                                    <XCircle className="mx-auto h-6 w-6 text-muted-foreground" />
                                    <p className="mt-2 text-2xl font-bold">{complianceSummary.not_started}</p>
                                    <p className="text-sm text-muted-foreground">Not Started</p>
                                </CardContent>
                            </Card>
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle>Compliance Requirements</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">Requirement</th>
                                            <th className="px-4 py-3 text-left font-medium">Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                            <th className="px-4 py-3 text-left font-medium">Expiry Date</th>
                                            <th className="px-4 py-3 text-left font-medium">Evidence</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {complianceStatuses.map((cs) => {
                                            const config = statusConfig[cs.status] || statusConfig.not_started;
                                            return (
                                                <tr key={cs.id} className="hover:bg-muted/30">
                                                    <td className="px-4 py-3 font-medium">{cs.requirement_name}</td>
                                                    <td className="px-4 py-3 text-muted-foreground capitalize">{cs.requirement_type?.replace('_', ' ')}</td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant={config.variant}>{config.label}</Badge>
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">{cs.expiry_date || '\u2014'}</td>
                                                    <td className="px-4 py-3">
                                                        {cs.evidence_url ? (
                                                            <a href={cs.evidence_url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                                                                View
                                                            </a>
                                                        ) : (
                                                            <span className="text-muted-foreground">\u2014</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                        {complianceStatuses.length === 0 && (
                                            <tr><td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">No compliance requirements assigned.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Leave Tab */}
                    <TabsContent value="leave" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Leave Balances</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">Leave Type</th>
                                            <th className="px-4 py-3 text-right font-medium">Accrued (hrs)</th>
                                            <th className="px-4 py-3 text-right font-medium">Used (hrs)</th>
                                            <th className="px-4 py-3 text-right font-medium">Balance (hrs)</th>
                                            <th className="px-4 py-3 text-left font-medium">As At</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {leaveBalances.map((lb) => (
                                            <tr key={lb.id} className="hover:bg-muted/30">
                                                <td className="px-4 py-3 font-medium capitalize">{lb.leave_type.replace('_', ' ')}</td>
                                                <td className="px-4 py-3 text-right">{lb.accrued_hours.toFixed(1)}</td>
                                                <td className="px-4 py-3 text-right">{lb.used_hours.toFixed(1)}</td>
                                                <td className="px-4 py-3 text-right font-medium">
                                                    <span className={lb.balance_hours < 0 ? 'text-destructive' : ''}>
                                                        {lb.balance_hours.toFixed(1)}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{lb.as_at_date}</td>
                                            </tr>
                                        ))}
                                        {leaveBalances.length === 0 && (
                                            <tr><td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">No leave balances recorded.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Onboarding Tab */}
                    <TabsContent value="onboarding" className="space-y-4">
                        {onboardingChecklists.length === 0 ? (
                            <Card>
                                <CardContent className="py-8 text-center text-muted-foreground">
                                    <ClipboardCheck className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                    <p>No onboarding checklists assigned.</p>
                                </CardContent>
                            </Card>
                        ) : (
                            onboardingChecklists.map((checklist) => {
                                const doneCount = checklist.items.filter((i) => i.done).length;
                                const totalCount = checklist.items.length;
                                const percent = totalCount > 0 ? Math.round((doneCount / totalCount) * 100) : 0;

                                return (
                                    <Card key={checklist.id}>
                                        <CardHeader>
                                            <div className="flex items-center justify-between">
                                                <CardTitle className="flex items-center gap-2">
                                                    {checklist.name}
                                                    {checklist.completed_at && (
                                                        <Badge variant="default">Completed</Badge>
                                                    )}
                                                </CardTitle>
                                                <span className="text-sm font-medium text-muted-foreground">
                                                    {doneCount}/{totalCount} ({percent}%)
                                                </span>
                                            </div>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="mb-4 h-2 w-full overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary transition-all"
                                                    style={{ width: `${percent}%` }}
                                                />
                                            </div>
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                {checklist.items.map((item) => (
                                                    <div key={item.key} className={`flex items-center gap-2 text-sm ${item.done ? 'text-green-600' : 'text-muted-foreground'}`}>
                                                        {item.done ? (
                                                            <CheckCircle2 className="h-4 w-4 shrink-0" />
                                                        ) : (
                                                            <XCircle className="h-4 w-4 shrink-0" />
                                                        )}
                                                        <span>{item.label}</span>
                                                        {item.completed_at && (
                                                            <span className="text-xs text-muted-foreground">({item.completed_at})</span>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })
                        )}
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
