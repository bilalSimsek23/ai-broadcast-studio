<x-filament-panels::page>
    <form wire:submit="generate">
        {{ $this->form }}

        <div class="mt-4 flex items-center gap-3">
            <x-filament::button
                type="submit"
                wire:target="generate"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="generate">Cevap Üret</span>
                <span wire:loading wire:target="generate">Üretiliyor…</span>
            </x-filament::button>

            <span
                class="text-sm text-gray-500 dark:text-gray-400"
                wire:loading
                wire:target="generate"
            >
                AI sağlayıcısından yanıt bekleniyor…
            </span>
        </div>
    </form>

    @if ($responseText !== null)
        <x-filament::section class="mt-6">
            <x-slot name="heading">AI yanıtı</x-slot>

            <div class="whitespace-pre-wrap text-sm leading-relaxed text-gray-950 dark:text-white">{{ $responseText }}</div>

            @if ($responseNote !== null)
                <p class="mt-4 border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">{{ $responseNote }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
