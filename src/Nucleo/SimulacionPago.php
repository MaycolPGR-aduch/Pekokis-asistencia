<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Simulador de pagos: el mismo cálculo de la planilla (honorario + horas
 * adicionales × tarifa) con valores que no se guardan. Montos en céntimos,
 * horas en minutos.
 *
 * - libre(): sin datos reales (pestaña "Desde cero").
 * - conJornadas(): con las horas reales de un mes y otro honorario y otras
 *   tarifas, más horas sumadas o restadas por tipo ("¿Y si…?" y "Todo el
 *   equipo"). Usa PrePlanilla::calcular, así que redondea igual que la planilla.
 */
final class SimulacionPago {
	public const TIPOS = array( 'extra', 'sabado', 'domingo', 'feriado' );

	/**
	 * @param array<string, int> $tarifas Céntimos por hora, por tipo.
	 * @param array<string, int> $minutos Minutos por tipo.
	 * @return array{minutos: array<string, int>, montos: array<string, int>}
	 */
	public static function libre( int $honorario, array $tarifas, array $minutos ): array {
		$r = self::vacio();

		$r['montos']['honorario'] = max( 0, $honorario );

		foreach ( self::TIPOS as $tipo ) {
			$min                     = max( 0, (int) ( $minutos[ $tipo ] ?? 0 ) );
			$r['minutos'][ $tipo ]   = $min;
			$r['montos'][ $tipo ]    = (int) round( $min * max( 0, (int) ( $tarifas[ $tipo ] ?? 0 ) ) / 60 );
		}

		return self::conTotal( $r );
	}

	/**
	 * @param array<string, CalculoJornada> $jornadas Días del mes ya calculados.
	 * @param array<string, int>            $tarifas  Céntimos por hora, por tipo.
	 * @param array<string, int>            $ajuste   Minutos a sumar (o restar) por tipo.
	 * @return array{minutos: array<string, int>, montos: array<string, int>}
	 */
	public static function conJornadas( array $jornadas, int $honorario, array $tarifas, array $ajuste = array() ): array {
		$sueldo = new Sueldo(
			max( 0, $honorario ),
			max( 0, (int) ( $tarifas['extra'] ?? 0 ) ),
			max( 0, (int) ( $tarifas['sabado'] ?? 0 ) ),
			max( 0, (int) ( $tarifas['domingo'] ?? 0 ) ),
			max( 0, (int) ( $tarifas['feriado'] ?? 0 ) ),
			'0000-01-01'
		);
		$fila = PrePlanilla::calcular( $jornadas, array( $sueldo ) );
		$r    = self::vacio();

		// El honorario cuenta aunque el mes todavía no tenga días calculados.
		$r['montos']['honorario'] = $sueldo->honorarioCentimos;

		foreach ( self::TIPOS as $tipo ) {
			$real  = (int) $fila->minutos[ $tipo ];
			$nuevo = max( 0, $real + (int) ( $ajuste[ $tipo ] ?? 0 ) );
			$monto = (int) $fila->montos[ $tipo ] + (int) round( ( $nuevo - $real ) * $sueldo->tarifa( $tipo ) / 60 );

			$r['minutos'][ $tipo ] = $nuevo;
			$r['montos'][ $tipo ]  = 0 === $nuevo ? 0 : max( 0, $monto );
		}

		return self::conTotal( $r );
	}

	/**
	 * Lo real de una pre-planilla en el mismo formato, para comparar.
	 *
	 * @return array{minutos: array<string, int>, montos: array<string, int>}
	 */
	public static function deReal( PrePlanilla $fila ): array {
		$r = self::vacio();

		$r['montos']['honorario'] = (int) $fila->montos['honorario'];

		foreach ( self::TIPOS as $tipo ) {
			$r['minutos'][ $tipo ] = (int) $fila->minutos[ $tipo ];
			$r['montos'][ $tipo ]  = (int) $fila->montos[ $tipo ];
		}

		return self::conTotal( $r );
	}

	/** @return array{minutos: array<string, int>, montos: array<string, int>} */
	private static function vacio(): array {
		return array(
			'minutos' => array_fill_keys( self::TIPOS, 0 ),
			'montos'  => array( 'honorario' => 0 ) + array_fill_keys( self::TIPOS, 0 ) + array( 'total' => 0 ),
		);
	}

	/**
	 * @param array{minutos: array<string, int>, montos: array<string, int>} $r
	 * @return array{minutos: array<string, int>, montos: array<string, int>}
	 */
	private static function conTotal( array $r ): array {
		$r['montos']['total'] = $r['montos']['honorario'] + array_sum( array_intersect_key( $r['montos'], array_flip( self::TIPOS ) ) );

		return $r;
	}
}
