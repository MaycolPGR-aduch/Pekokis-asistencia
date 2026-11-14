<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Pantallas públicas que dibuja el plugin (el tema está vacío).
 *
 *   /          App del trabajador: identificarse con la huella y marcar.
 *   /m?q=…     Lo abre el QR de la pantalla: valida la presencia y sigue como /.
 *   /activar/  Crear la passkey con el enlace de un solo uso.
 *   /pantalla/ La tablet de la entrada con el QR que cambia cada 30 segundos.
 */
final class Rutas {
	private const VISTAS = array(
		''         => 'app',
		'm'        => 'app',
		'activar'  => 'activar',
		'pantalla' => 'pantalla',
	);

	public static function registrar(): void {
		add_action( 'template_redirect', array( self::class, 'despachar' ), 0 );
	}

	public static function despachar(): void {
		$ruta = self::rutaActual();

		if ( 'manifest.webmanifest' === $ruta ) {
			self::manifest();
		}

		if ( 'sw.js' === $ruta ) {
			self::serviceWorker();
		}

		$vista = self::VISTAS[ $ruta ] ?? null;

		if ( null === $vista ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'" );

		$config = array(
			'vista' => $vista,
			'api'   => esc_url_raw( rest_url( Api::ESPACIO . '/' ) ),
			'inicio' => esc_url_raw( home_url( '/' ) ),
			'sw'     => esc_url_raw( home_url( '/sw.js' ) ),
		);

		if ( 'pantalla' === $vista && current_user_can( Capacidades::AJUSTES ) ) {
			// Solo un administrador con sesión puede convertir este equipo en pantalla.
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}

		require PKA_DIR . 'templates/app.php';
		exit;
	}

	/** Manifest de la PWA: permite "Agregar a la pantalla de inicio" con ícono propio. */
	private static function manifest(): never {
		$inicio = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		status_header( 200 );
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		echo wp_json_encode(
			array(
				'name'             => 'Asistencia Pekokis',
				'short_name'       => 'Asistencia',
				'description'      => 'Marca tu entrada y salida en el taller con tu huella.',
				'lang'             => 'es-PE',
				'start_url'        => $inicio,
				'scope'            => $inicio,
				'display'          => 'standalone',
				'orientation'      => 'portrait',
				'background_color' => '#fff8fc',
				'theme_color'      => '#e6007e',
				'icons'            => array(
					array( 'src' => self::asset( 'iconos/icono-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
					array( 'src' => self::asset( 'iconos/icono-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
					array( 'src' => self::asset( 'iconos/icono-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		exit;
	}

	/**
	 * Service worker mínimo. Solo guarda en caché los archivos estáticos de la app
	 * (llevan versión en la URL); nunca la API ni las páginas, para que el estado
	 * de un trabajador jamás salga de una caché.
	 */
	private static function serviceWorker(): never {
		$prefijo = (string) wp_parse_url( PKA_URL . 'assets/', PHP_URL_PATH );
		$cache   = 'pka-' . PKA_VERSION;

		status_header( 200 );
		header( 'Content-Type: text/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: ' . (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		echo "'use strict';\n";
		echo 'var CACHE = ' . wp_json_encode( $cache ) . ";\n";
		echo 'var PREFIJO = ' . wp_json_encode( $prefijo ) . ";\n";
		echo <<<'JS'
self.addEventListener( 'install', function () {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function ( evento ) {
	evento.waitUntil(
		caches.keys().then( function ( claves ) {
			return Promise.all( claves.filter( function ( c ) {
				return c.indexOf( 'pka-' ) === 0 && c !== CACHE;
			} ).map( function ( c ) {
				return caches.delete( c );
			} ) );
		} ).then( function () {
			return self.clients.claim();
		} )
	);
} );

self.addEventListener( 'fetch', function ( evento ) {
	var url = new URL( evento.request.url );

	// Solo archivos estáticos propios (css, js, íconos). Todo lo demás va a la red.
	if ( 'GET' !== evento.request.method || url.origin !== self.location.origin || url.pathname.indexOf( PREFIJO ) !== 0 ) {
		return;
	}

	evento.respondWith(
		caches.open( CACHE ).then( function ( cache ) {
			return cache.match( evento.request ).then( function ( guardada ) {
				return guardada || fetch( evento.request ).then( function ( respuesta ) {
					if ( respuesta.ok ) {
						cache.put( evento.request, respuesta.clone() );
					}
					return respuesta;
				} );
			} );
		} )
	);
} );
JS;
		exit;
	}

	private static function rutaActual(): string {
		$ruta = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '' !== $base && str_starts_with( $ruta, $base ) ) {
			$ruta = substr( $ruta, strlen( $base ) );
		}

		return trim( $ruta, '/' );
	}

	/** URL de un archivo de assets con versión para romper la caché. */
	public static function asset( string $archivo ): string {
		$ruta = PKA_DIR . 'assets/' . $archivo;

		return PKA_URL . 'assets/' . $archivo . '?v=' . ( file_exists( $ruta ) ? filemtime( $ruta ) : PKA_VERSION );
	}
}
