import {
    DB_NAME,
    STORES,
    channel,
    closeDb,
    getDb,
    isDeletePendingNow,
    noteWipeStarted,
    setPendingDelete,
    type StoreMessage,
} from './db';

// Lives in db.ts because the connection's blocking hook runs it too.
export { onWiped } from './db';

const WEB_STORAGE_PREFIX = 'lwtv:';

let running: Promise<void> | null = null;

/** True while the database delete waits on another tab. Reads show the empty state meanwhile. */
export function isDeletePending(): boolean {
    return isDeletePendingNow();
}

/**
 * Deletes everything this app keeps on the device. Resolves once every store
 * is empty and the delete has finished or is waiting on another tab: it never
 * waits on that tab. This tab keeps working; its next write lands in a fresh
 * database once the delete finishes.
 */
export function wipeEverything(): Promise<void> {
    if (running) return running;
    // A delete is already waiting, and the stores were cleared before it was asked for.
    if (isDeletePendingNow()) return Promise.resolve();
    running = run().finally(() => {
        running = null;
    });
    return running;
}

async function run(): Promise<void> {
    // Reads show the empty state and new writes wait from here on; writes already
    // under way are refused rather than landing after the clear.
    let settle!: () => void;
    setPendingDelete(new Promise<void>((resolve) => (settle = resolve)));
    noteWipeStarted();

    try {
        // 1. Clear on this tab's own connection, before any delete is queued: once
        //    a delete waits, a new open waits behind it. When this commits, the data is gone.
        const db = await getDb();
        const tx = db.transaction([...STORES], 'readwrite');
        await Promise.all([...STORES.map((name) => tx.objectStore(name).clear()), tx.done]);
    } catch (error) {
        settle();
        throw error;
    }

    // 2. Tell the other tabs.
    channel().postMessage({ type: 'wipe' } satisfies StoreMessage);

    // 3-7. Let go, ask for the delete, and resolve on success or blocked.
    closeDb();
    await new Promise<void>((resolve) => {
        const request = indexedDB.deleteDatabase(DB_NAME);
        const finished = () => {
            // An error still settles: the stores were already cleared.
            settle();
            resolve();
        };
        request.onsuccess = finished;
        request.onerror = finished;
        request.onblocked = () => resolve();
        clearWebStorage();
    });
}

function clearWebStorage(): void {
    for (const name of ['localStorage', 'sessionStorage'] as const) {
        try {
            const storage = (globalThis as Partial<Record<typeof name, Storage>>)[name];
            if (!storage) continue;
            const keys: string[] = [];
            for (let i = 0; i < storage.length; i++) {
                const key = storage.key(i);
                if (key?.startsWith(WEB_STORAGE_PREFIX)) keys.push(key);
            }
            for (const key of keys) storage.removeItem(key);
        } catch {
            // Storage can be off limits (Safari private mode); there is nothing of ours to remove then.
        }
    }
}
