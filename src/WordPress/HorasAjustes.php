<?php

namespace Pekokis\Asistencia\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes de gerencia a las horas de un día (pedido de gerencia, 0.22.0):
 * minutos que se suman (o restan) a lo trabajado, con motivo. Lo sumado
 * completa primero el horario y lo que sobra es hora extra (en sábado, domingo
 * o feriado, horas de ese día). No se editan: se anulan con motivo.
 */
final class HorasAjustes {
	public const MAX_MINUTOS = 960; // 16 h, en un sentido o en el otro.

	/**
	 * Suma vigente por persona y día.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, int>> trabajador_id => dia => minutos
	 */
	public static function delRango( array $ids, string $desde, string $hasta ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return array();
		}

		$tabla = Tablas::nombre( 'horas_ajustes' );
		$lista = implode( ',', $ids );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT trabajador_id, dia, SUM(minutos) AS total FROM {$tabla} WHERE estado = 'vigente' AND dia BETWEEN %s AND %s AND trabajador_id IN ({$lista}) GROUP BY trabajador_id, dia", $desde, $hasta ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();

		foreach ( (array) $filas as $f ) {
			$out[ (int) $f['trabajador_id'] ][ (string) $f['dia'] ] = (int) $f['total'];
		}

		return $out;
	}

	/** @return array<int, array<string, mixed>> Los de la persona en el rango (vigentes y anulados). */
	public static function deTrabajador( int $trabajadorId, string $desde, string $hasta ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'horas_ajustes' );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE trabajador_id = %d AND dia BETWEEN %s AND %s ORDER BY dia, id", $trabajadorId, $desde, $hasta ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function crear( int $trabajadorId, string $dia, int $minutos, string $motivo ): int|WP_Error {
		global $wpdb;

		if ( ! current_user_can( Capacidades::JUSTIFICAR ) ) {
			return new WP_Error( 'pka_permiso', 'Solo gerencia ajusta las horas de un día.' );
		}

		if ( null === Trabajadores::buscar( $trabajadorId ) ) {
			return new WP_Error( 'pka_no_existe', 'Ese trabajador no existe.' );
		}

		if ( Trabajadores::esPropio( $trabajadorId ) ) {
			return new WP_Error( 'pka_propio', 'No puedes ajustar tus propias horas.' );
		}

		if ( ! \Pekokis\Asistencia\Nucleo\ValidacionTrabajador::esFecha( $dia ) || $dia > Jornadas::ayer() ) {
			return new WP_Error( 'pka_dia', 'Elige un día que ya terminó.' );
		}

		if ( 0 === $minutos || abs( $minutos ) > self::MAX_MINUTOS ) {
			return new WP_Error( 'pka_minutos', 'Escribe cuántas horas sumar o restar (por ejemplo 1:30 o -0:45), hasta 16 horas.' );
		}

		$motivo = mb_substr( trim( $motivo ), 0, 255 );

		if ( '' === $motivo ) {
			return new WP_Error( 'pka_motivo', 'Escribe el motivo del ajuste.' );
		}

		$wpdb->insert(
			Tablas::nombre( 'horas_ajustes' ),
			array(
				'trabajador_id' => $trabajadorId,
				'dia'           => $dia,
				'minutos'       => $minutos,
				'motivo'        => $motivo,
				'estado'        => 'vigente',
				'creado_por'    => get_current_user_id(),
				'creado_en'     => Peticion::ahoraUtc(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'horas.ajustar', 'trabajador', $trabajadorId, null, array( 'dia' => $dia, 'minutos' => self::texto( $minutos ) ), $motivo );

		return $id;
	}

	public static function anular( int $id, string $motivo ): true|WP_Error {
		global $wpdb;

		if ( ! current_user_can( Capacidades::JUSTIFICAR ) ) {
			return new WP_Error( 'pka_permiso', 'Solo gerencia anula ajustes de horas.' );
		}

		$tabla = Tablas::nombre( 'horas_ajustes' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d AND estado = 'vigente'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return new WP_Error( 'pka_no_existe', 'Ese ajuste no existe o ya está anulado.' );
		}

		if ( Trabajadores::esPropio( (int) $fila['trabajador_id'] ) ) {
			return new WP_Error( 'pka_propio', 'No puedes anular un ajuste de tus propias horas.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe por qué se anula.' );
		}

		$wpdb->update( $tabla, array( 'estado' => 'anulado', 'anulado_por' => get_current_user_id(), 'anulado_en' => Peticion::ahoraUtc(), 'motivo_anulacion' => mb_substr( $motivo, 0, 255 ) ), array( 'id' => $id ) );
		Auditoria::registrar( 'horas.anular_ajuste', 'trabajador', (int) $fila['trabajador_id'], array( 'dia' => $fila['dia'], 'minutos' => self::texto( (int) $fila['minutos'] ), 'motivo' => $fila['motivo'] ), null, $motivo );

		return true;
	}

	/** "1:30", "-0:45", "2" o "-1,5" → minutos con signo (null si no se entiende). */
	public static function aMinutos( string $texto ): ?int {
		$texto = str_replace( array( ' ', 'h' ), '', trim( $texto ) );
		$signo = 1;

		if ( '' === $texto ) {
			return null;
		}

		if ( in_array( $texto[0], array( '-', '−', '+' ), true ) || str_starts_with( $texto, '−' ) ) {
			$signo = str_starts_with( $texto, '+' ) ? 1 : -1;
			$texto = ltrim( $texto, '+-' );
			$texto = str_starts_with( $texto, '−' ) ? substr( $texto, strlen( '−' ) ) : $texto;
		}

		if ( preg_match( '/^(\d{1,2}):([0-5]\d)$/', $texto, $m ) ) {
			return $signo * ( (int) $m[1] * 60 + (int) $m[2] );
		}

		if ( preg_match( '/^\d{1,2}([.,]\d{1,2})?$/', $texto ) ) {
			return $signo * (int) round( (float) str_replace( ',', '.', $texto ) * 60 );
		}

		return null;
	}

	/** 90 → "+1:30", -45 → "−0:45". */
	public static function texto( int $minutos ): string {
		return ( $minutos < 0 ? '−' : '+' ) . intdiv( abs( $minutos ), 60 ) . ':' . str_pad( (string) ( abs( $minutos ) % 60 ), 2, '0', STR_PAD_LEFT );
	}
}
