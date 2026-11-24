import { toBase64Url, fromBase64Url } from './base64url';
import type { Settings } from './settings';
import type { Rating } from './shows';

export type BackupErrorCode =
    | 'not-a-backup'
    | 'unsupported-version'
    | 'wrong-passphrase-or-damaged'
    | 'invalid-contents'
    | 'key-change-needs-confirmation'
    | 'passphrase-too-short';

export class BackupError extends Error {
    readonly code: BackupErrorCode;

    constructor(code: BackupErrorCode, message?: string) {
        super(message ?? code);
        this.name = 'BackupError';
        this.code = code;
    }
}

export interface BackupPlaintext {
    schema: 1;
    exportedAt: number;
    settings: Settings;
    watched: Array<{ showId: number; watchedAt: number }>;
    ratings: Rating[];
    identity: {
        alg: 'Ed25519';
        privateJwk: JsonWebKey;
        publicJwk: JsonWebKey;
        createdAt: number;
    } | null;
}

export const DEFAULT_ITERATIONS = 600_000;
export const MIN_ITERATIONS = 100_000;
export const MAX_ITERATIONS = 10_000_000;

const SALT_BYTES = 16;
const IV_BYTES = 12;

interface Header {
    iterations: number;
    salt: string;
    iv: string;
}

/** The additional authenticated data, built field by field from the file's own values. */
function additionalData({ iterations, salt, iv }: Header): Uint8Array<ArrayBuffer> {
    const json = JSON.stringify({
        format: 'lwtv-backup',
        version: 1,
        kdf: { name: 'PBKDF2', hash: 'SHA-256', iterations, salt },
        cipher: { name: 'AES-GCM', iv },
    });
    return new TextEncoder().encode(json);
}

async function deriveKey(passphrase: string, salt: Uint8Array, iterations: number): Promise<CryptoKey> {
    const material = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(passphrase.normalize('NFC')),
        'PBKDF2',
        false,
        ['deriveKey'],
    );
    return crypto.subtle.deriveKey(
        { name: 'PBKDF2', hash: 'SHA-256', salt: salt as BufferSource, iterations },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

/** Returns the file's JSON text. `iterations` exists for tests. */
export async function encryptBackup(
    plain: BackupPlaintext,
    passphrase: string,
    iterations: number = DEFAULT_ITERATIONS,
): Promise<string> {
    const saltBytes = crypto.getRandomValues(new Uint8Array(SALT_BYTES));
    const ivBytes = crypto.getRandomValues(new Uint8Array(IV_BYTES));
    const header: Header = { iterations, salt: toBase64Url(saltBytes), iv: toBase64Url(ivBytes) };

    const key = await deriveKey(passphrase, saltBytes, iterations);
    const cipher = await crypto.subtle.encrypt(
        { name: 'AES-GCM', iv: ivBytes, additionalData: additionalData(header) },
        key,
        new TextEncoder().encode(JSON.stringify(plain)),
    );

    return JSON.stringify({
        format: 'lwtv-backup',
        version: 1,
        kdf: { name: 'PBKDF2', hash: 'SHA-256', iterations, salt: header.salt },
        cipher: { name: 'AES-GCM', iv: header.iv },
        data: toBase64Url(cipher),
    });
}

function isObject(v: unknown): v is Record<string, unknown> {
    return typeof v === 'object' && v !== null && !Array.isArray(v);
}

function damaged(): BackupError {
    return new BackupError('wrong-passphrase-or-damaged');
}

function decodeExact(value: unknown, length: number): Uint8Array {
    if (typeof value !== 'string') throw damaged();
    let bytes: Uint8Array;
    try {
        bytes = fromBase64Url(value);
    } catch {
        throw damaged();
    }
    if (bytes.length !== length) throw damaged();
    return bytes;
}

/** Checks the header, then decrypts. Returns the parsed plaintext, unvalidated. */
export async function decryptBackup(text: string, passphrase: string): Promise<unknown> {
    let file: unknown;
    try {
        file = JSON.parse(text);
    } catch {
        throw new BackupError('not-a-backup');
    }
    if (!isObject(file) || file.format !== 'lwtv-backup') throw new BackupError('not-a-backup');
    if (file.version !== 1) throw new BackupError('unsupported-version');

    const kdf = file.kdf;
    const cipher = file.cipher;
    if (
        !isObject(kdf) ||
        kdf.name !== 'PBKDF2' ||
        kdf.hash !== 'SHA-256' ||
        typeof kdf.iterations !== 'number' ||
        !Number.isInteger(kdf.iterations) ||
        kdf.iterations < MIN_ITERATIONS ||
        kdf.iterations > MAX_ITERATIONS ||
        !isObject(cipher) ||
        cipher.name !== 'AES-GCM'
    ) {
        throw new BackupError('unsupported-version');
    }

    const salt = decodeExact(kdf.salt, SALT_BYTES);
    const iv = decodeExact(cipher.iv, IV_BYTES);
    if (typeof file.data !== 'string' || file.data === '') throw damaged();
    let data: Uint8Array;
    try {
        data = fromBase64Url(file.data);
    } catch {
        throw damaged();
    }

    const header: Header = {
        iterations: kdf.iterations,
        salt: kdf.salt as string,
        iv: cipher.iv as string,
    };

    let plaintext: ArrayBuffer;
    try {
        const key = await deriveKey(passphrase, salt, header.iterations);
        plaintext = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: iv as BufferSource, additionalData: additionalData(header) },
            key,
            data as BufferSource,
        );
    } catch {
        throw damaged();
    }

    try {
        return JSON.parse(new TextDecoder().decode(plaintext));
    } catch {
        throw damaged();
    }
}
