<?php
namespace StudioNet\ScoreSearch;
use Illuminate\Support\Arr;

use Illuminate\Database\Eloquent\Builder;

/**
 * Implements score-based searchable methods
 */
trait Searchable {
	/** @var string Define score column name */
	protected $scoreColumn = 'score';

	/**
	 * Add local scope in order to search models from query
	 *
	 * ```php
	 * Model::search([
	 *     'name'       => 'cyril%',
	 *     'mail'       => '%studio-net.fr',
	 *     'is_admin'   => 1,
	 *     'created_at' => '(lte)2017-06-17'
	 * ])->get();
	 * ```
	 *
	 * @param  Builder  $builder
	 * @param  array    $search
	 * @param  int|null $threshold
	 * @return Builder
	 */
	public function scopeSearch(Builder $builder, array $search = [], $threshold = null) {
		// If there's no filter, we cannot search anything. Let's just return
		// the original builder
		if (empty($search)) {
			return $builder;
		}

		$query   = $this->getSearchableQuery($builder, $search);
		$builder = $this->applySearchable($builder, $query, $threshold);

		return $builder;
	}

	/**
	 * Return corresponding grammar from entity connection driver
	 *
	 * @return GrammarInterface
	 */
	public function getSearchableGrammar() {
		$driver  = $this->getConnection()->getDriverName();
		$grammar = null;

		switch ($driver) {
			case 'pgsql' : $grammar = new Grammars\PostgreSQLGrammar ; break;
			case 'mysql' : $grammar = new Grammars\MySQLGrammar      ; break;
		}

		// Assert that grammar exists
		if (is_null($grammar)) {
			throw new \BadMethodCallException("{$driver} driver is not managed");
		}

		return $grammar;
	}

	/**
	 * Return cases
	 *
	 * @param  array $search
	 * @return array
	 */
	protected function getSearchableCases(array $search) {
		$data    = ['sql' => [], 'bindings' => []];
		$grammar = $this->getSearchableGrammar();

		foreach ($search as $key => $value) {
			$built = $key;

			if (strpos($key, '.') === false) {
				$built = sprintf('%s.%s', $this->getTable(), $key);
			}

			$expression = $grammar->getExpression($built, $value);
			$bindings   = $expression['bindings'];
			$weight     = $this->getSearchableWeight($key);
			$sql        = $grammar->getCase($expression['sql'], $weight);

			array_push($data['sql'], $sql);
			array_push($data['bindings'], $bindings);
		}

		return ['sql' => $data['sql'], 'bindings' => $data['bindings']];
	}

	/**
	 * Return weight for given column key
	 *
	 * @param  string $key
	 * @return int
	 */
	protected function getSearchableWeight($key) {
		$columns = Arr::get($this->searchable, 'columns', []);
		$weight  = 1;

		if (array_key_exists($key, $columns)) {
			$weight = $columns[$key];
		}

		return $weight;
	}

	/**
	 * Return searchable query
	 *
	 * @param  Builder $builder
	 * @param  array $search
	 * @return Builder
	 */
	protected function getSearchableQuery(Builder $builder, array $search) {
		// Get cases and implode SQL queries in order to sum everything
		$cases = $this->getSearchableCases($search);
		$sql   = implode(' + ', $cases['sql']);
		$query = clone $builder;
		$joins = Arr::get($this->searchable, 'joins', []);

		foreach ($joins as $table => $keys) {
			if (is_string($keys)) {
				$table = $keys;
				$keys  = [
					sprintf('%s.id', $table, str_singular($table)),
					sprintf('%s.%s_id', $this->getTable(), str_singular($table))
				];
			}

			$query->leftJoin($table, function($join) use ($keys) {
				$join->on($keys[0], '=', $keys[1]);

				if (array_key_exists(2, $keys) and array_key_exists(3, $keys)) {
					$join->where($keys[2], '=', $keys[3]);
				}
			});
		}

		return $query
			->setBindings(Arr::flatten($cases['bindings']))
			->addSelect(
				$this->getConnection()->raw(sprintf('%s.*', $this->getTable())),
				$this->getConnection()->raw(sprintf('(%s) as score', $sql))
			);
	}

	/**
	 * Return from value
	 *
	 * @param  Builder $query
	 * @return Illuminate\Database\Query\Expression
	 */
	protected function getSearchableFrom(Builder $query) {
		$sql   = $query->toSql();
		$table = $this->getTable();

		return $this->getConnection()->raw(sprintf('(%s) as %s', $sql, $table));
	}

	/**
	 * Apply query within main builder
	 *
	 * @param  Builder  $builder
	 * @param  Builder  $query
	 * @param  int|null $threshold
	 * @return Builder
	 */
	protected function applySearchable(Builder $builder, Builder $query, $threshold) {
		$columns   = array_sum(Arr::get($this->searchable, 'columns', []));
		$threshold = (is_null($threshold)) ? ceil($columns / 5) : $threshold;
		$bindings  = array_merge_recursive($query->getBindings(), $builder->getBindings());
		$from      = $this->getSearchableFrom($query);

		return $builder
			->from($from)
			->setBindings($bindings)
			->where($this->scoreColumn, '>=', $threshold)
			->orderBy($this->scoreColumn, 'DESC');
	}
}
