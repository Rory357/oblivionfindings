import {
    BookOpen,
    CalendarDays,
    ContactRound,
    GraduationCap,
    HeartHandshake,
    LifeBuoy,
    Monitor,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

const icons: Record<string, LucideIcon> = {
    people: HeartHandshake,
    leave: CalendarDays,
    policies: BookOpen,
    learning: GraduationCap,
    profile: ContactRound,
    maintenance: Wrench,
    computer: Monitor,
};
export function ServiceIcon({
    name,
    className = 'size-5',
}: {
    name?: string;
    className?: string;
}) {
    const Icon = icons[name ?? ''] ?? LifeBuoy;
    return <Icon className={className} aria-hidden="true" />;
}
