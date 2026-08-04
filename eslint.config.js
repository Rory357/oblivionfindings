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
                        'Use semantic tokens (bg-primary, text-status-success, bg-category-hr) instead of raw Tailwind colour classes. See docs/DESIGN_TOKENS.md.',
                },
                {
                    selector:
                        'TemplateElement[value.raw=/\\b(bg|text|border|ring)-(violet|indigo|purple|fuchsia|emerald|green|lime|red|rose|pink|amber|yellow|orange|blue|sky|cyan|teal|slate|zinc|neutral|stone|gray)-\\d+\\b/]',
                    message:
                        'Use semantic tokens (bg-primary, text-status-success, bg-category-hr) instead of raw Tailwind colour classes. See docs/DESIGN_TOKENS.md.',
                },
                {
                    selector:
                        "JSXElement > JSXOpeningElement[name.name='button']:has(JSXAttribute[name.name='onClick'])",
                    message:
                        'Consider <Button> from @/components/ui/button. If the raw <button> is intentional (custom layout / selector card), add an inline disable comment with reason.',
                },
                {
                    selector:
                        "JSXElement > JSXOpeningElement[name.name='div']:has(JSXAttribute[name.name='className'][value.value=/rounded-(lg|xl|md).*border.*(bg-card|bg-white|bg-background)/])",
                    message:
                        'Consider Card/CardHeader/CardContent from @/components/ui/card for plain rounded bordered panels. Leave custom layout surfaces as raw divs with an inline disable comment.',
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
        // Theme-purity guardrail for the shared PageHero component family.
        // The hero gradient is built on --primary / --primary-foreground tokens
        // so brand changes propagate. text-white / bg-white/* and hex literals
        // inside these files bypass that token system and break dark-mode +
        // white-label support.
        //
        // Scoped narrowly to the page hero source so the rest of the codebase
        // (including marketing pages that genuinely render on coloured photo
        // hero backgrounds) is unaffected.
        files: ['resources/js/components/page/**/*.{ts,tsx}'],
        rules: {
            'no-restricted-syntax': [
                'error',
                {
                    selector:
                        "JSXAttribute[name.name='className'] Literal[value=/\\b(text|bg|border|ring|from|to|via)-(white|black)(\\/\\d+)?\\b/]",
                    message:
                        'Hero components must use --primary-foreground tokens (text-primary-foreground, bg-primary-foreground/10). text-white / bg-white/* bypass the theme system.',
                },
                {
                    selector:
                        'TemplateElement[value.raw=/\\b(text|bg|border|ring|from|to|via)-(white|black)(\\/\\d+)?\\b/]',
                    message:
                        'Hero components must use --primary-foreground tokens, not text-white / bg-white/*.',
                },
                {
                    selector:
                        "JSXAttribute[name.name='className'] Literal[value=/#[0-9a-fA-F]{3,8}\\b/]",
                    message:
                        'No hex colours in className inside hero components. Use theme tokens.',
                },
            ],
        },
    },
    {
        ignores: [
            'vendor',
            'collector/vendor/**',
            'node_modules',
            'public',
            'bootstrap/ssr',
            '.design-drops/**',
            'playwright-report/**',
            'test-results/**',
            'tailwind.config.js',
            // Claude Code agent worktrees: each is a full repo checkout so
            // recursing into them duplicates lint work for every parallel
            // session and overflows ESLint's stylish formatter on machines
            // with several active worktrees. CI never has these.
            '.claude/worktrees/**',
            '.claude/**',
        ],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];
