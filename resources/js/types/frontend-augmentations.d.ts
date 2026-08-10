import '@inertiajs/core';
import 'react';

declare module 'react' {
    interface HTMLAttributes<T> {
        dusk?: string;
    }

    interface SVGProps<T> {
        dusk?: string;
    }
}

declare module '@inertiajs/core' {
    interface Router {
        reload<T extends RequestPayload = RequestPayload>(
            options?: ReloadOptions<T> &
                Pick<VisitOptions<T>, 'preserveScroll'>,
        ): void;
    }
}
