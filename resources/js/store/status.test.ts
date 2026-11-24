import { test, expect, vi, beforeEach, afterEach } from 'vitest';
import { closeDb, __resetForTests } from './db';
import { saveSettings } from './settings';
import { storageStatus, requestPersistence } from './status';

beforeEach(() => {
    closeDb();
    __resetForTests();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

test('isEmpty is true with no settings record and false after saveSettings', async () => {
    expect((await storageStatus()).isEmpty).toBe(true);
    await saveSettings({});
    expect((await storageStatus()).isEmpty).toBe(false);
});

test('installed follows display-mode standalone', async () => {
    vi.stubGlobal('matchMedia', (q: string) => ({ matches: q === '(display-mode: standalone)' }));
    expect((await storageStatus()).installed).toBe(true);
});

test('installed follows navigator.standalone', async () => {
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    vi.stubGlobal('navigator', { standalone: true });
    expect((await storageStatus()).installed).toBe(true);
});

test('installed is false with neither, and when matchMedia is undefined', async () => {
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    vi.stubGlobal('navigator', { standalone: false });
    expect((await storageStatus()).installed).toBe(false);
    vi.stubGlobal('matchMedia', undefined);
    expect((await storageStatus()).installed).toBe(false);
});

test('persisted comes from navigator.storage.persisted, or false when missing', async () => {
    vi.stubGlobal('navigator', { storage: { persisted: async () => true } });
    expect((await storageStatus()).persisted).toBe(true);
    vi.stubGlobal('navigator', { storage: {} });
    expect((await storageStatus()).persisted).toBe(false);
    vi.stubGlobal('navigator', {});
    expect((await storageStatus()).persisted).toBe(false);
});

test('requestPersistence returns persist()s answer, false when storage is missing', async () => {
    vi.stubGlobal('navigator', { storage: { persist: async () => true } });
    expect(await requestPersistence()).toBe(true);
    vi.stubGlobal('navigator', { storage: { persist: async () => false } });
    expect(await requestPersistence()).toBe(false);
    vi.stubGlobal('navigator', {});
    expect(await requestPersistence()).toBe(false);
});
