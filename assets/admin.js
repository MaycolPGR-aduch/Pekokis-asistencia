/**
 * Panel de gerencia: tooltip de los gráficos SVG (.pk-viz), que al pasar el
 * mouse o con el teclado muestra el dato de la barra (data-tip), y el botón
 * "Imprimir o guardar como PDF" del informe.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
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
