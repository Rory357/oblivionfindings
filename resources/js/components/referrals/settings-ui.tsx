/* Referral settings parts, composed only from shared primitives and laid
 * out like Fleet Settings (pages/fleet-assets/settings) and Medication
 * settings: an Overview of ReviewCards with "Review … ↗" links, titled
 * groups of compact rows, Switches that spell out On/Off, and a sticky save
 * bar with Discard / Review changes. */
import { ListCaption } from '@/components/lists/list-caption';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import { ArrowUpRight, Lock, type LucideIcon } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';

/** On/off with the state spelled out, so colour is never the only signal. */
export function OnOff({
    checked,
    onChange,
    disabled,
    label,
    id,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
    disabled?: boolean;
    label: string;
    id?: string;
}) {
    return (
        <span className="inline-flex shrink-0 items-center gap-3">
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onChange}
                disabled={disabled}
                aria-label={label}
            />
            <span className="text-subtle w-7" aria-hidden="true">
                {checked ? 'On' : 'Off'}
            </span>
        </span>
    );
}

/** A safeguard that can't be switched off here, shown with the reason in its row. */
export function AlwaysOn({ children = 'Always on' }: { children?: string }) {
    return (
        <StatusBadge variant="neutral" size="sm" className="gap-1">
            <Lock className="size-3" aria-hidden="true" />
            {children}
        </StatusBadge>
    );
}

/** Settings sit in titled groups: icon + title + caption, then divided compact rows. */
export function SettingGroup({
    icon: Icon,
    title,
    caption,
    children,
    actions,
    wide,
}: {
    icon: LucideIcon;
    title: string;
    caption?: string;
    children: ReactNode;
    actions?: ReactNode;
    wide?: boolean;
}) {
    return (
        <Card
            className={cn('gap-0 overflow-hidden p-0', wide && 'lg:col-span-2')}
        >
            <div className="flex items-start justify-between gap-3 border-b border-border p-4">
                <div className="flex min-w-0 items-start gap-3">
                    <Lens icon={Icon} />
                    <div className="min-w-0">
                        <h3 className="text-sm font-semibold">{title}</h3>
                        {caption ? (
                            <p className="text-caption mt-0.5">{caption}</p>
                        ) : null}
                    </div>
                </div>
                {actions ? (
                    <div className="flex shrink-0 flex-wrap gap-2">
                        {actions}
                    </div>
                ) : null}
            </div>
            {/* Slate layering: white card, nested rows on the secondary surface. */}
            <div className="p-3">
                <div className="divide-y divide-border overflow-hidden rounded-lg bg-muted">
                    {children}
                </div>
            </div>
        </Card>
    );
}

/** An icon in a ring (the signature "lens"), coloured from the brand token only. */
export function Lens({ icon: Icon }: { icon: LucideIcon }) {
    return (
        <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-fill/10 text-primary ring-1 ring-primary/25">
            <Icon className="size-4" />
        </span>
    );
}

/**
 * A nested section inside a pop-up or card: the secondary surface, never a
 * card on a card (Slate layering).
 */
export function Nested({
    icon,
    title,
    children,
}: {
    icon?: LucideIcon;
    title?: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-lg bg-muted p-4">
            {title ? (
                <div className="mb-3 flex items-center gap-2.5">
                    {icon ? <Lens icon={icon} /> : null}
                    <h3 className="text-sm font-semibold">{title}</h3>
                </div>
            ) : null}
            {children}
        </section>
    );
}

/** Label and value lines inside a nested section (the ReviewRow layout, on the secondary surface). */
export function NestedRow({
    label,
    value,
}: {
    label: string;
    value?: ReactNode;
}) {
    return (
        <div className="flex justify-between gap-4 border-b border-border py-1.5 last:border-0">
            <span className="max-w-[45%] shrink-0 text-[13px] break-words text-muted-foreground">
                {label}
            </span>
            <span className="min-w-0 text-right text-[13px] font-medium break-words">
                {value == null || value === '' ? (
                    <span className="font-normal text-muted-foreground">—</span>
                ) : (
                    value
                )}
            </span>
        </div>
    );
}

