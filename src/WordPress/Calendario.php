<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\FeriadosPeru;
use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Feriados y días no laborables de la empresa. Un día del calendario no es
 * laborable para nadie: no hay ausentes, faltas ni tardanzas ese día.
 */
final class Calendario {
	public const TIPOS = array(
		'feriado'      => 'Feriado',
		'no_laborable' => 'Día no laborable de la empresa',
	);

	/** @var array<string, array<string, string>>|null fecha => fila, cacheado por petición. */
	private static ?array $cache = null;

	/** @return array{nombre: string, tipo: string}|null */
	public static function delDia( string $dia ): ?array {
		$todos = self::todos();

		return $todos[ $dia ] ?? null;
	}

	/** Texto para mostrar: "Feriado: Navidad". */
	public static function descripcion( string $dia ): string {
		$d = self::delDia( $dia );

		return $d ? ( self::TIPOS[ $d['tipo'] ] ?? 'Feriado' ) . ': ' . $d['nombre'] : '';
	}

	/** @return array<string, array<string, string>> fecha => fila del año. */
	public static function delAnio( int $anio ): array {
		return array_filter( self::todos(), static fn( string $fecha ): bool => str_starts_with( $fecha, $anio . '-' ), ARRAY_FILTER_USE_KEY );
	}

	public static function agregar( string $fecha, string $nombre, string $tipo ): true|WP_Error {
		global $wpdb;

		$nombre = mb_substr( trim( $nombre ), 0, 120 );

		if ( ! ValidacionTrabajador::esFecha( $fecha ) ) {
			return new WP_Error( 'pka_fecha', 'La fecha no es válida.' );
		}

		if ( '' === $nombre ) {
			return new WP_Error( 'pka_nombre', 'Escribe el nombre del feriado.' );
		}

		if ( ! isset( self::TIPOS[ $tipo ] ) ) {
			return new WP_Error( 'pka_tipo', 'Tipo de día desconocido.' );
		}

		if ( null !== self::delDia( $fecha ) ) {
			return new WP_Error( 'pka_existe', 'Esa fecha ya está en el calendario.' );
		}

		$wpdb->insert(
			Tablas::nombre( 'calendario' ),
			array( 'fecha' => $fecha, 'nombre' => $nombre, 'tipo' => $tipo, 'creado_por' => get_current_user_id(), 'creado_en' => Peticion::ahoraUtc() )
		);

		self::$cache = null;
		Horarios::limpiarCache();
		Auditoria::registrar( 'calendario.agregar', 'calendario', null, null, array( 'fecha' => $fecha, 'nombre' => $nombre, 'tipo' => $tipo ) );

		Tardanzas::resincronizar( null, $fecha, $fecha );

		return true;
	}

	/** Cambia el nombre o el tipo de un día ya cargado (maqueta G9, "Editar"). */
	public static function editar( string $fecha, string $nombre, string $tipo ): true|WP_Error {
		global $wpdb;

		$antes  = self::delDia( $fecha );
		$nombre = mb_substr( trim( $nombre ), 0, 120 );

		if ( null === $antes ) {
			return new WP_Error( 'pka_no_existe', 'Esa fecha no está en el calendario.' );
		}

		if ( '' === $nombre ) {
			return new WP_Error( 'pka_nombre', 'Escribe el nombre del feriado.' );
		}

		if ( ! isset( self::TIPOS[ $tipo ] ) ) {
			return new WP_Error( 'pka_tipo', 'Tipo de día desconocido.' );
		}

		if ( $antes['nombre'] === $nombre && $antes['tipo'] === $tipo ) {
			return true;
		}

		$wpdb->update( Tablas::nombre( 'calendario' ), array( 'nombre' => $nombre, 'tipo' => $tipo ), array( 'fecha' => $fecha ) );

		self::$cache = null;
		Horarios::limpiarCache();
		Auditoria::registrar( 'calendario.editar', 'calendario', null, array( 'fecha' => $fecha ) + $antes, array( 'fecha' => $fecha, 'nombre' => $nombre, 'tipo' => $tipo ) );

		Tardanzas::resincronizar( null, $fecha, $fecha );

		return true;
	}

	public static function quitar( string $fecha ): true|WP_Error {
		global $wpdb;

		$antes = self::delDia( $fecha );

		if ( null === $antes ) {
			return new WP_Error( 'pka_no_existe', 'Esa fecha no está en el calendario.' );
		}

		$wpdb->delete( Tablas::nombre( 'calendario' ), array( 'fecha' => $fecha ) );

		self::$cache = null;
		Horarios::limpiarCache();
		Auditoria::registrar( 'calendario.quitar', 'calendario', null, array( 'fecha' => $fecha ) + $antes, null );

		Tardanzas::resincronizar( null, $fecha, $fecha );

		return true;
	}

	/**
	 * Carga los feriados nacionales sugeridos de un año, sin tocar las fechas que ya existen.
	 *
	 * @return int Cantidad de fechas agregadas.
	 */
	public static function cargarFeriadosPeru( int $anio ): int {
		$agregados = 0;

		foreach ( FeriadosPeru::del( $anio ) as $fecha => $nombre ) {
			if ( null === self::delDia( $fecha ) && true === self::agregar( $fecha, $nombre, 'feriado' ) ) {
				++$agregados;
			}
		}

		return $agregados;
	}

	/** @return array<string, array<string, string>> */
	private static function todos(): array {
		global $wpdb;

		if ( null === self::$cache ) {
			$tabla       = Tablas::nombre( 'calendario' );
			$filas       = $wpdb->get_results( "SELECT fecha, nombre, tipo FROM {$tabla} ORDER BY fecha", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$cache = array_column( $filas, null, 'fecha' );
		}

		return self::$cache;
	}
}
