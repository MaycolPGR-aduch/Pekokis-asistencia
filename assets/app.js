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
			datos.ayer ? aviso( 'error', datos.ayer.mensaje ) : null,
			tarjeta,
			mensaje || null,
			botones,
			listaHoy( datos.hoy ),
			el( 'button', { class: 'secundario', type: 'button', texto: 'Olvidé marcar', alHacerClic: function () {
				vistaOlvido( datos );
			} } ),
			el( 'button', { class: 'secundario', type: 'button', texto: 'Salir', alHacerClic: function () {
				vistaInicio();
			} } )
		);
	}

	/** Solicitud de corrección: queda pendiente hasta que el jefe la apruebe. */
	function vistaOlvido( datos ) {
		var ayer = new Date( datos.fecha + 'T12:00:00' );
		ayer.setDate( ayer.getDate() - 1 );
		var fechaAyer = ayer.toISOString().slice( 0, 10 );

		var fecha = el( 'select', { id: 'olvido-fecha' }, [
			el( 'option', { value: datos.fecha, texto: 'Hoy' } ),
			el( 'option', { value: fechaAyer, texto: 'Ayer' } ),
		] );
		if ( datos.ayer ) {
			fecha.value = fechaAyer;
		}

		var tipo = el( 'select', { id: 'olvido-tipo' }, datos.olvido.map( function ( o ) {
			return el( 'option', { value: o.clave, texto: o.etiqueta } );
		} ) );
		var motivo = el( 'select', { id: 'olvido-motivo' } );
		var filaMotivo = el( 'div', {}, [ el( 'label', { for: 'olvido-motivo', texto: 'Motivo' } ), motivo ] );
		var hora = el( 'input', { type: 'time', id: 'olvido-hora', required: 'required' } );
		var detalle = el( 'textarea', { id: 'olvido-detalle', rows: '3', maxlength: '300', required: 'required' } );
		var estado = el( 'div', {} );
		var enviar = el( 'button', { class: 'principal', type: 'button', texto: 'Enviar a mi jefe' } );

		function actualizarMotivos() {
			var opcion = datos.olvido.filter( function ( o ) {
				return o.clave === tipo.value;
			} )[ 0 ];
			motivo.replaceChildren.apply( motivo, opcion.motivos.map( function ( m ) {
				return el( 'option', { value: m.clave, texto: m.nombre } );
			} ) );
			filaMotivo.hidden = opcion.motivos.length < 2;
		}

		tipo.addEventListener( 'change', actualizarMotivos );
		if ( datos.ayer ) {
			tipo.value = 'fin_jornada';
		}
		actualizarMotivos();

		enviar.addEventListener( 'click', function () {
			if ( ! sesion ) {
				vistaInicio();
				return;
			}
			enviar.disabled = true;
			api( 'correcciones', {
				ticket: sesion.ticket,
				fecha: fecha.value,
				hora: hora.value,
				accion: tipo.value,
				motivo: motivo.value || '',
				justificacion: detalle.value,
			} ).then( function ( r ) {
				limpiarSesion();
				pintar(
					el( 'div', { class: 'hecho' }, [ el( 'div', { class: 'icono', 'aria-hidden': 'true', texto: '✔' } ) ] ),
					el( 'h1', { texto: 'Solicitud enviada' } ),
					aviso( 'ok', r.mensaje ),
					el( 'button', { class: 'secundario', type: 'button', texto: 'Listo', alHacerClic: function () {
						vistaInicio();
					} } )
				);
			} ).catch( function ( error ) {
				if ( 'pka_ticket' === error.codigo ) {
					vistaInicio( aviso( 'error', error.message ) );
					return;
				}
				enviar.disabled = false;
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ '¿Qué ', el( 'em', { texto: 'olvidaste' } ), '?' ] ),
			el( 'p', { class: 'ayuda', texto: 'Tu jefe revisará la solicitud. La marca solo cuenta cuando la aprueba.' } ),
			el( 'div', { class: 'formulario' }, [
				el( 'div', {}, [ el( 'label', { for: 'olvido-fecha', texto: 'Día' } ), fecha ] ),
				el( 'div', {}, [ el( 'label', { for: 'olvido-tipo', texto: 'Marca' } ), tipo ] ),
				filaMotivo,
				el( 'div', {}, [ el( 'label', { for: 'olvido-hora', texto: 'Hora' } ), hora ] ),
				el( 'div', {}, [ el( 'label', { for: 'olvido-detalle', texto: '¿Qué pasó?' } ), detalle ] ),
			] ),
			enviar,
			estado,
			el( 'button', { class: 'secundario', type: 'button', texto: 'Volver', alHacerClic: function () {
				vistaEstado( datos );
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
			if ( 'pka_sin_presencia' === error.codigo && error.datos.acepta_qr ) {
				vistaEstado( datos, formularioCodigo( error.message, function () {
					enviarMarca( datos, accion, motivo, nota );
				} ) );
				return;
			}
			vistaEstado( datos, aviso( 'error', error.message ) );
			actualizarPresencia();
		} );
	}

	/** Pide el código de 6 dígitos de la pantalla y, si es correcto, reintenta la marca. */
	function formularioCodigo( mensaje, reintentar ) {
		var entrada = el( 'input', { type: 'text', id: 'codigo', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', pattern: '[0-9]{6}' } );
		var estado = el( 'div', {} );
		var boton = el( 'button', { class: 'principal', type: 'button', texto: 'Validar código y marcar' } );

		boton.addEventListener( 'click', function () {
			boton.disabled = true;
			api( 'presencia/codigo', { codigo: entrada.value } ).then( reintentar ).catch( function ( error ) {
				boton.disabled = false;
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		return el( 'div', { class: 'tarjeta' }, [
			aviso( 'error', mensaje ),
			el( 'p', { class: 'ayuda', texto: 'Escribe el código de 6 números que se ve debajo del QR en la pantalla de la entrada.' } ),
			el( 'label', { for: 'codigo', texto: 'Código de la pantalla' } ),
			entrada,
			el( 'div', { class: 'botones' }, [ boton ] ),
			estado,
		] );
	}

	/** Si se llegó escaneando el QR (/m?q=…), valida la presencia antes de seguir. */
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

	// ---------- pantalla de la entrada ----------

	function vistaPantalla() {
		var tokenActual = '';
		var img = el( 'img', { class: 'qr', alt: 'Código QR para marcar asistencia' } );
		var codigo = el( 'p', { class: 'codigo-pantalla', 'aria-label': 'Código' } );
		var cuenta = el( 'p', { class: 'cuenta' } );
		var hora = el( 'p', { class: 'hora-pantalla' } );
		var estado = el( 'p', { class: 'aviso error', hidden: 'hidden', texto: 'Sin conexión. Los trabajadores pueden marcar con el WiFi del taller.' } );
		var restantes = 30;
		var pintada = false;

		function pedir() {
			// Con enlaces permanentes "simples", la URL de la API ya trae "?rest_route=".
			var separador = -1 === config.api.indexOf( '?' ) ? '?' : '&';
			api( 'pantalla/codigo' + separador + 'actual=' + encodeURIComponent( tokenActual ), null, 'GET' ).then( function ( r ) {
				estado.hidden = true;
				if ( ! pintada ) {
					// Se dibuja recién con el primer código, para no mostrar un marco vacío.
					pintada = true;
					pintar(
						el( 'h1', {}, [ 'Escanea para ', el( 'em', { texto: 'marcar' } ) ] ),
						el( 'div', { class: 'tarjeta marco-qr' }, [ img ] ),
						el( 'p', { class: 'ayuda', texto: 'Si tu cámara no lo lee, escribe este código:' } ),
						codigo,
						cuenta,
						hora,
						estado
					);
				}
				if ( r.qr ) {
					img.src = r.qr;
				}
				tokenActual = r.token;
				codigo.textContent = r.codigo.slice( 0, 3 ) + ' ' + r.codigo.slice( 3 );
				hora.textContent = r.hora;
				restantes = r.segundos;
				setTimeout( pedir, Math.min( r.segundos, 10 ) * 1000 + 300 );
			} ).catch( function ( error ) {
				if ( 'pka_pantalla' === error.codigo ) {
					vistaActivarPantalla();
					return;
				}
				estado.hidden = false;
				setTimeout( pedir, 5000 );
			} );
		}

		setInterval( function () {
			restantes = Math.max( 0, restantes - 1 );
			cuenta.textContent = 'Cambia en ' + restantes + ' s';
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
		var boton = el( 'button', { class: 'principal', type: 'button', texto: 'Usar este equipo como pantalla' } );
		var estado = el( 'div', {} );

		boton.addEventListener( 'click', function () {
			boton.disabled = true;
			api( 'pantalla/activar', { nombre: nombre.value } ).then( vistaPantalla ).catch( function ( error ) {
				boton.disabled = false;
				estado.replaceChildren( aviso( 'error', error.message ) );
			} );
		} );

		pintar(
			el( 'h1', {}, [ 'Activar ', el( 'em', { texto: 'pantalla' } ) ] ),
			el( 'p', { texto: 'Este equipo mostrará el QR que cambia cada 30 segundos. Déjalo enchufado en la entrada del taller.' } ),
			el( 'div', {}, [ el( 'label', { for: 'nombre-pantalla', texto: 'Nombre' } ), nombre ] ),
			boton,
			estado
		);
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
	} else if ( 'pantalla' === config.vista ) {
		vistaPantalla();
	} else {
		leerQr().then( function ( mensaje ) {
			vistaInicio( mensaje );
		} );
	}
}() );
