import {
    Award,
    Building2,
    CalendarDays,
    Flame,
    HeartPulse,
    Loader2,
    Mail,
    MapPin,
    Phone,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Employee {
    id: number;
    user_id: number;
    name: string;
    full_name: string;
    email: string | null;
    work_phone: string | null;
    personal_email: string | null;
    personal_phone: string | null;
    position_title: string | null;
    department: string | null;
    team: string | null;
    site: string | null;
    profile_photo_path: string | null;
    bio: string | null;
    start_date: string | null;
    employment_type: string | null;
    is_first_aider: boolean;
    is_fire_warden: boolean;
}

interface PersonRef {
    id: number;
    name: string;
    position_title: string | null;
    profile_photo_path: string | null;
}

interface Kudos {
    id: number;
    from_name: string;
    category: string | null;
    message: string | null;
    created_at: string | null;
}

interface ComplianceSummary {
    compliant: number;
    expiring_soon: number;
    expired: number;
    not_started: number;
    total: number;
}

interface StaffDetails {
    employee: Employee;
    tenure: { years: number; months: number } | null;
    manager: PersonRef | null;
    directReports: PersonRef[];
    kudosReceived: Kudos[];
    kudosCount: number;
    complianceSummary: ComplianceSummary | null;
    canManage: boolean;
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function tenureLabel(t: { years: number; months: number }): string {
    const parts: string[] = [];
    if (t.years > 0) parts.push(`${t.years}y`);
    if (t.months > 0) parts.push(`${t.months}m`);
    return parts.length > 0 ? parts.join(' ') : 'New';
}

/**
 * Read-only staff-details popup for the People-hub Directory tab. Replaces the
 * heavy full-page profile as the directory's click target — fetches a compact
 * card payload from GET /hr/directory/{profile} (JSON) on open.
 */
export function StaffDetailsModal({
    profileId,
    open,
    onClose,
}: {
    profileId: number | null;
    open: boolean;
    onClose: () => void;
}) {
    const [data, setData] = useState<StaffDetails | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!open || !profileId) return;
        let cancelled = false;
        setLoading(true);
        setError(false);
        setData(null);
        fetch(`/hr/directory/${profileId}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('failed'))))
            .then((json: StaffDetails) => {
                if (!cancelled) setData(json);
            })
            .catch(() => {
                if (!cancelled) setError(true);
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [open, profileId]);

    const emp = data?.employee;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Staff details</DialogTitle>
                </DialogHeader>

                {loading && (
                    <div className="flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground">
                        <Loader2 className="size-4 animate-spin" />
                        Loading…
                    </div>
                )}

                {error && !loading && (
                    <div className="py-12 text-center text-sm text-muted-foreground">
                        Couldn’t load this person’s details. Please try again.
                    </div>
                )}

                {emp && !loading && data && (
                    <div className="space-y-5">
                        {/* Identity */}
                        <div className="flex items-start gap-4">
                            <Avatar className="size-16">
                                <AvatarImage
                                    src={
                                        emp.profile_photo_path
                                            ? `/storage/${emp.profile_photo_path}`
                                            : undefined
                                    }
                                    alt={emp.name}
                                />
                                <AvatarFallback className="text-lg font-bold">
                                    {getInitials(emp.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 flex-1">
                                <h3 className="text-lg font-semibold">{emp.name}</h3>
                                {emp.position_title && (
                                    <p className="text-sm text-muted-foreground">
                                        {emp.position_title}
                                    </p>
                                )}
                                {(emp.is_first_aider || emp.is_fire_warden) && (
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {emp.is_first_aider && (
                                            <Badge
                                                variant="outline"
                                                className="gap-0.5 border-status-success/30 bg-status-success-bg text-[10px] text-status-success-foreground"
                                            >
                                                <HeartPulse className="size-2.5" />
                                                First aider
                                            </Badge>
                                        )}
                                        {emp.is_fire_warden && (
                                            <Badge
                                                variant="outline"
                                                className="gap-0.5 border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning-foreground"
                                            >
                                                <Flame className="size-2.5" />
                                                Fire warden
                                            </Badge>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>

                        {emp.bio && (
                            <p className="text-sm text-muted-foreground">{emp.bio}</p>
                        )}

                        {/* Contact */}
                        <div className="space-y-1.5 text-sm">
                            {emp.email && (
                                <a
                                    href={`mailto:${emp.email}`}
                                    className="flex items-center gap-2 text-primary hover:underline"
                                >
                                    <Mail className="size-3.5 shrink-0" />
                                    <span className="truncate">{emp.email}</span>
                                </a>
                            )}
                            {emp.work_phone && (
                                <a
                                    href={`tel:${emp.work_phone}`}
                                    className="flex items-center gap-2 text-primary hover:underline"
                                >
                                    <Phone className="size-3.5 shrink-0" />
                                    <span className="truncate">{emp.work_phone}</span>
                                </a>
                            )}
                            {emp.personal_email && (
                                <a
                                    href={`mailto:${emp.personal_email}`}
                                    className="flex items-center gap-2 text-muted-foreground hover:underline"
                                >
                                    <Mail className="size-3.5 shrink-0" />
                                    <span className="truncate">
                                        {emp.personal_email} (personal)
                                    </span>
                                </a>
                            )}
                            {emp.personal_phone && (
                                <a
                                    href={`tel:${emp.personal_phone}`}
                                    className="flex items-center gap-2 text-muted-foreground hover:underline"
                                >
                                    <Phone className="size-3.5 shrink-0" />
                                    <span className="truncate">
                                        {emp.personal_phone} (personal)
                                    </span>
                                </a>
                            )}
                        </div>

                        {/* Org details */}
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            {emp.site && (
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Site
                                    </dt>
                                    <dd className="flex items-center gap-1.5">
                                        <MapPin className="size-3.5 shrink-0 text-muted-foreground" />
                                        {emp.site}
                                    </dd>
                                </div>
                            )}
                            {emp.department && (
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Department
                                    </dt>
                                    <dd className="flex items-center gap-1.5">
                                        <Building2 className="size-3.5 shrink-0 text-muted-foreground" />
                                        {emp.department}
                                    </dd>
                                </div>
                            )}
                            {emp.employment_type && (
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Employment
                                    </dt>
                                    <dd className="capitalize">
                                        {emp.employment_type.replace(/_/g, ' ')}
                                    </dd>
                                </div>
                            )}
                            {data.tenure && (
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Tenure
                                    </dt>
                                    <dd className="flex items-center gap-1.5">
                                        <CalendarDays className="size-3.5 shrink-0 text-muted-foreground" />
                                        {tenureLabel(data.tenure)}
                                    </dd>
                                </div>
                            )}
                        </dl>

                        {/* Reporting line */}
                        {(data.manager || data.directReports.length > 0) && (
                            <div className="space-y-1.5 border-t pt-4 text-sm">
                                {data.manager && (
                                    <p className="text-muted-foreground">
                                        Reports to{' '}
                                        <span className="font-medium text-foreground">
                                            {data.manager.name}
                                        </span>
                                        {data.manager.position_title
                                            ? ` · ${data.manager.position_title}`
                                            : ''}
                                    </p>
                                )}
                                {data.directReports.length > 0 && (
                                    <p className="flex items-center gap-1.5 text-muted-foreground">
                                        <Users className="size-3.5" />
                                        {data.directReports.length} direct report
                                        {data.directReports.length === 1 ? '' : 's'}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Compliance (manager-only) */}
                        {data.canManage &&
                            data.complianceSummary &&
                            data.complianceSummary.total > 0 && (
                                <div className="border-t pt-4">
                                    <p className="mb-2 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                        <ShieldCheck className="size-3.5" />
                                        Compliance
                                    </p>
                                    <div className="flex flex-wrap gap-1.5 text-[11px]">
                                        <Badge
                                            variant="outline"
                                            className="border-status-success/30 bg-status-success-bg text-status-success-foreground"
                                        >
                                            {data.complianceSummary.compliant} compliant
                                        </Badge>
                                        {data.complianceSummary.expiring_soon > 0 && (
                                            <Badge
                                                variant="outline"
                                                className="border-status-warning/30 bg-status-warning-bg text-status-warning-foreground"
                                            >
                                                {data.complianceSummary.expiring_soon}{' '}
                                                expiring
                                            </Badge>
                                        )}
                                        {data.complianceSummary.expired > 0 && (
                                            <Badge
                                                variant="outline"
                                                className="border-status-critical/30 bg-status-critical-bg text-status-critical-foreground"
                                            >
                                                {data.complianceSummary.expired} expired
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                            )}

                        {/* Recognition */}
                        {data.kudosReceived.length > 0 && (
                            <div className="border-t pt-4">
                                <p className="mb-2 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                    <Award className="size-3.5" />
                                    Recent recognition
                                </p>
                                <ul className="space-y-2">
                                    {data.kudosReceived.slice(0, 3).map((k) => (
                                        <li
                                            key={k.id}
                                            className="rounded-lg bg-muted/50 p-2.5 text-sm"
                                        >
                                            {k.message && (
                                                <p className="text-foreground">
                                                    “{k.message}”
                                                </p>
                                            )}
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                — {k.from_name}
                                                {k.created_at ? ` · ${k.created_at}` : ''}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default StaffDetailsModal;
