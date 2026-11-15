<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Sesión del trabajador en la app (T0 de las maquetas): entra una vez con su
 * huella y puede ver sus horas, su recibo y su perfil desde cualquier lugar.
 * MARCAR no usa la sesión: cada marca pide otra vez la huella.
 *
 * También se entra con el PIN opcional, solo en el celular registrado (Pines).
 *
 * La sesión es una cookie HttpOnly con un token aleatorio; los datos quedan del
 * lado del servidor. Deja de valer si se revoca la llave (cambio de celular) o
 * si la persona cesa.
 */
final class SesionTrabajador {
	public const COOKIE   = 'pka_sesion';
	public const SEGUNDOS = 30 * DAY_IN_SECONDS;

	public static function crear( int $trabajadorId, int $credencialId, ?int $dispositivoId ): void {
		self::cerrar();

		$token = Temporales::guardar( 'sesion', array( 'trabajador_id' => $trabajadorId, 'credencial_id' => $credencialId, 'dispositivo_id' => $dispositivoId ), self::SEGUNDOS );
		Peticion::ponerCookie( self::COOKIE, $token, self::SEGUNDOS );
	}

	/**
	 * La sesión de este navegador, si sigue valiendo.
	 *
	 * @return array{trabajador: array<string, mixed>, credencial_id: int, dispositivo_id: ?int}|null
	 */
	public static function actual(): ?array {
		global $wpdb;

		$token = Peticion::cookie( self::COOKIE );

		if ( '' === $token ) {
			return null;
		}

		$datos = Temporales::leer( 'sesion', $token );
		$t     = $datos ? Trabajadores::buscar( (int) $datos['trabajador_id'] ) : null;
		$viva  = $datos && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Tablas::nombre( 'credenciales' ) . ' WHERE id = %d AND revocada_en IS NULL AND pendiente = 0', (int) $datos['credencial_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $t || ! $viva || Trabajadores::ACTIVO !== $t['estado'] ) {
			self::cerrar();
			return null;
		}

		return array( 'trabajador' => $t, 'credencial_id' => (int) $datos['credencial_id'], 'dispositivo_id' => $datos['dispositivo_id'] ? (int) $datos['dispositivo_id'] : null );
	}

	public static function cerrar(): void {
		$token = Peticion::cookie( self::COOKIE );

		if ( '' !== $token ) {
			Temporales::borrar( 'sesion', $token );
		}

		Peticion::borrarCookie( self::COOKIE );
	}
}
