<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 6 — zona Resource (ronde 3), chunk dom-rest.
 *
 * Kurikulum 121 escape baseline: FilterSpec (grammar operator suffix,
 * whitelist injection boundary, cap 32 kondisi, truncation 256, equals
 * bool/float kanonik, compare numerik vs leksikal), SortSpec ('-'/'+'/
 * dup-first-wins/cap 8/boundary 64, usort desc negation + stabil), Cursor
 * (base64url + crc32 salt, anchor grammar, MAX_OFFSET >> 2, boundary 96/128),
 * PageRequest (scalarToInt anchor 12 digit, clamp PHP_INT_MAX - limit,
 * fallback page/per-page), PageSlice (hasNext strictly-less, meta lengkap).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Resource\AdmissionDecision;
use Zef\Framework\Resource\Cursor;
use Zef\Framework\Resource\FilterCondition;
use Zef\Framework\Resource\FilterSpec;
use Zef\Framework\Resource\PageRequest;
use Zef\Framework\Resource\PageSlice;
use Zef\Framework\Resource\ResourceBudget;
use Zef\Framework\Resource\SortKey;
use Zef\Framework\Resource\SortSpec;

/**
 * @internal
 */
final class EdgeMatrixF6RestTest extends TestCase
{
    // -------------------------------------------------- FilterCondition (1)

