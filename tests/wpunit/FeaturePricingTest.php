<?php

use okoskabet_woocommerce_plugin\Integrations\Feature_Pricing;

/**
 * The pricing panel's pure parts: how a back-office answer is normalised, and
 * how a price is written out for a merchant to read. The HTTP call itself is
 * covered by using the plugin against a real back office.
 */
class FeaturePricingTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
		delete_option( Feature_Pricing::CACHE_OPTION );
		Feature_Pricing::purge();
	}

	public function tearDown(): void {
		delete_option( Feature_Pricing::CACHE_OPTION );
		Feature_Pricing::purge();
		parent::tearDown();
	}

	/** normalise_features() is private; reach it the way get_pricing() does. */
	private function normalise( array $features ): array {
		$method = new ReflectionMethod( Feature_Pricing::class, 'normalise_features' );
		$method->setAccessible( true );
		return $method->invoke( null, $features );
	}

	// ------------------------------------------------------------- normalising

	/**
	 * @test
	 * A back office that has not shipped the features block yet is not an
	 * error — there is simply nothing priced.
	 */
	public function a_missing_features_block_is_an_empty_list_not_a_crash() {
		$this->assertSame( array(), $this->normalise( array() ) );
		$this->assertSame( array(), $this->normalise( array( 'nonsense', 42 ) ) );
	}

	/**
	 * @test
	 */
	public function it_drops_entries_with_no_code() {
		$this->assertCount( 1, $this->normalise( array(
			array( 'name' => 'Nameless' ),
			array( 'code' => 'pakkeri', 'name' => 'Pakkeri' ),
		) ) );
	}

	/**
	 * @test
	 * A feature with no price is not a feature costing zero — the panel says
	 * so in words, so the distinction has to survive normalising.
	 */
	public function a_feature_without_a_price_keeps_a_null_price() {
		$features = $this->normalise( array( array( 'code' => 'free_thing' ) ) );
		$this->assertNull( $features[0]['price'] );
	}

	/**
	 * @test
	 * `to: null` is the open-ended top tier and must not collapse to 0.
	 */
	public function the_top_tier_stays_open_ended() {
		$features = $this->normalise( array(
			array(
				'code'  => 'labels',
				'price' => array(
					'unit'  => 'label',
					'tiers' => array(
						array( 'from' => 1, 'to' => 100, 'unit_price' => '1.00' ),
						array( 'from' => 101, 'to' => null, 'unit_price' => '0.80' ),
					),
				),
			),
		) );

		$tiers = $features[0]['price']['tiers'];
		$this->assertSame( 100, $tiers[0]['to'] );
		$this->assertNull( $tiers[1]['to'] );
	}

	// -------------------------------------------------------------- formatting

	/**
	 * @test
	 */
	public function no_price_produces_no_lines() {
		$this->assertSame( array(), Feature_Pricing::price_lines( null, 'DKK' ) );
	}

	/**
	 * @test
	 */
	public function a_flat_price_is_one_line_with_its_unit() {
		$lines = Feature_Pricing::price_lines(
			array( 'unit' => 'label', 'amount' => '2.00', 'tiers' => array() ),
			'DKK'
		);

		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'DKK', $lines[0] );
		$this->assertStringContainsString( 'label', $lines[0] );
	}

	/**
	 * @test
	 * One line per tier step, because that is how the invoice will bill it.
	 */
	public function a_ladder_is_one_line_per_step() {
		$lines = Feature_Pricing::price_lines(
			array(
				'unit'  => 'label',
				'amount' => null,
				'tiers' => array(
					array( 'from' => 1, 'to' => 100, 'unit_price' => '1.00' ),
					array( 'from' => 101, 'to' => null, 'unit_price' => '0.80' ),
				),
			),
			'DKK'
		);

		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( '100', $lines[0] );
		$this->assertStringContainsString( '101', $lines[1] );
	}

	/**
	 * @test
	 * Amounts arrive as decimal strings on purpose. Something unparseable is
	 * shown as-is rather than silently becoming 0,00 — a wrong price a
	 * merchant believes is worse than one they can see is broken.
	 */
	public function an_unparseable_amount_is_left_alone() {
		$lines = Feature_Pricing::price_lines(
			array( 'unit' => '', 'amount' => 'efter aftale', 'tiers' => array() ),
			'DKK'
		);

		$this->assertSame( array( 'efter aftale' ), $lines );
	}
}