/** One setting: label and a one-line hint on the left, its control on the right. */
export function SettingRow({
    label,
    hint,
    control,
    children,
    htmlFor,
}: {
    label: ReactNode;
    hint?: ReactNode;
    control?: ReactNode;
    children?: ReactNode;
    htmlFor?: string;
}) {
    return (
        <div className="space-y-2 px-4 py-3">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    {htmlFor ? (
                        <Label
                            htmlFor={htmlFor}
                            className="text-[13px] leading-snug"
                        >
                            {label}
                        </Label>
                    ) : (
                        <p className="text-[13px] leading-snug font-medium">
                            {label}
                        </p>
                    )}
                    {hint ? (
                        <p className="text-caption mt-0.5">{hint}</p>
                    ) : null}
                </div>
                {control ? <div className="shrink-0">{control}</div> : null}
            </div>
            {children}
        </div>
    );
}

/** A switch row: the commonest setting. */
export function SwitchRow({
    label,
    hint,
    checked,
    onChange,
    disabled,
}: {
    label: string;
    hint?: ReactNode;
    checked: boolean;
    onChange: (value: boolean) => void;
    disabled?: boolean;
}) {
    const id = useId();
    return (
        <SettingRow
            label={label}
            hint={hint}
            htmlFor={id}
            control={
                <OnOff
                    id={id}
                    label={label}
                    checked={checked}
                    onChange={onChange}
                    disabled={disabled}
                />
            }
        />
    );
}

/** A whole number with its unit after it ("2 working days"). */
export function NumberRow({
    label,
    hint,
    value,
    onChange,
    unit,
    min = 1,
    max,
    disabled,
}: {
    label: string;
    hint?: ReactNode;
    value: number;
    onChange: (value: number) => void;
    unit: string;
    min?: number;
    max?: number;
    disabled?: boolean;
}) {
    const id = useId();
    return (
        <SettingRow
            label={label}
            hint={hint}
            htmlFor={id}
            control={
                <span className="inline-flex items-center gap-2">
                    <Input
                        id={id}
                        type="number"
                        inputMode="numeric"
                        min={min}
                        max={max}
                        value={Number.isFinite(value) ? value : ''}
                        disabled={disabled}
                        onChange={(event) =>
                            onChange(
                                event.target.value === ''
                                    ? Number.NaN
                                    : Number(event.target.value),
                            )
                        }
                        className="h-9 w-20 tabular-nums"
                    />
                    <span className="text-subtle">{unit}</span>
                </span>
            }
        />
    );
}

/** A short text setting edited in place. */
export function TextRow({
    label,
    hint,
    value,
    onChange,
    disabled,
    type = 'text',
    maxLength,
    placeholder,
}: {
    label: string;
    hint?: ReactNode;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    type?: string;
    maxLength?: number;
    placeholder?: string;
}) {
    const id = useId();
    return (
        <SettingRow
            label={label}
            hint={hint}
            htmlFor={id}
            control={
                <Input
                    id={id}
                    type={type}
                    value={value}
                    disabled={disabled}
                    maxLength={maxLength}
                    placeholder={placeholder}
                    onChange={(event) => onChange(event.target.value)}
                    className="h-9 w-64 max-w-full"
                />
            }
        />
    );
}

/** A collection summarised in one row; its detail is edited in a dialog. */
export function DialogRow({
    label,
    hint,
    action,
    onOpen,
    badge,
}: {
    label: string;
    hint?: ReactNode;
    action: string;
    onOpen: () => void;
    badge?: ReactNode;
}) {
    return (
        <SettingRow
            label={label}
            hint={hint}
            control={
                <span className="inline-flex items-center gap-2">
                    {badge}
                    <Button variant="outline" size="sm" onClick={onOpen}>
                        {action}
                    </Button>
                </span>
            }
        />
    );
}

/**
 * Editing detail lives in a dialog (Fleet Modal anatomy): the dialog works
 * on its own copy and "Apply to draft" hands it back. Nothing is saved until
 * the page's own "Review changes".
 */
