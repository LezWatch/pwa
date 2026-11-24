import 'fake-indexeddb/auto';
import { IDBFactory } from 'fake-indexeddb';
import { beforeEach } from 'vitest';

// Every test starts with an empty browser.
beforeEach(() => {
    globalThis.indexedDB = new IDBFactory();
});
