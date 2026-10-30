import { Button } from '@/components/ui/button';
import { RefreshCw } from 'lucide-react';

/** Loaded with the app entry so a failed page chunk still has a recovery UI. */
export default function PageLoadError() {
    return (
        <main className="flex min-h-screen items-center justify-center bg-background p-6 text-foreground">
            <section
                role="alert"
                className="max-w-lg space-y-4 rounded-xl border bg-card p-6"
            >
                <h1 className="text-xl font-semibold">
                    This page couldn’t load
                </h1>
                <p className="text-sm text-muted-foreground">
                    Check your connection, then try again. The page may have
                    been updated since you opened it.
                </p>
                <Button
                    className="frontline-tap"
                    onClick={() => window.location.reload()}
                >
                    <RefreshCw className="size-4" aria-hidden="true" /> Reload
                    this page
                </Button>
            </section>
        </main>
    );
}
