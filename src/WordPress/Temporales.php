<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\Base64Url;

defined( 'ABSPATH' ) || exit;

/**
 * Datos de vida corta guardados del lado del servidor: retos de passkey,
 * tickets de marca y contadores de intentos.
 */
final class Temporales {
	/** Guarda datos y devuelve una clave aleatoria para recuperarlos. */
	public static function guardar( string $tipo, array $datos, int $segundos ): string {
		$clave = Base64Url::aleatorio( 24 );
		set_transient( self::nombre( $tipo, $clave ), $datos, $segundos );

		return $clave;
	}

	/** @return array<string, mixed>|null */
	public static function leer( string $tipo, string $clave ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32}$/', $clave ) ) {
			return null;
		}

		$datos = get_transient( self::nombre( $tipo, $clave ) );

		return is_array( $datos ) ? $datos : null;
	}

	/** Lee y borra: para retos y tickets de un solo uso. */
	public static function tomar( string $tipo, string $clave ): ?array {
		$datos = self::leer( $tipo, $clave );

		if ( null !== $datos ) {
			self::borrar( $tipo, $clave );
		}

		return $datos;
	}

	public static function borrar( string $tipo, string $clave ): void {
		delete_transient( self::nombre( $tipo, $clave ) );
	}

	/** Límite de intentos fallidos por clave (por ejemplo, por IP). */
	public static function bloqueado( string $clave, int $maximo ): bool {
		return (int) get_transient( 'pka_lim_' . md5( $clave ) ) >= $maximo;
	}

	/** Intentos fallidos que lleva una clave en su ventana. */
	public static function intentos( string $clave ): int {
		return (int) get_transient( 'pka_lim_' . md5( $clave ) );
	}

	public static function limpiarFallos( string $clave ): void {
		delete_transient( 'pka_lim_' . md5( $clave ) );
	}

	public static function sumarFallo( string $clave, int $ventana ): void {
		$nombre = 'pka_lim_' . md5( $clave );
		set_transient( $nombre, (int) get_transient( $nombre ) + 1, $ventana );
	}

	private static function nombre( string $tipo, string $clave ): string {
		return 'pka_' . $tipo . '_' . md5( $clave );
	}
}
