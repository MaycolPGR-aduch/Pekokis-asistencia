<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\ValidacionIdentidad;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * PIN de ingreso opcional (maquetas T0B, T0C y T7C).
 *
 * - Solo inicia sesión, y solo en el celular registrado de esa persona (la cookie
 *   del celular dice de quién es). Para marcar se sigue pidiendo la huella.
 * - 5 PIN equivocados bloquean 15 minutos.
 * - Se guarda con password_hash(); nadie puede leerlo.
 */
final class Pines {
	public const MAX_FALLOS = 5;
	public const VENTANA    = 15 * MINUTE_IN_SECONDS;

	public static function activo(): bool {
		return ! empty( Ajustes::todos()['pin_activo'] );
	}

	public static function largo(): int {
		return max( 4, min( 8, (int) Ajustes::todos()['pin_largo'] ) );
	}

	/**
	 * El celular registrado de este navegador, con su llave vigente y su dueño activo.
	 *
	 * @return array{trabajador: array<string, mixed>, credencial_id: int, dispositivo_id: int}|null
	 */
	public static function celularDeEsteNavegador(): ?array {
		global $wpdb;

		$d = Dispositivos::actual();
		$t = $d ? Trabajadores::buscar( (int) $d['trabajador_id'] ) : null;

		if ( ! $t || Trabajadores::ACTIVO !== $t['estado'] ) {
			return null;
		}

		$c = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Tablas::nombre( 'credenciales' ) . ' WHERE dispositivo_id = %d AND trabajador_id = %d AND revocada_en IS NULL AND pendiente = 0 ORDER BY id DESC LIMIT 1', (int) $d['id'], (int) $t['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $c ? array( 'trabajador' => $t, 'credencial_id' => $c, 'dispositivo_id' => (int) $d['id'] ) : null;
	}

	/** ¿Se puede entrar con PIN en este celular? */
	public static function disponible(): bool {
		$celular = self::celularDeEsteNavegador();

		return self::activo() && null !== $celular && '' !== (string) $celular['trabajador']['pin_hash'];
	}

	/** @return array<string, mixed>|WP_Error El trabajador, con la sesión ya iniciada. */
	public static function entrar( string $pin ): array|WP_Error {
		$celular = self::activo() ? self::celularDeEsteNavegador() : null;

		if ( null === $celular || '' === (string) $celular['trabajador']['pin_hash'] ) {
			return new WP_Error( 'pka_pin_no', 'En este celular no se puede entrar con PIN. Usa tu huella.', array( 'status' => 403 ) );
		}

		$t     = $celular['trabajador'];
		$clave = 'pin|' . $t['id'];

		if ( Temporales::bloqueado( $clave, self::MAX_FALLOS ) ) {
			return new WP_Error( 'pka_bloqueado', 'Pusiste mal el PIN 5 veces. Espera 15 minutos o entra con tu huella.', array( 'status' => 429, 'intentos' => 0 ) );
		}

		if ( ! password_verify( ValidacionIdentidad::normalizarPin( $pin ), (string) $t['pin_hash'] ) ) {
			Temporales::sumarFallo( $clave, self::VENTANA );
			$quedan = max( 0, self::MAX_FALLOS - Temporales::intentos( $clave ) );

			if ( 0 === $quedan ) {
				Auditoria::registrar( 'trabajador.pin_bloqueado', 'trabajador', (int) $t['id'], null, null, '5 PIN equivocados', (int) $t['id'] );
			}

			return new WP_Error(
				'pka_pin',
				$quedan ? 'PIN incorrecto. Te quedan ' . $quedan . ( 1 === $quedan ? ' intento.' : ' intentos.' ) : 'Pusiste mal el PIN 5 veces. Espera 15 minutos o entra con tu huella.',
				array( 'status' => $quedan ? 401 : 429, 'intentos' => $quedan )
			);
		}

		Temporales::limpiarFallos( $clave );
		Dispositivos::tocar( $celular['dispositivo_id'] );
		SesionTrabajador::crear( (int) $t['id'], $celular['credencial_id'], $celular['dispositivo_id'] );

		return $t;
	}

	public static function crear( int $trabajadorId, string $pin ): true|WP_Error {
		global $wpdb;

		if ( ! self::activo() ) {
			return new WP_Error( 'pka_pin_no', 'Gerencia desactivó el PIN de ingreso.', array( 'status' => 403 ) );
		}

		$pin   = ValidacionIdentidad::normalizarPin( $pin );
		$error = ValidacionIdentidad::errorPin( $pin, self::largo() );

		if ( null !== $error ) {
			return new WP_Error( 'pka_pin', $error, array( 'status' => 422 ) );
		}

		$t = Trabajadores::buscar( $trabajadorId );

		if ( $t && preg_match( '/^\d+$/', (string) $t['dni'] ) && str_contains( (string) $t['dni'], $pin ) ) {
			return new WP_Error( 'pka_pin', 'No uses números de tu DNI en el PIN.', array( 'status' => 422 ) );
		}

		$tenia = $t && '' !== (string) $t['pin_hash'];
		$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'pin_hash' => password_hash( $pin, PASSWORD_DEFAULT ), 'pin_creado_en' => Peticion::ahoraUtc() ), array( 'id' => $trabajadorId ) );
		Temporales::limpiarFallos( 'pin|' . $trabajadorId );
		Auditoria::registrar( $tenia ? 'trabajador.pin_cambiar' : 'trabajador.pin_crear', 'trabajador', $trabajadorId, null, null, '', $trabajadorId );

		return true;
	}

	/** Lo quita la persona desde su perfil, o gerencia desde el panel (si la persona lo olvidó). */
	public static function quitar( int $trabajadorId, string $motivo = '', bool $porElTrabajador = true ): void {
		global $wpdb;

		$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'pin_hash' => '', 'pin_creado_en' => null ), array( 'id' => $trabajadorId ) );
		Temporales::limpiarFallos( 'pin|' . $trabajadorId );
		Auditoria::registrar( 'trabajador.pin_quitar', 'trabajador', $trabajadorId, null, null, $motivo, $porElTrabajador ? $trabajadorId : null );
	}
}
