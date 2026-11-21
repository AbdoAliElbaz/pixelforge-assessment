<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    protected $fillable = [
        'user_id', 'pixelforge_id', 'idempotency_key', 'prompt', 'size', 'quality',
        'reference_images', 'status', 'error_code', 'error_message',
        'rates', 'usage', 'provider_cost_nano', 'charge_nano', 'reserved_nano', 'image_path',
        'is_fixture', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'is_fixture' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'rejected', 'submit_failed'], true);
    }
}
