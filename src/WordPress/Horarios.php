<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\ValidacionHorario;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Horarios guardados en la base de datos.
 */
final class Horarios {
	/** @var array<string, HorarioDelDia> */
	private static array $cache = array();

	public static function delDia( int $horarioId, string $dia ): HorarioDelDia {
		global $wpdb;

		// Un feriado o día no laborable del calendario manda sobre el horario.
		$feriado = Calendario::descripcion( $dia );

		if ( '' !== $feriado ) {
			return HorarioDelDia::libre( $feriado );
		}

		$diaSemana = (int) ( new DateTimeImmutable( $dia ) )->format( 'N' );
		$clave     = $horarioId . '|' . $diaSemana;

		if ( isset( self::$cache[ $clave ] ) ) {
			return self::$cache[ $clave ];
		}

		$h    = Tablas::nombre( 'horarios' );
		$d    = Tablas::nombre( 'horario_dias' );
		$fila = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT h.tolerancia_min, h.refrigerio_min, d.laborable, d.entrada, d.salida
				FROM {$h} h JOIN {$d} d ON d.horario_id = h.id WHERE h.id = %d AND d.dia_semana = %d",
				$horarioId,
				$diaSemana
			),
			ARRAY_A
		);

		self::$cache[ $clave ] = $fila
			? new HorarioDelDia( (bool) $fila['laborable'], $fila['entrada'], $fila['salida'], (int) $fila['tolerancia_min'], (int) $fila['refrigerio_min'] )
			: HorarioDelDia::libre();

		return self::$cache[ $clave ];
	}

	public static function limpiarCache(): void {
		self::$cache = array();
	}

	/** @return array<int, array<string, mixed>> Horarios con la cantidad de trabajadores activos que los usan. */
	public static function listar(): array {
		global $wpdb;

		$h = Tablas::nombre( 'horarios' );
		$t = Tablas::nombre( 'trabajadores' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT h.*, ( SELECT COUNT(*) FROM {$t} t WHERE t.horario_id = h.id AND t.estado = 'activo' ) AS trabajadores
			FROM {$h} h ORDER BY h.activo DESC, h.nombre",
			ARRAY_A
		);
	}

	/**
	 * Horario con sus 7 días, en el formato de ValidacionHorario.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function completo( int $id ): ?array {
		global $wpdb;

		$h    = Tablas::nombre( 'horarios' );
		$d    = Tablas::nombre( 'horario_dias' );
		$fila = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$h} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $fila ) {
			return null;
		}

		$dias = array();

		foreach ( array_keys( ValidacionHorario::DIAS ) as $n ) {
			$dias[ $n ] = array( 'laborable' => false, 'entrada' => null, 'salida' => null );
		}

		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$d} WHERE horario_id = %d", $id ), ARRAY_A ) as $dia ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$dias[ (int) $dia['dia_semana'] ] = array(
				'laborable' => (bool) $dia['laborable'],
				'entrada'   => $dia['entrada'],
				'salida'    => $dia['salida'],
			);
		}

		return array(
			'id'             => (int) $fila['id'],
			'nombre'         => $fila['nombre'],
			'tolerancia_min' => (int) $fila['tolerancia_min'],
			'refrigerio_min' => (int) $fila['refrigerio_min'],
			'activo'         => (bool) $fila['activo'],
			'dias'           => $dias,
		);
	}

	/**
	 * Crea o actualiza un horario. Los cambios quedan en el historial con un resumen legible.
	 *
	 * @param array<string, mixed> $entrada
	 * @return int|WP_Error
	 */
	public static function guardar( array $entrada, ?int $id, string $motivo = '' ): int|WP_Error {
		global $wpdb;

		$datos   = ValidacionHorario::normalizar( $entrada );
		$errores = ValidacionHorario::errores( $datos );

		if ( $errores ) {
			return new WP_Error( 'pka_horario', 'Revisa el horario.', array( 'errores' => $errores ) );
		}

		$antes = null;

		if ( null !== $id ) {
			$antes = self::completo( $id );

			if ( null === $antes ) {
				return new WP_Error( 'pka_no_existe', 'Ese horario no existe.' );
			}

			if ( '' === trim( $motivo ) ) {
				return new WP_Error( 'pka_motivo', 'Escribe el motivo del cambio: afecta el cálculo de tardanzas.', array( 'errores' => array( 'motivo' => 'Escribe el motivo del cambio.' ) ) );
			}
		}

		$h      = Tablas::nombre( 'horarios' );
		$d      = Tablas::nombre( 'horario_dias' );
		$cabeza = array( 'nombre' => $datos['nombre'], 'tolerancia_min' => $datos['tolerancia_min'], 'refrigerio_min' => $datos['refrigerio_min'] );

		if ( null === $id ) {
			$wpdb->insert( $h, $cabeza + array( 'activo' => 1, 'creado_en' => Peticion::ahoraUtc() ) );
			$id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update( $h, $cabeza, array( 'id' => $id ) );
		}

		foreach ( $datos['dias'] as $n => $dia ) {
			$wpdb->replace(
				$d,
				array( 'horario_id' => $id, 'dia_semana' => $n, 'laborable' => (int) $dia['laborable'], 'entrada' => $dia['entrada'], 'salida' => $dia['salida'] ),
				array( '%d', '%d', '%d', '%s', '%s' )
			);
		}

		self::$cache = array();

		$resumenAntes = $antes ? self::resumen( $antes ) : null;
		$resumen      = self::resumen( $datos );

		if ( null === $antes ) {
			Auditoria::registrar( 'horario.crear', 'horario', $id, null, $resumen, $motivo );
		} else {
			[ $a, $dd ] = Auditoria::diferencias( $resumenAntes, $resumen );

			if ( $dd ) {
				Auditoria::registrar( 'horario.editar', 'horario', $id, $a, $dd, $motivo );
			}
		}

		return $id;
	}

	/** Desactiva un horario que ya nadie usa. */
	public static function cambiarEstado( int $id, bool $activo ): true|WP_Error {
		global $wpdb;

		$fila = array_values( array_filter( self::listar(), static fn( array $h ): bool => (int) $h['id'] === $id ) )[0] ?? null;

		if ( null === $fila ) {
			return new WP_Error( 'pka_no_existe', 'Ese horario no existe.' );
		}

		if ( ! $activo && (int) $fila['trabajadores'] > 0 ) {
			return new WP_Error( 'pka_en_uso', 'No se puede desactivar: lo usan ' . (int) $fila['trabajadores'] . ' trabajador(es). Asígnales otro horario primero.' );
		}

		$wpdb->update( Tablas::nombre( 'horarios' ), array( 'activo' => (int) $activo ), array( 'id' => $id ) );
		Auditoria::registrar( $activo ? 'horario.activar' : 'horario.desactivar', 'horario', $id );

		return true;
	}

	/**
	 * Resumen plano para el historial: "Lunes" => "09:00–19:30", "Sábado" => "libre"…
	 *
	 * @param array<string, mixed> $h
	 * @return array<string, mixed>
	 */
	private static function resumen( array $h ): array {
		$r = array( 'nombre' => $h['nombre'], 'tolerancia_min' => $h['tolerancia_min'], 'refrigerio_min' => $h['refrigerio_min'] );

		foreach ( ValidacionHorario::DIAS as $n => $nombreDia ) {
			$dia             = $h['dias'][ $n ];
			$r[ $nombreDia ] = $dia['laborable'] ? substr( (string) $dia['entrada'], 0, 5 ) . '–' . substr( (string) $dia['salida'], 0, 5 ) : 'libre';
		}

		return $r;
	}
}
