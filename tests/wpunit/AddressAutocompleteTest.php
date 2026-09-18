<?php

use okoskabet_woocommerce_plugin\Integrations\Address_Autocomplete;

/**
 * The checkout's address suggestions, as the shop's REST routes answer them:
 * off until turned on, the key sent to Økoskabet and never back, every
 * failure an empty answer, and the per-IP limit. Økoskabet is faked through
 * `pre_http_request`. The script's behaviour in the browser is covered by
 * manual checkout testing.
 */
class AddressAutocompleteTest extends \Codeception\TestCase\WPTestCase {

	const KEY      = 'test-api-key-123';
	const BUILDING = 'husnummer:0a3f507b-5ada-32b8-e044-0003ba298018';
	const FLAT     = '0a3f50a2-c234-32b8-e044-0003ba298018';

	/** @var array<int,array{url:string,args:array}> */
	private $requests = array();

	/** @var callable */
	private $fake;

	public function setUp(): void {
		parent::setUp();
		$this->requests            = array();
		$_SERVER['REMOTE_ADDR']    = '192.0.2.' . wp_rand( 1, 250 );
		$this->answer( 200, array( 'suggestions' => array() ) );
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		$this->settings( array( '_api_key' => self::KEY, Address_Autocomplete::SETTING => 'on' ) );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		remove_all_filters( 'okoskabet_address_autocomplete_rate_limit' );
		delete_option( O_TEXTDOMAIN . '-settings' );
		parent::tearDown();
	}

	public function intercept( $pre, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		return call_user_func( $this->fake, $url );
	}

	private function answer( int $status, $body ): void {
		$this->fake = static function () use ( $status, $body ) {
			return array(
				'headers'  => array(),
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'response' => array( 'code' => $status, 'message' => '' ),
				'cookies'  => array(),
			);
		};
	}

	private function settings( array $settings ): void {
		update_option( O_TEXTDOMAIN . '-settings', $settings );
	}

	private function suggest( $q ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/okoskabet/addresses' );
		$request->set_param( 'q', $q );
		return ( new Address_Autocomplete() )->get_suggestions( $request );
	}

	private function details( string $id ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', '/wp/v2/okoskabet/addresses/' . $id );
		$request->set_param( 'address_id', $id );
		return ( new Address_Autocomplete() )->get_address( $request );
	}

	private function building_answer(): array {
		return array(
			'address' => array(
				'id'                 => self::BUILDING,
				'text'               => 'Sankt Nikolaj Vej 15, 1953 Frederiksberg C',
				'street_name'        => 'Sankt Nikolaj Vej',
				'house_number'       => '15',
				'floor'              => null,
				'door'               => null,
				'supplementary_town' => null,
				'postal_code'        => '1953',
				'city'               => 'Frederiksberg C',
				'address_1'          => 'Sankt Nikolaj Vej 15',
				'address_2'          => null,
			),
			'units'   => array(
				array( 'id' => self::FLAT, 'text' => 'Sankt Nikolaj Vej 15, st. tv, 1953 Frederiksberg C' ),
			),
		);
	}

	// ------------------------------------------------------------- setting

	/**
	 * @test
	 */
	public function it_is_off_until_the_shop_turns_it_on() {
		$this->settings( array( '_api_key' => self::KEY ) );
		$this->answer( 200, array( 'suggestions' => array( array( 'id' => self::FLAT, 'text' => 'X' ) ) ) );

		$this->assertFalse( Address_Autocomplete::is_enabled() );
		$this->assertSame( array( 'suggestions' => array() ), $this->suggest( 'Sankt Nikolaj Vej 15' )->get_data() );
		$this->assertNull( $this->details( self::FLAT )->get_data()['address'] );
		$this->assertCount( 0, $this->requests );
	}

	// --------------------------------------------------------- suggestions

