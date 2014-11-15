<?php
/**
 * @section LICENSE
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Convenience class for working with XHProf
 * <https://github.com/phacility/xhprof>. XHProf can be installed as a PECL
 * package for use with PHP5 (Zend PHP) and is built-in to HHVM 3.3.0.
 *
 * @author Bryan Davis <bd808@wikimedia.org>
 * @copyright © 2014 Bryan Davis and Wikimedia Foundation.
 * @since 1.25
 */
class Xhprof {

	/**
	 * @var array $config
	 */
	protected $config;

	/**
	 * Hierarchical profiling data returned by xhprof.
	 * @var array $hieraData
	 */
	protected $hieraData;

	/**
	 * Per-function inclusive data.
	 * @var array $inclusive
	 */
	protected $inclusive;

	/**
	 * Per-function inclusive and exclusive data.
	 * @var array $complete
	 */
	protected $complete;

	/**
	 * Configuration data can contain:
	 * - flags:   Optional flags to add additional information to the
	 *            profiling data collected.
	 *            (XHPROF_FLAGS_NO_BUILTINS, XHPROF_FLAGS_CPU,
	 *            XHPROF_FLAGS_MEMORY)
	 * - exclude: Array of function names to exclude from profiling.
	 * - include: Array of function names to include in profiling.
	 * - sort:    Key to sort per-function reports on.
	 *
	 * Note: When running under HHVM, xhprof will always behave as though the
	 * XHPROF_FLAGS_NO_BUILTINS flag has been used unless the
	 * Eval.JitEnableRenameFunction option is enabled for the HHVM process.
	 *
	 * @param array $config
	 */
	public function __construct( array $config = array() ) {
		$this->config = array_merge(
			array(
				'flags' => 0,
				'exclude' => array(),
				'include' => null,
				'sort' => 'wt',
			),
			$config
		);

		xhprof_enable( $this->config['flags'], array(
			'ignored_functions' => $this->config['exclude']
		) );
	}

	/**
	 * Stop collecting profiling data.
	 *
	 * Only the first invocation of this method will effect the internal
	 * object state. Subsequent calls will return the data collected by the
	 * initial call.
	 *
	 * @return array Collected profiling data (possibly cached)
	 */
	public function stop() {
		if ( $this->hieraData === null ) {
			$this->hieraData = $this->pruneData( xhprof_disable() );
		}
		return $this->hieraData;
	}

	/**
	 * Load raw data from a prior run for analysis.
	 * Stops any existing data collection and clears internal caches.
	 *
	 * Any 'include' filters configured for this Xhprof instance will be
	 * enforced on the data as it is loaded. 'exclude' filters will however
	 * not be enforced as they are an XHProf intrinsic behavior.
	 *
	 * @param array $data
	 * @see getRawData()
	 */
	public function loadRawData( array $data ) {
		$this->stop();
		$this->inclusive = null;
		$this->complete = null;
		$this->hieraData = $this->pruneData( $data );
	}

	/**
	 * Get raw data collected by xhprof.
	 *
	 * If data collection has not been stopped yet this method will halt
	 * collection to gather the profiling data.
	 *
	 * Each key in the returned array is an edge label for the call graph in
	 * the form "caller==>callee". There is once special case edge labled
	 * simply "main()" which represents the global scope entry point of the
	 * application.
	 *
	 * XHProf will collect different data depending on the flags that are used:
	 * - ct:    Number of matching events seen.
	 * - wt:    Inclusive elapsed wall time for this event in microseconds.
	 * - cpu:   Inclusive elapsed cpu time for this event in microseconds.
	 *          (XHPROF_FLAGS_CPU)
	 * - mu:    Delta of memory usage from start to end of callee in bytes.
	 *          (XHPROF_FLAGS_MEMORY)
	 * - pmu:   Delta of peak memory usage from start to end of callee in
	 *          bytes. (XHPROF_FLAGS_MEMORY)
	 * - alloc: Delta of amount memory requested from malloc() by the callee,
	 *          in bytes. (XHPROF_FLAGS_MALLOC)
	 * - free:  Delta of amount of memory passed to free() by the callee, in
	 *          bytes. (XHPROF_FLAGS_MALLOC)
	 *
	 * @return array
	 * @see stop()
	 * @see getInclusiveMetrics()
	 * @see getCompleteMetrics()
	 */
	public function getRawData() {
		return $this->stop();
	}

