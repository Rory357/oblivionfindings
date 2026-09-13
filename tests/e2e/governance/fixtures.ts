import { type Page } from '@playwright/test';
import { runLaravelJson } from '../helpers';

export interface GovernanceFixturesData {
    sites: {
        alpha: number;
        beta: number;
    };
    users: {
        chair: number;
        secretary: number;
        member: number;
        member_duplicate_name: number;
        finance: number;
        ceo: number;
        observer: number;
    };
    board_members: {
        chair: number;
        secretary: number;
        member: number;
        finance: number;
        observer: number;
    };
    meetings: {
        private: number;
        regular: number;
    };
    resolutions: {
        draft: number;
        open: number;
        expired: number;
        null_deadline: number;
    };
    pack: number;
    performance_review: number;
}

export const GOVERNANCE_SYNTHETIC_PERSONAS = {
    chair: {
        email: 'gov-chair@synthetic.test',
        password: 'Secret123!',
        name: 'Synthetic Chair',
        role: 'board_chair',
    },
    secretary: {
        email: 'gov-secretary@synthetic.test',
        password: 'Secret123!',
        name: 'Synthetic Secretary',
        role: 'board_secretary',
    },
    member: {
        email: 'gov-member@synthetic.test',
        password: 'Secret123!',
        name: 'Synthetic Member',
        role: 'board_member',
    },
    duplicateNameMember: {
        email: 'gov-member-dup@synthetic.test',
        password: 'Secret123!',
        name: 'Synthetic Member',
        role: 'board_member',
    },
    finance: {
        email: 'gov-finance@synthetic.test',
        password: 'Secret123!',
        name: 'Synthetic Finance Lead',
        role: 'board_member',
    },
    ceo: {
        email: 'gov-ceo@synthetic.test',
        password: 'Secret123!',
        name: 'Synthetic CEO',
        role: 'ceo',
    },
    observer: {
        email: 'gov-observer@synthetic.test',
        password: 'Secret123!',
        name: 'Synthetic Observer',
        role: 'board_observer',
    },
} as const;

export type GovernancePersonaKey = keyof typeof GOVERNANCE_SYNTHETIC_PERSONAS;

import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const stateFile = resolve(here, '.governance-e2e-db.json');

function getVerifiedDisposableDbName(): string {
    if (!existsSync(stateFile)) {
        throw new Error(
            'Governance E2E fail-closed: State file .governance-e2e-db.json is missing. Setup must create a guarded disposable database before fixtures run.',
        );
    }
    let dbName = '';
    try {
        const parsed = JSON.parse(readFileSync(stateFile, 'utf8'));
        dbName = parsed?.dbName ?? '';
    } catch (e) {
        throw new Error(
            `Governance E2E fail-closed: Failed to read state file ${stateFile}: ${(e as Error).message}`,
        );
    }
    if (
        !dbName ||
        !dbName.startsWith('oblivion_gov_e2e_') ||
        dbName === 'oblivionfindings' ||
        dbName === 'oblivion_findings'
    ) {
        throw new Error(
            `Governance E2E fail-closed: Refusing to run fixtures against non-disposable database: ${dbName}`,
        );
    }
    return dbName;
}

export function runGovernanceLaravelJson<T>(code: string): T {
    const dbName = getVerifiedDisposableDbName();
    process.env.DB_DATABASE = dbName;
    const dbPrefix = `
putenv("DB_DATABASE=${dbName}");
$_ENV['DB_DATABASE'] = '${dbName}';
$_SERVER['DB_DATABASE'] = '${dbName}';
putenv("APP_CONFIG_CACHE=".__DIR__."/unused-config.php");
$_ENV['APP_CONFIG_CACHE'] = __DIR__."/unused-config.php";
$_SERVER['APP_CONFIG_CACHE'] = __DIR__."/unused-config.php";
putenv("MAIL_MAILER=array");
$_ENV['MAIL_MAILER'] = 'array';
$_SERVER['MAIL_MAILER'] = 'array';
putenv("QUEUE_CONNECTION=sync");
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_SERVER['QUEUE_CONNECTION'] = 'sync';
putenv("CACHE_STORE=array");
$_ENV['CACHE_STORE'] = 'array';
$_SERVER['CACHE_STORE'] = 'array';
config(['database.connections.mysql.database' => '${dbName}']);
config(['mail.default' => 'array']);
config(['queue.default' => 'sync']);
config(['cache.default' => 'array']);
\\Illuminate\\Support\\Facades\\DB::purge('mysql');
\\Illuminate\\Support\\Facades\\DB::reconnect('mysql');
`;
    return runLaravelJson<T>(dbPrefix + code);
}

export function seedGovernanceFixtures(): GovernanceFixturesData {
    return runGovernanceLaravelJson<GovernanceFixturesData>(`
        $data = \\Tests\\Support\\GovernanceSyntheticFixtures::seed();
        echo json_encode($data);
    `);
}

export async function loginAsGovernancePersona(
    page: Page,
    personaKey: GovernancePersonaKey,
): Promise<void> {
    const persona = GOVERNANCE_SYNTHETIC_PERSONAS[personaKey];
    await page.goto('about:blank');
    await page.context().clearCookies();
    try {
        runGovernanceLaravelJson(`
            $email = ${JSON.stringify(persona.email)};
            $identity = \\Illuminate\\Support\\Str::transliterate(
                \\Illuminate\\Support\\Str::lower($email).'|127.0.0.1'
            );
            \\Illuminate\\Support\\Facades\\RateLimiter::clear($identity);
            \\Illuminate\\Support\\Facades\\RateLimiter::clear(md5('login'.$identity));
            return true;
        `);
    } catch {
        // Ignore if throttle reset encounters issue
    }
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('#email').fill(persona.email);
    await page.locator('#password').fill(persona.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('/login'));
}
