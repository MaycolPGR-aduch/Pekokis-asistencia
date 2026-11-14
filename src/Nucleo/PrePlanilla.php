<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Pre-planilla de un trabajador en un periodo (normalmente un mes): suma los
 * días y estima los montos con el sueldo vigente de cada día.
 *
 * Los montos son ESTIMADOS para el contador, no una boleta:
 * - Sueldo mensual: la base es el sueldo del mes (prorrateado por días si
 *   cambió a mitad de mes); se descuenta lo no trabajado si la regla lo pide.
 * - Pago por hora: la base son las horas ordinarias trabajadas; no hay descuento.
 * - Horas extra: valor hora × (1 + sobretasa). Descanso o feriado trabajado igual,
 *   con su propia sobretasa.
 */
final class PrePlanilla {
	/** @var array<string, int> */
	public array $dias = array(
		'laborables'   => 0,
		'trabajados'   => 0,
		'faltas'       => 0,
		'justificados' => 0,
		'incompletos'  => 0,
		'por_revisar'  => 0,
		'descanso_trabajados' => 0,
		'con_tardanza'        => 0,
	);

	/** @var array<string, int> */
	public array $minutos = array(
		'esperados'       => 0,
		'trabajados'      => 0,
		'ordinarios'      => 0,
		'salidas_pagadas' => 0,
		'tardanza'        => 0,
		'salida_anticipada' => 0,
		'exceso_refrigerio' => 0,
		'no_trabajados'   => 0,
		'extra_1'         => 0,
		'extra_2'         => 0,
		'descanso'        => 0,
		'compensables'    => 0,
		'sin_autorizar'   => 0,
	);

	/** @var array<string, int> Céntimos. */
	public array $montos = array(
		'base'      => 0,
		'descuento' => 0,
		'extra_1'   => 0,
		'extra_2'   => 0,
		'descanso'  => 0,
		'total'     => 0,
	);

	public ?Sueldo $sueldoFinal = null;

	/** Días del periodo sin un sueldo vigente: sus montos no se pudieron calcular. */
	public int $diasSinSueldo = 0;

	public bool $cambioDeSueldo = false;

	/**
	 * @param array<string, CalculoJornada> $jornadas  Día (Y-m-d) => cálculo. Todos los días calendario del periodo.
	 * @param Sueldo[]                      $historial Sueldos del trabajador.
	 */
	public static function calcular( array $jornadas, array $historial, ReglasPago $reglas ): self {
		ksort( $jornadas );

		$fila   = new self();
		$montos = array_fill_keys( array_keys( $fila->montos ), 0.0 );
		$previo = null;

		foreach ( $jornadas as $dia => $j ) {
			$fila->acumular( $j );

			$sueldo = Sueldo::vigenteEn( $historial, (string) $dia );

			if ( ! $sueldo ) {
				++$fila->diasSinSueldo;
				continue;
			}

			if ( $previo && $previo !== $sueldo ) {
				$fila->cambioDeSueldo = true;
			}

			$previo            = $sueldo;
			$fila->sueldoFinal = $sueldo;
			$vh                = $sueldo->valorHora( $reglas );

			if ( Sueldo::MENSUAL === $sueldo->modalidad ) {
				$montos['base'] += $sueldo->montoCentimos / self::diasDelMes( (string) $dia );

				// Un día incompleto (olvidó marcar la salida) no se descuenta hasta corregirlo.
				if ( $reglas->descontarNoTrabajado && CalculoJornada::INCOMPLETO !== $j->estado ) {
					$montos['descuento'] += $j->esFalta()
						? $vh * $reglas->horasJornadaValor
						: $j->minutosNoTrabajados / 60 * $vh;
				}
			} else {
				$montos['base'] += ( $j->minutosOrdinarios ) / 60 * $vh;
			}

			$montos['extra_1']  += $j->minutosExtra1 / 60 * $vh * ( 1 + $reglas->tasaExtra1 / 100 );
			$montos['extra_2']  += $j->minutosExtra2 / 60 * $vh * ( 1 + $reglas->tasaExtra2 / 100 );
			$montos['descanso'] += $j->minutosDescansoTrabajado / 60 * $vh * ( 1 + $reglas->tasaDescanso / 100 );
		}

		foreach ( array( 'base', 'descuento', 'extra_1', 'extra_2', 'descanso' ) as $clave ) {
			$fila->montos[ $clave ] = (int) round( $montos[ $clave ] );
		}

		$fila->montos['total'] = $fila->montos['base'] - $fila->montos['descuento'] + $fila->montos['extra_1'] + $fila->montos['extra_2'] + $fila->montos['descanso'];

		return $fila;
	}

