@php
    /** @var \App\Application\Episodes\Readiness\EpisodeReadiness $readiness */
@endphp
<div class="space-y-3 text-sm">
    <div class="font-semibold {{ $readiness->isReady() ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400' }}">
        {{ $readiness->isReady() ? 'Bu bölüm yayına hazır.' : 'Bu bölüm henüz yayına hazır değil.' }}
    </div>

    <ul class="space-y-1">
        @foreach ($readiness->checks() as $check)
            <li class="flex items-start gap-2" data-readiness-key="{{ $check->key }}" data-readiness-passed="{{ $check->passed ? '1' : '0' }}">
                <span class="mt-px">{{ $check->passed ? '✅' : ($check->blocking ? '⛔' : '⚠️') }}</span>
                <span>
                    {{ $check->label }}
                    @unless ($check->passed)
                        @if ($check->hint)
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $check->hint }}</span>
                        @endif
                    @endunless
                </span>
            </li>
        @endforeach
    </ul>

    @if ($readiness->blockingIssues() !== [])
        <div class="text-xs text-red-600 dark:text-red-400">
            Eksik zorunlu maddeler: {{ implode(' · ', $readiness->blockingIssues()) }}
        </div>
    @endif
</div>
