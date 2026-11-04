/*
 * Marsad admin, no libraries: copy a service account e-mail, sort/filter/expand report tables,
 * and poll the live visitors card. Everything here only improves server-rendered markup that
 * already reads correctly without it.
 */
( function () {
	'use strict';

	var each = function ( list, fn ) {
		Array.prototype.forEach.call( list, fn );
	};

	/* Click a service account e-mail to copy it. */
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

	/*
	 * Report tables. The server prints the first rows in the table and the rest inside a
	 * <details>; here the rest join the table so sorting and filtering see every row, and a
	 * button takes the place of the <details>.
	 */
	function initTable( box ) {
		var table = box.querySelector( 'table.marsad-tbl' );
		if ( ! table || ! table.tBodies.length ) {
			return;
		}
		var tbody = table.tBodies[ 0 ];
		var rows = Array.prototype.slice.call( tbody.rows );
		var visible = parseInt( box.getAttribute( 'data-visible' ), 10 ) || 25;
		var more = box.querySelector( 'details.marsad-showall' );
		var none = box.querySelector( '.marsad-table-none' );
		var input = box.querySelector( '.marsad-filter' );
		var expanded = false;
		var button = null;

		if ( more ) {
			each( more.querySelectorAll( 'tbody tr' ), function ( tr ) {
				tbody.appendChild( tr );
				rows.push( tr );
			} );
			var showAll = more.querySelector( 'summary' ).textContent;
			var showLess = box.getAttribute( 'data-less' ) || showAll;
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'marsad-btn marsad-btn-sm marsad-more';
			button.textContent = showAll;
			button.setAttribute( 'aria-expanded', 'false' );
			more.parentNode.replaceChild( button, more );
			button.addEventListener( 'click', function () {
				expanded = ! expanded;
				button.textContent = expanded ? showLess : showAll;
				button.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
				update();
			} );
		}

		function update() {
			var q = input ? input.value.trim().toLowerCase() : '';
			var shown = 0;
			rows.forEach( function ( tr ) {
				var match = ! q || tr.textContent.toLowerCase().indexOf( q ) !== -1;
				var show = match && ( q || expanded || shown < visible );
				if ( show ) {
					shown++;
				}
				tr.hidden = ! show;
			} );
			if ( button ) {
				button.hidden = !! q;
			}
			if ( none ) {
				none.hidden = shown > 0;
			}
		}

		if ( input ) {
			input.hidden = false;
			input.addEventListener( 'input', update );
		}

		each( table.tHead.querySelectorAll( 'button.marsad-sort' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var th = b.parentNode;
				var col = th.cellIndex;
				var num = 'num' === b.getAttribute( 'data-type' );
				// Numbers start high-to-low, text A to Z; a second click reverses.
				var current = th.getAttribute( 'aria-sort' );
				var dir = current ? ( 'descending' === current ? 'ascending' : 'descending' ) : ( num ? 'descending' : 'ascending' );
				each( table.tHead.querySelectorAll( 'th[aria-sort]' ), function ( other ) {
					other.removeAttribute( 'aria-sort' );
				} );
				th.setAttribute( 'aria-sort', dir );
				var sign = 'ascending' === dir ? 1 : -1;
				rows.sort( function ( a, c ) {
					var x = a.cells[ col ] ? a.cells[ col ].getAttribute( 'data-v' ) : '';
					var y = c.cells[ col ] ? c.cells[ col ].getAttribute( 'data-v' ) : '';
					// Empty cells stay at the bottom whichever way the column is sorted.
					if ( '' === x || null === x ) {
						return '' === y || null === y ? 0 : 1;
					}
					if ( '' === y || null === y ) {
						return -1;
					}
					if ( num ) {
						return sign * ( parseFloat( x ) - parseFloat( y ) );
					}
					return sign * x.localeCompare( y );
				} );
				rows.forEach( function ( tr ) {
					tbody.appendChild( tr );
				} );
				update();
			} );
		} );

		update();
	}

	/* The live visitors card: asks the server every minute while the page is visible. */
	function initRealtime( box ) {
		var url = box.getAttribute( 'data-url' );
		var nonce = box.getAttribute( 'data-nonce' );
		var lang = document.documentElement.lang || undefined;
		var part = function ( name ) {
			return box.querySelector( '[data-rt="' + name + '"]' );
		};
		var number = function ( v ) {
			try {
				return Number( v ).toLocaleString( lang );
			} catch ( err ) {
				return String( v );
			}
		};
		var fill = function ( name, items ) {
			var list = part( name );
			if ( ! list ) {
				return;
			}
			list.textContent = '';
			if ( ! items || ! items.length ) {
				var empty = document.createElement( 'li' );
				empty.textContent = box.getAttribute( 'data-empty' ) || '';
				list.appendChild( empty );
				return;
			}
			items.forEach( function ( it ) {
				var li = document.createElement( 'li' );
				var name = document.createElement( 'span' );
				var value = document.createElement( 'b' );
				name.textContent = it.name;
				name.title = it.name;
				value.textContent = number( it.value );
				li.appendChild( name );
				li.appendChild( value );
				list.appendChild( li );
			} );
		};
		var status = function ( text ) {
			var el = part( 'status' );
			if ( el ) {
				el.textContent = text;
			}
		};

		function load() {
			if ( document.hidden || ! window.fetch || ! window.FormData ) {
				return;
			}
			var body = new FormData();
			body.append( 'action', 'marsad_realtime' );
			body.append( '_ajax_nonce', nonce );
			fetch( url, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( ! res || ! res.success || ! res.data ) {
						throw new Error( 'unavailable' );
					}
					part( 'active' ).textContent = number( res.data.active );
					fill( 'pages', res.data.pages );
					fill( 'countries', res.data.countries );
					fill( 'devices', res.data.devices );
					var time = new Date().toLocaleTimeString( lang, { hour: '2-digit', minute: '2-digit' } );
					status( ( box.getAttribute( 'data-updated' ) || '%s' ).replace( '%s', time ) );
				} )
				.catch( function () {
					status( box.getAttribute( 'data-error' ) || '' );
				} );
		}

		load();
		setInterval( load, 60000 );
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				load();
			}
		} );
	}

	function init() {
		each( document.querySelectorAll( '[data-marsad-table]' ), initTable );
		each( document.querySelectorAll( '[data-marsad-realtime]' ), initRealtime );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
