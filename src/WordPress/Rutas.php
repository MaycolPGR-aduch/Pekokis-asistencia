<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Pantallas públicas que dibuja el plugin (el tema está vacío).
 *
 *   /          App del trabajador: identificarse con la huella y marcar.
 *   /activar/  Crear la passkey con el enlace de un solo uso.
 */
final class Rutas {
	private const VISTAS = array(
		''        => 'app',
		'activar' => 'activar',
	);

	public static function registrar(): void {
		add_action( 'template_redirect', array( self::class, 'despachar' ), 0 );
	}

	public static function despachar(): void {
		$vista = self::VISTAS[ self::rutaActual() ] ?? null;

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
		);

		require PKA_DIR . 'templates/app.php';
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
