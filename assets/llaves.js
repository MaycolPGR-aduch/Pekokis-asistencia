/**
 * Perfil de WordPress → "Entrar con huella o rostro" (G0): activa una passkey
 * de gerencia en este equipo o quita una activada.
 */
( function () {
	'use strict';

	var config = window.pkLlaves || {};

	function deBase64Url( texto ) {
		var base = texto.replace( /-/g, '+' ).replace( /_/g, '/' );
		while ( base.length % 4 ) {
			base += '=';
		}
		var binario = atob( base );
		var bytes = new Uint8Array( binario.length );
		for ( var i = 0; i < binario.length; i++ ) {
			bytes[ i ] = binario.charCodeAt( i );
		}
		return bytes.buffer;
	}

	function aBase64Url( buffer ) {
		var bytes = new Uint8Array( buffer );
		var texto = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			texto += String.fromCharCode( bytes[ i ] );
		}
		return btoa( texto ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}

	function api( ruta, cuerpo ) {
		return fetch( config.api + ruta, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( cuerpo || {} ),
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( datos ) {
				if ( ! r.ok ) {
					throw new Error( datos.message || 'No se pudo completar. Inténtalo de nuevo.' );
				}
				return datos;
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var activar = document.getElementById( 'pk-llave-activar' );
		var estado = document.getElementById( 'pk-llave-estado' );

		if ( activar ) {
			if ( ! window.isSecureContext || ! window.PublicKeyCredential || ! navigator.credentials ) {
				activar.disabled = true;
				estado.textContent = 'Este navegador no permite entrar con huella ni rostro. En Android usa Chrome; en iPhone, Safari.';
			}

			activar.addEventListener( 'click', function () {
				activar.disabled = true;
				estado.textContent = '';

				api( 'gerencia/llave/opciones' ).then( function ( r ) {
					var pk = r.publicKey;
					pk.challenge = deBase64Url( pk.challenge );
					pk.user.id = deBase64Url( pk.user.id );
					( pk.excludeCredentials || [] ).forEach( function ( c ) {
						c.id = deBase64Url( c.id );
					} );
					return navigator.credentials.create( { publicKey: pk } ).then( function ( cred ) {
						return api( 'gerencia/llave', {
							reto: r.reto,
							credencial: {
								clientDataJSON: aBase64Url( cred.response.clientDataJSON ),
								attestationObject: aBase64Url( cred.response.attestationObject ),
							},
						} );
					} );
				} ).then( function ( r ) {
					estado.textContent = 'Listo: ' + r.nombre + ' quedó activado. La próxima vez toca "Entrar con huella o rostro".';
					setTimeout( function () {
						location.reload();
					}, 1500 );
				} ).catch( function ( e ) {
					activar.disabled = false;
					if ( e && 'InvalidStateError' === e.name ) {
						estado.textContent = 'Este equipo ya estaba activado.';
					} else if ( e && 'NotAllowedError' === e.name ) {
						estado.textContent = 'Se canceló. Inténtalo de nuevo.';
					} else {
						estado.textContent = e && e.message ? e.message : 'No se pudo activar.';
					}
				} );
			} );
		}

		document.querySelectorAll( '.pk-llave-quitar' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				b.disabled = true;
				api( 'gerencia/llave/quitar', { id: Number( b.getAttribute( 'data-id' ) ) } ).then( function () {
					b.closest( 'li' ).remove();
				} ).catch( function ( e ) {
					b.disabled = false;
					estado.textContent = e.message;
				} );
			} );
		} );
	} );
}() );
