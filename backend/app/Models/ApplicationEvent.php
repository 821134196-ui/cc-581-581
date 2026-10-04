<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationEvent extends Model
{
    protected $fillable = ['application_id', 'type', 'actor_id', 'message', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function application(): BelongsTo { return $this->belongsTo(Application::class); }

    /**
     * @param array<string,mixed>|null $meta
     */
    public static function log(Application $application, string $type, ?User $actor,
                                string $message, ?array $meta = null): self
    {
        return self::create([
            'application_id' => $application->id,
            'type' => $type,
            'actor_id' => $actor?->id,
            'message' => $message,
            'meta' => $meta,
        ]);
    }
}
