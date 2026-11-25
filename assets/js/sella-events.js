/**
 * The event ticket form (inc/sella-events.php): the + and − beside the
 * quantity. Adding to the cart is Smart Cart's (it catches form.cart).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.sella-event-qty__btn' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();

		var input = btn.parentNode.querySelector( '.sella-event-qty__input' );
		if ( ! input ) {
			return;
		}
		var min = parseInt( input.min, 10 ) || 1;
		var max = parseInt( input.max, 10 ) || 99;
		var value = ( parseInt( input.value, 10 ) || min ) + ( parseInt( btn.getAttribute( 'data-step' ), 10 ) || 0 );

		input.value = Math.min( max, Math.max( min, value ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );

	// A typed value is kept inside the limits.
	document.addEventListener( 'change', function ( e ) {
		var input = e.target;
		if ( ! input.classList || ! input.classList.contains( 'sella-event-qty__input' ) ) {
			return;
		}
		var min = parseInt( input.min, 10 ) || 1;
		var max = parseInt( input.max, 10 ) || 99;
		var value = parseInt( input.value, 10 ) || min;
		if ( value < min || value > max || String( value ) !== input.value ) {
			input.value = Math.min( max, Math.max( min, value ) );
		}
	} );
} )();
