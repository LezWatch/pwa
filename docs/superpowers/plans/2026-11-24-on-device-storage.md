# On-device Storage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the phone-side store for #13: settings, watched list, ratings and the anonymous key in IndexedDB, plus persistence signals, a passphrase-encrypted backup, and a panic wipe that works with other tabs open.

**Architecture:** A framework-free TypeScript module in `resources/js/store/`. Screens import only from `index.ts`. One IndexedDB database (`lwtv`, version 1) with four object stores is opened through `idb`. Crypto is Web Crypto only. Tabs coordinate over `BroadcastChannel('lwtv-store')` and IndexedDB's `versionchange`.

**Tech Stack:** TypeScript 7, Vite 8, `idb` 8, Vitest 5, `fake-indexeddb` 6, Node 22 Web Crypto.

**Spec:** `docs/superpowers/specs/2026-11-24-on-device-storage-design.md`. Read it before starting. It carries the reasons this plan doesn't repeat.

## Global Constraints

- iOS 17 and later. Ed25519 only; no P-256 fallback. Older browsers get `'unsupported'`, never a thrown error at load.
- Nothing personal leaves the phone. No `fetch` anywhere in `resources/js/store/`.
- No outside marker of past use: no cookies, `localStorage` flags or server calls to detect "data was deleted".
- The key is made only by `getOrCreateIdentity()`. No other function may create one.
- No plaintext export. Backups are PBKDF2-SHA-256 at 600,000 iterations, a 16-byte salt and a 12-byte IV, then AES-GCM-256, with the header as additional data.
- Import is all or nothing: one `readwrite` transaction over all four stores, after full validation.
- Tests run on the host (`npm test`), never in the app container.
- Dependencies are bundled by Vite and served from this origin. No CDN scripts.
- New npm packages make the PR high risk in the gate. That's expected: Mika reviews it.
- Work on branch `claude/on-device-storage`, cut from local `release/0.0.4`. Commit messages are Conventional Commits.

## Review Focus

1. **A passphrase typed on two different keyboards.** "café" can come out as precomposed é on one device and e plus a combining accent on another. The same passphrase must open the backup on both, so normalize to NFC before encoding (Task 5).
2. **Two quick settings saves from a screen,** e.g. toggling two tropes fast. Both changes must survive. Read-modify-write happens inside one transaction (Task 2).
3. **A screen bug saving a bad value** (`triggers: 'hide-low'`, a region of `'usa'`). It's rejected with an error, not stored, because a stored bad value would quietly change what the filter hides (Task 2).
4. **A settings record from an older build, missing a field.** It loads with that field defaulted, rather than breaking every screen (Task 2).
5. **Picking the wrong file to import,** such as a 40 MB photo or a text file. It's refused fast with `not-a-backup`, before any parsing or PBKDF2 work (Task 6).

---

### Task 1: Tooling and the CryptoKey check

**Files:**
- Create: `tsconfig.json`, `vitest.config.ts`, `resources/js/store/test-setup.ts`, `resources/js/store/tooling.test.ts`
- Modify: `package.json`, `.github/workflows/ci.yml` (after the `npm ci` step), `.claude/skills/lwtv-release/SKILL.md` (§2 Check), `README.md`
- Also commit: `docs/superpowers/specs/2026-11-24-on-device-storage-design.md`, this plan

**Interfaces:**
- Produces: `npm test` (`vitest run`) and `npm run typecheck` (`tsc --noEmit`). `test-setup.ts` imports `fake-indexeddb/auto` and, in `beforeEach`, sets `globalThis.indexedDB = new IDBFactory()` so every test starts with an empty browser.

