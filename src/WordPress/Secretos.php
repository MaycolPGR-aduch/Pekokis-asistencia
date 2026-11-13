<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Secreto con el que se firman los códigos QR y los pases de presencia.
 *
 * Lo ideal es definirlo en wp-config.php, fuera de la base de datos:
 *   define( 'PKA_SECRETO', '...64 caracteres hexadecimales...' );
 * Si no está, se genera uno y se guarda en una opción (sin autocarga).
 */
final class Secretos {
	private const OPCION = 'pka_secreto';

	public static function clave(): string {
		if ( self::vieneDeWpConfig() ) {
			return (string) constant( 'PKA_SECRETO' );
		}

		$guardada = get_option( self::OPCION );

		if ( is_string( $guardada ) && strlen( $guardada ) >= 64 ) {
			return $guardada;
		}

		add_option( self::OPCION, bin2hex( random_bytes( 32 ) ), '', false );

		// Se vuelve a leer por si otra petición lo creó al mismo tiempo.
		return (string) get_option( self::OPCION );
	}

	public static function vieneDeWpConfig(): bool {
		return defined( 'PKA_SECRETO' ) && is_string( constant( 'PKA_SECRETO' ) ) && strlen( constant( 'PKA_SECRETO' ) ) >= 32;
	}
}
