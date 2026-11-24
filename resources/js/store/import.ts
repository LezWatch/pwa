import { BackupError, decryptBackup } from './backup-format';
import { channel, writeTx, type IdentityRecord } from './db';
import { getIdentity } from './identity';
import { fromBase64Url } from './base64url';
import { validateSettings, SettingsError, type Settings } from './settings';
import type { Rating } from './shows';

const MAX_BYTES = 5 * 1024 * 1024;
const TEST_MESSAGE = new TextEncoder().encode('lwtv-backup-key-check');

interface Validated {
    settings: Settings;
    watched: Array<{ showId: number; watchedAt: number }>;
    ratings: Rating[];
    identity: Omit<IdentityRecord, 'id'> | null;
}

function invalid(): never {
    throw new BackupError('invalid-contents');
}

function isObject(v: unknown): v is Record<string, unknown> {
    return typeof v === 'object' && v !== null && !Array.isArray(v);
}

function finite(v: unknown): number {
    return typeof v === 'number' && Number.isFinite(v) ? v : invalid();
}

function showId(v: unknown): number {
    return typeof v === 'number' && Number.isSafeInteger(v) && v > 0 ? v : invalid();
}

function list(v: unknown): unknown[] {
    return Array.isArray(v) ? v : invalid();
}

async function validateIdentity(v: unknown): Promise<Validated['identity']> {
    if (v === null) return null;
    if (!isObject(v) || v.alg !== 'Ed25519' || !isObject(v.privateJwk) || !isObject(v.publicJwk)) invalid();
    const createdAt = finite(v.createdAt);

    const x = (v.publicJwk as Record<string, unknown>).x;
    try {
        if (typeof x !== 'string' || fromBase64Url(x).length !== 32) invalid();
    } catch {
        invalid();
    }

    let privateKey: CryptoKey;
    let publicKey: CryptoKey;
    try {
        privateKey = await crypto.subtle.importKey('jwk', v.privateJwk as JsonWebKey, 'Ed25519', true, ['sign']);
        publicKey = await crypto.subtle.importKey('jwk', v.publicJwk as JsonWebKey, 'Ed25519', true, ['verify']);
        const signature = await crypto.subtle.sign('Ed25519', privateKey, TEST_MESSAGE);
        if (!(await crypto.subtle.verify('Ed25519', publicKey, signature, TEST_MESSAGE))) invalid();
    } catch {
        invalid();
    }

    return { alg: 'Ed25519', privateKey, publicKey, publicKeyRaw: x as string, createdAt };
}

async function validate(plain: unknown): Promise<Validated> {
    if (!isObject(plain) || plain.schema !== 1) invalid();
    finite(plain.exportedAt);

    let settings: Settings;
    try {
        settings = validateSettings(plain.settings);
    } catch (error) {
        if (error instanceof SettingsError) invalid();
        throw error;
    }

    const watched: Validated['watched'] = [];
    const watchedIds = new Set<number>();
    for (const item of list(plain.watched)) {
        if (!isObject(item)) invalid();
        const id = showId(item.showId);
        if (watchedIds.has(id)) invalid();
        watchedIds.add(id);
        watched.push({ showId: id, watchedAt: finite(item.watchedAt) });
    }

    const ratings: Rating[] = [];
    const ratedIds = new Set<number>();
    for (const item of list(plain.ratings)) {
        if (!isObject(item)) invalid();
        const id = showId(item.showId);
        const value = item.value;
        if (ratedIds.has(id) || !watchedIds.has(id)) invalid();
        if (typeof value !== 'number' || !Number.isInteger(value) || value < 1 || value > 5) invalid();
        ratedIds.add(id);
        ratings.push({ showId: id, value: value as Rating['value'], ratedAt: finite(item.ratedAt) });
    }

    return { settings, watched, ratings, identity: await validateIdentity(plain.identity) };
}

/** True when importing `incoming` would drop or change the key the device already has. */
function replacesKey(
    device: { publicKeyRaw: string } | null,
    incoming: { publicKeyRaw: string } | null,
): boolean {
    return device !== null && (incoming === null || incoming.publicKeyRaw !== device.publicKeyRaw);
}

/**
 * Replaces everything on this device with the backup, all or nothing.
 * Everything is checked before the one write transaction starts.
 */
export async function importBackup(
    file: Blob,
    passphrase: string,
    opts?: { allowKeyChange?: boolean },
): Promise<void> {
    if (file.size > MAX_BYTES) throw new BackupError('not-a-backup');

    const data = await validate(await decryptBackup(await file.text(), passphrase));

    const allow = opts?.allowKeyChange === true;
    // Early check, so the common case fails before a readwrite transaction opens.
    if (!allow && replacesKey(await getIdentity(), data.identity)) {
        throw new BackupError('key-change-needs-confirmation');
    }

    await writeTx(['settings', 'watched', 'ratings', 'identity'], async (tx) => {
        // Re-check inside the transaction: another tab may have stored a key meanwhile.
        const current = await tx.objectStore('identity').get('primary');
        if (!allow && replacesKey(current ?? null, data.identity)) {
            throw new BackupError('key-change-needs-confirmation');
        }
        await Promise.all([
            tx.objectStore('settings').clear(),
            tx.objectStore('watched').clear(),
            tx.objectStore('ratings').clear(),
            tx.objectStore('identity').clear(),
        ]);
        await tx.objectStore('settings').put(data.settings, 'current');
        for (const w of data.watched) await tx.objectStore('watched').put(w);
        for (const r of data.ratings) await tx.objectStore('ratings').put(r);
        if (data.identity) {
            await tx.objectStore('identity').put({ id: 'primary', ...data.identity }, 'primary');
        }
    });

    channel().postMessage({ type: 'replaced' } satisfies import('./db').StoreMessage);
}
