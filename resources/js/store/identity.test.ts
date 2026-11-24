import { test, expect, vi, beforeEach, afterEach } from 'vitest';
import { closeDb, getDb, __resetForTests } from './db';
import {
    keySupport,
    getIdentity,
    getOrCreateIdentity,
    KeyUnsupportedError,
    __resetKeySupportForTests,
} from './identity';
import { toBase64Url, fromBase64Url } from './base64url';

beforeEach(() => {
    closeDb();
    __resetForTests();
    __resetKeySupportForTests();
});

afterEach(() => {
    vi.restoreAllMocks();
});

test('getIdentity is null on an empty database and creates nothing', async () => {
    expect(await getIdentity()).toBeNull();
    const db = await getDb();
    expect(await db.count('identity')).toBe(0);
});

test('getOrCreateIdentity twice gives one key that signs and verifies', async () => {
    const first = await getOrCreateIdentity();
    const second = await getOrCreateIdentity();
    expect(second.publicKeyRaw).toBe(first.publicKeyRaw);
    expect(fromBase64Url(first.publicKeyRaw)).toHaveLength(32);
    expect(first.alg).toBe('Ed25519');
    const data = new TextEncoder().encode('hello');
    const sig = await crypto.subtle.sign('Ed25519', first.privateKey, data);
    expect(await crypto.subtle.verify('Ed25519', second.publicKey, sig, data)).toBe(true);
    expect(await getIdentity()).toMatchObject({ publicKeyRaw: first.publicKeyRaw });
});

test('concurrent calls make one key and store one record', async () => {
    const [a, b] = await Promise.all([getOrCreateIdentity(), getOrCreateIdentity()]);
    expect(a.publicKeyRaw).toBe(b.publicKeyRaw);
    const db = await getDb();
    expect(await db.count('identity')).toBe(1);
});

test('a key made by another tab in the meantime wins', async () => {
    const db = await getDb();
    const real = await crypto.subtle.generateKey('Ed25519', true, ['sign', 'verify']);
    const pair = real as CryptoKeyPair;
    const raw = toBase64Url(await crypto.subtle.exportKey('raw', pair.publicKey));
    const original = crypto.subtle.generateKey.bind(crypto.subtle);
    vi.spyOn(crypto.subtle, 'generateKey').mockImplementation(async (...args) => {
        // Another tab stores its key while this one is still generating.
        await db.put('identity', {
            id: 'primary',
            alg: 'Ed25519',
            privateKey: pair.privateKey,
            publicKey: pair.publicKey,
            publicKeyRaw: raw,
            createdAt: 1,
        }, 'primary');
        return (original as (...a: unknown[]) => Promise<unknown>)(...args) as Promise<CryptoKeyPair>;
    });
    __resetKeySupportForTests();
    const got = await getOrCreateIdentity();
    expect(got.publicKeyRaw).toBe(raw);
    expect(await db.count('identity')).toBe(1);
});

test('unsupported Ed25519 reports unsupported and refuses to create', async () => {
    vi.spyOn(crypto.subtle, 'generateKey').mockRejectedValue(new DOMException('', 'NotSupportedError'));
    expect(await keySupport()).toBe('unsupported');
    await expect(getOrCreateIdentity()).rejects.toBeInstanceOf(KeyUnsupportedError);
    const db = await getDb();
    expect(await db.count('identity')).toBe(0);
});

test('keySupport is ok when Ed25519 works, and is cached', async () => {
    const spy = vi.spyOn(crypto.subtle, 'generateKey');
    expect(await keySupport()).toBe('ok');
    expect(await keySupport()).toBe('ok');
    expect(spy).toHaveBeenCalledTimes(1);
});

test('base64url round-trips without padding and rejects bad input', () => {
    const bytes = new Uint8Array([251, 255, 254, 1]);
    const s = toBase64Url(bytes);
    expect(s).not.toMatch(/[+/=]/);
    expect(Array.from(fromBase64Url(s))).toEqual(Array.from(bytes));
    expect(() => fromBase64Url('ab+/')).toThrow();
    expect(() => fromBase64Url('a')).toThrow();
    expect(() => fromBase64Url('ab=c')).toThrow();
});
