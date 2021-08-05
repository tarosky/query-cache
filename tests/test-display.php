<?php

/**
 * Display related tests.
 *
 * @package query-cache
 */
class DisplayTest extends WP_UnitTestCase {

	/**
	 * Test title functions
	 */
	public function test_title() {
		$this->assertNotEmpty( 'title' );
	}

}
