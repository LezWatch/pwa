import { getDb } from './db';

export interface StorageStatus {
    persisted: boolean;
    installed: boolean;
    isEmpty: boolean;
}

function isInstalled(): boolean {
    if (typeof matchMedia === 'function' && matchMedia('(display-mode: standalone)').matches) {
        return true;
    }
    return (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

export async function storageStatus(): Promise<StorageStatus> {
    const db = await getDb();
    const settings = await db.get('settings', 'current'); // raw record: getSettings() would return defaults
    const persisted =
        typeof navigator.storage?.persisted === 'function' ? await navigator.storage.persisted() : false;
    return { persisted, installed: isInstalled(), isEmpty: settings === undefined };
}

export async function requestPersistence(): Promise<boolean> {
    if (typeof navigator.storage?.persist !== 'function') return false;
    return navigator.storage.persist();
}
