<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class SupportAdditionalAggregateServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {

        Builder::macro('withAggregate', function($relations, $column, $function = null){
            if (empty($relations)) {
                return $this;
            }

            if (is_null($this->query->columns)) {
                $this->query->select([$this->query->from.'.*']);
            }

            $relations = is_array($relations) ? $relations : [$relations];

            foreach ($this->parseWithRelations($relations) as $name => $constraints) {
                // First we will determine if the name has been aliased using an "as" clause on the name
                // and if it has we will extract the actual relationship name and the desired name of
                // the resulting column. This allows multiple aggregates on the same relationships.
                $segments = explode(' ', $name);

                unset($alias);

                if (count($segments) === 3 && Str::lower($segments[1]) === 'as') {
                    [$name, $alias] = [$segments[0], $segments[2]];
                }

                $relation = $this->getRelationWithoutConstraints($name);

                if ($function) {
                    $hashedColumn = $this->getQuery()->from === $relation->getQuery()->getQuery()->from
                                                ? "{$relation->getRelationCountHash(false)}.$column"
                                                : $column;

                    $wrappedColumn = $this->getQuery()->getGrammar()->wrap(
                        $column === '*' ? $column : $relation->getRelated()->qualifyColumn($hashedColumn)
                    );

                    $expression = $function === 'exists' ? $wrappedColumn : sprintf('%s(%s)', $function, $wrappedColumn);
                } else {
                    $expression = $column;
                }

                // Here, we will grab the relationship sub-query and prepare to add it to the main query
                // as a sub-select. First, we'll get the "has" query and use that to get the relation
                // sub-query. We'll format this relationship name and append this column if needed.
                $query = $relation->getRelationExistenceQuery(
                    $relation->getRelated()->newQuery(), $this, new Expression($expression)
                )->setBindings([], 'select');

                $query->callScope($constraints);

                $query = $query->mergeConstraintsFrom($relation->getQuery())->toBase();

                // If the query contains certain elements like orderings / more than one column selected
                // then we will remove those elements from the query so that it will execute properly
                // when given to the database. Otherwise, we may receive SQL errors or poor syntax.
                $query->orders = null;
                $query->setBindings([], 'order');

                if (count($query->columns) > 1) {
                    $query->columns = [$query->columns[0]];
                    $query->bindings['select'] = [];
                }

                // Finally, we will make the proper column alias to the query and run this sub-select on
                // the query builder. Then, we will return the builder instance back to the developer
                // for further constraint chaining that needs to take place on the query as needed.
                $alias ??= Str::snake(
                    preg_replace('/[^[:alnum:][:space:]_]/u', '', "$name $function $column")
                );

                if ($function === 'exists') {
                    $this->selectRaw(
                        sprintf('exists(%s) as %s', $query->toSql(), $this->getQuery()->grammar->wrap($alias)),
                        $query->getBindings()
                    )->withCasts([$alias => 'bool']);
                } else {
                    $this->selectSub(
                        $function ? $query : $query->limit(1),
                        $alias
                    );
                }
            }

            return $this;
        });
    
        Builder::macro('withSum', function($relation, $column){
            return $this->withAggregate($relation, $column, 'sum');
        });
        /**
         * Add subselect queries to include the average of the relation's column.
         *
         * @param  string|array  $relation
         * @param  string  $column
         * @return $this
         */
        
        Builder::macro('withAvg', function($relation, $column){
            return $this->withAggregate($relation, $column, 'avg');
        });

    // /**
    //  * Add subselect queries to include the max of the relation's column.
    //  *
    //  * @param  string|array  $relation
    //  * @param  string  $column
    //  * @return $this
    //  */
    // public function withMax($relation, $column)
    // {
    //     return $this->withAggregate($relation, $column, 'max');
    // }

    // /**
    //  * Add subselect queries to include the min of the relation's column.
    //  *
    //  * @param  string|array  $relation
    //  * @param  string  $column
    //  * @return $this
    //  */
    // public function withMin($relation, $column)
    // {
    //     return $this->withAggregate($relation, $column, 'min');
    // }

    // /**
    //  * Add subselect queries to include the sum of the relation's column.
    //  *
    //  * @param  string|array  $relation
    //  * @param  string  $column
    //  * @return $this
    //  */



    // /**
    //  * Add subselect queries to include the existence of related models.
    //  *
    //  * @param  string|array  $relation
    //  * @return $this
    //  */
    // public function withExists($relation)
    // {
    //     return $this->withAggregate($relation, '*', 'exists');
    // }

    }
}
