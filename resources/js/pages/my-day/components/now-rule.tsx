import { cn } from '@/lib/utils';

interface NowRuleProps {
    time: string;
    className?: string;
}

/**
 * Full-width "NOW · 11:14" inset divider rendered between time-blocks in the
 * What's Next rail.
 */
export function NowRule({ time, className }: NowRuleProps) {
    return (
        <div
            className={cn(
                'flex items-center gap-2.5 border-y border-brand-tint-deep px-[18px] py-2',
                'bg-gradient-to-r from-accent to-transparent',
                className,
            )}
        >
            <span className="h-2.5 w-2.5 rounded-full bg-primary shadow-[0_0_0_4px_var(--accent)]" />
            <span className="text-[11px] font-bold tracking-[0.12em] text-primary uppercase">
                Now · {time}
            </span>
            <span className="h-px flex-1 bg-brand-tint-deep" />
        </div>
    );
}

export default NowRule;