- [ ] **Step 1:** `git switch -c claude/on-device-storage release/0.0.4`, then `npm install idb@^8 && npm install -D typescript@^7 vitest@^5 fake-indexeddb@^6`.
- [ ] **Step 2:** Add `"test": "vitest run"` and `"typecheck": "tsc --noEmit"` to `package.json`. `tsconfig.json`: `strict`, `target`/`lib` ES2022 plus DOM, `module: ESNext`, `moduleResolution: bundler`, `noEmit`, include `resources/js/**/*.ts`. `vitest.config.ts` is separate from `vite.config.js`, so the Laravel plugin stays out of tests: environment `node`, include `resources/js/**/*.test.ts`, `setupFiles: ['resources/js/store/test-setup.ts']`.
- [ ] **Step 3: Write `tooling.test.ts`.** This is the check the spec asks for before building on it.

```ts
test('fake-indexeddb stores and returns a usable Ed25519 CryptoKey', async () => {
  const pair = await crypto.subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify']) as CryptoKeyPair;
  const db = await openDB('t', 1, { upgrade: d => d.createObjectStore('k') });
  await db.put('k', pair, 'p');
  const back = await db.get('k', 'p') as CryptoKeyPair;
  const msg = new TextEncoder().encode('x');
  const sig = await crypto.subtle.sign('Ed25519', back.privateKey, msg);
  expect(await crypto.subtle.verify('Ed25519', pair.publicKey, sig, msg)).toBe(true);
});
```

- [ ] **Step 4:** Run `npm test && npm run typecheck`. Expected: 1 passed, no type errors. **If the clone throws,** stop and report. The fallback is storing the JWK and re-importing on read, and Mika should hear about it before it's chosen.
- [ ] **Step 5:** CI: add `- run: npm run typecheck` and `- run: npm test` right after `npm ci`. Release skill §2: add the same two commands to the check list, and name them in the PR Notes sentence. README: one short "Front-end tests" paragraph saying to run `npm test` on the host.
- [ ] **Step 6:** Commit: `chore: add TypeScript and Vitest for the on-device store` (include the spec and plan).

### Task 2: Database and settings

**Files:**
- Create: `resources/js/store/db.ts`, `resources/js/store/settings.ts`, `resources/js/store/index.ts`, `resources/js/store/settings.test.ts`

**Interfaces:**
- Produces, in `db.ts`:
  - `DB_NAME = 'lwtv'`, `DB_VERSION = 1`, `STORES = ['settings', 'watched', 'ratings', 'identity'] as const`;
  - `getDb(): Promise<IDBPDatabase<LwtvSchema>>`, which caches one connection;
  - `closeDb(): void`;
  - `assertWritable(): void`, which throws `StoreWipedError` once this tab's `wiped` flag is set;
  - `markWiped(): void`;
  - `whenDeleteSettles(): Promise<void>`, which resolves at once unless a delete is pending (Task 7 sets it);
  - `LwtvSchema` (an `idb` `DBSchema`) with the record types from the spec.

  `getDb()` opens with `blocking: () => { markWiped(); closeDb(); }` and creates all four stores in `upgrade`. Every exported write calls `assertWritable()` and then `await whenDeleteSettles()` before `getDb()`.
