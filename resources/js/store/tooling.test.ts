import { openDB } from 'idb';
import { expect, test } from 'vitest';

test('fake-indexeddb stores and returns a usable Ed25519 CryptoKey', async () => {
    const pair = (await crypto.subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify'])) as CryptoKeyPair;
    const db = await openDB('t', 1, { upgrade: (d) => d.createObjectStore('k') });
    await db.put('k', pair, 'p');
    const back = (await db.get('k', 'p')) as CryptoKeyPair;
    const msg = new TextEncoder().encode('x');
    const sig = await crypto.subtle.sign('Ed25519', back.privateKey, msg);
    expect(await crypto.subtle.verify('Ed25519', pair.publicKey, sig, msg)).toBe(true);
});