    public function testFilterConditionGuards(): void
    {
        new FilterCondition('status', FilterCondition::EQ, 'open');
        new FilterCondition('status', FilterCondition::IN, ['a', 'b']);
        foreach ([
            ['', FilterCondition::EQ, 'x'],
            ['status', 'bogus', 'x'],
            ['status', FilterCondition::IN, 'scalar'],
            ['status', FilterCondition::IN, []],
            ['status', FilterCondition::EQ, ['list']],
        ] as $args) {
            try {
                new FilterCondition(...$args);
                self::fail('FilterCondition invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ----------------------------------------------------- FilterSpec (40)

    public function testFilterSpecCtorRequiresConditionInstances(): void
    {
        $spec = new FilterSpec([new FilterCondition('status', FilterCondition::EQ, 'open')]);
        self::assertCount(1, $spec->conditions());
        self::assertFalse($spec->isEmpty());
        self::assertTrue(new FilterSpec()->isEmpty());

        try {
            // @phpstan-ignore-next-line (elemen non-condition disengaja)
            new FilterSpec(['status=open']);
            self::fail('Elemen non-FilterCondition harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testFilterSpecParsesNestedAndFlatForms(): void
    {
        $nested = FilterSpec::fromQuery(['filter' => ['status' => 'open', 'price_gte' => '100']], $this->wl());
        self::assertCount(2, $nested->conditions());
        self::assertSame('status', $nested->conditions()[0]->field);
        self::assertSame('eq', $nested->conditions()[0]->op);
        self::assertSame('open', $nested->conditions()[0]->value);
        self::assertSame('price', $nested->conditions()[1]->field);
        self::assertSame('gte', $nested->conditions()[1]->op);

        $flat = FilterSpec::fromQuery(['filter_status' => 'open', 'filter_price_gte' => '100'], $this->wl());
        self::assertCount(2, $flat->conditions());
        self::assertSame('price', $flat->conditions()[1]->field);

        $custom = FilterSpec::fromQuery(['f_status' => 'new'], $this->wl(), 'f');
        self::assertCount(1, $custom->conditions());
        self::assertSame('status', $custom->conditions()[0]->field);
    }

    public function testFilterSpecConditionCapIs32AndLenient(): void
    {
        $wl = [];
        for ($i = 0; $i < 40; ++$i) {
            $wl[] = 'f' . $i;
        }
        $q2 = ['filter' => []];
        for ($i = 0; $i < 40; ++$i) {
            $q2['filter']['f' . $i] = 'x';
        }
        $spec = FilterSpec::fromQuery($q2, $wl);
        self::assertCount(32, $spec->conditions());
        // Key non-string / value non-scalar dilewati tanpa error.
        $mixed = FilterSpec::fromQuery(['filter' => ['status' => 'ok', 5 => 'int-key', 'arr' => ['x'], 'price' => '3']], $this->wl());
        self::assertCount(2, $mixed->conditions());
    }

    public function testFilterSpecKeyTrimAndLenientDrops(): void
    {
        $spec = FilterSpec::fromQuery(['filter' => [' status ' => 'open']], $this->wl());
        self::assertCount(1, $spec->conditions());
        self::assertSame('status', $spec->conditions()[0]->field);
        // fieldPart kosong: key tepat 'filter_'
        self::assertCount(0, FilterSpec::fromQuery(['filter_' => 'open'], $this->wl())->conditions());
        // Key terlalu panjang: 73 byte -> drop; 68 (64 field + '_gte') -> ok.
        $long = str_repeat('b', 73);
        self::assertCount(0, FilterSpec::fromQuery(['filter' => [$long => 'x']], [substr($long, 0, 64)])->conditions());
        $f64 = str_repeat('a', 64);
        $ok = FilterSpec::fromQuery(['filter' => [$f64 . '_gte' => 'x']], [$f64]);
        self::assertCount(1, $ok->conditions());
        self::assertSame($f64, $ok->conditions()[0]->field);
    }

    public function testFilterSpecOperatorGrammarAndInSplit(): void
    {
        $spec = FilterSpec::fromQuery(['filter_status_in' => 'a, b,,c'], $this->wl());
        self::assertCount(1, $spec->conditions());
        self::assertSame('in', $spec->conditions()[0]->op);
        self::assertSame(['a', 'b', 'c'], $spec->conditions()[0]->value);
        // in dengan nilai kosong semua -> condition dibuang.
        self::assertCount(0, FilterSpec::fromQuery(['filter_status_in' => ' , ,'], $this->wl())->conditions());
        // Suffix bukan operator -> field unknown -> drop.
        self::assertCount(0, FilterSpec::fromQuery(['filter_status_eqx' => 'a'], $this->wl())->conditions());
        // Nilai > 256 byte dipotong tepat 256.
        $v = str_repeat('v', 300);
        $spec = FilterSpec::fromQuery(['filter_status' => $v], $this->wl());
        self::assertSame(str_repeat('v', 256), $spec->conditions()[0]->value);
        // Unknown field dibuang lenient.
        self::assertCount(0, FilterSpec::fromQuery(['filter_ghost' => 'x'], $this->wl())->conditions());
        self::assertCount(0, FilterSpec::fromQuery(['other_status' => 'x'], $this->wl())->conditions());
    }

    public function testFilterSpecApplyEqualsCanonicalizesBoolAndFloat(): void
    {
        $eq1 = FilterSpec::fromQuery(['filter_flag' => '1'], $this->wl());
        $eq0 = FilterSpec::fromQuery(['filter_flag' => '0'], $this->wl());
        $rows = [['flag' => true], ['flag' => false], ['flag' => '1'], ['flag' => null]];
        self::assertSame([0, 1], array_keys($eq1->applyTo($rows)));
        self::assertSame([0], array_keys($eq0->applyTo($rows)));
        $eq5 = FilterSpec::fromQuery(['filter_price' => '5'], $this->wl());
        $prow = [['price' => 5], ['price' => 5.0], ['price' => 5.5], ['price' => '5']];
        self::assertSame([0, 1, 2], array_keys($eq5->applyTo($prow)));
        $eq55 = FilterSpec::fromQuery(['filter_price' => '5.5'], $this->wl());
        self::assertSame([0], array_keys($eq55->applyTo($prow)));
    }

    public function testFilterSpecApplyNeqNullMatches(): void
    {
        $neq = FilterSpec::fromQuery(['filter_status_neq' => 'open'], $this->wl());
        $rows = [['status' => 'open'], ['status' => 'closed'], ['status' => null], ['other' => 1], ['status' => 5]];
        self::assertSame([0, 1, 2, 3], array_keys($neq->applyTo($rows)));
    }

    public function testFilterSpecApplyInAndLike(): void
    {
        $in = FilterSpec::fromQuery(['filter_status_in' => 'open,pending'], $this->wl());
        $rows = [['status' => 'open'], ['status' => 'pending'], ['status' => 'closed'], ['status' => null], []];
        self::assertSame([0, 1], array_keys($in->applyTo($rows)));
        $like = FilterSpec::fromQuery(['filter_name_like' => 'WORLD'], $this->wl());
        $lrows = [['name' => 'hello world'], ['name' => 'HELLO WORLD'], ['name' => 'nope'], ['name' => 12345], ['name' => null]];
        self::assertSame([0, 1], array_keys($like->applyTo($lrows)));
    }

    public function testFilterSpecApplyOrderingOperatorsNumericAndLexical(): void
    {
        $gt = FilterSpec::fromQuery(['filter_price_gt' => '2.5'], $this->wl());
        $rows = [['price' => 2.6], ['price' => 2.4], ['price' => 2.5], ['price' => null], ['price' => '3']];
        self::assertSame([0, 1], array_keys($gt->applyTo($rows)));
        $gte = FilterSpec::fromQuery(['filter_price_gte' => '2.5'], $this->wl());
        self::assertSame([0, 1, 2], array_keys($gte->applyTo($rows)));
        $lt = FilterSpec::fromQuery(['filter_price_lt' => '2.5'], $this->wl());
        self::assertSame([0], array_keys($lt->applyTo($rows)));
        $lte = FilterSpec::fromQuery(['filter_price_lte' => '2.5'], $this->wl());
        self::assertSame([0, 1], array_keys($lte->applyTo($rows)));
        $lex = FilterSpec::fromQuery(['filter_name_gt' => 'a'], $this->wl());
        $srows = [['name' => 'b'], ['name' => 'a'], ['name' => 'aa']];
        self::assertSame([0, 1], array_keys($lex->applyTo($srows)));
    }

    public function testFilterSpecApplyMultiConditionAndObjects(): void
    {
        $spec = FilterSpec::fromQuery(['filter_status' => 'open', 'filter_price_lt' => '10'], $this->wl());
        $rows = [
            (object) ['status' => 'open', 'price' => 5],
            (object) ['status' => 'open', 'price' => 50],
            ['status' => 'open', 'price' => 7],
        ];
        self::assertSame([0, 1], array_keys($spec->applyTo($rows)));
        // Tanpa kondisi -> identik.
        self::assertSame($rows, new FilterSpec()->applyTo($rows));
        // Row skalar (bukan array/object) -> nilai selalu null.
        $neq = FilterSpec::fromQuery(['filter_status_neq' => 'x'], $this->wl());
        // @phpstan-ignore-next-line (row skalar disengaja)
        self::assertSame([0, 1], array_keys($neq->applyTo(['scalar', 42])));
    }

    // ------------------------------------------------------ SortSpec (24)

    public function testSortSpecCtorAndDefaults(): void
    {
        $s = new SortSpec([new SortKey('price', true)]);
        self::assertCount(1, $s->keys());
        self::assertFalse($s->isEmpty());
        $d = new SortSpec([], ['name', 'created'], true);
        self::assertSame('name', $d->keys()[0]->field);
        self::assertTrue($d->keys()[0]->desc);
        self::assertTrue($d->keys()[1]->desc);
        self::assertCount(0, new SortSpec([])->keys());

        try {
            new SortSpec(['price']);
            self::fail('Elemen non-SortKey harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new SortSpec([], ['', 'ok']);
            self::fail('Default field kosong harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            // @phpstan-ignore-next-line (default field non-string disengaja)
            new SortSpec([], [42]);
            self::fail('Default field non-string harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testSortSpecFromQueryGrammar(): void
    {
        $s = SortSpec::fromQuery(['sort' => '-price, name ,+qty'], ['price', 'name', 'qty']);
        self::assertSame('price', $s->keys()[0]->field);
        self::assertTrue($s->keys()[0]->desc);
        self::assertSame('name', $s->keys()[1]->field);
        self::assertFalse($s->keys()[1]->desc);
        self::assertSame('qty', $s->keys()[2]->field);
        self::assertFalse($s->keys()[2]->desc);
        // Tanda ganda: '--price' -> ltrim hapus semuanya -> tetap desc 'price'.
        $dbl = SortSpec::fromQuery(['sort' => '--price'], ['price']);
        self::assertSame('price', $dbl->keys()[0]->field);
        self::assertTrue($dbl->keys()[0]->desc);
        $plus = SortSpec::fromQuery(['sort' => '++price'], ['price']);
        self::assertSame('price', $plus->keys()[0]->field);
        self::assertFalse($plus->keys()[0]->desc);
        // Ekor kosong, unknown, dan duplikat first-wins.
        $mixed = SortSpec::fromQuery(['sort' => '-price,,ghost,name,price'], ['price', 'name']);
        self::assertSame(['price', 'name'], array_map(static fn (SortKey $k): string => $k->field, $mixed->keys()));
        self::assertTrue($mixed->keys()[0]->desc);
        // Field 64 byte ok, 65 byte dibuang.
        $f64 = str_repeat('z', 64);
        $ok = SortSpec::fromQuery(['sort' => $f64], [$f64]);
        self::assertCount(1, $ok->keys());
        $f65 = str_repeat('z', 65);
        self::assertCount(0, SortSpec::fromQuery(['sort' => $f65], [substr($f65, 0, 64)])->keys());
        // Raw > 512 dipotong.
        self::assertCount(0, SortSpec::fromQuery(['sort' => str_repeat('q', 600)], ['price'])->keys());
    }

    public function testSortSpecCap8AndFallback(): void
    {
        $wl = ['f0', 'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9'];
        $s = SortSpec::fromQuery(['sort' => 'f0,f1,f2,f3,f4,f5,f6,f7,f8,f9'], $wl);
        self::assertCount(8, $s->keys());
        self::assertSame('f7', $s->keys()[7]->field);
        // Fallback default saat tidak ada key valid.
        $d = SortSpec::fromQuery(['sort' => 'ghost'], ['price'], 'sort', ['name'], true);
        self::assertSame('name', $d->keys()[0]->field);
        self::assertTrue($d->keys()[0]->desc);
        self::assertCount(0, SortSpec::fromQuery(['sort' => ['array']], ['price'])->keys());
        self::assertCount(0, SortSpec::fromQuery([], ['price'])->keys());
    }

    public function testSortSpecApplyMultiKeyStableWithDesc(): void
    {
        $s = SortSpec::fromQuery(['sort' => '-group,name'], ['group', 'name']);
        $rows = [
            ['group' => 2, 'name' => 'a', 'tag' => 0],
            ['group' => 1, 'name' => 'b', 'tag' => 1],
            ['group' => 2, 'name' => 'b', 'tag' => 2],
            ['group' => 1, 'name' => 'a', 'tag' => 3],
        ];
        $out = $s->applyTo($rows);
        self::assertSame([0, 2, 3, 1], array_column($out, 'tag'));
        // Stabil: dua baris identik mempertahankan urutan asli.
        $eq = SortSpec::fromQuery(['sort' => 'group'], ['group']);
        $tied = [['group' => 1, 'tag' => 'x'], ['group' => 1, 'tag' => 'y']];
        self::assertSame(['x', 'y'], array_column($eq->applyTo($tied), 'tag'));
        // Kurang dari 2 baris atau tanpa key -> apa adanya.
        self::assertSame([], $s->applyTo([]));
        self::assertCount(1, $s->applyTo([['group' => 9]]));
        self::assertSame($rows, new SortSpec([])->applyTo($rows));
    }

    public function testSortSpecToQueryRoundTrip(): void
    {
        self::assertNull(new SortSpec([])->toQuery());
        $s = SortSpec::fromQuery(['sort' => '-price,name'], ['price', 'name']);
        self::assertSame('-price,name', $s->toQuery());
    }

    // --------------------------------------------------------- Cursor (20)

    public function testCursorOffsetGuardAndRoundtrip(): void
    {
        new Cursor(0);
        new Cursor(PHP_INT_MAX >> 2);

        try {
            new Cursor(-1);
            self::fail('Offset negatif harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        foreach ([0, 1, 5, 123456, PHP_INT_MAX >> 2] as $offset) {
            $decoded = Cursor::decode(new Cursor($offset)->encode('s'), 's');
            self::assertSame($offset, $decoded->offset);
        }
        self::assertSame(5, Cursor::decode(new Cursor(5)->encode())->offset);
    }

    public function testCursorSaltMustMatchAndBound(): void
    {
        $enc = new Cursor(5)->encode('salt-a');
        self::assertSame(5, Cursor::decode($enc, 'salt-a')->offset);

        try {
            Cursor::decode($enc, 'salt-b');
            self::fail('Salt berbeda harus ditolak (tamper).');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('checksum mismatch', $e->getMessage());
        }

        try {
            new Cursor(5)->encode(str_repeat('s', 257));
            self::fail('Salt 257 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        new Cursor(5)->encode(str_repeat('s', 256));
    }

    public function testCursorDecodeRejectsMalformed(): void
    {
        foreach ([
            '',
            '    ',
            str_repeat('A', 129),
            '!!!',
            base64_encode(str_repeat('x', 97)),
            'tampered-cursor',
            base64_encode('v1.abc.123'),
            base64_encode('v1.-5.123'),
            base64_encode('v1.5'),
            base64_encode('v1.5.1.2'),
            base64_encode('v2.5.123'),
            base64_encode('v1.5.999999'),
        ] as $bad) {
            try {
                Cursor::decode($bad);
                self::fail("Cursor '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCursorDecodeTrimsAndBound96(): void
    {
        $enc = new Cursor(5)->encode();
        self::assertSame(5, Cursor::decode('  ' . $enc . '  ')->offset);
        // Decoded payload > 96 byte ditolak; offset besar hingga max tetap OK.
        $max = new Cursor(PHP_INT_MAX >> 2)->encode();
        self::assertSame(PHP_INT_MAX >> 2, Cursor::decode($max)->offset);
    }

    public function testCursorUrlSafeAlphabetAndNoPadding(): void
    {
        $enc = new Cursor(PHP_INT_MAX >> 2)->encode();
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $enc);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $enc);
        self::assertTrue(strlen($enc) <= 128);
    }

    // ---------------------------------------------------- PageRequest (19)

    public function testPageRequestCtorGuards(): void
    {
        new PageRequest(0, 1);
        new PageRequest(0, 100);
        new PageRequest(PHP_INT_MAX, 1);
        foreach ([[0, 0], [0, -1], [0, 101], [-1, 10]] as [$o, $l]) {
            try {
                new PageRequest($o, $l);
                self::fail('PageRequest invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(100, PageRequest::MAX_LIMIT);
        $l = PageRequest::limit(25, 75);
        self::assertSame(75, $l->offset);
        self::assertSame(25, $l->limit);
    }

    public function testPageRequestPageStyle(): void
    {
        $p1 = PageRequest::page(1, 10);
        self::assertSame(0, $p1->offset);
        self::assertSame(10, $p1->limit);
        $p3 = PageRequest::page(3, 10);
        self::assertSame(20, $p3->offset);

        try {
            PageRequest::page(0, 10);
            self::fail('Page 0 harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            PageRequest::page(-2, 10);
            self::fail('Page negatif harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            PageRequest::page(1, 101);
            self::fail('perPage > cap harus ditolak lewat ctor.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testPageRequestFromQueryFallbacks(): void
    {
        $d = PageRequest::fromQuery([]);
        self::assertSame(0, $d->offset);
        self::assertSame(20, $d->limit);
        // limit tidak valid -> per_page -> default.
        $q = PageRequest::fromQuery(['limit' => 'abc']);
        self::assertSame(20, $q->limit);
        $pp = PageRequest::fromQuery(['per_page' => '7']);
        self::assertSame(7, $pp->limit);
        $ppBad = PageRequest::fromQuery(['limit' => '0', 'per_page' => '-2']);
        self::assertSame(20, $ppBad->limit);
        // Clamp 100.
        self::assertSame(100, PageRequest::fromQuery(['limit' => '500'])->limit);
        // page + limit -> offset (page-1)*limit.
        $pg = PageRequest::fromQuery(['page' => '4', 'limit' => '10']);
        self::assertSame(30, $pg->offset);
        $pgDefault = PageRequest::fromQuery(['page' => '2']);
        self::assertSame(20, $pgDefault->offset);
        // offset menang atas page; offset negatif -> page path.
        $both = PageRequest::fromQuery(['offset' => '33', 'page' => '9']);
        self::assertSame(33, $both->offset);
        $negOff = PageRequest::fromQuery(['offset' => '-5', 'page' => '3', 'limit' => '4']);
        self::assertSame(8, $negOff->offset);
        // defaultLimit di-clamp ke [1, 100].
        self::assertSame(1, PageRequest::fromQuery([], 0)->limit);
        self::assertSame(1, PageRequest::fromQuery([], -9)->limit);
        self::assertSame(100, PageRequest::fromQuery([], 500)->limit);
    }

    public function testPageRequestScalarToIntAnchored(): void
    {
        // ' 5' / '5x' / 13 digit -> null -> fallback default (anchor ^$).
        foreach ([' 5', '5x', '1234567890123', '-5', '', '5.0'] as $bad) {
            $r = PageRequest::fromQuery(['limit' => $bad]);
            self::assertSame(20, $r->limit, "Limit '{$bad}' harus fallback.");
        }
        $r = PageRequest::fromQuery(['limit' => '123456789012']);
        self::assertSame(100, $r->limit); // 12 digit valid, di-clamp 100
        $i = PageRequest::fromQuery(['limit' => 33]);
        self::assertSame(33, $i->limit);
    }

    public function testPageRequestClampNearIntMax(): void
    {
        $r = PageRequest::fromQuery(['offset' => PHP_INT_MAX, 'limit' => 20]);
        self::assertSame(PHP_INT_MAX - 20, $r->offset);
        self::assertSame(20, $r->limit);
        $r2 = new PageRequest(PHP_INT_MAX, 1);
        self::assertSame(PHP_INT_MAX, $r2->offset);
    }

    // ----------------------------------------------------- PageSlice (12)

    public function testPageSliceCtorGuards(): void
    {
        new PageSlice([], 0, 1);
        new PageSlice(['a'], 0, 10);
        new PageSlice(['a'], 0, 10, 99);
        foreach ([[[], -1, 1], [[], 0, 0], [['a'], 0, 1, -1]] as $args) {
            try {
                new PageSlice(...$args);
                self::fail('PageSlice invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPageSliceHasNextStrictlyLessThanTotal(): void
    {
        self::assertFalse(new PageSlice([], 0, 10)->hasNext());
        self::assertTrue(new PageSlice(['a'], 0, 10)->hasNext());
        self::assertTrue(new PageSlice(['a'], 0, 10, 21)->hasNext());
        self::assertTrue(new PageSlice(['a'], 0, 10, 20)->hasNext());
        self::assertFalse(new PageSlice(['a'], 10, 10, 20)->hasNext());
        self::assertFalse(new PageSlice(['a'], 0, 10, 10)->hasNext());
        self::assertFalse(new PageSlice([], 0, 10, 0)->hasNext());
        self::assertSame(2, new PageSlice(['a', 'b'], 0, 10)->count());
        self::assertTrue(new PageSlice(['a'], 0, 10)->hasItems());
        self::assertFalse(new PageSlice([], 0, 10)->hasItems());
    }

    public function testPageSliceNavigation(): void
    {
        self::assertFalse(new PageSlice(['a'], 0, 10)->hasPrevious());
        self::assertTrue(new PageSlice(['a'], 5, 10)->hasPrevious());
        self::assertSame(10, new PageSlice(['a'], 0, 10, 30)->nextOffset());
        self::assertNull(new PageSlice(['a'], 20, 10, 30)->nextOffset());
        self::assertSame(10, new PageSlice(['a'], 0, 10)->nextOffset());
        self::assertSame(5, new PageSlice(['a'], 15, 10)->previousOffset());
        self::assertSame(0, new PageSlice(['a'], 5, 10)->previousOffset());
        self::assertNull(new PageSlice(['a'], 0, 10)->previousOffset());
    }

    public function testPageSliceMetaExactShape(): void
    {
        $full = new PageSlice(['a', 'b'], 20, 10, 45)->meta();
        self::assertSame(['offset' => 20, 'limit' => 10, 'count' => 2, 'total' => 45, 'next_offset' => 30, 'previous_offset' => 10], $full);
        $min = new PageSlice(['a'], 0, 10)->meta();
        self::assertSame(['offset' => 0, 'limit' => 10, 'count' => 1, 'next_offset' => 10], $min);
        $noNav = new PageSlice(['a'], 0, 10, 10)->meta();
        self::assertSame(['offset' => 0, 'limit' => 10, 'count' => 1, 'total' => 10], $noNav);
    }

    // ------------------------------------------- ResourceBudget + Admission

    public function testResourceBudgetBounds(): void
    {
        new ResourceBudget(1, 0);
        new ResourceBudget(1024, 100000);
        foreach ([[0, 0], [1025, 0], [1, -1], [1, 100001]] as [$f, $q]) {
            try {
                new ResourceBudget($f, $q);
                self::fail('Budget di luar rentang harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAdmissionDecisionReasonBound(): void
    {
        new AdmissionDecision(true, 'ok', 0, 0);
        new AdmissionDecision(false, str_repeat('r', 64), 3, 9);
        self::assertSame(3, new AdmissionDecision(true, 'ok', 3, 9)->inFlight);
        foreach ([['', 0], [str_repeat('r', 65), 0]] as [$reason]) {
            try {
                new AdmissionDecision(true, $reason, 0, 0);
                self::fail('Reason invalid harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return list<string> */
    private function wl(): array
    {
        return ['status', 'price', 'name', 'flag', str_repeat('a', 64)];
    }
}
