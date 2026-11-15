<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Cálculo del pago de un trabajador en un periodo (normalmente un mes), por
 * recibos por honorarios:
 *
 *   total = honorario + horas extra × tarifa extra + horas de sábado × tarifa
 *           sábado + horas de domingo × tarifa domingo + horas de feriado ×
 *           tarifa feriado
 *
 * Cada día usa la tarifa vigente ese día. El honorario es el vigente al final
 * del periodo. No hay descuentos. El pago solo se frena por una incidencia de
 * asistencia sin resolver (un día incompleto o una marca por revisar); el
 * recibo por honorarios no lo frena.
 */
final class PrePlanilla {
	public const LISTO      = 'listo';
	public const INCIDENCIA = 'incidencia';
	public const SIN_TARIFA = 'sin_tarifa';

	public const ESTADOS = array(
		self::LISTO      => 'Listo para pagar',
		self::INCIDENCIA => 'Resolver incidencia',
		self::SIN_TARIFA => 'Falta su honorario',
	);

	/** @var array<string, int> */
	public array $dias = array(
		'laborables'   => 0,
		'trabajados'   => 0,
		'faltas'       => 0,
		'justificados' => 0,
		'incompletos'  => 0,
		'por_revisar'  => 0,
		'con_tardanza' => 0,
		'sabados'      => 0,
		'domingos'     => 0,
		'feriados'     => 0,
	);

	/** @var array<string, int> */
	public array $minutos = array(
		'esperados'         => 0,
		'trabajados'        => 0,
		'tardanza'          => 0,
		'salida_anticipada' => 0,
		'no_trabajados'     => 0,
		'comida_descontada' => 0,
		'extra'             => 0,
		'sabado'            => 0,
		'domingo'           => 0,
		'feriado'           => 0,
	);

	/** @var array<string, int> Céntimos. */
	public array $montos = array(
		'honorario' => 0,
		'extra'     => 0,
		'sabado'    => 0,
		'domingo'   => 0,
		'feriado'   => 0,
		'total'     => 0,
	);

	public ?Sueldo $sueldoFinal = null;

	/** Días con horas adicionales y sin tarifa vigente: esas horas no se pudieron valorizar. */
	public int $diasSinSueldo = 0;

	public bool $cambioDeSueldo = false;

	/**
	 * @param array<string, CalculoJornada> $jornadas  Día (Y-m-d) => cálculo.
	 * @param Sueldo[]                      $historial Honorarios y tarifas del trabajador.
	 */
	public static function calcular( array $jornadas, array $historial ): self {
		ksort( $jornadas );

		$fila   = new self();
		$previo = null;

		foreach ( $jornadas as $dia => $j ) {
			$fila->acumular( $j );

			$sueldo = Sueldo::vigenteEn( $historial, (string) $dia );

			if ( ! $sueldo ) {
				$fila->diasSinSueldo += $j->minutosAdicionales() > 0 ? 1 : 0;
				continue;
			}

			if ( $previo && $previo !== $sueldo ) {
				$fila->cambioDeSueldo = true;
			}

			$previo            = $sueldo;
			$fila->sueldoFinal = $sueldo;

			foreach ( array( 'extra' => $j->minutosExtra, 'sabado' => $j->minutosSabado, 'domingo' => $j->minutosDomingo, 'feriado' => $j->minutosFeriado ) as $tipo => $min ) {
				$fila->montos[ $tipo ] += (int) round( $min * $sueldo->tarifa( $tipo ) / 60 );
			}
		}

		$fila->montos['honorario'] = $fila->sueldoFinal ? $fila->sueldoFinal->honorarioCentimos : 0;
		$fila->montos['total']     = $fila->montos['honorario'] + $fila->montos['extra'] + $fila->montos['sabado'] + $fila->montos['domingo'] + $fila->montos['feriado'];

		return $fila;
	}

