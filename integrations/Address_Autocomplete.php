<?php

/**
 * okoskabet_woocommerce_plugin
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Foodshipper <kontakt@okoskabet.dk>
 * @copyright 2026 Foodshipper
 * @license   GPL 2.0+
 * @link      https://okoskabet.dk
 */

namespace okoskabet_woocommerce_plugin\Integrations;

use okoskabet_woocommerce_plugin\Engine\Base;

/**
 * Address suggestions in the classic checkout.
 *
 * A customer typing their address on one free line is where the addresses
 * Økoskabet cannot deliver to come from. Økoskabet answers what has been typed
 * with addresses from the Danish address register (`/api/v1/addresses`), and
 * this class is the shop's side of that: two REST routes the checkout script
 * calls, which forward to Økoskabet with the shop's API key, and the script
 * itself, enqueued only while the shop has turned the feature on.
 *
 * The key never reaches the browser — the browser only ever talks to the
 * shop. Nothing here can stop a customer from checking out: every failure,
 * including the feature being off, is answered as "no suggestions", and the
 * customer carries on typing as before.
 */
class Address_Autocomplete extends Base {

	/** The checkbox in the plugin settings. Off until the shop turns it on. */
	const SETTING = '_address_autocomplete';

	/** Requests one visitor's IP may make per minute, suggestions and details together. */
	const RATE_LIMIT_PER_MINUTE = 60;

	/** Seconds to wait for Økoskabet. The customer is typing; a late answer is no answer. */
	const TIMEOUT = 4;

	/** Below this many characters Økoskabet answers with nothing, so we don't ask. */
	const MIN_QUERY_LENGTH = 3;

	/** Longer than any Danish address; anything past it is not an address. */
	const MAX_QUERY_LENGTH = 200;

	/** Most suggestions passed on, matching what Økoskabet sends. */
	const MAX_SUGGESTIONS = 8;

	/**
	 * The ids Økoskabet hands out: a register uuid, tagged when it names a
	 * building. Checked before one goes into a path, so a crafted id cannot
	 * reach anything else on Økoskabet's side.
	 */
	const ADDRESS_ID_PATTERN = '/\A(?:husnummer:)?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

	public function initialize() {
		parent::initialize();

		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		\add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the shop has turned address suggestions on.
	 */
	public static function is_enabled(): bool {
		$settings = \o_get_settings();
		return ! empty( $settings[ self::SETTING ] );
	}

	public function register_routes(): void {
		\register_rest_route(
			'wp/v2',
			'okoskabet/addresses',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'get_suggestions' ),
				'args'                => array(
					'q' => array( 'required' => false ),
				),
			)
		);

