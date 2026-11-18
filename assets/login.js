/**
 * Página de acceso (maqueta G0): mete el título con el logo dentro de la
 * tarjeta del formulario y, si el navegador sabe usar passkeys, muestra
 * "Entrar con huella o rostro" debajo de "Acceder".
 */
( function () {
	'use strict';

	var config = window.pkLogin || {};

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
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( cuerpo || {} ),
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( datos ) {
				if ( ! r.ok ) {
					throw new Error( datos.message || 'No se pudo entrar. Inténtalo de nuevo.' );
				}
				return datos;
			} );
		}, function () {
			throw new Error( 'No se pudo conectar. Revisa tu internet.' );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'loginform' );
		var titulo = document.getElementById( 'pk-lg-titulo' );
		var caja = document.getElementById( 'pk-lg-llave' );

		if ( form && titulo ) {
			form.insertBefore( titulo, form.firstChild );
		}

		if ( ! form || ! caja || ! window.isSecureContext || ! window.PublicKeyCredential || ! navigator.credentials ) {
			return;
		}

		var submit = form.querySelector( 'p.submit' );
		if ( submit ) {
			submit.parentNode.insertBefore( caja, submit.nextSibling );
		}
		caja.hidden = false;

		var boton = document.getElementById( 'pk-lg-boton' );
		var error = document.getElementById( 'pk-lg-error' );

		boton.addEventListener( 'click', function () {
			boton.disabled = true;
			error.hidden = true;

			api( 'gerencia/entrar/opciones' ).then( function ( r ) {
				var pk = r.publicKey;
				pk.challenge = deBase64Url( pk.challenge );
				( pk.allowCredentials || [] ).forEach( function ( c ) {
					c.id = deBase64Url( c.id );
				} );
				return navigator.credentials.get( { publicKey: pk } ).then( function ( cred ) {
					return api( 'gerencia/entrar', {
						reto: r.reto,
						redirigir: config.redirigir || '',
						credencial: {
							id: aBase64Url( cred.rawId ),
							clientDataJSON: aBase64Url( cred.response.clientDataJSON ),
							authenticatorData: aBase64Url( cred.response.authenticatorData ),
							signature: aBase64Url( cred.response.signature ),
						},
					} );
				} );
			} ).then( function ( r ) {
				location.href = r.ir;
			} ).catch( function ( e ) {
				boton.disabled = false;
				error.textContent = e && 'NotAllowedError' === e.name ? 'Se canceló o no se reconoció tu rostro o huella. Inténtalo de nuevo o entra con tu contraseña.' : ( e && e.message ? e.message : 'No se pudo entrar.' );
				error.hidden = false;
			} );
		} );
	} );
}() );
