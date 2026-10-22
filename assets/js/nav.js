/* AI Theme Studio — collapsible navigation (progressive enhancement; menu stays usable without JS). */
( function () {
	'use strict';
	document.querySelectorAll( '.ats-nav' ).forEach( function ( nav ) {
		var toggle = nav.querySelector( '.ats-nav__toggle' );
		if ( ! toggle ) {
			return;
		}
		nav.classList.add( 'ats-nav--js' );
		toggle.hidden = false;
		toggle.addEventListener( 'click', function () {
			var open = nav.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
		nav.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && nav.classList.contains( 'is-open' ) ) {
				nav.classList.remove( 'is-open' );
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.focus();
			}
		} );
	} );
} )();
