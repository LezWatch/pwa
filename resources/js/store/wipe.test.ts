import { test, expect, vi, beforeEach, afterEach } from 'vitest';
import { openDB, unwrap, type IDBPDatabase } from 'idb';
import { closeDb, STORES, StoreWipedError, __resetForTests } from './db';
import { __resetKeySupportForTests, getIdentity, getOrCreateIdentity } from './identity';
import { getSettings, saveSettings, defaultSettings } from './settings';
import { setWatched, setRating, listWatched, listRatings, isWatched, getRating } from './shows';
import { storageStatus } from './status';
import { wipeEverything, onWiped, isDeletePending } from './wipe';
import { MIN_ITERATIONS, encryptBackup } from './backup-format';
import { importBackup } from './import';
import * as index from './index';

async function seedAll() {
    await saveSettings({ region: 'US', setupComplete: true });
    await setWatched(5, true);
    await setRating(5, 4);
    await getOrCreateIdentity();
}

async function dbNames(): Promise<string[]> {
    return (await indexedDB.databases()).map((d) => d.name!);
}

async function expectEmpty() {
    expect(await getSettings()).toEqual(defaultSettings());
    expect(await listWatched()).toEqual([]);
    expect(await listRatings()).toEqual([]);
    expect(await getIdentity()).toBeNull();
}

function within<T>(p: Promise<T>, ms: number): Promise<T> {
    return Promise.race([
        p,
        new Promise<T>((_, reject) => setTimeout(() => reject(new Error(`still waiting after ${ms} ms`)), ms)),
    ]);
}

/** Opens tab B: a second module instance with its own connection, flag and channel. */
async function openTabB() {
    vi.resetModules();
    const index = await import('./index');
    const db = await import('./db');
    return { index, db };
}

const cleanups: Array<() => void> = [];

beforeEach(() => {
    closeDb();
    __resetForTests();
    __resetKeySupportForTests();
});

