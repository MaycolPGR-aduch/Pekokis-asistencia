/**
 * App del trabajador: activar la passkey y marcar asistencia.
 * Sin dependencias. Cada marca exige la huella (passkey con verificación del usuario).
 */
( function () {
	'use strict';

	var config = JSON.parse( document.getElementById( 'pka-config' ).textContent );
	var contenido = document.getElementById( 'contenido' );
	var barraPresencia = document.getElementById( 'presencia' );
	var sesion = null; // { ticket, vence, datos }
	var temporizador = null;

	// ---------- utilidades ----------

	function el( etiqueta, atributos, hijos ) {
		var nodo = document.createElement( etiqueta );
		Object.keys( atributos || {} ).forEach( function ( clave ) {
			if ( 'texto' === clave ) {
				nodo.textContent = atributos[ clave ];
			} else if ( 'alHacerClic' === clave ) {
				nodo.addEventListener( 'click', atributos[ clave ] );
			} else {
				nodo.setAttribute( clave, atributos[ clave ] );
			}
		} );
		( hijos || [] ).forEach( function ( hijo ) {
			if ( hijo ) {
				nodo.appendChild( 'string' === typeof hijo ? document.createTextNode( hijo ) : hijo );
			}
		} );
		return nodo;
	}

	function pintar() {
		contenido.replaceChildren.apply( contenido, Array.prototype.slice.call( arguments ) );
		var titulo = contenido.querySelector( 'h1' );
		if ( titulo ) {
			titulo.setAttribute( 'tabindex', '-1' );
			titulo.focus( { preventScroll: true } );
		}
	}

	function aviso( tipo, texto ) {
		return el( 'p', { class: 'aviso ' + tipo, role: 'error' === tipo ? 'alert' : 'status', texto: texto } );
	}

	function aBase64Url( buffer ) {
		var bytes = new Uint8Array( buffer );
		var texto = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			texto += String.fromCharCode( bytes[ i ] );
		}
		return btoa( texto ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}

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

	function api( ruta, cuerpo, metodo ) {
		return fetch( config.api + ruta, {
			method: metodo || 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: cuerpo ? JSON.stringify( cuerpo ) : undefined,
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( datos ) {
				if ( ! r.ok ) {
					var error = new Error( datos.message || 'No se pudo conectar. Revisa tu internet.' );
					error.datos = datos.data || {};
					error.codigo = datos.code || '';
					throw error;
				}
				return datos;
			} );
		}, function () {
			throw new Error( 'No se pudo conectar. Revisa tu internet.' );
		} );
	}

	function soportaPasskeys() {
		return window.isSecureContext && window.PublicKeyCredential && navigator.credentials;
	}

	function mensajePasskey( error ) {
		if ( error && 'NotAllowedError' === error.name ) {
			return 'Se canceló o no se reconoció tu huella. Inténtalo de nuevo.';
		}
		if ( error && 'InvalidStateError' === error.name ) {
			return 'Este celular ya tiene una llave para ti. Toca "Ir a marcar".';
		}
		return ( error && error.message ) || 'Algo salió mal. Inténtalo de nuevo.';
	}

	function reloj() {
		var nodo = document.getElementById( 'reloj' );
		var tic = function () {
			nodo.textContent = new Date().toLocaleTimeString( 'es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' } );
		};
		tic();
		setInterval( tic, 15000 );
	}

	function actualizarPresencia() {
		api( 'presencia', null, 'GET' ).then( function ( p ) {
			barraPresencia.hidden = false;
			barraPresencia.className = 'presencia' + ( p.cumple ? ' ok' : '' );
			barraPresencia.textContent = p.cumple ? '✔ ' + p.mensaje : p.mensaje;
		} ).catch( function () {
			barraPresencia.hidden = true;
		} );
	}

	// ---------- activación ----------

	function vistaActivar() {
		var token = location.hash.replace( /^#/, '' );
		// El token no debe quedar a la vista en la barra de direcciones ni en el historial.
		history.replaceState( null, '', location.pathname );

		if ( ! soportaPasskeys() ) {
			pintar( el( 'h1', { texto: 'Este navegador no sirve' } ), aviso( 'error', 'Abre el enlace en Chrome (Android) o Safari (iPhone), con el celular con bloqueo de pantalla.' ) );
			return;
		}

		if ( ! /^[A-Za-z0-9_-]{32}$/.test( token ) ) {
			pintar( el( 'h1', { texto: 'Enlace incompleto' } ), aviso( 'error', 'Abre el enlace completo que te dio RR.HH.' ) );
			return;
		}

		var boton = el( 'button', { class: 'principal', type: 'button', texto: 'Crear mi llave' } );
		var estado = el( 'div', {} );

		boton.addEventListener( 'click', function () {
			boton.disabled = true;
			estado.replaceChildren();
			var nombre = '';

			api( 'activacion/opciones', { token: token } ).then( function ( r ) {
				nombre = r.nombre;
				var pk = r.publicKey;
				pk.challenge = deBase64Url( pk.challenge );
				pk.user.id = deBase64Url( pk.user.id );
				( pk.excludeCredentials || [] ).forEach( function ( c ) {
					c.id = deBase64Url( c.id );
				} );
				return navigator.credentials.create( { publicKey: pk } ).then( function ( cred ) {
					return api( 'activacion/completar', {
						token: token,
						reto: r.reto,
						credencial: {
							id: cred.id,
							clientDataJSON: aBase64Url( cred.response.clientDataJSON ),
							attestationObject: aBase64Url( cred.response.attestationObject ),
						},
					} );
				} );
			} ).then( function () {
				pintar(
					el( 'div', { class: 'hecho' }, [ el( 'div', { class: 'icono', 'aria-hidden': 'true', texto: '✔' } ) ] ),
					el( 'h1', {}, [ 'Listo, ', el( 'em', { texto: nombre } ) ] ),
					el( 'p', { texto: 'Desde ahora marcas tu asistencia con tu huella. Agrega esta página a la pantalla de inicio para tenerla a mano.' } ),
					el( 'button', { class: 'principal', type: 'button', texto: 'Ir a marcar', alHacerClic: function () {
						location.href = location.origin + location.pathname.replace( /activar\/?$/, '' );
					} } )
				);
			} ).catch( function ( error ) {
				boton.disabled = false;
				estado.replaceChildren( aviso( 'error', mensajePasskey( error ) ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Activa tu ', el( 'em', { texto: 'celular' } ) ] ),
			el( 'p', { texto: 'Vas a crear una llave que se abre con la huella, el rostro o el PIN de este celular. Con ella marcarás tu asistencia.' } ),
			el( 'p', { class: 'ayuda', texto: 'Hazlo en tu propio celular: cada persona usa el suyo.' } ),
			boton,
			estado
		);
	}

	// ---------- marcar ----------

	function vistaInicio( mensaje ) {
		limpiarSesion();

		if ( ! soportaPasskeys() ) {
			pintar( el( 'h1', { texto: 'Este navegador no sirve' } ), aviso( 'error', 'Usa Chrome (Android) o Safari (iPhone), con el celular con bloqueo de pantalla.' ) );
			return;
		}

		var boton = el( 'button', { class: 'principal', type: 'button', texto: 'Marcar con mi huella' } );
		var estado = el( 'div', {}, [ mensaje ] );

		boton.addEventListener( 'click', function () {
			boton.disabled = true;
			estado.replaceChildren();
			identificarse().catch( function ( error ) {
				boton.disabled = false;
				estado.replaceChildren( aviso( 'error', mensajePasskey( error ) ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Asistencia ', el( 'em', { texto: 'Pekokis' } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Pon tu huella para ver tu estado y marcar.' } ),
			boton,
			estado
		);
		actualizarPresencia();
	}

	function identificarse() {
		return api( 'identificar/opciones' ).then( function ( r ) {
			var pk = r.publicKey;
			pk.challenge = deBase64Url( pk.challenge );
			( pk.allowCredentials || [] ).forEach( function ( c ) {
				c.id = deBase64Url( c.id );
			} );
			return navigator.credentials.get( { publicKey: pk } ).then( function ( cred ) {
				return api( 'identificar', {
					reto: r.reto,
					credencial: {
						id: aBase64Url( cred.rawId ),
						clientDataJSON: aBase64Url( cred.response.clientDataJSON ),
						authenticatorData: aBase64Url( cred.response.authenticatorData ),
						signature: aBase64Url( cred.response.signature ),
						userHandle: cred.response.userHandle ? aBase64Url( cred.response.userHandle ) : '',
					},
				} );
			} );
		} ).then( function ( datos ) {
			sesion = { ticket: datos.ticket, vence: Date.now() + datos.segundos * 1000 };
			temporizador = setTimeout( function () {
				vistaInicio( aviso( 'error', 'Pasaron 2 minutos. Vuelve a poner tu huella.' ) );
			}, datos.segundos * 1000 );
			vistaEstado( datos );
		} );
	}

	function vistaEstado( datos, mensaje ) {
		var tarjeta = el( 'div', { class: 'tarjeta estado' }, [
			el( 'span', { class: 'ayuda', texto: 'Tu estado' } ),
			el( 'strong', { texto: datos.estado.etiqueta + ( datos.estado.desde ? ' desde las ' + datos.estado.desde : '' ) } ),
		] );

		var botones = el( 'div', { class: 'botones' } );
		datos.acciones.forEach( function ( accion, i ) {
			botones.appendChild( el( 'button', {
				class: 0 === i ? 'principal' : '',
				type: 'button',
				texto: accion.etiqueta,
				alHacerClic: function () {
					if ( accion.motivos.length > 1 ) {
						vistaMotivos( datos, accion );
					} else {
						enviarMarca( datos, accion, accion.motivos.length ? accion.motivos[ 0 ].clave : '' );
					}
				},
			} ) );
		} );

		pintar(
			el( 'h1', {}, [ 'Hola, ', el( 'em', { texto: datos.nombre } ) ] ),
			tarjeta,
			mensaje || null,
			botones,
			listaHoy( datos.hoy ),
			el( 'button', { class: 'secundario', type: 'button', texto: 'Salir', alHacerClic: function () {
				vistaInicio();
			} } )
		);
	}

	function vistaMotivos( datos, accion ) {
		var nota = el( 'input', { type: 'text', id: 'nota', maxlength: '200', autocomplete: 'off' } );
		var botones = el( 'div', { class: 'botones' } );

		accion.motivos.forEach( function ( motivo ) {
			botones.appendChild( el( 'button', { type: 'button', texto: motivo.nombre, alHacerClic: function () {
				enviarMarca( datos, accion, motivo.clave, nota.value );
			} } ) );
		} );

		pintar(
			el( 'h1', {}, [ '¿Por qué ', el( 'em', { texto: 'sales' } ), '?' ] ),
			botones,
			el( 'div', {}, [ el( 'label', { for: 'nota', texto: 'Nota (opcional)' } ), nota ] ),
			el( 'button', { class: 'secundario', type: 'button', texto: 'Volver', alHacerClic: function () {
				vistaEstado( datos );
			} } )
		);
	}

	function enviarMarca( datos, accion, motivo, nota ) {
		if ( ! sesion ) {
			vistaInicio();
			return;
		}

		contenido.querySelectorAll( 'button' ).forEach( function ( b ) {
			b.disabled = true;
		} );

		api( 'marcas', { ticket: sesion.ticket, accion: accion.clave, motivo: motivo || '', nota: nota || '' } ).then( function ( r ) {
			limpiarSesion();
			pintar(
				el( 'div', { class: 'hecho' }, [ el( 'div', { class: 'icono', 'aria-hidden': 'true', texto: '✔' } ) ] ),
				el( 'h1', { texto: 'rebote' === r.resultado ? 'Ya estaba registrada' : 'Marca registrada' } ),
				aviso( 'ok', r.mensaje ),
				r.revision ? el( 'p', { class: 'ayuda', texto: 'Tu jefe la revisará.' } ) : null,
				listaHoy( r.hoy ),
				el( 'button', { class: 'secundario', type: 'button', texto: 'Listo', alHacerClic: function () {
					vistaInicio();
				} } )
			);
			temporizador = setTimeout( vistaInicio, 15000 );
		} ).catch( function ( error ) {
			if ( 'pka_ticket' === error.codigo ) {
				vistaInicio( aviso( 'error', error.message ) );
				return;
			}
			vistaEstado( datos, aviso( 'error', error.message ) );
			actualizarPresencia();
		} );
	}

	function listaHoy( hoy ) {
		if ( ! hoy || ! hoy.length ) {
			return null;
		}
		return el( 'div', { class: 'tarjeta' }, [
			el( 'p', { class: 'ayuda', texto: 'Hoy' } ),
			el( 'ul', { class: 'lista-hoy' }, hoy.map( function ( m ) {
				return el( 'li', {}, [ el( 'span', { class: 'hora', texto: m.hora } ), el( 'span', { texto: m.texto } ) ] );
			} ) ),
		] );
	}

	function limpiarSesion() {
		sesion = null;
		if ( temporizador ) {
			clearTimeout( temporizador );
			temporizador = null;
		}
	}

	// ---------- arranque ----------

	reloj();

	if ( 'activar' === config.vista ) {
		vistaActivar();
	} else {
		vistaInicio();
	}
}() );
