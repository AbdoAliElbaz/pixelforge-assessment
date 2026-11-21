<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Per-day/user spend + Pixelforge /v1/usage reconciliation. */
class AdminReportService
{
    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array{rows: Collection, reconciliation: Collection|null, window: array{start: string, end: string}}
     */
    public function build(?Carbon $since = null): array
    {
        $since ??= now()->subDays(29)->startOfDay();
        $window = ['start' => $since->toDateString(), 'end' => now()->toDateString()];

        $rows = Generation::query()
            ->where('status', 'succeeded')
            ->where('completed_at', '>=', $since)
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->groupByRaw('date(generations.completed_at), generations.user_id')
            ->orderByDesc('day')
            ->orderBy('users.email')
            ->get([
                DB::raw('date(generations.completed_at) as day'),
                'users.email',
                DB::raw('count(*) as generations'),
                DB::raw('sum(generations.charge_nano) as charged_nano'),
                DB::raw('sum(generations.provider_cost_nano) as cost_nano'),
            ])
            ->map(fn ($r) => [
                'day' => $r->day,
                'user' => $r->email,
                'generations' => (int) $r->generations,
                'charged' => Pricing::format((int) $r->charged_nano),
                'cost' => Pricing::format((int) $r->cost_nano),
                'profit' => Pricing::format((int) $r->charged_nano - (int) $r->cost_nano),
            ]);

        $oursByDay = Generation::query()
            ->where('status', 'succeeded')
            ->where('completed_at', '>=', $since)
            ->groupByRaw('date(completed_at)')
            ->pluck(DB::raw('sum(provider_cost_nano)'), DB::raw('date(completed_at)'));

        $usage = Cache::remember(
            'pixelforge_usage_admin',
            now()->addMinute(),
            fn () => $this->client->usage($window['start'], $window['end']),
        );

        $reconciliation = null;
        if ($usage !== null) {
            $reconciliation = collect($usage['data'] ?? [])->map(function ($day) use ($oursByDay) {
                $theirs = Pricing::usdToNano($day['cost_usd']);
                $ours = (int) ($oursByDay[$day['date']] ?? 0);

                return [
                    'day' => $day['date'],
                    'generations' => (int) $day['generations'],
                    'pixelforge_cost' => Pricing::format($theirs),
                    'our_cost' => Pricing::format($ours),
                    'matches' => $theirs === $ours,
                ];
            })->sortByDesc('day')->values();
        }

        return [
            'rows' => $rows,
            'reconciliation' => $reconciliation,
            'window' => $window,
        ];
    }
}
