import { readDb, writeTx } from './db';

export interface Settings {
    schema: 1;
    setupComplete: boolean; // false until guided setup finishes
    region: string | null; // ISO 3166-1 alpha-2, e.g. "US"
    services: string[] | 'all'; // availability `services` slugs
    tropes: Record<number, 'include' | 'exclude'>; // term id -> choice; absent = Any
    hideQueerDeath: boolean;
    triggers: 'show-all' | 'hide-high' | 'hide-med-high' | 'hide-any';
    starsOnly: boolean;
    starTiers: Array<'gold' | 'silver' | 'bronze'>;
    showAnti: boolean;
}

export class SettingsError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'SettingsError';
    }
}

const KEY = 'current';
const TRIGGERS = ['show-all', 'hide-high', 'hide-med-high', 'hide-any'] as const;
const TIERS = ['gold', 'silver', 'bronze'] as const;

export function defaultSettings(): Settings {
    return {
        schema: 1,
        setupComplete: false,
        region: null,
        services: 'all',
        tropes: {},
        hideQueerDeath: true,
        triggers: 'show-all',
        starsOnly: false,
        starTiers: ['gold', 'silver', 'bronze'],
        showAnti: false,
    };
}

function fail(field: string): never {
    throw new SettingsError(`Invalid setting: ${field}`);
}

function bool(v: unknown, field: string): boolean {
    return typeof v === 'boolean' ? v : fail(field);
}

/** Checks every field and returns a fresh object. Unknown keys are dropped. */
export function validateSettings(value: unknown): Settings {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        fail('settings');
    }
    const v = value as Record<string, unknown>;

    if (v.schema !== 1) fail('schema');

    const region = v.region;
    if (region !== null && !(typeof region === 'string' && /^[A-Z]{2}$/.test(region))) fail('region');

    let services: Settings['services'];
    if (v.services === 'all') {
        services = 'all';
    } else if (
        Array.isArray(v.services) &&
        v.services.every((s) => typeof s === 'string' && s !== '')
    ) {
        services = [...(v.services as string[])];
    } else {
        fail('services');
    }

    const tropes: Settings['tropes'] = {};
    if (typeof v.tropes !== 'object' || v.tropes === null || Array.isArray(v.tropes)) fail('tropes');
    for (const [id, choice] of Object.entries(v.tropes as Record<string, unknown>)) {
        if (!/^[1-9]\d*$/.test(id) || (choice !== 'include' && choice !== 'exclude')) fail('tropes');
        tropes[Number(id)] = choice;
    }

    if (!(TRIGGERS as readonly unknown[]).includes(v.triggers)) fail('triggers');

    const tiers = v.starTiers;
    if (
        !Array.isArray(tiers) ||
        !tiers.every((t) => (TIERS as readonly unknown[]).includes(t)) ||
        new Set(tiers).size !== tiers.length
    ) {
        fail('starTiers');
    }

    return {
        schema: 1,
        setupComplete: bool(v.setupComplete, 'setupComplete'),
        region: region as string | null,
        services,
        tropes,
        hideQueerDeath: bool(v.hideQueerDeath, 'hideQueerDeath'),
        triggers: v.triggers as Settings['triggers'],
        starsOnly: bool(v.starsOnly, 'starsOnly'),
        starTiers: [...(tiers as Settings['starTiers'])],
        showAnti: bool(v.showAnti, 'showAnti'),
    };
}

export async function getSettings(): Promise<Settings> {
    const stored = await readDb(() => undefined, (db) => db.get('settings', KEY));
    return validateSettings({ ...defaultSettings(), ...stored });
}

/**
 * Read, merge, validate and write in one readwrite transaction, so concurrent
 * saves can't lose each other's changes. A failed validation puts nothing.
 */
async function update(patch: (current: Settings) => Partial<Settings>): Promise<Settings> {
    return writeTx(['settings'], async (tx) => {
        const stored = await tx.store.get(KEY);
        const current = validateSettings({ ...defaultSettings(), ...stored });
        const next = validateSettings({ ...current, ...patch(current) });
        await tx.store.put(next, KEY);
        return next;
    });
}

export function saveSettings(patch: Partial<Settings>): Promise<Settings> {
    return update(() => patch);
}

/** Filters back to defaults. Region, services and setupComplete are kept. */
export function resetFilters(): Promise<Settings> {
    return update((current) => ({
        ...defaultSettings(),
        region: current.region,
        services: current.services,
        setupComplete: current.setupComplete,
    }));
}
