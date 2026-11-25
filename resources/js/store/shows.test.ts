import { test, expect, beforeEach } from 'vitest';
import { closeDb, getDb, __resetForTests } from './db';
import {
    isWatched,
    setWatched,
    listWatched,
    getRating,
    setRating,
    listRatings,
    ShowError,
    type RatingValue,
} from './shows';

beforeEach(() => {
    closeDb();
    __resetForTests();
});

test('setWatched marks a show watched and lists it', async () => {
    await setWatched(7, true);
    expect(await isWatched(7)).toBe(true);
    expect(await listWatched()).toEqual([7]);
    expect(await isWatched(8)).toBe(false);
});

test('setWatched(true) again keeps the original watchedAt', async () => {
    await setWatched(7, true);
    const db = await getDb();
    const first = (await db.get('watched', 7))!.watchedAt;
    await new Promise((r) => setTimeout(r, 5));
    await setWatched(7, true);
    expect((await db.get('watched', 7))!.watchedAt).toBe(first);
});

test('listWatched is ascending and listRatings is ordered by showId', async () => {
    for (const id of [9, 3, 5]) await setWatched(id, true);
    expect(await listWatched()).toEqual([3, 5, 9]);
    await setRating(9, 1);
    await setRating(3, 5);
    expect((await listRatings()).map((r) => r.showId)).toEqual([3, 9]);
});

test('setRating on an unwatched show rejects and writes nothing', async () => {
    await expect(setRating(7, 4)).rejects.toBeInstanceOf(ShowError);
    expect(await getRating(7)).toBeNull();
    expect(await listRatings()).toEqual([]);
});

test('re-rating replaces the rating', async () => {
    await setWatched(7, true);
    await setRating(7, 4);
    await setRating(7, 2);
    expect(await getRating(7)).toBe(2);
    const all = await listRatings();
    expect(all).toHaveLength(1);
    expect(all[0]).toMatchObject({ showId: 7, value: 2 });
    expect(typeof all[0].ratedAt).toBe('number');
});

test('unwatching removes the rating too', async () => {
    await setWatched(7, true);
    await setRating(7, 4);
    await setWatched(7, false);
    expect(await isWatched(7)).toBe(false);
    expect(await getRating(7)).toBeNull();
});

test('invalid ids and values reject with ShowError', async () => {
    await expect(setWatched(0, true)).rejects.toBeInstanceOf(ShowError);
    await expect(setWatched(1.5, true)).rejects.toBeInstanceOf(ShowError);
    await expect(setWatched(Number('7x'), true)).rejects.toBeInstanceOf(ShowError);
    await setWatched(7, true);
    await expect(setRating(7, 6 as RatingValue)).rejects.toBeInstanceOf(ShowError);
    await expect(setRating(7, 0 as RatingValue)).rejects.toBeInstanceOf(ShowError);
    expect(await getRating(7)).toBeNull();
    expect(new ShowError('x').name).toBe('ShowError');
});

test('none of these create an identity', async () => {
    await setWatched(7, true);
    await setRating(7, 3);
    await setWatched(7, false);
    await listWatched();
    await listRatings();
    const db = await getDb();
    expect(await db.count('identity')).toBe(0);
});
