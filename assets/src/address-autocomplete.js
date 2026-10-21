/**
 * Økoskabet WooCommerce Plugin — address suggestions in the classic checkout.
 *
 * While the customer types into "Street address", suggest addresses from the
 * Danish address register, and fill street, floor and door, postcode and city
 * from the one they choose. When the chosen address is a building with flats
 * and no floor or door, the flats are offered as a second list.
 *
 * The suggestions come from the shop's own REST routes (see
 * integrations/Address_Autocomplete.php), which ask Økoskabet with the shop's
 * API key. The key is never in the browser.
 *
 * Nothing here can stand in the customer's way: every failure is "no
 * suggestions", and a customer who ignores the list checks out exactly as
 * before. On a page without the fields (the block checkout, the order-received
 * page) this does nothing.
 *
 * Configuration comes from PHP as window._okoskabet_address_autocomplete.
 */
import './styles/address-autocomplete.scss';

const CONFIG = window._okoskabet_address_autocomplete || {};
const STR = CONFIG.strings || {};

const DEBOUNCE_MS = 250;
const MIN_LENGTH = 3;
const PREFIXES = [ 'billing', 'shipping' ];

// A floor and door as the register writes them: "st.", "kl.", "2. th",
// "st. 4", "tv". Used to tell a floor left over from an earlier address from
// something else the customer wrote in the second address line ("c/o …").
const UNIT_ONLY =
	/^(?:(?:st|kl|\d{1,2})\.?(?:\s*(?:th|tv|mf|\d{1,4}[a-z]?))?|th|tv|mf)$/i;

let instances = 0;

/**
 *
 * @param query
 */
function suggestUrl( query ) {
	const base = CONFIG.suggestUrl || '';
	const glue = base.indexOf( '?' ) === -1 ? '?' : '&';
	return base + glue + 'q=' + encodeURIComponent( query );
}

/**
 *
 * @param id
 */
function addressUrl( id ) {
	// Ids are a uuid with an optional "husnummer:" tag, checked again on the
	// server. Encoded apart from the colon, which a path may carry as it is.
	return ( CONFIG.addressUrl || '' ).replace(
		'__ID__',
		encodeURIComponent( id ).replace( /%3A/gi, ':' )
	);
}

/**
 *
 * @param url
 * @param signal
 */
function getJson( url, signal ) {
	return window
		.fetch( url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			signal,
		} )
		.then( ( response ) => ( response.ok ? response.json() : null ) )
		.catch( () => null );
}

/**
 *
 * @param field
 * @param value
 */
function setValue( field, value ) {
	if ( ! field || field.value === value ) {
		return;
	}
	field.value = value;
	// Native events reach WooCommerce's jQuery handlers as well as anything
	// listening without jQuery. Not trusted, so our own listener ignores them.
	field.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
	field.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
}

/**
 *
 * @param template
 * @param count
 */
function format( template, count ) {
	return ( template || '' ).replace( '%d', String( count ) );
}

/**
 * One address block: billing or shipping.
 *
 * @param {string} prefix "billing" or "shipping".
 */
