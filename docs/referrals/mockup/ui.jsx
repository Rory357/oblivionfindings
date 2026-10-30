import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    AlertTriangle,
    Check,
    ChevronDown,
    ClipboardList,
    Info,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { usePrototype } from './context';
import { date } from './data';
export {
    Button,
    Checkbox,
    Input,
    ReviewCard,
    ReviewRow,
    Switch,
    Textarea,
    WizardSuccessPane,
};
export function Badge({ children, tone }) {
    const text = String(children);
    return (
        <StatusBadge
            variant={
                tone ||
                (text.match(
                    /Verified|Active|Reviewed|Converted|Full approval|Complete/,
                )
                    ? 'success'
                    : text.match(/Declined|Overdue|Quarantined|Failed|Closed/)
                      ? 'critical'
                      : text.match(
                              /Preparing|Pending|hold|Partial|Conditional|New|Unassigned|Funding|Waiting/,
                          )
                        ? 'warning'
                        : 'neutral')
            }
        >
            {children}
        </StatusBadge>
    );
}
export function Notice({ children, tone = 'info' }) {
    return (
        <div
            role={tone === 'critical' ? 'alert' : 'status'}
            className={'notice notice-' + tone}
        >
            {tone === 'critical' ? (
                <AlertTriangle />
            ) : tone === 'success' ? (
                <ShieldCheck />
            ) : (
                <Info />
            )}
            <div>{children}</div>
        </div>
    );
}
export function Section({
    title,
    caption,
    icon: Icon = ClipboardList,
    actions,
    children,
}) {
    return (
        <section className="panel">
            <div className="section-head">
                <div className="section-title">
                    <Icon />
                    <div>
                        <h2 className="text-section-title">{title}</h2>
                        {caption && <p className="text-subtle">{caption}</p>}
                    </div>
                </div>
                <div className="actions">{actions}</div>
            </div>
            {children}
        </section>
    );
}
export function Empty({ title = 'Nothing here yet', children, action }) {
    return (
        <div className="empty">
            <ClipboardList />
            <h2 className="text-section-title">{title}</h2>
            <p className="text-subtle">{children}</p>
            {action}
        </div>
    );
}
export function Tabs({ items, value, onChange }) {
    return (
        <TierTwoTabs
            tabs={items.map((x) =>
                typeof x === 'string'
                    ? { key: x, label: x, icon: ClipboardList }
                    : x,
            )}
            activeTab={value}
            onTab={onChange}
            ariaLabel="Workspace sections"
            renderLink={(tab, cls, inner, a11y) => (
                <button
                    key={tab.key}
                    className={cls}
                    {...a11y}
                    onClick={() => onChange(tab.key)}
                >
                    {inner}
                </button>
            )}
        />
    );
}
export function Select({ value, onChange, options, label, id, disabled }) {
    return (
        <select
            className="fixed-select"
            aria-label={label}
            id={id}
            value={value}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
        >
            {options.map((o) => (
                <option
                    key={typeof o === 'string' ? o : o.value}
                    value={typeof o === 'string' ? o : o.value}
                >
                    {typeof o === 'string' ? o : o.label}
                </option>
            ))}
        </select>
    );
}
export function SearchSelect({ value, onChange, options, label, disabled }) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    disabled={disabled}
                    aria-expanded={open}
                    aria-label={label}
                    className="w-full justify-between"
                >
                    {value || 'Choose…'}
                    <ChevronDown />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="p-0">
                <Command>
                    <CommandInput
                        aria-label={'Find ' + label.toLowerCase()}
                        placeholder={'Find ' + label.toLowerCase()}
                    />
                    <CommandList>
                        <CommandEmpty>No matching options</CommandEmpty>
                        <CommandGroup>
                            {options.map((o) => (
                                <CommandItem
                                    key={o}
                                    value={o}
                                    onSelect={() => {
                                        onChange(o);
                                        setOpen(false);
                                    }}
                                >
                                    {o}
                                    {o === value && (
                                        <Check className="ml-auto" />
                                    )}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
export function Field({
    label,
    value,
    onChange,
    type = 'text',
    options,
    hint,
    required,
    disabled,
    min,
    max,
}) {
    const id = useId();
    return (
        <div className="field">
            <label htmlFor={id}>
                {label}
                {required ? ' *' : ''}
            </label>
            {type === 'select' ? (
                <Select
                    id={id}
                    label={label}
                    options={options}
                    value={value}
                    onChange={onChange}
                    disabled={disabled}
                />
            ) : type === 'search' ? (
                <SearchSelect
                    label={label}
                    options={options}
                    value={value}
                    onChange={onChange}
                    disabled={disabled}
                />
            ) : type === 'textarea' ? (
                <Textarea
                    id={id}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                />
            ) : type === 'date' ? (
                <DatePicker
                    id={id}
                    label={label}
                    value={value || ''}
                    onChange={onChange}
                    compact
                />
            ) : type === 'time' ? (
                <TimePicker
                    id={id}
                    label={label}
                    value={value || ''}
                    onChange={onChange}
                    compact
                    timezone="Pacific/Auckland"
                />
            ) : type === 'check' ? (
                <div className="check-line">
                    <Checkbox
                        aria-label={label}
                        aria-required={required}
                        id={id}
                        checked={!!value}
                        onCheckedChange={onChange}
                        disabled={disabled}
                    />
                    <span>{value ? 'Confirmed' : 'Not confirmed'}</span>
                </div>
            ) : (
                <Input
                    id={id}
                    type={type}
                    value={value ?? ''}
                    min={min}
                    max={max}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                />
            )}{' '}
            {hint && (
                <p className="text-caption text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}
export function Modal({ title, description, children, onClose, actions }) {
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[88vh] overflow-y-auto sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {description || 'Review the details before continuing.'}
                    </DialogDescription>
                </DialogHeader>
                {children}
                <DialogFooter>
                    {actions || (
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
export function Wizard({
    title,
    description,
    steps,
    initial = {},
    onSave,
    onClose,
    onDraft,
    submitLabel = 'Save',
    submitVariant = 'default',
    failure,
    clearFailure,
}) {
    const { setWizardDirty } = usePrototype();
    const [values, setValues] = useState(initial),
        [step, setStep] = useState(0),
        [error, setError] = useState(''),
        [success, setSuccess] = useState(null),
        [guard, setGuard] = useState(false);
    const changed = JSON.stringify(values) !== JSON.stringify(initial);
    useEffect(() => {
        setWizardDirty(changed && !success);
        return () => setWizardDirty(false);
    }, [changed, success, setWizardDirty]);
    const bodyRef = useRef(null),
        headingRef = useRef(null),
        errorRef = useRef(null);
    useEffect(() => {
        bodyRef.current?.scrollTo({ top: 0 });
        headingRef.current?.focus({ preventScroll: true });
    }, [step]);
    useEffect(() => {
        if (error) {
            errorRef.current?.focus();
            errorRef.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [error]);
    useEffect(() => {
        const listener = (e) => {
            if (changed && !success) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', listener);
        return () => window.removeEventListener('beforeunload', listener);
    }, [changed, success]);
    const set = (k, v) =>
        setValues((s) => ({
            ...s,
            [k]: v,
            ...(k === 'relationship' && v === 'For myself'
                ? { generalOnly: false }
                : {}),
        }));
    const validate = (index = step) => {
        for (const f of steps[index]?.fields || []) {
            if (f.when && !f.when(values)) continue;
            if (
                f.required &&
                (f.type === 'check'
                    ? values[f.key] !== true
                    : !String(values[f.key] ?? '').trim())
            )
                return f.label + ' is required.';
            if (
                f.type === 'email' &&
                values[f.key] &&
                !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values[f.key])
            )
                return 'Enter a valid email address.';
            if (
                f.type === 'number' &&
                values[f.key] !== '' &&
                (!Number.isFinite(Number(values[f.key])) ||
                    Number(values[f.key]) < 0)
            )
                return f.label + ' must be zero or greater.';
        }
        return steps[index]?.validate?.(values) || '';
    };
    const next = () => {
        const err = validate();
        if (err) {
            setError(err);
            return;
        }
        setError('');
        setStep(step + 1);
    };
    const save = () => {
        const err = steps.map((_, index) => validate(index)).find(Boolean);
        if (err) {
            setError(err);
            return;
        }
        if (
            failure === 'Save failure' ||
            failure === 'Conflict' ||
            failure === 'Service unavailable'
        ) {
            setError(
                failure === 'Conflict'
                    ? 'Simulated conflict: your entries are preserved and nothing was saved. Use Back or Edit to review your changes, then save again. This preview does not connect to other users’ records.'
                    : 'The save did not complete. Your entries are preserved. Retry when ready.',
            );
            clearFailure();
            return;
        }
        const result = onSave(values);
        if (result?.error) {
            setError(result.error);
            return;
        }
        setError('');
        setSuccess(
            result?.message ||
                'Your changes have been saved in this prototype.',
        );
    };
    const close = () => (changed && !success ? setGuard(true) : onClose());
    const wizardSteps = [
        ...steps,
        {
            key: 'review',
            label: 'Review',
            blurb: 'Check and confirm',
            icon: ShieldCheck,
        },
    ];
    return (
        <>
            <WizardShell
                open
                frontline
                bodyRef={bodyRef}
                onClose={close}
                title={title}
                description={description || title}
                railIcon={ClipboardList}
                railTitle={title}
                railSub={description || 'A clear next step for every person'}
                steps={wizardSteps.map((s, i) => ({
                    ...s,
                    key: s.key || String(i),
                    icon: s.icon || ClipboardList,
                    blurb: s.blurb || 'Add the details',
                }))}
                stepIndex={step}
                onStepClick={(i) => {
                    if (i <= step) {
                        setStep(i);
                        setError('');
                    }
                }}
                pct={Math.round((step / (wizardSteps.length - 1)) * 100)}
                footerStart={
                    onDraft && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                try {
                                    onDraft(values);
                                    onClose();
                                } catch {
                                    setError(
                                        'The draft could not be saved in this browser. Your entries are still here. Keep this form open or submit when ready.',
                                    );
                                }
                            }}
                        >
                            Save draft & close
                        </Button>
                    )
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            onClick={
                                step
                                    ? () => {
                                          setStep(step - 1);
                                          setError('');
                                      }
                                    : close
                            }
                        >
                            {step ? 'Back' : 'Cancel'}
                        </Button>
                        {step < steps.length ? (
                            <Button onClick={next}>Continue</Button>
                        ) : (
                            <Button variant={submitVariant} onClick={save}>
                                {submitLabel}
                            </Button>
                        )}
                    </>
                }
                success={
                    success && (
                        <WizardSuccessPane
                            title="Saved successfully"
                            blurb={success}
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    )
                }
            >
                <WizardStepPane>
                    {error && (
                        <div ref={errorRef} tabIndex={-1}>
                            <Notice tone="critical">{error}</Notice>
                        </div>
                    )}
                    {step < steps.length ? (
                        <>
                            <h2
                                ref={headingRef}
                                tabIndex={-1}
                                className="text-section-title mb-4"
                            >
                                {steps[step].label}
                            </h2>
                            {steps[step].notice && (
                                <Notice>{steps[step].notice}</Notice>
                            )}
                            <div className="form-grid">
                                {steps[step].fields
                                    ?.filter((f) => !f.when || f.when(values))
                                    .map((f) => (
                                        <Field
                                            key={f.key}
                                            {...f}
                                            options={
                                                typeof f.options === 'function'
                                                    ? f.options(values)
                                                    : f.options
                                            }
                                            value={values[f.key]}
                                            onChange={(v) => set(f.key, v)}
                                        />
                                    ))}
                            </div>
                            {steps[step].render?.(values, set)}
                        </>
                    ) : (
                        <div className="stack">
                            <Notice>
                                Nothing is sent to external agencies. Confirming
                                updates this fictional case only.
                            </Notice>
                            {steps.map((s, i) => (
                                <ReviewCard
                                    key={i}
                                    icon={s.icon || ClipboardList}
                                    title={s.label}
                                    onEdit={() => setStep(i)}
                                >
                                    {s.fields
                                        ?.filter(
                                            (f) => !f.when || f.when(values),
                                        )
                                        .map((f) => (
                                            <ReviewRow
                                                key={f.key}
                                                label={f.label}
                                                value={
                                                    typeof values[f.key] ===
                                                    'boolean'
                                                        ? values[f.key]
                                                            ? 'Yes'
                                                            : 'No'
                                                        : f.type === 'date'
                                                          ? date(values[f.key])
                                                          : f.type ===
                                                                  'select' &&
                                                              typeof f
                                                                  .options?.[0] ===
                                                                  'object'
                                                            ? f.options.find(
                                                                  (o) =>
                                                                      o.value ===
                                                                      values[
                                                                          f.key
                                                                      ],
                                                              )?.label ||
                                                              values[f.key]
                                                            : values[f.key]
                                                }
                                            />
                                        ))}
                                    {s.review?.(values)}
                                </ReviewCard>
                            ))}
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            {guard && (
                <Modal
                    title="Keep your unfinished work?"
                    description="Your entries have not been submitted."
                    onClose={() => setGuard(false)}
                    actions={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setGuard(false)}
                            >
                                Keep editing
                            </Button>
                            {onDraft && (
                                <Button
                                    onClick={() => {
                                        onDraft(values);
                                        onClose();
                                    }}
                                >
                                    Save draft
                                </Button>
                            )}
                            <Button variant="destructive" onClick={onClose}>
                                Discard changes
                            </Button>
                        </>
                    }
                />
            )}
        </>
    );
}
export function download(name, content, type = 'text/plain') {
    const url = URL.createObjectURL(new Blob([content], { type }));
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
