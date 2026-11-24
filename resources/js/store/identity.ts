import { getDb, writeTx, type IdentityRecord } from './db';
import { toBase64Url } from './base64url';

export type Identity = Omit<IdentityRecord, 'id'>;

export class KeyUnsupportedError extends Error {
    constructor() {
        super('This browser cannot make Ed25519 keys.');
        this.name = 'KeyUnsupportedError';
    }
}

const KEY = 'primary';

function generate(): Promise<CryptoKeyPair> {
    return crypto.subtle.generateKey('Ed25519', true, ['sign', 'verify']) as Promise<CryptoKeyPair>;
}

let support: Promise<'ok' | 'unsupported'> | null = null;

/** Tries to make a throwaway key once per page and remembers the answer. */
export function keySupport(): Promise<'ok' | 'unsupported'> {
    support ??= generate().then(
        () => 'ok' as const,
        () => 'unsupported' as const,
    );
    return support;
}

/** Test helper: forget the cached probe and any in-flight creation. Not part of the public API. */
export function __resetKeySupportForTests(): void {
    support = null;
    creating = null;
}

function toIdentity({ id: _id, ...identity }: IdentityRecord): Identity {
    return identity;
}

/** The stored key, or null. Never creates one. */
export async function getIdentity(): Promise<Identity | null> {
    const db = await getDb();
    const record = await db.get('identity', KEY);
    return record ? toIdentity(record) : null;
}

let creating: Promise<Identity> | null = null;

async function create(): Promise<Identity> {
    const existing = await getIdentity();
    if (existing) return existing;

    if ((await keySupport()) === 'unsupported') throw new KeyUnsupportedError();

    // Generate outside any transaction: awaiting crypto inside one lets IndexedDB auto-commit.
    const pair = await generate();
    const record: IdentityRecord = {
        id: KEY,
        alg: 'Ed25519',
        privateKey: pair.privateKey,
        publicKey: pair.publicKey,
        publicKeyRaw: toBase64Url(await crypto.subtle.exportKey('raw', pair.publicKey)),
        createdAt: Date.now(),
    };

    // Another tab may have stored a key meanwhile: keep that one.
    return writeTx(['identity'], async (tx) => {
        const winner = await tx.store.get(KEY);
        if (winner) return toIdentity(winner);
        await tx.store.put(record, KEY);
        return toIdentity(record);
    });
}

/** Called only when a person saves their first rating. Makes the key once, never twice. */
export function getOrCreateIdentity(): Promise<Identity> {
    creating ??= create().finally(() => {
        creating = null;
    });
    return creating;
}