function attach( prefix ) {
	const input = document.getElementById( prefix + '_address_1' );
	if (
		! input ||
		input.tagName !== 'INPUT' ||
		input.dataset.okoAutocomplete
	) {
		return;
	}
	input.dataset.okoAutocomplete = '1';

	const n = ++instances;
	const listId = 'okoskabet-address-list-' + n;
	const headingId = 'okoskabet-address-heading-' + n;

	const wrapper = input.parentNode;
	wrapper.classList.add( 'okoskabet-address-wrapper' );

	const popup = document.createElement( 'div' );
	popup.className = 'okoskabet-address-popup';
	popup.hidden = true;

	const heading = document.createElement( 'div' );
	heading.className = 'okoskabet-address-heading';
	heading.id = headingId;
	heading.hidden = true;
	heading.textContent = STR.chooseUnit || '';

	const list = document.createElement( 'ul' );
	list.id = listId;
	list.className = 'okoskabet-address-list';
	list.setAttribute( 'role', 'listbox' );
	list.setAttribute( 'aria-label', STR.suggestions || '' );

	const status = document.createElement( 'div' );
	status.className = 'okoskabet-address-status';
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );

	popup.appendChild( heading );
	popup.appendChild( list );
	wrapper.appendChild( popup );
	wrapper.appendChild( status );

	input.setAttribute( 'role', 'combobox' );
	input.setAttribute( 'aria-autocomplete', 'list' );
	input.setAttribute( 'aria-expanded', 'false' );
	input.setAttribute( 'aria-controls', listId );

	// What the list currently shows: [{ id, text, building? }].
	let items = [];
	let active = -1;
	let timer = null;
	let controller = null;
	let generation = 0;
	const cache = new Map();

	/**
	 *
	 * @param text
	 */
	function announce( text ) {
		status.textContent = text || '';
	}

	/**
	 *
	 */
	function close() {
		popup.hidden = true;
		heading.hidden = true;
		list.setAttribute( 'aria-label', STR.suggestions || '' );
		list.removeAttribute( 'aria-labelledby' );
		list.textContent = '';
		items = [];
		active = -1;
		input.setAttribute( 'aria-expanded', 'false' );
		input.removeAttribute( 'aria-activedescendant' );
	}

	/**
	 *
	 * @param index
	 */
	function setActive( index ) {
		const options = list.children;
		if ( active >= 0 && options[ active ] ) {
			options[ active ].setAttribute( 'aria-selected', 'false' );
			options[ active ].classList.remove( 'is-active' );
		}
		active = index;
		if ( active >= 0 && options[ active ] ) {
			const option = options[ active ];
			option.setAttribute( 'aria-selected', 'true' );
			option.classList.add( 'is-active' );
			input.setAttribute( 'aria-activedescendant', option.id );
			if ( typeof option.scrollIntoView === 'function' ) {
				option.scrollIntoView( { block: 'nearest' } );
			}
		} else {
			input.removeAttribute( 'aria-activedescendant' );
		}
	}

	/**
	 * @param {Array}   entries  [{ id, text, building? }]
	 * @param {boolean} forUnits Whether this is the flats of a building.
	 */
	function open( entries, forUnits ) {
		list.textContent = '';
		items = entries;
		active = -1;

		if ( ! entries.length ) {
			close();
			return;
		}

		entries.forEach( ( entry, index ) => {
			const option = document.createElement( 'li' );
			option.id = listId + '-' + index;
			option.className = 'okoskabet-address-option';
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'aria-selected', 'false' );
			option.textContent = entry.text;
			list.appendChild( option );
		} );

		heading.hidden = ! forUnits;
		if ( forUnits ) {
			list.removeAttribute( 'aria-label' );
			list.setAttribute( 'aria-labelledby', headingId );
		} else {
			list.removeAttribute( 'aria-labelledby' );
			list.setAttribute( 'aria-label', STR.suggestions || '' );
		}

		popup.hidden = false;
		input.setAttribute( 'aria-expanded', 'true' );

		if ( forUnits ) {
			announce(
				( STR.chooseUnit ? STR.chooseUnit + '. ' : '' ) +
					format( STR.unitCount, entries.length - 1 )
			);
			setActive( 0 );
		} else {
			announce(
				entries.length === 1
					? STR.oneCount
					: format( STR.count, entries.length )
			);
		}
	}

	/**
	 *
	 */
	function cancelPending() {
		window.clearTimeout( timer );
		generation++;
		if ( controller ) {
			controller.abort();
			controller = null;
		}
	}

	/**
	 *
	 * @param url
	 */
	function request( url ) {
		cancelPending();
		const mine = generation;
		controller =
			typeof window.AbortController === 'function'
				? new window.AbortController()
				: null;
		return getJson( url, controller ? controller.signal : undefined ).then(
			( data ) => ( mine === generation ? data : undefined )
		);
	}

	/**
	 *
	 * @param query
	 */
	function search( query ) {
		if ( cache.has( query ) ) {
			open( cache.get( query ), false );
			return;
		}
		request( suggestUrl( query ) ).then( ( data ) => {
			if ( data === undefined ) {
				return; // Overtaken by a newer keystroke.
			}
			const suggestions =
				data && Array.isArray( data.suggestions )
					? data.suggestions
					: [];
			cache.set( query, suggestions );
			// Only if the customer is still here and hasn't typed on.
			if (
				input.ownerDocument.activeElement === input &&
				input.value.trim().replace( /\s+/g, ' ' ) === query
			) {
				open( suggestions, false );
			}
		} );
	}

	/**
	 *
	 * @param name
	 */
	function field( name ) {
		return document.getElementById( prefix + '_' + name );
	}

	/**
	 *
	 * @param address
	 */
	function fill( address ) {
		const address2Field = field( 'address_2' );
		const unit = address.address_2 || '';
		let line1 = address.address_1;

		const country = field( 'country' );
		if (
			country &&
			country.tagName === 'SELECT' &&
			country.value !== 'DK' &&
			country.querySelector( 'option[value="DK"]' )
		) {
			setValue( country, 'DK' );
			// selectWoo shows the old country until told.
			if ( window.jQuery ) {
				window.jQuery( country ).trigger( 'change.select2' );
			}
		}

		if ( address2Field ) {
			if ( unit ) {
				setValue( address2Field, unit );
			} else if ( UNIT_ONLY.test( address2Field.value.trim() ) ) {
				// A floor left from an address the customer had before.
				setValue( address2Field, '' );
			}
		} else if ( unit ) {
			line1 += ', ' + unit;
		}

		setValue( input, line1 );
		if ( address.postal_code ) {
			setValue( field( 'postcode' ), address.postal_code );
		}
		if ( address.city ) {
			setValue( field( 'city' ), address.city );
		}

		announce( STR.filled );

		// Delivery dates and Økoskabe depend on the postcode.
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	/**
	 *
	 * @param index
	 */
	function choose( index ) {
		const entry = items[ index ];
		if ( ! entry ) {
			return;
		}

		// "No apartment": the building, which is already filled in.
		if ( entry.building ) {
			close();
			return;
		}

		const listedUnits = ! heading.hidden;
		close();
		input.setAttribute( 'aria-busy', 'true' );

		request( addressUrl( entry.id ) ).then( ( data ) => {
			input.removeAttribute( 'aria-busy' );
			if ( data === undefined || ! data || ! data.address ) {
				return; // Leave what the customer typed.
			}
			const units = Array.isArray( data.units ) ? data.units : [];
			// The building goes in at once, so street, postcode and city are
			// right even if the customer never picks a flat.
			fill( data.address );
			if ( units.length && ! listedUnits ) {
				open(
					[
						{
							id: '',
							text: STR.noUnit || '',
							building: data.address,
						},
					].concat( units ),
					true
				);
			}
		} );
	}

	input.addEventListener( 'input', ( event ) => {
		// Only the customer's own typing. Our own filling dispatches untrusted
		// events, and browser autofill sends no inputType; neither should open
		// a list over a finished address.
		if ( ! event.isTrusted || ! event.inputType ) {
			return;
		}
		cancelPending();
		const query = input.value.trim().replace( /\s+/g, ' ' );
		if ( query.length < MIN_LENGTH ) {
			close();
			return;
		}
		timer = window.setTimeout( () => search( query ), DEBOUNCE_MS );
	} );

	input.addEventListener( 'keydown', ( event ) => {
		const isOpen = ! popup.hidden && items.length > 0;

		switch ( event.key ) {
			case 'ArrowDown':
				if ( isOpen ) {
					event.preventDefault();
					setActive( active + 1 < items.length ? active + 1 : 0 );
				}
				break;
			case 'ArrowUp':
				if ( isOpen ) {
					event.preventDefault();
					setActive( active > 0 ? active - 1 : items.length - 1 );
				}
				break;
			case 'Enter':
				// Only when a suggestion is highlighted; otherwise Enter does
				// what it always did.
				if ( isOpen && active >= 0 ) {
					event.preventDefault();
					choose( active );
				}
				break;
			case 'Escape':
				if ( isOpen ) {
					event.preventDefault();
					cancelPending();
					close();
				}
				break;
			case 'Tab':
				close();
				break;
		}
	} );

	input.addEventListener( 'blur', () => {
		// After a click on an option has had its turn.
		window.setTimeout( () => {
			if ( input.ownerDocument.activeElement !== input ) {
				close();
			}
		}, 150 );
	} );

	// Keep the focus in the field, so the list isn't closed by the blur
	// before the click lands, and the keyboard carries on from here.
	list.addEventListener( 'mousedown', ( event ) => event.preventDefault() );

	list.addEventListener( 'click', ( event ) => {
		const option = event.target.closest( '.okoskabet-address-option' );
		if ( ! option ) {
			return;
		}
		const index = Array.prototype.indexOf.call( list.children, option );
		input.focus();
		choose( index );
	} );

	list.addEventListener( 'mousemove', ( event ) => {
		const option = event.target.closest( '.okoskabet-address-option' );
		if ( option ) {
			const index = Array.prototype.indexOf.call( list.children, option );
			if ( index !== active ) {
				setActive( index );
			}
		}
	} );
}

/**
 *
 */
function init() {
	if ( ! CONFIG.suggestUrl || typeof window.fetch !== 'function' ) {
		return;
	}
	PREFIXES.forEach( attach );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}

// A theme or plugin that rebuilds the address fields gets a fresh field
// without our marker; attach to it again.
if ( window.jQuery ) {
	window.jQuery( document.body ).on( 'updated_checkout', init );
}
