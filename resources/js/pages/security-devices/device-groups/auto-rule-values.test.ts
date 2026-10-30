import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    autoRuleValueLabel,
    autoRuleValueOptions,
    DEVICE_STATUS_LABELS,
    DEVICE_TAXONOMY,
    HEALTH_STATUS_LABELS,
} from './auto-rule-values';

const source = (path: string) =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

const enumValues = (path: string) =>
    [...source(path).matchAll(/case (\w+) = '([^']+)';/g)].map(
        ([, name, value]) => [name, value] as const,
    );

/** Reads DeviceTaxonomy::all() as written, without running PHP. */
function phpTaxonomy() {
    const domains = Object.fromEntries(
        enumValues('app/Domain/SecurityDevices/Enums/DeviceDomain.php'),
    );
    const body = source(
        'app/Domain/SecurityDevices/Config/DeviceTaxonomy.php',
    ).split('public static function categoriesFor')[0];
    const tree: Record<string, Record<string, Record<string, string>>> = {};
    let domain = '';
    let category = '';
    for (const line of body.split('\n')) {
        const domainLine = /DeviceDomain::(\w+)->value => \[/.exec(line);
        const categoryLine = /^ {16}'(\w+)' => \[\s*$/.exec(line);
        const subcategoryLine = /^ {20}'([^']+)' => '((?:[^'\\]|\\.)*)',/.exec(
            line,
        );
        if (domainLine) {
            domain = domains[domainLine[1]];
            tree[domain] = {};
        } else if (categoryLine) {
            category = categoryLine[1];
            tree[domain][category] = {};
        } else if (subcategoryLine) {
            tree[domain][category][subcategoryLine[1]] =
                subcategoryLine[2].replace(/\\'/g, "'");
        }
    }
    return tree;
}

describe('Automatic membership rule values', () => {
    it('mirrors the server taxonomy and status enums exactly', () => {
        const tree = phpTaxonomy();
        expect(Object.keys(tree)).toHaveLength(5);
        expect(DEVICE_TAXONOMY).toEqual(tree);
        expect(Object.keys(DEVICE_STATUS_LABELS)).toEqual(
            enumValues('app/Domain/SecurityDevices/Enums/DeviceStatus.php').map(
                ([, value]) => value,
            ),
        );
        expect(Object.keys(HEALTH_STATUS_LABELS)).toEqual(
            enumValues('app/Domain/SecurityDevices/Enums/HealthStatus.php').map(
                ([, value]) => value,
            ),
        );
    });

    it('offers stored values in words so a chosen value matches exactly', () => {
        const domains = autoRuleValueOptions('domain');
        expect(domains).toContainEqual({
            value: 'iot_healthcare',
            label: 'Healthcare',
        });
        expect(domains).toContainEqual({
            value: 'it_infrastructure',
            label: 'Network & IT',
        });
        expect(autoRuleValueOptions('category')).toContainEqual({
            value: 'access_control',
            label: 'Access control',
            group: 'Security',
        });
        const subtypes = autoRuleValueOptions('subcategory') ?? [];
        expect(subtypes).toContainEqual({
            value: 'pir_motion',
            label: 'PIR motion sensor',
            group: 'Alarm',
        });
        expect(new Set(subtypes.map((option) => option.value)).size).toBe(
            subtypes.length,
        );
        expect(autoRuleValueOptions('provider')).toBeNull();
        expect(autoRuleValueLabel('category', 'cctv')).toBe('CCTV');
        expect(autoRuleValueLabel('category', 'pump')).toBe('pump');
    });
});
