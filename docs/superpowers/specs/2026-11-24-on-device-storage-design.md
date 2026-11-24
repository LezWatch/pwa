# On-device storage (#13)

## Intent

The filter app's promise is that a person's filters, watched list and ratings
never leave their phone, except a rating and an anonymous key when they choose
to rate. So the phone is the only place that data lives, and there is no
server copy to fall back on.

iOS Safari can delete a site's storage after about seven days without use
unless the app is installed to the home screen. This layer has to hold
everything personal, make that deletion less likely, give people an encrypted
backup they control, and recover gracefully when the data is gone anyway. It
also has to let someone destroy everything at once, even with the app open in
another tab.

Every screen (#3 to #12) reads and writes through this layer, so it comes
first. It is the first real JavaScript in the repo.

Sources: #13, the spec and review notes in the closed #1, #12, and the PWA
decision on #14.

## Goals

- One local store for country, services, tropes, safety filters, star
  settings, the watched list, ratings and the anonymous key.
- Ask for persistent storage, and expose whether the app is installed so
  screens can prompt "Add to Home Screen".
- A passphrase-encrypted backup that round-trips the whole store, including
  the key, so a second device keeps the same voter identity.
- A wrong passphrase, or a damaged file, fails with no change to local data.
- A panic wipe that succeeds with another tab open, and can't stall.
- A documented rule for when the anonymous key is made and how it's kept.

## Non-goals

- Signing ratings, the outbox, enrollment and sending to the server. Those are
  the phone's side of #15 and #17, built with them. #13 records ratings on the
  phone only.
- The screens themselves (setup, settings, install prompt). #13 gives them the
  functions and signals; #3 to #12 draw them.
- The catalogue cache and the service worker. The catalogue is public data and
  gets its own store with the screens that use it.
- Picking a UI framework. The store is plain TypeScript and works with any.
- Asking the server for the user's server-side rating deletion (#19). The
  wipe's ordering with that call is noted below for #12 to build.

## Anti-goals (a failure even if it works)

- Any personal data sent to the server, or written anywhere but this store.
- A plaintext backup, or any way to export without a passphrase.
- An import that leaves half-written data after a failure.
- A wipe that reports success while data is still readable.
- Detecting "your data was deleted" by keeping a marker anywhere outside this
  store (a cookie, a server record). That marker would itself be tracking.
- Making the anonymous key before the person rates anything.

## Constraints

- **iOS 17 and later only** (decided 2026-11-24). Ed25519 is in Web Crypto from
  Safari 17.0. No P-256 fallback. Older iOS can still filter but can't rate;
  the key functions report "unsupported" rather than throw.
- WebKit bug 262499 (open): Safari's Ed25519 signatures differ from the
  standard test vectors but still verify. Any test vectors shared with the
  server (#15) check that a signature verifies, never that its bytes match.
- Node 22 (`.nvmrc`). Tests run on the host, never in the app container.
- No third-party scripts at runtime. Dependencies are bundled by Vite and
  served from this origin.
- The private key must be extractable so it can go in the backup. Any script on
  this origin can read it, which is why the strict CSP in #1's review must ship
  before the key is first used (#15). Not part of #13.

## Approaches considered

1. **Raw IndexedDB, wrapped by hand.** No dependency and full control, but about
   150 lines of callback boilerplate, which is where #1's reference code had
   its bugs.
2. **`idb`** (Jake Archibald, about 1 KB). Promises and types over IndexedDB
   without hiding it, plus `blocked` and `blocking` hooks the wipe needs.
3. **Dexie** (about 25 KB). Live queries, migrations and a query API this data
   doesn't need, and more library code running beside the private key.

**Picked: `idb`** (Mika, 2026-11-24). Small enough to read, close enough to
IndexedDB that the platform docs still describe what the code does, and the
data on disk is plain IndexedDB, so it could be dropped later without
converting anything.

Also decided in conversation:
- **Ed25519 only, iOS 17+** (above).
- **The watched list is a set of shows.** C1 has one "Mark as watched" toggle
  and C2 needs it on to rate. #1's four statuses and free-text `notes` are
  dropped.
- **Meilisearch is for title search only, later.** The catalogue stays on the
  phone and filtering stays on the phone. Nothing in #13 talks to it (see
  "Later, not now").

## Design

### Tooling

- TypeScript, compiled by Vite (no separate build step). Add a `tsconfig.json`
  with `strict` on.
- Code lives in `resources/js/store/`. Screens import from
  `resources/js/store/index.ts` only.
- Tests: Vitest with `fake-indexeddb`, in `resources/js/store/*.test.ts`. Node
  22's Web Crypto provides PBKDF2, AES-GCM and Ed25519.
- Add `npx vitest run` to CI next to `npm ci`, and to the `lwtv-release`
  skill's checks.

### Database

One IndexedDB database, `lwtv`, version 1, so the wipe is one
`deleteDatabase`. Four object stores:

| Store | Key | Record |
| --- | --- | --- |
| `settings` | `"current"` | The `Settings` object below, one record. |
| `watched` | `showId` | `{ showId: number, watchedAt: number }` |
| `ratings` | `showId` | `{ showId: number, value: 1-5, ratedAt: number }` |
| `identity` | `"primary"` | `{ id, alg: "Ed25519", privateKey, publicKey, publicKeyRaw, createdAt }` |

Times are milliseconds since the epoch. `privateKey` and `publicKey` are
`CryptoKey` objects stored directly (IndexedDB clones them).
`publicKeyRaw` is the 32-byte public key as base64url, for display and for
#15.

`Settings`:

```ts
interface Settings {
  schema: 1;
  setupComplete: boolean;          // false until guided setup finishes
  region: string | null;           // ISO 3166-1 alpha-2, e.g. "US"
  services: string[] | 'all';      // availability `services` slugs
  tropes: Record<number, 'include' | 'exclude'>; // term id -> choice; absent = Any
  hideQueerDeath: boolean;         // default true
  triggers: 'show-all' | 'hide-high' | 'hide-med-high' | 'hide-any'; // default 'show-all'
  starsOnly: boolean;              // default false
  starTiers: Array<'gold' | 'silver' | 'bronze'>; // default all three
  showAnti: boolean;               // default false
}
```

Tropes are keyed by WordPress term id, not slug, because ids survive a
rename. A stored id that's no longer in the catalogue is ignored by the
filter and left in place (it may come back).

Defaults come from one `defaultSettings()` function, so "Reset all filters"
(#8) and a fresh install agree.

Ratings require the show to be in `watched` (C2). Unmarking a show as watched
deletes its local rating too. What that means for the server copy is #15's to
decide.

### Module API (`resources/js/store/index.ts`)

```ts
getSettings(): Promise<Settings>              // defaults if none saved
saveSettings(patch: Partial<Settings>): Promise<Settings>
resetFilters(): Promise<Settings>             // filters back to defaults; region, services, setupComplete kept

isWatched(showId): Promise<boolean>
setWatched(showId, watched: boolean): Promise<void>   // false also removes the rating
listWatched(): Promise<number[]>

getRating(showId): Promise<1|2|3|4|5 | null>
setRating(showId, value): Promise<void>       // throws if not watched
listRatings(): Promise<Rating[]>

getIdentity(): Promise<Identity | null>       // never creates
getOrCreateIdentity(): Promise<Identity>      // the only place a key is made
keySupport(): Promise<'ok' | 'unsupported'>   // probes Ed25519 once

storageStatus(): Promise<{ persisted: boolean; installed: boolean; isEmpty: boolean }>
requestPersistence(): Promise<boolean>

exportBackup(passphrase): Promise<Blob>
importBackup(file: Blob, passphrase, opts?: { allowKeyChange?: boolean }): Promise<void>  // all or nothing

wipeEverything(): Promise<void>
onWiped(callback): () => void                 // another tab wiped
```

Screens never open the database themselves.

### The anonymous key

- Made by `getOrCreateIdentity()`, called only when a person saves their
  first rating. Opening the app, finishing setup or marking a show watched
  never makes one. People who never rate never get a pseudonym.
- Ed25519, `extractable: true`, usages `sign` and `verify`.
- Kept in `identity` until the wipe. Never regenerated while one exists: a
  new key would look like a new voter.
- Restored from a backup, it replaces any key on this device (see Import).
- `keySupport()` runs `generateKey` once and caches the answer for the
  session. On "unsupported", the rate sheet explains rating needs iOS 17.

### Persistence and installation

- `requestPersistence()` calls `navigator.storage.persist()` if it exists and
  returns the result. Call it when setup finishes and again at the first
  rating. Safari tends to grant it to installed apps; asking costs nothing.
- `installed` is true when `matchMedia('(display-mode: standalone)')` matches
  or `navigator.standalone` is true. iOS has no install event, so the prompt
  (#7 or #12) shows instructions: Share, then Add to Home Screen.
- **Empty storage:** the app can't tell a first visit from a deleted store,
  and won't keep an outside marker to find out (anti-goals). So it doesn't
  try. `isEmpty` is true when there's no settings record. Every time setup
  starts from empty, step 1 (A1) shows a line with a link: "Set this up
  before? Import a backup." That covers both cases with one path.

### Backup file

The file is JSON, named `lwtv-backup-YYYY-MM-DD.json`:

```json
{
  "format": "lwtv-backup",
  "version": 1,
  "kdf": { "name": "PBKDF2", "hash": "SHA-256", "iterations": 600000, "salt": "<base64url, 16 bytes>" },
  "cipher": { "name": "AES-GCM", "iv": "<base64url, 12 bytes>" },
  "data": "<base64url ciphertext>"
}
```

- A fresh random salt and IV for every export.
- The key is PBKDF2-SHA-256 at 600,000 iterations (OWASP's current figure),
  deriving a 256-bit AES-GCM key.
- Everything except `data` is passed to AES-GCM as additional authenticated
  data, as canonical JSON (the object above with `data` removed, keys in the
  order shown), so editing the header breaks decryption.
- The plaintext inside is:

  ```json
  {
    "schema": 1,
    "exportedAt": 1795291200000,
    "settings": { },
    "watched": [ ],
    "ratings": [ ],
    "identity": { "alg": "Ed25519", "privateJwk": { }, "publicJwk": { }, "createdAt": 0 }
  }
  ```

  `identity` is `null` when no key exists yet.
- Passphrase: at least 8 characters, checked before export. Nothing is stored
  that would help recover it, and the Settings copy (#12) says so.

### Import

1. Parse and check the header: `format`, a known `version`, the `kdf` and
   `cipher` names, and an iteration count between 100,000 and 10,000,000 (so
   a hostile file can't hang the phone).
2. Derive the key and decrypt. A GCM failure means "wrong passphrase or
   damaged file" (they can't be told apart) and stops here.
3. Validate the plaintext fully: every field's type and range, show ids are
   positive integers, rating values 1 to 5, every rated show is watched.
   Import the JWKs to check they're a working Ed25519 pair.
4. Only then, one `readwrite` transaction over all four stores: clear each,
   write the backup's records. If anything fails, the transaction aborts and
   the old data stays.

5. After the commit, post `{ type: 'replaced' }` on the same
   BroadcastChannel. Other tabs reload, so none keeps working from the old
   data or writes it back (my call; it reuses the wipe's channel).

**When the import would replace this device's key** (decided by Mika,
2026-11-24: warn, then replace). If the device has a key, and the backup holds
a different key or none, the import needs a second confirmation before step 4.
`importBackup` takes an `{ allowKeyChange: boolean }` option, and without it
fails with `BackupError` code `key-change-needs-confirmation`, after the
decrypt and validation but before anything is written. The screen then
explains the consequences and retries with `allowKeyChange: true`:

> This backup has a different anonymous key. Ratings this phone already sent
> will stay on our server, and you won't be able to delete them. If this phone
> and the backup rated the same show, both ratings count.

The matching keys case, which covers restoring onto a wiped or new phone,
needs no extra step. The warning is written for after #15. Until ratings are
sent, nothing is actually left behind, but the rule is the same.

Import **replaces** what's on the device; it doesn't merge. The screen asks
first when the device has data. Merging two watched lists is easy, but merging
two keys isn't possible, and a replace is the only result that's easy to
explain.

Errors are typed (`BackupError` with `code`: `not-a-backup`,
`unsupported-version`, `wrong-passphrase-or-damaged`, `invalid-contents`,
`key-change-needs-confirmation`) so screens can word them.

### Panic wipe

IndexedDB queues opens and deletes for the same database name in one line.
Once a delete is waiting, a new open waits behind it. So the wipe clears the
data on the connection it already has, before asking for the delete:

1. On this tab's open connection, `clear()` all four stores in one
   `readwrite` transaction and wait for it to commit. That needs no version
   change and can't be blocked by another tab. **When it commits, the data is
   gone.**
2. Post `{ type: 'wipe' }` on a `BroadcastChannel('lwtv-store')`.
3. Close this tab's connection and call `indexedDB.deleteDatabase('lwtv')`.
4. Every connection opens with `idb`'s `blocking` hook set to close itself.
   Other tabs release the database when the delete asks, their `onWiped`
   listeners run, and they reload into the empty state, dropping anything
   they held in memory.
5. Remove anything this app put in `localStorage` and `sessionStorage`
   (nothing planned today, but the wipe owns it).
6. Resolve when step 1 has committed and the delete has either succeeded or
   reported `blocked`. The wipe never waits on another tab.

**What a wipe guarantees** (my call): when it resolves, every store is empty.
The database file itself goes as soon as no other tab holds it. While a
delete is still pending, this tab shows the empty state without opening the
database. Setup keeps its choices in memory, and the first save waits for the
delete. If that wait passes 3 seconds, the screen says "Close other LezWatch
tabs to finish."

A tab frozen in the background could, in theory, write stale data when it
wakes, before it sees the wipe. To guard against that, every write first
checks a `wiped` flag that the BroadcastChannel message and `versionchange`
both set. Once the flag is set, that tab writes nothing more and reloads.

**For #12 to build:** "Delete my data" promises to delete the server's copy of
the ratings too, and that request has to be signed with the key the wipe
destroys. So #12 calls the server delete (#19) first, with a short timeout,
then `wipeEverything()`. This ordering is #12's; #13 only needs the wipe to be
callable after the request.

### Privacy copy

The text #12 and the rate sheet use must say: the public key is a stable
pseudonym that links one device's ratings together, and nothing else
identifies the person. When title search ships, add that search text goes
to the server.

## Testing

- Store functions against `fake-indexeddb`, a fresh database per test.
- Backup round-trip, including the key: export, wipe, import, and the
  restored key signs something the original public key verifies.
- Wrong passphrase, flipped header byte, truncated `data`, bad JSON, and a
  valid-decrypting file with bad contents: each fails with its error code
  and leaves the earlier data unchanged.
- Wipe with a second open connection: the second gets `blocking`, closes,
  and the delete finishes. Wipe with a second connection that never closes:
  `wipeEverything()` still resolves, every store reads empty from the stuck
  connection, and that connection's writes are refused once its `wiped`
  flag is set.
- Check once, before building on it, that the pinned `fake-indexeddb` (6.x)
  can store a Node 22 `CryptoKey`. Older versions used a JS clone that
  throws on `CryptoKey`.
- `getOrCreateIdentity()` twice returns the same key; `setWatched` and
  `saveSettings` never create one.
- `setRating` on an unwatched show throws; unwatching removes the rating.
- Import onto a device with a different key, or a backup with no key, fails
  with `key-change-needs-confirmation` and changes nothing; with
  `allowKeyChange: true` it replaces. Same key: no confirmation needed.
- A manual check on a real iPhone with iOS 17 and later: the round-trip and
  the wipe with two tabs. fake-indexeddb can't prove Safari's behaviour.

## Left to the builder

- File layout inside `resources/js/store/`, and internal helper names.
- Whether `exportBackup` returns a `Blob` or a string, as long as the screen
  can download it with that file name.
- Exact Vitest config, and whether the JS checks get their own CI job or join
  an existing one.
- How the `keySupport()` probe caches its answer.

## Later, not now

- **Meilisearch (title search).** Send queries as POST, never put search text
  in a URL (nginx logs URLs), never send filters with a query, and add search
  to the privacy copy.
- **Strict CSP and no third-party scripts** before the key is first used
  (#15).
- **Catalogue cache and service worker**, with the screens.
