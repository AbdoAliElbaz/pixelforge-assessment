<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * All money math for the app.
 *
 * Pixelforge prices per million tokens as exact decimal strings (e.g. "5.00"
 * dollars per 1M input text tokens) and reports exact decimal costs with nine
 * decimal places. To match that exactness we never use floats: every amount is
 * an integer number of nanodollars (1 USD = 1,000,000,000 nano).
 *
 * A rate of "D.DD" dollars per 1M tokens is exactly D.DD * 1000 nanodollars
 * per token, and rates carry at most two decimal places, so the per-token
 * nanodollar rate is always an exact integer and all products stay exact.
 */
class Pricing
{
    public const MARKUP_NUMERATOR = 5;   // charge = cost * 5/4 = cost + 25%

    public const MARKUP_DENOMINATOR = 4;

    /** Fetch current pricing from Pixelforge (no auth needed), cached briefly. */
    public function current(): array
    {
        return Cache::remember('pixelforge_pricing', now()->addMinutes(5), function () {
            return Http::baseUrl(config('services.pixelforge.base_url'))
                ->acceptJson()
                ->get('/pricing')
                ->throw()
                ->json();
        });
    }

    /** Dollars-per-1M-tokens rate string -> exact nanodollars per single token. */
    public static function rateToNanoPerToken(string $rate): int
    {
        // "5.00" -> 5000 ; "12.50" -> 12500. Parse without floats.
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($rate), $m)) {
            throw new \InvalidArgumentException("Unparseable rate: {$rate}");
        }
        $dollars = (int) $m[1];
        $cents = (int) str_pad($m[2] ?? '', 2, '0');

        return $dollars * 1000 + $cents * 10;
    }

    /** Exact cost in nanodollars for a token count at a given rate. */
    public static function costNano(int $tokens, string $rate): int
    {
        return $tokens * self::rateToNanoPerToken($rate);
    }

    /** Exact decimal USD string (up to nine places, as /v1/usage returns) -> nanodollars. */
    public static function usdToNano(string $usd): int
    {
        if (! preg_match('/^(\d+)\.(\d{1,9})$/', trim($usd), $m)) {
            throw new \InvalidArgumentException("Unparseable USD amount: {$usd}");
        }

        return (int) $m[1] * 1_000_000_000 + (int) str_pad($m[2], 9, '0');
    }

    /** What the user pays: provider cost + 25% markup (nearest nanodollar). */
    public static function withMarkup(int $costNano): int
    {
        return intdiv($costNano * self::MARKUP_NUMERATOR + 2, self::MARKUP_DENOMINATOR);
    }

    /** Exact total provider cost for a finished generation's usage, in nanodollars. */
    public static function totalCostNano(array $usage, array $rates): int
    {
        $total = 0;
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $kind) {
            $total += self::costNano((int) ($usage[$kind] ?? 0), (string) $rates[$kind]);
        }

        return $total;
    }

    /**
     * Pre-submit estimate for the UI. Some token counts are only known after the
     * run, so we return an honest low..high range plus the exact parts.
     * All values are nanodollars.
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount): array
    {
        $pricing = $this->current();
        $rates = $pricing['rates'];
        $tokenRules = $pricing['tokens'];

        // Input text: ~1 token per 4 characters, real count within +/-15%.
        $estText = max(1, (int) ceil(mb_strlen($prompt) / $tokenRules['characters_per_text_token']));
        $variance = (float) $tokenRules['text_token_variance'];
        $minText = (int) floor($estText * (1 - $variance));
        $maxText = (int) ceil($estText * (1 + $variance));

        // Input images: exact, 765 tokens each.
        $imageTokens = $referenceImageCount * (int) $tokenRules['per_reference_image'];

        // Output: exact for the chosen size/quality.
        $outputTokens = (int) $tokenRules['output'][$quality][$size];

        // Reasoning: only the range is known, per quality.
        $minReasoning = (int) $tokenRules['reasoning'][$quality]['min'];
        $maxReasoning = (int) $tokenRules['reasoning'][$quality]['max'];

        $low = self::costNano($minText, $rates['input_text_tokens'])
            + self::costNano($imageTokens, $rates['input_image_tokens'])
            + self::costNano($outputTokens, $rates['output_tokens'])
            + self::costNano($minReasoning, $rates['reasoning_tokens']);

        $high = self::costNano($maxText, $rates['input_text_tokens'])
            + self::costNano($imageTokens, $rates['input_image_tokens'])
            + self::costNano($outputTokens, $rates['output_tokens'])
            + self::costNano($maxReasoning, $rates['reasoning_tokens']);

        return [
            'cost_low_nano' => $low,
            'cost_high_nano' => $high,
            'charge_low_nano' => self::withMarkup($low),
            'charge_high_nano' => self::withMarkup($high),
            'rates' => $rates,
        ];
    }

    /** Format nanodollars as an exact decimal string, e.g. 46125000 -> "0.046125". */
    public static function format(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $nano = abs($nano);
        $dollars = intdiv($nano, 1_000_000_000);
        $fraction = str_pad((string) ($nano % 1_000_000_000), 9, '0', STR_PAD_LEFT);
        $fraction = rtrim($fraction, '0');

        return $sign.$dollars.($fraction === '' ? '' : '.'.$fraction);
    }
}
