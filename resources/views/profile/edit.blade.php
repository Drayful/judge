<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-100 leading-tight">
            Профиль
        </h2>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-6">
            <x-flash />

            <x-card>
                <div class="w-full">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </x-card>

            <x-card>
                <div class="w-full">
                    @include('profile.partials.update-password-form')
                </div>
            </x-card>

            <x-card>
                <div class="w-full">
                    @include('profile.partials.delete-user-form')
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
