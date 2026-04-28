import { Mic, MicOff } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useDictation } from '@/hooks/use-dictation';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { cn } from '@/lib/utils';

export type DictateButtonProps = {
    value: string;
    onChange: (next: string) => void;
    fieldLabel?: string;
    className?: string;
    disabled?: boolean;
    locale?: string;
};

export default function DictateButton({
    value,
    onChange,
    fieldLabel,
    className,
    disabled,
    locale,
}: DictateButtonProps) {
    const t = useMyDayLabels();
    const appendTranscript = (chunk: string) => {
        const current = value ?? '';
        const needsSpace = current.length > 0 && !/\s$/.test(current);
        onChange(needsSpace ? `${current} ${chunk}` : `${current}${chunk}`);
    };

    const { isListening, supported, toggle } = useDictation({
        locale,
        onTranscript: appendTranscript,
    });

    if (!supported) {
        return null;
    }

    const aria = fieldLabel
        ? isListening
            ? `${t('stop_voice_input')} (${fieldLabel})`
            : t('start_voice_input', { field: fieldLabel })
        : isListening
          ? t('stop_voice_input')
          : t('start_voice_input', { field: '' }).replace(/\s*$/, '').trim() ||
            t('dictate');

    return (
        <Button
            type="button"
            variant="outline"
            onClick={toggle}
            disabled={disabled}
            aria-pressed={isListening}
            aria-label={aria}
            className={cn(
                'frontline-focus min-h-9 gap-1.5 px-2.5 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60',
                isListening
                    ? 'border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/60 dark:bg-status-critical-bg dark:text-status-critical'
                    : 'border-border bg-background text-muted-foreground hover:bg-muted/50',
                className,
            )}
        >
            {isListening ? (
                <MicOff aria-hidden className="h-3.5 w-3.5" />
            ) : (
                <Mic aria-hidden className="h-3.5 w-3.5" />
            )}
            <span>{isListening ? `${t('listening')}...` : t('dictate')}</span>
        </Button>
    );
}
