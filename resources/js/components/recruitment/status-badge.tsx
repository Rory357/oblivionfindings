import { Badge } from '@/components/ui/badge';
import {
    UserPlus,
    FileText,
    PhoneCall,
    CheckCircle2,
    Clock,
    XCircle,
    Users,
    Send,
    ThumbsUp,
    Sparkles,
} from 'lucide-react';

export const statusConfig: Record<string, { label: string; color: string; icon: React.ElementType; bgClass: string }> = {
    new: { label: 'New', color: 'border-blue-500/30 text-blue-400 bg-blue-500/10', bgClass: 'bg-blue-500', icon: UserPlus },
    screening: { label: 'Screening', color: 'border-indigo-500/30 text-indigo-400 bg-indigo-500/10', bgClass: 'bg-indigo-500', icon: FileText },
    interview_scheduled: { label: 'Interview', color: 'border-amber-500/30 text-amber-400 bg-amber-500/10', bgClass: 'bg-amber-500', icon: PhoneCall },
    interview_completed: { label: 'Interviewed', color: 'border-orange-500/30 text-orange-400 bg-orange-500/10', bgClass: 'bg-orange-500', icon: CheckCircle2 },
    reference_check: { label: 'References', color: 'border-purple-500/30 text-purple-400 bg-purple-500/10', bgClass: 'bg-purple-500', icon: FileText },
    offer_pending: { label: 'Offer Pending', color: 'border-cyan-500/30 text-cyan-400 bg-cyan-500/10', bgClass: 'bg-cyan-500', icon: Clock },
    offer_sent: { label: 'Offer Sent', color: 'border-teal-500/30 text-teal-400 bg-teal-500/10', bgClass: 'bg-teal-500', icon: Send },
    offer_accepted: { label: 'Accepted', color: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', bgClass: 'bg-emerald-500', icon: ThumbsUp },
    onboarding: { label: 'Onboarding', color: 'border-lime-500/30 text-lime-400 bg-lime-500/10', bgClass: 'bg-lime-500', icon: Sparkles },
    hired: { label: 'Hired', color: 'border-green-500/30 text-green-400 bg-green-500/10', bgClass: 'bg-green-500', icon: CheckCircle2 },
    withdrawn: { label: 'Withdrawn', color: 'border-slate-500/30 text-slate-400 bg-slate-500/10', bgClass: 'bg-slate-500', icon: XCircle },
    rejected: { label: 'Rejected', color: 'border-red-500/30 text-red-400 bg-red-500/10', bgClass: 'bg-red-500', icon: XCircle },
};

export const stageOrder = [
    'new', 'screening', 'interview_scheduled', 'interview_completed',
    'reference_check', 'offer_pending', 'offer_sent', 'offer_accepted',
    'onboarding', 'hired',
];

export const stageLabels: Record<string, string> = Object.fromEntries(
    Object.entries(statusConfig).map(([key, val]) => [key, val.label]),
);

export const stageColors: Record<string, string> = {
    new: 'bg-blue-500/10 border-blue-500/30',
    screening: 'bg-indigo-500/10 border-indigo-500/30',
    interview_scheduled: 'bg-amber-500/10 border-amber-500/30',
    interview_completed: 'bg-orange-500/10 border-orange-500/30',
    reference_check: 'bg-purple-500/10 border-purple-500/30',
    offer_pending: 'bg-cyan-500/10 border-cyan-500/30',
    offer_sent: 'bg-teal-500/10 border-teal-500/30',
    offer_accepted: 'bg-emerald-500/10 border-emerald-500/30',
    onboarding: 'bg-lime-500/10 border-lime-500/30',
    hired: 'bg-green-500/10 border-green-500/30',
    withdrawn: 'bg-slate-500/10 border-slate-500/30',
    rejected: 'bg-red-500/10 border-red-500/30',
};

export function StatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] ?? {
        label: status,
        color: 'border-slate-500/30 text-slate-400',
        icon: Clock,
    };
    const Icon = config.icon;
    return (
        <Badge variant="outline" className={config.color}>
            <Icon className="w-3 h-3 mr-1" />
            {config.label}
        </Badge>
    );
}
