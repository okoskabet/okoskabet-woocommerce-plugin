<?php
/**
 * GuideCoversEverySettingTest: the installation guide names every setting.
 *
 * The guide is written for shop owners and lives in Økoskabet's backoffice
 * (delivery_backend, priv/guides/woocommerce-plugin/), where the plugin's
 * settings are out of reach of any test. This is the reminder from this side:
 * docs/guide-indstillinger.txt lists every setting the guide describes, and
 * this test reads the fields out of the source and fails when the two differ.
 *
 * Adding a setting therefore means: describe it in the guide in
 * delivery_backend, under a marker `<!-- setting: id -->` and with
 * "**Fra version x.**", and add the id to docs/guide-indstillinger.txt. A
 * removed setting goes out of both.
 *
 * How a field is named in the list and in the guide's markers:
 *
 *   - CMB2 fields on the main page, and WooCommerce's own field helpers, by
 *     their `'id'`: `_api_key`, `okoskabet_free_packaging`.
 *   - Form fields in an extra's panel by their `name`, with a row number
 *     written `[]`, and — when the panel saves into an option of its own — that
 *     option's key in front: `okoskabet_packaging_fee:rules[][amount]`.
 *   - Shipping-method fields as `shipping:` and the key, once for all three
 *     methods, since they share them: `shipping:tiers`.
 *
 * No WordPress is loaded for any of this; it is text in, text out.
 *
 * @package okoskabet_woocommerce_plugin
 */

// phpcs:disable

/** The plugin's own folder. */
function oko_guide_root(): string {
	return dirname( __DIR__, 2 );
}

/** A file in the plugin, as text, or a failure saying which one is missing. */
function oko_guide_source( string $relative ): string {
	$path = oko_guide_root() . '/' . $relative;
	$text = is_readable( $path ) ? file_get_contents( $path ) : false;
	if ( $text === false ) {
		fail( "cannot read $relative" );
	}

	return $text;
}

/**
 * A string constant, read out of the integration that defines it.
 *
 * Only for ids written as `\...\Some_Class::NAME` in the settings page. Read as
 * text rather than loaded, because loading an integration means loading what it
 * leans on, and none of that is needed to read one line.
 */
function oko_guide_constant( string $class, string $name ): string {
	$short = substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
	$src   = oko_guide_source( 'integrations/' . $short . '.php' );
	if ( ! preg_match( '/const\s+' . preg_quote( $name, '/' ) . '\s*=\s*\'([^\']+)\'/', $src, $m ) ) {
		fail( "cannot find $class::$name" );
	}

	return $m[1];
}

/**
 * Every CMB2 field on the main settings page, except the headings.
 *
 * A `title` field is a heading or a read-only status line, nothing to fill in.
 *
 * @return string[]
 */
function oko_guide_main_page_ids(): array {
	$chunks = preg_split( '/->add_field\s*\(/', oko_guide_source( 'backend/views/settings.php' ) );
	array_shift( $chunks );

	$ids = array();
	foreach ( $chunks as $chunk ) {
		if ( ! preg_match( "/'id'\s*=>\s*([^,\n]+),/", $chunk, $id ) || ! preg_match( "/'type'\s*=>\s*'(\w+)'/", $chunk, $type ) ) {
			fail( 'a field on the settings page has no readable id or type: ' . substr( trim( $chunk ), 0, 80 ) );
		}
		if ( $type[1] === 'title' ) {
			continue;
		}

		$expression = trim( $id[1] );
		if ( preg_match( "/^'([^']+)'$/", $expression, $literal ) ) {
			$ids[] = $literal[1];
		} elseif ( preg_match( '/^\\\\?([\w\\\\]+)::(\w+)$/', $expression, $constant ) ) {
			$ids[] = oko_guide_constant( $constant[1], $constant[2] );
		} else {
			fail( "the settings page names a field in a way this test cannot read: $expression" );
		}
	}

	return $ids;
}

/**
 * Names a panel prints that are not settings, and why.
 *
 * Hidden inputs are left out by their type and need no entry here.
 */
const OKO_GUIDE_NOT_SETTINGS = array(
	// The customer's choice in the split-delivery banner at checkout.
	'oko_split_remove_option',
);

/**
 * Every field the integrations print for a shop to fill in.
 *
 * Read from the form controls in each file. A name built from a variable is
 * resolved from the place that gives the variable its values; one this test
 * has not been taught fails, naming the file, rather than being skipped.
 *
 * @return string[]
 */