	/**
	 * Convert an xhprof data key into an array of ['parent', 'child']
	 * function names.
	 *
	 * The resulting array is left padded with nulls, so a key
	 * with no parent (eg 'main()') will return [null, 'function'].
	 *
	 * @return array
	 */
	public static function splitKey( $key ) {
		return array_pad( explode( '==>', $key, 2 ), -2, null );
	}

	/**
	 * Remove data for functions that are not included in the 'include'
	 * configuration array.
	 *
	 * @param array $data Raw xhprof data
	 * @return array
	 */
	protected function pruneData( $data ) {
		if ( !$this->config['include'] ) {
			return $data;
		}

		$want = array_fill_keys( $this->config['include'], true );
		$want['main()'] = true;

		$keep = array();
		foreach ( $data as $key => $stats ) {
			list( $parent, $child ) = self::splitKey( $key );
			if ( isset( $want[$parent] ) || isset( $want[$child] ) ) {
				$keep[$key] = $stats;
			}
		}
		return $keep;
	}

	/**
	 * Get the inclusive metrics for each function call. Inclusive metrics
	 * for given function include the metrics for all functions that were
	 * called from that function during the measurement period.
	 *
	 * If data collection has not been stopped yet this method will halt
	 * collection to gather the profiling data.
	 *
	 * See getRawData() for a description of the metric that are returned for
	 * each funcition call. The values for the wt, cpu, mu and pmu metrics are
	 * arrays with these values:
	 * - total: Cumulative value
	 * - min: Minimum value
	 * - mean: Mean (average) value
	 * - max: Maximum value
	 * - variance: Variance (spread) of the values
	 *
	 * @return array
	 * @see getRawData()
	 * @see getCompleteMetrics()
	 */
	public function getInclusiveMetrics() {
		if ( $this->inclusive === null ) {
			// Make sure we have data to work with
			$this->stop();

			$main = $this->hieraData['main()'];
			$hasCpu = isset( $main['cpu'] );
			$hasMu = isset( $main['mu'] );
			$hasAlloc = isset( $main['alloc'] );

			$this->inclusive = array();
			foreach ( $this->hieraData as $key => $stats ) {
				list( $parent, $child ) = self::splitKey( $key );
				if ( !isset( $this->inclusive[$child] ) ) {
					$this->inclusive[$child] = array(
						'ct' => 0,
						'wt' => new RunningStat(),
					);
					if ( $hasCpu ) {
						$this->inclusive[$child]['cpu'] = new RunningStat();
					}
					if ( $hasMu ) {
						$this->inclusive[$child]['mu'] = new RunningStat();
						$this->inclusive[$child]['pmu'] = new RunningStat();
					}
					if ( $hasAlloc ) {
						$this->inclusive[$child]['alloc'] = new RunningStat();
						$this->inclusive[$child]['free'] = new RunningStat();
					}
				}

				$this->inclusive[$child]['ct'] += $stats['ct'];
				foreach ( $stats as $stat => $value ) {
					if ( $stat === 'ct' ) {
						continue;
					}

					if ( !isset( $this->inclusive[$child][$stat] ) ) {
						// Ignore unknown stats
						continue;
					}

					for ( $i = 0; $i < $stats['ct']; $i++ ) {
						$this->inclusive[$child][$stat]->push(
							$value / $stats['ct']
						);
					}
				}
			}

			// Convert RunningStat instances to static arrays and add
			// percentage stats.
			foreach ( $this->inclusive as $func => $stats ) {
				foreach ( $stats as $name => $value ) {
					if ( $value instanceof RunningStat ) {
						$total = $value->m1 * $value->n;
						$this->inclusive[$func][$name] = array(
							'total' => $total,
							'min' => $value->min,
							'mean' => $value->m1,
							'max' => $value->max,
							'variance' => $value->m2,
							'percent' => 100 * $total / $main[$name],
						);
					}
				}
			}

			uasort( $this->inclusive, self::makeSortFunction(
				$this->config['sort'], 'total'
			) );
		}
		return $this->inclusive;
	}

