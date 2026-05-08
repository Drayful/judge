<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * После сохранения оценки судьёй (для realtime / слушателей; при driver=log просто диспатчится).
 */
class ScoreUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $performanceId,
        public int $categoryId,
    ) {}
}
