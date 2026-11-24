import { BackupError, encryptBackup, type BackupPlaintext } from './backup-format';
import { readDb, type IdentityRecord, type RatingRecord, type WatchedRecord } from './db';
import { withDefaults, type Settings } from './settings';

const MIN_PASSPHRASE_CHARACTERS = 8;

function pad(n: number): string {
    return String(n).padStart(2, '0');
}

/** Encrypts everything on this device. Never creates a key. */
export async function exportBackup(passphrase: string): Promise<{ blob: Blob; filename: string }> {
    if ([...passphrase.normalize('NFC')].length < MIN_PASSPHRASE_CHARACTERS) {
        throw new BackupError('passphrase-too-short');
    }

    // One readonly transaction, so a write from another tab can't land between
    // the reads and leave, say, a rating for a show the file says is unwatched.
    const snapshot = await readDb(
        () => ({ settings: undefined, watched: [], ratings: [], identity: undefined }),
        async (db): Promise<{
            settings: Settings | undefined;
            watched: WatchedRecord[];
            ratings: RatingRecord[];
            identity: IdentityRecord | undefined;
        }> => {
            const tx = db.transaction(['settings', 'watched', 'ratings', 'identity'], 'readonly');
            const [settings, watched, ratings, identity] = await Promise.all([
                tx.objectStore('settings').get('current'),
                tx.objectStore('watched').getAll(),
                tx.objectStore('ratings').getAll(),
                tx.objectStore('identity').get('primary'),
                tx.done,
            ]);
            return { settings, watched, ratings, identity };
        },
    );

    // Exporting the keys is async crypto, so it runs after the transaction.
    const identity = snapshot.identity ?? null;
    const plain: BackupPlaintext = {
        schema: 1,
        exportedAt: Date.now(),
        settings: withDefaults(snapshot.settings),
        watched: snapshot.watched.sort((a, b) => a.showId - b.showId),
        ratings: snapshot.ratings.sort((a, b) => a.showId - b.showId),
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
