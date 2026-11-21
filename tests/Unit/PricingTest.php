<?php

namespace Tests\Unit;

use App\Services\Pricing;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    public function test_rates_parse_to_exact_nanodollars_per_token(): void
    {
        $this->assertSame(5000, Pricing::rateToNanoPerToken('5.00'));
        $this->assertSame(12500, Pricing::rateToNanoPerToken('12.50'));
        $this->assertSame(40000, Pricing::rateToNanoPerToken('40.00'));
    }

    public function test_cost_math_matches_the_documented_worked_example(): void
    {
        // From the Pixelforge docs: the maximum cost of a high-quality 1536x1024
        // image with a 400-character prompt and 2 reference images is 0.373875000.
        $rates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
        $usage = [
            'input_text_tokens' => 115,   // 400/4 estimated, +15%
            'input_image_tokens' => 1530, // 2 x 765
            'output_tokens' => 6450,      // high, 1536x1024
            'reasoning_tokens' => 8000,   // max for high
        ];

        $cost = Pricing::totalCostNano($usage, $rates);

        $this->assertSame(373_875_000, $cost);
        $this->assertSame('0.373875', Pricing::format($cost));
    }

    public function test_usage_cost_strings_parse_to_exact_nanodollars(): void
    {
        $this->assertSame(169_080_000, Pricing::usdToNano('0.169080000'));
        $this->assertSame(373_875_000, Pricing::usdToNano('0.373875000'));
        $this->assertSame(0, Pricing::usdToNano('0.000000000'));
        $this->assertSame(12_000_000_001, Pricing::usdToNano('12.000000001'));
    }

    public function test_markup_is_exactly_25_percent(): void
    {
        $this->assertSame(500, Pricing::withMarkup(400));
        $this->assertSame(467_343_750, Pricing::withMarkup(373_875_000));
    }

    public function test_format_is_an_exact_decimal_string(): void
    {
        $this->assertSame('0.000001', Pricing::format(1000));
        $this->assertSame('2', Pricing::format(2_000_000_000));
        $this->assertSame('1.5', Pricing::format(1_500_000_000));
        $this->assertSame('0', Pricing::format(0));
    }
}
