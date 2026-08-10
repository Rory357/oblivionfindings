import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import ts from 'typescript';
import { describe, expect, it } from 'vitest';

const roots = [
    'resources/js/pages/it',
    'resources/js/pages/security-devices',
    'resources/js/components/it',
    'resources/js/components/security-devices',
];

const crossModuleSources = [
    'resources/js/pages/control-room/alerts/index.tsx',
    'resources/js/pages/control-room/show.tsx',
    'resources/js/pages/control-room/map.tsx',
    'resources/js/pages/control-room/devices/index.tsx',
    'resources/js/pages/control-room/devices/show.tsx',
    'resources/js/components/control-room/alert-workspace-dialog.tsx',
    'resources/js/components/control-room/alert-workspace/linked-journey.tsx',
    'resources/js/components/control-room/alert-worklist/alert-worklist.tsx',
    'resources/js/components/control-room/alert-worklist/alert-worklist-row.tsx',
    'resources/js/pages/sites/show.tsx',
    'resources/js/components/sites/site-technology-projection.tsx',
    'resources/js/pages/operations/clients/show.tsx',
    'resources/js/pages/operations/clients/tabs/healthcare-devices.tsx',
    'resources/js/components/client-location-tab.tsx',
    'resources/js/pages/fleet-assets/vehicles/show.tsx',
    'resources/js/pages/fleet-assets/vehicles/vehicle-technology-projection.tsx',
    'resources/js/pages/fleet-assets/assets/show.tsx',
    'resources/js/components/assets/asset-finance-technology-projection.tsx',
    'resources/js/pages/fleet-assets/resident-tracking/history.tsx',
    'resources/js/pages/hr/employees/show.tsx',
    'resources/js/pages/settings/api.tsx',
    'resources/js/pages/settings/it-mailbox.tsx',
    'resources/js/components/monitoring/delivery-recovery-card.tsx',
    'resources/js/components/monitoring/monitoring-incident-evidence-card.tsx',
];

const triggerPattern =
    /(?:Dialog|Popover|DropdownMenu|Tooltip|Sheet|Collapsible|Select)Trigger$/;

function productionSources(directory: string): string[] {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            return productionSources(path);
        }

        return /\.tsx?$/.test(entry.name) &&
            !/\.(?:test|spec)\./.test(entry.name)
            ? [path]
            : [];
    });
}

function attributeMap(
    opening: ts.JsxOpeningLikeElement,
): Map<string, ts.JsxAttribute> {
    return new Map(
        opening.attributes.properties
            .filter(ts.isJsxAttribute)
            .map((attribute) => [attribute.name.getText(), attribute]),
    );
}

function literalAttribute(
    attribute: ts.JsxAttribute | undefined,
): string | null {
    if (!attribute?.initializer) return null;

    if (ts.isStringLiteral(attribute.initializer)) {
        return attribute.initializer.text;
    }

    if (
        ts.isJsxExpression(attribute.initializer) &&
        attribute.initializer.expression &&
        ts.isStringLiteral(attribute.initializer.expression)
    ) {
        return attribute.initializer.expression.text;
    }

    return null;
}

function isInsideTrigger(node: ts.Node): boolean {
    let parent: ts.Node | undefined = node.parent;

    while (parent) {
        if (
            ts.isJsxElement(parent) &&
            triggerPattern.test(parent.openingElement.tagName.getText())
        ) {
            return true;
        }

        parent = parent.parent;
    }

    return false;
}

function isInsideDestinationBackedElement(
    node: ts.Node,
    source: ts.SourceFile,
): boolean {
    let parent: ts.Node | undefined = node.parent;

    while (parent) {
        if (ts.isJsxElement(parent)) {
            const opening = parent.openingElement;
            const tag = opening.tagName.getText(source);

            if (tag === 'Link' || tag === 'a') {
                const href = attributeMap(opening).get('href');
                const literalHref = literalAttribute(href);

                return (
                    href !== undefined &&
                    (literalHref === null ||
                        (literalHref.trim() !== '' &&
                            literalHref.trim() !== '#' &&
                            !literalHref
                                .trim()
                                .toLowerCase()
                                .startsWith('javascript:')))
                );
            }
        }

        parent = parent.parent;
    }

    return false;
}

function hasDndKitListeners(opening: ts.JsxOpeningLikeElement): boolean {
    return opening.attributes.properties.some(
        (attribute) =>
            ts.isJsxSpreadAttribute(attribute) &&
            ts.isIdentifier(attribute.expression) &&
            attribute.expression.text === 'listeners',
    );
}

describe('IT and Security & Devices production interactions', () => {
    it('keeps buttons actionable or explicitly unavailable and links destination-backed', () => {
        const workspace = process.cwd();
        const findings: string[] = [];

        const productionFiles = [
            ...roots.flatMap((root) =>
                productionSources(resolve(workspace, root)),
            ),
            ...crossModuleSources.map((file) => resolve(workspace, file)),
        ];

        for (const file of new Set(productionFiles)) {
            const source = ts.createSourceFile(
                file,
                readFileSync(file, 'utf8'),
                ts.ScriptTarget.Latest,
                true,
                ts.ScriptKind.TSX,
            );

            const visit = (node: ts.Node): void => {
                if (ts.isJsxElement(node) || ts.isJsxSelfClosingElement(node)) {
                    const opening = ts.isJsxElement(node)
                        ? node.openingElement
                        : node;
                    const tag = opening.tagName.getText(source);
                    const attributes = attributeMap(opening);
                    const line =
                        source.getLineAndCharacterOfPosition(
                            node.getStart(source),
                        ).line + 1;
                    const location = `${relative(workspace, file).replaceAll('\\', '/')}:${line}`;

                    if (tag === 'Link' || tag === 'a') {
                        const href = attributes.get('href');
                        const literalHref = literalAttribute(href);

                        if (!href) {
                            findings.push(
                                `${location}: <${tag}> has no destination`,
                            );
                        } else if (
                            literalHref !== null &&
                            (literalHref.trim() === '' ||
                                literalHref.trim() === '#' ||
                                literalHref
                                    .trim()
                                    .toLowerCase()
                                    .startsWith('javascript:'))
                        ) {
                            findings.push(
                                `${location}: <${tag}> has an inert destination`,
                            );
                        }
                    }

                    if (tag === 'Button' || tag === 'button') {
                        const type = literalAttribute(attributes.get('type'));
                        const disabled = attributes.get('disabled');
                        const explicitlyUnavailable =
                            disabled !== undefined &&
                            disabled.initializer === undefined;
                        const actionable =
                            attributes.has('onClick') ||
                            attributes.has('asChild') ||
                            attributes.has('form') ||
                            type === 'submit' ||
                            type === 'reset' ||
                            isInsideTrigger(node) ||
                            isInsideDestinationBackedElement(node, source) ||
                            hasDndKitListeners(opening);

                        if (!actionable && !explicitlyUnavailable) {
                            findings.push(
                                `${location}: <${tag}> has no action, trigger, form submission, or explicit unavailable state`,
                            );
                        }
                    }
                }

                ts.forEachChild(node, visit);
            };

            visit(source);
        }

        expect(findings).toEqual([]);
    });
});