	private function acumular( CalculoJornada $j ): void {
		if ( $j->laborable ) {
			++$this->dias['laborables'];
		}

		if ( $j->trabajoAlgo() ) {
			++$this->dias[ $j->laborable ? 'trabajados' : 'descanso_trabajados' ];
		}

		match ( $j->estado ) {
			CalculoJornada::FALTA       => ++$this->dias['faltas'],
			CalculoJornada::JUSTIFICADO => ++$this->dias['justificados'],
			CalculoJornada::INCOMPLETO  => ++$this->dias['incompletos'],
			CalculoJornada::A_REVISAR   => ++$this->dias['por_revisar'],
			default                     => null,
		};

		if ( $j->minutosTardanza > 0 ) {
			++$this->dias['con_tardanza'];
		}

		$this->minutos['esperados']         += $j->minutosEsperados;
		$this->minutos['trabajados']        += $j->minutosTrabajados;
		$this->minutos['ordinarios']        += $j->minutosOrdinarios;
		$this->minutos['salidas_pagadas']   += $j->minutosSalidasPagadas;
		$this->minutos['tardanza']          += $j->minutosTardanza;
		$this->minutos['salida_anticipada'] += $j->minutosSalidaAnticipada;
		$this->minutos['exceso_refrigerio'] += $j->minutosExcesoRefrigerio;
		$this->minutos['no_trabajados']     += $j->minutosNoTrabajados;
		$this->minutos['extra_1']           += $j->minutosExtra1;
		$this->minutos['extra_2']           += $j->minutosExtra2;
		$this->minutos['descanso']          += $j->minutosDescansoTrabajado;
		$this->minutos['compensables']      += $j->minutosCompensables;
		$this->minutos['sin_autorizar']     += $j->minutosSinAutorizar;
	}

	/**
	 * Suma varias filas (un equipo, o todo el taller) para el dashboard.
	 *
	 * @param PrePlanilla[] $filas
	 */
	public static function sumar( array $filas ): self {
		$total = new self();

		foreach ( $filas as $f ) {
			foreach ( array( 'dias', 'minutos', 'montos' ) as $grupo ) {
				foreach ( $f->{$grupo} as $clave => $valor ) {
					$total->{$grupo}[ $clave ] += $valor;
				}
			}

			$total->diasSinSueldo += $f->diasSinSueldo;
		}

		return $total;
	}

	/** Porcentaje de días trabajados en horario que llegó a tiempo (null si no trabajó días laborables). */
	public function puntualidad(): ?int {
		return $this->dias['trabajados'] > 0
			? (int) round( 100 * ( $this->dias['trabajados'] - $this->dias['con_tardanza'] ) / $this->dias['trabajados'] )
			: null;
	}

	/** Costo estimado de las horas extra y del descanso o feriado trabajado, en céntimos. */
	public function costoExtra(): int {
		return $this->montos['extra_1'] + $this->montos['extra_2'] + $this->montos['descanso'];
	}

	private static function diasDelMes( string $dia ): int {
		return (int) ( new \DateTimeImmutable( $dia ) )->format( 't' );
	}

	/** 135 → "2:15". */
	public static function horas( int $minutos ): string {
		return intdiv( $minutos, 60 ) . ':' . str_pad( (string) ( $minutos % 60 ), 2, '0', STR_PAD_LEFT );
	}
}
