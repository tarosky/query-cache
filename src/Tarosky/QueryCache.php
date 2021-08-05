<?php

namespace Tarosky;


use Tarosky\QueryCache\Pattern\Singleton;

/**
 * Query cache.
 *
 * @package query-cache
 * @property-read  string $cache_group            Cache group name.
 * @property-read  string $cache_group_found_rows Cache group name.
 */
class QueryCache extends Singleton {

	/**
	 * @var string Group base.
	 */
	protected $cache_group_base = 'ts_query_cache';

	/**
	 * @var string Cache key.
	 * @deprectated
	 */
	private $CACHE_GROUP_PREFIX = 'advanced_post_cache_';

	// Flag for temp (within one page load) turning invalidations on and off
	// @see dont_clear_advanced_post_cache()
	// @see do_clear_advanced_post_cache()
	// Used to prevent invalidation during new comment
	var $do_flush_cache = true;

	// Flag for preventing multiple invalidations in a row: clean_post_cache() calls itself recursively for post children.
	var $need_to_flush_cache = true; // Currently disabled

	/* Per cache-clear data */
	var $cache_incr = 0; // Increments the cache group (advanced_post_cache_0, advanced_post_cache_1, ...)

	/* Per query data */
	var $cache_key = ''; // md5 of current SQL query
	var $all_post_ids = false; // IDs of all posts current SQL query returns
	var $cached_post_ids = array(); // subset of $all_post_ids whose posts are currently in cache
	var $cached_posts = array();
	var $found_posts = false; // The result of the FOUND_ROWS() query
	var $cache_func = 'wp_cache_add'; // Turns to set if there seems to be inconsistencies

	protected $post_type_not_to_cache = [];

	/**
	 * If post types are in list, skip.
	 *
	 * @return string[]
	 */
	protected function post_types_no_cache() {
		return apply_filters( 'ts_query_cache_post_type_not_cached', $this->post_type_not_to_cache );
	}

	/**
	 * Detect if the query requires found_rows.
	 *
	 * @param \WP_Query $wp_query Query object.
	 * @return bool
	 */
	protected function need_found_rows( $wp_query ) {
		if ( $wp_query->query_vars['no_found_rows'] || ( is_array( $wp_query->posts ) && ! $wp_query->posts ) ) {
			return false;
		}

		return false !== preg_match( '/[ \t\n]LIMIT[ \t\n\r]/us', $wp_query->request );
	}

	/**
	 * Get found rows if query set.
	 *
	 * @param \WP_Query $wp_query Query object.
	 * @return int|false
	 */
	protected function get_found_rows( $wp_query ) {
		$key   = md5( $wp_query->request );
		$group = $this->cache_group_found_rows;
		return wp_cache_get( $key, $group );
	}

	/**
	 * Save cached result.
	 *
	 * @param int       $rows
	 * @param \WP_Query $wp_query
	 */
	protected function save_found_rows( $rows, $wp_query ) {
		if ( ! $this->need_found_rows( $wp_query ) ) {
			return;
		}
		$key   = md5( $wp_query->request );
		$group = $this->cache_group_found_rows;
		wp_cache_set( $key, $rows, $group, $this->cache_time( $wp_query ) );
	}

	/**
	 * Detect if this query should be cached.
	 *
	 * @param \WP_Query $wp_query
	 * @return bool
	 */
	protected function should_cache( $wp_query ) {
		$should_cache = true;
		if ( is_admin() ) {
			$should_cache = false;
		} elseif ( $wp_query->get( 'no_query_cache' ) ) {
			// Explicitly no cache.
			$should_cache = false;
		} elseif ( $wp_query->is_search() ) {
			// Not cache search.
			$should_cache = false;
		} elseif ( $wp_query->get( 'suppress_filters' ) ) {
			// Filter is not registered.
			$should_cache = false;
		} else {
			$post_types = (array) $wp_query->get( 'post_type' );
			$post_type_not_to_cache = $this->post_types_no_cache();
			$filtered   = array_filter( $post_types, function( $post_type ) use ( $post_type_not_to_cache ) {
				return ! in_array( $post_type, $post_type_not_to_cache, true );
			} );
			if ( count( $post_types ) !== count( $filtered ) ) {
				$should_cache = false;
			}
		}
		return (bool) apply_filters( 'ts_query_cache_should_cache', $should_cache, $wp_query );
	}

	/**
	 * Cache life time.
	 *
	 * @param \WP_Query $wp_query Query object.
	 * @return int Cache life time in second.
	 */
	protected function cache_time( $wp_query ) {
		if ( $wp_query->is_main_query() ) {
			$time = 300;
		} else {
			$time = 600;
		}
		return (int) apply_filters( 'tts_query_cache_life_time', $time, $wp_query );
	}

	/**
	 * @inheritDoc
	 */
	protected function init() {
		// If object cache not exists, do nothing.
		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		add_filter( 'posts_pre_query', [ $this, 'pre_query' ], 10, 2 );
		add_filter( 'found_posts_query', [ $this, 'found_posts_query' ], 10, 2 );
	}

	/**
	 * Cache if request is proper.
	 *
	 * @param \stdClass[]|null $posts    Post results from DB.
	 * @param \WP_Query        $wp_query Query object.
	 * @return \stdClass[]|null
	 */
	public function pre_query( $posts, $wp_query ) {
		if ( ! $this->should_cache( $wp_query ) ) {
			return $posts;
		}
		$query     = $wp_query->request;
		$cache_key = md5( $query );
		$stored    = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $stored ) {
			// Cache exists.
			return $stored;
		} else {
			global $wpdb;
			// No cache.
			$lifetime = $this->cache_time( $wp_query );
			if ( ! $lifetime ) {
				// No cache life tiime, returns original.
				return $posts;
			}
			switch ( $wp_query->get( 'fields' ) ) {
				case 'ids':
					$posts = $wpdb->get_col( $wp_query->request );
					break;
				default:
					$posts = $wpdb->get_results( $wp_query->request );
					break;
			}
			// Save results.
			wp_cache_set( $cache_key, $posts, $this->cache_group, $lifetime );
			// Save found rows.
			$this->save_found_rows( (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' ), $wp_query );
		}

		return $posts;
	}

	/**
	 * Override found rows.
	 *
	 * @param string    $sql Original SQL.
	 * @param \WP_Query $wp_query WP_Query object.
	 * @return string
	 */
	function found_posts_query( $sql, $wp_query ) {
		global $wpdb;
		if ( ! $this->need_found_rows( $wp_query ) ) {
			return $sql;
		}
		$cache = $this->get_found_rows( $wp_query );
		if ( false !== $cache ) {
			$sql = $wpdb->prepare( 'SELECT %d', $cache );
		}
		return $sql;
	}

	/**
	 * Getter.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function __get( $name ) {
		switch ( $name ) {
			case 'cache_group':
				return $this->cache_group_base . '_' . get_current_blog_id();
			case 'cache_group_found_rows':
				return $this->cache_group . '_rows';
			default:
				return null;
		}
	}

}
