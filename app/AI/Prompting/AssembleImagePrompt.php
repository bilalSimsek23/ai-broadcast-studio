<?php

declare(strict_types=1);

namespace App\AI\Prompting;

use App\Models\Episode;
use InvalidArgumentException;

/**
 * Builds the single, centralized prompt for a broadcast still image
 * (CLAUDE.md §5 — prompt construction is centralized and testable, not
 * string-concatenated at call sites).
 *
 * The operator's free text is UNTRUSTED input (project-context hard
 * constraint 7): it is placed in its own clearly-labelled segment and the
 * fixed rules that follow it constrain the output regardless of what it asks
 * for. The operator still previews and vets the generated image before it
 * goes on air.
 */
final readonly class AssembleImagePrompt
{
    private const RULES = 'KURALLAR: Görselin üzerinde yazı, başlık, altyazı, filigran, logo veya '
        .'gerçekmiş gibi görünen sahte alıntı / haber / istatistik grafiği olmasın. Yayına uygun, '
        .'konuyu açıklayan, tarafsız ve gerçekçi bir görsel üret.';

    public function forOperatorBrief(string $operatorBrief, ?Episode $episode = null): string
    {
        $operatorBrief = trim($operatorBrief);

        if ($operatorBrief === '') {
            throw new InvalidArgumentException('A broadcast image prompt needs a non-empty operator brief.');
        }

        $lines = ['Bir canlı Türk televizyon programının yayın ekranında gösterilmek üzere tek bir görsel üret.'];

        if ($episode !== null) {
            $episode->loadMissing('show');

            $program = trim($episode->show->name);
            $title = trim($episode->title);
            $context = implode(' — ', array_values(array_filter(
                [$program, $title],
                static fn (string $part): bool => $part !== '',
            )));

            if ($context !== '') {
                $lines[] = 'PROGRAM BAĞLAMI: '.$context;
            }

            $mainTopic = trim((string) ($episode->main_topic ?? ''));
            if ($mainTopic !== '') {
                $lines[] = 'BÖLÜMÜN ANA KONUSU: '.$mainTopic;
            }
        }

        $lines[] = 'OPERATÖR İSTEĞİ: '.$operatorBrief;
        $lines[] = self::RULES;

        return implode("\n\n", $lines);
    }
}