	/**
	 * @test
	 */
	public function it_asks_okoskabet_with_the_key_and_passes_on_id_and_text_only() {
		$this->answer( 200, array( 'suggestions' => array(
			array( 'id' => self::FLAT, 'text' => 'Sankt Nikolaj Vej 15, st. tv, 1953 Frederiksberg C', 'extra' => 'dropped' ),
		) ) );

		$response = $this->suggest( "  Sankt   Nikolaj Vej 15 " );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'suggestions' => array( array( 'id' => self::FLAT, 'text' => 'Sankt Nikolaj Vej 15, st. tv, 1953 Frederiksberg C' ) ) ),
			$response->get_data()
		);
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://okoskabet.dk/api/v1/addresses?q=Sankt%20Nikolaj%20Vej%2015', $this->requests[0]['url'] );
		$this->assertSame( self::KEY, $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * @test
	 */
	public function it_follows_the_staging_switch() {
		$this->settings( array( '_api_key' => self::KEY, '_staging_api' => 'on', Address_Autocomplete::SETTING => 'on' ) );

		$this->suggest( 'Sankt Nikolaj Vej 15' );

		$this->assertStringStartsWith( 'https://staging.okoskabet.dk/api/v1/addresses?', $this->requests[0]['url'] );
	}

	/**
	 * @test
	 */
	public function it_does_not_ask_about_fewer_than_three_characters_or_a_non_string() {
		foreach ( array( '', 'Sa', "  S\n", '   Sa   ', array( 'Sankt', 'Nikolaj' ) ) as $q ) {
			$this->assertSame( array( 'suggestions' => array() ), $this->suggest( $q )->get_data() );
		}
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * @test
	 */
	public function every_failure_is_no_suggestions() {
		$failures = array(
			array( 503, array( 'suggestions' => array(), 'error' => 'address_lookup_unavailable' ) ),
			array( 500, 'Internal Server Error' ),
			// Only a 200 is read, whatever else the body looks like.
			array( 502, array( 'suggestions' => array( array( 'id' => self::FLAT, 'text' => 'From an error page' ) ) ) ),
			array( 200, 'not json' ),
			array( 200, array( 'suggestions' => 'nope' ) ),
			array( 200, array( 'suggestions' => array( array( 'id' => '../sheds', 'text' => 'Bad id' ), array( 'text' => 'No id' ) ) ) ),
		);
		foreach ( $failures as $failure ) {
			$this->answer( $failure[0], $failure[1] );
			$response = $this->suggest( 'Sankt Nikolaj Vej 15' );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( array( 'suggestions' => array() ), $response->get_data() );
		}

		$this->fake = static function () {
			return new \WP_Error( 'http_request_failed', 'timed out' );
		};
		$this->assertSame( array( 'suggestions' => array() ), $this->suggest( 'Sankt Nikolaj Vej 15' )->get_data() );
	}

	/**
	 * @test
	 */
	public function it_passes_on_at_most_eight() {
		$hits = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$hits[] = array( 'id' => sprintf( '0a3f50a2-c234-32b8-e044-0003ba2980%02d', $i ), 'text' => "Vej $i" );
		}
		$this->answer( 200, array( 'suggestions' => $hits ) );

		$this->assertCount( 8, $this->suggest( 'Vej 1' )->get_data()['suggestions'] );
	}

	/**
	 * @test
	 */
	public function without_an_api_key_it_does_not_ask() {
		$this->settings( array( Address_Autocomplete::SETTING => 'on' ) );

		$this->assertSame( array( 'suggestions' => array() ), $this->suggest( 'Sankt Nikolaj Vej 15' )->get_data() );
		$this->assertCount( 0, $this->requests );
	}

	// ------------------------------------------------------------- details

	/**
	 * @test
	 */
	public function it_answers_an_address_with_its_fields_and_flats() {
		$this->answer( 200, $this->building_answer() );

		$data = $this->details( self::BUILDING )->get_data();

		$this->assertSame( 'https://okoskabet.dk/api/v1/addresses/husnummer%3A0a3f507b-5ada-32b8-e044-0003ba298018', $this->requests[0]['url'] );
		$this->assertSame( 'Sankt Nikolaj Vej 15', $data['address']['address_1'] );
		$this->assertNull( $data['address']['address_2'] );
		$this->assertSame( '1953', $data['address']['postal_code'] );
		$this->assertSame( 'Frederiksberg C', $data['address']['city'] );
		$this->assertSame( array( array( 'id' => self::FLAT, 'text' => 'Sankt Nikolaj Vej 15, st. tv, 1953 Frederiksberg C' ) ), $data['units'] );
		$this->assertArrayNotHasKey( 'street_name', $data['address'] );
	}

	/**
	 * @test
	 */
	public function a_flat_carries_its_floor_and_door() {
		$answer                         = $this->building_answer();
		$answer['address']['address_2'] = '2. th';
		$answer['units']                = array();
		$this->answer( 200, $answer );

		$data = $this->details( self::FLAT )->get_data();

		$this->assertSame( '2. th', $data['address']['address_2'] );
		$this->assertSame( array(), $data['units'] );
	}

	/**
	 * @test
	 */
	public function an_id_that_is_not_an_address_is_not_looked_up() {
		foreach ( array( 'adr-1', '..', 'husnummer:nope', self::FLAT . 'x', 'vej:' . self::FLAT ) as $id ) {
			$this->assertSame( 404, $this->details( $id )->get_status() );
		}
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * @test
	 */
	public function details_okoskabet_cannot_give_are_no_address() {
		foreach ( array( array( 404, array( 'error' => 'not_found' ) ), array( 503, array( 'error' => 'address_lookup_unavailable' ) ), array( 200, array( 'address' => array( 'address_1' => '' ) ) ) ) as $failure ) {
			$this->answer( $failure[0], $failure[1] );
			$response = $this->details( self::FLAT );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( array( 'address' => null, 'units' => array() ), $response->get_data() );
		}
	}

	// ----------------------------------------------------------- rate limit

	/**
	 * @test
	 */
	public function one_ip_gets_sixty_requests_a_minute() {
		for ( $i = 0; $i < Address_Autocomplete::RATE_LIMIT_PER_MINUTE; $i++ ) {
			$this->assertSame( 200, $this->suggest( 'Sankt Nikolaj Vej 15' )->get_status(), "request $i" );
		}

		$limited = $this->suggest( 'Sankt Nikolaj Vej 15' );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( array(), $limited->get_data()['suggestions'] );
		$this->assertSame( 429, $this->details( self::FLAT )->get_status() );
		$this->assertCount( Address_Autocomplete::RATE_LIMIT_PER_MINUTE, $this->requests );

		// Another visitor is not affected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 200, $this->suggest( 'Sankt Nikolaj Vej 15' )->get_status() );
	}

	/**
	 * @test
	 */
	public function the_allowance_is_per_minute() {
		add_filter( 'okoskabet_address_autocomplete_rate_limit', static function () {
			return 2;
		} );
		$minute = 60 * 1000000;

		$this->assertFalse( Address_Autocomplete::is_rate_limited( $minute ) );
		$this->assertFalse( Address_Autocomplete::is_rate_limited( $minute + 59 ) );
		$this->assertTrue( Address_Autocomplete::is_rate_limited( $minute + 30 ) );
		$this->assertFalse( Address_Autocomplete::is_rate_limited( $minute + 60 ) );
	}
}