function oko_guide_integration_ids(): array {
	$ids = array();

	foreach ( glob( oko_guide_root() . '/integrations/*.php' ) as $path ) {
		$file = 'integrations/' . basename( $path );
		$src  = oko_guide_source( $file );

		$prefix = preg_match( "/const\s+OPTION_KEY\s*=\s*'([^']+)'/", $src, $m ) ? $m[1] . ':' : '';

		// WooCommerce's own field helpers, as on a coupon: named by their id.
		if ( preg_match_all( "/woocommerce_wp_\w+\(\s*array\(\s*'id'\s*=>\s*'([^']+)'/", $src, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[] = $id;
			}
		}

		// The PHP printed inside the markup, one line at a time, set aside so
		// the tags around it can be read. Kept, because a name built from a
		// variable is resolved by what the variable is.
		$segments = array();
		$markup   = preg_replace_callback(
			'/<\?php[^\n]*?\?>/',
			static function ( array $m ) use ( &$segments ): string {
				$segments[] = $m[0];
				return "\x01" . ( count( $segments ) - 1 ) . "\x02";
			},
			$src
		);

		preg_match_all( '/<(?:input|select|textarea)\b([^>]*)>/', $markup, $tags );
		foreach ( $tags[1] as $attributes ) {
			if ( preg_match( '/\btype="hidden"/', $attributes ) || ! preg_match( '/\bname="([^"]*)"/', $attributes, $n ) ) {
				continue;
			}

			// A row number is not part of the setting.
			$name = preg_replace( "/\[\x01\d+\x02\]/", '[]', $n[1] );

			if ( strpbrk( $name, "\x01$'" ) === false ) {
				if ( ! in_array( $name, OKO_GUIDE_NOT_SETTINGS, true ) ) {
					$ids[] = $prefix . $name;
				}
				continue;
			}

			// Built from a variable: which one decides where its values live.
			$code = preg_replace_callback(
				"/\x01(\d+)\x02/",
				static function ( array $m ) use ( $segments ): string {
					return $segments[ (int) $m[1] ];
				},
				$name
			);

			foreach ( oko_guide_resolve( $file, $src, $code ) as $resolved ) {
				$ids[] = $prefix . $resolved;
			}
		}
	}

	return $ids;
}

/**
 * The names a field built from a variable takes.
 *
 * @return string[]
 */
function oko_guide_resolve( string $file, string $src, string $code ): array {
	// A helper handed the whole name, e.g. render_term_select( "rules[$index][tags][]" ):
	// every call of every helper in the file that takes `string $name` first.
	if ( strpos( $code, '$name' ) !== false ) {
		preg_match_all( '/function\s+(\w+)\(\s*string\s+\$name\b/', $src, $helpers );
		$names = array();
		foreach ( $helpers[1] as $helper ) {
			preg_match_all( '/->' . $helper . '\(\s*(["\'])(.*?)\1/', $src, $calls );
			foreach ( $calls[2] as $called ) {
				$names[] = preg_replace( '/\[\$\w+\]/', '[]', $called );
			}
		}
		if ( $names === array() ) {
			fail( "$file builds a field name from \$name, but no call hands one in" );
		}

		return $names;
	}

	// The per-section limit — the section's name echoed in front of
	// `_limit_mode` — for every section the panel calls it with.
	if ( strpos( $code, '$section' ) !== false && preg_match( '/\?>(_\w+)$/', $code, $suffix ) ) {
		preg_match_all( "/render_section_limit_control\(\s*\\\$config\s*,\s*'(\w+)'\s*\)/", $src, $sections );
		if ( $sections[1] === array() ) {
			fail( "$file builds a field name from \$section, but no section is named" );
		}

		return array_map(
			static function ( string $section ) use ( $suffix ): string {
				return $section . $suffix[1];
			},
			$sections[1]
		);
	}

	// The split-delivery texts, one field per key the panel owns.
	if ( strpos( $code, '$key' ) !== false && preg_match( '/const\s+SETTING_KEYS\s*=\s*array\((.*?)\);/s', $src, $keys ) ) {
		preg_match_all( "/'([^']+)'/", $keys[1], $names );

		return $names[1];
	}

	fail( "$file prints a field whose name this test cannot work out: $code" );
}

/**
 * Every field of the three Økoskabet shipping methods, as `shipping:<key>`.
 *
 * They share one set of fields, built by oko_shipping_instance_fields(), and a
 * method may add its own on top (home delivery adds `allow_without_date`).
 *
 * @return string[]
 */
