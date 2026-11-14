<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\PrePlanilla;

defined( 'ABSPATH' ) || exit;

/**
 * Junta lo que guarda WordPress (marcas, horarios, calendario, ausencias,
 * revisiones y sobretiempo) y calcula cada día con el núcleo.
 *
 * No guarda nada: la fuente de verdad son siempre las marcas y sus correcciones.
 */
final class Jornadas {
	/**
	 * @param array<int, array<string, mixed>> $trabajadores Filas de Trabajadores::listar().
	 * @return array<int, array<string, CalculoJornada>> trabajador => día => cálculo (solo días en que trabajaba ahí)
	 */
	public static function delRango( array $trabajadores, string $desde, string $hasta ): array {
		$ids = array_map( static fn( array $t ): int => (int) $t['id'], $trabajadores );

		if ( ! $ids ) {
			return array();
		}

		$zona       = Ajustes::zona();
		$reglas     = Ajustes::reglasPago();
		$marcas     = Marcas::delRango( $ids, $desde, $hasta );
		$ausencias  = Ausencias::delRango( $ids, $desde, $hasta );
		$sobre      = Sobretiempo::delRango( $ids, $desde, $hasta );
		$revisiones = self::revisiones( $ids, $desde, $hasta );
		$resultado  = array();

		foreach ( $trabajadores as $t ) {
			$id = (int) $t['id'];
			[ $inicio, $fin ] = self::periodoDe( $t, $desde, $hasta );

			for ( $d = new DateTimeImmutable( $inicio ); $d->format( 'Y-m-d' ) <= $fin; $d = $d->modify( '+1 day' ) ) {
				$dia = $d->format( 'Y-m-d' );

				$resultado[ $id ][ $dia ] = CalculoJornada::calcular(
					$marcas[ $id ][ $dia ] ?? array(),
					Horarios::delDia( (int) $t['horario_id'], $dia ),
					$dia,
					$zona,
					$reglas,
					$revisiones,
					$sobre[ $id ][ $dia ] ?? array(),
					$ausencias[ $id ][ $dia ] ?? null
				);
			}
		}

		return $resultado;
	}

	public static function delDia( array $trabajador, string $dia ): ?CalculoJornada {
		return self::delRango( array( $trabajador ), $dia, $dia )[ (int) $trabajador['id'] ][ $dia ] ?? null;
	}

	/**
	 * Pre-planilla de un mes (Y-m) para varios trabajadores. Solo cuenta días ya
	 * terminados: el día de hoy y los futuros no entran.
	 *
	 * @param array<int, array<string, mixed>> $trabajadores
	 * @return array<int, PrePlanilla>
	 */
	public static function prePlanilla( array $trabajadores, string $mes ): array {
		$desde = $mes . '-01';
		$hasta = min( ( new DateTimeImmutable( $desde ) )->format( 'Y-m-t' ), self::ayer() );

		if ( $hasta < $desde ) {
			return array();
		}

		$jornadas = self::delRango( $trabajadores, $desde, $hasta );
		$sueldos  = Sueldos::deTrabajadores( array_map( static fn( array $t ): int => (int) $t['id'], $trabajadores ) );
		$reglas   = Ajustes::reglasPago();
		$filas    = array();

		foreach ( $trabajadores as $t ) {
			$id           = (int) $t['id'];
			$filas[ $id ] = PrePlanilla::calcular( $jornadas[ $id ] ?? array(), $sueldos[ $id ] ?? array(), $reglas );
		}

		return $filas;
	}

	/** El día laboral anterior al actual. */
	public static function ayer(): string {
		$hoy = Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );

		return ( new DateTimeImmutable( $hoy ) )->modify( '-1 day' )->format( 'Y-m-d' );
	}

	/**
	 * Días del rango en que la persona trabajaba aquí (desde su ingreso o su alta en el sistema, hasta su cese).
	 *
	 * @param array<string, mixed> $t
	 * @return array{0: string, 1: string}
	 */
	public static function periodoDe( array $t, string $desde, string $hasta ): array {
		$alta   = get_date_from_gmt( (string) $t['creado_en'], 'Y-m-d' );
		$inicio = max( $desde, (string) ( $t['fecha_ingreso'] ?? '' ), $alta );
		$fin    = empty( $t['fecha_cese'] ) ? $hasta : min( $hasta, (string) $t['fecha_cese'] );

		return array( $inicio, $fin );
	}

	/**
	 * Decisiones de los jefes sobre las salidas del rango. Una salida que no pasó
	 * por revisión (la registró un jefe a mano, por ejemplo) cuenta como aprobada.
	 *
	 * @param int[] $ids
	 * @return array<int, string> marca_id => "aprobada" | "rechazada"
	 */
	private static function revisiones( array $ids, string $desde, string $hasta ): array {
		global $wpdb;

		$m = Tablas::nombre( 'marcas' );
		$r = Tablas::nombre( 'revisiones' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.id, m.requiere_revision, r.decision FROM {$m} m LEFT JOIN {$r} r ON r.marca_id = m.id
				WHERE m.tipo = 'salida' AND m.dia_laboral BETWEEN %s AND %s AND m.trabajador_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')',
				$desde,
				$hasta
			),
			ARRAY_A
		);

		$decisiones = array();

		foreach ( $filas as $f ) {
			if ( null !== $f['decision'] ) {
				$decisiones[ (int) $f['id'] ] = (string) $f['decision'];
			} elseif ( ! (int) $f['requiere_revision'] ) {
				$decisiones[ (int) $f['id'] ] = Revisiones::APROBADA;
			}
		}

		return $decisiones;
	}
}
