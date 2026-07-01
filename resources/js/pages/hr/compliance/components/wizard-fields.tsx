/* Shared input wrappers for the Compliance wizards — thin styled adapters over
 * the shadcn kit so every wizard step looks like the Add-Client reference. */
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import type { ReactNode } from 'react';

/** Multi-select chips with distinct value/label (e.g. role name vs. role label). */
export function LabeledChipMulti({
    values,
    onChange,
    options,
}: {
    values: string[];
    onChange: (v: string[]) => void;
    options: { value: string; label: string }[];
}) {
    const toggle = (v: string) =>
        onChange(values.includes(v) ? values.filter((x) => x !== v) : [...values, v]);
    return (
        <div className="flex flex-wrap gap-1.5">
            {options.map((o) => {
                const active = values.includes(o.value);
                return (
                    <button
                        key={o.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => toggle(o.value)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                            active
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border bg-card text-foreground hover:border-primary/50',
                        )}
                    >
                        {active ? <Check className="h-3 w-3" /> : null}
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

export function WizardGrid({ children }: { children: ReactNode }) {
    return <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>;
}

export function TextField({
    value,
    onChange,
    placeholder,
    type = 'text',
    id,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    type?: 'text' | 'number' | 'date';
    id?: string;
}) {
    return (
        <Input
            id={id}
            type={type}
            value={value}
            placeholder={placeholder}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}

export function TextAreaField({
    value,
    onChange,
    placeholder,
    id,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
    id?: string;
}) {
    return (
        <Textarea
            id={id}
            value={value}
            placeholder={placeholder}
            onChange={(e) => onChange(e.target.value)}
            className="min-h-[88px]"
        />
    );
}

export function Toggle({
    checked,
    onChange,
    label,
}: {
    checked: boolean;
    onChange: (v: boolean) => void;
    label: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={() => onChange(!checked)}
            aria-pressed={checked}
            className="flex w-full items-center gap-3 rounded-lg border border-border bg-card/50 p-3 text-left"
        >
            <span
                className={cn(
                    'relative h-6 w-11 shrink-0 rounded-full transition-colors',
                    checked ? 'bg-primary' : 'bg-muted',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-[left]',
                        checked ? 'left-[22px]' : 'left-0.5',
                    )}
                />
            </span>
            <span className="text-[13px] font-medium">{label}</span>
        </button>
    );
}

export function FileField({
    fileName,
    onPick,
    id,
}: {
    fileName: string | null;
    onPick: (file: File | null) => void;
    id?: string;
}) {
    return (
        <label
            htmlFor={id}
            className="flex cursor-pointer items-center gap-2.5 rounded-lg border border-dashed border-border bg-background p-3.5 text-[13px] font-medium text-muted-foreground transition-colors hover:border-primary/50"
        >
            <svg
                className="h-4 w-4"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                <path d="m7 9 5-5 5 5" />
                <path d="M12 4v12" />
            </svg>
            <span className={fileName ? 'text-foreground' : undefined}>
                {fileName ?? 'Click to upload (PDF, JPG, PNG)'}
            </span>
            <input
                id={id}
                type="file"
                accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
                className="hidden"
                onChange={(e) => onPick(e.target.files?.[0] ?? null)}
            />
        </label>
    );
}
