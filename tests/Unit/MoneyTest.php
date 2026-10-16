<?php

namespace Tests\Unit;

use App\Support\Money;
use Tests\TestCase;

/**
 * Formato de montos sin floats (RNF-03).
 */
class MoneyTest extends TestCase
{
    public function test_it_formats_whole_and_fractional_amounts(): void
    {
        $this->assertSame('$ 0,00', Money::format(0));
        $this->assertSame('$ 0,05', Money::format(5));
        $this->assertSame('$ 0,99', Money::format(99));
        $this->assertSame('$ 1,00', Money::format(100));
        $this->assertSame('$ 12,34', Money::format(1_234));
    }

    public function test_it_uses_the_thousands_separator(): void
    {
        $this->assertSame('$ 1.000,00', Money::format(100_000));
        $this->assertSame('$ 1.234.567,89', Money::format(123_456_789));
    }

    public function test_it_keeps_the_sign(): void
    {
        $this->assertSame('-$ 5,00', Money::format(-500));
    }
}
