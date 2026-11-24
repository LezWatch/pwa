import { test, expect, vi, beforeEach, afterEach } from 'vitest';
import { closeDb, getDb, __resetForTests } from './db';
import { __resetKeySupportForTests, getOrCreateIdentity } from './identity';
import { setWatched, setRating } from './shows';
import { saveSettings } from './settings';
import { fromBase64Url } from './base64url';
import {
    BackupError,
    MIN_ITERATIONS,
    decryptBackup,
    encryptBackup,
    type BackupPlaintext,
} from './backup-format';
import { exportBackup } from './backup';

const plain: BackupPlaintext = {
    schema: 1,
    exportedAt: 1795291200000,
    settings: {
        schema: 1,
        setupComplete: true,
        region: 'US',
        services: 'all',
        tropes: { 12: 'include' },
        hideQueerDeath: true,
        triggers: 'show-all',
        starsOnly: false,
        starTiers: ['gold'],
        showAnti: false,
    },
    watched: [{ showId: 5, watchedAt: 1000 }],
    ratings: [{ showId: 5, value: 4, ratedAt: 2000 }],
    identity: null,
};

const FAST = MIN_ITERATIONS;

beforeEach(() => {
    closeDb();
    __resetForTests();
    __resetKeySupportForTests();
});

afterEach(() => {
    vi.restoreAllMocks();
});

async function rejectsWith(p: Promise<unknown>, code: string) {
    await expect(p).rejects.toMatchObject({ name: 'BackupError', code });
}

test('round-trips', async () => {
    const text = await encryptBackup(plain, 'correct horse', FAST);
    expect(await decryptBackup(text, 'correct horse')).toEqual(plain);
});

test('file shape, and fresh salt, IV and data every export', async () => {
    const a = JSON.parse(await encryptBackup(plain, 'correct horse', 1000));
    const b = JSON.parse(await encryptBackup(plain, 'correct horse', 1000));
    expect(Object.keys(a)).toEqual(['format', 'version', 'kdf', 'cipher', 'data']);
    expect(a).toMatchObject({
        format: 'lwtv-backup',
        version: 1,
        kdf: { name: 'PBKDF2', hash: 'SHA-256', iterations: 1000 },
        cipher: { name: 'AES-GCM' },
    });
    expect(fromBase64Url(a.kdf.salt)).toHaveLength(16);
    expect(fromBase64Url(a.cipher.iv)).toHaveLength(12);
    expect(b.kdf.salt).not.toBe(a.kdf.salt);
    expect(b.cipher.iv).not.toBe(a.cipher.iv);
    expect(b.data).not.toBe(a.data);
});

test('the default iteration count is 600000', async () => {
    const file = JSON.parse(await encryptBackup(plain, 'correct horse'));
    expect(file.kdf.iterations).toBe(600000);
});

test('a wrong passphrase, tampered or truncated data, or an edited header is refused', async () => {
    const text = await encryptBackup(plain, 'correct horse', FAST);
    const file = JSON.parse(text);

    await rejectsWith(decryptBackup(text, 'wrong horse'), 'wrong-passphrase-or-damaged');

    const mid = Math.floor(file.data.length / 2);
    const flipped = file.data[mid] === 'A' ? 'B' : 'A';
    const tampered = { ...file, data: file.data.slice(0, mid) + flipped + file.data.slice(mid + 1) };
    await rejectsWith(decryptBackup(JSON.stringify(tampered), 'correct horse'), 'wrong-passphrase-or-damaged');

    const cut = { ...file, data: file.data.slice(0, file.data.length - 8) };
    await rejectsWith(decryptBackup(JSON.stringify(cut), 'correct horse'), 'wrong-passphrase-or-damaged');

    const edited = { ...file, kdf: { ...file.kdf, iterations: FAST + 1 } };
    await rejectsWith(decryptBackup(JSON.stringify(edited), 'correct horse'), 'wrong-passphrase-or-damaged');
});

test('damaged header fields and empty data are refused as damaged', async () => {
    const file = JSON.parse(await encryptBackup(plain, 'correct horse', 1000));
    for (const bad of [
        { ...file, data: '' },
        { ...file, kdf: { ...file.kdf, salt: 'AAAA' } },
        { ...file, cipher: { ...file.cipher, iv: 'AAAA' } },
        { ...file, kdf: { ...file.kdf, salt: 5 } },
    ]) {
        // iterations below the minimum would be refused first, so fix them up.
        bad.kdf.iterations = FAST;
        await rejectsWith(decryptBackup(JSON.stringify(bad), 'correct horse'), 'wrong-passphrase-or-damaged');
    }
});