afterEach(() => {
    for (const fn of cleanups.splice(0)) fn();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

test('with only this tab, the wipe deletes the database and leaves defaults', async () => {
    await seedAll();
    await wipeEverything();

    expect(await dbNames()).not.toContain('lwtv');
    expect(isDeletePending()).toBe(false);
    await expectEmpty();
    expect((await storageStatus()).isEmpty).toBe(true);
});

test('the wiping tab keeps working against a fresh database', async () => {
    await seedAll();
    const own = vi.fn();
    onWiped(own);
    await wipeEverything();

    await expect(saveSettings({ region: 'CA' })).resolves.toMatchObject({ region: 'CA' });
    expect((await getSettings()).region).toBe('CA');
    expect(own).not.toHaveBeenCalled();
});

test('a tab with no open connection opens one to clear, then deletes', async () => {
    await seedAll();
    closeDb();
    await wipeEverything();

    expect(await dbNames()).not.toContain('lwtv');
    await expectEmpty();
});

test('another tab that lets go: its onWiped runs once, its writes are refused, the database goes', async () => {
    await seedAll();
    const b = await openTabB();
    cleanups.push(() => {
        b.db.closeDb();
        b.db.__resetForTests();
    });
    expect((await b.index.getSettings()).region).toBe('US'); // B now holds a connection
    const wipedB = vi.fn();
    b.index.onWiped(wipedB);

    await wipeEverything();

    await vi.waitFor(() => expect(wipedB).toHaveBeenCalledTimes(1), { timeout: 2000 });
    await vi.waitFor(async () => expect(await dbNames()).not.toContain('lwtv'), { timeout: 2000 });
    // B gets both the message and the versionchange; it still runs once.
    await new Promise((r) => setTimeout(r, 100));
    expect(wipedB).toHaveBeenCalledTimes(1);

    await expect(b.index.saveSettings({})).rejects.toBeInstanceOf(b.index.StoreWipedError);
    await expect(b.index.setWatched(9, true)).rejects.toBeInstanceOf(b.index.StoreWipedError);
    expect(await b.index.listWatched()).toEqual([]);
    await expectEmpty();
});

test("a write from another tab racing the wipe either lands before the clear or fails as StoreWipedError", async () => {
    await seedAll();
    const b = await openTabB();
    cleanups.push(() => {
        b.db.closeDb();
        b.db.__resetForTests();
    });
    await b.index.getSettings();

    const write = b.index.setWatched(77, true).then(
        () => 'ok',
        (e: Error) => e.name,
    );
    await wipeEverything();

    expect(['ok', 'StoreWipedError']).toContain(await write);
    await vi.waitFor(async () => expect(await dbNames()).not.toContain('lwtv'), { timeout: 2000 });
    await expectEmpty();
});

test('a write issued on this tab just before the wipe does not land after the clear', async () => {
    await seedAll();
    const raw: IDBPDatabase = await openDB('lwtv', 1); // holds the old database open after the wipe
    const write = saveSettings({ region: 'CA' }).then(
        () => 'ok',
        (e: Error) => e.name,
    );
    await within(wipeEverything(), 1000);

    expect(await write).toBe('StoreWipedError');
    expect(await raw.get('settings', 'current')).toBeUndefined();
    raw.close();
    await vi.waitFor(() => expect(isDeletePending()).toBe(false), { timeout: 2000 });
    await expectEmpty();
});

test('a tab told twice about one wipe (versionchange, then the message) runs onWiped once', async () => {
    await seedAll();
    const b = await openTabB();
    cleanups.push(() => {
        b.db.closeDb();
        b.db.__resetForTests();
    });
    await b.index.getSettings();
    const wipedB = vi.fn();
    b.index.onWiped(wipedB);

    // The versionchange alone: a bare delete, no message.
    await new Promise<void>((resolve, reject) => {
        const req = indexedDB.deleteDatabase('lwtv');
        req.onsuccess = () => resolve();
        req.onerror = () => reject(req.error);
    });
    expect(wipedB).toHaveBeenCalledTimes(1);

    // Then the message for the same wipe.
    const other = new BroadcastChannel('lwtv-store');
    other.postMessage({ type: 'wipe' });
    await new Promise((r) => setTimeout(r, 100));
    other.close();
    expect(wipedB).toHaveBeenCalledTimes(1);
});

test('the message alone marks another tab wiped and closes its connection', async () => {
    await seedAll();
    const b = await openTabB();
    cleanups.push(() => {
        b.db.closeDb();
        b.db.__resetForTests();
    });
    await b.index.getSettings();
    const wipedB = vi.fn();
    b.index.onWiped(wipedB);

    const other = new BroadcastChannel('lwtv-store');
    other.postMessage({ type: 'wipe' });
    other.close();
    await vi.waitFor(() => expect(wipedB).toHaveBeenCalledTimes(1), { timeout: 2000 });
    await expect(b.index.saveSettings({})).rejects.toBeInstanceOf(b.index.StoreWipedError);

    // B let go, so a delete goes through without being blocked.
    const blocked = vi.fn();
    await new Promise<void>((resolve, reject) => {
        const req = indexedDB.deleteDatabase('lwtv');
        req.onblocked = blocked;
        req.onsuccess = () => resolve();
        req.onerror = () => reject(req.error);
    });
    expect(blocked).not.toHaveBeenCalled();
});

test('a transaction that fails because the connection closed for a wipe rejects as StoreWipedError', async () => {
    await seedAll();
    const b = await openTabB();
    cleanups.push(() => {
        b.db.closeDb();
        b.db.__resetForTests();
    });
    const db = await b.db.getDb();

    // The wipe lands between the writable check and the transaction call.
    vi.spyOn(db, 'transaction').mockImplementationOnce(() => {
        b.db.markWiped();
        throw new DOMException('The database connection is closing.', 'InvalidStateError');
    });
    await expect(b.index.saveSettings({ region: 'CA' })).rejects.toBeInstanceOf(b.index.StoreWipedError);
});

test('a transaction aborted under a wipe mid-write rejects as StoreWipedError', async () => {
    await seedAll();
    const b = await openTabB();
    cleanups.push(() => {
        b.db.closeDb();
        b.db.__resetForTests();
    });
    const db = await b.db.getDb();
    const raw = unwrap(db);
    const real = raw.transaction.bind(raw);

    // The wipe lands after the transaction starts. Hand idb the raw transaction so it wraps it once.
    vi.spyOn(db, 'transaction').mockImplementationOnce(((names: string[], mode: IDBTransactionMode) => {
        const tx = real(names, mode);
        b.db.markWiped();
        tx.abort();
        return tx;
    }) as unknown as typeof db.transaction);
    await expect(b.index.setWatched(9, true)).rejects.toBeInstanceOf(b.index.StoreWipedError);
});

test('a stuck connection: the wipe still resolves, the stores are empty, and the delete finishes later', async () => {
    await seedAll();
    const raw: IDBPDatabase = await openDB('lwtv', 1); // no blocking handler: never lets go

    await within(wipeEverything(), 1000);

    for (const store of STORES) {
        expect(await raw.getAll(store)).toEqual([]);
    }
    expect(isDeletePending()).toBe(true);

    raw.close();
    await vi.waitFor(() => expect(isDeletePending()).toBe(false), { timeout: 2000 });
    expect(await dbNames()).not.toContain('lwtv');
});

test('while the delete is pending, reads give the empty state at once and a save waits for the delete', async () => {
    await seedAll();
    const raw: IDBPDatabase = await openDB('lwtv', 1);
    await within(wipeEverything(), 1000);
    expect(isDeletePending()).toBe(true);

    expect(await within(getSettings(), 100)).toEqual(defaultSettings());
    expect(await within(listWatched(), 100)).toEqual([]);
    expect(await within(listRatings(), 100)).toEqual([]);
    expect(await within(isWatched(5), 100)).toBe(false);
    expect(await within(getRating(5), 100)).toBeNull();
    expect(await within(getIdentity(), 100)).toBeNull();
    expect((await within(storageStatus(), 100)).isEmpty).toBe(true);

    let saved = false;
    const save = saveSettings({ region: 'CA' }).then(() => (saved = true));
    await new Promise((r) => setTimeout(r, 50));
    expect(saved).toBe(false);

    raw.close();
    await within(save, 2000);
    expect(isDeletePending()).toBe(false);
    expect((await getSettings()).region).toBe('CA');
    expect(await listWatched()).toEqual([]);
});

test('wiping again while a delete is pending resolves without doing anything', async () => {
    await seedAll();
    const raw: IDBPDatabase = await openDB('lwtv', 1);
    await within(wipeEverything(), 1000);

    const del = vi.spyOn(indexedDB, 'deleteDatabase');
    await within(wipeEverything(), 100);
    expect(del).not.toHaveBeenCalled();
    expect(isDeletePending()).toBe(true);

    raw.close();
    await vi.waitFor(() => expect(isDeletePending()).toBe(false), { timeout: 2000 });
});

test('two wipes called together share one run', async () => {
    await seedAll();
    const del = vi.spyOn(indexedDB, 'deleteDatabase');
    await Promise.all([wipeEverything(), wipeEverything()]);
    expect(del).toHaveBeenCalledTimes(1);
    await expectEmpty();
});

test('a delete request that errors still settles: the wipe resolves and writes go on', async () => {
    await seedAll();
    vi.spyOn(indexedDB, 'deleteDatabase').mockImplementation(() => {
        const req = {} as IDBOpenDBRequest;
        setTimeout(() => req.onerror?.call(req, new Event('error')), 0);
        return req;
    });

    await within(wipeEverything(), 1000);
    expect(isDeletePending()).toBe(false);
    vi.restoreAllMocks();

    await expectEmpty(); // step 1 cleared everything
    await expect(within(saveSettings({ region: 'CA' }), 1000)).resolves.toMatchObject({ region: 'CA' });
});

test('the wipe removes lwtv: keys from both web storages and leaves the others', async () => {
    function stub(initial: Record<string, string>): Storage {
        const map = new Map(Object.entries(initial));
        return {
            get length() {
                return map.size;
            },
            key: (i: number) => [...map.keys()][i] ?? null,
            getItem: (k: string) => map.get(k) ?? null,
            setItem: (k: string, v: string) => void map.set(k, String(v)),
            removeItem: (k: string) => void map.delete(k),
            clear: () => map.clear(),
        };
    }
    const local = stub({ 'lwtv:draft': '1', 'lwtv:other': '2', theme: 'dark' });
    const session = stub({ 'lwtv:tab': 'x', keep: 'y' });
    vi.stubGlobal('localStorage', local);
    vi.stubGlobal('sessionStorage', session);

    await seedAll();
    await wipeEverything();

    expect(local.getItem('lwtv:draft')).toBeNull();
    expect(local.getItem('lwtv:other')).toBeNull();
    expect(local.getItem('theme')).toBe('dark');
    expect(session.getItem('lwtv:tab')).toBeNull();
    expect(session.getItem('keep')).toBe('y');
});

test('web storage that throws on access does not stop the wipe', async () => {
    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        get() {
            throw new DOMException('denied', 'SecurityError');
        },
    });
    cleanups.push(() => {
        delete (globalThis as { localStorage?: unknown }).localStorage;
    });

    await seedAll();
    await expect(wipeEverything()).resolves.toBeUndefined();
    expect(await dbNames()).not.toContain('lwtv');
});

