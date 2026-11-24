import { openDB, type DBSchema, type IDBPDatabase, type IDBPTransaction, type StoreNames } from 'idb';
import type { Settings } from './settings';

export const DB_NAME = 'lwtv';
export const DB_VERSION = 1;
export const STORES = ['settings', 'watched', 'ratings', 'identity'] as const;

export interface WatchedRecord {
    showId: number;
    watchedAt: number;
}

export interface RatingRecord {
    showId: number;
    value: 1 | 2 | 3 | 4 | 5;
    ratedAt: number;
}

export interface IdentityRecord {
    id: string;
    alg: 'Ed25519';
    privateKey: CryptoKey;
    publicKey: CryptoKey;
    publicKeyRaw: string; // 32-byte public key, base64url
    createdAt: number;
}

export interface LwtvSchema extends DBSchema {
    settings: { key: 'current'; value: Settings };
    watched: { key: number; value: WatchedRecord };
    ratings: { key: number; value: RatingRecord };
    identity: { key: 'primary'; value: IdentityRecord };
}

export class StoreWipedError extends Error {
    constructor() {
        super('This tab no longer owns the data: it was wiped or replaced elsewhere.');
        this.name = 'StoreWipedError';
    }
}

let wiped = false;
let dbPromise: Promise<IDBPDatabase<LwtvSchema>> | null = null;
// The connection behind dbPromise once it has opened, so closeDb() can close it synchronously.
let openDb: IDBPDatabase<LwtvSchema> | null = null;
let pendingDelete: Promise<void> | null = null;
// Bumped when this tab starts a wipe, so a write that began before it can tell.
let wipeGeneration = 0;

/** Once set, every write from this tab is refused. */
export function markWiped(): void {
    wiped = true;
}

export function assertWritable(): void {
    if (wiped) {
        throw new StoreWipedError();
    }
}

/** Sets the delete that writes wait for. It clears itself when it settles. */
export function setPendingDelete(p: Promise<void> | null): void {
    pendingDelete = p;
    p?.finally(() => {
        if (pendingDelete === p) {
            pendingDelete = null;
        }
    }).catch(() => {});
}

/** Called by the wipe before it clears anything: writes already under way are refused. */
export function noteWipeStarted(): void {
    wipeGeneration++;
}

/**
 * The wipe count to hand to writeTx's `since`. An operation that does slow work
 * before it writes captures this when it starts, so a wipe that begins in the
 * meantime refuses its write.
 */
export function currentWipeGeneration(): number {
    return wipeGeneration;
}

export function isDeletePendingNow(): boolean {
    return pendingDelete !== null;
}

/** Resolves at once unless a database delete is still pending. */
export async function whenDeleteSettles(): Promise<void> {
    if (pendingDelete) {
        await pendingDelete;
    }
}

export function getDb(): Promise<IDBPDatabase<LwtvSchema>> {
    if (!dbPromise) {
        openDb = null; // any earlier connection was closed or terminated
        const opening = openDB<LwtvSchema>(DB_NAME, DB_VERSION, {
            upgrade(db) {
                db.createObjectStore('settings');
                db.createObjectStore('watched', { keyPath: 'showId' });
                db.createObjectStore('ratings', { keyPath: 'showId' });
                db.createObjectStore('identity');
            },
            // Another tab (or its wipe) wants to delete or upgrade the database.
            blocking() {
                handleWiped();
            },
            // The browser closed the connection under us; open afresh next time.
            terminated() {
                if (dbPromise === opening) {
                    dbPromise = null;
                }
            },
        });
        dbPromise = opening;
        opening.then(
            (db) => {
                if (dbPromise === opening) {
                    openDb = db;
                }
            },
            () => {
                if (dbPromise === opening) {
                    dbPromise = null;
                }
            },
        );
        // Any tab that touches the data hears another tab's wipe.
        listen();
    }
    return dbPromise;
}

/**
 * Runs `read` against the database, or returns `fallback()` without opening it
 * while a delete is pending or after this tab was wiped: the data is gone, and
 * an open would only queue behind the delete.
 */
export async function readDb<R>(fallback: () => R, read: (db: IDBPDatabase<LwtvSchema>) => Promise<R>): Promise<R> {
    if (wiped || pendingDelete) return fallback();
    const db = await getDb();
    if (wiped || pendingDelete) return fallback();
    return read(db);
}

