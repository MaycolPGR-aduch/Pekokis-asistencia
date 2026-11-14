<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\ValidacionAusencia;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ausencias justificadas de días completos. No se borran: se anulan con motivo.
 *
 * El sustento (certificado médico, papeleta de vacaciones) lo guarda RR.HH.
 * fuera del sistema: un certificado médico es un dato de salud y no debe quedar
 * en una carpeta pública del sitio. Aquí solo se anota su referencia.
 */
final class Ausencias {
	/** @return int|WP_Error Id de la ausencia. */
	public static function crear( array $entrada ): int|WP_Error {
		global $wpdb;

		$datos      = ValidacionAusencia::normalizar( $entrada );
		$trabajador = Trabajadores::buscar( $datos['trabajador_id'] );

		if ( null === $trabajador ) {
			return new WP_Error( 'pka_ausencia', 'Revisa la ausencia.', array( 'errores' => array( 'trabajador_id' => 'Ese trabajador no existe.' ) ) );
		}

		$errores = ValidacionAusencia::errores( $datos, self::vigentesDe( $datos['trabajador_id'] ) );

		if ( $errores ) {
			return new WP_Error( 'pka_ausencia', 'Revisa la ausencia.', array( 'errores' => $errores ) );
		}

		$wpdb->insert( Tablas::nombre( 'ausencias' ), $datos + array( 'estado' => 'vigente', 'creado_por' => get_current_user_id(), 'creado_en' => Peticion::ahoraUtc() ) );
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'ausencia.crear', 'ausencia', $id, null, array( 'tipo' => ValidacionAusencia::TIPOS[ $datos['tipo'] ], 'desde' => $datos['desde'], 'hasta' => $datos['hasta'], 'nota' => $datos['nota'] ) );

		return $id;
	}

	public static function anular( int $id, string $motivo ): true|WP_Error {
		global $wpdb;

		$tabla = Tablas::nombre( 'ausencias' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d AND estado = 'vigente'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return new WP_Error( 'pka_no_existe', 'Esa ausencia no existe o ya está anulada.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe por qué se anula.' );
		}

		$wpdb->update(
			$tabla,
			array( 'estado' => 'anulada', 'anulado_por' => get_current_user_id(), 'anulado_en' => Peticion::ahoraUtc(), 'motivo_anulacion' => mb_substr( $motivo, 0, 255 ) ),
			array( 'id' => $id )
		);

		Auditoria::registrar( 'ausencia.anular', 'ausencia', $id, array( 'tipo' => ValidacionAusencia::TIPOS[ $fila['tipo'] ] ?? $fila['tipo'], 'desde' => $fila['desde'], 'hasta' => $fila['hasta'] ), null, $motivo );

		return true;
	}

	/** Etiqueta de la ausencia que cubre ese día ("Vacaciones"), o null. */
	public static function delDia( int $trabajadorId, string $dia ): ?string {
		return self::delRango( array( $trabajadorId ), $dia, $dia )[ $trabajadorId ][ $dia ] ?? null;
	}

	/**
	 * Ausencias vigentes en un rango, expandidas por día.
	 *
	 * @param int[]|null $trabajadores
	 * @return array<int, array<string, string>> trabajador => día => etiqueta
	 */
	public static function delRango( ?array $trabajadores, string $desde, string $hasta ): array {
		global $wpdb;

		if ( is_array( $trabajadores ) && ! $trabajadores ) {
			return array();
		}

		$tabla = Tablas::nombre( 'ausencias' );
		$solo  = is_array( $trabajadores ) ? 'AND trabajador_id IN (' . implode( ',', array_map( 'intval', $trabajadores ) ) . ')' : '';
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT trabajador_id, tipo, desde, hasta FROM {$tabla} WHERE estado = 'vigente' AND desde <= %s AND hasta >= %s {$solo}", $hasta, $desde ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$porDia = array();

		foreach ( $filas as $f ) {
			$inicio = max( $f['desde'], $desde );
			$fin    = min( $f['hasta'], $hasta );

			for ( $d = new DateTimeImmutable( $inicio ); $d->format( 'Y-m-d' ) <= $fin; $d = $d->modify( '+1 day' ) ) {
				$porDia[ (int) $f['trabajador_id'] ][ $d->format( 'Y-m-d' ) ] = ValidacionAusencia::TIPOS[ $f['tipo'] ] ?? $f['tipo'];
			}
		}

		return $porDia;
	}

	/**
	 * @param array{trabajador?: int, estado?: string} $filtros
	 * @return array<int, array<string, mixed>>
	 */
	public static function listar( array $filtros = array() ): array {
		global $wpdb;

		$a     = Tablas::nombre( 'ausencias' );
		$t     = Tablas::nombre( 'trabajadores' );
		$where = $wpdb->prepare( 'a.estado = %s', $filtros['estado'] ?? 'vigente' );

		if ( ! empty( $filtros['trabajador'] ) ) {
			$where .= $wpdb->prepare( ' AND a.trabajador_id = %d', (int) $filtros['trabajador'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT a.*, t.nombres, t.apellidos FROM {$a} a JOIN {$t} t ON t.id = a.trabajador_id WHERE {$where} ORDER BY a.desde DESC LIMIT 300", ARRAY_A );
	}

	/** @return array<int, array{desde: string, hasta: string}> */
	private static function vigentesDe( int $trabajadorId ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'ausencias' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT desde, hasta FROM {$tabla} WHERE trabajador_id = %d AND estado = 'vigente'", $trabajadorId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
