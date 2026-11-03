/* Marsad admin: click a service account e-mail to copy it. */
( function () {
	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest ? e.target.closest( '.marsad-copy' ) : null;
		if ( ! el || ! navigator.clipboard ) {
			return;
		}
		navigator.clipboard.writeText( el.textContent.trim() ).then( function () {
			el.style.outline = '2px solid #16a34a';
			setTimeout( function () {
				el.style.outline = '';
			}, 900 );
		} );
	} );
} )();
