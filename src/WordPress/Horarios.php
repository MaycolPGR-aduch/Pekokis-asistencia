<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;

defined( 'ABSPATH' ) || exit;

/**
 * Horarios guardados en la base de datos.
 */
final class Horarios {
	/** @var array<string, HorarioDelDia> */
	private static array $cache = array();

	public static function delDia( int $horarioId, string $dia ): HorarioDelDia {
		global $wpdb;

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
}
