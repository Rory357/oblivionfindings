import { Button } from '@/components/ui/button';
import { LockKeyhole } from 'lucide-react';

export function WorkflowTemplateDestination({
    canManage,
}: {
    canManage: boolean;
}) {
    if (!canManage) {
        return (
            <span
                role="note"
                className="flex min-h-11 items-center gap-2 px-2 text-sm text-muted-foreground"
            >
                <LockKeyhole className="h-4 w-4" aria-hidden />
                IT management access required
            </span>
        );
    }

    return (
        <Button asChild size="sm" variant="outline" className="min-h-11">
            <a href="/it/setup" className="frontline-focus">
                Manage workflow templates
            </a>
        </Button>
    );
}