function oko_guide_shipping_method_ids(): array {
	$src = oko_guide_source( 'functions/functions.php' );

	if ( ! preg_match( '/function oko_shipping_instance_fields\(.*?\n\}/s', $src, $shared_body ) ) {
		fail( 'cannot find oko_shipping_instance_fields()' );
	}
	preg_match_all( "/^\t\t'(\w+)'\s*=>\s*array\(/m", $shared_body[0], $shared );

	preg_match_all( '/\$this->id\s*=\s*\'hey_okoskabet_shipping_\w+\'/', $src, $methods );
	preg_match_all( '/\$this->instance_form_fields\s*=\s*(.*?);/s', $src, $statements );
	if ( count( $methods[0] ) === 0 || count( $methods[0] ) !== count( $statements[1] ) ) {
		fail( sprintf( 'expected one field list per Økoskabet shipping method, found %d methods and %d lists', count( $methods[0] ), count( $statements[1] ) ) );
	}

	$keys = array();
	foreach ( $statements[1] as $statement ) {
		if ( strpos( $statement, 'oko_shipping_instance_fields(' ) !== false ) {
			$keys = array_merge( $keys, $shared[1] );
		}
		preg_match_all( "/'(\w+)'\s*=>/", $statement, $own );
		$keys = array_merge( $keys, $own[1] );
	}

	return array_map(
		static function ( string $key ): string {
			return 'shipping:' . $key;
		},
		$keys
	);
}

/** Every setting there is, each once. */
function oko_guide_all_settings(): array {
	$all = array_merge( oko_guide_main_page_ids(), oko_guide_integration_ids(), oko_guide_shipping_method_ids() );

	return array_values( array_unique( $all ) );
}

/**
 * Every setting the guide describes, as listed in docs/guide-indstillinger.txt,
 * with how many times each is listed.
 *
 * @return array<string,int>
 */
function oko_guide_markers(): array {
	$path = oko_guide_root() . '/docs/guide-indstillinger.txt';
	if ( ! is_readable( $path ) ) {
		fail( 'docs/guide-indstillinger.txt is missing' );
	}

	$markers = array();
	foreach ( preg_split( '/\R/', (string) file_get_contents( $path ) ) as $line ) {
		$id = trim( $line );
		if ( $id === '' || $id[0] === '#' ) {
			continue;
		}
		$markers[ $id ] = ( $markers[ $id ] ?? 0 ) + 1;
	}

	return $markers;
}

describe( 'GuideCoversEverySettingTest' );

it( 'reads the fields it is meant to find out of the source', function () {
	$settings = oko_guide_all_settings();

	// One from each place the reading has to work, so a parser that quietly
	// finds nothing cannot pass the two tests below by default.
	foreach ( array(
		'_api_key',
		'_address_autocomplete',
		'okoskabet_free_packaging',
		'okoskabet_product_merchant',
		'okoskabet_packaging_fee:rules[][tiers]',
		'okoskabet_delivery_exceptions:from_until_limit_mode',
		'okoskabet_delivery_exceptions:weekdays[][flip]',
		'okoskabet_merchants_config:merchant[api_key]',
		'_split_banner_body_pre_order',
		'shipping:tiers',
		'shipping:allow_without_date',
	) as $expected ) {
		assert_true( in_array( $expected, $settings, true ), "found $expected" );
	}
	foreach ( array( '_delivery_location_title', '_oko_inbound_seen', 'action', 'merchant_id', 'oko_split_remove_option' ) as $not_a_setting ) {
		assert_false( in_array( $not_a_setting, $settings, true ), "left out $not_a_setting" );
	}
} );

it( 'has a marker in the guide for every setting', function () {
	$missing = array_values( array_diff( oko_guide_all_settings(), array_keys( oko_guide_markers() ) ) );
	if ( $missing !== array() ) {
		fail( "new settings — describe them in the WooCommerce guide in delivery_backend (priv/guides/woocommerce-plugin/), then add them to docs/guide-indstillinger.txt:\n         " . implode( "\n         ", $missing ) );
	}
} );

it( 'has no marker for a setting that no longer exists', function () {
	$stale = array_values( array_diff( array_keys( oko_guide_markers() ), oko_guide_all_settings() ) );
	if ( $stale !== array() ) {
		fail( "settings that are gone — take them out of the WooCommerce guide in delivery_backend and out of docs/guide-indstillinger.txt:\n         " . implode( "\n         ", $stale ) );
	}
} );

it( 'marks every setting exactly once', function () {
	$twice = array_keys( array_filter( oko_guide_markers(), static function ( int $count ): bool {
		return $count > 1;
	} ) );
	if ( $twice !== array() ) {
		fail( "marked more than once:\n         " . implode( "\n         ", $twice ) );
	}
} );
