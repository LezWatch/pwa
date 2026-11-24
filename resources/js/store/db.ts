import { openDB, type DBSchema, type IDBPDatabase } from 'idb';
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
let pendingDelete: Promise<void> | null = null;

/** Once set, every write from this tab is refused. */
export function markWiped(): void {
    wiped = true;
}

export function assertWritable(): void {
    if (wiped) {
        throw new StoreWipedError();
    }
}

export function setPendingDelete(p: Promise<void> | null): void {
    pendingDelete = p;
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
        const opening = openDB<LwtvSchema>(DB_NAME, DB_VERSION, {
            upgrade(db) {
                db.createObjectStore('settings');
                db.createObjectStore('watched', { keyPath: 'showId' });
                db.createObjectStore('ratings', { keyPath: 'showId' });
                db.createObjectStore('identity');
            },
            // Another tab (or the wipe) wants to delete or upgrade the database.
            blocking() {
                markWiped();
                closeDb();
            },
            // The browser closed the connection under us; open afresh next time.
            terminated() {
                if (dbPromise === opening) {
                    dbPromise = null;
                }
            },
        });
        dbPromise = opening;
        opening.catch(() => {
            if (dbPromise === opening) {
                dbPromise = null;
            }
        });
    }
    return dbPromise;
}

export function closeDb(): void {
    const opening = dbPromise;
    dbPromise = null;
    opening?.then((db) => db.close(), () => {});
}

/** Test helper: forget the wiped flag and any pending delete. Not part of the public API. */
export function __resetForTests(): void {
    wiped = false;
    pendingDelete = null;
}
