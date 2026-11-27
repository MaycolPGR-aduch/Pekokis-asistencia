<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\Nucleo\ValidacionPago;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Historial de sueldos. Solo lo ve quien tiene pka_ver_sueldos (RR.HH. y administradores).
 *
 * Un sueldo nuevo cierra el anterior el día antes de que empiece a regir. Un
 * error se anula con motivo (y el anterior vuelve a quedar abierto); nunca se edita.
 */
final class Sueldos {
	/** @return array<int, array<string, mixed>> Del más nuevo al más antiguo, con anulados. */
	public static function historial( int $trabajadorId ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'sueldos' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE trabajador_id = %d ORDER BY estado = 'vigente' DESC, vigente_desde DESC, id DESC", $trabajadorId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sueldos vigentes de varios trabajadores, para el cálculo.
	 *
	 * @param int[]|null $trabajadores
	 * @return array<int, Sueldo[]>
	 */
	public static function deTrabajadores( ?array $trabajadores ): array {
		global $wpdb;

		if ( is_array( $trabajadores ) && ! $trabajadores ) {
			return array();
		}

		$tabla = Tablas::nombre( 'sueldos' );
		$solo  = is_array( $trabajadores ) ? 'AND trabajador_id IN (' . implode( ',', array_map( 'intval', $trabajadores ) ) . ')' : '';
		$filas = $wpdb->get_results( "SELECT * FROM {$tabla} WHERE estado = 'vigente' {$solo}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$todos = array();

		foreach ( $filas as $f ) {
			$todos[ (int) $f['trabajador_id'] ][] = self::aSueldo( $f );
		}

		return $todos;
	}

	public static function vigente( int $trabajadorId, ?string $dia = null ): ?Sueldo {
		$dia ??= wp_date( 'Y-m-d', null, Ajustes::zona() );

		return Sueldo::vigenteEn( self::deTrabajadores( array( $trabajadorId ) )[ $trabajadorId ] ?? array(), $dia );
	}

	/** @return int|WP_Error */
	public static function agregar( int $trabajadorId, array $entrada ): int|WP_Error {
		global $wpdb;

		if ( null === Trabajadores::buscar( $trabajadorId ) ) {
			return new WP_Error( 'pka_no_existe', 'Ese trabajador no existe.' );
		}

		if ( Trabajadores::esPropio( $trabajadorId ) ) {
			return new WP_Error( 'pka_propio', 'No puedes registrar tu propio honorario: lo hace otra persona de gerencia o un administrador.' );
		}

		$datos  = ValidacionPago::normalizarSueldo( $entrada );
		$actual = self::abierto( $trabajadorId );
		$errores = ValidacionPago::erroresSueldo( $datos, $actual['vigente_desde'] ?? null );

		if ( $errores ) {
			return new WP_Error( 'pka_sueldo', 'Revisa el honorario y las tarifas.', array( 'errores' => $errores ) );
		}

		$tabla = Tablas::nombre( 'sueldos' );

		if ( $actual ) {
			$cierre = ( new DateTimeImmutable( $datos['vigente_desde'] ) )->modify( '-1 day' )->format( 'Y-m-d' );
			$wpdb->update( $tabla, array( 'vigente_hasta' => $cierre ), array( 'id' => (int) $actual['id'] ) );
		}

		$wpdb->insert(
			$tabla,
			array(
				'trabajador_id'  => $trabajadorId,
				'modalidad'      => Sueldo::HORAS === $datos['modo'] ? 'horas' : 'honorario',
				'opciones'       => implode( ',', $datos['aparte'] ),
				'monto_centimos' => $datos['honorario'],
				'tarifa_extra'   => $datos['tarifas']['extra'],
				'tarifa_sabado'  => $datos['tarifas']['extra'], // Desde la 0.22.0 el sábado con semana completa usa la tarifa extra.
				'tarifa_domingo' => $datos['tarifas']['domingo'],
				'tarifa_feriado' => $datos['tarifas']['feriado'],
				'vigente_desde'  => $datos['vigente_desde'],
				'nota'           => $datos['nota'],
				'estado'         => 'vigente',
				'creado_por'     => get_current_user_id(),
				'creado_en'      => Peticion::ahoraUtc(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar(
			'sueldo.agregar',
			'trabajador',
			$trabajadorId,
			$actual ? self::paraAuditoria( $actual ) : null,
			self::paraAuditoria(
				array(
					'modalidad'      => Sueldo::HORAS === $datos['modo'] ? 'horas' : 'honorario',
					'opciones'       => implode( ',', $datos['aparte'] ),
					'monto_centimos' => $datos['honorario'],
					'tarifa_extra'   => $datos['tarifas']['extra'],
					'tarifa_domingo' => $datos['tarifas']['domingo'],
					'tarifa_feriado' => $datos['tarifas']['feriado'],
					'vigente_desde'  => $datos['vigente_desde'],
				)
			),
			$datos['nota']
		);

		return $id;
	}

	public static function anular( int $id, string $motivo ): true|WP_Error {
		global $wpdb;

		$tabla = Tablas::nombre( 'sueldos' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d AND estado = 'vigente'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return new WP_Error( 'pka_no_existe', 'Ese registro no existe o ya está anulado.' );
		}

		if ( Trabajadores::esPropio( (int) $fila['trabajador_id'] ) ) {
			return new WP_Error( 'pka_propio', 'No puedes cambiar tu propio honorario.' );
		}

		if ( null !== $fila['vigente_hasta'] ) {
			return new WP_Error( 'pka_sueldo', 'Solo se puede anular el último registro.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe por qué se anula.' );
		}

		$wpdb->update(
			$tabla,
			array( 'estado' => 'anulado', 'anulado_por' => get_current_user_id(), 'anulado_en' => Peticion::ahoraUtc(), 'motivo_anulacion' => mb_substr( $motivo, 0, 255 ) ),
			array( 'id' => $id )
		);

		// El sueldo anterior vuelve a quedar abierto.
		$anterior = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE trabajador_id = %d AND estado = 'vigente' ORDER BY vigente_desde DESC LIMIT 1", (int) $fila['trabajador_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $anterior ) {
			$wpdb->update( $tabla, array( 'vigente_hasta' => null ), array( 'id' => (int) $anterior['id'] ) );
		}

		Auditoria::registrar( 'sueldo.anular', 'trabajador', (int) $fila['trabajador_id'], self::paraAuditoria( $fila ), null, $motivo );

		return true;
	}

	/** @param array<string, mixed> $fila */
	public static function aSueldo( array $fila ): Sueldo {
		return new Sueldo(
			(int) $fila['monto_centimos'],
			(int) ( $fila['tarifa_extra'] ?? 0 ),
			(int) ( $fila['tarifa_sabado'] ?? 0 ),
			(int) ( $fila['tarifa_domingo'] ?? 0 ),
			(int) ( $fila['tarifa_feriado'] ?? 0 ),
			(string) $fila['vigente_desde'],
			! empty( $fila['vigente_hasta'] ) ? (string) $fila['vigente_hasta'] : null,
			(int) ( $fila['id'] ?? 0 ),
			'horas' === ( $fila['modalidad'] ?? '' ) ? Sueldo::HORAS : Sueldo::FIJO,
			'horas' === ( $fila['modalidad'] ?? '' ) ? array_values( array_filter( explode( ',', (string) ( $fila['opciones'] ?? '' ) ) ) ) : array()
		);
	}

	/** "S/ 1,800.00 al mes (valor hora S/ 9.38) · extra S/ 12.00 · domingo S/ 20.00 · feriado S/ 24.00". */
	public static function etiqueta( array $fila ): string {
		$s      = self::aSueldo( $fila + array( 'id' => 0, 'vigente_hasta' => null ) );
		$tarifa = ' · extra ' . Sueldo::soles( $s->tarifaExtra ) . ' · domingo ' . Sueldo::soles( $s->tarifaDomingo ) . ' · feriado ' . Sueldo::soles( $s->tarifaFeriado );

		if ( $s->porHoras() ) {
			$aparte = array_map( static fn( string $k ): string => array( 'extra' => 'extra', 'madrugada' => 'madrugada', 'domingo' => 'domingo y feriado' )[ $k ] ?? $k, $s->aparte );

			return 'Por horas: ' . Sueldo::soles( $s->valorHora() ) . ' la hora (' . Sueldo::soles( $s->honorarioCentimos ) . ' ÷ ' . Sueldo::HORAS_MES . ')' . ( $aparte ? ' · aparte: ' . implode( ', ', $aparte ) . $tarifa : ' · todo a valor hora' );
		}

		return Sueldo::soles( $s->honorarioCentimos ) . ' al mes (valor hora ' . Sueldo::soles( $s->valorHora() ) . ')' . $tarifa;
	}

	/** @return array<string, mixed>|null */
	private static function abierto( int $trabajadorId ): ?array {
		global $wpdb;

		$tabla = Tablas::nombre( 'sueldos' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE trabajador_id = %d AND estado = 'vigente' AND vigente_hasta IS NULL ORDER BY vigente_desde DESC LIMIT 1", $trabajadorId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $fila ? $fila : null;
	}

	/** @param array<string, mixed> $fila */
	private static function paraAuditoria( array $fila ): array {
		return array(
			'honorario'     => self::etiqueta( $fila ),
			'vigente_desde' => $fila['vigente_desde'],
		);
	}
}
