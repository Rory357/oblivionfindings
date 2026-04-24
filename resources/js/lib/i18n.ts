import { usePage } from '@inertiajs/react';

interface TranslationTree {
    [key: string]: string | TranslationTree;
}

type LocaleMeta = {
    label: string;
    native?: string;
};

type I18nProps = {
    locale?: string;
    availableLocales?: Record<string, LocaleMeta>;
    translations?: Record<string, TranslationTree>;
};

export function translate(
    translations: Record<string, TranslationTree> | undefined,
    key: string,
    fallback?: string,
): string {
    const value = key
        .split('.')
        .reduce<TranslationTree | string | undefined>((carry, segment) => {
            if (!carry || typeof carry === 'string') {
                return undefined;
            }

            return carry[segment];
        }, translations);

    return typeof value === 'string' ? value : (fallback ?? key);
}

export function useI18n() {
    const { locale, availableLocales, translations } =
        usePage<I18nProps>().props;

    return {
        locale: locale ?? 'en',
        availableLocales: availableLocales ?? { en: { label: 'English (NZ)' } },
        t: (key: string, fallback?: string) =>
            translate(translations, key, fallback),
    };
}
