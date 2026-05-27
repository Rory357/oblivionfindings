import { UserPlus } from 'lucide-react';

type Props = {
    name?: string | null;
    size?: 'sm' | 'md';
    className?: string;
};

export function StaffAvatar({ name, size = 'sm', className = '' }: Props) {
    const sizeClass =
        size === 'sm' ? 'h-7 w-7 text-[10px]' : 'h-9 w-9 text-xs';

    if (!name) {
        return (
            <span
                className={`inline-flex items-center justify-center rounded-full bg-status-critical-bg font-semibold text-status-critical ${sizeClass} ${className}`}
                aria-label="Unassigned"
            >
                <UserPlus className="h-3.5 w-3.5" />
            </span>
        );
    }

    const initials = name
        .split(/\s+/)
        .filter(Boolean)
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    let h = 0;
    for (let i = 0; i < name.length; i++) {
        h = (h * 31 + name.charCodeAt(i)) % 360;
    }
    const bg = `oklch(0.86 0.08 ${h})`;
    const fg = `oklch(0.30 0.10 ${h})`;

    return (
        <span
            className={`inline-flex items-center justify-center rounded-full font-semibold ${sizeClass} ${className}`}
            style={{ background: bg, color: fg }}
            aria-label={name}
        >
            {initials}
        </span>
    );
}
