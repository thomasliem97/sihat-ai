<?php

namespace App\Models;

use Database\Factories\GuidelineChunkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $source
 * @property string|null $section
 * @property string $content
 * @property array<int, float>|null $embedding
 */
class GuidelineChunk extends Model
{
    /** @use HasFactory<GuidelineChunkFactory> */
    use HasFactory;

    protected $fillable = [
        'source',
        'section',
        'content',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }
}
