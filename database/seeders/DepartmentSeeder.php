<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Top-level departments and the units under them, each with the short name
     * it is known by on screen.
     *
     * The real hierarchy is not settled yet; this grouping follows the names
     * and is meant to be corrected here.
     *
     * @var array<string, array{string, array<string, string>}>
     */
    public const TREE = [
        'Департамент управления человеческими ресурсами' => ['ДУЧР', []],
        'Департамент аналитики и статистики' => ['ДАС', [
            'Отдел аналитики' => 'ОА',
            'Отдел анализа производителей' => 'ОАП',
        ]],
        'Департамент маркетинга' => ['ДМ', [
            'Отдел цифрового маркетинга' => 'ОЦМ',
            'Отдел Дизайна' => 'ОД',
            'Отдел развития продуктового портфеля' => 'ОРПП',
        ]],
        'Департамент финансового контроля и аудита' => ['ДФКА', [
            'Отдел мониторинга и финансового контроля Эволет Европы' => 'ОМФК Европа',
            'Отдел управления расходами Эволет Таджикистан' => 'ОУР Тдж',
            'Отдел платежной реконсиляции' => 'ОПР',
        ]],
        'Департамент регистрации и документации' => ['ДРД', [
            'Отдел регистрации' => 'ОР',
            'Отдел составления досье' => 'ОСД',
            'Отдел Товарных Знаков' => 'ОТЗ',
            'Научный отдел' => 'НО',
        ]],
        'Департамент инновации и автоматизации' => ['ДИА', [
            'Отдел автоматизации рабочих процессов' => 'ОАРП',
            'Отдел Веб-разработок' => 'ОВР',
            'Отдел мониторинга и оптимизации рабочих процессов' => 'ОМОРП',
        ]],
        'Департамент развития' => ['ДР', [
            'Отдел управления проектов' => 'ОУП',
        ]],
        'Департамент контрактного производства' => ['ДКП', [
            'Отдел контрактного производства Тдж' => 'ОКП Тдж',
            'Отдел планирование производство и логистики' => 'ОППЛ',
        ]],
    ];

    public function run(): void
    {
        foreach (self::TREE as $name => [$abbreviation, $children]) {
            $parent = Department::updateOrCreate(['name' => $name], ['abbreviation' => $abbreviation, 'parent_id' => null]);

            foreach ($children as $child => $childAbbreviation) {
                Department::updateOrCreate(['name' => $child], ['abbreviation' => $childAbbreviation, 'parent_id' => $parent->id]);
            }
        }
    }
}
