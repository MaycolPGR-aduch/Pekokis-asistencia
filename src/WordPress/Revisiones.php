<?php

namespace Pekokis\Asistencia\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Marcas que el jefe tiene que revisar: emergencias marcadas fuera del taller y
 * salidas que requieren aprobación (personal, salud). La marca no cambia: la
 * decisión se guarda aparte y en la auditoría.
 */
final class Revisiones {
	public const APROBADA  = 'aprobada';
	public const RECHAZADA = 'rechazada';

	/**
	 * @param int[]|null $trabajadores Limitar a estos trabajadores (equipo del jefe); null = todos.
	 * @return array<int, array<string, mixed>>
	 */
	public static function pendientes( ?array $trabajadores ): array {
		global $wpdb;

		if ( is_array( $trabajadores ) && ! $trabajadores ) {
			return array();
		}

		$m     = Tablas::nombre( 'marcas' );
		$r     = Tablas::nombre( 'revisiones' );
		$t     = Tablas::nombre( 'trabajadores' );
		$solo  = is_array( $trabajadores ) ? 'AND m.trabajador_id IN (' . implode( ',', array_map( 'intval', $trabajadores ) ) . ')' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT m.*, t.nombres, t.apellidos FROM {$m} m
			JOIN {$t} t ON t.id = m.trabajador_id
			LEFT JOIN {$r} r ON r.marca_id = m.id
			WHERE m.requiere_revision = 1 AND r.id IS NULL {$solo}
			ORDER BY m.marcado_utc DESC LIMIT 100",
			ARRAY_A
		);
	}

	public static function resolver( int $marcaId, string $decision, string $nota ): true|WP_Error {
		global $wpdb;

		if ( ! in_array( $decision, array( self::APROBADA, self::RECHAZADA ), true ) ) {
			return new WP_Error( 'pka_decision', 'Decisión desconocida.' );
		}

		if ( self::RECHAZADA === $decision && '' === trim( $nota ) ) {
			return new WP_Error( 'pka_nota', 'Para rechazar, escribe el motivo.' );
		}

		$tabla = Tablas::nombre( 'revisiones' );

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tabla} WHERE marca_id = %d", $marcaId ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return new WP_Error( 'pka_revisada', 'Esa marca ya fue revisada.' );
		}

		$ok = $wpdb->insert(
			Tablas::nombre( 'revisiones' ),
			array(
				'marca_id'     => $marcaId,
				'decision'     => $decision,
				'nota'         => mb_substr( $nota, 0, 500 ),
				'revisado_por' => get_current_user_id(),
				'revisado_en'  => Peticion::ahoraUtc(),
			)
		);

		if ( false === $ok ) {
			return new WP_Error( 'pka_revisada', 'Esa marca ya fue revisada.' );
		}

		Auditoria::registrar( 'marca.revisar', 'marca', $marcaId, null, array( 'decision' => $decision ), $nota );

		return true;
	}
}
