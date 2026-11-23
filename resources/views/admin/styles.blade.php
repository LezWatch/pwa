{{-- Role tokens copied from docs/design/system/tokens.css. No webfonts: the admin stays light. --}}
:root{--surface:#fff;--surface-subtle:#fbfafb;--surface-border:#ece9eb;--ink:#333;--ink-muted:#666;--link:#cb3e85;--link-hover:#8d329f;--pink:#cb3e85;--pink-deep:#9e2968;--ok-bg:#d4edda;--ok-edge:#047a49;--bad-bg:#f8d7da;--bad-edge:#c0392b;--warn-bg:#fff3cd;--warn-edge:#b45309;--radius-sm:.25rem;--font-sans:"Open Sans",system-ui,sans-serif;--font-display:"Oswald",system-ui,sans-serif;color-scheme:light dark}
@media (prefers-color-scheme:dark){:root{--surface:#333;--surface-subtle:#404040;--surface-border:#6a5d5d;--ink:#f3f3f3;--ink-muted:#dedede;--link:#eecee3;--link-hover:#e0d2fe;--ok-bg:#404040;--ok-edge:#2ecc71;--bad-bg:#404040;--bad-edge:#e74c3c;--warn-bg:#404040;--warn-edge:#f1c40f}}
body{margin:0;background:var(--surface);color:var(--ink);font:400 1rem/1.5 var(--font-sans)}
h1,h2{font-family:var(--font-display);font-weight:400;line-height:1.3}
a{color:var(--link)}a:hover,a:focus{color:var(--link-hover)}
.bar{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.5rem 1rem;padding:.75rem 1rem;border-bottom:1px solid var(--surface-border);background:var(--surface-subtle)}
.bar form{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin:0}
main{max-width:64rem;margin:0 auto;padding:1rem}
.muted{color:var(--ink-muted)}
.btn{display:inline-block;border:1px solid var(--pink);background:var(--pink);color:#fff;border-radius:var(--radius-sm);padding:.5rem 1rem;font:inherit;cursor:pointer;text-decoration:none}
.btn:hover,.btn:focus{background:var(--pink-deep);border-color:var(--pink-deep);color:#fff}
.btn-quiet{background:transparent;color:var(--link)}
.btn-quiet:hover,.btn-quiet:focus{background:transparent;color:var(--link-hover)}
.alert{border:1px solid var(--surface-border);border-left-width:4px;border-radius:var(--radius-sm);padding:.75rem 1rem;margin:1rem 0}
.alert-ok{background:var(--ok-bg);border-color:var(--ok-edge)}
.alert-bad{background:var(--bad-bg);border-color:var(--bad-edge)}
.actions{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;margin:1rem 0}
.actions form{margin:0}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:1rem;margin:1rem 0}
.summary div{background:var(--surface-subtle);border:1px solid var(--surface-border);border-radius:var(--radius-sm);padding:.75rem 1rem}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;vertical-align:top;padding:.5rem;border-bottom:1px solid var(--surface-border)}
th{font-weight:600}
.num{text-align:right;font-variant-numeric:tabular-nums}
.badge{display:inline-block;border:1px solid;border-radius:999px;padding:0 .5rem;font-size:.875rem;white-space:nowrap}
.badge-succeeded{background:var(--ok-bg);border-color:var(--ok-edge)}
.badge-failed{background:var(--bad-bg);border-color:var(--bad-edge)}
.badge-running,.badge-stalled{background:var(--warn-bg);border-color:var(--warn-edge)}
.error{overflow-wrap:anywhere}