/**
 * Runs `work` in one readwrite transaction over `storeNames`. Refuses if this
 * tab was wiped, waits out a pending delete, and aborts the transaction if
 * `work` throws. Resolves with `work`'s result once the transaction commits.
 * `since` (from currentWipeGeneration) dates the write from an earlier start:
 * if this tab began a wipe after it, the write is refused.
 */
export async function writeTx<Names extends StoreNames<LwtvSchema>[], R>(
    storeNames: [...Names],
    work: (tx: IDBPTransaction<LwtvSchema, Names, 'readwrite'>) => Promise<R>,
    since: number = wipeGeneration,
): Promise<R> {
    assertWritable();
    if (wipeGeneration !== since) {
        throw new StoreWipedError();
    }
    await whenDeleteSettles();
    const db = await getDb();
    // A wipe may have landed while we waited: from another tab, or one this tab started.
    assertWritable();
    if (wipeGeneration !== since) {
        throw new StoreWipedError();
    }
    let tx: IDBPTransaction<LwtvSchema, Names, 'readwrite'>;
    try {
        tx = db.transaction(storeNames, 'readwrite');
    } catch (error) {
        throw wipedOr(error);
    }
    try {
        const result = await work(tx);
        await tx.done;
        return result;
    } catch (error) {
        try {
            tx.abort();
        } catch {
            // Already finished or aborted.
        }
        await tx.done.catch(() => {});
        throw wipedOr(error);
    }
}

/** A database error caused by this tab's connection closing for a wipe reads as StoreWipedError. */
function wipedOr(error: unknown): unknown {
    return wiped && error instanceof DOMException ? new StoreWipedError() : error;
}

export type StoreMessage = { type: 'wipe' } | { type: 'replaced' };

let bc: BroadcastChannel | null = null;
const replacedCallbacks = new Set<() => void>();
const wipedCallbacks = new Set<() => void>();
let listening = false;

/** One channel per module instance, created on first use. */
export function channel(): BroadcastChannel {
    if (!bc) {
        bc = new BroadcastChannel('lwtv-store');
        // Don't keep Node (tests) alive; a no-op in browsers.
        (bc as unknown as { unref?: () => void }).unref?.();
    }
    return bc;
}

function dispatch(event: MessageEvent<StoreMessage>): void {
    switch (event.data?.type) {
        case 'replaced':
            for (const cb of [...replacedCallbacks]) cb();
            break;
        case 'wipe':
            handleWiped();
            break;
    }
}

/**
 * Another tab wiped the data. Both its message and the delete's versionchange
 * land here; the callbacks run only the first time, since the tab is expected
 * to reload once told.
 */
function handleWiped(): void {
    const first = !wiped;
    markWiped();
    closeDb();
    if (first) {
        for (const cb of [...wipedCallbacks]) cb();
    }
}

function listen(): void {
    if (!listening) {
        channel().addEventListener('message', dispatch as EventListener);
        listening = true;
    }
}

/** Called in other tabs after an import replaced the data. Returns an unsubscribe. */
export function onReplaced(cb: () => void): () => void {
    listen();
    replacedCallbacks.add(cb);
    return () => {
        replacedCallbacks.delete(cb);
    };
}

/** Called in this tab after another tab wiped the data. Returns an unsubscribe. */
export function onWiped(cb: () => void): () => void {
    listen();
    wipedCallbacks.add(cb);
    return () => {
        wipedCallbacks.delete(cb);
    };
}

/** Closes this tab's connection: at once if it is open, else as soon as it opens. */
export function closeDb(): void {
    const opening = dbPromise;
    const open = openDb;
    dbPromise = null;
    openDb = null;
    if (open) {
        // Synchronous, so a delete requested right after doesn't see us as a blocker.
        open.close();
    } else {
        opening?.then((db) => db.close(), () => {});
    }
}

/** Test helper: forget the wiped flag and any pending delete. Not part of the public API. */
export function __resetForTests(): void {
    wiped = false;
    pendingDelete = null;
    bc?.close();
    bc = null;
    listening = false;
    replacedCallbacks.clear();
    wipedCallbacks.clear();
}
