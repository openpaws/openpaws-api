<?php

namespace App\Search;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\CollectionEngine;

/**
 * The stock collection engine (used by the test suite) queries columns, but `tags` is an
 * indexed array in Meilisearch. This applies the `tags` where / whereNotIn clauses
 * against the model's tag slugs instead, so both engines answer the same queries.
 */
class TagAwareCollectionEngine extends CollectionEngine
{
    protected function searchModels(Builder $builder)
    {
        $required = [];
        foreach ($builder->wheres as $i => $where) {
            if ($where['field'] === 'tags') {
                $required[] = $where['value'];
                unset($builder->wheres[$i]);
            }
        }
        $excluded = $builder->whereNotIns['tags'] ?? [];
        unset($builder->whereNotIns['tags']);

        return parent::searchModels($builder)->filter(function ($model) use ($required, $excluded) {
            $slugs = $model->tags()->pluck('tags.slug')->all();

            return array_diff($required, $slugs) === [] && array_intersect($excluded, $slugs) === [];
        })->values();
    }
}
