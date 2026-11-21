<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPixelforgeWebhook;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        if ($secret === '') {
            abort(503, 'Pixelforge webhook secret is not configured.');
        }

        $payload = $request->getContent();
        $signature = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $signature[$key] = $value;
        }

        $timestamp = (int) ($signature['t'] ?? 0);
        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        if (! hash_equals($expected, $signature['v1'] ?? '') || abs(time() - $timestamp) > 300) {
            abort(400, 'Invalid Pixelforge signature.');
        }

        $event = json_decode($payload, true);
        $eventId = $event['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            abort(400, 'Missing Pixelforge event id.');
        }

        try {
            $row = WebhookEvent::create([
                'event_id' => $eventId,
                'type' => $event['type'] ?? 'unknown',
                'payload' => $event,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->noContent();
        }

        ProcessPixelforgeWebhook::dispatch($row->id);

        return response()->noContent();
    }
}
