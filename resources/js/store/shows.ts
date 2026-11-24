import { readDb, writeTx, type RatingRecord } from './db';

export type RatingValue = 1 | 2 | 3 | 4 | 5;
export type Rating = { showId: number; value: RatingValue; ratedAt: number };

export class ShowError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'ShowError';
    }
}

function checkId(showId: number): void {
    if (!Number.isSafeInteger(showId) || showId <= 0) {
        throw new ShowError('Invalid show id.');
    }
}

function checkValue(value: number): void {
    if (!Number.isInteger(value) || value < 1 || value > 5) {
        throw new ShowError('A rating must be a whole number from 1 to 5.');
    }
}

export async function isWatched(showId: number): Promise<boolean> {
    return readDb(
        () => false,
        async (db) => (await db.get('watched', showId)) !== undefined,
    );
}

export async function listWatched(): Promise<number[]> {
    const keys = await readDb(() => [], (db) => db.getAllKeys('watched'));
    return keys.sort((a, b) => a - b);
}

export async function getRating(showId: number): Promise<RatingValue | null> {
    const record = await readDb(() => undefined, (db) => db.get('ratings', showId));
    return record?.value ?? null;
}

export async function listRatings(): Promise<Rating[]> {
    const all: RatingRecord[] = await readDb(() => [], (db) => db.getAll('ratings'));
    return all.sort((a, b) => a.showId - b.showId);
}

export async function setWatched(showId: number, watched: boolean): Promise<void> {
    checkId(showId);
    await writeTx(['watched', 'ratings'], async (tx) => {
        if (watched) {
            if ((await tx.objectStore('watched').get(showId)) === undefined) {
                await tx.objectStore('watched').put({ showId, watchedAt: Date.now() });
            }
        } else {
            await tx.objectStore('watched').delete(showId);
            await tx.objectStore('ratings').delete(showId);
        }
    });
}

export async function setRating(showId: number, value: RatingValue): Promise<void> {
    checkId(showId);
    checkValue(value);
    await writeTx(['watched', 'ratings'], async (tx) => {
        if ((await tx.objectStore('watched').get(showId)) === undefined) {
            throw new ShowError('Mark the show as watched before rating it.');
        }
        await tx.objectStore('ratings').put({ showId, value, ratedAt: Date.now() });
    });
}
