<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\Base64Url;

defined( 'ABSPATH' ) || exit;

/**
 * Vincula cada celular a un solo trabajador con una cookie de larga duración.
 * No es infalible (borrar los datos del navegador la quita), pero frena el caso
 * "registré mi huella y la de mi compañera en mi celular".
 */
final class Dispositivos {
	public const COOKIE = 'pka_dispositivo';

	private const DURACION = 2 * YEAR_IN_SECONDS;

	/** @return array<string, mixed>|null Dispositivo vigente de este navegador. */
	public static function actual(): ?array {
		global $wpdb;

		$token = Peticion::cookie( self::COOKIE );

		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
			return null;
		}

		$tabla = Tablas::nombre( 'dispositivos' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE token_hash = %s AND revocado_en IS NULL", hash( 'sha256', $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $fila ? $fila : null;
	}

	/** Registra este navegador como el celular del trabajador y deja la cookie. */
	public static function vincular( int $trabajadorId ): int {
		global $wpdb;

		$actual = self::actual();

		if ( $actual && (int) $actual['trabajador_id'] === $trabajadorId ) {
			return (int) $actual['id'];
		}

		$token = Base64Url::aleatorio( 32 );

		$wpdb->insert(
			Tablas::nombre( 'dispositivos' ),
			array(
				'trabajador_id' => $trabajadorId,
				'token_hash'    => hash( 'sha256', $token ),
				'user_agent'    => Peticion::navegador(),
				'creado_en'     => Peticion::ahoraUtc(),
			)
		);

		Peticion::ponerCookie( self::COOKIE, $token, self::DURACION );

		return (int) $wpdb->insert_id;
	}

	/** Trabajador activo al que ya pertenece este celular, si es otro distinto. */
	public static function perteneceAOtro( int $trabajadorId ): bool {
		$actual = self::actual();

		if ( ! $actual || (int) $actual['trabajador_id'] === $trabajadorId ) {
			return false;
		}

		$duenio = Trabajadores::buscar( (int) $actual['trabajador_id'] );

		return null !== $duenio && Trabajadores::ACTIVO === $duenio['estado'];
	}

	public static function tocar( int $dispositivoId ): void {
		global $wpdb;

		$wpdb->update( Tablas::nombre( 'dispositivos' ), array( 'ultimo_uso' => Peticion::ahoraUtc() ), array( 'id' => $dispositivoId ) );
	}

	/**
	 * Al activar un celular nuevo, revoca los demás celulares y llaves del trabajador.
	 *
	 * @return int Cantidad de llaves revocadas.
	 */
	public static function revocarOtros( int $trabajadorId, int $dispositivoNuevo, int $credencialNueva ): int {
		global $wpdb;

		$ahora = Peticion::ahoraUtc();
		$d     = Tablas::nombre( 'dispositivos' );
		$c     = Tablas::nombre( 'credenciales' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$d} SET revocado_en = %s WHERE trabajador_id = %d AND id <> %d AND revocado_en IS NULL", $ahora, $trabajadorId, $dispositivoNuevo ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$c} SET revocada_en = %s WHERE trabajador_id = %d AND id <> %d AND revocada_en IS NULL", $ahora, $trabajadorId, $credencialNueva ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Da de baja un celular y sus llaves (liberar el móvil). */
	public static function revocarDispositivo( int $dispositivoId ): void {
		global $wpdb;

		$ahora = Peticion::ahoraUtc();

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tablas::nombre( 'dispositivos' ) . ' SET revocado_en = %s WHERE id = %d AND revocado_en IS NULL', $ahora, $dispositivoId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tablas::nombre( 'credenciales' ) . ' SET revocada_en = %s WHERE dispositivo_id = %d AND revocada_en IS NULL', $ahora, $dispositivoId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Revoca todos los celulares y passkeys de un trabajador (cese o cambio de celular). */
	public static function revocarTodo( int $trabajadorId ): void {
		global $wpdb;

		$ahora = Peticion::ahoraUtc();

		foreach ( array( 'dispositivos' => 'revocado_en', 'credenciales' => 'revocada_en' ) as $tabla => $columna ) {
			$nombre = Tablas::nombre( $tabla );
			$wpdb->query( $wpdb->prepare( "UPDATE {$nombre} SET {$columna} = %s WHERE trabajador_id = %d AND {$columna} IS NULL", $ahora, $trabajadorId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
