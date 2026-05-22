import { cn } from '@/lib/utils';

interface ResidentDotProps {
    hue: number;
    initials: string;
    className?: string;
}

/** Tiny initials puck used inside resident tabs + stream rows. */
export function ResidentDot({ hue, initials, className }: ResidentDotProps) {
    return (
        <span
            className={cn(
                'inline-flex h-[18px] w-[18px] items-center justify-center rounded-full text-[9px] font-bold leading-none',
                className,
            )}
            style={{
                background: `oklch(0.85 0.10 ${hue})`,
                color: `oklch(0.28 0.16 ${hue})`,
                letterSpacing: '-0.02em',
            }}
        >
            {initials}
        </span>
    );
}

export default ResidentDot;
