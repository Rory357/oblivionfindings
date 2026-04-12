import axios from 'axios';
import { toast } from 'sonner';

type SetError = (field: string, value: string) => void;

function firstErrorMessage(value: unknown): string | null {
    if (Array.isArray(value)) {
        return typeof value[0] === 'string' ? value[0] : null;
    }

    return typeof value === 'string' ? value : null;
}

export function applyFormRequestErrors(
    error: unknown,
    setError: SetError,
    fallbackMessage: string,
) {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors;

        if (errors && typeof errors === 'object') {
            let applied = false;

            Object.entries(errors as Record<string, unknown>).forEach(
                ([field, value]) => {
                    const message = firstErrorMessage(value);
                    if (!message) {
                        return;
                    }

                    applied = true;
                    setError(field, message);
                },
            );

            if (applied) {
                return;
            }
        }

        const responseMessage =
            typeof error.response?.data?.message === 'string'
                ? error.response.data.message
                : typeof error.response?.data?.error === 'string'
                  ? error.response.data.error
                  : null;

        toast.error(responseMessage ?? fallbackMessage);

        return;
    }

    if (error instanceof Error && error.message) {
        toast.error(error.message);
        return;
    }

    toast.error(fallbackMessage);
}
