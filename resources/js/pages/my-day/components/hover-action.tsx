import { type ComponentType, type MouseEvent, useState } from 'react';

import { cn } from '@/lib/utils';

export type HoverActionTone = 'default' | 'success' | 'danger';

interface HoverActionProps {
    icon: ComponentType<{ className?: string }>;
    label: string;
    tone?: HoverActionTone;
    onClick?: (e: MouseEvent<HTMLButtonElement>) => void;
}

/**
 * 28×28 icon button used inline in stream rows. Idle = transparent; on hover
 * the tone tints the background and the icon takes the tone's foreground.
 */
export function HoverAction({
    icon: Icon,
    label,
    tone = 'default',
    onClick,
}: HoverActionProps) {
    const [hover, setHover] = useState(false);
    const palette = TONE_CLASSES[tone];
    return (
        // eslint-disable-next-line no-restricted-syntax -- 28×28 inline-row hover action with tone palette; not a shadcn Button.
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
            className={cn(
                'inline-flex h-7 w-7 items-center justify-center rounded-md border border-border text-muted-foreground transition-colors',
                hover && palette,
            )}
        >
            <Icon className="h-[13px] w-[13px]" />
        </button>
    );
}

const TONE_CLASSES: Record<HoverActionTone, string> = {
    default: 'border-transparent bg-muted text-foreground',
    success: 'border-transparent bg-status-success-bg text-status-success',
    danger: 'border-transparent bg-status-critical-bg text-status-critical',
};

export default HoverAction;