	private function acumular( CalculoJornada $j ): void {
		if ( $j->laborable ) {
			++$this->dias['laborables'];
		}

		if ( $j->trabajoAlgo() ) {
			match ( $j->tipoDia ) {
				CalculoJornada::DIA_SABADO  => ++$this->dias['sabados'],
				CalculoJornada::DIA_DOMINGO => ++$this->dias['domingos'],
				CalculoJornada::DIA_FERIADO => ++$this->dias['feriados'],
				default                     => null,
			};

			if ( $j->laborable ) {
				++$this->dias['trabajados'];
			}
		}

		if ( $j->minutosTardanza > 0 ) {
			++$this->dias['con_tardanza'];
		}

		match ( $j->estado ) {
			CalculoJornada::FALTA       => ++$this->dias['faltas'],
			CalculoJornada::JUSTIFICADO => ++$this->dias['justificados'],
			CalculoJornada::INCOMPLETO  => ++$this->dias['incompletos'],
			CalculoJornada::A_REVISAR   => ++$this->dias['por_revisar'],
			default                     => null,
		};

		$this->minutos['esperados']         += $j->minutosEsperados;
		$this->minutos['trabajados']        += $j->minutosTrabajados;
		$this->minutos['tardanza']          += $j->minutosTardanza;
		$this->minutos['salida_anticipada'] += $j->minutosSalidaAnticipada;
		$this->minutos['no_trabajados']     += $j->minutosNoTrabajados;
		$this->minutos['comida_descontada'] += $j->minutosComidaDescontada;
		$this->minutos['extra']             += $j->minutosExtra;
		$this->minutos['sabado']            += $j->minutosSabado;
		$this->minutos['domingo']           += $j->minutosDomingo;
		$this->minutos['feriado']           += $j->minutosFeriado;
	}

	/** Listo para pagar, o qué lo frena. */
	public function estado(): string {
		if ( ! $this->sueldoFinal ) {
			return self::SIN_TARIFA;
		}

		return $this->dias['incompletos'] + $this->dias['por_revisar'] > 0 ? self::INCIDENCIA : self::LISTO;
	}

	/**
	 * Suma varias filas (un equipo, o todo el taller) para el resumen.
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

	/** Minutos que se pagan aparte del honorario. */
	public function minutosAdicionales(): int {
		return $this->minutos['extra'] + $this->minutos['sabado'] + $this->minutos['domingo'] + $this->minutos['feriado'];
	}

	/** Lo que se paga aparte del honorario, en céntimos. */
	public function costoExtra(): int {
		return $this->montos['extra'] + $this->montos['sabado'] + $this->montos['domingo'] + $this->montos['feriado'];
	}

	/**
	 * El cálculo en palabras ("Ver cálculo").
	 *
	 * @return string[]
	 */
	public function explicacion(): array {
		$s = $this->sueldoFinal;

		if ( ! $s ) {
			return array( 'Todavía no tiene honorario ni tarifas registrados: no se puede calcular su pago.' );
		}

		$lineas = array( 'Honorario del mes: ' . Sueldo::soles( $this->montos['honorario'] ) . ' (incluye las horas normales).' );

		foreach ( array( 'extra' => 'Horas extra de lunes a viernes', 'sabado' => 'Horas de sábado', 'domingo' => 'Horas de domingo', 'feriado' => 'Horas de feriado' ) as $tipo => $nombre ) {
			if ( $this->minutos[ $tipo ] > 0 ) {
				$lineas[] = sprintf( '%s: %s h × %s = %s.', $nombre, self::horasDecimal( $this->minutos[ $tipo ] ), Sueldo::soles( $s->tarifa( $tipo ) ), Sueldo::soles( $this->montos[ $tipo ] ) );
			}
		}

		$lineas[] = 'Total: ' . Sueldo::soles( $this->montos['total'] ) . '.';

		if ( $this->cambioDeSueldo ) {
			$lineas[] = 'Las tarifas cambiaron en el mes: cada día se calculó con la que estaba vigente.';
		}

		if ( $this->diasSinSueldo ) {
			$lineas[] = $this->diasSinSueldo . ' día(s) con horas adicionales no tenían tarifa vigente y no se cobraron.';
		}

		return $lineas;
	}

	/** 135 → "2:15". */
	public static function horas( int $minutos ): string {
		return intdiv( $minutos, 60 ) . ':' . str_pad( (string) ( $minutos % 60 ), 2, '0', STR_PAD_LEFT );
	}

	/** 390 → "6,5". */
	public static function horasDecimal( int $minutos ): string {
		return rtrim( rtrim( number_format( round( $minutos / 60, 2 ), 2, ',', '' ), '0' ), ',' );
	}
}
