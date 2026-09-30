/* The second person's personal 6-digit witness PIN (eMAR P00 v5 / PIN-1),
 * typed on the recorder's screen. Never the login password: the input is
 * numeric, masked, and opts out of password managers so the recorder's own
 * saved password can't be filled in. */
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { sanitiseWitnessPin, WITNESS_PIN_LENGTH } from '@/lib/witness-pin';
import { useId } from 'react';

export function WitnessPinInput({
    value,
    onChange,
    label = 'Their witness PIN',
    required = true,
    error,
    disabled = false,
    atCupboard = false,
    id,
    className,
    ariaLabel,
    hideLabel = false,
}: {
    value: string;
    onChange: (value: string) => void;
    label?: string;
    required?: boolean;
    error?: string;
    disabled?: boolean;
    /** Controlled-drug witnessing happens at the medicine cupboard. */
    atCupboard?: boolean;
    id?: string;
    className?: string;
    ariaLabel?: string;
    hideLabel?: boolean;
}) {
    const generated = useId();
    const inputId = id ?? `witness-pin-${generated}`;
    const helpId = `${inputId}-help`;

    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label
                htmlFor={inputId}
                className={hideLabel ? 'sr-only' : undefined}
            >
                {label}
                {required ? (
                    <span className="text-status-critical"> *</span>
                ) : null}
            </Label>
            <Input
                id={inputId}
                type="password"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={WITNESS_PIN_LENGTH}
                autoComplete="one-time-code"
                data-lpignore="true"
                data-1p-ignore="true"
                data-form-type="other"
                value={value}
                disabled={disabled}
                aria-label={ariaLabel}
                aria-invalid={error ? true : undefined}
                aria-describedby={helpId}
                onChange={(event) =>
                    onChange(sanitiseWitnessPin(event.target.value))
                }
                className="tracking-[0.3em] tabular-nums"
            />
            <p id={helpId} className="text-caption">
                Their own 6-digit witness PIN — not their login password. They
                type it here{atCupboard ? ', at the medicine cupboard' : ''}.
            </p>
            {error ? <InputError message={error} /> : null}
        </div>
    );
}
