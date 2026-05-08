<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-slate-900 border border-slate-700 rounded-lg font-semibold text-xs text-slate-200 uppercase tracking-widest shadow-sm hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 focus:ring-offset-slate-950 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
