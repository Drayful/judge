{{-- Дизайн «Obsidian Precision» (Stitch) для страниц «Потоки» и «Стартовый лист».
     Самодостаточный CSS: не зависит от пересборки Tailwind на сервере. --}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
<style>
    .sx{--sx-bg:#0b1326;--sx-low:#131b2e;--sx-mid:#171f33;--sx-high:#222a3d;--sx-top:#2d3449;--sx-line:#1e293b;--sx-line2:#334155;
        --sx-text:#f8fafc;--sx-soft:#dae2fd;--sx-muted:#94a3b8;--sx-dim:#64748b;--sx-em:#10b981;--sx-em2:#4edea3;--sx-sky:#38bdf8;--sx-amber:#f59e0b;--sx-rose:#f43f5e;
        font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:var(--sx-soft);font-variant-numeric:tabular-nums}
    .sx *{box-sizing:border-box}
    .sx-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums}

    /* --- каркас --- */
    .sx-layout{display:flex;flex-direction:column;gap:1.25rem}
    .sx-aside{width:100%}
    .sx-main{min-width:0;flex:1;display:flex;flex-direction:column;gap:1rem}
    @media (min-width:1024px){.sx-layout{flex-direction:row}.sx-aside{width:19rem;flex-shrink:0}.sx-aside>.sx-card{position:sticky;top:6rem}}
    .sx-create{display:grid;gap:1.25rem;grid-template-columns:minmax(0,1fr)}
    @media (min-width:1280px){.sx-create{grid-template-columns:minmax(0,26rem) minmax(0,1fr)}}
    .sx-col{display:flex;flex-direction:column;gap:1rem;min-width:0}
    .sx-tiles{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr))}
    .sx-row-wrap{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem}
    .sx-grow{flex:1;min-width:0}
    .sx-ml-auto{margin-left:auto}
    .sx-hidden{display:none!important}

    /* --- поверхности --- */
    .sx-card{background:var(--sx-low);border:1px solid var(--sx-line);border-radius:.5rem}
    .sx-card-hi{background:var(--sx-mid);border:1px solid var(--sx-line2);border-radius:.5rem}
    .sx-pad{padding:1.25rem}.sx-pad-sm{padding:.75rem}
    .sx-inset{background:var(--sx-bg);border:1px solid var(--sx-line);border-radius:.375rem}
    .sx-accent{box-shadow:inset 4px 0 0 var(--sx-em)}
    .sx-dashed{border-style:dashed}
    .sx-divider{border-top:1px solid var(--sx-line)}

    /* --- типографика --- */
    .sx-h1{font-size:28px;line-height:1.15;font-weight:700;letter-spacing:-.02em;color:var(--sx-text);margin:0}
    @media (min-width:1280px){.sx-h1{font-size:34px}}
    .sx-h2{font-size:18px;line-height:26px;font-weight:600;letter-spacing:-.01em;color:var(--sx-text)}
    .sx-h3{font-size:15px;font-weight:600;color:var(--sx-text)}
    .sx-label{font-size:11px;line-height:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--sx-muted)}
    .sx-muted{color:var(--sx-muted)}.sx-dim{color:var(--sx-dim)}.sx-text{color:var(--sx-text)}
    .sx-em{color:var(--sx-em2)}.sx-sky{color:var(--sx-sky)}.sx-amber{color:#fbbf24}.sx-rose{color:#fb7185}
    .sx-small{font-size:12px;line-height:16px}.sx-body{font-size:14px;line-height:20px}
    .sx-num{font-size:24px;line-height:30px;font-weight:700;letter-spacing:-.02em;color:var(--sx-text)}
    .sx-strike{text-decoration:line-through}
    .sx-truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    /* --- кнопки --- */
    .sx-btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem .85rem;border-radius:.375rem;border:1px solid var(--sx-line2);
        background:#1e293b;color:var(--sx-text);font-size:13px;font-weight:600;line-height:18px;cursor:pointer;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s}
    .sx-btn:hover{background:var(--sx-line2)}
    .sx-btn:focus-visible{outline:2px solid var(--sx-em);outline-offset:2px}
    .sx-btn:disabled,.sx-btn[aria-disabled=true]{opacity:.4;cursor:not-allowed}
    .sx-btn-primary{background:var(--sx-em);border-color:var(--sx-em);color:#022c22}
    .sx-btn-primary:hover{background:#34d399;border-color:#34d399}
    .sx-btn-ghost{background:transparent;border-color:transparent;color:var(--sx-muted)}
    .sx-btn-ghost:hover{background:var(--sx-high);color:var(--sx-text)}
    .sx-btn-amber{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:#fbbf24}
    .sx-btn-amber:hover{background:rgba(245,158,11,.22)}
    .sx-btn-danger{background:rgba(244,63,94,.12);border-color:rgba(244,63,94,.3);color:#fb7185}
    .sx-btn-danger:hover{background:rgba(244,63,94,.22)}
    .sx-btn-sm{padding:.3rem .55rem;font-size:12px}
    .sx-btn-icon{padding:.35rem;width:2rem;height:2rem}
    .sx-btn-xl{width:100%;padding:1rem 1.25rem;font-size:18px;font-weight:700;border-radius:.5rem}

    /* --- чипы --- */
    .sx-chip{display:inline-flex;align-items:center;gap:.3rem;min-height:22px;padding:1px 8px;border-radius:4px;border:1px solid;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;line-height:14px;white-space:nowrap}
    .sx-chip-ok{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.3);color:var(--sx-em2)}
    .sx-chip-warn{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.3);color:#fbbf24}
    .sx-chip-bad{background:rgba(244,63,94,.15);border-color:rgba(244,63,94,.3);color:#fb7185}
    .sx-chip-info{background:rgba(56,189,248,.15);border-color:rgba(56,189,248,.3);color:var(--sx-sky)}
    .sx-chip-mute{background:rgba(148,163,184,.1);border-color:rgba(148,163,184,.25);color:var(--sx-muted)}
    .sx-chip-plain{text-transform:none;letter-spacing:0;font-weight:600;white-space:normal}
    .sx-chip{max-width:100%}
    @media (max-width:640px){.sx-entry{flex-wrap:wrap}.sx-entry>.sx-grow{flex-basis:calc(100% - 6rem)}}
    .sx-dot{width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block}

    /* --- поля --- */
    .sx-input{background:var(--sx-bg);border:1px solid var(--sx-line2);border-radius:.375rem;color:var(--sx-text);padding:.45rem .6rem;font-size:14px;line-height:20px;font-family:inherit;color-scheme:dark}
    .sx-input:focus{outline:none;border-color:var(--sx-em);box-shadow:0 0 0 1px var(--sx-em)}
    .sx-input::placeholder{color:var(--sx-dim)}
    select.sx-input{padding-right:2rem}
    .sx-search{position:relative}.sx-search .sx-input{width:100%;padding-left:2.1rem}
    .sx-search>svg{position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--sx-dim)}
    .sx-field{display:flex;flex-direction:column;gap:.35rem;min-width:0}
    .sx-field>select.sx-input{width:100%}
    .sx-check{accent-color:var(--sx-em);width:1rem;height:1rem}

    /* --- шаги, степпер, сегменты --- */
    .sx-step{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:var(--sx-em);color:#022c22;font-size:12px;font-weight:700;flex-shrink:0}
    .sx-stepper{display:inline-flex;align-items:center;gap:.25rem;background:var(--sx-bg);border:1px solid var(--sx-line2);border-radius:.375rem;padding:.2rem}
    .sx-stepper button{width:2rem;height:2rem;border-radius:.25rem;background:var(--sx-high);border:0;color:var(--sx-text);font-size:16px;cursor:pointer}
    .sx-stepper button:hover{background:var(--sx-top)}
    .sx-stepper input{width:3.5rem;background:transparent;border:0;text-align:center;color:var(--sx-em2);font-size:22px;font-weight:700;padding:0;-moz-appearance:textfield}
    .sx-stepper input:focus{outline:none;box-shadow:none}
    .sx-stepper input::-webkit-inner-spin-button,.sx-stepper input::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
    .sx-seg{display:inline-flex;flex-wrap:wrap;gap:.25rem;background:var(--sx-bg);border:1px solid var(--sx-line);border-radius:.375rem;padding:.2rem}
    .sx-seg>*{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .75rem;border-radius:.25rem;font-size:13px;font-weight:600;color:var(--sx-muted);text-decoration:none;cursor:pointer;border:0;background:transparent}
    .sx-seg>*:hover{color:var(--sx-text);background:var(--sx-high)}
    .sx-seg>.is-on,.sx-seg>label:has(:checked){background:var(--sx-em);color:#022c22}
    .sx-seg input[type=radio]{position:absolute;opacity:0;pointer-events:none}
    .sx-app{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem .9rem;border-radius:.375rem;border:1px solid var(--sx-line2);background:var(--sx-bg);color:var(--sx-soft);font-size:14px;font-weight:600;cursor:pointer}
    .sx-app:hover{border-color:var(--sx-dim)}
    .sx-app.is-on{background:var(--sx-em);border-color:var(--sx-em);color:#022c22}
    .sx-app .sx-app-no{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#022c22;color:var(--sx-em2);font-size:11px;font-weight:700}
    .sx-app .sx-app-ring{width:16px;height:16px;border-radius:50%;border:2px solid var(--sx-dim)}

    /* --- список групп --- */
    .sx-group{display:block;border-radius:.375rem;padding:.6rem .75rem;text-decoration:none;color:var(--sx-soft);border:1px solid transparent}
    .sx-group:hover{background:var(--sx-mid)}
    .sx-group.is-on{background:var(--sx-high);border-color:var(--sx-line2);box-shadow:inset 3px 0 0 var(--sx-em)}
    .sx-group.is-pool{border:1px dashed var(--sx-line2)}
    .sx-group.is-pool.is-on{border-color:var(--sx-amber);box-shadow:none}

    /* --- таймлайн расписания --- */
    .sx-timeline{display:flex;gap:3px;height:14px}
    .sx-timeline>span{flex:1;border-radius:2px;background:var(--sx-em);color:#022c22;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;overflow:hidden}
    .sx-timeline>span:nth-child(even){background:#34d399}

    /* --- строки очереди (доска потоков) --- */
    .sx-entry{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border-radius:.375rem;background:var(--sx-mid);border:1px solid var(--sx-line);user-select:none;-webkit-user-select:none}
    .sx-entry:hover{border-color:var(--sx-line2)}
    .sx-entry.is-draggable{cursor:grab}
    .sx-entry.locked{opacity:.65}
    .sx-entry.is-out{background:transparent}
    .sx-entry.is-nomusic{box-shadow:inset 3px 0 0 var(--sx-amber)}
    .sx-grip{color:var(--sx-dim);flex-shrink:0;display:inline-flex}
    .sx-startno{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:3.25rem;padding:.25rem .35rem;border-radius:.375rem;background:var(--sx-bg);border:1px solid var(--sx-line)}
    .sx-startno b{font-size:18px;line-height:22px;color:var(--sx-text)}
    .sx-stream-list{display:flex;flex-direction:column;gap:.4rem;min-height:3.5rem;border-radius:.375rem}
    .sx-stream.sx-collapsed .sx-stream-body{display:none}
    .sx-stream.sx-collapsed .sx-rot{transform:rotate(-90deg)}
    .sx-rot{transition:transform .15s}
    .sortable-ghost{opacity:.35}
    .sortable-drag,.sortable-fallback{outline:2px solid var(--sx-em)!important;box-shadow:0 12px 32px rgba(0,0,0,.6)!important;opacity:.95!important}

    /* --- таблица стартового листа --- */
    .sx-table{width:100%;border-collapse:collapse;font-size:14px}
    .sx-table th{background:var(--sx-bg);border-bottom:2px solid var(--sx-line2);color:var(--sx-muted);font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;text-align:left;padding:.6rem .5rem;white-space:nowrap}
    .sx-table td{padding:.6rem .5rem;border-bottom:1px solid var(--sx-line);vertical-align:middle}
    .sx-table tbody tr:hover td{background:rgba(51,65,85,.35)}
    .sx-table tr.is-out td{color:var(--sx-dim)}
    .sx-table tr.is-live td{background:rgba(16,185,129,.07)}
    .sx-table tr.is-live td:first-child{box-shadow:inset 3px 0 0 var(--sx-em)}
    .sx-table th:first-child,.sx-table td:first-child{padding-left:1rem}
    .sx-scroll-x{overflow-x:auto}
    .sx-icon-box{display:inline-flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:.375rem;background:var(--sx-high);color:var(--sx-em2);flex-shrink:0}
    .sx-icon-box.is-amber{background:rgba(245,158,11,.15);color:#fbbf24}
    .sx-icon-box.is-sky{background:rgba(56,189,248,.12);color:var(--sx-sky)}

    /* --- баннеры --- */
    .sx-banner{display:flex;gap:.75rem;align-items:flex-start;padding:.85rem 1rem;border-radius:.5rem;border:1px solid}
    .sx-banner-bad{background:rgba(127,29,29,.35);border-color:rgba(244,63,94,.4);color:#fecdd3}
    .sx-banner-warn{background:rgba(120,53,15,.3);border-color:rgba(245,158,11,.4);color:#fde68a}
    .sx-banner-info{background:rgba(12,74,110,.3);border-color:rgba(56,189,248,.35);color:#bae6fd}
    .sx-banner-ok{background:rgba(6,78,59,.3);border-color:rgba(16,185,129,.35);color:#a7f3d0}
    .sx details>summary{list-style:none}.sx details>summary::-webkit-details-marker{display:none}
</style>
