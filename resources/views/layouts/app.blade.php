<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title ?? 'Developer Training Platform' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @isset($metaDescription)<meta name="description" content="{{ $metaDescription }}">@endisset
    {{-- Pre-Tailwind layout styles sit in the `legacy` layer so Tailwind utilities override them on converted views. --}}
    <style>
        @layer legacy {
        :root{--line:#293052;--muted:#9da7c6;--p:#8b5cf6;--c:#22d3ee;--danger:#fb7185}*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,sans-serif;background:#080b18;color:#eef2ff;min-height:100vh}a{color:inherit;text-decoration:none}.wrap{max-width:1120px;margin:auto;padding:24px 22px}.nav{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:36px}.nav-links{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.brand{font-weight:900;letter-spacing:.08em}.badge,.pill{border:1px solid var(--line);padding:7px 11px;border-radius:999px;color:var(--muted);font-size:12px}.nav-sync-form{display:inline-flex;margin:0}.sync-nav-action{white-space:nowrap;color:#eef2ff;border-color:#5b45a8;background:#281d56;cursor:pointer;font-weight:800}.sync-nav-action:hover{border-color:var(--p);color:#fff}.sync-nav-action[disabled]{opacity:.7;cursor:default}@media(max-width:600px){.nav{align-items:flex-start}.nav-links{width:100%}.sync-nav-action{font-size:11px;padding:7px 9px}}.hero{padding:30px 0}.eyebrow,.num{color:var(--c);font-weight:900;letter-spacing:.1em;font-size:13px}h1{font-size:clamp(42px,7vw,76px);line-height:.95;margin:14px 0;background:linear-gradient(90deg,#fff,#a78bfa,#22d3ee);-webkit-background-clip:text;color:transparent}.muted{color:var(--muted);line-height:1.6}.card{background:#11162b;border:1px solid var(--line);border-radius:22px;padding:26px}[class="grid"]{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}@media(max-width:800px){[class="grid"]{grid-template-columns:1fr}}.form{display:grid;gap:18px}.field label{display:block;font-weight:800;margin-bottom:8px}.field input,.field select,.field textarea,.field input[type=file]{width:100%;background:#0a0f21;color:#fff;border:1px solid var(--line);border-radius:12px;padding:13px;font:inherit;min-height:46px}.field textarea{min-height:110px}.field input:focus,.field select:focus,.field textarea:focus{outline:3px solid #22d3ee55;border-color:var(--c)}input[type=checkbox]{accent-color:var(--p);width:18px;height:18px}.btn{display:inline-block;border:0;border-radius:12px;padding:13px 18px;font-weight:900;cursor:pointer;background:linear-gradient(90deg,var(--p),#4f46e5);color:#fff}.btn.secondary{background:#1b2340;border:1px solid var(--line)}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}.success{color:#a7f3d0;background:#063d2b;border:1px solid #047857;padding:14px;border-radius:12px}.error{color:#fecdd3;background:#3b0d19;border:1px solid #7f1d2d;padding:12px;border-radius:10px}.ai{background:#0b1022;padding:18px;white-space:pre-wrap}.markdown{white-space:normal;line-height:1.6}.markdown>:first-child{margin-top:0}.markdown>:last-child{margin-bottom:0}.markdown p{margin:0 0 .7em}.markdown h1,.markdown h2,.markdown h3,.markdown h4{color:#fff;line-height:1.25;margin:1.1em 0 .45em}.markdown h1{font-size:1.6em}.markdown h2{font-size:1.35em}.markdown h3{font-size:1.15em}.markdown ul,.markdown ol{margin:0 0 .7em;padding-left:1.4em}.markdown li{margin:.15em 0}.markdown ul{list-style:disc}.markdown ol{list-style:decimal}.markdown pre{background:#080b18;border:1px solid var(--line);border-radius:10px;padding:12px;overflow-x:auto;white-space:pre;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.9em;margin:0 0 .7em}.markdown :not(pre)>code{background:#0a0f21;border:1px solid var(--line);border-radius:6px;padding:1px 5px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.9em}.markdown blockquote{border-left:3px solid var(--line);margin:.2em 0 .7em;padding:.1em 0 .1em 1em;color:var(--muted)}.markdown a{color:var(--c);text-decoration:underline}.markdown hr{border:0;border-top:1px solid var(--line);margin:1em 0}.markdown table{width:100%;border-collapse:collapse;margin:0 0 .7em;font-size:.95em}.markdown th,.markdown td{border:1px solid var(--line);padding:7px 10px;text-align:left}.markdown th{background:#0a0f21}.site-footer{margin-top:48px;padding:24px 0;border-top:1px solid var(--line);display:flex;gap:16px;flex-wrap:wrap;color:var(--muted);font-size:14px}.site-footer a{text-decoration:underline;text-underline-offset:3px}
        /* Authenticated pages use the full viewport; guest/auth pages retain the constrained shell. */
        .wrap{width:100%;max-width:none;margin:0;padding:24px clamp(16px,3vw,48px)}
        .wrap.guest-shell{max-width:1120px;margin:0 auto;padding:24px 22px}
        .wrap.app-shell{max-width:none}
        .markdown-tabs{display:inline-flex;gap:4px;margin-bottom:8px;padding:3px;border:1px solid #293052;border-radius:10px;background:#0a0f21}
        .markdown-tab{border:0;border-radius:7px;padding:6px 12px;background:transparent;color:var(--muted);font:inherit;font-weight:800;cursor:pointer}
        .markdown-tab:hover{color:#eef2ff}
        .markdown-tab-active{background:#281d56;color:#fff}
        .markdown-preview{min-height:110px;border:1px solid #293052;border-radius:12px;padding:13px;background:#0a0f21}
        .version-history{display:grid;gap:10px;margin-top:14px}.version-history-row{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:14px 16px;background:#0a0f21;border:1px solid var(--line);border-radius:14px}.version-history-row.current{border-color:var(--c);box-shadow:0 0 0 1px #22d3ee33}.version-history-meta{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.version-status.approved,.version-status.completed{border-color:#34d399;color:#a7f3d0}.version-status.failed{border-color:var(--danger);color:#fecdd3}.version-status.needs_revision{border-color:#f59e0b;color:#fde68a}.version-current{border-color:var(--c);color:#a5f3fc}.version-readonly{color:var(--muted);font-size:12px}
        .formatted-text{max-width:100%;min-width:0;overflow-wrap:anywhere;word-break:break-word;line-height:1.7}.formatted-text h3{margin:1.25rem 0 .35rem;padding-bottom:.3rem;border-bottom:1px solid #293052;color:#c4b5fd;font-size:.82rem;letter-spacing:.11em;text-transform:uppercase}.formatted-text h3 + p{margin-top:.25rem}.formatted-text p{overflow-wrap:anywhere}.formatted-text pre{max-width:100%;overflow-x:auto}.formatted-text table{display:block;max-width:100%;overflow-x:auto}.formatted-text.ai{white-space:normal}
        .weekly-video-modal { width: min(95vw, 1000px); max-width: none; max-height: min(90vh, 760px); padding: 0; border: 1px solid #293052; border-radius: 18px; background: #11162b; color: #eef2ff; }
        .weekly-video-modal::backdrop { background: rgba(3, 5, 15, .8); }
        .weekly-video-modal-content { display: grid; gap: 14px; padding: 22px; max-height: 90vh; overflow-y: auto; }
        .weekly-video-modal-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .weekly-video-modal-header h2 { margin: 0; }
        .weekly-video-modal-close { border: 1px solid #293052; border-radius: 10px; padding: 8px 12px; background: #1b2340; color: #fff; cursor: pointer; }
        .weekly-video-player { display: block; width: 100%; max-height: 70vh; background: #000; border-radius: 12px; }
        .weekly-video-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        @media (max-width: 600px) { .weekly-video-modal { width: calc(100vw - 20px); } .weekly-video-modal-content { padding: 16px; } }
        .evidence-image-modal { width: min(95vw, 1100px); max-width: none; max-height: 90vh; padding: 0; border: 1px solid #293052; border-radius: 18px; background: #11162b; color: #eef2ff; }
        .evidence-image-modal::backdrop { background: rgba(3, 5, 15, .8); }
        .evidence-image-modal-content { display: grid; gap: 14px; padding: 22px; max-height: 90vh; overflow-y: auto; }
        .evidence-image-modal-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .evidence-image-modal-header h2 { margin: 0; overflow-wrap: anywhere; }
        .evidence-image-modal-close { border: 1px solid #293052; border-radius: 10px; padding: 8px 12px; background: #1b2340; color: #fff; cursor: pointer; font-size: 1.25rem; line-height: 1; }
        .evidence-image-preview { display: block; max-width: 90vw; max-height: 76vh; width: auto; height: auto; object-fit: contain; margin: 0 auto; border-radius: 12px; }
        .evidence-preview-modal { width: min(95vw, 1200px); max-width: none; max-height: 90vh; padding: 0; border: 1px solid #293052; border-radius: 18px; background: #11162b; color: #eef2ff; }
        .evidence-preview-modal::backdrop { background: rgba(3, 5, 15, .8); }
        .evidence-preview-modal-content { display: grid; gap: 14px; padding: 22px; max-height: 90vh; overflow-y: auto; }
        .evidence-preview-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .evidence-preview-modal-header h2 { margin: 0; overflow-wrap: anywhere; }
        .evidence-preview-modal-close { border: 1px solid #293052; border-radius: 10px; padding: 8px 12px; background: #1b2340; color: #fff; cursor: pointer; font-size: 1.25rem; line-height: 1; }
        .evidence-preview-area { min-height: 180px; display: grid; place-items: center; overflow: auto; }
        .evidence-preview-image { display: block; max-width: 90vw; max-height: 76vh; width: auto; height: auto; object-fit: contain; border-radius: 12px; }
        .evidence-preview-frame { display: block; width: 100%; height: min(72vh, 760px); border: 1px solid var(--line); border-radius: 12px; background: #fff; }
        .evidence-preview-video { display: block; width: 100%; max-height: 72vh; background: #000; border-radius: 12px; }
        .evidence-preview-text { width: 100%; max-height: 72vh; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; background: #0a0f21; border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        .evidence-preview-table-wrap { width: 100%; max-height: 72vh; overflow: auto; border: 1px solid var(--line); border-radius: 12px; }
        .evidence-preview-table { width: 100%; min-width: 620px; border-collapse: collapse; background: #0a0f21; }
        .evidence-preview-table th,.evidence-preview-table td { border: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; white-space: pre-wrap; }
        .evidence-preview-table th { position: sticky; top: 0; background: #1b2340; }
        }
    </style>
</head>
<body class="min-h-screen bg-canvas font-sans text-slate-100 antialiased">
@auth
<div class="flex min-h-screen">
    @include('layouts.navigation')
    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-canvas/95 px-4 py-3 backdrop-blur lg:hidden">
            <button class="cursor-pointer rounded-lg border border-line bg-raised px-3 py-1.5 font-sans text-sm font-extrabold text-white focus-visible:outline-3 focus-visible:outline-accent/50" type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false">☰ Menu</button>
            <a class="text-xs font-black tracking-[0.08em]" href="{{ route('home') }}">⚡ DEVELOPER TRAINING PLATFORM</a>
        </header>
@endauth
<div class="wrap {{ auth()->check() ? 'app-shell' : 'guest-shell' }}">
    @guest
        <div class="nav">
            <a class="brand" href="{{ route('home') }}">⚡ DEVELOPER TRAINING PLATFORM</a>
            <span class="nav-links"><a class="badge" href="{{ route('login') }}">Login</a><a class="badge" href="{{ route('register') }}">Register</a></span>
        </div>
    @endguest
    @yield('content')
    <footer class="mt-12 flex flex-wrap gap-4 border-t border-line py-6 text-sm text-muted" aria-label="Legal"><span>© {{ date('Y') }} Developer Training Platform</span><a class="underline underline-offset-3 hover:text-slate-100" href="{{ route('privacy-policy') }}">Privacy Policy</a><a class="underline underline-offset-3 hover:text-slate-100" href="{{ route('terms-of-service') }}">Terms of Service</a></footer>
</div>
@auth
    </div>
</div>
@endauth
</body>
</html>
