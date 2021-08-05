<?php

namespace Tarosky\QueryCache\Pattern;


/**
 * Singleton pattern
 *
 * @package query-cache
 */
abstract class Singleton {

	/**
	 * @var static[] Array of instances.
	 */
	private static $instances = [];

	/**
	 * Constructor.
	 */
	final protected function __construct() {
		$this->init();
	}

	/**
	 * Executed inside constructor.
	 */
	protected function init() {
		// Do something.
	}

	/**
	 * Create constructor.
	 *
	 * @return static
	 */
	final public static function get_instance() {
		$class_name = get_called_class();
		if ( ! isset( self::$instances[ $class_name ] ) ) {
			self::$instances[ $class_name ] = new $class_name();
		}
		return self::$instances[ $class_name ];
	}
}
