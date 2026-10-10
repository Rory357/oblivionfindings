import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { AlertTriangle, CheckCircle2, RefreshCw } from 'lucide-react';
import type { TemplateCommand } from './use-template-command';

export function TemplateCommandNotice({
    command,
    onReload,
}: {
    command: TemplateCommand;
    onReload?: () => void;
}) {
    if (!command.notice) return null;
    const confirmed = command.notice.kind === 'confirmed';
    return (
        <Alert className="mb-4" role="status">
            {confirmed ? (
                <CheckCircle2 className="size-4" />
            ) : (
                <AlertTriangle className="size-4" />
            )}
            <AlertTitle>
                {confirmed
                    ? 'Template confirmed'
                    : command.notice.kind === 'read'
                      ? 'Library refreshed'
                      : 'Review before continuing'}
            </AlertTitle>
            <AlertDescription>
                <p>{command.notice.message}</p>
                {command.needsRead ? (
                    <Button
                        className="mt-3"
                        variant="outline"
                        disabled={command.busy}
                        onClick={onReload ?? (() => command.refresh())}
                    >
                        <RefreshCw className="size-4" />{' '}
                        {command.busy ? 'Reloading…' : 'Reload library'}
                    </Button>
                ) : null}
            </AlertDescription>
        </Alert>
    );
}