		\register_rest_route(
			'wp/v2',
			'okoskabet/addresses/(?P<address_id>[A-Za-z0-9:\-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'get_address' ),
			)
		);
	}

	/**
	 * The script, its styles and what it needs to know — on the checkout page
	 * only, and only while the feature is on. The script looks for the address
	 * fields itself and does nothing on a checkout that has none (the block
	 * checkout, or the order-received page).
	 */
	public function enqueue(): void {
		if ( ! \function_exists( 'is_checkout' ) || ! \is_checkout() || ! self::is_enabled() ) {
			return;
		}

		\wp_enqueue_style( 'okoskabet-address-autocomplete', \oko_build_asset_url( 'address-autocomplete', 'css' ), array(), O_VERSION );
		\wp_register_script( 'okoskabet-address-autocomplete', \oko_build_asset_url( 'address-autocomplete', 'js' ), array( 'jquery' ), O_VERSION, true );

		$config = array(
			// The details URL carries a placeholder for the id rather than being
			// built in the script, so it works with plain permalinks too
			// (?rest_route=…), where appending a path segment would not.
			'suggestUrl' => \get_rest_url( null, 'wp/v2/okoskabet/addresses' ),
			'addressUrl' => \get_rest_url( null, 'wp/v2/okoskabet/addresses/__ID__' ),
			'strings'    => array(
				'suggestions' => \__( 'Address suggestions', O_TEXTDOMAIN ),
				'chooseUnit'  => \__( 'Choose apartment', O_TEXTDOMAIN ),
				'noUnit'      => \__( 'No apartment / the whole house', O_TEXTDOMAIN ),
				/* translators: %d = number of suggestions */
				'count'       => \__( '%d suggestions. Use the up and down arrows to choose.', O_TEXTDOMAIN ),
				'oneCount'    => \__( '1 suggestion. Use the up and down arrows to choose.', O_TEXTDOMAIN ),
				/* translators: %d = number of apartments */
				'unitCount'   => \__( '%d apartments. Use the up and down arrows to choose.', O_TEXTDOMAIN ),
				'filled'      => \__( 'The address has been filled in.', O_TEXTDOMAIN ),
			),
		);

		\wp_add_inline_script(
			'okoskabet-address-autocomplete',
			'window._okoskabet_address_autocomplete = ' . \wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);
		\wp_enqueue_script( 'okoskabet-address-autocomplete' );
	}

	// =========================================================================
	// REST
	// =========================================================================

	/**
	 * `GET /wp-json/wp/v2/okoskabet/addresses?q=…` — always 200 with a list,
	 * possibly empty, except 429 when this visitor has asked too often.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_suggestions( \WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return self::suggestions_response( array() );
		}

		$limited = self::rate_limited_response();
		if ( $limited ) {
			return $limited;
		}

		$query = self::normalise_query( $request->get_param( 'q' ) );
		if ( self::query_length( $query ) < self::MIN_QUERY_LENGTH ) {
			return self::suggestions_response( array() );
		}

		$body = self::ask_okoskabet( '/api/v1/addresses?' . \http_build_query( array( 'q' => $query ), '', '&', PHP_QUERY_RFC3986 ) );

		return self::suggestions_response( self::parse_suggestions( $body ) );
	}

	/**
	 * `GET /wp-json/wp/v2/okoskabet/addresses/{id}` — the chosen address split
	 * into checkout fields, plus the flats in its building. 404 for an id that
	 * is not an address; `{"address": null, "units": []}` when Økoskabet could
	 * not answer, so the script leaves the fields as the customer typed them.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_address( \WP_REST_Request $request ) {
		$empty = array(
			'address' => null,
			'units'   => array(),
		);

		if ( ! self::is_enabled() ) {
			return self::no_store( new \WP_REST_Response( $empty, 200 ) );
		}

		$limited = self::rate_limited_response();
		if ( $limited ) {
			return $limited;
		}

		$id = (string) $request->get_param( 'address_id' );
		if ( ! self::is_address_id( $id ) ) {
			return self::no_store( new \WP_REST_Response( array( 'error' => 'not_found' ), 404 ) );
		}

		$body    = self::ask_okoskabet( '/api/v1/addresses/' . \rawurlencode( $id ) );
		$details = self::parse_address( $body );

		return self::no_store( new \WP_REST_Response( $details ?? $empty, 200 ) );
	}

	// =========================================================================
	// Pieces, public so they can be tested without WordPress' HTTP layer
	// =========================================================================

	/**
	 * What the customer typed, trimmed and with runs of whitespace as one
	 * space — the same normalising Økoskabet does, so the length checked here
	 * is the length checked there. Anything that isn't a string is no query.
	 *
	 * @param mixed $raw
	 */
	public static function normalise_query( $raw ): string {
		if ( ! \is_string( $raw ) ) {
			return '';
		}
		$query = \trim( (string) \preg_replace( '/\s+/u', ' ', $raw ) );
		if ( self::query_length( $query ) > self::MAX_QUERY_LENGTH ) {
			$query = \function_exists( 'mb_substr' ) ? \mb_substr( $query, 0, self::MAX_QUERY_LENGTH ) : \substr( $query, 0, self::MAX_QUERY_LENGTH );
		}
		return $query;
	}

	private static function query_length( string $query ): int {
		return \function_exists( 'mb_strlen' ) ? \mb_strlen( $query ) : \strlen( $query );
	}

	public static function is_address_id( string $id ): bool {
		return (bool) \preg_match( self::ADDRESS_ID_PATTERN, $id );
	}

	/**
	 * Økoskabet's suggestions, reduced to id and text. Anything else — an
	 * error body, a 503, no body at all — is no suggestions.
	 *
	 * @param array|null $body Decoded JSON, or null when there was no usable answer.
	 * @return array<int,array{id:string,text:string}>
	 */
	public static function parse_suggestions( ?array $body ): array {
		if ( ! \is_array( $body ) || ! isset( $body['suggestions'] ) || ! \is_array( $body['suggestions'] ) ) {
			return array();
		}
		return \array_slice( self::parse_hits( $body['suggestions'] ), 0, self::MAX_SUGGESTIONS );
	}

	/**
	 * Økoskabet's answer for one address, reduced to the fields the checkout
	 * fills. Null when the answer is not an address.
	 *
	 * @param array|null $body Decoded JSON.
	 * @return array{address:array<string,string|null>,units:array<int,array{id:string,text:string}>}|null
	 */
	public static function parse_address( ?array $body ): ?array {
		if ( ! \is_array( $body ) || empty( $body['address'] ) || ! \is_array( $body['address'] ) ) {
			return null;
		}
		$raw = $body['address'];

		$address = array();
		foreach ( array( 'id', 'text', 'address_1', 'address_2', 'postal_code', 'city' ) as $field ) {
			$value             = $raw[ $field ] ?? null;
			$address[ $field ] = \is_scalar( $value ) && \trim( (string) $value ) !== '' ? \trim( (string) $value ) : null;
		}

		// Without a street line there is nothing to fill in.
		if ( $address['address_1'] === null ) {
			return null;
		}

		return array(
			'address' => $address,
			'units'   => isset( $body['units'] ) && \is_array( $body['units'] ) ? self::parse_hits( $body['units'] ) : array(),
		);
	}

	/**
	 * @param array<mixed> $hits
	 * @return array<int,array{id:string,text:string}>
	 */
	private static function parse_hits( array $hits ): array {
		$out = array();
		foreach ( $hits as $hit ) {
			if ( ! \is_array( $hit ) || ! isset( $hit['id'], $hit['text'] ) || ! \is_string( $hit['id'] ) || ! \is_string( $hit['text'] ) ) {
				continue;
			}
			if ( ! self::is_address_id( $hit['id'] ) || \trim( $hit['text'] ) === '' ) {
				continue;
			}
			$out[] = array(
				'id'   => $hit['id'],
				'text' => \trim( $hit['text'] ),
			);
		}
		return $out;
	}

	/**
	 * Count this request against its IP's allowance for the current minute.
	 * True when the allowance is already spent.
	 *
	 * A fixed one-minute window in a transient: coarse, and two requests
	 * racing can both slip in, but it only has to stop a script from using
	 * the shop as a free address lookup, not meter customers exactly.
	 *
	 * The IP is REMOTE_ADDR only. A forwarded-for header is whatever the
	 * client says it is, so honouring it would let anyone reset their own
	 * limit. Behind a proxy that hides visitors' addresses, all visitors
	 * share one allowance — see the filter.
	 */
	public static function is_rate_limited( ?int $now = null ): bool {
		$limit = (int) \apply_filters( 'okoskabet_address_autocomplete_rate_limit', self::RATE_LIMIT_PER_MINUTE );
		if ( $limit <= 0 ) {
			return false;
		}

		$now    = $now ?? \time();
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never output.
		$window = (int) \floor( $now / 60 );
		$key    = 'oko_addr_rl_' . \md5( $ip . '|' . $window );

		$count = (int) \get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		\set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * @return \WP_REST_Response|null
	 */
	private static function rate_limited_response() {
		if ( ! self::is_rate_limited() ) {
			return null;
		}
		$response = new \WP_REST_Response(
			array(
				'suggestions' => array(),
				'error'       => 'rate_limited',
			),
			429
		);
		$response->header( 'Retry-After', '60' );
		return self::no_store( $response );
	}

	/**
	 * GET a path on Økoskabet's API with the default merchant's key, honouring
	 * its staging switch. Addresses are the same whichever merchant a cart
	 * routes to, so there is no cart to resolve. Null for anything but a 200
	 * with a JSON object.
	 *
	 * @return array|null
	 */
	private static function ask_okoskabet( string $path ): ?array {
		$merchant = \o_get_merchant();
		if ( empty( $merchant['api_key'] ) ) {
			return null;
		}

		$response = \wp_remote_get(
			\o_merchant_api_url( $merchant ) . $path,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => $merchant['api_key'],
					'Accept'        => 'application/json',
				),
			)
		);

		if ( \is_wp_error( $response ) || (int) \wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$decoded = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		return \is_array( $decoded ) ? $decoded : null;
	}

	private static function suggestions_response( array $suggestions ): \WP_REST_Response {
		return self::no_store( new \WP_REST_Response( array( 'suggestions' => $suggestions ), 200 ) );
	}

	/**
	 * What a customer typed is theirs; no page cache should keep it.
	 */
	private static function no_store( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
