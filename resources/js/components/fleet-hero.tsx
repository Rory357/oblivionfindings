import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ReactNode } from 'react';

type Props = {
    title: ReactNode;
    description?: string;
    icon?: ReactNode;
    backHref?: string;
    backLabel?: string;
    actions?: ReactNode;
    stats?: Array<{ label: string; value: string | number }>;
    children?: ReactNode;
};

export default function FleetHero({
    title,
    description,
    icon,
    backHref,
    backLabel = 'Back',
    actions,
    stats,
    children,
}: Props) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-white md:p-8">
            <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-white/5" />
            <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-white/5" />
            <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-white/5" />

            <div className="relative">
                {backHref && (
                    <Link
                        href={backHref}
                        className="mb-3 inline-flex items-center gap-1.5 text-xs text-white/50 transition-colors hover:text-white/80"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        {backLabel}
                    </Link>
                )}

                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div className="flex items-center gap-4">
                        {icon && (
                            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-white/10 shadow-lg">
                                {icon}
                            </div>
                        )}
                        <div>
                            <h1 className="text-2xl font-bold md:text-3xl">{title}</h1>
                            {description && (
                                <p className="mt-0.5 text-sm text-white/60">{description}</p>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        {stats?.map((s) => (
                            <div key={s.label} className="rounded-xl bg-white/10 px-4 py-2 text-center backdrop-blur-sm">
                                <div className="text-lg font-bold">{s.value}</div>
                                <div className="text-[10px] uppercase tracking-wider text-white/60">{s.label}</div>
                            </div>
                        ))}
                        {actions && (
                            <div className="[&_[data-slot=button]]:border-white/30 [&_[data-slot=button]]:bg-white/10 [&_[data-slot=button]]:text-white [&_[data-slot=button]]:shadow-none [&_[data-slot=button]]:hover:bg-white/20">
                                {actions}
                            </div>
                        )}
                    </div>
                </div>

                {children}
            </div>
        </div>
    );
}
