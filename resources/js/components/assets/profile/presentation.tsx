import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { ArrowUpRight, CircleAlert, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export const human = (value?: string | null) => {
    const labels: Record<string, string> = {
        pending_receipt: 'Receipt pending',
        acknowledged: 'Receipt acknowledged',
        needs_followup: 'Needs assessment',
        pass: 'Passed',
        fail: 'Needs assessment',
        scan_unavailable: 'File check unavailable',
        legacy_unverified: 'Legacy file · not checked',
    };
    return value
        ? labels[value] ||
              value.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase())
        : 'Not recorded';
};

export function Panel({
    title,
    actions,
    children,
    icon: Icon = CircleAlert,
}: {
    title: string;
    actions?: ReactNode;
    children: ReactNode;
    icon?: LucideIcon;
}) {
    return (
        <Card className="gap-0 overflow-hidden rounded-xl py-0 shadow-sm">
            <CardHeader className="flex flex-row items-center justify-between gap-3 border-b px-5 py-4">
                <CardTitle className="text-section-title flex items-center gap-2">
                    <Icon className="size-4 shrink-0 text-primary" />
                    {title}
                </CardTitle>
                {actions}
            </CardHeader>
            <CardContent className="space-y-4 p-5">{children}</CardContent>
        </Card>
    );
}
export function Fact({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div>
            <dt className="text-caption text-muted-foreground">{label}</dt>
            <dd className="text-subtle mt-1 font-medium break-words">
                {children || 'Not recorded'}
            </dd>
        </div>
    );
}
export function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="text-subtle rounded-lg border border-dashed p-6 text-center text-muted-foreground">
            {children}
        </p>
    );
}
export function SectionHeading({
    title,
    description,
    eyebrow,
    actions,
}: {
    title: string;
    description: string;
    eyebrow?: string;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-4">
            <div>
                {eyebrow && (
                    <p className="text-caption mb-1 font-semibold tracking-wide text-muted-foreground uppercase">
                        {eyebrow}
                    </p>
                )}
                <h2 className="text-section-title">{title}</h2>
                <p className="text-subtle mt-1 text-muted-foreground">
                    {description}
                </p>
            </div>
            {actions}
        </div>
    );
}
export function ActionRow({
    title,
    description,
    detail,
    icon: Icon,
    action,
}: {
    title: string;
    description?: string;
    detail?: string;
    icon?: LucideIcon;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center gap-3 border-b pb-4 last:border-0 last:pb-0">
            {Icon && (
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary">
                    <Icon className="size-5" />
                </span>
            )}
            <div className="min-w-40 flex-1">
                <h3 className="text-subtle font-semibold">{title}</h3>
                {description && (
                    <p className="text-subtle mt-1 text-muted-foreground">
                        {description}
                    </p>
                )}
                {detail && (
                    <p className="text-caption mt-1 text-muted-foreground">
                        {detail}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}
export function TextAction({
    children,
    onClick,
}: {
    children: ReactNode;
    onClick: () => void;
}) {
    return (
        <Button
            variant="link"
            type="button"
            onClick={onClick}
            className="text-subtle inline-flex min-h-9 items-center gap-1 rounded px-1 font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
        >
            {children}
            <ArrowUpRight className="size-3.5" />
        </Button>
    );
}
export function State({ value }: { value: string }) {
    return (
        <StatusBadge
            variant={
                ['acknowledged', 'pass', 'available', 'active'].includes(value)
                    ? 'success'
                    : [
                            'incomplete',
                            'disputed',
                            'needs_followup',
                            'pending_receipt',
                            'scan_unavailable',
                        ].includes(value)
                      ? 'warning'
                      : 'neutral'
            }
            size="sm"
        >
            {human(value)}
        </StatusBadge>
    );
}