test('not-a-backup and unsupported-version, before any key derivation', async () => {
    const file = JSON.parse(await encryptBackup(plain, 'correct horse', FAST));
    const derive = vi.spyOn(crypto.subtle, 'deriveKey');
    const importKey = vi.spyOn(crypto.subtle, 'importKey');

    await rejectsWith(decryptBackup('not json {', 'x'), 'not-a-backup');
    await rejectsWith(decryptBackup('[]', 'x'), 'not-a-backup');
    const { format: _format, ...noFormat } = file;
    await rejectsWith(decryptBackup(JSON.stringify(noFormat), 'x'), 'not-a-backup');
    await rejectsWith(decryptBackup(JSON.stringify({ ...file, version: 2 }), 'x'), 'unsupported-version');
    for (const iterations of [99999, 10000001, 1.5]) {
        const bad = { ...file, kdf: { ...file.kdf, iterations } };
        await rejectsWith(decryptBackup(JSON.stringify(bad), 'x'), 'unsupported-version');
    }
    const badKdf = { ...file, kdf: { ...file.kdf, name: 'scrypt' } };
    await rejectsWith(decryptBackup(JSON.stringify(badKdf), 'x'), 'unsupported-version');
    const badCipher = { ...file, cipher: { ...file.cipher, name: 'AES-CBC' } };
    await rejectsWith(decryptBackup(JSON.stringify(badCipher), 'x'), 'unsupported-version');

    expect(derive).not.toHaveBeenCalled();
    expect(importKey).not.toHaveBeenCalled();
});

test('RF1: a composed and a decomposed passphrase are the same', async () => {
    const text = await encryptBackup(plain, 'café au lait', FAST);
    expect(await decryptBackup(text, 'café au lait')).toEqual(plain);
    expect(await decryptBackup(await encryptBackup(plain, 'café', FAST), 'café')).toEqual(plain);
});

test('exportBackup refuses a short passphrase, counted after NFC, and allows exactly 8', async () => {
    await rejectsWith(exportBackup('short'), 'passphrase-too-short');
    // 'cafe' + combining acute + 'abc' is 8 code points as typed but 7 once composed.
    const decomposed = 'cafe\u0301abc';
    expect([...decomposed]).toHaveLength(8);
    expect([...decomposed.normalize('NFC')]).toHaveLength(7);
    await rejectsWith(exportBackup(decomposed), 'passphrase-too-short');
    // Exactly 8 after NFC is accepted.
    const { filename } = await exportBackup('cafe\u0301abcd');
    expect(filename).toMatch(/^lwtv-backup-/);
});

test('the header is authenticated: known-answer decrypt needs the exact additional data', async () => {
    const passphrase = 'caf\u00e9 au lait';
    const file = JSON.parse(await encryptBackup(plain, passphrase, MIN_ITERATIONS));
    const material = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(passphrase.normalize('NFC')),
        'PBKDF2',
        false,
        ['deriveKey'],
    );
    const key = await crypto.subtle.deriveKey(
        { name: 'PBKDF2', hash: 'SHA-256', salt: fromBase64Url(file.kdf.salt) as BufferSource, iterations: file.kdf.iterations },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['decrypt'],
    );
    const iv = fromBase64Url(file.cipher.iv) as BufferSource;
    const data = fromBase64Url(file.data) as BufferSource;
    const aad = new TextEncoder().encode(
        JSON.stringify({
            format: 'lwtv-backup',
            version: 1,
            kdf: { name: 'PBKDF2', hash: 'SHA-256', iterations: file.kdf.iterations, salt: file.kdf.salt },
            cipher: { name: 'AES-GCM', iv: file.cipher.iv },
        }),
    );
    const opened = await crypto.subtle.decrypt({ name: 'AES-GCM', iv, additionalData: aad }, key, data);
    expect(JSON.parse(new TextDecoder().decode(opened))).toEqual(plain);
    await expect(crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, data)).rejects.toThrow();
});

test('exportBackup carries the key, whose x matches publicKeyRaw', async () => {
    await setWatched(7, true);
    await setRating(7, 5);
    await saveSettings({ region: 'CA' });
    const identity = await getOrCreateIdentity();

    const { blob, filename } = await exportBackup('correct horse');
    expect(filename).toMatch(/^lwtv-backup-\d{4}-\d{2}-\d{2}\.json$/);
    expect(blob.type).toBe('application/json');

    const out = (await decryptBackup(await blob.text(), 'correct horse')) as BackupPlaintext;
    expect(out.identity?.alg).toBe('Ed25519');
    expect(out.identity?.publicJwk.x).toBe(identity.publicKeyRaw);
    expect(out.identity?.privateJwk.x).toBe(identity.publicKeyRaw);
    expect(out.identity?.privateJwk.d).toBeTruthy();
    expect(out.identity?.createdAt).toBe(identity.createdAt);
    expect(out.settings.region).toBe('CA');
    expect(out.watched).toEqual([{ showId: 7, watchedAt: expect.any(Number) }]);
    expect(out.ratings).toMatchObject([{ showId: 7, value: 5 }]);
});

test('exportBackup with no identity says null and creates none', async () => {
    const { blob } = await exportBackup('correct horse');
    const out = (await decryptBackup(await blob.text(), 'correct horse')) as BackupPlaintext;
    expect(out.identity).toBeNull();
    expect(await (await getDb()).count('identity')).toBe(0);
});
