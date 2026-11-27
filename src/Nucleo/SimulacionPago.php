<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Simulador de pagos: el mismo cálculo de la planilla con valores que no se
 * guardan. Montos en céntimos, horas en minutos.
 *
 * - libre(): sin datos reales (pestaña "Desde cero"), sueldo fijo.
 * - conJornadas(): con las horas reales de un mes y otro honorario y otras
 *   tarifas, más horas sumadas o restadas por tipo ("¿Y si…?" y "Todo el
 *   equipo"). Usa PrePlanilla::calcular, así que redondea igual que la planilla.
 *
 * Tipos de hora: horas (solo quien cobra por horas), extra, sábado con la
 * semana completa (tarifa extra), sábado sin completarla (valor hora),
 * domingo, feriado y madrugada (valor hora × multiplicador).
 */
final class SimulacionPago {
	public const TIPOS = array( 'horas', 'extra', 'sabado_extra', 'sabado_normal', 'domingo', 'feriado', 'madrugada' );

	/**
	 * @param array<string, int> $tarifas Céntimos por hora: extra, domingo, feriado.
	 * @param array<string, int> $minutos Minutos por tipo.
	 * @return array{minutos: array<string, int>, montos: array<string, int>}
	 */
	public static function libre( int $honorario, array $tarifas, array $minutos, float $multiplicador = 1.5, int $descuentos = 0 ): array {
		$r      = self::vacio();
		$sueldo = self::sueldo( $honorario, $tarifas );

		$r['montos']['honorario']  = $sueldo->honorarioCentimos;
		$r['montos']['descuentos'] = max( 0, $descuentos );

		foreach ( self::TIPOS as $tipo ) {
			if ( 'horas' === $tipo ) {
				continue;
			}

			$min                   = max( 0, (int) ( $minutos[ $tipo ] ?? 0 ) );
			$r['minutos'][ $tipo ] = $min;
			$r['montos'][ $tipo ]  = (int) round( $min * self::precio( $sueldo, $tipo, $multiplicador ) / 60 );
		}

		return self::conTotal( $r );
	}

	/**
	 * @param array<string, CalculoJornada> $jornadas Días del mes ya calculados.
	 * @param array<string, int>            $tarifas  Céntimos por hora: extra, domingo, feriado.
	 * @param array<string, int>            $ajuste   Minutos a sumar (o restar) por tipo.
	 * @param array<string, array{esperados: int, trabajados: int, completa: bool}>|null $semanas
	 * @param string[]                      $aparte   Modo por horas: lo que va con su tarifa.
	 * @return array{minutos: array<string, int>, montos: array<string, int>}
	 */
	public static function conJornadas( array $jornadas, int $honorario, array $tarifas, array $ajuste = array(), ?ReglasPago $reglas = null, ?array $semanas = null, int $descuentos = 0, string $modo = Sueldo::FIJO, array $aparte = array() ): array {
		$reglas ??= new ReglasPago();
		$sueldo   = self::sueldo( $honorario, $tarifas, $modo, $aparte );
		$fila     = PrePlanilla::calcular( $jornadas, array( $sueldo ), $reglas, $semanas, $descuentos );
		$r        = self::deReal( $fila );

		// El honorario cuenta aunque el mes todavía no tenga días calculados.
		$r['montos']['honorario'] = $sueldo->porHoras() ? 0 : $sueldo->honorarioCentimos;

		foreach ( self::TIPOS as $tipo ) {
			$suma = (int) ( $ajuste[ $tipo ] ?? 0 );

			if ( 0 === $suma || ( 'horas' === $tipo && ! $sueldo->porHoras() ) ) {
				continue;
			}

			// En el modo por horas, lo que no va aparte se paga a valor hora.
			$destino = $sueldo->porHoras() && ! self::vaAparte( $sueldo, $tipo ) ? 'horas' : $tipo;
			$precio  = 'horas' === $destino ? $sueldo->valorHora() : self::precio( $sueldo, $tipo, $reglas->multiplicadorMadrugada );
			$nuevo   = max( 0, $r['minutos'][ $tipo ] + $suma );
			$cambio  = $nuevo - $r['minutos'][ $tipo ];

			$r['minutos'][ $tipo ]    = $nuevo;
			$r['montos'][ $destino ]  = max( 0, $r['montos'][ $destino ] + (int) round( $cambio * $precio / 60 ) );

			if ( 'horas' !== $tipo && 'horas' === $destino ) {
				$r['minutos']['horas'] = max( 0, $r['minutos']['horas'] + $cambio );
			}
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

		$r['montos']['honorario']  = (int) $fila->montos['honorario'];
		$r['montos']['descuentos'] = (int) $fila->montos['descuentos'];

		foreach ( self::TIPOS as $tipo ) {
			$r['minutos'][ $tipo ] = (int) $fila->minutos[ $tipo ];
			$r['montos'][ $tipo ]  = (int) $fila->montos[ $tipo ];
		}

		return self::conTotal( $r );
	}

	/** Precio de una hora de ese tipo (céntimos), en el sueldo fijo. */
	public static function precio( Sueldo $s, string $tipo, float $multiplicador ): float {
		return match ( $tipo ) {
			'extra', 'sabado_extra' => (float) $s->tarifa( 'extra' ),
			'sabado_normal', 'horas' => (float) $s->valorHora(),
			'domingo'               => (float) $s->tarifa( 'domingo' ),
			'feriado'               => (float) $s->tarifa( 'feriado' ),
			'madrugada'             => $s->valorHora() * $multiplicador,
			default                 => 0.0,
		};
	}

	private static function vaAparte( Sueldo $s, string $tipo ): bool {
		return match ( $tipo ) {
			'extra', 'sabado_extra' => $s->aparte( 'extra' ),
			'domingo', 'feriado'    => $s->aparte( 'domingo' ),
			'madrugada'             => $s->aparte( 'madrugada' ),
			default                 => false,
		};
	}

	/** @param array<string, int> $tarifas @param string[] $aparte */
	private static function sueldo( int $honorario, array $tarifas, string $modo = Sueldo::FIJO, array $aparte = array() ): Sueldo {
		$extra = max( 0, (int) ( $tarifas['extra'] ?? 0 ) );

		return new Sueldo(
			max( 0, $honorario ),
			$extra,
			$extra,
			max( 0, (int) ( $tarifas['domingo'] ?? 0 ) ),
			max( 0, (int) ( $tarifas['feriado'] ?? 0 ) ),
			'0000-01-01',
			null,
			null,
			Sueldo::HORAS === $modo ? Sueldo::HORAS : Sueldo::FIJO,
			$aparte
		);
	}

	/** @return array{minutos: array<string, int>, montos: array<string, int>} */
	private static function vacio(): array {
		return array(
			'minutos' => array_fill_keys( self::TIPOS, 0 ),
			'montos'  => array( 'honorario' => 0 ) + array_fill_keys( self::TIPOS, 0 ) + array( 'descuentos' => 0, 'total' => 0 ),
		);
	}

	/**
	 * @param array{minutos: array<string, int>, montos: array<string, int>} $r
	 * @return array{minutos: array<string, int>, montos: array<string, int>}
	 */
	private static function conTotal( array $r ): array {
		$r['montos']['total'] = max( 0, $r['montos']['honorario'] + array_sum( array_intersect_key( $r['montos'], array_flip( self::TIPOS ) ) ) - $r['montos']['descuentos'] );

		return $r;
	}
}
