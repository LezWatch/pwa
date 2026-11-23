:root{--bg:#fff;--fg:#1d1d1f;--muted:#5f5f66;--line:#e2e2e8;--accent:#6c3a99;--ok:#1f7a45;--bad:#b3261e;--warn:#8a5a00;--radius:8px;font:16px/1.5 system-ui,sans-serif}
body{margin:0;background:var(--bg);color:var(--fg)}
.bar{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.75rem 1rem;border-bottom:1px solid var(--line)}
.bar form{display:flex;gap:.75rem;align-items:center;margin:0}
main{max-width:64rem;margin:0 auto;padding:1rem}
.muted{color:var(--muted)}
.btn{display:inline-block;border:1px solid var(--accent);background:var(--accent);color:#fff;border-radius:var(--radius);padding:.5rem 1rem;font:inherit;cursor:pointer;text-decoration:none}
.btn-quiet{background:transparent;color:var(--accent)}
.alert{border:1px solid var(--line);border-radius:var(--radius);padding:.75rem 1rem;margin:1rem 0}
.alert-bad{border-color:var(--bad);color:var(--bad)}
.alert-ok{border-color:var(--ok);color:var(--ok)}
