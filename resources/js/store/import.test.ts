import { test, expect, vi, beforeEach, afterEach } from 'vitest';
import { closeDb, __resetForTests } from './db';
import * as identityModule from './identity';
import { __resetKeySupportForTests, getIdentity, getOrCreateIdentity } from './identity';
import { setWatched, setRating, listWatched, listRatings } from './shows';
import { getSettings, saveSettings } from './settings';
import { MIN_ITERATIONS, encryptBackup, type BackupPlaintext } from './backup-format';
import { importBackup } from './import';

const PASS = 'correct horse';

const settings: BackupPlaintext['settings'] = {
    schema: 1,
    setupComplete: true,
    region: 'GB',
    services: ['netflix'],
    tropes: { 12: 'exclude' },
    hideQueerDeath: false,
    triggers: 'hide-high',
    starsOnly: true,
    starTiers: ['gold'],
    showAnti: true,
};

async function keyFixture(): Promise<NonNullable<BackupPlaintext['identity']>> {
    const pair = (await crypto.subtle.generateKey('Ed25519', true, ['sign', 'verify'])) as CryptoKeyPair;
    return {
        alg: 'Ed25519',
        privateJwk: await crypto.subtle.exportKey('jwk', pair.privateKey),
        publicJwk: await crypto.subtle.exportKey('jwk', pair.publicKey),
        createdAt: 777,
    };
}

function base(identity: BackupPlaintext['identity'] = null): BackupPlaintext {
    return {
        schema: 1,
        exportedAt: 1795291200000,
        settings: structuredClone(settings),
        watched: [
            { showId: 20, watchedAt: 100 },
            { showId: 21, watchedAt: 200 },
        ],
        ratings: [{ showId: 20, value: 5, ratedAt: 300 }],
        identity,
    };
}

async function fileOf(plain: unknown, passphrase = PASS): Promise<Blob> {
    return new Blob([await encryptBackup(plain as BackupPlaintext, passphrase, MIN_ITERATIONS)]);
}

async function seedDevice() {
    await saveSettings({ region: 'US', setupComplete: true });
    await setWatched(5, true);
    await setRating(5, 3);
}

async function expectDeviceUntouched() {
    expect((await getSettings()).region).toBe('US');
    expect((await listWatched())).toEqual([5]);
    const ratings = await listRatings();
    expect(ratings).toHaveLength(1);
    expect(ratings[0]).toMatchObject({ showId: 5, value: 3 });
}

async function rejectsWith(p: Promise<unknown>, code: string) {
    await expect(p).rejects.toMatchObject({ name: 'BackupError', code });
}

beforeEach(() => {
    closeDb();
    __resetForTests();
    __resetKeySupportForTests();
});

afterEach(() => {
    vi.restoreAllMocks();
});

test('refuses a file over 5 MB, and non-JSON text, without deriving a key', async () => {
    await seedDevice();
    const derive = vi.spyOn(crypto.subtle, 'deriveKey');
    await rejectsWith(importBackup(new Blob([new Uint8Array(5 * 1024 * 1024 + 1)]), PASS), 'not-a-backup');
    await rejectsWith(importBackup(new Blob(['x'.repeat(1024)]), PASS), 'not-a-backup');
    expect(derive).not.toHaveBeenCalled();
    await expectDeviceUntouched();
});

test('a wrong passphrase leaves the device alone', async () => {
    await seedDevice();
    await rejectsWith(importBackup(await fileOf(base()), 'wrong horse!'), 'wrong-passphrase-or-damaged');
    await expectDeviceUntouched();
});

const badContents: Array<[string, (p: any) => void]> = [
    ['a rating of 6', (p) => (p.ratings[0].value = 6)],
    ['a rating of 2.5', (p) => (p.ratings[0].value = 2.5)],
    ['a rating for an unwatched show', (p) => (p.ratings[0].showId = 99)],
    ['a negative showId', (p) => (p.watched[0].showId = -1)],
    ['a duplicate watched showId', (p) => (p.watched[1].showId = 20)],
    ['a duplicate rating showId', (p) => p.ratings.push({ showId: 20, value: 1, ratedAt: 1 })],
    ['bad settings', (p) => (p.settings.region = 'usa')],
    ['a wrong schema', (p) => (p.schema = 2)],
    ['a non-finite exportedAt', (p) => (p.exportedAt = 'now')],
    ['watched that is not an array', (p) => (p.watched = {})],
];

for (const [name, mutate] of badContents) {
    test(`rejects ${name} as invalid-contents and changes nothing`, async () => {
        await seedDevice();
        const plain: any = base();
        mutate(plain);
        await rejectsWith(importBackup(await fileOf(plain), PASS), 'invalid-contents');
        await expectDeviceUntouched();
    });
}

