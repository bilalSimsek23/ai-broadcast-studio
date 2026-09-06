@php
    /** @var \App\Models\Episode $episode */
@endphp
<div class="space-y-4 text-sm">
    <div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Program</div>
        <div>
            {{ $episode->show?->name ?? '—' }} — {{ $episode->title }}
            @if ($episode->episode_number !== null)
                <span class="text-gray-500">(Bölüm {{ $episode->episode_number }})</span>
            @endif
        </div>
    </div>

    <div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ana konu</div>
        <div>{{ $episode->main_topic ?: '—' }}</div>
    </div>

    <div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">AI Karakterleri</div>
        @if ($episode->lineup->isEmpty())
            <div>—</div>
        @else
            <ol class="list-inside list-decimal">
                @foreach ($episode->lineup as $slot)
                    <li>
                        {{ $slot->aiPersona->name }}@if ($slot->aiPersona->title) — {{ $slot->aiPersona->title }}@endif
                        @if (filled($slot->episode_instructions))
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $slot->episode_instructions }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Başlıklar</div>
        @if ($episode->topics->isEmpty())
            <div>—</div>
        @else
            <ol class="list-inside list-decimal space-y-1">
                @foreach ($episode->topics as $topic)
                    <li>
                        {{ $topic->title }}
                        @if ($topic->questions->isNotEmpty())
                            <ul class="ml-5 list-inside list-disc text-gray-600 dark:text-gray-400">
                                @foreach ($topic->questions as $question)
                                    <li>{{ $question->question }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Sunucu Brifingi</div>
        <div class="whitespace-pre-line">{{ $episode->opening_notes ?: '—' }}</div>
    </div>

    <div>
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">AI Brifingi</div>
        <div class="whitespace-pre-line">{{ $episode->ai_objective ?: '—' }}</div>
    </div>
</div>