export function ConfigDialog<T>({
    title,
    description,
    initial,
    readOnly,
    onApply,
    onClose,
    width = 720,
    validate,
    applyLabel = 'Apply to draft',
    children,
}: {
    title: string;
    description: ReactNode;
    initial: T;
    readOnly?: boolean;
    onApply: (value: T) => void;
    onClose: () => void;
    width?: 480 | 720 | 900;
    validate?: (value: T) => string | null;
    applyLabel?: string;
    children: (value: T, set: (next: T) => void) => ReactNode;
}) {
    const [draft, setDraft] = useState<T>(() => structuredClone(initial));
    const [error, setError] = useState('');
    return (
        <SettingsModal
            title={title}
            description={description}
            width={width}
            onClose={onClose}
            footer={
                readOnly ? undefined : (
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            onClick={() => {
                                const problem = validate?.(draft) ?? null;
                                if (problem) {
                                    setError(problem);
                                    return;
                                }
                                onApply(draft);
                                onClose();
                            }}
                        >
                            {applyLabel}
                        </Button>
                    </>
                )
            }
        >
            {children(draft, (next) => {
                setError('');
                setDraft(next);
            })}
            {error ? <SettingsNotice>{error}</SettingsNotice> : null}
        </SettingsModal>
    );
}

/**
 * The collection editors (questions, checklists, stage changes, routing)
 * render as a card on their own and as plain sections inside a dialog.
 */
export function EditorFrame({
    embedded,
    header,
    contentClassName,
    children,
}: {
    embedded?: boolean;
    header: ReactNode;
    contentClassName?: string;
    children: ReactNode;
}) {
    if (embedded)
        return (
            <section className="space-y-3">
                <div className="space-y-1">{header}</div>
                <div className={contentClassName}>{children}</div>
            </section>
        );
    return (
        <Card>
            <CardHeader>{header}</CardHeader>
            <CardContent className={contentClassName}>{children}</CardContent>
        </Card>
    );
}

/** Two groups per line where they fit. */
export const GroupGrid = ({ children }: { children: ReactNode }) => (
    <div className="grid items-start gap-5 lg:grid-cols-2">{children}</div>
);

export const Note = ({ children }: { children: ReactNode }) => (
    <SettingsNotice role="note">
        <span>{children}</span>
    </SettingsNotice>
);

export type OverviewCard = {
    key: string;
    icon: LucideIcon;
    title: string;
    rows: [string, ReactNode][];
    cta: string;
    onReview: () => void;
};

/** Each view opens here: a caption, then a two-column grid of ReviewCards with a way in. */
export function OverviewGrid({
    title,
    caption,
    cards,
    note,
}: {
    title: string;
    caption: string;
    cards: OverviewCard[];
    note?: ReactNode;
}) {
    return (
        <div className="space-y-5">
            <ListCaption title={title} caption={caption} />
            <div className="grid gap-5 lg:grid-cols-2">
                {cards.map((card) => (
                    <ReviewCard
                        key={card.key}
                        icon={card.icon}
                        title={card.title}
                    >
                        {card.rows.map(([label, value]) => (
                            <ReviewRow
                                key={label}
                                label={label}
                                value={value}
                            />
                        ))}
                        <Button
                            variant="link"
                            className="mt-1 px-0"
                            onClick={card.onReview}
                        >
                            {card.cta} <ArrowUpRight className="size-4" />
                        </Button>
                    </ReviewCard>
                ))}
            </div>
            {note ? <Note>{note}</Note> : null}
        </div>
    );
}

/** Sticky save bar (Fleet `_notifications.tsx`): what changed, then Discard / Review changes. */
export function SaveBar({
    count,
    onDiscard,
    onReview,
    busy,
    note,
}: {
    count: number;
    onDiscard: () => void;
    onReview: () => void;
    busy?: boolean;
    note?: string;
}) {
    return (
        <Card
            className="sticky bottom-0 z-10 flex flex-row flex-wrap items-center justify-between gap-3 p-4"
            role="region"
            aria-label="Save changes"
        >
            <div>
                <p className="text-sm font-semibold">
                    {count
                        ? `${count} ${count === 1 ? 'change' : 'changes'} not saved`
                        : 'No unsaved changes'}
                </p>
                <p className="text-caption mt-1">
                    {note ??
                        'Nothing changes until a draft is saved and another person publishes it. Open referrals keep their current settings.'}
                </p>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={!count || busy}
                    onClick={onDiscard}
                >
                    Discard changes
                </Button>
                <Button size="sm" disabled={!count || busy} onClick={onReview}>
                    Review changes
                </Button>
            </div>
        </Card>
    );
}
