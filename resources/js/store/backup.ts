import { BackupError, encryptBackup, type BackupPlaintext } from './backup-format';
import { getIdentity } from './identity';
import { getSettings } from './settings';
import { listRatings, listWatchedRecords } from './shows';

const MIN_PASSPHRASE_CHARACTERS = 8;

function pad(n: number): string {
    return String(n).padStart(2, '0');
}

/** Encrypts everything on this device. Never creates a key. */
export async function exportBackup(passphrase: string): Promise<{ blob: Blob; filename: string }> {
    if ([...passphrase.normalize('NFC')].length < MIN_PASSPHRASE_CHARACTERS) {
        throw new BackupError('passphrase-too-short');
    }

    const identity = await getIdentity();
    const plain: BackupPlaintext = {
        schema: 1,
        exportedAt: Date.now(),
        settings: await getSettings(),
        watched: await listWatchedRecords(),
        ratings: await listRatings(),
        identity: identity && {
            alg: 'Ed25519',
            privateJwk: await crypto.subtle.exportKey('jwk', identity.privateKey),
            publicJwk: await crypto.subtle.exportKey('jwk', identity.publicKey),
            createdAt: identity.createdAt,
        },
    };

    const text = await encryptBackup(plain, passphrase);
    const now = new Date(plain.exportedAt);
    return {
        blob: new Blob([text], { type: 'application/json' }),
        filename: `lwtv-backup-${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}.json`,
    };
}
