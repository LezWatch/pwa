// The only module screens import from.
export {
    getSettings,
    saveSettings,
    resetFilters,
    defaultSettings,
    validateSettings,
    SettingsError,
    type Settings,
} from './settings';
export { StoreWipedError, onReplaced } from './db';
export {
    isWatched,
    setWatched,
    listWatched,
    getRating,
    setRating,
    listRatings,
    ShowError,
    type RatingValue,
    type Rating,
} from './shows';
export {
    keySupport,
    getIdentity,
    getOrCreateIdentity,
    KeyUnsupportedError,
    type Identity,
} from './identity';
export { storageStatus, requestPersistence, type StorageStatus } from './status';
export { exportBackup } from './backup';
export { BackupError, type BackupPlaintext } from './backup-format';
export { importBackup } from './import';