- Produces, in `settings.ts`: `Settings` (exactly the spec's interface), `defaultSettings(): Settings`, `validateSettings(value: unknown): Settings` (throws `SettingsError`), `getSettings(): Promise<Settings>`, `saveSettings(patch: Partial<Settings>): Promise<Settings>`, `resetFilters(): Promise<Settings>`.
- `index.ts` re-exports the public API of each task as it lands. Screens import nothing else.

- [ ] **Step 1: Write `settings.test.ts`:**
  - `getSettings` on an empty database equals `defaultSettings()`, which has `hideQueerDeath: true`, `triggers: 'show-all'`, `starsOnly: false`, `starTiers: ['gold','silver','bronze']`, `showAnti: false`, `setupComplete: false`, `region: null`, `services: 'all'`, `tropes: {}`.
  - `saveSettings({ region: 'CA' })` then `getSettings()` has `region: 'CA'` and every other field at its default.
  - **(RF2)** `await Promise.all([saveSettings({ tropes: { 101: 'include' } }), saveSettings({ hideQueerDeath: false })])`: both changes are present afterwards.
  - **(RF3)** `saveSettings({ triggers: 'hide-low' })`, `saveSettings({ region: 'usa' })` (must match `/^[A-Z]{2}$/`) and `saveSettings({ tropes: { 101: 'maybe' } })` each reject with `SettingsError`, and the stored record is unchanged.
  - **(RF4)** Put a raw record `{ schema: 1, region: 'US' }` into `settings` under `'current'`. `getSettings()` returns `region: 'US'` with every missing field defaulted.
  - `resetFilters()` after setting region, services, tropes, triggers and `setupComplete: true` keeps region, services and `setupComplete`, and puts everything else back to its default.
- [ ] **Step 2:** Run `npm test -- settings`. Expected: FAIL, the modules don't exist yet.
- [ ] **Step 3:** Implement `db.ts` and `settings.ts`. Stored values are merged over `defaultSettings()` and then validated, on read and on write. `saveSettings` does its get, merge, validate and put inside one `readwrite` transaction on `settings`. Validation happens before the transaction opens, on the merged value from a read. If validation fails, nothing is put.
- [ ] **Step 4:** Run `npm test && npm run typecheck`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(store): add the database and settings`.

### Task 3: Watched list and ratings

**Files:**
- Create: `resources/js/store/shows.ts`, `resources/js/store/shows.test.ts`
- Modify: `resources/js/store/index.ts`

**Interfaces:**
- Consumes: `getDb`, `assertWritable`, `whenDeleteSettles` (Task 2).
- Produces: `RatingValue = 1|2|3|4|5`, `Rating = { showId: number; value: RatingValue; ratedAt: number }`, `isWatched(showId: number): Promise<boolean>`, `setWatched(showId: number, watched: boolean): Promise<void>`, `listWatched(): Promise<number[]>`, `getRating(showId: number): Promise<RatingValue | null>`, `setRating(showId: number, value: RatingValue): Promise<void>`, `listRatings(): Promise<Rating[]>`, `ShowError`.

- [ ] **Step 1: Write `shows.test.ts`:**
  - `setWatched(7, true)`: `isWatched(7)` is true and `listWatched()` is `[7]`.
  - `setRating(7, 4)` on an unwatched show rejects with `ShowError` and writes nothing.
  - Watched, then `setRating(7, 4)`, then `setRating(7, 2)`: `getRating(7)` is 2 and `listRatings()` has one entry (re-rating replaces).
  - `setWatched(7, false)` after a rating: the show isn't watched and `getRating(7)` is null. Both deletes happen in one transaction.
  - `setWatched(0, true)`, `setWatched(1.5, true)`, `setWatched(Number('7x'), true)` and `setRating(7, 6 as RatingValue)` reject with `ShowError`.
  - None of these functions creates an identity: the `identity` store is empty after all of them.
- [ ] **Step 2:** Run `npm test -- shows`. Expected: FAIL.
- [ ] **Step 3:** Implement `shows.ts`. `setRating` checks `watched` and writes `ratings` in the same `readwrite` transaction over both stores.
- [ ] **Step 4:** Run `npm test && npm run typecheck`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(store): add the watched list and ratings`.

### Task 4: The anonymous key, and storage status

**Files:**
- Create: `resources/js/store/identity.ts`, `resources/js/store/status.ts`, `resources/js/store/identity.test.ts`, `resources/js/store/status.test.ts`, `resources/js/store/base64url.ts`
- Modify: `resources/js/store/index.ts`

**Interfaces:**
- Consumes: Task 2's `db.ts`.
- Produces:
  - `toBase64Url(bytes: ArrayBuffer | Uint8Array): string` and `fromBase64Url(s: string): Uint8Array` (throws on bad input);
  - `Identity = { alg: 'Ed25519'; privateKey: CryptoKey; publicKey: CryptoKey; publicKeyRaw: string; createdAt: number }`;
  - `keySupport(): Promise<'ok' | 'unsupported'>`, `getIdentity(): Promise<Identity | null>`, `getOrCreateIdentity(): Promise<Identity>` (throws `KeyUnsupportedError`);
  - `storageStatus(): Promise<{ persisted: boolean; installed: boolean; isEmpty: boolean }>`, `requestPersistence(): Promise<boolean>`.

- [ ] **Step 1: Write `identity.test.ts`:**
  - `getIdentity()` on an empty database is null and creates nothing.
  - `getOrCreateIdentity()` twice gives the same `publicKeyRaw`, which decodes to 32 bytes. A signature from the first call's `privateKey` verifies with the second call's `publicKey`.
  - `await Promise.all([getOrCreateIdentity(), getOrCreateIdentity()])` gives one `publicKeyRaw`, and the store holds one record.
  - With `vi.spyOn(crypto.subtle, 'generateKey').mockRejectedValue(new DOMException('', 'NotSupportedError'))`: `keySupport()` is `'unsupported'` and `getOrCreateIdentity()` rejects with `KeyUnsupportedError`. Reset the probe's cache between tests through an exported `__resetKeySupportForTests()`.
- [ ] **Step 2: Write `status.test.ts`.** Stub `navigator.storage` and `matchMedia` with `vi.stubGlobal`:
  - `isEmpty` is true with no settings record, and false after `saveSettings({})`.
  - `installed` is true when `matchMedia('(display-mode: standalone)').matches`, or when `navigator.standalone === true`. It's false with neither, and false when `matchMedia` is undefined.
  - `requestPersistence()` returns `navigator.storage.persist()`'s answer, and returns false without throwing when `navigator.storage` is undefined. `persisted` comes from `navigator.storage.persisted()`, or false when that's missing.
- [ ] **Step 3:** Run `npm test -- identity status`. Expected: FAIL.
- [ ] **Step 4:** Implement. In `getOrCreateIdentity`, generate the key *outside* any transaction, because awaiting crypto inside one lets IndexedDB auto-commit. Then, in one `readwrite` transaction, put it only if `get('primary')` is empty, and return whichever record is stored. Also keep a module-level in-flight promise, so one tab never generates twice. `keySupport()` generates a throwaway pair once per page and caches the answer.
- [ ] **Step 5:** Run `npm test && npm run typecheck`. Expected: PASS.
- [ ] **Step 6:** Commit: `feat(store): add the anonymous key and storage status`.

### Task 5: Backup file format and export

**Files:**
- Create: `resources/js/store/backup-format.ts`, `resources/js/store/backup.ts`, `resources/js/store/backup-format.test.ts`
- Modify: `resources/js/store/index.ts`

**Interfaces:**
- Consumes: `toBase64Url`/`fromBase64Url`, `getIdentity` (Task 4), `getSettings` (Task 2), `listWatched`/`listRatings` (Task 3).
- Produces:
  - `BackupError extends Error` with `code: 'not-a-backup' | 'unsupported-version' | 'wrong-passphrase-or-damaged' | 'invalid-contents' | 'key-change-needs-confirmation' | 'passphrase-too-short'`;
  - `BackupPlaintext` (the spec's inner JSON; `identity` is `{ alg: 'Ed25519', privateJwk, publicJwk, createdAt } | null`);
  - `encryptBackup(plain: BackupPlaintext, passphrase: string, iterations?: number): Promise<string>`, which returns the file's JSON text;
  - `decryptBackup(text: string, passphrase: string): Promise<unknown>`, which returns the parsed plaintext without validating it;
  - `exportBackup(passphrase: string): Promise<{ blob: Blob; filename: string }>`, where `filename` is `lwtv-backup-YYYY-MM-DD.json` in local time.

- [ ] **Step 1: Write `backup-format.test.ts`.** Use `iterations: 1000` in tests for speed, except one test that reads the default:
  - Round-trip: `decryptBackup(await encryptBackup(p, 'correct horse'), 'correct horse')` deep-equals `p`.
  - The file has `format: 'lwtv-backup'`, `version: 1`, `kdf: { name: 'PBKDF2', hash: 'SHA-256', iterations, salt }`, `cipher: { name: 'AES-GCM', iv }`. The salt decodes to 16 bytes and the IV to 12. Two exports of the same data have different salts, IVs and `data`.
  - The default `iterations` is 600000.
  - Wrong passphrase, one changed character in `data`, `data` cut short, and `kdf.iterations` changed by 1 (proving the header is authenticated) each reject with `wrong-passphrase-or-damaged`.
  - `format` missing, or the text not JSON: `not-a-backup`. `version: 2`: `unsupported-version`. `kdf.iterations` of 99999 or 10000001, or a `kdf.name` other than PBKDF2: `unsupported-version`, thrown before any key derivation (spy on `crypto.subtle.deriveKey` and check it isn't called).
  - **(RF1)** Encrypt with `'café'`. Decrypting with `'café'` succeeds.
  - `exportBackup('short')` (under 8 characters, counted after NFC) rejects with `passphrase-too-short`.
  - `exportBackup` with an identity present puts JWKs in the plaintext whose `x` matches `publicKeyRaw`. With no identity, `identity` is null, and `exportBackup` doesn't create one.
- [ ] **Step 2:** Run `npm test -- backup-format`. Expected: FAIL.
- [ ] **Step 3:** Implement. The additional data is `new TextEncoder().encode(JSON.stringify({ format, version, kdf: { name, hash, iterations, salt }, cipher: { name, iv } }))`, built field by field in that order from the values in the file, never by re-serializing the parsed object. The passphrase is `passphrase.normalize('NFC')`, UTF-8 encoded. Derive with `deriveKey(PBKDF2 → AES-GCM 256, extractable false)`. A failed `decrypt` becomes `wrong-passphrase-or-damaged`.
- [ ] **Step 4:** Run `npm test && npm run typecheck`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(store): add the encrypted backup format and export`.

### Task 6: Import

**Files:**
- Create: `resources/js/store/import.ts`, `resources/js/store/import.test.ts`
- Modify: `resources/js/store/index.ts`, `resources/js/store/db.ts` (the channel)

**Interfaces:**
- Consumes: `decryptBackup`, `BackupError`, `encryptBackup` (Task 5, for test fixtures), `validateSettings` (Task 2), `getIdentity` (Task 4), `getDb` (Task 2).
- Produces:
  - `importBackup(file: Blob, passphrase: string, opts?: { allowKeyChange?: boolean }): Promise<void>`;
  - in `db.ts`: `channel(): BroadcastChannel` (one per module, name `'lwtv-store'`) and the message type `StoreMessage = { type: 'wipe' } | { type: 'replaced' }`;
  - `onReplaced(cb: () => void): () => void`.

- [ ] **Step 1: Write `import.test.ts`.** Each test seeds the device with a setting, a watched show and a rating, and then:
  - **(RF5)** A `Blob` over 5 MB, and a 1 KB blob of non-JSON text, reject with `not-a-backup`, without calling `crypto.subtle.deriveKey`.
  - A wrong passphrase, and a decrypting file whose contents are bad, reject with `wrong-passphrase-or-damaged` and `invalid-contents` respectively. The device's settings, watched list and rating are unchanged. Bad contents include: a rating of 6; a rating for an unwatched show; a `showId` of -1; settings failing `validateSettings`; a `publicJwk` that doesn't match the `privateJwk` (sign with one, verify with the other).
  - A valid backup onto a device with **no key** replaces everything: the device's old watched show is gone and the backup's rows are present.
  - Device key A with a backup holding key B, and device key A with a backup holding no key: both reject with `key-change-needs-confirmation`, and nothing changes. Repeating with `{ allowKeyChange: true }` replaces everything. Afterwards `getIdentity()` gives B's `publicKeyRaw`, or null.
  - A backup with the same key as the device needs no option.
  - After a successful import, a second module instance (`vi.resetModules()`, then `await import('./index')`) has its `onReplaced` callback called once.
- [ ] **Step 2:** Run `npm test -- import`. Expected: FAIL.
- [ ] **Step 3:** Implement the spec's order:
  1. Size check.
  2. `decryptBackup`.
  3. Full validation, including importing the JWKs as extractable and doing a sign/verify pair check. This all happens before any transaction.
  4. The key-change check against `getIdentity()`.
  5. One `readwrite` transaction over all four stores: clear each, then put the backup's rows. The identity is stored as imported `CryptoKey`s plus `publicKeyRaw` from the public JWK's `x`.
  6. After the transaction completes, post `{ type: 'replaced' }`.
- [ ] **Step 4:** Run `npm test && npm run typecheck`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(store): import a backup all or nothing`.

### Task 7: Panic wipe

**Files:**
- Create: `resources/js/store/wipe.ts`, `resources/js/store/wipe.test.ts`
- Modify: `resources/js/store/db.ts`, `resources/js/store/index.ts`

**Interfaces:**
- Consumes: `getDb`, `closeDb`, `markWiped`, `channel` (Tasks 2 and 6).
- Produces: `wipeEverything(): Promise<void>`, `onWiped(cb: () => void): () => void`, `isDeletePending(): boolean`. Also `StoreWipedError`, exported from `index.ts` so screens can catch it.

- [ ] **Step 1: Write `wipe.test.ts`.** Seed all four stores first.
  - With only this tab, `wipeEverything()` resolves. `indexedDB.databases()` no longer lists `lwtv`, and a later `getSettings()` gives defaults with every store empty.
  - **Another tab that lets go:** open tab B (a second module instance via `vi.resetModules()`) and have it read settings, so it holds a connection. Then call `wipeEverything()` on tab A. B's `onWiped` runs once. B's next `saveSettings({})` rejects with `StoreWipedError`. The database is deleted.
  - **A stuck connection:** open a raw `openDB('lwtv', 1)` with no `blocking` handler. `wipeEverything()` still resolves within 1 second. Reading all four stores through the raw connection gives empty arrays. `isDeletePending()` is true. After the raw connection closes, the delete finishes and `isDeletePending()` becomes false.
  - While a delete is pending, `getSettings()` gives defaults without waiting. `saveSettings({ region: 'CA' })` stays pending until the raw connection closes, then resolves, and the value is in the new database.
  - Wiping when a `localStorage` stub holds a `lwtv:`-prefixed key removes that key and leaves others.
- [ ] **Step 2:** Run `npm test -- wipe`. Expected: FAIL.
- [ ] **Step 3:** Implement, in this order:
  1. Clear all four stores in one transaction on this tab's connection, and wait for it to complete.
  2. Post `{ type: 'wipe' }`.
  3. `closeDb()`.
  4. Call `indexedDB.deleteDatabase(DB_NAME)` directly, not through `idb`'s `deleteDB`, so you hold the request and can see `onblocked`.
  5. Set the pending promise that `whenDeleteSettles()` returns.
  6. Remove `lwtv:`-prefixed keys from both web storages.
  7. Resolve on `onsuccess` or `onblocked`, whichever comes first.

  Incoming `wipe` messages and the `blocking` hook both call `markWiped()`, `closeDb()` and the `onWiped` callbacks. While a delete is pending, reads return defaults or empty lists without opening.
- [ ] **Step 4:** Run `npm test && npm run typecheck`. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(store): add the panic wipe`.

### Task 8: Final check

- [ ] **Step 1:** Run `npm test && npm run typecheck && npm run build:assets && php artisan test`. Expected: all pass.
- [ ] **Step 2:** `grep -rn "fetch\|XMLHttpRequest\|document.cookie" resources/js/store/` returns nothing.
- [ ] **Step 3:** Check that `index.ts` exports exactly the spec's module API, plus `isDeletePending`, `onReplaced`, the error classes and the types. Nothing internal (`getDb`, `__reset…`) is exported.
- [ ] **Step 4:** Write the manual iPhone checklist into the PR body. It has to be run on an iPhone with iOS 17 or later before #13 closes:
  - export, then wipe, then import, with the same `publicKeyRaw` afterwards;
  - wipe with the app open in two tabs;
  - a wrong passphrase leaves the data unchanged.
