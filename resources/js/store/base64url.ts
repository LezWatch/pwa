export function toBase64Url(bytes: ArrayBuffer | Uint8Array): string {
    const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
    let binary = '';
    for (const b of view) binary += String.fromCharCode(b);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function fromBase64Url(s: string): Uint8Array {
    if (!/^[A-Za-z0-9_-]*$/.test(s) || s.length % 4 === 1) {
        throw new Error('Invalid base64url.');
    }
    const padded = s.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (s.length % 4)) % 4);
    const binary = atob(padded);
    const out = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) out[i] = binary.charCodeAt(i);
    return out;
}
