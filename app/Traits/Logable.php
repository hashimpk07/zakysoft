<?php
namespace App\Traits;

use App\Captain;
use App\Log;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log as FacadesLog;

trait Logable
{
    /**
     * The "booted" method of the model.
     */
    public static function bootLogable()
    {
        static::created(function (Model $model) {
            $model->recordLog(class_basename($model) . ' Created', [], $model->toArray());
        });

        static::updated(function (Model $model) {
            $model->recordLog(class_basename($model) .' Updated', $model->getOriginal(), $model->withoutRelations()->setAppends([])->toArray());
        });

        static::deleted(function (Model $model) {
            $model->recordLog(class_basename($model) .' Deleted', $model->toArray(), []);
        });
    }

    public function logs()
    {
        return $this->morphMany(Log::class, 'logable');
    }

    public function recordLog($message, $before = [], $after = [])
    {
        Log::create([
            'module' => class_basename($this),
            'logable_id' => $this->id,
            'logable_type' => get_class($this),
            "before" => $before, // "before"
            "after" => $after, // "after"
            'content' => $message,
            'created_by' => auth()->id() ?? 1,
        ]);
    }
} 