test('index exports the wipe API', () => {
    expect(index.wipeEverything).toBe(wipeEverything);
    expect(index.onWiped).toBe(onWiped);
    expect(index.isDeletePending).toBe(isDeletePending);
    expect(index.StoreWipedError).toBe(StoreWipedError);
});

test('onWiped returns an unsubscribe', async () => {
    const b = await openTabB();
    cleanups.push(() => {
        b.db.closeDb();
        b.db.__resetForTests();
    });
    await seedAll();
    await b.index.getSettings();
    const cb = vi.fn();
    b.index.onWiped(cb)();

    await wipeEverything();
    await vi.waitFor(async () => expect(await dbNames()).not.toContain('lwtv'), { timeout: 2000 });
    expect(cb).not.toHaveBeenCalled();
    await expect(b.index.saveSettings({})).rejects.toBeInstanceOf(b.index.StoreWipedError);
});

test('an import that starts before a wipe on this tab is refused, not landed in the fresh database', async () => {
    const plain = {
        schema: 1,
        exportedAt: 1,
        settings: { ...defaultSettings(), region: 'GB', setupComplete: true },
        watched: [{ showId: 20, watchedAt: 1 }],
        ratings: [],
        identity: null,
    } as const;
    const file = new Blob([await encryptBackup(structuredClone(plain) as never, 'correct horse', MIN_ITERATIONS)]);

    const importing = importBackup(file, 'correct horse').then(
        () => 'ok',
        (e: Error) => e.name,
    );
    await wipeEverything();

    expect(await importing).toBe('StoreWipedError');
    await expectEmpty();
});

