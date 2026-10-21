import { Button } from '@/components/ui/button';
import { useId, useRef } from 'react';
import {
    friendlyNotificationDescription,
    friendlyNotificationName,
} from './notification-catalog';

export type ChatNotificationPreference = {
    enabled: boolean;
    inapp: boolean;
    email: boolean;
    push: boolean;
};

/** Used inside both the existing personal preferences and role-default editor. */
export function ChatNotificationFields({
    eventKey,
    value,
    onChange,
    disabled = false,
    inherited,
    inheritSource = 'role',
    onInherit,
}: {
    eventKey: string;
    value: ChatNotificationPreference;
    onChange: (field: keyof ChatNotificationPreference, value: boolean) => void;
    disabled?: boolean;
    inherited?: boolean;
    inheritSource?: 'role' | 'organisation';
    onInherit?: () => void;
}) {
    const id = useId();
    const receiveControl = useRef<HTMLInputElement>(null);
    const name = friendlyNotificationName(eventKey);
    return (
        <fieldset
            disabled={disabled}
            className="min-w-0 space-y-2 text-left"
            aria-describedby={`${id}-detail ${id}-delivery`}
        >
            <legend className="text-sm font-medium">{name}</legend>
            <p id={`${id}-detail`} className="text-xs text-muted-foreground">
                {friendlyNotificationDescription(eventKey)}
            </p>
            <div className="flex flex-wrap gap-x-5 gap-y-1">
                {(['enabled', 'inapp', 'email', 'push'] as const).map(
                    (field) => {
                        const unavailable =
                            field === 'email' || field === 'push';
                        const label = {
                            enabled: 'Receive notices',
                            inapp: 'In-app',
                            email: 'Email',
                            push: 'Push',
                        }[field];
                        return (
                            <label
                                key={field}
                                className="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm has-[:disabled]:cursor-default has-[:disabled]:text-muted-foreground"
                            >
                                <input
                                    ref={
                                        field === 'enabled'
                                            ? receiveControl
                                            : undefined
                                    }
                                    type="checkbox"
                                    className="size-4 accent-primary"
                                    aria-label={`${name}: ${label.toLowerCase()}`}
                                    checked={!unavailable && value[field]}
                                    disabled={
                                        unavailable ||
                                        (field !== 'enabled' && !value.enabled)
                                    }
                                    onChange={(event) =>
                                        onChange(
                                            field,
                                            event.currentTarget.checked,
                                        )
                                    }
                                />
                                <span>
                                    {label}
                                    {unavailable && (
                                        <span className="ml-1 text-xs">
                                            (not available yet)
                                        </span>
                                    )}
                                </span>
                            </label>
                        );
                    },
                )}
            </div>
            <p id={`${id}-delivery`} className="text-xs text-muted-foreground">
                {eventKey === 'chat.family.assigned'
                    ? 'In-app notices only. Quiet time delays delivery; current ownership and access are checked again before delivery and when opening the notice.'
                    : eventKey === 'chat.call.missed'
                      ? 'In-app notices only. Quiet time delays delivery; your invitation and current history access are checked again before delivery and when opening the notice.'
                      : 'In-app notices only. Quiet time delays delivery; access and unread state are checked again before a notice is delivered.'}
            </p>
            {onInherit && (
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="text-xs text-muted-foreground">
                        {inherited
                            ? `Following ${inheritSource} defaults`
                            : 'Your preference'}
                    </span>
                    {!inherited && (
                        <Button
                            type="button"
                            variant="link"
                            className="min-h-[44px] px-0 text-xs whitespace-normal"
                            onClick={() => {
                                onInherit();
                                receiveControl.current?.focus({
                                    preventScroll: true,
                                });
                            }}
                        >
                            Use {inheritSource} defaults
                        </Button>
                    )}
                </div>
            )}
        </fieldset>
    );
}