	/**
	 * Get the inclusive and exclusive metrics for each function call.
	 *
	 * If data collection has not been stopped yet this method will halt
	 * collection to gather the profiling data.
	 *
	 * In addition to the normal data contained in the inclusive metrics, the
	 * metrics have an additional 'exclusive' measurement which is the total
	 * minus the totals of all child function calls.
	 *
	 * @return array
	 * @see getRawData()
	 * @see getInclusiveMetrics()
	 */
	public function getCompleteMetrics() {
		if ( $this->complete === null ) {
			// Start with inclusive data
			$this->complete = $this->getInclusiveMetrics();

			foreach ( $this->complete as $func => $stats ) {
				foreach ( $stats as $stat => $value ) {
					if ( $stat === 'ct' ) {
						continue;
					}
					// Initialize exclusive data with inclusive totals
					$this->complete[$func][$stat]['exclusive'] = $value['total'];
				}
				// Add sapce for call tree information to be filled in later
				$this->complete[$func]['calls'] = array();
				$this->complete[$func]['subcalls'] = array();
			}

			foreach( $this->hieraData as $key => $stats ) {
				list( $parent, $child ) = self::splitKey( $key );
				if ( $parent !== null ) {
					// Track call tree information
					$this->complete[$child]['calls'][$parent] = $stats;
					$this->complete[$parent]['subcalls'][$child] = $stats;
				}

				if ( isset( $this->complete[$parent] ) ) {
					// Deduct child inclusive data from exclusive data
					foreach ( $stats as $stat => $value ) {
						if ( $stat === 'ct' ) {
							continue;
						}

						if ( !isset( $this->complete[$parent][$stat] ) ) {
							// Ignore unknown stats
							continue;
						}

						$this->complete[$parent][$stat]['exclusive'] -= $value;
					}
				}
			}

			uasort( $this->complete, self::makeSortFunction(
				$this->config['sort'], 'exclusive'
			) );
		}
		return $this->complete;
	}

	/**
	 * Get a list of all callers of a given function.
	 *
	 * @param string $function Function name
	 * @return array
	 * @see getEdges()
	 */
	public function getCallers( $function ) {
		$edges = $this->getCompleteMetrics();
		if ( isset( $edges[$function]['calls'] ) ) {
			return array_keys( $edges[$function]['calls'] );
		} else {
			return array();
		}
	}

	/**
	 * Get a list of all callees from a given function.
	 *
	 * @param string $function Function name
	 * @return array
	 * @see getEdges()
	 */
	public function getCallees( $function ) {
		$edges = $this->getCompleteMetrics();
		if ( isset( $edges[$function]['subcalls'] ) ) {
			return array_keys( $edges[$function]['subcalls'] );
		} else {
			return array();
		}
	}

	/**
	 * Find the critical path for the given metric.
	 *
	 * @param string $metric Metric to find critical path for
	 * @return array
	 */
	public function getCriticalPath( $metric = 'wt' ) {
		$this->stop();
		$func = 'main()';
		$path = array(
			$func => $this->hieraData[$func],
		);
		while ( $func ) {
			$callees = $this->getCallees( $func );
			$maxCallee = null;
			$maxCall = null;
			foreach ( $callees as $callee ) {
				$call = "{$func}==>{$callee}";
				if ( $maxCall === null ||
					$this->hieraData[$call][$metric] >
						$this->hieraData[$maxCall][$metric]
				) {
					$maxCallee = $callee;
					$maxCall = $call;
				}
			}
			if ( $maxCall !== null ) {
				$path[$maxCall] = $this->hieraData[$maxCall];
			}
			$func = $maxCallee;
		}
		return $path;
	}

	/**
	 * Make a closure to use as a sort function. The resulting function will
	 * sort by descending numeric values (largest value first).
	 *
	 * @param string $key Data key to sort on
	 * @param string $sub Sub key to sort array values on
	 * @return Closure
	 */
	public static function makeSortFunction( $key, $sub ) {
		return function ( $a, $b ) use ( $key, $sub ) {
			if ( isset( $a[$key] ) && isset( $b[$key] ) ) {
				// Descending sort: larger values will be first in result.
				// Assumes all values are numeric.
				// Values for 'main()' will not have sub keys
				$valA = is_array( $a[$key] ) ? $a[$key][$sub] : $a[$key];
				$valB = is_array( $b[$key] ) ? $b[$key][$sub] : $b[$key];
				return $valB - $valA;
			} else {
				// Sort datum with the key before those without
				return isset( $a[$key] ) ? -1 : 1;
			}
		};
	}
}