test('rejects a public key that does not match the private key', async () => {
    await seedDevice();
    const a = await keyFixture();
    const b = await keyFixture();
    const plain = base({ ...a, publicJwk: b.publicJwk });
    await rejectsWith(importBackup(await fileOf(plain), PASS), 'invalid-contents');
    await expectDeviceUntouched();
});

test('rejects a key that is not a JWK at all', async () => {
    await seedDevice();
    const a = await keyFixture();
    const plain = base({ ...a, privateJwk: { kty: 'nope' } });
    await rejectsWith(importBackup(await fileOf(plain), PASS), 'invalid-contents');
    await expectDeviceUntouched();
});

test('onto a device with no key, replaces everything', async () => {
    await saveSettings({ region: 'US', setupComplete: true });
    await setWatched(5, true);
    expect(await getIdentity()).toBeNull();
    const key = await keyFixture();
    await importBackup(await fileOf(base(key)), PASS);
    expect(await getSettings()).toEqual(settings);
    expect((await listWatched()).sort()).toEqual([20, 21]);
    expect(await listRatings()).toEqual([{ showId: 20, value: 5, ratedAt: 300 }]);
    const id = await getIdentity();
    expect(id?.publicKeyRaw).toBe(key.publicJwk.x);
    expect(id?.createdAt).toBe(777);
});

test('a key-less device imports a key-less backup without any option', async () => {
    await saveSettings({ region: 'US' });
    await setWatched(5, true);
    await importBackup(await fileOf(base()), PASS);
    expect((await listWatched()).sort()).toEqual([20, 21]);
    expect(await getIdentity()).toBeNull();
});

test('a different key, or none, needs confirmation; then replaces', async () => {
    await getOrCreateIdentity();
    await seedDevice();
    const before = (await getIdentity())!.publicKeyRaw;
    const key = await keyFixture();

    await rejectsWith(importBackup(await fileOf(base(key)), PASS), 'key-change-needs-confirmation');
    await rejectsWith(importBackup(await fileOf(base(null)), PASS), 'key-change-needs-confirmation');
    await expectDeviceUntouched();
    expect((await getIdentity())!.publicKeyRaw).toBe(before);

    await importBackup(await fileOf(base(key)), PASS, { allowKeyChange: true });
    expect((await getIdentity())!.publicKeyRaw).toBe(key.publicJwk.x);
    expect((await listWatched()).length).toBe(2);

    await importBackup(await fileOf(base(null)), PASS, { allowKeyChange: true });
    expect(await getIdentity()).toBeNull();
    expect(await listRatings()).toHaveLength(1);
});

test('the same key as the device needs no option', async () => {
    await getOrCreateIdentity();
    await seedDevice();
    const device = (await getIdentity())!;
    const same: NonNullable<BackupPlaintext['identity']> = {
        alg: 'Ed25519',
        privateJwk: await crypto.subtle.exportKey('jwk', device.privateKey),
        publicJwk: await crypto.subtle.exportKey('jwk', device.publicKey),
        createdAt: device.createdAt,
    };
    await importBackup(await fileOf(base(same)), PASS);
    expect((await getIdentity())!.publicKeyRaw).toBe(device.publicKeyRaw);
    expect((await listWatched()).length).toBe(2);
});

test('stored identity has only the known fields', async () => {
    const key = await keyFixture();
    (key as any).extra = 'x';
    await importBackup(await fileOf(base(key)), PASS);
    const db = await (await import('./db')).getDb();
    const record = await db.get('identity', 'primary');
    expect(Object.keys(record!).sort()).toEqual(
        ['alg', 'createdAt', 'id', 'privateKey', 'publicKey', 'publicKeyRaw'],
    );
});

test('another tab is told once after a successful import, and not after a failed one', async () => {
    await seedDevice();
    vi.resetModules();
    const tabB = await import('./index');
    const cb = vi.fn();
    const off = tabB.onReplaced(cb);

    await rejectsWith(importBackup(await fileOf({ ...base(), schema: 2 }), PASS), 'invalid-contents');
    await importBackup(await fileOf(base()), PASS, { allowKeyChange: true });

    await vi.waitFor(() => expect(cb).toHaveBeenCalledTimes(1), { timeout: 2000 });
    await new Promise((r) => setTimeout(r, 100));
    expect(cb).toHaveBeenCalledTimes(1);
    off();
});

test('a key that appears after the early check still needs confirmation', async () => {
    await getOrCreateIdentity();
    await seedDevice();
    const before = (await getIdentity())!.publicKeyRaw;
    const file = await fileOf(base(await keyFixture()));
    // The early check sees no key; the store actually holds one.
    vi.spyOn(identityModule, 'getIdentity').mockResolvedValueOnce(null);
    await rejectsWith(importBackup(file, PASS), 'key-change-needs-confirmation');
    await expectDeviceUntouched();
    expect((await getIdentity())!.publicKeyRaw).toBe(before);
});
