/**
 * Panel de gerencia: tooltip de los gráficos SVG (.pk-viz), que al pasar el
 * mouse o con el teclado muestra el dato de la barra (data-tip); el botón
 * "Imprimir o guardar como PDF" del informe; y los cambios sin guardar de
 * Sueldos y tarifas (G8), del panel "Editar registro" (G4B) y de Seguridad y
 * reglas (G14).
 */
( function () {
	'use strict';

	/**
	 * Formularios con valores anteriores (data-antes): resalta lo cambiado
	 * (.pk-cambiado), cuenta los cambios en #pk-sin-guardar (G8) y avisa si se
	 * sale de la página sin guardar.
	 */
	function seguirCambios( form ) {
		var campos = form.querySelectorAll( '[data-antes]' );
		var contador = document.getElementById( 'pk-sin-guardar' );
		var enviando = false;

		function contar() {
			var n = 0;
			campos.forEach( function ( c ) {
				var cambiado = c.value.trim() !== c.getAttribute( 'data-antes' );
				c.classList.toggle( 'pk-cambiado', cambiado );
				n += cambiado ? 1 : 0;
			} );
			if ( contador ) {
				contador.hidden = 0 === n;
				contador.textContent = n + ( 1 === n ? ' cambio sin guardar' : ' cambios sin guardar' );
				contador.classList.toggle( 'pk-alerta', n > 0 );
			}
			return n;
		}

		campos.forEach( function ( c ) {
			c.addEventListener( 'input', contar );
		} );
		form.addEventListener( 'submit', function () {
			enviando = true;
		} );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( ! enviando && contar() > 0 ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
		contar();
	}

	/**
	 * Formularios .pk-sigue (Seguridad y reglas, G14): toma una foto de cada
	 * campo al cargar y compara con ella. Un grupo de radios cuenta como un
	 * solo cambio. Resalta el campo (o su etiqueta) y suma en #pk-sin-guardar.
	 */
	function seguirFormularios( forms ) {
		var contador = document.getElementById( 'pk-sin-guardar' );
		var enviando = false;
		var campos = [];

		forms.forEach( function ( form ) {
			var vistos = {};
			Array.prototype.forEach.call( form.elements, function ( c ) {
				if ( ! c.name || 'hidden' === c.type || 'submit' === c.type || 'button' === c.type || /nonce|referer/.test( c.name ) ) {
					return;
				}
				if ( 'radio' === c.type ) {
					if ( vistos[ c.name ] ) {
						return;
					}
					vistos[ c.name ] = true;
					var grupo = form.querySelectorAll( 'input[type="radio"][name="' + c.name + '"]' );
					campos.push( { tipo: 'radio', nodos: grupo, inicial: valorRadio( grupo ) } );
					return;
				}
				campos.push( { tipo: c.type, nodos: [ c ], inicial: 'checkbox' === c.type ? c.checked : c.value } );
			} );
			form.addEventListener( 'submit', function () {
				enviando = true;
			} );
			form.addEventListener( 'input', contar );
			form.addEventListener( 'change', contar );
		} );

		function valorRadio( grupo ) {
			var v = '';
			Array.prototype.forEach.call( grupo, function ( r ) {
				if ( r.checked ) {
					v = r.value;
				}
			} );
			return v;
		}

		function marcar( nodo, cambiado ) {
			var donde = ( 'radio' === nodo.type || 'checkbox' === nodo.type ) && nodo.closest( 'label' ) ? nodo.closest( 'label' ) : nodo;
			donde.classList.toggle( 'pk-cambiado', cambiado );
		}

		function contar() {
			var n = 0;
			campos.forEach( function ( f ) {
				var actual = 'radio' === f.tipo ? valorRadio( f.nodos ) : ( 'checkbox' === f.tipo ? f.nodos[ 0 ].checked : f.nodos[ 0 ].value );
				var cambiado = actual !== f.inicial;
				Array.prototype.forEach.call( f.nodos, function ( nodo ) {
					marcar( nodo, cambiado && ( 'radio' !== f.tipo || nodo.checked ) );
				} );
				n += cambiado ? 1 : 0;
			} );
			if ( contador ) {
				contador.hidden = 0 === n;
				contador.textContent = n + ( 1 === n ? ' cambio sin guardar' : ' cambios sin guardar' );
				contador.classList.toggle( 'pk-alerta', n > 0 );
			}
			return n;
		}

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( ! enviando && contar() > 0 ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.pk-sueldos, .pk-panel form' ).forEach( seguirCambios );

		var sigue = document.querySelectorAll( 'form.pk-sigue' );
		if ( sigue.length ) {
			seguirFormularios( Array.prototype.slice.call( sigue ) );
		}

		// Menú de tablet (TG0–TG8): el logo abre el riel para ver los nombres.
		var abrirRiel = document.getElementById( 'pk-riel-abrir' );
		var riel = document.getElementById( 'pk-riel' );
		if ( abrirRiel && riel ) {
			abrirRiel.addEventListener( 'click', function () {
				var abierto = riel.classList.toggle( 'abierto' );
				abrirRiel.setAttribute( 'aria-expanded', abierto ? 'true' : 'false' );
			} );
			// Tocar fuera la cierra.
			document.addEventListener( 'click', function ( e ) {
				if ( riel.classList.contains( 'abierto' ) && ! riel.contains( e.target ) ) {
					riel.classList.remove( 'abierto' );
					abrirRiel.setAttribute( 'aria-expanded', 'false' );
				}
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && riel.classList.contains( 'abierto' ) ) {
					riel.classList.remove( 'abierto' );
					abrirRiel.setAttribute( 'aria-expanded', 'false' );
					abrirRiel.focus();
				}
			} );
		}

		var imprimir = document.getElementById( 'pk-imprimir' );
		if ( imprimir ) {
			imprimir.addEventListener( 'click', function () {
				window.print();
			} );
		}

		document.querySelectorAll( '.pk-viz' ).forEach( function ( fig ) {
			var tip = document.createElement( 'div' );
			tip.className = 'pk-viz-tip';
			tip.hidden = true;
			fig.appendChild( tip );

			function mostrar( g, x, y ) {
				tip.textContent = g.getAttribute( 'data-tip' );
				tip.hidden = false;
				tip.style.left = x + 'px';
				tip.style.top = y + 'px';
			}

			fig.querySelectorAll( '.pk-viz-marca' ).forEach( function ( g ) {
				g.addEventListener( 'mousemove', function ( e ) {
					var r = fig.getBoundingClientRect();
					mostrar( g, e.clientX - r.left, e.clientY - r.top );
				} );
				g.addEventListener( 'focus', function () {
					var r = fig.getBoundingClientRect();
					var b = g.getBoundingClientRect();
					mostrar( g, b.left - r.left + b.width / 2, b.top - r.top );
				} );
				g.addEventListener( 'mouseleave', function () {
					tip.hidden = true;
				} );
				g.addEventListener( 'blur', function () {
					tip.hidden = true;
				} );
			} );
		} );
	} );
}() );
