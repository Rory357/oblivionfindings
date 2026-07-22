import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, ClipboardList } from 'lucide-react';
import type { ReactNode } from 'react';

export function SafetyRegisterHeader({
    title,
    description,
    href,
    actionLabel,
    count,
    children,
}: {
    title: string;
    description: string;
    href: string;
    actionLabel: string;
    count?: number;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-lg font-semibold">{title}</h2>
                    {typeof count === 'number' ? (
                        <Badge variant="secondary">{count}</Badge>
                    ) : null}
                </div>
                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                    {description}
                </p>
            </div>
            <div className="flex flex-wrap gap-2">
                {children}
                <Button asChild variant="outline" size="sm">
                    <Link href={href}>
                        {actionLabel}
                        <ArrowUpRight className="ml-1.5 h-4 w-4" />
                    </Link>
                </Button>
            </div>
        </div>
    );
}

export function SafetyRegisterCard({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-base">{title}</CardTitle>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

export function SafetyEmpty({ label }: { label: string }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed px-4 py-10 text-center">
            <ClipboardList className="h-8 w-8 text-muted-foreground/40" />
            <p className="text-sm text-muted-foreground">{label}</p>
        </div>
    );
}

export function registerLabel(value?: string | null): string {
    return value
        ? value
              .replaceAll('_', ' ')
              .replace(/\b\w/g, (letter) => letter.toUpperCase())
        : 'Not recorded';
}

export function formatRegisterDate(value?: string | null): string {
    if (!value) return 'Not scheduled';
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}
