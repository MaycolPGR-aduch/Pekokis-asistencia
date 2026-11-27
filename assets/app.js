/**
 * App del trabajador (maquetas T0 a T9): entra una vez con su huella y queda la
 * sesión iniciada para ver Marcar, Mis horas, Mi recibo y Perfil. Cada MARCA
 * vuelve a pedir la huella (passkey con verificación del usuario).
 * Sin dependencias.
 */
( function () {
	'use strict';

	var config = JSON.parse( document.getElementById( 'pka-config' ).textContent );
	var contenido = document.getElementById( 'contenido' );
	var pestanas = document.getElementById( 'pestanas' );
	var quien = document.getElementById( 'quien' );
	var yo = null; // Último estado de GET /yo.
	var ticket = null; // { ticket, vence }: la huella recién puesta sirve 2 minutos para marcar.
	var relojes = []; // Temporizadores de la vista actual; se limpian al cambiar de vista.
	var camara = null;
	var ZONA = 'America/Lima';

	// ---------- utilidades ----------

	function el( etiqueta, atributos, hijos ) {
		var nodo = document.createElement( etiqueta );
		Object.keys( atributos || {} ).forEach( function ( clave ) {
			var valor = atributos[ clave ];
			if ( 'texto' === clave ) {
				nodo.textContent = valor;
			} else if ( 'alHacerClic' === clave ) {
				nodo.addEventListener( 'click', valor );
			} else if ( null !== valor && undefined !== valor && false !== valor ) {
				nodo.setAttribute( clave, valor );
			}
		} );
		( hijos || [] ).forEach( function ( hijo ) {
			if ( hijo ) {
				nodo.appendChild( 'string' === typeof hijo ? document.createTextNode( hijo ) : hijo );
			}
		} );
		return nodo;
	}

	var TRAZOS = {
		huella: '<path d="M12 11v4"/><path d="M8.5 9.5a3.5 3.5 0 0 1 7 0V14a7 7 0 0 1-1.2 4"/><path d="M8.5 13v1.5a8 8 0 0 0 1.6 4.8"/><path d="M5.5 9.5a6.5 6.5 0 0 1 13 0v3"/><path d="M5.3 14.5c.1-.6.2-1.3.2-2"/>',
		qr: '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z"/><path d="M14 14h2v2h-2zM18 18h2v2h-2zM14 18v2M18 14h2"/>',
		check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		reloj: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
		calendario: '<rect x="4" y="5" width="16" height="15" rx="3"/><path d="M4 10h16M9 3v4M15 3v4"/>',
		recibo: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
		persona: '<circle cx="12" cy="8" r="4"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
		wifi: '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.5a9.5 9.5 0 0 1 13 0M8.8 15.8a5 5 0 0 1 6.4 0"/><path d="M12 19.2h.01"/>',
		sinwifi: '<path d="M2 9a15 15 0 0 1 20 0"/><path d="M5 12.5a10.5 10.5 0 0 1 14 0"/><path d="M8.5 16a5.5 5.5 0 0 1 7 0"/><path d="M12 19.5h.01"/><path d="M3 3l18 18"/>',
		alerta: '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>',
		cubierto: '<path d="M7 3v18M4.5 3v5a2.5 2.5 0 0 0 5 0V3M17 3c-2.5 2-2.5 7 0 9v9"/>',
	};

	function icono( nombre ) {
		var caja = document.createElement( 'span' );
		caja.innerHTML = '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + TRAZOS[ nombre ] + '</svg>';
		return caja.firstChild;
	}

	function pintar() {
		relojes.forEach( clearInterval );
		relojes = [];
		apagarCamara();
		// replaceChildren() convierte null en el texto "null": se quitan antes.
		contenido.replaceChildren.apply( contenido, Array.prototype.slice.call( arguments ).filter( Boolean ) );
		window.scrollTo( 0, 0 );
		var titulo = contenido.querySelector( 'h1' );
		if ( titulo ) {
			titulo.setAttribute( 'tabindex', '-1' );
			titulo.focus( { preventScroll: true } );
		}
	}

	function cadaRato( funcion, ms ) {
		relojes.push( setInterval( funcion, ms ) );
	}

	function aviso( tipo, texto, extra ) {
		return el( 'div', { class: 'pk-aviso ' + tipo, role: 'error' === tipo ? 'alert' : 'status' }, [ texto, extra || null ] );
	}

	function cargando( texto ) {
		pintar( el( 'p', { class: 'cargando', texto: texto || 'Cargando…' } ) );
	}

	function boton( clase, texto, alHacerClic, iconoNombre ) {
		return el( 'button', { class: clase, type: 'button', alHacerClic: alHacerClic }, [ iconoNombre ? icono( iconoNombre ) : null, texto ] );
	}

	/** "3 h 38 min", "45 min" (igual que VistaDia::duracion). */
	function duracion( minutos ) {
		var h = Math.floor( minutos / 60 );
		var m = minutos % 60;
		return h ? h + ' h ' + ( m < 10 ? '0' : '' ) + m + ' min' : m + ' min';
	}

	function horaAhora() {
		return new Date().toLocaleTimeString( 'es-PE', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: ZONA } );
	}

	function guardar( clave, valor ) {
		try {
			window.localStorage.setItem( clave, valor );
		} catch ( e ) {}
	}

	function leer( clave ) {
		try {
			return window.localStorage.getItem( clave ) || '';
		} catch ( e ) {
			return '';
		}
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

	function respuestaApi( r, mensajeRed ) {
		return r.json().catch( function () {
			return {};
		} ).then( function ( datos ) {
			if ( ! r.ok ) {
				var error = new Error( datos.message || mensajeRed );
				error.datos = datos.data || {};
				error.codigo = datos.code || '';
				throw error;
			}
			return datos;
		} );
	}

	function api( ruta, cuerpo, metodo ) {
		var cabeceras = { 'Content-Type': 'application/json' };
		if ( config.nonce ) {
			cabeceras[ 'X-WP-Nonce' ] = config.nonce;
		}
		return fetch( config.api + ruta, {
			method: metodo || 'POST',
			credentials: 'same-origin',
			headers: cabeceras,
			body: cuerpo ? JSON.stringify( cuerpo ) : undefined,
		} ).then( function ( r ) {
			return respuestaApi( r, 'No se pudo conectar. Revisa tu internet.' );
		}, function () {
			throw new Error( 'No se pudo conectar. Revisa tu internet.' );
		} );
	}

	/** Envío con archivo (el recibo en PDF). */
	function apiArchivo( ruta, formulario ) {
		var cabeceras = {};
		if ( config.nonce ) {
			cabeceras[ 'X-WP-Nonce' ] = config.nonce;
		}
		return fetch( config.api + ruta, { method: 'POST', credentials: 'same-origin', headers: cabeceras, body: formulario } ).then( function ( r ) {
			return respuestaApi( r, 'No se pudo enviar. Revisa tu internet.' );
		}, function () {
			throw new Error( 'No se pudo enviar. Revisa tu internet.' );
		} );
	}

	/** Si la sesión venció, vuelve a la pantalla de entrar. Devuelve true si lo manejó. */
	function sesionVencida( error ) {
		if ( 'pka_sesion' === error.codigo ) {
			// La primera vez en este celular no hubo sesión: no hay nada que "terminó".
			vistaEntrar( yo || leer( 'pka_nombre' ) ? aviso( 'espera', error.message ) : null );
			return true;
		}
		return false;
	}

	function soportaPasskeys() {
		return window.isSecureContext && window.PublicKeyCredential && navigator.credentials;
	}

	function mensajePasskey( error ) {
		if ( error && 'NotAllowedError' === error.name ) {
			return 'Se canceló o no se reconoció tu huella. Inténtalo de nuevo.';
		}
		if ( error && 'InvalidStateError' === error.name ) {
			return 'Este celular ya tiene una llave para ti. Toca "Ir a la asistencia".';
		}
		return ( error && error.message ) || 'Algo salió mal. Inténtalo de nuevo.';
	}

	function navegadorNoSirve() {
		mostrarPestanas( null );
		pintar(
			el( 'div', { class: 'centro' }, [ el( 'div', { class: 'medallon espera' }, [ icono( 'alerta' ) ] ) ] ),
			el( 'h1', { texto: 'Este navegador no sirve' } ),
			aviso( 'error', 'Abre la asistencia en Chrome (Android) o Safari (iPhone), con el celular con bloqueo de pantalla.' )
		);
	}

	// ---------- pestañas ----------

	var SECCIONES = [
		{ clave: 'marcar', texto: 'Marcar', icono: 'huella', abrir: function () { cargarYo(); } },
		{ clave: 'horas', texto: 'Mis horas', icono: 'calendario', abrir: function () { vistaHoras( '' ); } },
		{ clave: 'recibo', texto: 'Mi recibo', icono: 'recibo', abrir: function () { vistaRecibos(); } },
		{ clave: 'perfil', texto: 'Perfil', icono: 'persona', abrir: function () { vistaPerfil(); } },
	];

	function mostrarPestanas( activa ) {
		document.body.classList.toggle( 'con-pestanas', !! activa );
		pestanas.hidden = ! activa;
		quien.hidden = ! activa || ! yo;

		if ( ! activa ) {
			return;
		}

		if ( ! pestanas.childNodes.length ) {
			SECCIONES.forEach( function ( s ) {
				pestanas.appendChild( el( 'button', { type: 'button', 'data-seccion': s.clave, alHacerClic: s.abrir }, [ icono( s.icono ), s.texto ] ) );
			} );
		}

		pestanas.querySelectorAll( 'button' ).forEach( function ( b ) {
			if ( b.getAttribute( 'data-seccion' ) === activa ) {
				b.setAttribute( 'aria-current', 'page' );
			} else {
				b.removeAttribute( 'aria-current' );
			}
		} );
	}

	quien.addEventListener( 'click', function () {
		vistaPerfil();
	} );

	function recordarPersona( datos ) {
		if ( datos.nombre ) {
			guardar( 'pka_nombre', datos.nombre );
		}
		if ( datos.iniciales ) {
			quien.textContent = datos.iniciales;
			quien.setAttribute( 'aria-label', 'Mi perfil' );
		}
	}

	// ---------- activación (T1) ----------

	function vistaActivar() {
		var token = location.hash.replace( /^#/, '' );
		// El token no debe quedar a la vista en la barra de direcciones ni en el historial.
		history.replaceState( null, '', location.pathname );

		if ( ! soportaPasskeys() ) {
			navegadorNoSirve();
			return;
		}

		if ( ! /^[A-Za-z0-9_-]{32}$/.test( token ) ) {
			pintar( el( 'h1', { texto: 'Enlace incompleto' } ), aviso( 'error', 'Abre el enlace completo que te dio RR.HH.' ) );
			return;
		}

		var crear = boton( 'principal', 'Crear mi llave', null, 'huella' );
		crear.disabled = true;
		var estado = el( 'div', {} );
		var cajaAviso = el( 'div', { class: 'aviso-datos', tabindex: '0', role: 'region', 'aria-label': 'Aviso de datos personales' }, [ el( 'p', { class: 'cargando', texto: 'Cargando el aviso…' } ) ] );
		var casilla = el( 'input', { type: 'checkbox', id: 'acepto' } );

		casilla.addEventListener( 'change', function () {
			crear.disabled = ! casilla.checked;
		} );

		api( 'aviso', null, 'GET' ).then( function ( a ) {
			cajaAviso.replaceChildren( textoAviso( a.texto ) );
		} ).catch( function () {
			cajaAviso.replaceChildren( aviso( 'error', 'No se pudo cargar el aviso. Recarga la página.' ) );
		} );

		crear.addEventListener( 'click', function () {
			crear.disabled = true;
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
						acepta_aviso: r.aviso.version,
						credencial: {
							id: cred.id,
							clientDataJSON: aBase64Url( cred.response.clientDataJSON ),
							attestationObject: aBase64Url( cred.response.attestationObject ),
						},
					} );
				} );
			} ).then( function () {
				guardar( 'pka_nombre', nombre );
				pintar(
					el( 'div', { class: 'centro' }, [ el( 'div', { class: 'medallon ok' }, [ icono( 'check' ) ] ) ] ),
					el( 'h1', {}, [ 'Listo, ', el( 'em', { texto: nombre } ) ] ),
					el( 'p', { texto: 'Tu celular quedó registrado. Desde ahora marcas tu asistencia con tu huella.' } ),
					el( 'p', { class: 'ayuda', texto: 'Agrega esta página a la pantalla de inicio para tenerla a mano.' } ),
					boton( 'principal', 'Ir a la asistencia', function () {
						location.href = config.inicio;
					} )
				);
			} ).catch( function ( error ) {
				crear.disabled = ! casilla.checked;
				estado.replaceChildren( aviso( 'error', mensajePasskey( error ) ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Activa tu ', el( 'em', { texto: 'celular' } ) ] ),
			el( 'p', { texto: 'Vas a crear una llave que se abre con la huella, el rostro o el PIN de este celular. Con ella marcarás tu asistencia.' } ),
			el( 'p', { class: 'ayuda', texto: 'Hazlo en tu propio celular: cada persona usa el suyo. Antes, lee cómo usamos tus datos:' } ),
			cajaAviso,
			el( 'label', { class: 'casilla', for: 'acepto' }, [ casilla, 'Leí el aviso de datos personales' ] ),
			crear,
			estado
		);
	}

	/** Párrafos, viñetas ("- ") y subtítulos (línea corta sin punto final) a partir del texto plano. */
	function textoAviso( texto ) {
		var cont = el( 'div', {} );
		texto.split( /\n\s*\n/ ).forEach( function ( bloque ) {
			var lineas = bloque.split( '\n' ).map( function ( l ) {
				return l.trim();
			} ).filter( Boolean );
			var lista = null;
			lineas.forEach( function ( linea, i ) {
				if ( 0 === linea.indexOf( '- ' ) ) {
					if ( ! lista ) {
						lista = el( 'ul', {} );
						cont.appendChild( lista );
					}
					lista.appendChild( el( 'li', { texto: linea.slice( 2 ) } ) );
					return;
				}
				lista = null;
				var esTitulo = linea.length < 60 && ! /[.:]$/.test( linea ) && 0 === i;
				cont.appendChild( el( esTitulo ? 'h2' : 'p', { texto: linea } ) );
			} );
		} );
		return cont;
	}

	/** Si RR.HH. cambió el aviso, se pide aceptarlo antes de seguir. */
	function vistaAceptarAviso( datos, despues ) {
		mostrarPestanas( null );
		var aceptar = boton( 'principal', 'Acepto y continúo' );
		var estado = el( 'div', {} );

		aceptar.addEventListener( 'click', function () {
			aceptar.disabled = true;
			api( 'aviso/aceptar', { ticket: datos.ticket, version: datos.aviso.version } ).then( function () {
				despues();
			} ).catch( function ( error ) {
				if ( 'pka_ticket' === error.codigo ) {
					vistaEntrar( aviso( 'error', error.message ) );
					return;
				}
				aceptar.disabled = false;
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Actualizamos el ', el( 'em', { texto: 'aviso' } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Cambió el aviso de datos personales. Léelo para seguir.' } ),
			el( 'div', { class: 'aviso-datos', tabindex: '0', role: 'region', 'aria-label': 'Aviso de datos personales' }, [ textoAviso( datos.aviso.texto ) ] ),
			el( 'div', { class: 'pie-fila' }, [ aceptar ] ),
			estado
		);
	}

	function vistaPrivacidad() {
		api( 'aviso', null, 'GET' ).then( function ( a ) {
			pintar(
				el( 'div', { class: 'aviso-datos abierto' }, [ textoAviso( a.texto ) ] ),
				el( 'a', { class: 'volver', href: config.inicio, texto: '← Volver a la asistencia' } )
			);
		} ).catch( function ( error ) {
			pintar( aviso( 'error', error.message ) );
		} );
	}

	// ---------- entrar (T0) ----------

	/** Pide la huella. Devuelve los datos de /identificar (ticket + estado) y deja la sesión iniciada. */
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
			ticket = { ticket: datos.ticket, vence: Date.now() + datos.segundos * 1000 };
			recordarPersona( datos );
			return datos;
		} );
	}

	/** Entrar (T0A): la huella es lo principal; el PIN, liberar el celular y el registro son alternativas. */
	function vistaEntrar( mensaje ) {
		yo = null;
		ticket = null;
		mostrarPestanas( null );

		if ( ! soportaPasskeys() ) {
			navegadorNoSirve();
			return;
		}

		var nombre = leer( 'pka_nombre' );
		var entrar = boton( 'principal', 'Entrar con mi huella', null, 'huella' );
		var estado = el( 'div', {}, [ mensaje || null ] );
		var alternativas = el( 'div', {} );

		entrar.addEventListener( 'click', function () {
			entrar.disabled = true;
			estado.replaceChildren();
			identificarse().then( function ( datos ) {
				if ( datos.aviso ) {
					vistaAceptarAviso( datos, function () {
						cargarYo();
					} );
					return;
				}
				cargarYo();
			} ).catch( function ( error ) {
				entrar.disabled = false;
				if ( 'pka_registro_pendiente' === error.codigo || 'pka_cambio_pendiente' === error.codigo ) {
					estado.replaceChildren( aviso( 'espera', error.message ) );
					return;
				}
				// T4D: celular no registrado → recuperar el perfil sin perder datos.
				estado.replaceChildren( aviso( 'error', mensajePasskey( error ), 'pka_passkey' === error.codigo ? boton( 'simple', 'Recuperar mi perfil', function () {
					vistaBienvenida( 'cambio' );
				} ) : null ) );
			} );
		} );

		pintar(
			el( 'div', { class: 'centro' }, [ el( 'div', { class: 'medallon rosa' }, [ icono( 'huella' ) ] ) ] ),
			el( 'h1', {}, nombre ? [ 'Hola, ', el( 'em', { texto: nombre } ) ] : [ 'Asistencia ', el( 'em', { texto: 'Pekokis' } ) ] ),
			el( 'p', { texto: 'Entra con tu huella o tu cara desde cualquier lugar para ver tus horas, tu recibo y tu perfil. Para marcar, el celular te la volverá a pedir en el taller.' } ),
			estado,
			el( 'div', { class: 'pie-fila' }, [ entrar ] ),
			alternativas
		);

		api( 'pin/estado', null, 'GET' ).then( function ( p ) {
			var hijos = [];
			if ( p.pin ) {
				hijos.push( boton( 'secundario', 'Entrar con PIN', function () {
					vistaPin( p.largo );
				} ) );
			}
			if ( p.registrado ) {
				hijos.push( boton( 'simple', 'Solicitar liberar este celular', function () {
					vistaLiberar( vistaEntrar );
				} ) );
			} else {
				hijos.push( el( 'p', { class: 'etq', texto: '¿Es la primera vez en este celular?' } ) );
				hijos.push( boton( 'secundario', 'Es mi primera vez', function () {
					vistaBienvenida( 'nuevo' );
				} ) );
				hijos.push( boton( 'secundario', 'Cambié de celular', function () {
					vistaBienvenida( 'cambio' );
				} ) );
			}
			alternativas.replaceChildren.apply( alternativas, hijos );
		} ).catch( function () {} );
	}

	/** Teclado grande para el PIN (T0B y T7C). Llama a alCompletar con los números al llegar al largo. */
	function tecladoPin( largo, alCompletar ) {
		var pin = '';
		var puntos = el( 'div', { class: 'pin-puntos', role: 'img', 'aria-label': 'Números escritos: 0 de ' + largo } );
		var dibujar = function () {
			var hijos = [];
			for ( var i = 0; i < largo; i++ ) {
				hijos.push( el( 'i', { class: i < pin.length ? 'lleno' : '' } ) );
			}
			puntos.replaceChildren.apply( puntos, hijos );
			puntos.setAttribute( 'aria-label', 'Números escritos: ' + pin.length + ' de ' + largo );
		};
		var teclas = [ '1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'borrar' ];
		var teclado = el( 'div', { class: 'teclado' }, teclas.map( function ( t ) {
			if ( '' === t ) {
				return el( 'span', {} );
			}
			return el( 'button', { type: 'button', 'aria-label': 'borrar' === t ? 'Borrar' : t, alHacerClic: function () {
				if ( 'borrar' === t ) {
					pin = pin.slice( 0, -1 );
				} else if ( pin.length < largo ) {
					pin += t;
				}
				dibujar();
				if ( pin.length === largo ) {
					var listo = pin;
					alCompletar( listo );
				}
			} }, [ 'borrar' === t ? '⌫' : t ] );
		} ) );
		dibujar();

		return {
			nodo: el( 'div', { class: 'pin' }, [ puntos, teclado ] ),
			reiniciar: function () {
				pin = '';
				dibujar();
			},
			apagar: function ( si ) {
				teclado.querySelectorAll( 'button' ).forEach( function ( b ) {
					b.disabled = si;
				} );
			},
		};
	}

	/** Entrar con PIN (T0B) y PIN incorrecto (T0C). Solo inicia sesión: marcar sigue pidiendo la huella. */
	function vistaPin( largo ) {
		mostrarPestanas( null );
		var estado = el( 'div', {} );
		var t = tecladoPin( largo, function ( pin ) {
			t.apagar( true );
			api( 'pin/entrar', { pin: pin } ).then( function () {
				cargarYo();
			} ).catch( function ( error ) {
				t.reiniciar();
				t.apagar( false );
				var bloqueado = 0 === ( error.datos || {} ).intentos;
				estado.replaceChildren( aviso( 'error', error.message, bloqueado ? boton( 'simple', 'Recuperar mi acceso', function () {
					vistaBienvenida( 'cambio' );
				} ) : null ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Entra con tu ', el( 'em', { texto: 'PIN' } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Tus ' + largo + ' números. Sirve solo para entrar: para marcar se sigue usando tu huella.' } ),
			t.nodo,
			estado,
			boton( 'simple', 'Volver a la huella', function () {
				vistaEntrar();
			} )
		);
	}

	/** Solicitar liberar este celular (T0D) y solicitud enviada (T0E). */
	function vistaLiberar( volver ) {
		var estado = el( 'div', {} );
		var pedir = boton( 'principal', 'Sí, pedir que lo liberen' );

		pedir.addEventListener( 'click', function () {
			pedir.disabled = true;
			api( 'celular/liberar', {} ).then( function ( r ) {
				pintar(
					el( 'div', { class: 'centro' }, [
						el( 'div', { class: 'medallon espera' }, [ icono( 'reloj' ) ] ),
						el( 'h1', {}, [ 'Solicitud ', el( 'em', { texto: 'enviada' } ) ] ),
						el( 'p', { texto: r.mensaje } ),
						el( 'p', { class: 'ayuda', texto: 'Para seguir marcando, registra tu celular nuevo dentro del taller con "Cambié de celular".' } ),
					] ),
					el( 'div', { class: 'pie-fila' }, [ boton( 'principal', 'Listo', function () {
						volver();
					} ) ] )
				);
			} ).catch( function ( error ) {
				pedir.disabled = false;
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ '¿Liberar este ', el( 'em', { texto: 'celular' } ), '?' ] ),
			el( 'p', { texto: 'Si lo vas a vender, regalar o cambiar, pide a gerencia que lo libere.' } ),
			el( 'ul', { class: 'bl-pasos lista-simple' }, [
				el( 'li', {}, [ el( 'span', { class: 'bp' }, [ icono( 'check' ) ] ), 'Tus datos y tus horas se conservan.' ] ),
				el( 'li', {}, [ el( 'span', { class: 'bp' }, [ icono( 'alerta' ) ] ), 'Cuando gerencia lo apruebe, este celular quedará sin acceso.' ] ),
				el( 'li', {}, [ el( 'span', { class: 'bp' }, [ icono( 'huella' ) ] ), 'Después registras tu celular nuevo en el taller.' ] ),
			] ),
			estado,
			el( 'div', { class: 'pie-fila' }, [ pedir ] ),
			boton( 'simple', 'Volver', function () {
				volver();
			} )
		);
	}

	// ---------- registro y cambio de celular (T1) ----------

	var MESES = [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];

	/** Cumpleaños con listas (T1C): día y mes, sin el año. */
	function selectorCumple( id ) {
		var dia = el( 'select', { id: id + '-dia', 'aria-label': 'Día' }, [ el( 'option', { value: '', texto: 'Día' } ) ] );
		var mes = el( 'select', { id: id + '-mes', 'aria-label': 'Mes' }, [ el( 'option', { value: '', texto: 'Mes' } ) ] );
		for ( var d = 1; d <= 31; d++ ) {
			dia.appendChild( el( 'option', { value: String( d ), texto: String( d ) } ) );
		}
		MESES.forEach( function ( nombre, i ) {
			mes.appendChild( el( 'option', { value: String( i + 1 ), texto: nombre } ) );
		} );
		return { dia: dia, mes: mes, nodo: el( 'div', { class: 'cumple' }, [ dia, mes ] ) };
	}

	/** Bienvenida (T1A): primero, comprobar que está en el taller (WiFi o QR). */
	function vistaBienvenida( tipo ) {
		mostrarPestanas( null );
		cargando( 'Comprobando que estás en el taller…' );

		api( 'registro/estado', null, 'GET' ).then( function ( r ) {
			if ( ! r.en_taller ) {
				var reintentar = function () {
					vistaBienvenida( tipo );
				};
				pintar(
					el( 'h1', {}, [ 'Hazlo en el ', el( 'em', { texto: 'taller' } ) ] ),
					el( 'p', { texto: 'nuevo' === tipo ? 'Para registrarte tienes que estar en el taller.' : 'Para registrar tu celular nuevo tienes que estar en el taller.' } ),
					bloqueoPresencia( { mensaje: r.mensaje, acepta_qr: r.acepta_qr }, reintentar ),
					r.acepta_qr ? el( 'div', { class: 'pie-fila' }, [ boton( 'principal', 'Escanear el QR', function () {
						vistaEscanear( reintentar );
					}, 'qr' ) ] ) : null,
					boton( 'simple', 'Volver', function () {
						vistaEntrar();
					} )
				);
				return;
			}
			if ( 'nuevo' === tipo ) {
				vistaDatos( r.aviso );
			} else {
				vistaBuscarPerfil();
			}
		} ).catch( function ( error ) {
			pintar( el( 'h1', { texto: 'Sin conexión' } ), aviso( 'error', error.message ), boton( 'principal', 'Reintentar', function () {
				vistaBienvenida( tipo );
			} ) );
		} );
	}

	/** Tus datos (T1B) con errores junto a cada campo (T1C). */
	function vistaDatos( avisoDatos ) {
		var campo = function ( id, etiqueta, atributos ) {
			var entrada = el( 'input', Object.assign( { type: 'text', id: 'reg-' + id, autocomplete: 'off' }, atributos || {} ) );
			var error = el( 'p', { class: 'error-campo', id: 'reg-' + id + '-error' } );
			return { entrada: entrada, error: error, nodo: el( 'div', {}, [ el( 'label', { for: 'reg-' + id, texto: etiqueta } ), entrada, error ] ) };
		};
		var c = {
			nombres: campo( 'nombres', 'Nombre', { autocomplete: 'given-name' } ),
			apellidos: campo( 'apellidos', 'Apellido', { autocomplete: 'family-name' } ),
			telefono: campo( 'telefono', 'Celular o WhatsApp', { inputmode: 'tel', autocomplete: 'tel', placeholder: '987 654 321', class: 'normal' } ),
			dni: campo( 'dni', 'DNI', { inputmode: 'numeric', maxlength: '12', class: 'normal' } ),
		};
		var cumple = selectorCumple( 'reg-cumple' );
		var errorCumple = el( 'p', { class: 'error-campo' } );
		var errorAviso = el( 'p', { class: 'error-campo' } );
		var casilla = el( 'input', { type: 'checkbox', id: 'reg-acepto' } );
		var estado = el( 'div', {} );
		var seguir = boton( 'principal', 'Continuar' );

		var revisar = function () {
			seguir.disabled = ! ( c.nombres.entrada.value.trim() && c.apellidos.entrada.value.trim() && c.telefono.entrada.value.trim() && c.dni.entrada.value.trim() && cumple.dia.value && cumple.mes.value && casilla.checked );
		};
		Object.keys( c ).forEach( function ( k ) {
			c[ k ].entrada.addEventListener( 'input', function () {
				c[ k ].error.textContent = '';
				revisar();
			} );
		} );
		[ cumple.dia, cumple.mes, casilla ].forEach( function ( x ) {
			x.addEventListener( 'change', revisar );
		} );
		c.dni.entrada.addEventListener( 'blur', function () {
			var dni = c.dni.entrada.value.replace( /[\s.-]/g, '' );
			c.dni.error.textContent = dni && ! /^\d{8}$/.test( dni ) && ! /^[A-Za-z0-9]{9,12}$/.test( dni ) ? 'El DNI tiene 8 números.' : '';
		} );
		revisar();

		seguir.addEventListener( 'click', function () {
			seguir.disabled = true;
			estado.replaceChildren();
			api( 'registro/nuevo', {
				nombres: c.nombres.entrada.value,
				apellidos: c.apellidos.entrada.value,
				telefono: c.telefono.entrada.value,
				dni: c.dni.entrada.value,
				cumple_dia: cumple.dia.value,
				cumple_mes: cumple.mes.value,
				acepta_aviso: casilla.checked ? avisoDatos.version : 0,
			} ).then( function ( op ) {
				vistaCrearLlave( op, 'registro' );
			} ).catch( function ( error ) {
				var porCampo = ( error.datos || {} ).errores || {};
				Object.keys( c ).forEach( function ( k ) {
					c[ k ].error.textContent = porCampo[ k ] || '';
				} );
				errorCumple.textContent = porCampo.cumpleanos || '';
				errorAviso.textContent = porCampo.aviso || '';
				if ( ! Object.keys( porCampo ).length ) {
					estado.replaceChildren( aviso( 'error', error.message ) );
				}
				revisar();
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Tus ', el( 'em', { texto: 'datos' } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Gerencia los revisará antes de que puedas marcar.' } ),
			el( 'div', { class: 'formulario' }, [
				c.nombres.nodo,
				c.apellidos.nodo,
				c.telefono.nodo,
				c.dni.nodo,
				el( 'div', {}, [ el( 'label', { for: 'reg-cumple-dia', texto: 'Cumpleaños' } ), cumple.nodo, errorCumple ] ),
			] ),
			el( 'p', { class: 'etq', texto: 'Cómo usamos tus datos' } ),
			el( 'div', { class: 'aviso-datos', tabindex: '0', role: 'region', 'aria-label': 'Aviso de datos personales' }, [ textoAviso( avisoDatos.texto ) ] ),
			el( 'label', { class: 'casilla', for: 'reg-acepto' }, [ casilla, 'Leí el aviso de datos personales' ] ),
			errorAviso,
			estado,
			el( 'div', { class: 'pie-fila' }, [ seguir ] ),
			boton( 'simple', 'Volver', function () {
				vistaEntrar();
			} )
		);
	}

	/** Crear la llave (T1D y T1I): la huella y la cara no salen del celular. */
	function vistaCrearLlave( op, tipo ) {
		var estado = el( 'div', {} );
		var crear = boton( 'principal', 'Crear mi llave', null, 'huella' );

		crear.addEventListener( 'click', function () {
			crear.disabled = true;
			estado.replaceChildren();
			var pk = op.publicKey;
			pk.challenge = 'string' === typeof pk.challenge ? deBase64Url( pk.challenge ) : pk.challenge;
			pk.user.id = 'string' === typeof pk.user.id ? deBase64Url( pk.user.id ) : pk.user.id;
			( pk.excludeCredentials || [] ).forEach( function ( c ) {
				c.id = 'string' === typeof c.id ? deBase64Url( c.id ) : c.id;
			} );
			navigator.credentials.create( { publicKey: pk } ).then( function ( cred ) {
				return api( 'registro/completar', {
					flujo: op.flujo,
					reto: op.reto,
					credencial: {
						id: cred.id,
						clientDataJSON: aBase64Url( cred.response.clientDataJSON ),
						attestationObject: aBase64Url( cred.response.attestationObject ),
					},
				} );
			} ).then( function ( r ) {
				guardar( 'pka_nombre', r.nombre );
				vistaSolicitudEnviada( r.tipo );
			} ).catch( function ( error ) {
				crear.disabled = false;
				estado.replaceChildren( aviso( 'error', mensajePasskey( error ) ) );
			} );
		} );

		pintar(
			el( 'div', { class: 'centro' }, [ el( 'div', { class: 'medallon rosa' }, [ icono( 'huella' ) ] ) ] ),
			el( 'h1', {}, [ 'registro' === tipo ? 'Crea tu ' : 'Llave de tu celular ', el( 'em', { texto: 'registro' === tipo ? 'llave' : 'nuevo' } ) ] ),
			el( 'p', { texto: 'Con ella entras y marcas usando la huella, la cara o el PIN de bloqueo de este celular.' } ),
			el( 'p', { class: 'ayuda', texto: 'Tu huella y tu cara no se guardan en el sistema: se quedan en tu celular.' } ),
			estado,
			el( 'div', { class: 'pie-fila' }, [ crear ] )
		);
	}

	/** Registro enviado (T1E) o cambio pedido (T1J): gerencia aprueba. */
	function vistaSolicitudEnviada( tipo ) {
		pintar(
			el( 'div', { class: 'centro' }, [
				el( 'div', { class: 'medallon espera' }, [ icono( 'reloj' ) ] ),
				el( 'h1', {}, 'registro' === tipo ? [ 'Registro ', el( 'em', { texto: 'enviado' } ) ] : [ 'Cambio ', el( 'em', { texto: 'pedido' } ) ] ),
				el( 'p', { texto: 'registro' === tipo ? 'Gerencia revisará tus datos. Cuando lo apruebe, podrás entrar y marcar con tu huella.' : 'Cuando gerencia lo apruebe, este celular quedará listo y el anterior se dará de baja. Tus datos y tus horas se conservan.' } ),
				'registro' === tipo ? null : el( 'p', { class: 'ayuda', texto: 'Mientras tanto, sigue marcando con tu celular anterior.' } ),
			] ),
			el( 'div', { class: 'pie-fila' }, [ boton( 'principal', 'Listo', function () {
				vistaEntrar();
			} ) ] )
		);
	}

	/** Buscar mi perfil (T1G): solo nombres. */
	function vistaBuscarPerfil() {
		var entrada = el( 'input', { type: 'text', id: 'buscar-perfil', autocomplete: 'off', placeholder: 'Escribe tu nombre' } );
		var lista = el( 'ul', { class: 'lista-mes', 'aria-live': 'polite' } );
		var estado = el( 'div', {} );
		var espera = null;

		entrada.addEventListener( 'input', function () {
			clearTimeout( espera );
			var texto = entrada.value.trim();
			if ( texto.length < 3 ) {
				lista.replaceChildren();
				return;
			}
			espera = setTimeout( function () {
				api( 'registro/buscar', { texto: texto } ).then( function ( r ) {
					estado.replaceChildren();
					lista.replaceChildren.apply( lista, r.perfiles.length ? r.perfiles.map( function ( p ) {
						return el( 'li', {}, [ el( 'button', { class: 'enlace-fila', type: 'button', alHacerClic: function () {
							vistaConfirmar( p );
						} }, [ el( 'span', { class: 'dia', texto: p.nombre } ), el( 'span', { class: 'pk-chip ok', texto: 'Soy yo' } ) ] ) ] );
					} ) : [ el( 'li', { class: 'vacio' }, [ el( 'b', { texto: 'No apareces' } ), el( 'span', { class: 'ayuda', texto: 'Revisa cómo lo escribiste o pide ayuda a gerencia.' } ) ] ) ] );
				} ).catch( function ( error ) {
					estado.replaceChildren( aviso( 'error', error.message ) );
				} );
			}, 350 );
		} );

		pintar(
			el( 'h1', {}, [ 'Busca tu ', el( 'em', { texto: 'perfil' } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Así conservas tus datos y tus horas. Escribe al menos 3 letras de tu nombre.' } ),
			el( 'div', { class: 'formulario' }, [ el( 'div', {}, [ el( 'label', { for: 'buscar-perfil', texto: 'Tu nombre' } ), entrada ] ) ] ),
			estado,
			lista,
			boton( 'simple', 'Volver', function () {
				vistaEntrar();
			} )
		);
	}

	/** Confirmar que soy yo (T1H): DNI completo y cumpleaños. */
	function vistaConfirmar( perfil ) {
		var dni = el( 'input', { type: 'text', id: 'conf-dni', inputmode: 'numeric', autocomplete: 'off', maxlength: '12', class: 'normal' } );
		var cumple = selectorCumple( 'conf-cumple' );
		var estado = el( 'div', {} );
		var seguir = boton( 'principal', 'Confirmar' );
		var revisar = function () {
			seguir.disabled = ! ( dni.value.trim() && cumple.dia.value && cumple.mes.value );
		};
		dni.addEventListener( 'input', revisar );
		cumple.dia.addEventListener( 'change', revisar );
		cumple.mes.addEventListener( 'change', revisar );
		revisar();

		seguir.addEventListener( 'click', function () {
			seguir.disabled = true;
			api( 'registro/confirmar', { trabajador_id: perfil.id, dni: dni.value, cumple_dia: cumple.dia.value, cumple_mes: cumple.mes.value } ).then( function ( op ) {
				vistaCrearLlave( op, 'cambio' );
			} ).catch( function ( error ) {
				revisar();
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Confirma que eres ', el( 'em', { texto: perfil.nombre } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Para que nadie tome el perfil de otra persona. Con 5 intentos equivocados se bloquea 15 minutos.' } ),
			el( 'div', { class: 'formulario' }, [
				el( 'div', {}, [ el( 'label', { for: 'conf-dni', texto: 'Tu DNI completo' } ), dni ] ),
				el( 'div', {}, [ el( 'label', { for: 'conf-cumple-dia', texto: 'Tu cumpleaños' } ), cumple.nodo ] ),
			] ),
			estado,
			el( 'div', { class: 'pie-fila' }, [ seguir ] ),
			boton( 'simple', 'No soy yo', function () {
				vistaBuscarPerfil();
			} )
		);
	}

	/** Crear o cambiar mi PIN (T7C): dos veces, para no equivocarse. */
	function vistaCrearPin( largo, cambiar ) {
		mostrarPestanas( 'perfil' );
		var primero = '';
		var titulo = el( 'h1', {}, [ cambiar ? 'Cambia tu ' : 'Crea tu ', el( 'em', { texto: 'PIN' } ) ] );
		var paso = el( 'p', { class: 'ayuda', texto: 'Elige ' + largo + ' números. No uses tu DNI ni 123456.' } );
		var estado = el( 'div', {} );
		var t = tecladoPin( largo, function ( pin ) {
			if ( ! primero ) {
				primero = pin;
				t.reiniciar();
				paso.textContent = 'Escríbelo otra vez para confirmarlo.';
				estado.replaceChildren();
				return;
			}
			if ( pin !== primero ) {
				primero = '';
				t.reiniciar();
				paso.textContent = 'Elige ' + largo + ' números. No uses tu DNI ni 123456.';
				estado.replaceChildren( aviso( 'error', 'Los dos PIN no son iguales. Empieza otra vez.' ) );
				return;
			}
			t.apagar( true );
			api( 'yo/pin', { pin: pin } ).then( function ( r ) {
				vistaPerfil( aviso( 'ok', r.mensaje ) );
			} ).catch( function ( error ) {
				if ( sesionVencida( error ) ) {
					return;
				}
				primero = '';
				t.reiniciar();
				t.apagar( false );
				paso.textContent = 'Elige ' + largo + ' números. No uses tu DNI ni 123456.';
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		pintar(
			titulo,
			paso,
			t.nodo,
			estado,
			el( 'p', { class: 'ayuda', texto: 'Sirve solo para entrar en este celular si tu huella no funciona. Para marcar se sigue usando tu huella.' } ),
			boton( 'simple', 'Volver', function () {
				vistaPerfil();
			} )
		);
	}

	// ---------- marcar (T2 y T3) ----------

	function cargarYo( mensaje ) {
		if ( ! yo ) {
			cargando();
		}
		return api( 'yo', null, 'GET' ).then( function ( r ) {
			yo = r;
			recordarPersona( r );
			vistaMarcar( mensaje );
		} ).catch( function ( error ) {
			if ( sesionVencida( error ) ) {
				return;
			}
			mostrarPestanas( null );
			pintar(
				el( 'h1', { texto: 'Sin conexión' } ),
				aviso( 'error', error.message ),
				boton( 'principal', 'Reintentar', function () {
					cargarYo( mensaje );
				} )
			);
		} );
	}

	function accionDe( clave ) {
		return ( yo.acciones || [] ).filter( function ( a ) {
			return a.clave === clave;
		} )[ 0 ] || { clave: clave, motivos: [] };
	}

	function elegirAccion( clave, etiqueta ) {
		var accion = accionDe( clave );
		if ( accion.motivos.length > 1 ) {
			vistaMotivos( accion, etiqueta );
		} else {
			marcar( clave, accion.motivos.length ? accion.motivos[ 0 ].clave : '', '' );
		}
	}

	function vistaMarcar( mensaje ) {
		mostrarPestanas( 'marcar' );
		var v = yo.vista;
		var p = yo.presencia || { cumple: true };
		var j = yo.jornada || {};

		var principal = null;
		var escanear = null;
		if ( v.siguiente && ! p.cumple && p.acepta_qr ) {
			// Enlace vencido o fuera del WiFi: lo primero es escanear (T2E).
			principal = boton( 'principal', 'Escanear el QR', function () {
				vistaEscanear();
			}, 'qr' );
		} else if ( v.siguiente ) {
			// T2G: la jornada de ayer sigue abierta: "Sigo aquí: marcar salida".
			var etiqueta = j.de_ayer && 'fin_jornada' === v.siguiente.accion ? 'Sigo aquí: marcar salida' : v.siguiente.etiqueta;
			principal = boton( 'principal', etiqueta, function () {
				elegirAccion( v.siguiente.accion, v.siguiente.etiqueta );
			}, 'huella' );
			principal.disabled = ! p.cumple;
			escanear = p.acepta_qr && ! p.en_red ? el( 'button', { class: 'cuad', type: 'button', 'aria-label': 'Escanear el QR de la entrada', alHacerClic: function () {
				vistaEscanear();
			} }, [ icono( 'qr' ), 'Escanear' ] ) : null;
		}

		var otras = ( v.otras || [] ).map( function ( o ) {
			var b = boton( 'secundario', o.etiqueta, function () {
				elegirAccion( o.accion, o.etiqueta );
			} );
			b.disabled = ! p.cumple;
			return b;
		} );

		pintar(
			el( 'p', { class: 'fecha', texto: ( j.otro_dia ? 'Jornada del ' : '' ) + yo.dia.texto + ( yo.dia.horario ? ' · ' + yo.dia.horario : '' ) } ),
			el( 'h1', {}, [ 'Hola, ', el( 'em', { texto: yo.nombre } ) ] ),
			mensaje || null,
			yo.recordatorio ? aviso( 'espera', yo.recordatorio ) : null,
			yo.tardanza ? aviso( yo.tardanza.nivel > 1 ? 'error' : 'espera', yo.tardanza.texto ) : null,
			j.de_ayer ? aviso( 'espera', 'Tu jornada del ' + j.texto + ' sigue abierta: no marcaste la salida.', boton( 'simple', 'Salí ayer y olvidé marcar', function () {
				vistaHoras( '', j.dia );
			} ) ) : null,
			j.otro_dia && ! j.de_ayer && ! v.terminado ? aviso( 'info', 'Pasó la medianoche: sigues en tu jornada del ' + j.texto + '. Sus horas cuentan en ese día.' ) : null,
			yo.ayer ? aviso( 'espera', 'Ayer no marcaste tu salida.', boton( 'simple', 'Pedir la corrección', function () {
				vistaHoras( '', yo.ayer.fecha );
			} ) ) : null,
			yo.aviso_pendiente ? aviso( 'info', 'Actualizamos el aviso de datos personales. Te lo mostraremos al marcar.' ) : null,
			tarjetaPresencia( p ),
			heroDelDia( v ),
			el( 'p', { class: 'etq', texto: 'Tu día' } ),
			lineaDelDia( v.pasos, false ),
			p.cumple ? null : bloqueoPresencia( p ),
			principal || escanear ? el( 'div', { class: 'pie-fila' }, [ principal, escanear ] ) : null
		);
		otras.forEach( function ( b ) {
			contenido.appendChild( b );
		} );
		contenido.appendChild( boton( 'simple', '¿Olvidaste marcar algo?', vistaOlvido ) );
	}

	function tarjetaPresencia( p ) {
		if ( p.cumple && p.en_red ) {
			return el( 'div', { class: 'enlace' }, [
				el( 'span', { class: 'e-ic' }, [ icono( 'wifi' ) ] ),
				el( 'div', { class: 'e-tx' }, [ el( 'b', { texto: 'Estás en el WiFi del taller' } ), el( 'small', { texto: 'Puedes marcar.' } ) ] ),
			] );
		}

		if ( p.cumple && p.pase > 0 ) {
			var total = Math.max( p.pase, config.pase_segundos || 180 );
			var fin = Date.now() + p.pase * 1000;
			var texto = el( 'small', {} );
			var titulo = el( 'b', {} );
			var barra = el( 'i', {} );
			var tic = function () {
				var resta = Math.max( 0, Math.round( ( fin - Date.now() ) / 1000 ) );
				if ( resta > 120 ) {
					titulo.textContent = 'Enlace vigente · ' + Math.ceil( resta / 60 ) + ' min';
					texto.textContent = 'Puedes marcar.';
				} else {
					titulo.textContent = 'Enlace vigente';
					texto.textContent = resta ? 'Vence en ' + Math.floor( resta / 60 ) + ':' + ( '0' + ( resta % 60 ) ).slice( -2 ) : 'Venció. Escanea el QR otra vez.';
				}
				barra.style.width = Math.round( 100 * resta / total ) + '%';
				if ( ! resta ) {
					cargarYo();
				}
			};
			tic();
			cadaRato( tic, 1000 );
			return el( 'div', { class: 'enlace' }, [
				el( 'span', { class: 'e-ic' }, [ icono( 'qr' ) ] ),
				el( 'div', { class: 'e-tx' }, [ titulo, el( 'div', { class: 'e-bar' }, [ barra ] ), texto ] ),
			] );
		}

		// Sin presencia, el aviso va en el bloque de abajo, junto a los botones.
		return null;
	}

	function bloqueoPresencia( p, revisarOtraVez ) {
		var pasos = [ el( 'li', {}, [ el( 'span', { class: 'bp' }, [ icono( 'wifi' ) ] ), 'Conéctate al WiFi del taller' ] ) ];
		if ( p.acepta_qr ) {
			pasos.push( el( 'li', {}, [ el( 'span', { class: 'bp' }, [ icono( 'qr' ) ] ), 'O escanea el QR de la pantalla de la entrada' ] ) );
		}
		return el( 'div', { class: 'bloqueo' }, [
			el( 'h2', { texto: 'Para marcar tienes que estar en el taller' } ),
			el( 'p', { texto: p.mensaje } ),
			el( 'ul', { class: 'bl-pasos' }, pasos ),
			boton( 'simple', 'Ya me conecté: revisar otra vez', function () {
				( revisarOtraVez || cargarYo )();
			} ),
		] );
	}

	function heroDelDia( v ) {
		var fila = function ( clase, chico, grande, abajo, barra ) {
			return el( 'div', { class: 'hero' + ( clase ? ' ' + clase : '' ) }, [
				el( 'small', { texto: chico } ),
				el( 'div', { class: 'grande', texto: grande } ),
				barra || null,
				abajo ? el( 'p', { texto: abajo } ) : null,
			] );
		};

		if ( v.terminado ) {
			return fila( 'lima', 'Jornada terminada', duracion( v.trabajado ), v.extra ? 'Incluye ' + duracion( v.extra ) + ' de horas extra.' : '¡Buen trabajo! Hasta mañana.' );
		}

		if ( v.comida ) {
			return contador( fila( 'turq', 'En comida desde las ' + v.comida.desde, duracion( v.comida.minutos ), 'Al volver, marca "Volver de comer".' ), v.comida.minutos, function ( m ) {
				return duracion( m );
			} );
		}

		if ( v.fuera ) {
			return contador( fila( 'turq', v.fuera.motivo + ' · desde las ' + v.fuera.desde, duracion( v.fuera.minutos ), 'Al regresar, marca "Volver al taller".' ), v.fuera.minutos, function ( m ) {
				return duracion( m );
			} );
		}

		if ( 'trabajando' === v.estado ) {
			var barra = el( 'div', { class: 'barrita' }, [ el( 'i', {} ) ] );
			var tarjeta = fila( '', 'Llevas trabajando', duracion( v.trabajado ), '', barra );
			var abajo = el( 'p', {} );
			tarjeta.appendChild( abajo );
			var pintarAbajo = function ( m ) {
				barra.firstChild.style.width = v.esperados ? Math.min( 100, Math.round( 100 * m / v.esperados ) ) + '%' : '100%';
				abajo.textContent = v.esperados ? ( m < v.esperados ? 'Te faltan ' + duracion( v.esperados - m ) + ' de tu jornada.' : 'Ya completaste tu jornada.' ) : 'Hoy no está en tu horario: estas horas se pagan aparte.';
				return duracion( m );
			};
			pintarAbajo( v.trabajado );
			return contador( tarjeta, v.trabajado, pintarAbajo );
		}

		return fila( '', yo.dia.horario ? 'Tu horario de hoy' : 'Hoy', yo.dia.horario || ( yo.dia.descanso || 'Día libre' ), yo.dia.horario ? 'Marca tu entrada al llegar al taller.' : 'Si vienes a trabajar, marca tu entrada.' );
	}

	/** Suma un minuto a la tarjeta grande mientras la pantalla esté abierta. */
	function contador( tarjeta, minutos, texto ) {
		var inicio = Date.now();
		var grande = tarjeta.querySelector( '.grande' );
		cadaRato( function () {
			grande.textContent = texto( minutos + Math.floor( ( Date.now() - inicio ) / 60000 ) );
		}, 20000 );
		return tarjeta;
	}

	/** Los cuatro pasos de "Tu día". En días pasados, lo que falta va en rojo. */
	function lineaDelDia( pasos, pasado ) {
		return el( 'ol', { class: 'linea' }, pasos.map( function ( paso ) {
			var estado = paso.estado;
			if ( pasado && 'hecho' !== estado ) {
				estado = 'sig' === estado ? 'falta' : 'omitido';
			}
			var punto = 'hecho' === estado ? icono( 'check' ) : ( 'sig' === estado ? el( 'i', {} ) : null );
			var derecha;
			if ( 'hecho' === estado ) {
				derecha = el( 'b', { texto: paso.hora } );
			} else if ( 'sig' === estado ) {
				derecha = el( 'em', { texto: 'Siguiente' } );
			} else if ( 'falta' === estado ) {
				derecha = el( 'b', { texto: 'Falta' } );
			} else {
				derecha = el( 'b', { class: 'pd', texto: 'omitido' === estado ? 'Sin marcar' : 'Pendiente' } );
			}
			return el( 'li', { class: estado }, [
				el( 'span', { class: 'pt', 'aria-hidden': 'true' }, [ punto ] ),
				el( 'div', { class: 'tx' }, [ el( 'span', { texto: paso.nombre } ), derecha ] ),
			] );
		} ) );
	}

	function vistaMotivos( accion, etiqueta ) {
		mostrarPestanas( 'marcar' );
		var nota = el( 'input', { type: 'text', id: 'nota', maxlength: '200', autocomplete: 'off', placeholder: 'Ej.: llevo pedidos a Olva' } );
		var elegido = '';
		var seguir = boton( 'principal', 'Marcar con mi huella', function () {
			marcar( accion.clave, elegido, nota.value );
		}, 'huella' );
		seguir.disabled = true;

		var opciones = el( 'fieldset', { class: 'opciones' }, [ el( 'legend', { texto: 'Motivo' } ) ].concat( accion.motivos.map( function ( m ) {
			var radio = el( 'input', { type: 'radio', name: 'motivo', value: m.clave } );
			radio.addEventListener( 'change', function () {
				elegido = m.clave;
				seguir.disabled = false;
			} );
			return el( 'label', {}, [ radio, el( 'span', { texto: m.nombre } ) ] );
		} ) ) );

		pintar(
			el( 'h1', {}, [ '¿Por qué ', el( 'em', { texto: 'sales' } ), '?' ] ),
			el( 'p', { class: 'ayuda', texto: etiqueta || 'Elige el motivo de la salida.' } ),
			opciones,
			el( 'div', { class: 'formulario' }, [ el( 'div', {}, [ el( 'label', { for: 'nota', texto: 'Nota (opcional)' } ), nota ] ) ] ),
			el( 'div', { class: 'pie-fila' }, [ seguir ] ),
			boton( 'secundario', 'Volver', function () {
				vistaMarcar();
			} )
		);
	}

	/** Usa la huella recién puesta (2 minutos) o la pide otra vez. */
	function conTicket() {
		if ( ticket && Date.now() < ticket.vence - 5000 ) {
			var t = ticket.ticket;
			ticket = null; // Un ticket por marca.
			return Promise.resolve( t );
		}
		return identificarse().then( function ( datos ) {
			if ( datos.aviso ) {
				vistaAceptarAviso( datos, function () {
					cargarYo();
				} );
				var e = new Error( '' );
				e.codigo = 'pka_aviso_pendiente';
				throw e;
			}
			ticket = null;
			return datos.ticket;
		} );
	}

	function marcar( accion, motivo, nota ) {
		contenido.querySelectorAll( 'button' ).forEach( function ( b ) {
			b.disabled = true;
		} );

		conTicket().then( function ( t ) {
			return api( 'marcas', { ticket: t, accion: accion, motivo: motivo || '', nota: nota || '' } );
		} ).then( function ( r ) {
			vistaMarcada( accion, r );
		} ).catch( function ( error ) {
			if ( 'pka_aviso_pendiente' === error.codigo ) {
				return;
			}
			var mensaje = aviso( 'error', mensajePasskey( error ) );
			if ( 'pka_sin_presencia' === error.codigo ) {
				mensaje = aviso( 'error', error.message );
			}
			cargarYo( mensaje );
		} );
	}

	var TITULOS = {
		regreso: [ 'De vuelta en el ', 'taller' ],
		salida_refrigerio: [ '¡Buen ', 'provecho!' ],
		salida_temporal: [ 'Salida ', 'registrada' ],
		fin_jornada: [ '¡Hasta ', 'mañana!' ],
	};

	function vistaMarcada( accion, r ) {
		yo = Object.assign( {}, yo, r );
		mostrarPestanas( 'marcar' );
		var v = r.vista || {};
		var titulo = 'rebote' === r.resultado ? [ 'Ya estaba ', 'registrada' ] : ( TITULOS[ accion ] || [ 'Marca ', 'registrada' ] );
		// La entrada saluda por el nombre.
		if ( 'entrada' === accion && 'rebote' !== r.resultado ) {
			titulo = [ '¡Buen día, ', ( yo.nombre || '' ) + '!' ];
		}

		var resumen = null;
		if ( 'fin_jornada' === accion && v.terminado ) {
			resumen = el( 'div', { class: 'pk-kpis dos' }, [
				el( 'div', { class: 'pk-kpi rosa compacto' }, [ el( 'span', { texto: 'Trabajaste' } ), el( 'b', { texto: duracion( v.trabajado ) } ) ] ),
				el( 'div', { class: 'pk-kpi rosa compacto' }, [ el( 'span', { texto: 'Horas extra' } ), el( 'b', { texto: v.extra ? duracion( v.extra ) : '—' } ) ] ),
			] );
		}

		pintar(
			el( 'div', { class: 'centro' }, [
				el( 'div', { class: 'medallon ' + ( r.revision ? 'espera' : 'ok' ) }, [ icono( r.revision ? 'reloj' : 'check' ) ] ),
				el( 'h1', {}, [ titulo[0], el( 'em', { texto: titulo[1] } ) ] ),
				el( 'p', { class: 'hora', texto: horaAhora() } ),
				el( 'p', { class: 'ayuda', texto: r.mensaje } ),
			] ),
			r.tardanza ? aviso( r.tardanza.nivel > 1 ? 'error' : 'espera', r.tardanza.texto ) : null,
			r.larga ? aviso( 'espera', 'Tu jornada duró ' + r.larga + ' desde tu entrada. Se registró igual y gerencia la revisará.' ) : ( r.revision ? aviso( 'espera', 'Gerencia revisará esta marca porque no se pudo comprobar que estabas en el taller.' ) : null ),
			'fin_jornada' === accion && r.jornada && r.jornada.otro_dia ? aviso( 'info', 'Es la salida de tu jornada del ' + r.jornada.texto + '. Las horas de madrugada cuentan en ese día.' ) : null,
			resumen,
			v.pasos ? lineaDelDia( v.pasos, false ) : null,
			el( 'div', { class: 'pie-fila' }, [ boton( 'principal', 'Listo', function () {
				cargarYo();
			} ) ] )
		);

		var volver = setTimeout( function () {
			cargarYo();
		}, 20000 );
		relojes.push( volver ); // clearInterval también limpia un setTimeout.
	}

	// ---------- QR (T2 escanear) ----------

	function apagarCamara() {
		if ( camara ) {
			camara.getTracks().forEach( function ( pista ) {
				pista.stop();
			} );
			camara = null;
		}
	}

	function validarQr( texto ) {
		var datos = /^\d{6}$/.test( texto ) ? { codigo: texto } : null;
		if ( ! datos ) {
			try {
				var q = new URL( texto ).searchParams.get( 'q' );
				datos = q ? { token: q } : null;
			} catch ( e ) {}
		}
		if ( ! datos ) {
			return Promise.reject( new Error( 'Ese no es el QR de la asistencia.' ) );
		}
		return api( 'presencia/codigo', datos );
	}

	function vistaEscanear( despues ) {
		// Con la sesión iniciada vuelve a Marcar; desde el registro, a la bienvenida.
		var seguir = 'function' === typeof despues ? despues : cargarYo;
		mostrarPestanas( yo ? 'marcar' : null );
		var estado = el( 'div', {} );
		var escribir = boton( 'secundario', 'Escribir el código de la pantalla', function () {
			vistaCodigo( seguir );
		} );
		var volver = boton( 'simple', 'Volver', function () {
			seguir();
		} );

		if ( ! ( 'BarcodeDetector' in window ) || ! navigator.mediaDevices ) {
			// iPhone: sin lector en la web. El código de 6 números hace lo mismo.
			vistaCodigo( seguir );
			return;
		}

		var video = el( 'video', { playsinline: 'playsinline', muted: 'muted', autoplay: 'autoplay' } );
		pintar(
			el( 'h1', {}, [ 'Escanea el ', el( 'em', { texto: 'QR' } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Apunta a la pantalla de la entrada del taller.' } ),
			el( 'div', { class: 'escaner' }, [ video ] ),
			estado,
			escribir,
			volver
		);

		var lector = new window.BarcodeDetector( { formats: [ 'qr_code' ] } );
		var leyendo = false;

		navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' }, audio: false } ).then( function ( flujo ) {
			camara = flujo;
			video.srcObject = flujo;
			cadaRato( function () {
				if ( leyendo || video.readyState < 2 ) {
					return;
				}
				leyendo = true;
				lector.detect( video ).then( function ( codigos ) {
					if ( ! codigos.length ) {
						leyendo = false;
						return;
					}
					apagarCamara();
					validarQr( codigos[0].rawValue ).then( function ( r ) {
						seguir( aviso( 'ok', r.mensaje ) );
					} ).catch( function ( error ) {
						estado.replaceChildren( aviso( 'error', error.message ) );
						setTimeout( function () {
							vistaEscanear( seguir );
						}, 2500 );
					} );
				} ).catch( function () {
					leyendo = false;
				} );
			}, 400 );
		} ).catch( function () {
			estado.replaceChildren( aviso( 'espera', 'No se pudo abrir la cámara. Escribe el código que se ve debajo del QR.' ) );
		} );
	}

	/** El código de 6 números que se ve debajo del QR. */
	function vistaCodigo( despues ) {
		var seguir = 'function' === typeof despues ? despues : cargarYo;
		mostrarPestanas( yo ? 'marcar' : null );
		var entrada = el( 'input', { type: 'text', id: 'codigo', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '7', placeholder: '000 000' } );
		var estado = el( 'div', {} );
		var validar = boton( 'principal', 'Validar código' );

		validar.addEventListener( 'click', function () {
			validar.disabled = true;
			validarQr( entrada.value.replace( /\D/g, '' ) ).then( function ( r ) {
				seguir( aviso( 'ok', r.mensaje ) );
			} ).catch( function ( error ) {
				validar.disabled = false;
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Código de la ', el( 'em', { texto: 'pantalla' } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Escribe los 6 números que se ven debajo del QR, en la pantalla de la entrada.' } ),
			el( 'div', { class: 'formulario' }, [ el( 'div', {}, [ el( 'label', { for: 'codigo', texto: 'Código' } ), entrada ] ) ] ),
			estado,
			el( 'div', { class: 'pie-fila' }, [ validar ] ),
			boton( 'simple', 'Volver', function () {
				seguir();
			} )
		);
	}

	/** Si se llegó escaneando el QR con la cámara del celular (/m?q=…), valida la presencia antes de seguir. */
	function leerQr() {
		var token = new URLSearchParams( location.search ).get( 'q' );
		if ( ! token ) {
			return Promise.resolve( null );
		}
		history.replaceState( null, '', config.inicio );
		return api( 'presencia/codigo', { token: token } ).then( function ( r ) {
			return aviso( 'ok', r.mensaje );
		}, function ( error ) {
			return aviso( 'error', error.message );
		} );
	}

	// ---------- olvidé marcar (hoy o ayer) ----------

	var MOTIVOS_OLVIDO = [ 'Olvidé marcar', 'Falló el WiFi', 'Se cerró la página', 'Otro' ];

	function opcionesOlvido( alElegir ) {
		return el( 'fieldset', { class: 'opciones' }, [ el( 'legend', { texto: '¿Qué pasó?' } ) ].concat( MOTIVOS_OLVIDO.map( function ( texto ) {
			var radio = el( 'input', { type: 'radio', name: 'que-paso', value: texto } );
			radio.addEventListener( 'change', function () {
				alElegir( texto );
			} );
			return el( 'label', {}, [ radio, el( 'span', { texto: texto } ) ] );
		} ) ) );
	}

	function vistaOlvido() {
		mostrarPestanas( 'marcar' );
		var ayer = new Date( yo.fecha + 'T12:00:00' );
		ayer.setDate( ayer.getDate() - 1 );
		var fechaAyer = ayer.toISOString().slice( 0, 10 );

		var fecha = el( 'select', { id: 'olvido-fecha' }, [
			el( 'option', { value: yo.fecha, texto: 'Hoy' } ),
			el( 'option', { value: fechaAyer, texto: 'Ayer' } ),
		] );
		var tipo = el( 'select', { id: 'olvido-tipo' }, yo.olvido.map( function ( o ) {
			return el( 'option', { value: o.clave, texto: o.etiqueta } );
		} ) );
		var motivo = el( 'select', { id: 'olvido-motivo' } );
		var filaMotivo = el( 'div', {}, [ el( 'label', { for: 'olvido-motivo', texto: 'Tipo de salida' } ), motivo ] );
		var hora = el( 'input', { type: 'time', id: 'olvido-hora', required: 'required' } );
		var detalle = el( 'textarea', { id: 'olvido-detalle', rows: '2', maxlength: '300', placeholder: 'Opcional' } );
		var quePaso = '';
		var estado = el( 'div', {} );

		function actualizarMotivos() {
			var opcion = yo.olvido.filter( function ( o ) {
				return o.clave === tipo.value;
			} )[ 0 ];
			motivo.replaceChildren.apply( motivo, opcion.motivos.map( function ( m ) {
				return el( 'option', { value: m.clave, texto: m.nombre } );
			} ) );
			filaMotivo.hidden = opcion.motivos.length < 2;
		}

		tipo.addEventListener( 'change', actualizarMotivos );
		actualizarMotivos();

		var enviar = boton( 'principal', 'Enviar a gerencia' );
		enviar.addEventListener( 'click', function () {
			enviar.disabled = true;
			enviarCorreccion( {
				fecha: fecha.value,
				hora: hora.value,
				accion: tipo.value,
				motivo: motivo.value || '',
				justificacion: justificacion( quePaso, detalle.value ),
			}, estado, enviar, function () {
				cargarYo();
			} );
		} );

		pintar(
			el( 'h1', {}, [ '¿Qué ', el( 'em', { texto: 'olvidaste' } ), '?' ] ),
			el( 'p', { class: 'ayuda', texto: 'Para días anteriores, entra a Mis horas y toca el día.' } ),
			el( 'div', { class: 'formulario' }, [
				el( 'div', {}, [ el( 'label', { for: 'olvido-fecha', texto: 'Día' } ), fecha ] ),
				el( 'div', {}, [ el( 'label', { for: 'olvido-tipo', texto: 'Marca' } ), tipo ] ),
				filaMotivo,
				el( 'div', {}, [ el( 'label', { for: 'olvido-hora', texto: '¿A qué hora fue?' } ), hora ] ),
				opcionesOlvido( function ( t ) {
					quePaso = t;
				} ),
				el( 'div', {}, [ el( 'label', { for: 'olvido-detalle', texto: 'Comentario' } ), detalle ] ),
			] ),
			estado,
			el( 'div', { class: 'pie-fila' }, [ enviar ] ),
			boton( 'simple', 'Volver', function () {
				vistaMarcar();
			} )
		);
	}

	function justificacion( quePaso, comentario ) {
		comentario = ( comentario || '' ).trim();
		return [ quePaso, comentario ].filter( Boolean ).join( ': ' );
	}

	function enviarCorreccion( datos, estado, enviar, alTerminar ) {
		if ( ! datos.hora ) {
			enviar.disabled = false;
			estado.replaceChildren( aviso( 'error', 'Escribe la hora.' ) );
			return;
		}
		if ( ! datos.justificacion ) {
			enviar.disabled = false;
			estado.replaceChildren( aviso( 'error', 'Elige qué pasó.' ) );
			return;
		}
		api( 'correcciones', datos ).then( function ( r ) {
			pintar(
				el( 'div', { class: 'centro' }, [
					el( 'div', { class: 'medallon espera' }, [ icono( 'reloj' ) ] ),
					el( 'h1', {}, [ 'Solicitud ', el( 'em', { texto: 'enviada' } ) ] ),
					el( 'p', { texto: 'Gerencia la revisará. La marca cuenta recién cuando la aprueban.' } ),
					el( 'p', { class: 'ayuda', texto: 'Verás la respuesta en Mis horas, al tocar el día.' } ),
				] ),
				el( 'div', { class: 'pie-fila' }, [ boton( 'principal', 'Listo', alTerminar ) ] )
			);
		} ).catch( function ( error ) {
			if ( sesionVencida( error ) ) {
				return;
			}
			enviar.disabled = false;
			estado.replaceChildren( aviso( 'error', error.message ) );
		} );
	}

	// ---------- mis horas (T5 y T6) ----------

	function vistaHoras( mes, abrirFecha ) {
		mostrarPestanas( 'horas' );
		cargando( 'Cargando tus horas…' );

		api( 'yo/mes', { mes: mes } ).then( function ( r ) {
			if ( abrirFecha && r.detalle[ abrirFecha ] ) {
				vistaDiaDetalle( r, abrirFecha );
				return;
			}
			pintarHoras( r );
		} ).catch( function ( error ) {
			if ( sesionVencida( error ) ) {
				return;
			}
			pintar( el( 'h1', { texto: 'Mis horas' } ), aviso( 'error', error.message ) );
		} );
	}

	function pintarHoras( r ) {
		mostrarPestanas( 'horas' );
		var esAnterior = 'Este mes' === r.otro.etiqueta;
		var anterior = el( 'button', { type: 'button', 'aria-label': 'Mes anterior', texto: '‹', alHacerClic: function () {
			vistaHoras( r.otro.mes );
		} } );
		var siguiente = el( 'button', { type: 'button', 'aria-label': 'Este mes', texto: '›', alHacerClic: function () {
			vistaHoras( r.otro.mes );
		} } );
		anterior.disabled = esAnterior;
		siguiente.disabled = ! esAnterior;

		var porCorregir = Object.keys( r.detalle ).sort().reverse().filter( function ( f ) {
			return r.detalle[ f ].pedir.length;
		} );

		var resumen = r.resumen.hay ? el( 'div', { class: 'pk-kpis tres' }, [
			el( 'div', { class: 'pk-kpi rosa compacto' }, [ el( 'span', { texto: 'Trabajadas' } ), el( 'b', { texto: r.resumen.horas } ) ] ),
			el( 'div', { class: 'pk-kpi rosa compacto' }, [ el( 'span', { texto: 'Extra' } ), el( 'b', { texto: r.resumen.extra } ) ] ),
			el( 'div', { class: 'pk-kpi rosa compacto' }, [ el( 'span', { texto: 'Días completos' } ), el( 'b', { texto: String( r.resumen.completos ) } ) ] ),
		] ) : el( 'div', { class: 'vacio' }, [ el( 'b', { texto: 'Todavía no hay horas este mes' } ), el( 'span', { class: 'ayuda', texto: 'Cuando marques tu primera jornada, aquí verás tus horas.' } ) ] );

		pintar(
			el( 'div', { class: 'mes-nav' }, [ anterior, el( 'h1', { texto: r.etiqueta } ), siguiente ] ),
			resumen,
			r.pendientes ? aviso( 'espera', 'Tienes ' + r.pendientes + ( 1 === r.pendientes ? ' solicitud' : ' solicitudes' ) + ' esperando respuesta.' ) : null,
			porCorregir.length ? el( 'p', { class: 'etq', texto: 'Te falta marcar' } ) : null,
			porCorregir.length ? el( 'ul', { class: 'lista-mes' }, porCorregir.slice( 0, 3 ).map( function ( f ) {
				var d = r.detalle[ f ];
				return el( 'li', { class: 'destacado' }, [ el( 'button', { class: 'enlace-fila', type: 'button', alHacerClic: function () {
					vistaDiaDetalle( r, f );
				} }, [
					el( 'span', { class: 'dia', texto: d.titulo } ),
					el( 'span', { class: 'pk-chip error', texto: d.falta ? 'Sin marcas' : 'Falta la ' + d.pedir[0].paso.toLowerCase() } ),
					el( 'span', { class: 'horas', texto: 'Toca para pedir la corrección' } ),
				] ) ] );
			} ) ) : null,
			porCorregir.length > 3 ? el( 'p', { class: 'ayuda', texto: 'Y ' + ( porCorregir.length - 3 ) + ( 4 === porCorregir.length ? ' día más' : ' días más' ) + ': tócalos en el calendario.' } ) : null,
			r.calendario ? calendarioMes( r ) : null,
			el( 'p', { class: 'ayuda', texto: 'Toca un día para ver sus marcas. Las horas extra se pagan aparte del honorario.' } )
		);
	}

	/** Calendario del mes con un color por estado (los mismos del informe del panel). */
	function calendarioMes( r ) {
		var cal = r.calendario;
		var celdas = [ 'L', 'M', 'M', 'J', 'V', 'S', 'D' ].map( function ( d ) {
			return el( 'span', { class: 'pk-cal-cab', texto: d, 'aria-hidden': 'true' } );
		} );

		for ( var i = 0; i < cal.vacios; i++ ) {
			celdas.push( el( 'span', { class: 'pk-cal-vacio', 'aria-hidden': 'true' } ) );
		}

		cal.dias.forEach( function ( d ) {
			var nombre = d.n + ': ' + ( d.texto || 'sin datos' ) + ( d.horas ? ', ' + d.horas + ' h' : '' ) + ( d.tardanza ? ', ' + d.tardanza + ' min tarde' : '' );
			var conDetalle = !! r.detalle[ d.fecha ];
			var hijos = [
				el( 'strong', { texto: String( d.n ) } ),
				d.corto ? el( 'small', { class: 'e', texto: d.corto, 'aria-hidden': 'true' } ) : null,
				d.horas ? el( 'small', { class: 'h', texto: d.horas, 'aria-hidden': 'true' } ) : null,
			];
			celdas.push( conDetalle ? el( 'button', { type: 'button', class: 'pk-cal-dia pk-est-' + d.estado, 'aria-label': nombre, alHacerClic: function () {
				vistaDiaDetalle( r, d.fecha );
			} }, hijos ) : el( 'span', { class: 'pk-cal-dia pk-est-' + d.estado, role: 'img', 'aria-label': nombre }, hijos ) );
		} );

		return el( 'div', { class: 'pk-caja plana' }, [
			el( 'div', { class: 'pk-cal', 'aria-label': 'Calendario de ' + r.etiqueta }, celdas ),
			cal.leyenda.length ? el( 'div', { class: 'pk-leyenda' }, cal.leyenda.map( function ( l ) {
				return el( 'span', { class: 'pk-est-' + l.estado, texto: l.texto } );
			} ) ) : null,
		] );
	}

	var ESTADOS_SOLICITUD = {
		pendiente: [ 'espera', 'Esperando respuesta' ],
		aprobada: [ 'bien', 'Aprobada' ],
		rechazada: [ 'mal', 'Rechazada' ],
	};

	function vistaDiaDetalle( r, fecha ) {
		mostrarPestanas( 'horas' );
		var d = r.detalle[ fecha ];
		var esHoy = yo && yo.fecha === fecha;

		var solicitudes = d.solicitudes.map( function ( s ) {
			var e = ESTADOS_SOLICITUD[ s.estado ] || [ 'espera', s.estado ];
			if ( 'aprobada' === s.estado && s.pedida ) {
				e = [ 'bien', 'Aprobada con corrección (pediste ' + s.pedida + ')' ];
			}
			return el( 'li', { class: 'aprobada' === s.estado ? 'hecho' : ( 'pendiente' === s.estado ? 'sig' : 'falta' ) }, [
				el( 'span', { class: 'pt', 'aria-hidden': 'true' }, [ 'aprobada' === s.estado ? icono( 'check' ) : null ] ),
				el( 'div', { class: 'tx' }, [ el( 'span', { texto: s.paso + ' · ' + s.hora } ), el( 'b', { class: 'pd', texto: e[1] } ) ] ),
				s.respuesta ? el( 'span', { class: 'nota ' + e[0], texto: 'Respuesta: ' + s.respuesta } ) : null,
			] );
		} );

		var hijos = [
			el( 'button', { class: 'simple atras', type: 'button', texto: '‹ ' + r.etiqueta.charAt( 0 ).toUpperCase() + r.etiqueta.slice( 1 ), alHacerClic: function () {
				pintarHoras( r );
			} } ),
			el( 'h1', { texto: d.titulo.charAt( 0 ).toUpperCase() + d.titulo.slice( 1 ) } ),
		];

		if ( d.horas || d.extra ) {
			hijos.push( el( 'div', { class: 'pk-kpis dos' }, [
				el( 'div', { class: 'pk-kpi rosa compacto' }, [ el( 'span', { texto: 'Trabajaste' } ), el( 'b', { texto: d.horas || '—' } ) ] ),
				el( 'div', { class: 'pk-kpi rosa compacto' }, [ el( 'span', { texto: 'Horas extra' } ), el( 'b', { texto: d.extra || '—' } ) ] ),
			] ) );
		}

		if ( d.falta ) {
			hijos.push( aviso( 'error', 'Este día no tiene marcas y cuenta como falta.' ) );
		} else if ( d.incompleto ) {
			hijos.push( aviso( 'error', 'Este día quedó sin salida.' ) );
		}

		if ( ! d.falta ) {
			hijos.push( el( 'p', { class: 'etq', texto: 'Marcas' } ), lineaDelDia( d.pasos, ! esHoy ) );
		}

		if ( d.extras.length ) {
			hijos.push( el( 'p', { class: 'etq', texto: 'Otras salidas' } ), el( 'ul', { class: 'linea' }, d.extras.map( function ( x ) {
				return el( 'li', { class: 'hecho' }, [
					el( 'span', { class: 'pt', 'aria-hidden': 'true' }, [ icono( 'check' ) ] ),
					el( 'div', { class: 'tx' }, [ el( 'span', { texto: x.texto } ), el( 'b', { texto: x.hora } ) ] ),
				] );
			} ) ) );
		}

		if ( solicitudes.length ) {
			hijos.push( el( 'p', { class: 'etq', texto: 'Correcciones pedidas' } ), el( 'ul', { class: 'linea' }, solicitudes ) );
		}

		d.pedir.forEach( function ( p ) {
			hijos.push( el( 'div', { class: 'pie-fila' }, [ boton( 'principal', 'Pedir corrección de la ' + p.paso.toLowerCase(), function () {
				vistaPedir( r, fecha, p );
			} ) ] ) );
		} );

		pintar.apply( null, hijos );
	}

	/** Pedir la marca que falta (T6B): hora, qué pasó y un comentario opcional. */
	function vistaPedir( r, fecha, p ) {
		mostrarPestanas( 'horas' );
		var d = r.detalle[ fecha ];
		var hora = el( 'input', { type: 'time', id: 'pedir-hora', required: 'required', min: p.despues_de || null } );
		var comentario = el( 'textarea', { id: 'pedir-comentario', rows: '2', maxlength: '300', placeholder: 'Opcional' } );
		var quePaso = '';
		var estado = el( 'div', {} );
		var enviar = boton( 'principal', 'Enviar a gerencia' );

		enviar.addEventListener( 'click', function () {
			if ( p.despues_de && hora.value && hora.value <= p.despues_de ) {
				estado.replaceChildren( aviso( 'error', 'La salida tiene que ser después de las ' + p.despues_de + '.' ) );
				return;
			}
			enviar.disabled = true;
			enviarCorreccion( {
				fecha: fecha,
				hora: hora.value,
				accion: p.accion,
				motivo: '',
				justificacion: justificacion( quePaso, comentario.value ),
			}, estado, enviar, function () {
				vistaHoras( r.mes );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Pedir ', el( 'em', { texto: 'corrección' } ) ] ),
			el( 'p', { class: 'fecha', texto: d.titulo } ),
			el( 'div', { class: 'corrige' }, [
				el( 'div', {}, [ el( 'span', { texto: 'Marca que falta' } ), el( 'b', { texto: p.paso } ) ] ),
				el( 'em', { texto: 'Falta' } ),
			] ),
			el( 'div', { class: 'formulario' }, [
				el( 'div', {}, [ el( 'label', { for: 'pedir-hora', texto: '¿A qué hora ' + ( 'entrada' === p.accion ? 'entraste' : 'saliste' ) + '?' } ), hora,
					p.despues_de ? el( 'p', { class: 'ayuda nota-campo', texto: 'Después de las ' + p.despues_de + ', tu última marca.' } ) : null ] ),
				opcionesOlvido( function ( t ) {
					quePaso = t;
				} ),
				el( 'div', {}, [ el( 'label', { for: 'pedir-comentario', texto: 'Comentario' } ), comentario ] ),
			] ),
			estado,
			el( 'div', { class: 'pie-fila' }, [ enviar ] ),
			boton( 'simple', 'Volver', function () {
				vistaDiaDetalle( r, fecha );
			} )
		);
	}

	// ---------- mi recibo (T8) ----------

	/** Un recibo por honorarios por mes. Nunca muestra cálculos de pago. */
	function vistaRecibos( mensaje ) {
		mostrarPestanas( 'recibo' );
		cargando( 'Cargando tus recibos…' );

		api( 'yo/recibos', {} ).then( function ( r ) {
			listaRecibos( { token: r.token, vence: Date.now() + r.segundos * 1000, meses: r.meses, constancia: r.constancia }, mensaje );
		} ).catch( function ( error ) {
			if ( sesionVencida( error ) ) {
				return;
			}
			pintar( el( 'h1', {}, [ 'Mi ', el( 'em', { texto: 'recibo' } ) ] ), aviso( 'error', error.message ) );
		} );
	}

	/** El permiso para enviar dura 15 minutos; si venció, se pide otro sin volver a entrar. */
	function errorRecibo( error ) {
		if ( 'pka_ticket' === error.codigo ) {
			vistaRecibos( aviso( 'espera', 'Pasó un rato. Vuelve a intentarlo.' ) );
			return true;
		}
		return sesionVencida( error );
	}

	function listaRecibos( estado, mensaje ) {
		mostrarPestanas( 'recibo' );
		var pendiente = estado.meses.filter( function ( m ) {
			return m.puede_enviar;
		} )[ 0 ];

		var lista = el( 'ul', { class: 'lista-mes' }, estado.meses.map( function ( m ) {
			return el( 'li', { class: pendiente && pendiente.mes === m.mes ? 'destacado' : '' }, [
				el( 'button', { class: 'enlace-fila', type: 'button', alHacerClic: function () {
					vistaUnRecibo( estado, m );
				} }, [
					el( 'span', { class: 'dia', texto: m.etiqueta } ),
					el( 'span', { class: 'pk-chip ' + ( 'recibido' === m.estado ? 'ok' : ( 'observado' === m.estado ? 'error' : 'aviso' ) ), texto: m.pagado && 'recibido' === m.estado ? 'Pagado' : m.texto } ),
					el( 'span', { class: 'horas', texto: m.numero ? m.numero + ' · S/ ' + m.monto : ( m.puede_enviar ? 'Toca para enviarlo' : '' ) } ),
				] ),
			] );
		} ) );

		pintar(
			el( 'h1', {}, [ 'Mi ', el( 'em', { texto: 'recibo' } ) ] ),
			el( 'p', { class: 'ayuda', texto: 'Cada mes envía tu recibo por honorarios: el número, el monto y el PDF. Gerencia lo revisa.' } ),
			mensaje || null,
			avisoConstancia( estado.constancia ),
			estado.meses.length ? lista : el( 'div', { class: 'vacio' }, [ el( 'b', { texto: 'Todavía no hay meses' } ), el( 'span', { class: 'ayuda', texto: 'Cuando cierre tu primer mes, aquí podrás enviar tu recibo.' } ) ] ),
			tarjetaConstancia( estado )
		);
	}

	// ---------- constancia de retención de 4.ª o 5.ª (pedido de gerencia, 0.23.0) ----------

	function claseConstancia( c ) {
		if ( 'observado' === c.estado || 'vencida' === c.vigencia ) {
			return 'error';
		}
		if ( 'enviado' === c.estado || 'por_vencer' === c.vigencia ) {
			return 'aviso';
		}
		return 'recibido' === c.estado ? 'ok' : 'gris';
	}

	/** Aviso arriba si la constancia recibida está por vencer o venció. */
	function avisoConstancia( c ) {
		if ( ! c || 'recibido' !== c.estado || ( 'por_vencer' !== c.vigencia && 'vencida' !== c.vigencia ) ) {
			return null;
		}
		return aviso( 'vencida' === c.vigencia ? 'error' : 'espera', 'Tu constancia de retención: ' + c.vigencia_texto.charAt( 0 ).toLowerCase() + c.vigencia_texto.slice( 1 ) + '. Sube la nueva aquí abajo.' );
	}

	function tarjetaConstancia( estado ) {
		var c = estado.constancia;
		if ( ! c ) {
			return null;
		}
		return el( 'div', { class: 'pk-mt' }, [
			el( 'h2', { texto: 'Constancia de retención' } ),
			el( 'ul', { class: 'lista-mes' }, [ el( 'li', { class: 'aviso' === claseConstancia( c ) || 'error' === claseConstancia( c ) ? 'destacado' : '' }, [
				el( 'button', { class: 'enlace-fila', type: 'button', alHacerClic: function () {
					vistaConstancia( estado );
				} }, [
					el( 'span', { class: 'dia', texto: c.tipo_texto || '4.ª o 5.ª categoría' } ),
					el( 'span', { class: 'pk-chip ' + claseConstancia( c ), texto: c.texto } ),
					el( 'span', { class: 'horas', texto: c.vigencia_texto || 'Si tienes la constancia de 4.ª o 5.ª categoría, súbela aquí' } ),
				] ),
			] ) ] ),
		] );
	}

	function vistaConstancia( estado, mensaje ) {
		mostrarPestanas( 'recibo' );
		var c = estado.constancia;
		var volver = boton( 'simple', 'Volver a mis recibos', function () {
			listaRecibos( estado );
		} );
		var titulo = el( 'h1', {}, [ 'Constancia de ', el( 'em', { texto: 'retención' } ) ] );
		var datos = 'falta' !== c.estado ? el( 'div', { class: 'pk-caja lista pk-datos' }, [
			el( 'div', {}, [ el( 'span', { texto: 'Estado' } ), el( 'b', { texto: c.texto } ) ] ),
			el( 'div', {}, [ el( 'span', { texto: 'Tipo' } ), el( 'b', { texto: c.tipo_texto } ) ] ),
			c.numero ? el( 'div', {}, [ el( 'span', { texto: 'Número' } ), el( 'b', { texto: c.numero } ) ] ) : null,
			el( 'div', {}, [ el( 'span', { texto: 'Vigencia' } ), el( 'b', { texto: c.vigencia_texto } ) ] ),
		] ) : null;

		if ( ! c.puede_enviar ) {
			var anular = c.puede_anular ? boton( 'secundario', 'Anular envío', function () {
				api( 'yo/constancia/anular', { token: estado.token, id: c.id } ).then( function ( r ) {
					estado.meses = r.meses;
					estado.constancia = r.constancia;
					listaRecibos( estado, aviso( 'ok', r.mensaje ) );
				} ).catch( function ( error ) {
					if ( errorRecibo( error ) ) {
						return;
					}
					vistaConstancia( estado, aviso( 'error', error.message ) );
				} );
			} ) : null;
			pintar( titulo, mensaje || null, datos, c.puede_anular ? el( 'p', { class: 'ayuda', texto: 'Mientras gerencia no la revise, puedes anular el envío si te equivocaste.' } ) : null, anular, volver );
			return;
		}

		var tipo = el( 'select', { id: 'const-tipo' }, [ el( 'option', { value: '', texto: 'Elige' } ) ].concat( Object.keys( c.tipos ).map( function ( clave ) {
			return el( 'option', { value: clave, texto: c.tipos[ clave ] } );
		} ) ) );
		var numero = el( 'input', { type: 'text', id: 'const-numero', autocomplete: 'off', placeholder: 'Número de la constancia (opcional)' } );
		var hasta = el( 'input', { type: 'date', id: 'const-hasta', value: c.sugerida } );
		var archivo = el( 'input', { type: 'file', id: 'const-archivo', accept: 'application/pdf,.pdf' } );
		var errores = { tipo: el( 'p', { class: 'error-campo' } ), vigente_hasta: el( 'p', { class: 'error-campo' } ), archivo: el( 'p', { class: 'error-campo' } ) };
		var enviar = boton( 'principal', 'Enviar constancia' );
		enviar.disabled = true;

		function revisar() {
			var pdf = archivo.files && archivo.files[ 0 ];
			var problemaPdf = pdf && ( 0 === pdf.size ? 'El archivo está vacío.' : ( 'application/pdf' !== pdf.type && ! /\.pdf$/i.test( pdf.name ) ? 'Elige un PDF.' : '' ) );
			errores.archivo.textContent = problemaPdf || '';
			enviar.disabled = ! ( tipo.value && hasta.value && pdf && ! problemaPdf );
		}

		tipo.addEventListener( 'change', revisar );
		hasta.addEventListener( 'input', revisar );
		archivo.addEventListener( 'change', revisar );

		enviar.addEventListener( 'click', function () {
			var formulario = new FormData();
			formulario.append( 'token', estado.token );
			formulario.append( 'tipo', tipo.value );
			formulario.append( 'numero', numero.value );
			formulario.append( 'vigente_hasta', hasta.value );
			formulario.append( 'archivo', archivo.files[ 0 ] );
			enviar.disabled = true;
			enviar.lastChild.textContent = 'Enviando…';

			apiArchivo( 'yo/constancia/enviar', formulario ).then( function ( r ) {
				estado.meses = r.meses;
				estado.constancia = r.constancia;
				listaRecibos( estado, aviso( 'ok', r.mensaje ) );
			} ).catch( function ( error ) {
				if ( errorRecibo( error ) ) {
					return;
				}
				var porCampo = error.datos.errores || {};
				Object.keys( errores ).forEach( function ( clave ) {
					errores[ clave ].textContent = porCampo[ clave ] || '';
				} );
				enviar.lastChild.textContent = 'Enviar constancia';
				revisar();
				if ( ! Object.keys( porCampo ).length ) {
					vistaConstancia( estado, aviso( 'error', error.message ) );
				}
			} );
		} );

		pintar(
			titulo,
			mensaje || null,
			'observado' === c.estado ? aviso( 'error', 'Gerencia observó tu constancia: ' + c.comentario ) : null,
			datos,
			el( 'p', { class: 'ayuda pk-mt-s', texto: 'recibido' === c.estado ? 'Cuando tengas la nueva (por ejemplo, la del año siguiente), súbela aquí.' : 'Si tienes la constancia de suspensión de retenciones de 4.ª categoría (SUNAT) o el documento de 5.ª, súbela en PDF. Gerencia la revisa.' } ),
			el( 'div', { class: 'formulario' }, [
				el( 'div', {}, [ el( 'label', { for: 'const-tipo', texto: 'Qué constancia es' } ), tipo, errores.tipo ] ),
				el( 'div', {}, [ el( 'label', { for: 'const-numero', texto: 'Número (opcional)' } ), numero ] ),
				el( 'div', {}, [ el( 'label', { for: 'const-hasta', texto: 'Vale hasta' } ), hasta, errores.vigente_hasta ] ),
				el( 'div', {}, [ el( 'label', { for: 'const-archivo', texto: 'PDF de la constancia' } ), archivo, errores.archivo ] ),
			] ),
			el( 'div', { class: 'pie-fila' }, [ enviar ] ),
			volver
		);
	}

	function vistaUnRecibo( estado, m, mensaje ) {
		mostrarPestanas( 'recibo' );
		var volver = boton( 'simple', 'Volver a mis recibos', function () {
			listaRecibos( estado );
		} );
		var titulo = el( 'h1', {}, [ 'Recibo de ', el( 'em', { texto: m.etiqueta } ) ] );

		if ( ! m.puede_enviar ) {
			var ficha = el( 'div', { class: 'pk-caja lista pk-datos' }, [
				el( 'div', {}, [ el( 'span', { texto: 'Estado' } ), el( 'b', { texto: m.pagado && 'recibido' === m.estado ? 'Pagado' : m.texto } ) ] ),
				el( 'div', {}, [ el( 'span', { texto: 'Número' } ), el( 'b', { texto: m.numero } ) ] ),
				el( 'div', {}, [ el( 'span', { texto: 'Monto' } ), el( 'b', { texto: 'S/ ' + m.monto } ) ] ),
				m.fecha ? el( 'div', {}, [ el( 'span', { texto: 'recibido' === m.estado ? 'Recibido el' : 'Enviado el' } ), el( 'b', { texto: m.fecha } ) ] ) : null,
			] );
			var anular = m.puede_anular ? boton( 'secundario', 'Anular envío', function () {
				confirmarAnulacion( estado, m );
			} ) : null;

			pintar( titulo, mensaje || null, ficha, m.puede_anular ? el( 'p', { class: 'ayuda', texto: 'Mientras gerencia no lo revise, puedes anular el envío si te equivocaste.' } ) : null, anular, volver );
			return;
		}

		var numero = el( 'input', { type: 'text', id: 'recibo-numero', autocomplete: 'off', placeholder: 'E001-482', value: m.numero || '' } );
		var monto = el( 'input', { type: 'text', id: 'recibo-monto', inputmode: 'decimal', placeholder: '1934.00', class: 'monto' } );
		var archivo = el( 'input', { type: 'file', id: 'recibo-archivo', accept: 'application/pdf,.pdf' } );
		var errores = { numero: el( 'p', { class: 'error-campo' } ), monto: el( 'p', { class: 'error-campo' } ), archivo: el( 'p', { class: 'error-campo' } ) };
		var enviar = boton( 'principal', 'Enviar recibo' );
		enviar.disabled = true;

		function revisar() {
			var pdf = archivo.files && archivo.files[ 0 ];
			var problemaPdf = pdf && ( 0 === pdf.size ? 'El archivo está vacío.' : ( 'application/pdf' !== pdf.type && ! /\.pdf$/i.test( pdf.name ) ? 'Elige un PDF.' : '' ) );
			errores.archivo.textContent = problemaPdf || '';
			enviar.disabled = ! ( numero.value.trim() && monto.value.trim() && pdf && ! problemaPdf );
		}

		[ numero, monto ].forEach( function ( campo ) {
			campo.addEventListener( 'input', revisar );
		} );
		archivo.addEventListener( 'change', revisar );

		enviar.addEventListener( 'click', function () {
			var formulario = new FormData();
			formulario.append( 'token', estado.token );
			formulario.append( 'mes', m.mes );
			formulario.append( 'numero', numero.value );
			formulario.append( 'monto', monto.value );
			formulario.append( 'archivo', archivo.files[ 0 ] );
			enviar.disabled = true;
			enviar.lastChild.textContent = 'Enviando…';

			apiArchivo( 'yo/recibos/enviar', formulario ).then( function ( r ) {
				estado.meses = r.meses;
				listaRecibos( estado, aviso( 'ok', r.mensaje ) );
			} ).catch( function ( error ) {
				if ( errorRecibo( error ) ) {
					return;
				}
				var porCampo = error.datos.errores || {};
				Object.keys( errores ).forEach( function ( clave ) {
					errores[ clave ].textContent = porCampo[ clave ] || '';
				} );
				enviar.lastChild.textContent = 'Enviar recibo';
				revisar();
				if ( ! Object.keys( porCampo ).length ) {
					vistaUnRecibo( estado, m, aviso( 'error', error.message ) );
				}
			} );
		} );

		pintar(
			titulo,
			mensaje || null,
			'observado' === m.estado ? aviso( 'error', 'Gerencia observó tu recibo: ' + m.comentario ) : null,
			el( 'div', { class: 'formulario' }, [
				el( 'div', {}, [ el( 'label', { for: 'recibo-numero', texto: 'Número del recibo (serie y número)' } ), numero, errores.numero ] ),
				el( 'div', {}, [ el( 'label', { for: 'recibo-monto', texto: 'Monto (S/)' } ), monto, errores.monto ] ),
				el( 'div', {}, [ el( 'label', { for: 'recibo-archivo', texto: 'PDF del recibo' } ), archivo, errores.archivo ] ),
			] ),
			el( 'div', { class: 'pie-fila' }, [ enviar ] ),
			volver
		);
	}

	function confirmarAnulacion( estado, m ) {
		pintar(
			el( 'h1', {}, [ '¿Anular el ', el( 'em', { texto: 'envío' } ), '?' ] ),
			el( 'p', { texto: 'Se retira el recibo ' + m.numero + ' de ' + m.etiqueta + '. Después puedes enviar uno nuevo.' } ),
			el( 'div', { class: 'pie-fila' }, [ boton( 'principal', 'Sí, anular el envío', function () {
				api( 'yo/recibos/anular', { token: estado.token, id: m.id } ).then( function ( r ) {
					estado.meses = r.meses;
					listaRecibos( estado, aviso( 'ok', r.mensaje ) );
				} ).catch( function ( error ) {
					if ( errorRecibo( error ) ) {
						return;
					}
					vistaUnRecibo( estado, m, aviso( 'error', error.message ) );
				} );
			} ) ] ),
			boton( 'secundario', 'No, volver', function () {
				vistaUnRecibo( estado, m );
			} )
		);
	}

	// ---------- perfil (T7) ----------

	function vistaPerfil( mensaje ) {
		mostrarPestanas( 'perfil' );
		cargando();

		api( 'yo/perfil', null, 'GET' ).then( function ( p ) {
			var dni = el( 'b', { texto: p.dni } );
			var visible = false;
			var ver = el( 'button', { type: 'button', texto: 'Ver', 'aria-label': 'Ver mi DNI completo' } );

			ver.addEventListener( 'click', function () {
				if ( visible ) {
					dni.textContent = p.dni;
					ver.textContent = 'Ver';
					visible = false;
					return;
				}
				ver.disabled = true;
				api( 'yo/dni', {} ).then( function ( r ) {
					dni.textContent = r.dni;
					ver.textContent = 'Ocultar';
					visible = true;
					ver.disabled = false;
				} ).catch( function ( error ) {
					ver.disabled = false;
					sesionVencida( error );
				} );
			} );

			pintar(
				el( 'div', { class: 'perfil-cab' }, [ el( 'span', { class: 'pk-av l', 'aria-hidden': 'true', texto: p.iniciales } ), el( 'div', {}, [ el( 'h1', { texto: p.nombre } ), el( 'span', { texto: p.cargo } ) ] ) ] ),
				mensaje || null,
				el( 'div', { class: 'pk-caja lista pk-datos' }, [
					el( 'div', {}, [ el( 'span', { texto: 'DNI' } ), el( 'div', {}, [ dni, ver ] ) ] ),
					p.telefono ? el( 'div', {}, [ el( 'span', { texto: 'Teléfono' } ), el( 'b', { texto: p.telefono } ) ] ) : null,
					el( 'div', {}, [ el( 'span', { texto: 'Este celular' } ), el( 'b', { texto: p.celular } ) ] ),
					el( 'div', {}, [ el( 'span', { texto: 'Estado' } ), el( 'b', { texto: p.estado } ) ] ),
					p.pin && p.pin.activo ? el( 'div', {}, [
						el( 'span', { texto: 'PIN de ingreso' } ),
						el( 'div', {}, [
							el( 'b', { texto: p.pin.tiene ? 'Desde el ' + p.pin.desde : 'Sin PIN' } ),
							el( 'button', { type: 'button', texto: p.pin.tiene ? 'Cambiar' : 'Crear', alHacerClic: function () {
								vistaCrearPin( p.pin.largo, p.pin.tiene );
							} } ),
							p.pin.tiene ? el( 'button', { type: 'button', texto: 'Quitar', alHacerClic: function () {
								api( 'yo/pin/quitar', {} ).then( function ( r ) {
									vistaPerfil( aviso( 'ok', r.mensaje ) );
								} ).catch( function ( error ) {
									sesionVencida( error );
								} );
							} } ) : null,
						] ),
					] ) : null,
				] ),
				p.pendiente ? aviso( 'espera', p.pendiente ) : null,
				el( 'p', { class: 'ayuda', texto: '¿Algún dato está mal? Pídele a RR.HH. que lo corrija. Si cambias de celular, registra el nuevo en el taller con "Cambié de celular".' } ),
				p.liberar ? boton( 'simple', 'Liberar este celular', function () {
					mostrarPestanas( 'perfil' );
					vistaLiberar( vistaPerfil );
				} ) : null,
				el( 'p', {}, [ el( 'a', { href: config.privacidad, texto: 'Aviso de datos personales' } ) ] ),
				boton( 'secundario', 'Cerrar sesión', function () {
					api( 'yo/salir', {} ).then( function () {
						vistaEntrar( aviso( 'ok', 'Cerraste sesión.' ) );
					} ).catch( function () {
						vistaEntrar();
					} );
				} )
			);
		} ).catch( function ( error ) {
			if ( sesionVencida( error ) ) {
				return;
			}
			pintar( el( 'h1', { texto: 'Perfil' } ), aviso( 'error', error.message ) );
		} );
	}

	// ---------- pantalla de la entrada (tablet) ----------

	/**
	 * Pantalla del taller (maquetas P1A–P1C y P2): tablet o PC fija en la entrada,
	 * apaisada. Título y pasos a la izquierda, QR con su barra de tiempo a la
	 * derecha y pie con la fecha y la hora. Mientras llega el código nuevo se pone
	 * turquesa ("Actualizando…") y, si se corta la conexión, avisa desde lejos.
	 */
	function vistaPantalla() {
		var tokenActual = '';
		var total = 30;
		var restantes = 30;
		var ultimaHora = '';
		var pintada = false;
		var logo = document.querySelector( '.barra .logo' );

		var img = el( 'img', { class: 'tp-img', alt: 'Código QR para marcar asistencia' } );
		var carga = el( 'p', { class: 'tp-carga', texto: 'Actualizando…', hidden: 'hidden' } );
		var barra = el( 'i', {} );
		var tiempo = el( 'span', {} );
		var marcoTiempo = el( 'div', { class: 'tp-tiempo' }, [ el( 'div', { class: 'tp-bar', 'aria-hidden': 'true' }, [ barra ] ), tiempo ] );
		var codigo = el( 'b', {} );
		var tarjetaQr = el( 'div', { class: 'tp-qr' }, [
			el( 'div', { class: 'tp-marco' }, [ img, carga ] ),
			marcoTiempo,
			el( 'p', { class: 'tp-codigo' }, [ '¿Sin cámara? Código ', codigo ] ),
		] );
		var tarjetaAlerta = el( 'div', { class: 'tp-qr tp-alerta', hidden: 'hidden' }, [
			el( 'div', { class: 'tp-medallon' }, [ icono( 'sinwifi' ) ] ),
			el( 'p', {}, [ el( 'b', { texto: 'El código no se está actualizando.' } ) ] ),
			el( 'p', { texto: 'Revisa el Wi-Fi del taller o avisa a gerencia.' } ),
		] );
		var titulo1 = el( 'span', { class: 'tp-sombra', texto: 'Escanea para marcar' } );
		var titulo2 = el( 'span', { class: 'tp-pill', texto: 'tu asistencia' } );
		var pasos = el( 'ol', { class: 'tp-pasos' } );
		var fecha = el( 'span', {} );
		var hora = el( 'b', {} );
		var raiz = el( 'div', { class: 'tp' }, [
			el( 'span', { class: 'tp-aro a1', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'tp-aro a2', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'tp-aro a3', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'tp-estrella', 'aria-hidden': 'true' } ),
			el( 'header', { class: 'tp-cab' }, [
				el( 'span', { class: 'tp-logo' }, [
					logo ? el( 'img', { src: logo.src, alt: 'Pekokis' } ) : null,
					el( 'span', { texto: 'Registro de asistencia' } ),
				].filter( Boolean ) ),
			] ),
			el( 'div', { class: 'tp-main' }, [
				el( 'div', { class: 'tp-izq' }, [ el( 'h1', {}, [ titulo1, titulo2 ] ), pasos ] ),
				tarjetaQr,
				tarjetaAlerta,
			] ),
			el( 'footer', { class: 'tp-pie' }, [ fecha, hora ] ),
		] );

		function ponerPasos( conWifi ) {
			var textos = conWifi
				? [ 'Conéctate al Wi-Fi del taller', 'Escanea el QR con la cámara', 'Marca tu entrada, comida o salida' ]
				: [ 'Abre la asistencia en tu celular', 'Escanea el QR con la cámara', 'Marca tu entrada, comida o salida' ];
			pasos.replaceChildren.apply( pasos, textos.map( function ( t, i ) {
				return el( 'li', {}, [ el( 'b', { texto: String( i + 1 ) } ), t ] );
			} ) );
		}

		// P2: sin conexión. Se ve desde lejos y dice qué revisar.
		function sinConexion( si ) {
			raiz.classList.toggle( 'sin-conexion', si );
			if ( si ) {
				raiz.classList.remove( 'turq' );
			}
			tarjetaQr.hidden = si;
			tarjetaAlerta.hidden = ! si;
			pasos.hidden = si;
			titulo1.textContent = si ? 'Sin conexión' : 'Escanea para marcar';
			titulo2.textContent = si ? 'no se puede marcar' : 'tu asistencia';
			if ( si && ultimaHora ) {
				fecha.textContent = 'Último código válido a las ' + ultimaHora;
			}
		}

		// P1C: mientras se genera el código nuevo no se muestra el anterior.
		function actualizando( si ) {
			raiz.classList.toggle( 'turq', si );
			img.hidden = si;
			carga.hidden = ! si;
			if ( si ) {
				barra.style.width = '100%';
				tiempo.textContent = 'Generando un código nuevo';
				marcoTiempo.classList.remove( 'ambar' );
			}
		}

		function pintarTiempo() {
			if ( restantes <= 0 ) {
				actualizando( true );
				return;
			}
			barra.style.width = Math.round( 100 * restantes / total ) + '%';
			// QR largo (30 min): minutos, y ámbar en los últimos minutos (P1B).
			tiempo.textContent = total >= 300
				? 'Se actualiza en ' + Math.ceil( restantes / 60 ) + ' min'
				: 'Se actualiza en ' + restantes + ' s';
			marcoTiempo.classList.toggle( 'ambar', total >= 300 && restantes <= Math.min( 300, total / 5 ) );
		}

		function pedir() {
			// Con enlaces permanentes "simples", la URL de la API ya trae "?rest_route=".
			var separador = -1 === config.api.indexOf( '?' ) ? '?' : '&';
			api( 'pantalla/codigo' + separador + 'actual=' + encodeURIComponent( tokenActual ), null, 'GET' ).then( function ( r ) {
				if ( ! pintada ) {
					// Se dibuja recién con el primer código, para no mostrar un marco vacío.
					pintada = true;
					pintar( raiz );
				}
				sinConexion( false );
				ponerPasos( !! r.con_wifi );
				if ( r.qr ) {
					img.src = r.qr;
				}
				actualizando( false );
				total = r.total || 30;
				tokenActual = r.token;
				codigo.textContent = r.codigo.slice( 0, 3 ) + ' ' + r.codigo.slice( 3 );
				hora.textContent = r.hora;
				fecha.textContent = r.fecha || '';
				ultimaHora = r.hora;
				restantes = r.segundos;
				pintarTiempo();
				setTimeout( pedir, Math.min( r.segundos, 10 ) * 1000 + 300 );
			} ).catch( function ( error ) {
				if ( 'pka_pantalla' === error.codigo ) {
					vistaActivarPantalla();
					return;
				}
				if ( ! pintada ) {
					pintada = true;
					pintar( raiz );
				}
				sinConexion( true );
				setTimeout( pedir, 5000 );
			} );
		}

		// Fuera de "relojes": pintar() no debe apagar la cuenta regresiva de la pantalla.
		setInterval( function () {
			restantes = Math.max( 0, restantes - 1 );
			if ( pintada && ! raiz.classList.contains( 'sin-conexion' ) ) {
				pintarTiempo();
			}
		}, 1000 );

		// Que la tablet no se apague.
		if ( navigator.wakeLock ) {
			var pedirLuz = function () {
				navigator.wakeLock.request( 'screen' ).catch( function () {} );
			};
			pedirLuz();
			document.addEventListener( 'visibilitychange', function () {
				if ( 'visible' === document.visibilityState ) {
					pedirLuz();
				}
			} );
		}

		pedir();
	}

	function vistaActivarPantalla() {
		if ( ! config.nonce ) {
			pintar(
				el( 'h1', { texto: 'Pantalla sin activar' } ),
				el( 'p', { texto: 'Para usar este equipo como pantalla del QR, un administrador debe iniciar sesión en este mismo equipo y volver a abrir esta página.' } )
			);
			return;
		}

		var nombre = el( 'input', { type: 'text', id: 'nombre-pantalla', value: 'Pantalla de la entrada', maxlength: '80' } );
		var activar = boton( 'principal', 'Usar este equipo como pantalla' );
		var estado = el( 'div', {} );

		activar.addEventListener( 'click', function () {
			activar.disabled = true;
			api( 'pantalla/activar', { nombre: nombre.value } ).then( vistaPantalla ).catch( function ( error ) {
				activar.disabled = false;
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Activar ', el( 'em', { texto: 'pantalla' } ) ] ),
			el( 'p', { texto: 'Este equipo mostrará el QR para marcar. Déjalo enchufado y con la pantalla encendida en la entrada del taller.' } ),
			el( 'div', { class: 'formulario' }, [ el( 'div', {}, [ el( 'label', { for: 'nombre-pantalla', texto: 'Nombre' } ), nombre ] ) ] ),
			activar,
			estado
		);
	}

	// ---------- arranque ----------

	// App instalable: el service worker solo guarda los archivos estáticos.
	if ( 'serviceWorker' in navigator && config.sw && 'pantalla' !== config.vista ) {
		navigator.serviceWorker.register( config.sw ).catch( function () {} );
	}

	if ( 'activar' === config.vista ) {
		vistaActivar();
	} else if ( 'pantalla' === config.vista ) {
		vistaPantalla();
	} else if ( 'privacidad' === config.vista ) {
		vistaPrivacidad();
	} else {
		leerQr().then( function ( mensaje ) {
			cargarYo( mensaje );
		} );
	}
}() );
