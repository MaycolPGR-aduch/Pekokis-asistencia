<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\Sueldo;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Descuentos de monto libre en el pago de un mes (pedido de gerencia, 0.22.0):
 * monto y motivo. Se restan del total del mes. No se editan: se anulan con
 * motivo, y todo queda en la auditoría. Solo quien ve los pagos.
 */
final class Descuentos {
	/**
	 * Total vigente por persona en un mes (céntimos).
	 *
	 * @param int[] $ids
	 * @return array<int, int>
	 */
	public static function totales( array $ids, string $mes ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return array();
		}

		$tabla = Tablas::nombre( 'descuentos' );
		$lista = implode( ',', $ids );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT trabajador_id, SUM(monto_centimos) AS total FROM {$tabla} WHERE mes = %s AND estado = 'vigente' AND trabajador_id IN ({$lista}) GROUP BY trabajador_id", $mes ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();

		foreach ( (array) $filas as $f ) {
			$out[ (int) $f['trabajador_id'] ] = (int) $f['total'];
		}

		return $out;
	}

	/** @return array<int, array<string, mixed>> Los del mes (vigentes y anulados), o todos si $mes es null. */
	public static function deTrabajador( int $trabajadorId, ?string $mes = null ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'descuentos' );
		$sql   = null === $mes
			? $wpdb->prepare( "SELECT * FROM {$tabla} WHERE trabajador_id = %d ORDER BY mes DESC, id DESC", $trabajadorId ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->prepare( "SELECT * FROM {$tabla} WHERE trabajador_id = %d AND mes = %s ORDER BY id DESC", $trabajadorId, $mes ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function crear( int $trabajadorId, string $mes, string $monto, string $motivo ): int|WP_Error {
		global $wpdb;

		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			return new WP_Error( 'pka_permiso', 'Solo gerencia registra descuentos.' );
		}

		if ( null === Trabajadores::buscar( $trabajadorId ) ) {
			return new WP_Error( 'pka_no_existe', 'Ese trabajador no existe.' );
		}

		if ( Trabajadores::esPropio( $trabajadorId ) ) {
			return new WP_Error( 'pka_propio', 'No puedes registrar un descuento en tu propio pago.' );
		}

		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $mes ) ) {
			return new WP_Error( 'pka_mes', 'Elige el mes del descuento.' );
		}

		$centimos = Sueldo::aCentimos( $monto );

		if ( null === $centimos || $centimos <= 0 ) {
			return new WP_Error( 'pka_monto', 'Escribe el monto en soles, por ejemplo 50 o 25.50.' );
		}

		if ( $centimos > 10000000 ) {
			return new WP_Error( 'pka_monto', 'El monto parece demasiado alto. Revísalo.' );
		}

		$motivo = mb_substr( trim( $motivo ), 0, 255 );

		if ( '' === $motivo ) {
			return new WP_Error( 'pka_motivo', 'Escribe el motivo del descuento.' );
		}

		$wpdb->insert(
			Tablas::nombre( 'descuentos' ),
			array(
				'trabajador_id'  => $trabajadorId,
				'mes'            => $mes,
				'monto_centimos' => $centimos,
				'motivo'         => $motivo,
				'estado'         => 'vigente',
				'creado_por'     => get_current_user_id(),
				'creado_en'      => Peticion::ahoraUtc(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'descuento.crear', 'trabajador', $trabajadorId, null, array( 'mes' => $mes, 'monto' => Sueldo::soles( $centimos ) ), $motivo );

		return $id;
	}

	public static function anular( int $id, string $motivo ): true|WP_Error {
		global $wpdb;

		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			return new WP_Error( 'pka_permiso', 'Solo gerencia anula descuentos.' );
		}

		$tabla = Tablas::nombre( 'descuentos' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d AND estado = 'vigente'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return new WP_Error( 'pka_no_existe', 'Ese descuento no existe o ya está anulado.' );
		}

		if ( Trabajadores::esPropio( (int) $fila['trabajador_id'] ) ) {
			return new WP_Error( 'pka_propio', 'No puedes cambiar tu propio pago.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe por qué se anula.' );
		}

		$wpdb->update( $tabla, array( 'estado' => 'anulado', 'anulado_por' => get_current_user_id(), 'anulado_en' => Peticion::ahoraUtc(), 'motivo_anulacion' => mb_substr( $motivo, 0, 255 ) ), array( 'id' => $id ) );
		Auditoria::registrar( 'descuento.anular', 'trabajador', (int) $fila['trabajador_id'], array( 'mes' => $fila['mes'], 'monto' => Sueldo::soles( (int) $fila['monto_centimos'] ), 'motivo' => $fila['motivo'] ), null, $motivo );

		return true;
	}
}
