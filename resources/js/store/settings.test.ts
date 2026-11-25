import { test, expect, beforeEach } from 'vitest';
import {
    closeDb,
    getDb,
    assertWritable,
    markWiped,
    setPendingDelete,
    isDeletePendingNow,
    StoreWipedError,
    __resetForTests,
} from './db';
import {
    defaultSettings,
    getSettings,
    saveSettings,
    resetFilters,
    validateSettings,
    SettingsError,
} from './settings';

beforeEach(() => {
    // test-setup.ts has already swapped in a fresh IDBFactory.
    closeDb();
    __resetForTests();
});

test('getSettings on an empty database returns the defaults', async () => {
    expect(await getSettings()).toEqual(defaultSettings());
    expect(defaultSettings()).toEqual({
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
    });
});

test('defaultSettings returns a fresh object each time', () => {
    const a = defaultSettings();
    a.starTiers.push('gold');
    a.tropes[1] = 'include';
    expect(defaultSettings().starTiers).toEqual(['gold', 'silver', 'bronze']);
    expect(defaultSettings().tropes).toEqual({});
});

test('saveSettings changes one field and leaves the rest at default', async () => {
    await saveSettings({ region: 'CA' });
    expect(await getSettings()).toEqual({ ...defaultSettings(), region: 'CA' });
});

test('concurrent saves both land (RF2)', async () => {
    await Promise.all([
        saveSettings({ tropes: { 101: 'include' } }),
        saveSettings({ hideQueerDeath: false }),
    ]);
    const s = await getSettings();
    expect(s.tropes).toEqual({ 101: 'include' });
    expect(s.hideQueerDeath).toBe(false);
});

test('invalid patches reject with SettingsError and store nothing (RF3)', async () => {
    await saveSettings({ region: 'US' });
    const before = await getSettings();

    await expect(saveSettings({ triggers: 'hide-low' as never })).rejects.toBeInstanceOf(SettingsError);
    await expect(saveSettings({ region: 'usa' })).rejects.toBeInstanceOf(SettingsError);
    await expect(saveSettings({ tropes: { 101: 'maybe' as never } })).rejects.toBeInstanceOf(SettingsError);

    expect(await getSettings()).toEqual(before);
});

test('a partial stored record is filled with defaults (RF4)', async () => {
    const db = await getDb();
    await db.put('settings', { schema: 1, region: 'US' } as never, 'current');
    expect(await getSettings()).toEqual({ ...defaultSettings(), region: 'US' });
});

test('an invalid stored record makes getSettings throw SettingsError', async () => {
    const db = await getDb();
    await db.put('settings', { schema: 1, region: 'nope' } as never, 'current');
    await expect(getSettings()).rejects.toBeInstanceOf(SettingsError);
});

test('resetFilters keeps region, services and setupComplete only', async () => {
    await saveSettings({
        region: 'GB',
        services: ['netflix'],
        tropes: { 7: 'exclude' },
        triggers: 'hide-any',
        setupComplete: true,
        hideQueerDeath: false,
        starsOnly: true,
        starTiers: ['gold'],
        showAnti: true,
    });
    const s = await resetFilters();
    expect(s).toEqual({
        ...defaultSettings(),
        region: 'GB',
        services: ['netflix'],
        setupComplete: true,
    });
    expect(await getSettings()).toEqual(s);
});

test('validateSettings checks each rule and drops unknown keys', () => {
    const ok = defaultSettings();
    expect(validateSettings({ ...ok, extra: 1 })).toEqual(ok);
    expect(validateSettings(ok)).not.toBe(ok);

    const bad: unknown[] = [
        null,
        'x',
        { ...ok, schema: 2 },
        { ...ok, setupComplete: 'yes' },
        { ...ok, hideQueerDeath: 1 },
        { ...ok, starsOnly: null },
        { ...ok, showAnti: 'no' },
        { ...ok, region: 'us' },
        { ...ok, region: 'USA' },
        { ...ok, services: 'some' },
        { ...ok, services: [''] },
        { ...ok, services: [1] },
        { ...ok, tropes: { abc: 'include' } },
        { ...ok, tropes: { 0: 'include' } },
        { ...ok, tropes: { '1.5': 'include' } },
        { ...ok, tropes: { 1: 'any' } },
        { ...ok, starTiers: ['gold', 'gold'] },
        { ...ok, starTiers: ['platinum'] },
        { ...ok, starTiers: 'gold' },
        { ...ok, triggers: 'hide-low' },
    ];
    for (const value of bad) {
        expect(() => validateSettings(value)).toThrow(SettingsError);
    }
    expect(validateSettings({ ...ok, region: 'US', services: ['a', 'b'], starTiers: [] }).starTiers).toEqual([]);
});

test('writes are refused once the tab is marked wiped', async () => {
    await saveSettings({ region: 'US' });
    markWiped();
    expect(() => assertWritable()).toThrow(StoreWipedError);
    await expect(saveSettings({ region: 'CA' })).rejects.toBeInstanceOf(StoreWipedError);
});

test('another connection asking to delete the database marks this tab wiped', async () => {
    await getDb();
    const deleted = new Promise<void>((resolve, reject) => {
        const req = indexedDB.deleteDatabase('lwtv');
        req.onsuccess = () => resolve();
        req.onerror = () => reject(req.error);
    });
    await deleted; // only succeeds if our connection closed itself
    expect(() => assertWritable()).toThrow(StoreWipedError);
});

test('a pending delete holds back saveSettings until it settles', async () => {
    let finish!: () => void;
    setPendingDelete(new Promise<void>((resolve) => (finish = resolve)));
    expect(isDeletePendingNow()).toBe(true);

    let saved = false;
    const p = saveSettings({ region: 'CA' }).then(() => (saved = true));
    await new Promise((r) => setTimeout(r, 20));
    expect(saved).toBe(false);

    setPendingDelete(null);
    finish();
    await p;
    expect(saved).toBe(true);
    expect(isDeletePendingNow()).toBe(false);
    expect((await getSettings()).region).toBe('CA');
});
