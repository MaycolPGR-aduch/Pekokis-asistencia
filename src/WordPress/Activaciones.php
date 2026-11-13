<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\Base64Url;

defined( 'ABSPATH' ) || exit;

/**
 * Enlaces de activación de un solo uso: con ellos el trabajador crea su passkey.
 *
 * El token va después de "#", así no viaja al servidor en la URL ni queda en
 * los registros del hosting; la app lo lee y lo manda en el cuerpo de la petición.
 * En la base de datos solo se guarda su hash.
 */
final class Activaciones {
	public const HORAS_VIGENCIA = 24;

	/** @return string Enlace para entregar al trabajador. */
	public static function generar( int $trabajadorId ): string {
		global $wpdb;

		$tabla = Tablas::nombre( 'activaciones' );
		$ahora = Peticion::ahoraUtc();
		$token = Base64Url::aleatorio( 24 );

		// Un enlace nuevo anula los anteriores que no se usaron.
		$wpdb->query( $wpdb->prepare( "UPDATE {$tabla} SET expira_en = %s WHERE trabajador_id = %d AND usado_en IS NULL AND expira_en > %s", $ahora, $trabajadorId, $ahora ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->insert(
			$tabla,
			array(
				'trabajador_id' => $trabajadorId,
				'token_hash'    => hash( 'sha256', $token ),
				'expira_en'     => gmdate( 'Y-m-d H:i:s', time() + self::HORAS_VIGENCIA * HOUR_IN_SECONDS ),
				'creado_por'    => get_current_user_id(),
				'creado_en'     => $ahora,
			)
		);

		Auditoria::registrar( 'activacion.generar', 'trabajador', $trabajadorId );

		return home_url( '/activar/#' . $token );
	}

	/**
	 * @return array<string, mixed>|null Activación vigente con los datos del trabajador activo.
	 */
	public static function vigente( string $token ): ?array {
		global $wpdb;

		if ( ! preg_match( '/^[A-Za-z0-9_-]{32}$/', $token ) ) {
			return null;
		}

		$a = Tablas::nombre( 'activaciones' );
		$t = Tablas::nombre( 'trabajadores' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fila = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.id AS activacion_id, t.* FROM {$a} a JOIN {$t} t ON t.id = a.trabajador_id
				WHERE a.token_hash = %s AND a.usado_en IS NULL AND a.expira_en > %s AND t.estado = %s",
				hash( 'sha256', $token ),
				Peticion::ahoraUtc(),
				Trabajadores::ACTIVO
			),
			ARRAY_A
		);

		return $fila ? $fila : null;
	}

	public static function consumir( int $activacionId ): void {
		global $wpdb;

		$wpdb->update( Tablas::nombre( 'activaciones' ), array( 'usado_en' => Peticion::ahoraUtc() ), array( 'id' => $activacionId ) );
	}
}
