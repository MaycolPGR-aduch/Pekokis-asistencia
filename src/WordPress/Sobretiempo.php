<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\AutorizacionSobretiempo;
use Pekokis\Asistencia\Nucleo\ValidacionPago;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Horas extra autorizadas. Las autoriza el jefe (o RR.HH.) antes o después del
 * día, para su equipo y nunca para sí mismo. No se borran: se anulan con motivo.
 */
final class Sobretiempo {
	/** @return int|WP_Error */
	public static function crear( array $entrada ): int|WP_Error {
		global $wpdb;

		$datos      = ValidacionPago::normalizarSobretiempo( $entrada );
		$trabajador = Trabajadores::buscar( $datos['trabajador_id'] );

		if ( null === $trabajador ) {
			return new WP_Error( 'pka_sobretiempo', 'Revisa la autorización.', array( 'errores' => array( 'trabajador_id' => 'Ese trabajador no existe.' ) ) );
		}

		if ( Trabajadores::esPropio( $datos['trabajador_id'] ) ) {
			return new WP_Error( 'pka_propio', 'No puedes autorizarte horas extra a ti mismo: pídeselo a tu jefe o a RR.HH.' );
		}

		$errores = ValidacionPago::erroresSobretiempo( $datos, self::franjas( $datos['trabajador_id'], $datos['fecha'] ) );

		if ( $errores ) {
			return new WP_Error( 'pka_sobretiempo', 'Revisa la autorización.', array( 'errores' => $errores ) );
		}

		$wpdb->insert(
			Tablas::nombre( 'sobretiempo' ),
			$datos + array( 'estado' => 'vigente', 'autorizado_por' => get_current_user_id(), 'autorizado_en' => Peticion::ahoraUtc() )
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'sobretiempo.autorizar', 'trabajador', $datos['trabajador_id'], null, self::paraAuditoria( $datos ), $datos['motivo'] );

		return $id;
	}

	public static function anular( int $id, string $motivo ): true|WP_Error {
		global $wpdb;

		$tabla = Tablas::nombre( 'sobretiempo' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d AND estado = 'vigente'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return new WP_Error( 'pka_no_existe', 'Esa autorización no existe o ya está anulada.' );
		}

		if ( Trabajadores::esPropio( (int) $fila['trabajador_id'] ) ) {
			return new WP_Error( 'pka_propio', 'No puedes cambiar tus propias horas extra.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe por qué se anula.' );
		}

		$wpdb->update(
			$tabla,
			array( 'estado' => 'anulada', 'anulado_por' => get_current_user_id(), 'anulado_en' => Peticion::ahoraUtc(), 'motivo_anulacion' => mb_substr( $motivo, 0, 255 ) ),
			array( 'id' => $id )
		);

		Auditoria::registrar( 'sobretiempo.anular', 'trabajador', (int) $fila['trabajador_id'], self::paraAuditoria( $fila ), null, $motivo );

		return true;
	}

	/** @return array<string, mixed>|null */
	public static function buscar( int $id ): ?array {
		global $wpdb;

		$tabla = Tablas::nombre( 'sobretiempo' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $fila ? $fila : null;
	}

	/**
	 * Autorizaciones vigentes de un rango, listas para el cálculo.
	 *
	 * @param int[]|null $trabajadores
	 * @return array<int, array<string, AutorizacionSobretiempo[]>> trabajador => día => autorizaciones
	 */
	public static function delRango( ?array $trabajadores, string $desde, string $hasta ): array {
		global $wpdb;

		if ( is_array( $trabajadores ) && ! $trabajadores ) {
			return array();
		}

		$tabla = Tablas::nombre( 'sobretiempo' );
		$solo  = is_array( $trabajadores ) ? 'AND trabajador_id IN (' . implode( ',', array_map( 'intval', $trabajadores ) ) . ')' : '';
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE estado = 'vigente' AND fecha BETWEEN %s AND %s {$solo}", $desde, $hasta ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$zona  = Ajustes::zona();
		$todas = array();

		foreach ( $filas as $f ) {
			$todas[ (int) $f['trabajador_id'] ][ $f['fecha'] ][] = AutorizacionSobretiempo::deHoras( $f['fecha'], $f['desde'], $f['hasta'], $zona, $f['compensacion'], (int) $f['id'] );
		}

		return $todas;
	}

	/**
	 * @param array{trabajadores?: int[]|null, desde?: string, hasta?: string, estado?: string} $filtros
	 * @return array<int, array<string, mixed>>
	 */
	public static function listar( array $filtros = array() ): array {
		global $wpdb;

		$s     = Tablas::nombre( 'sobretiempo' );
		$t     = Tablas::nombre( 'trabajadores' );
		$where = $wpdb->prepare( 's.estado = %s', $filtros['estado'] ?? 'vigente' );

		if ( ! empty( $filtros['desde'] ) ) {
			$where .= $wpdb->prepare( ' AND s.fecha >= %s', $filtros['desde'] );
		}

		if ( ! empty( $filtros['hasta'] ) ) {
			$where .= $wpdb->prepare( ' AND s.fecha <= %s', $filtros['hasta'] );
		}

		if ( isset( $filtros['trabajadores'] ) && is_array( $filtros['trabajadores'] ) ) {
			if ( ! $filtros['trabajadores'] ) {
				return array();
			}

			$where .= ' AND s.trabajador_id IN (' . implode( ',', array_map( 'intval', $filtros['trabajadores'] ) ) . ')';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT s.*, t.nombres, t.apellidos FROM {$s} s JOIN {$t} t ON t.id = s.trabajador_id WHERE {$where} ORDER BY s.fecha DESC, s.desde LIMIT 300", ARRAY_A );
	}

	/** @return array<int, array{desde: string, hasta: string}> */
	private static function franjas( int $trabajadorId, string $fecha ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'sobretiempo' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT desde, hasta FROM {$tabla} WHERE trabajador_id = %d AND fecha = %s AND estado = 'vigente'", $trabajadorId, $fecha ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param array<string, mixed> $f */
	private static function paraAuditoria( array $f ): array {
		return array(
			'fecha'        => $f['fecha'],
			'franja'       => substr( (string) $f['desde'], 0, 5 ) . ' a ' . substr( (string) $f['hasta'], 0, 5 ),
			'compensacion' => AutorizacionSobretiempo::COMPENSACIONES[ $f['compensacion'] ] ?? $f['compensacion'],
		);
	}
}
