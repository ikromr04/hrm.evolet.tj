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
    /** name => [hint, how few files will do, how many are too many, photographed too, asked of everybody]. */
    public const DOCUMENTS = [
        'Трудовая книжка' => ['Развороты с записями', 1, null, true, true],
        'Копия внутреннего паспорта' => ['Разворот с фотографией и страница с пропиской', 1, 3, true, true],
        'Копия паспорта других стран' => ['Если есть', 1, 3, true, false],
        'Копия диплома' => ['Диплом и приложение с оценками', 1, 5, true, true],
        'Личный листок / Автобиография' => ['Заполненный и подписанный бланк', 1, 3, true, true],
        'Свидетельство о браке' => ['Если состоите в браке', 1, 2, true, false],
        'Заявление о приёме на работу' => ['Подписанное заявление', 1, 1, true, true],
        // Exactly two, which is the one thing «две фотографии» says and a
        // single upper bound could not: one photograph is not an answer to it.
        'Фотографии 3×4' => [null, 2, 2, true, true],
        'ИНН' => [null, 1, 1, true, true],
        'СИН' => [null, 1, 1, true, true],
        'Рекомендательное письмо с предыдущей работы' => ['Если есть', 1, 3, true, false],
        'Справка о несудимости' => [null, 1, 1, true, true],
        // As long as somebody's courses were: no number of them is too many.
        'Сертификаты о курсах и дополнительном обучении' => ['Если есть', 1, null, true, false],
        'Документы воинского учёта' => ['Военный билет или приписное свидетельство', 1, 5, true, false],
        'Резюме' => ['Файлом, обычно PDF', 1, 1, false, false],
        'Медицинская справка' => [null, 1, 2, true, true],
    ];

    public function run(): void
    {
        foreach (self::DOCUMENTS as $name => [$hint, $least, $most, $photographed, $required]) {
            DocumentType::firstOrCreate(['name' => $name], [
                'hint' => $hint,
                'min_files' => $least,
                'max_files' => $most,
                'accepts_files' => true,
                'accepts_camera' => $photographed,
                'is_required' => $required,
            ]);
        }
    }
}
