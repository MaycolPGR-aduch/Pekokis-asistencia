/**
 * Panel de gerencia: tooltip de los gráficos SVG (.pk-viz), que al pasar el
 * mouse o con el teclado muestra el dato de la barra (data-tip); el botón
 * "Imprimir o guardar como PDF" del informe; y los cambios sin guardar de
 * Sueldos y tarifas (G8) y del panel "Editar registro" (G4B).
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

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.pk-sueldos, .pk-panel form' ).forEach( seguirCambios );

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
