import js from '@eslint/js';
import prettier from 'eslint-config-prettier/flat';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

/** @type {import('eslint').Linter.Config[]} */
export default [
    {
        linterOptions: {
            reportUnusedDisableDirectives: 'off',
        },
    },
    js.configs.recommended,
    reactHooks.configs.flat.recommended,
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'], // Required for React 17+
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',

            // Pragmatic baseline for large mixed-quality pages while module work continues.
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/no-unused-vars': 'off',
            '@typescript-eslint/ban-ts-comment': 'off',
            '@typescript-eslint/no-empty-object-type': 'off',

            'react-hooks/rules-of-hooks': 'warn',
            'react-hooks/exhaustive-deps': 'warn',
            'react-hooks/set-state-in-effect': 'off',
            'react-hooks/purity': 'off',
            'react-hooks/immutability': 'off',
            'react-hooks/preserve-manual-memoization': 'off',

            'no-empty': 'off',

            // Guardrail: discourage new raw Tailwind colour classes. The app uses
            // semantic tokens (bg-primary, text-status-success, etc.) so that
            // Branding changes propagate. Hardcoded colour shades like bg-violet-600
            // or text-emerald-500 bypass the token system.
            //
            // Severity is 'warn' so developers can still commit pragmatic
            // exceptions (e.g. chart/map gradients, the recruitment pipeline) —
            // CI treats warnings as advisory. Use /* eslint-disable-next-line */
            // with a comment explaining why on intentional exceptions.
            'no-restricted-syntax': [
                'warn',
                {
                    selector:
                        "JSXAttribute[name.name='className'] Literal[value=/\\b(bg|text|border|ring|from|to|via)-(violet|indigo|purple|fuchsia|emerald|green|lime|red|rose|pink|amber|yellow|orange|blue|sky|cyan|teal|slate|zinc|neutral|stone|gray)-\\d+\\b/]",
                    message:
                        "Use semantic tokens (bg-primary, text-status-success, bg-category-hr) instead of raw Tailwind colour classes. See docs/DESIGN_TOKENS.md.",
                },
                {
                    selector:
                        "TemplateElement[value.raw=/\\b(bg|text|border|ring)-(violet|indigo|purple|fuchsia|emerald|green|lime|red|rose|pink|amber|yellow|orange|blue|sky|cyan|teal|slate|zinc|neutral|stone|gray)-\\d+\\b/]",
                    message:
                        "Use semantic tokens (bg-primary, text-status-success, bg-category-hr) instead of raw Tailwind colour classes. See docs/DESIGN_TOKENS.md.",
                },
            ],
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'tailwind.config.js'],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];
