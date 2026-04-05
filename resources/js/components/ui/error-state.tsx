import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface ErrorStateProps {
    title?: string;
    message?: string;
    onRetry?: () => void;
    className?: string;
}

export function ErrorState({
    title = 'Something went wrong',
    message = 'An error occurred. Please try again.',
    onRetry,
    className = ''
}: ErrorStateProps) {
    return (
        <div className={`flex flex-col items-center justify-center py-12 text-center ${className}`}>
            <AlertTriangle className="h-12 w-12 text-destructive/50" />
            <h3 className="mt-4 text-lg font-medium">{title}</h3>
            <p className="mt-2 text-sm text-muted-foreground">{message}</p>
            {onRetry && (
                <Button variant="outline" onClick={onRetry} className="mt-6">
                    Try again
                </Button>
            )}
        </div>
    );
}
