<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * The papers a personnel department here actually asks a new colleague for.
 *
 * Part of the demo and not of the company's own first day: «Справочники» are
 * started empty on a real installation, because a list seeded from somebody
 * else's draft is a list the office has to clean up before it can use it. This
 * one is here so that a card has something to show while the thing is being
 * built and looked at.
 *
 * What each paper says about itself is a guess at how it is usually handed in,
 * and every bit of it is editable: how many files it holds, whether it may be
 * photographed on the spot, and whether everybody is asked for it. The ones
 * that are not — a foreign passport, a marriage certificate, a military record
 * — are the ones a person either has or has not, and the office has no business
 * counting them as missing from a card where they do not apply.
 */
class DocumentTypeSeeder extends Seeder
{
    /** name => [hint, handed over on paper, asked of everybody]. */
    public const DOCUMENTS = [
        // Given to the personnel officer across a desk and kept in a safe: the
        // card holds their word that it is there, not a scan of it.
        'Трудовая книжка' => ['Оригинал сдают в отдел кадров', true, true],
        'Копия внутреннего паспорта' => ['Разворот с фотографией и страница с пропиской', false, true],
        'Копия паспорта других стран' => ['Если есть', false, false],
        'Копия диплома' => ['Диплом и приложение с оценками', false, true],
        'Личный листок / Автобиография' => ['Заполненный и подписанный бланк', false, true],
        'Свидетельство о браке' => ['Если состоите в браке', false, false],
        'Заявление о приёме на работу' => ['Подписанное заявление', false, true],
        'Фотографии 3×4' => ['Две фотографии одним файлом', false, true],
        'ИНН' => [null, false, true],
        'СИН' => [null, false, true],
        'Рекомендательное письмо с предыдущей работы' => ['Если есть', false, false],
        'Справка о несудимости' => [null, false, true],
        'Сертификаты о курсах и дополнительном обучении' => ['Если есть', false, false],
        'Документы воинского учёта' => ['Военный билет или приписное свидетельство', false, false],
        'Резюме' => [null, false, false],
        'Медицинская справка' => [null, false, true],
    ];

    public function run(): void
    {
        foreach (self::DOCUMENTS as $name => [$hint, $kept, $required]) {
            DocumentType::firstOrCreate(['name' => $name], [
                'hint' => $hint,
                'on_paper' => $kept,
                'is_required' => $required,
            ]);
        }
    }
}
