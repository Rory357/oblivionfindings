import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { useEffect, useId } from 'react';
import { ChatAvailabilityControl } from './chat-availability-control';
import { useChatCalling } from './chat-calling';
import {
    chatReadingClass,
    chatSendingHint,
    useChatPreferences,
    type ChatPreferences,
} from './chat-personal-preferences';
import { ChatShiftHandover } from './chat-shift-handover';

export function ChatPreferencesPanel({ actorId }: { actorId: number }) {
    const preferences = useChatPreferences(actorId);
    const calling = useChatCalling();
    const { updateAppearance } = useAppearance();
    const values = preferences.values;
    const disabled =
        !preferences.loaded || preferences.saving || Boolean(preferences.error);
    useEffect(() => {
        if (preferences.loaded)
            updateAppearance(values.theme, { persist: false });
    }, [preferences.loaded, values.theme, updateAppearance]);
    const choices = [
        [
            'Text size',
            'text_size',
            [
                ['normal', 'Normal'],
                ['large', 'Large'],
                ['extra_large', 'Extra large'],
            ],
        ],
        [
            'Message spacing',
            'spacing',
            [
                ['comfortable', 'Comfortable'],
                ['compact', 'Compact'],
            ],
        ],
        [
            'Sending',
            'sending',
            [
                ['enter_sends', 'Enter sends'],
                ['enter_newline', 'Enter adds a line'],
            ],
        ],
        [
            'Theme',
            'theme',
            [
                ['light', 'Light'],
                ['dark', 'Dark'],
                ['system', 'Use device setting'],
            ],
        ],
    ] as const;
    const toggles = [
        [
            'Quiet hours',
            'quiet_enabled',
            'Pause non-essential Chat alerts until you switch this off.',
        ],
        [
            'Message and call sounds',
            'sound_enabled',
            'Browser sound permissions and quiet preferences still apply.',
        ],
        [
            'Show message previews inside the app',
            'show_previews',
            'Only show content that you are currently allowed to read.',
        ],
    ] as const;
    return (
        <div className="w-full space-y-5 p-4 sm:p-6">
            <header>
                <h1 className="text-page-title">My Chat preferences</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Personal choices apply within the organisation’s rules.
                </p>
            </header>
            {preferences.error && (
                <div
                    role="alert"
                    className="space-y-2 rounded-xl border border-status-warning p-3 text-sm"
                >
                    <p>{preferences.error}</p>
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => void preferences.refresh()}
                    >
                        Review latest preferences
                    </Button>
                </div>
            )}
            <div className="grid items-start gap-5 lg:grid-cols-2">
                <section
                    aria-label="Personal Chat preferences"
                    className="space-y-4 rounded-2xl border bg-background p-4 sm:p-5"
                >
                    {choices.slice(0, 3).map(([label, key, options]) => (
                        <PreferenceChoice
                            key={key}
                            label={label}
                            value={values[key]}
                            disabled={disabled}
                            options={options}
                            onChange={(value) =>
                                void preferences.save({
                                    [key]: value,
                                } as Partial<ChatPreferences>)
                            }
                        />
                    ))}
                    <ChatAvailabilityControl
                        actorId={actorId}
                        family={!calling?.canSetOffDuty}
                    />
                    {calling?.canSetOffDuty &&
                        calling.availability === 'off_duty' && (
                            <ChatShiftHandover
                                actorId={actorId}
                                enabled={calling.enabled}
                                active
                                context="off-duty"
                            />
                        )}
                    {choices.slice(3).map(([label, key, options]) => (
                        <PreferenceChoice
                            key={key}
                            label={label}
                            value={values[key]}
                            disabled={disabled}
                            options={options}
                            onChange={(value) =>
                                void preferences.save({
                                    [key]: value,
                                } as Partial<ChatPreferences>)
                            }
                        />
                    ))}
                    {toggles.map(([label, key, help]) => (
                        <div
                            key={key}
                            className="frontline-tap flex items-start justify-between gap-4 border-t py-3 text-sm"
                        >
                            <label htmlFor={`chat-preference-${key}`}>
                                <span className="font-medium">{label}</span>
                                <span className="mt-1 block text-xs text-muted-foreground">
                                    {help}
                                </span>
                            </label>
                            <Switch
                                id={`chat-preference-${key}`}
                                className="mt-1"
                                checked={values[key]}
                                disabled={disabled}
                                onCheckedChange={(checked) =>
                                    void preferences.save({
                                        [key]: checked,
                                    })
                                }
                            />
                        </div>
                    ))}
                    <p className="rounded-xl bg-muted/50 p-3 text-sm">
                        Mandatory operational alerts remain enabled. External
                        previews hide client names and message content. Off duty
                        does not imply an obligation to answer.
                    </p>
                    <p role="status" className="text-sm text-muted-foreground">
                        {preferences.saving
                            ? 'Saving your preference…'
                            : preferences.error
                              ? 'Your latest preferences need review.'
                              : preferences.loaded
                                ? 'Preferences saved'
                                : 'Loading your preferences…'}
                    </p>
                </section>
                <section
                    aria-label="Live reading preview"
                    className="space-y-4 rounded-2xl border bg-background p-4 sm:p-5"
                >
                    <h2 className="text-section-title">
                        Live reading preview
                    </h2>
                    <div
                        className={cn(
                            'rounded-2xl rounded-tl-md border bg-muted/40 leading-relaxed',
                            chatReadingClass(values.text_size),
                            values.spacing === 'compact'
                                ? 'px-3 py-2'
                                : 'px-4 py-3',
                        )}
                    >
                        Your changes apply to the shared conversation view.
                    </div>
                    <p className="text-sm">{chatSendingHint(values.sending)}</p>
                    <p className="text-sm text-muted-foreground">
                        {values.quiet_enabled
                            ? 'Quiet hours on · Non-essential Chat alerts are paused'
                            : 'Quiet hours off'}{' '}
                        ·{' '}
                        {values.sound_enabled
                            ? 'Sounds enabled'
                            : 'Sounds muted'}
                    </p>
                    <div className="space-y-2 rounded-xl border p-3">
                        <h3 className="font-medium">Notification preview</h3>
                        <p className="text-sm">
                            {preferences.loaded && values.show_previews
                                ? 'Alex Example: The afternoon handover is ready.'
                                : 'New Chat message · Open to read'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Fictional example
                        </p>
                    </div>
                    <Button asChild variant="outline" className="frontline-tap">
                        <Link href="/chat">See in my conversation</Link>
                    </Button>
                </section>
            </div>
        </div>
    );
}
function PreferenceChoice({
    label,
    value,
    disabled,
    options,
    onChange,
}: {
    label: string;
    value: string;
    disabled: boolean;
    options: readonly (readonly [string, string])[];
    onChange: (value: string) => void;
}) {
    const id = useId();
    return (
        <div className="space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value} disabled={disabled} onValueChange={onChange}>
                <SelectTrigger id={id} className="frontline-tap">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map(([value, label]) => (
                        <SelectItem key={value} value={value}>
                            {label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
