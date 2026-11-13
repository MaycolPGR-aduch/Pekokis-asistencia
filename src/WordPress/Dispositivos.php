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
