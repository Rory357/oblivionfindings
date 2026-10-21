import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import type { UrgentContactSetting } from './chat-urgent-contact';

export function ChatUrgentContactSettings({
    value,
    source,
    disabled,
    onChange,
}: {
    value: UrgentContactSetting | null;
    source: string;
    disabled: boolean;
    onChange: (value: UrgentContactSetting | null) => void;
}) {
    const edit = (field: 'label' | 'phone' | 'instructions', next: string) =>
        value && onChange({ ...value, [field]: next, confirmed: false });
    return (
        <section
            className="space-y-3 rounded-lg border p-4"
            aria-label="Service urgent contact settings"
        >
            <div>
                <h3 className="font-semibold">Service urgent contact</h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    Publish a service number that families may use for urgent
                    help. Internal site and staff numbers are not published
                    automatically.
                </p>
                <p className="mt-1 text-xs break-words text-muted-foreground">
                    {source}
                </p>
            </div>
            <label className="flex min-h-[44px] items-center gap-2 text-sm">
                <Checkbox
                    checked={value !== null}
                    disabled={disabled}
                    onCheckedChange={(checked) =>
                        onChange(
                            checked === true
                                ? {
                                      label: '',
                                      phone: '',
                                      instructions: '',
                                      confirmed: false,
                                  }
                                : null,
                        )
                    }
                />
                Configure a family-facing urgent contact
            </label>
            {value && (
                <>
                    <label className="block space-y-1 text-sm">
                        <span>Contact label</span>
                        <Input
                            value={value.label}
                            maxLength={100}
                            disabled={disabled}
                            className="min-h-[44px]"
                            onChange={(e) => edit('label', e.target.value)}
                            placeholder="Service on-call team"
                        />
                    </label>
                    <label className="block space-y-1 text-sm">
                        <span>Service phone number</span>
                        <Input
                            type="tel"
                            value={value.phone}
                            maxLength={32}
                            disabled={disabled}
                            className="min-h-[44px]"
                            onChange={(e) => edit('phone', e.target.value)}
                        />
                    </label>
                    <label className="block space-y-1 text-sm">
                        <span>When and how to use this contact</span>
                        <textarea
                            value={value.instructions}
                            maxLength={500}
                            disabled={disabled}
                            className="min-h-24 w-full rounded-lg border bg-background p-3"
                            onChange={(e) =>
                                edit('instructions', e.target.value)
                            }
                            placeholder="Include any service hours or fallback instructions."
                        />
                    </label>
                    <label className="flex min-h-[44px] items-start gap-2 py-2 text-sm">
                        <Checkbox
                            className="mt-1"
                            checked={value.confirmed}
                            disabled={disabled}
                            onCheckedChange={(checked) =>
                                onChange({
                                    ...value,
                                    confirmed: checked === true,
                                })
                            }
                        />
                        <span>
                            I have checked that this number and guidance are
                            current and approved for families. Recheck after
                            editing.
                        </span>
                    </label>
                </>
            )}
            <p className="text-xs text-muted-foreground">
                Review and save these changes below. An unconfigured service
                shows no urgent phone number.
            </p>
        </section>
    );
}
