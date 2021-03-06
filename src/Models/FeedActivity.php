<?php

namespace JasperTey\ActivityFeed\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use JasperTey\ActivityFeed\ActivityFeed;
use JasperTey\ActivityFeed\Contracts\PublishesToFeed;

class FeedActivity extends Model
{
    use HasTimestamps, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'data' => AsArrayObject::class,
    ];

    protected $appends = [
        'summary',
        'headline',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->actor) {
                $model->actor()->associate(Auth::user());
            }
        });

        static::saved(function ($model) {
            $model->setGroupings();
        });

        static::saving(function ($model) {
            if (! $model->published_at) {
                $model->published_at = now();
            }
        });
    }

    public static function make(array $attributes = [])
    {
        return new static($attributes);
    }

    public function grouping()
    {
        return $this->hasOne(FeedGrouping::class, 'activity_id')
            ->whereContext(null);
    }

    public function actor()
    {
        return $this->morphTo();
    }

    public function object()
    {
        return $this->morphTo();
    }

    public function target()
    {
        return $this->morphTo();
    }

    public function scopePublished(Builder $query)
    {
        return $query->whereNotNull('published_at');
    }

    public function setGroupings()
    {
        FeedGrouping::assign($this->id, $this->family_hash);
    }

    public function getFamilyHashAttribute()
    {
        $hash = "{$this->actor_id}:{$this->verb}:{$this->object_type}";

        if ($this->target_type) {
            $hash .= ":{$this->target_type}";
        }

        if ($this->target_id) {
            $hash .= ":{$this->target_id}";
        }

        if ($date = optional($this->published_at)->format('Y-m-d')) {
            $hash .= ":$date";
        }

        return $hash;
    }

    public function scopeContainsObject(Builder $query, $type, $id)
    {
        return $query->where(function ($query) use ($type, $id) {
            $query->orWhere(function ($query) use ($type, $id) {
                return $query->where('actor_type', $type)
                    ->where('actor_id', $id);
            });

            $query->orWhere(function ($query) use ($type, $id) {
                return $query->where('object_type', $type)
                    ->where('object_id', $id);
            });

            $query->orWhere(function ($query) use ($type, $id) {
                return $query->where('target_type', $type)
                    ->where('target_id', $id);
            });
        });
    }

    public function scopeActor(Builder $query, Model $model)
    {
        return $query->where('actor_type', $model->getMorphClass())
            ->where('actor_id', $model->getKey());
    }

    public function scopeObject(Builder $query, Model $model)
    {
        return $query->where('object_type', $model->getMorphClass())
            ->where('object_id', $model->getKey());
    }

    public function scopeTarget(Builder $query, Model $model)
    {
        return $query->where('target_type', $model->getMorphClass())
            ->where('target_id', $model->getKey());
    }

    public function scopeInvolving(Builder $query, $model)
    {
        return $query->where(function ($query) use ($model) {
            $query->orWhere->actor($model)
                ->orWhere->object($model)
                ->orWhere->target($model);
        });
    }

    public static function deleteAll($o = [])
    {
        $defaults = [
            'object_type' => '',
            'object_id' => '',
            'type' => null,
        ];
        $o += $defaults;

        $obj = data_get($o, 'object_type');
        $oid = data_get($o, 'object_id');

        $qry = static::query()
            ->whereContains($obj, $oid);

        if ($type = data_get($o, 'type')) {
            $qry->whereType($type);
        }

        return $qry->delete();
    }

    public function getSummaryAttribute()
    {
        return 'Actor did something with object in target';
    }

    public function getLabelsAttribute()
    {
        $labels = [
            'actor' => $this->actor_id,
            'object' => $this->object_id,
            'target' => $this->target_id,
        ];

        if ($this->actor instanceof PublishesToFeed) {
            $labels['actor'] = $this->actor->feedLabel();
        }

        if ($this->object instanceof PublishesToFeed) {
            $labels['object'] = $this->object->feedLabel();
        }

        if ($this->target instanceof PublishesToFeed) {
            $labels['target'] = $this->target->feedLabel();
        }

        return $labels;
    }

    public function getHeadlineAttribute()
    {
        $grammar = ActivityFeed::grammar();
        $objectMap = data_get($grammar, $this->object_type);

        if (is_callable($objectMap)) {
            $message = $objectMap($this);
        } else {
            $message = data_get($objectMap, $this->verb);
        }

        if (! $message) {
            return null;
        }

        if (is_callable($message)) {
            $message = $message($this);
        } else {
            $message = data_get($message, $this->verb);
        }

        return $message;
    }
}
