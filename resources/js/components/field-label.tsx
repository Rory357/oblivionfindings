import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Props = {
    htmlFor?: string;
    children: React.ReactNode;
    required?: boolean;
    recommended?: boolean;
    optional?: boolean;
    className?: string;
};

export function FieldLabel({
    htmlFor,
    children,
    required,
    recommended,
    optional,
    className,
}: Props) {
    const indicator = required
        ? 'Required'
        : recommended
          ? 'Recommended'
          : optional
            ? 'Optional'
            : null;

    return (
        <Label
            htmlFor={htmlFor}
            className={cn('mb-1 flex items-center gap-2', className)}
        >
            <span>{children}</span>
            {indicator && (
                <span
                    className={cn(
                        'rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                        required
                            ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                            : recommended
                              ? 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                              : 'border-border bg-muted text-muted-foreground',
                    )}
                >
                    {indicator}
                </span>
            )}
        </Label>
    );
}
