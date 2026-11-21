<?php

namespace App\Http\Controllers;

use App\Services\GenerationService;
use App\Services\Pricing;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GenerationController extends Controller
{
    public function index(Request $request, GenerationService $generations)
    {
        $items = $request->user()->generations()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($g) => $generations->toListItem($g));

        return Inertia::render('Generate', [
            'generations' => $items,
            'balance' => Pricing::format($request->user()->balance_nano),
            'options' => [
                'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
                'qualities' => ['low', 'medium', 'high'],
                'max_reference_images' => 4,
            ],
        ]);
    }

    public function estimate(Request $request, Pricing $pricing)
    {
        $data = $request->validate([
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['required', 'in:low,medium,high'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['url'],
        ]);

        $estimate = $pricing->estimate(
            $data['prompt'] ?? '',
            $data['size'],
            $data['quality'],
            count(array_filter($data['reference_images'] ?? [])),
        );

        return response()->json([
            'charge_low' => Pricing::format($estimate['charge_low_nano']),
            'charge_high' => Pricing::format($estimate['charge_high_nano']),
            'cost_low' => Pricing::format($estimate['cost_low_nano']),
            'cost_high' => Pricing::format($estimate['cost_high_nano']),
        ]);
    }

    public function store(Request $request, GenerationService $generations)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['required', 'in:low,medium,high'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['url'],
            'displayed_charge_high' => ['nullable', 'string'],
        ]);

        $result = $generations->submit($request->user(), $data);

        if (! $result['ok']) {
            return back()->withErrors(['balance' => $result['message']]);
        }

        return redirect()->route('generate');
    }
}