test('a key creation that starts before a wipe on this tab is refused; a later one makes a fresh key', async () => {
    const creating = getOrCreateIdentity().then(
        () => 'ok',
        (e: Error) => e.name,
    );
    await wipeEverything();

    // Asked again after the wipe: not handed the refused creation.
    const fresh = await getOrCreateIdentity();
    expect(await creating).toBe('StoreWipedError');
    expect((await getIdentity())?.publicKeyRaw).toBe(fresh.publicKeyRaw);
});

test('a key creation that starts before a wipe stores nothing', async () => {
    const creating = getOrCreateIdentity().then(
        () => 'ok',
        (e: Error) => e.name,
    );
    await wipeEverything();

    expect(await creating).toBe('StoreWipedError');
    await expectEmpty();
});

test("a second wipe from an already-wiped tab waits for the holding tab, then leaves every store empty", async () => {
    await seedAll();
    const b = await openTabB();
    cleanups.push(() => {
        b.db.closeDb();
        b.db.__resetForTests();
    });
    await b.index.getSettings();
    const wipedB = vi.fn();
    b.index.onWiped(wipedB);
    const raw: IDBPDatabase = await openDB('lwtv', 1); // a third tab that never lets go

    await within(wipeEverything(), 1000);
    await vi.waitFor(() => expect(wipedB).toHaveBeenCalledTimes(1), { timeout: 2000 });

    // B presses "Delete my data" before reloading: its open queues behind the blocked delete.
    let secondDone = false;
    const second = b.index.wipeEverything().then(() => (secondDone = true));
    await new Promise((r) => setTimeout(r, 200));
    expect(secondDone).toBe(false);

    raw.close();
    await within(second, 2000);
    await vi.waitFor(async () => expect(await dbNames()).not.toContain('lwtv'), { timeout: 2000 });
    await expectEmpty();
});
