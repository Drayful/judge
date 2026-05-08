<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-emerald-600 border border-emerald-500/40 rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-500 focus:bg-emerald-500 active:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-slate-950 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
