import { cn } from '@/lib/utils';
import { Check, Circle } from 'lucide-react';

export type PasswordRequirements = {
    minLength: number;
    mixedCase: boolean;
    numbers: boolean;
    symbols: boolean;
};

/**
 * A live checklist of the organisation's password rules (Settings ›
 * Security), ticking off as the person types. Met rules turn the brand
 * colour (the Journey brand avoids green).
 */
export function PasswordRules({
    id,
    rules,
    value,
}: {
    id: string;
    rules: PasswordRequirements;
    value: string;
}) {
    const checks = [
        {
            label: `${rules.minLength} characters or more`,
            ok: value.length >= rules.minLength,
            on: true,
        },
        {
            label: 'Upper and lower case',
            ok: /[a-z]/.test(value) && /[A-Z]/.test(value),
            on: rules.mixedCase,
        },
        { label: 'A number', ok: /[0-9]/.test(value), on: rules.numbers },
        {
            label: 'A symbol, like ! or #',
            ok: /[^A-Za-z0-9]/.test(value),
            on: rules.symbols,
        },
    ].filter((check) => check.on);

    return (
        <ul
            id={id}
            className="mt-0.5 grid grid-cols-2 gap-x-3.5 gap-y-1.5 text-[13.5px]"
        >
            {checks.map((check) => (
                <li
                    key={check.label}
                    className={cn(
                        'flex items-center gap-1.5',
                        check.ok ? 'text-primary' : 'text-muted-foreground',
                    )}
                >
                    {check.ok ? (
                        <Check aria-hidden="true" className="size-[15px]" />
                    ) : (
                        <Circle aria-hidden="true" className="size-[15px]" />
                    )}
                    <span>
                        {check.label}
                        <span className="sr-only">
                            {check.ok ? ' (done)' : ' (not yet)'}
                        </span>
                    </span>
                </li>
            ))}
        </ul>
    );
}
