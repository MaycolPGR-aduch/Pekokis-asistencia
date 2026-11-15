<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\Sueldo;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * La casilla "Pagado" de la calculadora: guarda que el pago del mes de una
 * persona ya se hizo, con el total de ese momento. Desmarcarla no borra: anula.
 */
final class Pagos {
	/** @return array<int, array<string, mixed>> trabajador_id => pago vigente del mes */
	public static function delMes( string $mes ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'pagos' );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE mes = %s AND estado = 'pagado'", $mes ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$porId = array();

		foreach ( $filas as $f ) {
			$porId[ (int) $f['trabajador_id'] ] = $f;
		}

		return $porId;
	}

	/** @return array<string, mixed>|null */
	public static function delTrabajador( int $trabajadorId, string $mes ): ?array {
		global $wpdb;

		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'pagos' ) . " WHERE trabajador_id = %d AND mes = %s AND estado = 'pagado' LIMIT 1", $trabajadorId, $mes ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $fila ? $fila : null;
	}

	public static function marcar( int $trabajadorId, string $mes, int $totalCentimos ): true|WP_Error {
		global $wpdb;

		if ( Trabajadores::esPropio( $trabajadorId ) ) {
			return new WP_Error( 'pka_propio', 'No puedes marcar tu propio pago.' );
		}

		if ( null !== self::delTrabajador( $trabajadorId, $mes ) ) {
			return new WP_Error( 'pka_pago', 'Ese pago ya estaba marcado.' );
		}

		$wpdb->insert(
			Tablas::nombre( 'pagos' ),
			array(
				'trabajador_id'  => $trabajadorId,
				'mes'            => $mes,
				'total_centimos' => max( 0, $totalCentimos ),
				'estado'         => 'pagado',
				'marcado_por'    => get_current_user_id(),
				'marcado_en'     => Peticion::ahoraUtc(),
			)
		);

		Auditoria::registrar( 'pago.marcar', 'trabajador', $trabajadorId, null, array( 'mes' => $mes, 'total' => Sueldo::soles( $totalCentimos ) ) );

		return true;
	}

	public static function desmarcar( int $trabajadorId, string $mes, string $motivo ): true|WP_Error {
		global $wpdb;

		$pago = self::delTrabajador( $trabajadorId, $mes );

		if ( ! $pago ) {
			return new WP_Error( 'pka_pago', 'Ese pago no estaba marcado.' );
		}

		if ( Trabajadores::esPropio( $trabajadorId ) ) {
			return new WP_Error( 'pka_propio', 'No puedes cambiar tu propio pago.' );
		}

		$wpdb->update( Tablas::nombre( 'pagos' ), array( 'estado' => 'anulado', 'anulado_por' => get_current_user_id(), 'anulado_en' => Peticion::ahoraUtc() ), array( 'id' => (int) $pago['id'] ) );
		Auditoria::registrar( 'pago.desmarcar', 'trabajador', $trabajadorId, array( 'mes' => $mes, 'total' => Sueldo::soles( (int) $pago['total_centimos'] ) ), null, $motivo );

		return true;
	}
}
