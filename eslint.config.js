import js from '@eslint/js';
import prettier from 'eslint-config-prettier/flat';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import { builtinRules } from 'eslint/use-at-your-own-risk';
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

            // Pop-up kit (design_styles/POPUP_STYLE_GUIDE.md): no browser
            // alert() / confirm() / prompt() — use the kit's Confirm or a Form.
            'no-alert': 'error',

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
                        'Use semantic tokens (bg-primary, text-status-success, bg-category-hr) instead of raw Tailwind colour classes. See design_styles/DESIGN_TOKENS.md.',
                },
                {
                    selector:
                        'TemplateElement[value.raw=/\\b(bg|text|border|ring)-(violet|indigo|purple|fuchsia|emerald|green|lime|red|rose|pink|amber|yellow|orange|blue|sky|cyan|teal|slate|zinc|neutral|stone|gray)-\\d+\\b/]',
                    message:
                        'Use semantic tokens (bg-primary, text-status-success, bg-category-hr) instead of raw Tailwind colour classes. See design_styles/DESIGN_TOKENS.md.',
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
                        'Hero components must use --band-foreground tokens (text-band-foreground, bg-band-foreground/10). text-white / bg-white/* bypass the theme system.',
                },
                {
                    selector:
                        'TemplateElement[value.raw=/\\b(text|bg|border|ring|from|to|via)-(white|black)(\\/\\d+)?\\b/]',
                    message:
                        'Hero components must use --band-foreground tokens, not text-white / bg-white/*.',
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
        // DESIGN.md anti-pattern "Recolouring a primary button with bg-*
        // classes": a <Button> or <AlertDialogAction> left on the default
        // variant carries .btn-soft-primary, whose unlayered `background`
        // gradient paints over Tailwind's layered bg-destructive /
        // bg-status-* / bg-white / bg-primary-foreground, so the button
        // renders purple (hero "white primary" buttons: purple text on
        // purple). `unstyled` Buttons skip buttonVariants, so their bg-*
        // classes do apply.
        //
        // This is core no-restricted-syntax under its own rule id: ~200 files
        // open with a blanket `eslint-disable no-restricted-syntax` for the
        // raw-colour warning above, which would silence this check too.
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: {
            design: {
                rules: {
                    'no-recoloured-primary-button': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                    'no-primary-foreground-on-fill': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                    'band-text-token': builtinRules.get('no-restricted-syntax'),
                    'no-sidebar-bg-without-foreground': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                    'no-destructive-foreground-off-fill': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                    'no-rem-tap-floor': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                    'no-accent-active-state': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                    'no-blanket-descendant-size': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                    'no-emoji-icon': builtinRules.get('no-restricted-syntax'),
                    'no-oblivion-fallback': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                    'no-edge-accent-line': builtinRules.get(
                        'no-restricted-syntax',
                    ),
                },
            },
        },
        rules: {
            // DESIGN.md "Brand-filled text on bg-primary": a solid brand
            // fill that carries text uses the fill pair, which stays
            // ≥ 4.5:1 for any brand in both modes; text-primary-foreground is
            // the brand sky's text and is white on light brands (~3.6:1).
            // Matches one class string pairing a solid `bg-primary` with
            // `text-primary-foreground` under the same variant prefix.
            'design/no-primary-foreground-on-fill': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/(?:^|\\s)((?:[^\\s:]+:)*)bg-primary(?=\\s|$)[\\s\\S]*(?:^|\\s)\\1text-primary-foreground(?=\\s|$|\\x2f)|(?:^|\\s)((?:[^\\s:]+:)*)text-primary-foreground(?=\\s|$|\\x2f)[\\s\\S]*(?:^|\\s)\\2bg-primary(?=\\s|$)/], TemplateElement[value.raw=/(?:^|\\s)((?:[^\\s:]+:)*)bg-primary(?=\\s|$)[\\s\\S]*(?:^|\\s)\\1text-primary-foreground(?=\\s|$|\\x2f)|(?:^|\\s)((?:[^\\s:]+:)*)text-primary-foreground(?=\\s|$|\\x2f)[\\s\\S]*(?:^|\\s)\\2bg-primary(?=\\s|$)/])',
                    message:
                        'Text on a solid brand fill uses the fill pair: bg-primary-fill + text-primary-fill-foreground (≥ 4.5:1 for any brand, both modes). text-primary-foreground is the brand sky text and stays white on light brands (~3.6:1). See DESIGN.md "Brand-filled text on bg-primary".',
                },
            ],
            'design/no-recoloured-primary-button': [
                'error',
                {
                    selector:
                        "JSXOpeningElement[name.name=/^(Button|AlertDialogAction)$/]:not(:has(> JSXAttribute[name.name='unstyled'])):not(:has(> JSXAttribute[name.name='variant']:not([value.value='default']))) > JSXAttribute[name.name='className'] :matches(Literal[value=/\\bbg-(destructive|status-(critical|success|warning|info)|white|primary-foreground|band-foreground)/], TemplateElement[value.raw=/\\bbg-(destructive|status-(critical|success|warning|info)|white|primary-foreground|band-foreground)/])",
                    message:
                        'The default Button variant (.btn-soft-primary gradient) paints over bg-destructive / bg-status-* / bg-white / bg-primary-foreground, so this renders purple. Use variant="destructive" for destructive actions; otherwise keep the default primary, or variant="outline" inside a status banner. On a hero, use PageHeaderPrimaryButton (white primary) or PageHeaderGlassButton (glass secondary). See DESIGN.md "Recolouring a primary button with bg-* classes".',
                },
            ],
            // DESIGN.md non-negotiable 8 (Stephan, 31 Oct 2026: "never look like this it is obvious a AI thing"):
            // no coloured line on any edge of a box. Matches one class string that pairs a thick edge width
            // (border-t/l/r/b-2|3|4|8|[Npx]) with a coloured edge (border-t/l/r/b-primary|status-*|category-*|…).
            'design/no-edge-accent-line': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/^(?=[\\s\\S]*(?:^|\\s)(?:[^\\s:]+:)*border-[tlrbxy]-(?:2|3|4|8|\\[\\d+px\\])(?=\\s|$))(?=[\\s\\S]*(?:^|\\s)(?:[^\\s:]+:)*border-[tlrbxy]-(?:primary|status-|category-|destructive|chart-|live|accent))/], TemplateElement[value.raw=/^(?=[\\s\\S]*(?:^|\\s)(?:[^\\s:]+:)*border-[tlrbxy]-(?:2|3|4|8|\\[\\d+px\\])(?=\\s|$))(?=[\\s\\S]*(?:^|\\s)(?:[^\\s:]+:)*border-[tlrbxy]-(?:primary|status-|category-|destructive|chart-|live|accent))/])',
                    message:
                        'No coloured line on any edge of a card, surface, strip, panel or pop-up (DESIGN.md non-negotiable 8 and the anti-pattern "Coloured edge lines"). Keep the neutral border; say what the box means with its title, a StatusBadge, a ring or an icon.',
                },
            ],
        },
    },
    {
        // DESIGN.md "Chat — the Chat v3 boards" and its anti-patterns (corrected
        // 2026-10-22). Chat is rebuilt to the approved boards; these guard the
        // rebuild. The ignored files predate the rule and are rebuilt in stage 4.
        files: [
            'resources/js/components/chat/**/*.{ts,tsx}',
            'resources/js/pages/chat/**/*.{ts,tsx}',
        ],
        ignores: [
            '**/*.test.tsx',
            '**/*.test.ts',
            'resources/js/components/chat/chat-attachment-publication.tsx',
            'resources/js/components/chat/chat-attachment-queue.tsx',
            'resources/js/components/chat/chat-voice-recorder.tsx',
            'resources/js/components/chat/chat-thread.tsx',
        ],
        rules: {
            'design/no-blanket-descendant-size': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/\\[&_[^\\]]+\\]:min-[hw]-/], TemplateElement[value.raw=/\\[&_[^\\]]+\\]:min-[hw]-/])',
                    message:
                        'Blanket descendant min-height/min-width rules also catch switches and compact controls. Give a control that needs a larger target .frontline-hit. See DESIGN.md non-negotiable 5 and "Chat — the Chat v3 boards".',
                },
            ],
            'design/no-emoji-icon': [
                'error',
                {
                    selector:
                        'JSXText[value=/[\\uD83C-\\uD83E][\\uDC00-\\uDFFF]|[\\u2600-\\u27BF]/]',
                    message:
                        'Emoji are message content (messages, reactions, the emoji picker, stickers), never icons or labels in chat chrome. Use a lucide icon. See DESIGN.md "Icons" and "Chat — the Chat v3 boards".',
                },
            ],
        },
    },
    {
        // DESIGN.md "Tap targets sized in rem" (EA-210): the root font is 13–16 px, so
        // min-h-11 / min-w-11 (2.75rem) render at 35.75–44 px. Medication
        // screens are frontline: use .frontline-tap (max(44px, 2.75rem)), or
        // .frontline-hit for a compact control that keeps its drawn size.
        files: [
            'resources/js/pages/emar/**/*.{ts,tsx}',
            'resources/js/components/emar/**/*.{ts,tsx}',
            'resources/js/pages/meds/**/*.{ts,tsx}',
        ],
        ignores: ['**/*.test.tsx', '**/*.test.ts'],
        rules: {
            'design/no-rem-tap-floor': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/(?:^|\\s)(?:[^\\s:]+:)*min-[hw]-11(?=\\s|$)/], TemplateElement[value.raw=/(?:^|\\s)(?:[^\\s:]+:)*min-[hw]-11(?=\\s|$)/])',
                    message:
                        'min-h-11 / min-w-11 render below 44 px at the 13–14 px root font. Use .frontline-tap (or .frontline-hit for a compact control). See DESIGN.md "Tap targets sized in rem".',
                },
            ],
        },
    },
    {
        // DESIGN.md "A light panel painted bg-sidebar": the app sidebar has
        // been near-black since 7 Sep, so bg-sidebar is a dark surface. A
        // class string that paints it must also set the sidebar foreground;
        // a light panel (wizard rail, card, table header) uses bg-muted/30
        // like the WizardShell rail. ui/sidebar.tsx is the shadcn primitive,
        // whose text colour is set on its wrapper.
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['resources/js/components/ui/sidebar.tsx'],
        rules: {
            'design/no-sidebar-bg-without-foreground': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/^(?![\\s\\S]*(?:^|\\s)text-sidebar-(?:accent-)?foreground(?:\\x2f\\d+)?(?:\\s|$))[\\s\\S]*(?:^|\\s)bg-sidebar(?:\\s|$)/], TemplateElement[value.raw=/^(?![\\s\\S]*(?:^|\\s)text-sidebar-(?:accent-)?foreground(?:\\x2f\\d+)?(?:\\s|$))[\\s\\S]*(?:^|\\s)bg-sidebar(?:\\s|$)/])',
                    message:
                        'bg-sidebar is the near-black app sidebar: pair it with text-sidebar-foreground in the same class string. A light panel (wizard rail, card, table header) uses bg-muted/30 like the WizardShell rail. See DESIGN.md "A light panel painted bg-sidebar".',
                },
            ],
        },
    },
    {
        // DESIGN.md "Fill foregrounds off their fill": --destructive-foreground
        // is text for a solid bg-destructive fill and is white in light mode,
        // so on a card or the page ground it vanishes (the destructive Alert
        // rendered white on white). A class string that uses it must also
        // paint the solid fill; error/danger text on a light surface uses the
        // status-critical pair.
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['**/*.test.tsx', '**/*.test.ts'],
        rules: {
            'design/no-destructive-foreground-off-fill': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/^(?![\\s\\S]*(?:^|\\s)(?:[^\\s:]+:)*bg-destructive(?:\\s|$))[\\s\\S]*(?:^|\\s)(?:[^\\s:]+:)*text-destructive-foreground(?:\\x2f\\d+)?(?:\\s|$)/], TemplateElement[value.raw=/^(?![\\s\\S]*(?:^|\\s)(?:[^\\s:]+:)*bg-destructive(?:\\s|$))[\\s\\S]*(?:^|\\s)(?:[^\\s:]+:)*text-destructive-foreground(?:\\x2f\\d+)?(?:\\s|$)/])',
                    message:
                        'text-destructive-foreground is text for a solid bg-destructive fill (white in light mode); without that fill in the same class string it disappears on cards. On a light surface use the critical status pair: bg-status-critical-bg + text-status-critical-foreground (icon text-status-critical). See DESIGN.md "Fill foregrounds off their fill".',
                },
            ],
        },
    },
    {
        // DESIGN.md "Accent as an active surface": the shared Toggle's
        // bg-accent on state measured 4.03:1 (axe, role Overview), and
        // bg-accent is purple in dark mode. Selected/pressed/active states
        // use bg-primary-fill/10 + text-primary, as components/ui/toggle.tsx
        // does. Open menu triggers (data-[state=open]) are not a selection.
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: ['**/*.test.tsx', '**/*.test.ts'],
        rules: {
            'design/no-accent-active-state': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/(?:^|\\s)(?:data-\\[state=(?:on|active|checked)\\]|aria-pressed|aria-selected|aria-checked):bg-accent(?:\\x2f\\d+)?(?:\\s|$)/], TemplateElement[value.raw=/(?:^|\\s)(?:data-\\[state=(?:on|active|checked)\\]|aria-pressed|aria-selected|aria-checked):bg-accent(?:\\x2f\\d+)?(?:\\s|$)/])',
                    message:
                        'bg-accent is under 4.5:1 as a selected/pressed/active surface (4.03:1 on the shared Toggle) and purple in dark mode. Mark the state with bg-primary-fill/10 + text-primary, as components/ui/toggle.tsx does. See DESIGN.md "Accent as an active surface".',
                },
            ],
        },
    },
    {
        // DESIGN.md "Oblivion identity on a client's site" (client demo
        // website, 23 Oct 2026): the public website and the sign-in layout
        // speak as the client. The provider's name comes from Settings ›
        // Branding or Settings › Referrals and reads neutrally when unset —
        // never an "Oblivion Care" string fallback or @oblivioncare
        // placeholder. JSX text (the Powered by line's screen-reader text)
        // isn't a Literal, so it stays allowed.
        files: [
            'resources/js/components/Header.tsx',
            'resources/js/components/Footer.tsx',
            'resources/js/components/public-site/**/*.{ts,tsx}',
            'resources/js/layouts/marketing-layout.tsx',
            'resources/js/layouts/auth/auth-journey-layout.tsx',
            'resources/js/pages/public-provider/**/*.{ts,tsx}',
            'resources/js/pages/public-referrals/**/*.{ts,tsx}',
            'resources/js/pages/auth/login.tsx',
            'resources/js/pages/portal/login.tsx',
        ],
        ignores: ['**/*.test.tsx', '**/*.test.ts'],
        rules: {
            'design/no-oblivion-fallback': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/oblivion ?care/i], TemplateElement[value.raw=/oblivion ?care/i])',
                    message:
                        'The public website and sign-in pages are the client’s: take the provider name from usePublicSite() / useCustomBrandName() and read neutrally when it is null, never "Oblivion Care". See DESIGN.md "Oblivion identity on a client’s site".',
                },
            ],
        },
    },
    {
        // DESIGN.md "Band text": the floored dark bands (the .eh-header sky
        // and the PageHero band) carry --band-foreground, which is always
        // white. --primary-foreground follows the brand and turns ink for a
        // very light brand, unreadable on a dark band, so the band
        // components never use it (class or var()). Page content passed
        // into band slots is covered by app.css, which redefines
        // primary-foreground inside both bands.
        files: [
            'resources/js/components/page/page-header*.tsx',
            'resources/js/components/page/page-hero*.tsx',
        ],
        ignores: ['**/*.test.tsx'],
        rules: {
            'design/band-text-token': [
                'error',
                {
                    selector:
                        ':matches(Literal[value=/primary-foreground/], TemplateElement[value.raw=/primary-foreground/])',
                    message:
                        'Band components use --band-foreground (text-band-foreground, bg-band-foreground/10, var(--band-foreground)): the bands are floored dark, and --primary-foreground turns ink for a very light brand. See DESIGN.md "Band text".',
                },
            ],
        },
    },
    {
        // Calendars use the shared Site Calendar parts (DESIGN.md
        // "Calendars — always the Site Calendar style" and the anti-pattern
        // "Module calendars that fork the shared calendar chrome"). The files
        // below predate the rule and are migration targets. Keep this block
        // before the finance block: a later block's no-restricted-imports
        // replaces this one, so finance repeats the pattern.
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [
            'resources/js/pages/hr/calendar/index.tsx',
            'resources/js/pages/operations/clients/calendar.tsx',
            'resources/js/pages/operations/clients/tabs/legacy-profile-sections.tsx',
            'resources/js/pages/portal/calendar.tsx',
            'resources/js/test/client-profile-appointment-access.test.tsx',
        ],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    paths: [
                        {
                            // DESIGN.md "Skeleton vs loader" + anti-pattern "Bare <Deferred>".
                            name: '@inertiajs/react',
                            importNames: ['Deferred'],
                            message:
                                'Background-loaded panels use <DeferredPanel> (@/components/deferred-panel): it adds the error state with Try again and keeps data through a refresh. See DESIGN.md "Skeleton vs loader".',
                        },
                    ],
                    patterns: [
                        {
                            group: ['@fullcalendar/*'],
                            message:
                                'Calendars reuse pages/sites/calendar (SiteCalendar + _parts) with a data adapter, not a new FullCalendar skin. See DESIGN.md "Calendars".',
                        },
                    ],
                },
            ],
        },
    },
    {
        // Finance is migrated to the Event Horizon header (audit
        // 2026-09-16). PageHero is the superseded page top — DESIGN.md
        // "Page headers" — so a new finance page can't quietly go back to it.
        // Widen this to the whole app once the remaining modules are assessed.
        files: ['resources/js/pages/finance/**/*.{ts,tsx}'],
        // Consolidation and Intercompany are quarantined by
        // RejectUnsupportedConsolidation — they 404 for everyone — so the
        // 2026-09-16 migration deliberately left them on the old page top
        // (audit decision D9) rather than spend the work on dead surfaces.
        // If they are ever un-quarantined they must be migrated or deleted;
        // until then they are the only finance pages exempt from the ban.
        ignores: [
            'resources/js/pages/finance/Consolidation/**',
            'resources/js/pages/finance/Intercompany/**',
        ],
        rules: {
            'no-restricted-imports': [
                'error',
                {
                    paths: [
                        {
                            name: '@/components/page/page-hero',
                            message:
                                'Finance pages use <PageHeader> (@/components/page), not the superseded PageHero. See DESIGN.md "Page headers".',
                        },
                        {
                            name: '@/components/finance/finance-hero',
                            message:
                                'FinanceHero is deleted. Use <PageHeader> from @/components/page. See DESIGN.md "Page headers".',
                        },
                        {
                            // Repeated from the calendar block (this block replaces it for finance).
                            name: '@inertiajs/react',
                            importNames: ['Deferred'],
                            message:
                                'Background-loaded panels use <DeferredPanel> (@/components/deferred-panel): it adds the error state with Try again and keeps data through a refresh. See DESIGN.md "Skeleton vs loader".',
                        },
                    ],
                    patterns: [
                        {
                            group: ['@/components/page'],
                            importNames: ['PageHero'],
                            message:
                                'Finance pages use <PageHeader> (@/components/page), not the superseded PageHero. See DESIGN.md "Page headers".',
                        },
                        {
                            group: ['@fullcalendar/*'],
                            message:
                                'Calendars reuse pages/sites/calendar (SiteCalendar + _parts) with a data adapter, not a new FullCalendar skin. See DESIGN.md "Calendars".',
                        },
                    ],
                },
            ],
        },
    },
    {
        // Security & Devices plain-language rule: keep system-design jargon out
        // of user-facing copy while leaving backend payload keys and TypeScript
        // identifiers untouched.
        files: [
            'resources/js/pages/security-devices/**/*.{ts,tsx}',
            'resources/js/components/security-devices/**/*.{ts,tsx}',
        ],
        ignores: ['**/*.test.ts', '**/*.test.tsx', '**/*fixtures*.ts'],
        plugins: {
            'security-devices-copy': {
                rules: {
                    'no-jargon': builtinRules.get('no-restricted-syntax'),
                },
            },
        },
        rules: {
            'security-devices-copy/no-jargon': [
                'error',
                {
                    selector:
                        'JSXText[value=/\\b(?:canonical|governed|provenances?|evidence-backed|evidence backed|projections?)\\b/i]',
                    message:
                        'Security & Devices UI copy uses plain English instead of system-design jargon (avoid "canonical", "governed", "provenance", "evidence-backed", and "projection").',
                },
                {
                    selector:
                        'JSXAttribute[name.name=/^(?:label|title|description|subline|caption|placeholder|aria-label|ariaLabel|empty|note|railTitle|railSub|blurb|text|confirmText)$/] :matches(Literal[value=/\\b(?:canonical|governed|provenances?|evidence-backed|evidence backed|projections?)\\b/i], TemplateElement[value.raw=/\\b(?:canonical|governed|provenances?|evidence-backed|evidence backed|projections?)\\b/i])',
                    message:
                        'Security & Devices UI copy uses plain English instead of system-design jargon (avoid "canonical", "governed", "provenance", "evidence-backed", and "projection").',
                },
                {
                    selector:
                        'Property[key.name=/^(?:label|title|description|subline|caption|placeholder|empty|note|blurb|next|confirmText)$/] > :matches(Literal[value=/\\b(?:canonical|governed|provenances?|evidence-backed|evidence backed|projections?)\\b/i], TemplateElement[value.raw=/\\b(?:canonical|governed|provenances?|evidence-backed|evidence backed|projections?)\\b/i])',
                    message:
                        'Security & Devices UI copy uses plain English instead of system-design jargon (avoid "canonical", "governed", "provenance", "evidence-backed", and "projection").',
                },
                {
                    selector:
                        'ArrayExpression > Literal:first-child[value=/\\b(?:canonical|governed|provenances?|evidence-backed|evidence backed|projections?)\\b/i]',
                    message:
                        'Security & Devices UI copy uses plain English instead of system-design jargon (avoid "canonical", "governed", "provenance", "evidence-backed", and "projection").',
                },
            ],
        },
    },
    {
        // Node tooling (scripts/, tools/): run by node, not the browser bundle.
        files: ['**/*.{cjs,mjs}'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    {
        files: ['**/*.cjs'],
        languageOptions: {
            sourceType: 'commonjs',
        },
        rules: {
            '@typescript-eslint/no-require-imports': 'off',
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
            // Audit evidence, design previews and before/after snapshots are
            // records, not shipped code. Some are verified by frozen byte
            // hashes, and `npm run lint` runs with --fix, which would
            // rewrite them.
            'docs/**',
            // Claude Code agent worktrees: each is a full repo checkout so
            // recursing into them duplicates lint work for every parallel
            // session and overflows ESLint's stylish formatter on machines
            // with several active worktrees. CI never has these.
            '.claude/worktrees/**',
            '.claude/**',
            '.endpoint-tools/**',
        ],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];
