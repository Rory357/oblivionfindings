import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import {
    AlertCircle,
    Check,
    ChevronDown,
    Info,
    Search,
    ShieldCheck,
} from 'lucide-react';
import {
    cloneElement,
    type ComponentType,
    isValidElement,
    type ReactElement,
    type ReactNode,
    useEffect,
    useId,
    useRef,
    useState,
} from 'react';
export { Button, Card };
export function Notice({
    title,
    children,
    tone = 'info',
}: {
    title: string;
    children?: ReactNode;
    tone?: 'info' | 'warning' | 'critical' | 'success';
}) {
    const Icon =
        tone === 'warning' || tone === 'critical'
            ? AlertCircle
            : tone === 'success'
              ? ShieldCheck
              : Info;
    return (
        <div
            className={'notice ' + tone}
            role={tone === 'critical' ? 'alert' : undefined}
        >
            <Icon size={18} />
            <div>
                <strong>{title}</strong>
                {children && <div>{children}</div>}
            </div>
        </div>
    );
}
export function Modal({
    title,
    description,
    children,
    onClose,
    footer,
    width = 480,
    icon: Icon = Info,
}: {
    title: string;
    description: string;
    children: ReactNode;
    onClose: () => void;
    footer?: ReactNode;
    width?: 480 | 720 | 900 | 1100;
    icon?: ComponentType<{ className?: string }>;
}) {
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="bnd-dialog flex flex-col overflow-hidden p-0"
                style={{
                    width: `min(92vw, ${width}px)`,
                    maxWidth: 'none',
                    maxHeight: '88vh',
                }}
            >
                <DialogHeader className="shrink-0 border-b px-6 py-5">
                    <div className="flex items-start gap-3 pr-5">
                        <span className="icon-tile shrink-0">
                            <Icon />
                        </span>
                        <div>
                            <DialogTitle>{title}</DialogTitle>
                            <DialogDescription className="mt-1">
                                {description}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>
                <div className="scrollbar-pretty min-h-0 overflow-y-auto p-6">
                    {children}
                </div>
                <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                    {footer ?? (
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
export type DetailSection = {
    key: string;
    label: string;
    blurb: string;
    icon: ComponentType<{ className?: string }>;
    content: ReactNode;
};
export function SectionDialog({
    title,
    description,
    sections,
    onClose,
    footer,
    icon = Info,
    sectionIndex,
    onSectionChange,
}: {
    title: string;
    description: string;
    sections: DetailSection[];
    onClose: () => void;
    footer?: ReactNode;
    icon?: ComponentType<{ className?: string }>;
    sectionIndex?: number;
    onSectionChange?: (n: number) => void;
}) {
    const [local, setLocal] = useState(0),
        ref = useRef<HTMLDivElement>(null),
        index = Math.min(sectionIndex ?? local, sections.length - 1);
    useEffect(() => {
        ref.current?.closest('[data-wizard-region="body"]')?.scrollTo(0, 0);
    }, [index]);
    return (
        <WizardShell
            open
            onClose={onClose}
            title={title}
            description={description}
            railIcon={icon}
            railTitle={title}
            railSub="Record sections"
            steps={sections}
            stepIndex={index}
            onStepClick={(n) => {
                setLocal(n);
                onSectionChange?.(n);
            }}
            headerLabel={sections[index].label}
            maxWidth="min(92vw, 1100px)"
            maxHeight="min(88vh, 820px)"
            footerEnd={
                footer ?? (
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                )
            }
        >
            <WizardStepPane>
                <div ref={ref} data-detail-body className="flow-stack">
                    <p className="text-caption">{description}</p>
                    {sections[index].content}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
export function Field({
    label,
    children,
    hint,
    error,
    required = false,
}: {
    label: string;
    children: ReactNode;
    hint?: string;
    error?: string;
    required?: boolean;
}) {
    const uid = useId(),
        id = uid + '-field',
        help = uid + '-help',
        err = uid + '-error';
    const element = isValidElement<any>(children)
        ? cloneElement(children as ReactElement<any>, {
              id: (children as ReactElement<any>).props.id ?? id,
              'aria-invalid': !!error,
              'aria-describedby':
                  [hint ? help : '', error ? err : '']
                      .filter(Boolean)
                      .join(' ') || undefined,
              'aria-required': required || undefined,
          })
        : children;
    return (
        <div className="field">
            <label
                htmlFor={
                    isValidElement(children)
                        ? ((children as ReactElement<any>).props.id ?? id)
                        : id
                }
            >
                {label}
                {required && <span className="text-status-critical"> *</span>}
            </label>
            {element}
            {hint && <small id={help}>{hint}</small>}
            {error && (
                <small id={err} className="text-status-critical">
                    {error}
                </small>
            )}
        </div>
    );
}
export function ChoiceTiles({
    label,
    value,
    options,
    onChange,
    error,
}: {
    label: string;
    value: string;
    options: {
        value: string;
        label: string;
        description: string;
        icon: ComponentType<{ className?: string }>;
        disabled?: boolean;
    }[];
    onChange: (v: string) => void;
    error?: string;
}) {
    const errorId = useId();
    return (
        <div className="field">
            <span>{label}</span>
            <div
                className="preview-choice-tiles"
                role="group"
                aria-label={label}
                aria-invalid={!!error}
                aria-describedby={error ? errorId : undefined}
                tabIndex={error ? -1 : undefined}
            >
                {options.map((o) => (
                    // eslint-disable-next-line no-restricted-syntax -- Pressed choice tile with icon, label and help text layout.
                    <button
                        type="button"
                        key={o.value}
                        aria-pressed={value === o.value}
                        disabled={o.disabled}
                        onClick={() => onChange(o.value)}
                    >
                        <o.icon />
                        <strong>{o.label}</strong>
                        <small>{o.description}</small>
                    </button>
                ))}
            </div>
            {error && (
                <small id={errorId} className="text-status-critical">
                    {error}
                </small>
            )}
        </div>
    );
}
export function Picker({
    label,
    value,
    options,
    onChange,
    disabled = false,
    error,
}: {
    label: string;
    value: string;
    options: { value: string; label: string; detail?: string }[];
    onChange: (v: string) => void;
    disabled?: boolean;
    error?: string;
}) {
    const errorId = useId();
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState('');
    const matches = options.filter((o) =>
        (o.label + ' ' + o.value + ' ' + (o.detail ?? ''))
            .toLowerCase()
            .includes(query.trim().toLowerCase()),
    );
    return (
        <div className="field">
            <span>{label}</span>
            <Popover
                open={open}
                onOpenChange={(value) => {
                    setOpen(value);
                    setQuery('');
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        role="combobox"
                        aria-label={label}
                        aria-invalid={!!error}
                        aria-describedby={error ? errorId : undefined}
                        aria-expanded={open}
                        className="w-full justify-between"
                        disabled={disabled}
                    >
                        <span className="picker-value">
                            {options.find((o) => o.value === value)?.label ??
                                'Search and select…'}
                        </span>
                        <ChevronDown className="shrink-0" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-[340px] p-0">
                    <Command shouldFilter={false}>
                        <CommandInput
                            value={query}
                            onValueChange={setQuery}
                            placeholder={'Search ' + label.toLowerCase()}
                            aria-label={'Search ' + label.toLowerCase()}
                        />
                        <CommandList>
                            <CommandEmpty>
                                No matching authorised records.
                            </CommandEmpty>
                            <CommandGroup>
                                {matches.slice(0, 20).map((o) => (
                                    <CommandItem
                                        key={o.value}
                                        value={o.label + ' ' + (o.detail ?? '')}
                                        onSelect={() => {
                                            onChange(o.value);
                                            setOpen(false);
                                        }}
                                    >
                                        <div>
                                            <strong>{o.label}</strong>
                                            {o.detail && (
                                                <div className="text-caption">
                                                    {o.detail}
                                                </div>
                                            )}
                                        </div>
                                        {value === o.value && (
                                            <Check className="ml-auto" />
                                        )}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                        {matches.length > 20 && (
                            <p className="picker-limit">
                                Showing 20 of {matches.length} matches. Type to
                                narrow the search.
                            </p>
                        )}
                    </Command>
                </PopoverContent>
            </Popover>
            {error && (
                <small id={errorId} className="text-status-critical">
                    {error}
                </small>
            )}
        </div>
    );
}
export function Empty({
    title,
    children,
    action,
}: {
    title: string;
    children: ReactNode;
    action?: ReactNode;
}) {
    return (
        <Card className="empty">
            <Search />
            <h2 className="text-section-title">{title}</h2>
            <p>{children}</p>
            {action}
        </Card>
    );
}
export function Facts({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="facts">
            {rows.map(([k, v]) => (
                <div key={k}>
                    <dt>{k}</dt>
                    <dd>{v}</dd>
                </div>
            ))}
        </dl>
    );
}
