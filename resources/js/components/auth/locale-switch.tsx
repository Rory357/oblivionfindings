import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update } from '@/routes/locale';
import { router } from '@inertiajs/react';

const LABELS: Record<string, string> = {
    en: 'English',
    mi: 'Te reo Māori',
};

/** English / Te reo Māori switch on the sign-in pages (stored in a cookie). */
export function LocaleSwitch({ className }: { className?: string }) {
    const { locale, availableLocales } = useI18n();
    const codes = Object.keys(availableLocales);

    if (codes.length < 2) {
        return null;
    }

    return (
        <div
            role="group"
            aria-label="Language"
            className={cn(
                'flex gap-0.5 rounded-[11px] border border-border bg-muted p-[3px]',
                className,
            )}
        >
            {codes.map((code) => {
                const active = code === locale;

                return (
                    <Button
                        key={code}
                        unstyled
                        type="button"
                        lang={code}
                        aria-pressed={active}
                        onClick={() =>
                            !active &&
                            router.post(
                                update().url,
                                { locale: code },
                                { preserveScroll: true, preserveState: true },
                            )
                        }
                        className={cn(
                            'frontline-hit h-8 rounded-lg px-3 text-[13.5px] font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring',
                            active
                                ? 'bg-journey-surface text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {LABELS[code] ?? availableLocales[code]?.native ?? code}
                    </Button>
                );
            })}
        </div>
    );
}
