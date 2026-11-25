<?php

namespace Tests\Unit;

use App\Support\Quantity;
use PHPUnit\Framework\TestCase;

/**
 * The quantity arithmetic. `tests/js/quantity.test.js` holds the browser's
 * half of it to the same figures.
 */
class QuantityTest extends TestCase
{
    public function test_a_quantity_becomes_whole_thousandths_exactly(): void
    {
        $this->assertSame(2500, Quantity::toMilli('2.5'));
        $this->assertSame(2500, Quantity::toMilli('2.500'));
        $this->assertSame(2500, Quantity::toMilli(2.5));
        $this->assertSame(10000, Quantity::toMilli(10));
        $this->assertSame(125, Quantity::toMilli('.125'));
        $this->assertSame(-1500, Quantity::toMilli('-1.5'));
        $this->assertSame(0, Quantity::toMilli(null));
        $this->assertSame(0, Quantity::toMilli(''));
        // Read digit by digit, not through a float.
        $this->assertSame(123456789125, Quantity::toMilli('123456789.125'));
        // What SQLite hands back for SUM(0.1, 0.1, 0.1).
        $this->assertSame(300, Quantity::toMilli(0.1 + 0.1 + 0.1));
        // A fourth decimal is rounded half up.
        $this->assertSame(1001, Quantity::toMilli('1.0005'));
        $this->assertSame(1000, Quantity::toMilli('1.0004'));
    }

    public function test_a_whole_quantity_comes_back_as_an_int(): void
    {
        $this->assertSame(10, Quantity::normalize('10.000'));
        $this->assertSame(2.5, Quantity::normalize('2.500'));
        $this->assertSame(0, Quantity::normalize(null));
        $this->assertSame('2.500', Quantity::toDb(2500));
        $this->assertSame('0.005', Quantity::toDb(5));
        $this->assertSame('-1.250', Quantity::toDb(-1250));
        $this->assertSame(0.2, Quantity::diff('0.3', '0.1'));
        $this->assertTrue(Quantity::isWhole('10.000'));
        $this->assertFalse(Quantity::isWhole('10.001'));
    }

    public function test_a_quantity_is_written_for_a_person_without_trailing_zeros(): void
    {
        $this->assertSame('10', Quantity::format(10));
        $this->assertSame('2.5', Quantity::format('2.500'));
        $this->assertSame('1,234.125', Quantity::format('1234.125'));
        $this->assertSame('-0.5', Quantity::format(-0.5));
    }

    public function test_a_line_is_priced_to_the_centavo_half_up(): void
    {
        // 2.5 x 18.35 = 45.875.
        $this->assertSame(45.88, Quantity::lineTotal('2.5', '18.35'));
        // 1.5 x 12.35 = 18.525: a float product lands on 18.524999...
        $this->assertSame(18.53, Quantity::lineTotal(1.5, 12.35));
        $this->assertSame(1000.0, Quantity::lineTotal(2, '500.00'));
        $this->assertSame(0.0, Quantity::lineTotal('0.001', '1.00'));
        $this->assertSame(0.01, Quantity::lineTotal('0.005', '1.00'));
        $this->assertSame(-45.88, Quantity::lineTotal('-2.5', '18.35'));
    }

    public function test_another_unit_becomes_the_products_own_exactly(): void
    {
        // 1.5 boxes of 12 is 18 pieces.
        $this->assertSame(18000, Quantity::times('1.5', 12));
        // 1.5 rolls of 75.5 m is 113.25 m.
        $this->assertSame(113250, Quantity::times('1.5', '75.5'));
        $this->assertSame(-18000, Quantity::times('-1.5', 12));
        // 1.001 x 75.5 is 75.5755: four decimals, which no column holds.
        $this->assertNull(Quantity::times('1.001', '75.5'));
        // Past the largest quantity a column holds.
        $this->assertNull(Quantity::times('999999999', 1000));
    }
}
