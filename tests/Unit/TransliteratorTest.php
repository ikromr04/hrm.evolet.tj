<?php

namespace Tests\Unit;

use App\Support\Transliterator;
use PHPUnit\Framework\TestCase;

class TransliteratorTest extends TestCase
{
    public function test_it_builds_email_local_parts_from_russian_and_tajik_names()
    {
        $this->assertSame('f.rahimov', Transliterator::emailLocalPart('Фарход', 'Рахимов'));
        $this->assertSame('sh.ibragimova', Transliterator::emailLocalPart('Шахноза', 'Ибрагимова'));
        $this->assertSame('h.qodirov', Transliterator::emailLocalPart('Ҳамид', 'Қодиров'));
        $this->assertSame('d.jalolov', Transliterator::emailLocalPart('Джамшед', 'Ҷалолов'));
    }
}
