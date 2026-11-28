<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Cálculo del pago de un trabajador en un periodo (normalmente un mes), por
 * recibos por honorarios. Sueldo fijo:
 *
 *   total = honorario + horas extra × tarifa extra
 *           + horas de sábado × (tarifa extra si completó la semana, o valor hora)
 *           + horas de domingo × tarifa domingo + horas de feriado × tarifa feriado
 *           + horas de madrugada × valor hora × multiplicador
 *           − descuentos
 *
 * Valor hora = honorario ÷ 192. "Completó la semana": de lunes a viernes trabajó,
 * en total, las horas de su horario (los días justificados y los feriados cuentan
 * como cumplidos). Modo por horas: horas trabajadas × valor hora, y lo que se
 * elija (extra, madrugada, domingo y feriado) con su propia tarifa.
 *
 * Cada día usa el sueldo vigente ese día. El honorario es el vigente al final
 * del periodo. El pago solo se frena por una incidencia de asistencia sin
 * resolver (un día incompleto o una marca por revisar).
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

	/** Lo que se suma al honorario (en céntimos), en el orden en que se muestra. */
	public const ADICIONALES = array( 'horas', 'extra', 'sabado', 'domingo', 'feriado', 'madrugada' );

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
		'sabado_extra'      => 0, // Sábados con la semana completa (tarifa extra).
		'sabado_normal'     => 0, // Sábados sin completar la semana (valor hora).
		'domingo'           => 0,
		'feriado'           => 0,
		'madrugada'         => 0,
		'temprano'          => 0, // Antes de la entrada (informativo).
		'ajuste'            => 0, // Ajustes de gerencia (+ o −).
		'horas'             => 0, // Modo por horas: las que se pagan a valor hora.
	);

	/** @var array<string, int> Céntimos. */
	public array $montos = array(
		'honorario'     => 0,
		'horas'         => 0,
		'extra'         => 0,
		'sabado'        => 0,
		'sabado_extra'  => 0,
		'sabado_normal' => 0,
		'domingo'       => 0,
		'feriado'       => 0,
		'madrugada'     => 0,
		'descuentos'    => 0,
		'total'         => 0,
	);

	public ?Sueldo $sueldoFinal = null;

	public float $multiplicadorMadrugada = 1.5;

	/** Días con horas adicionales y sin tarifa vigente: esas horas no se pudieron valorizar. */
	public int $diasSinSueldo = 0;

	public bool $cambioDeSueldo = false;

	/** Días del mes con sueldo fijo, si el mes mezcla sueldo fijo y por horas (null = no mezcla). @var array{0: int, 1: int}|null */
	public ?array $diasFijo = null;

	/**
	 * @param array<string, CalculoJornada>                                         $jornadas   Día (Y-m-d) => cálculo.
	 * @param Sueldo[]                                                              $historial  Honorarios y tarifas del trabajador.
	 * @param array<string, array{esperados: int, trabajados: int, completa: bool}>|null $semanas Lunes => semana (ver semanas()); si no
	 *                                                                              se pasa, se calcula con estas mismas jornadas.
	 * @param int                                                                   $descuentos Céntimos a descontar del mes.
	 */
	public static function calcular( array $jornadas, array $historial, ?ReglasPago $reglas = null, ?array $semanas = null, int $descuentos = 0 ): self {
		ksort( $jornadas );

		$reglas ??= new ReglasPago();
		$semanas ??= self::semanas( $jornadas );
		$fila      = new self();
		$previo    = null;
		$mult      = $reglas->multiplicadorMadrugada;

		$fila->multiplicadorMadrugada = $mult;

		foreach ( $jornadas as $dia => $j ) {
			$fila->acumular( $j );

			$completa = $j->minutosSabado > 0 && ( $semanas[ self::lunes( (string) $dia ) ]['completa'] ?? false );
			$fila->minutos[ $completa ? 'sabado_extra' : 'sabado_normal' ] += $j->minutosSabado;

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
			$hora              = $sueldo->valorHora();
			$aValorHora        = $sueldo->porHoras() ? $j->minutosTrabajados : 0;
			$pagar             = static function ( string $clave, int $min, float $tarifa ) use ( $fila ): void {
				$fila->montos[ $clave ] += (int) round( $min * $tarifa / 60 );
			};

			if ( $sueldo->aparte( 'extra' ) ) {
				$pagar( 'extra', $j->minutosExtra, $sueldo->tarifa( 'extra' ) );
				$aValorHora -= $j->minutosExtra + ( $completa ? $j->minutosSabado : 0 );

				if ( $completa ) {
					$pagar( 'sabado_extra', $j->minutosSabado, $sueldo->tarifa( 'extra' ) );
				}
			}

			if ( ! $sueldo->porHoras() && ! $completa ) {
				$pagar( 'sabado_normal', $j->minutosSabado, $hora );
			}

			if ( $sueldo->aparte( 'domingo' ) ) {
				$pagar( 'domingo', $j->minutosDomingo, $sueldo->tarifa( 'domingo' ) );
				$pagar( 'feriado', $j->minutosFeriado, $sueldo->tarifa( 'feriado' ) );
				$aValorHora -= $j->minutosDomingo + $j->minutosFeriado;
			}

			if ( $sueldo->aparte( 'madrugada' ) ) {
				$pagar( 'madrugada', $j->minutosMadrugada, $hora * $mult );
				$aValorHora -= $j->minutosMadrugada;
			}

			if ( $sueldo->porHoras() ) {
				$aValorHora              = max( 0, $aValorHora );
				$fila->minutos['horas'] += $aValorHora;
				$pagar( 'horas', $aValorHora, $hora );
			}
		}

		$fila->montos['sabado']     = $fila->montos['sabado_extra'] + $fila->montos['sabado_normal'];
		$fila->montos['honorario']  = $fila->sueldoFinal && ! $fila->sueldoFinal->porHoras() ? $fila->sueldoFinal->honorarioCentimos : 0;

		// Si en el mes cambió entre sueldo fijo y por horas, el honorario se cuenta
		// solo por los días del mes con sueldo fijo (si no, se pagaría dos veces o nunca).
		if ( $jornadas && $historial ) {
			$primero = (string) array_key_first( $jornadas );
			$inicio  = substr( $primero, 0, 7 ) . '-01';
			$totalD  = (int) gmdate( 't', (int) strtotime( $inicio . ' 12:00 UTC' ) );
			$fijos   = 0;
			$porH    = 0;
			$montoF  = 0;

			for ( $d = 0; $d < $totalD; $d++ ) {
				$s = Sueldo::vigenteEn( $historial, gmdate( 'Y-m-d', (int) strtotime( $inicio . ' 12:00 UTC' ) + $d * 86400 ) );

				if ( $s && $s->porHoras() ) {
					++$porH;
				} elseif ( $s ) {
					++$fijos;
					$montoF += $s->honorarioCentimos / $totalD;
				}
			}

			if ( $porH && $fijos ) {
				$fila->diasFijo            = array( $fijos, $totalD );
				$fila->montos['honorario'] = (int) round( $montoF );
			}
		}
		$fila->montos['descuentos'] = max( 0, $descuentos );
		$fila->montos['total']      = max( 0, $fila->montos['honorario'] + $fila->costoExtra() - $fila->montos['descuentos'] );

		return $fila;
	}

	/**
	 * Lunes a viernes de cada semana: horas esperadas y trabajadas, y si la
	 * completó (en total). Los días justificados no cuentan; los feriados no
	 * esperan horas. La madrugada no completa el horario.
	 *
	 * @param array<string, CalculoJornada> $jornadas
	 * @return array<string, array{esperados: int, trabajados: int, completa: bool}> Lunes (Y-m-d) => semana.
	 */
	public static function semanas( array $jornadas ): array {
		$semanas = array();

		foreach ( $jornadas as $dia => $j ) {
			$n = (int) gmdate( 'N', (int) strtotime( $dia . ' 12:00 UTC' ) );

			if ( $n > 5 ) {
				continue;
			}

			$lunes             = self::lunes( (string) $dia );
			$semanas[ $lunes ] ??= array( 'esperados' => 0, 'trabajados' => 0, 'completa' => true );

			if ( ! $j->laborable || CalculoJornada::JUSTIFICADO === $j->estado || $j->conAusencia ) {
				continue;
			}

			$semanas[ $lunes ]['esperados']  += $j->minutosEsperados;
			$semanas[ $lunes ]['trabajados'] += max( 0, $j->minutosTrabajados - $j->minutosMadrugada );
			$semanas[ $lunes ]['completa']    = $semanas[ $lunes ]['trabajados'] >= $semanas[ $lunes ]['esperados'];
		}

		return $semanas;
	}

	/** El lunes de la semana de ese día (Y-m-d). */
	public static function lunes( string $dia ): string {
		$t = (int) strtotime( $dia . ' 12:00 UTC' );

		return gmdate( 'Y-m-d', $t - ( (int) gmdate( 'N', $t ) - 1 ) * 86400 );
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
		$this->minutos['madrugada']         += $j->minutosMadrugada;
		$this->minutos['temprano']          += $j->minutosTemprano;
		$this->minutos['ajuste']            += $j->minutosAjuste;
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
		return $this->minutos['extra'] + $this->minutos['sabado'] + $this->minutos['domingo'] + $this->minutos['feriado'] + $this->minutos['madrugada'];
	}

	/** Lo que se paga aparte del honorario (en el modo por horas, todo), en céntimos y antes de descuentos. */
	public function costoExtra(): int {
		return array_sum( array_intersect_key( $this->montos, array_flip( self::ADICIONALES ) ) );
	}

	public function porHoras(): bool {
		return (bool) $this->sueldoFinal?->porHoras();
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

		$hora   = Sueldo::soles( $s->valorHora() );
		$lineas = $s->porHoras()
			? array( sprintf( 'Pago por horas: %s h × %s (valor hora: %s ÷ %d) = %s.', self::horasDecimal( $this->minutos['horas'] ), $hora, Sueldo::soles( $s->honorarioCentimos ), Sueldo::HORAS_MES, Sueldo::soles( $this->montos['horas'] ) ) )
			: array( 'Honorario del mes: ' . Sueldo::soles( $this->montos['honorario'] ) . ' (incluye las horas normales). Valor hora: ' . Sueldo::soles( $s->honorarioCentimos ) . ' ÷ ' . Sueldo::HORAS_MES . ' = ' . $hora . '.' );
		$tarifa = static fn( string $tipo ): string => Sueldo::soles( $s->tarifa( $tipo ) );
		$filas  = array(
			array( 'extra', 'extra', 'Horas extra de lunes a viernes', $tarifa( 'extra' ) ),
			array( 'sabado_extra', 'sabado_extra', 'Sábado con la semana completa', $tarifa( 'extra' ) . ' (tarifa extra)' ),
			array( 'sabado_normal', 'sabado_normal', 'Sábado sin completar la semana', $hora . ' (valor hora)' ),
			array( 'domingo', 'domingo', 'Horas de domingo', $tarifa( 'domingo' ) ),
			array( 'feriado', 'feriado', 'Horas de feriado', $tarifa( 'feriado' ) ),
			array( 'madrugada', 'madrugada', 'Madrugada (hasta las 6:00)', Sueldo::soles( (int) round( $s->valorHora() * $this->multiplicadorMadrugada ) ) . ' (valor hora × ' . self::numero( $this->multiplicadorMadrugada ) . ')' ),
		);

		foreach ( $filas as [ $min, $monto, $nombre, $precio ] ) {
			if ( $this->minutos[ $min ] <= 0 ) {
				continue;
			}

			$lineas[] = $this->montos[ $monto ] > 0 || ! $s->porHoras()
				? sprintf( '%s: %s h × %s = %s.', $nombre, self::horasDecimal( $this->minutos[ $min ] ), $precio, Sueldo::soles( $this->montos[ $monto ] ) )
				: sprintf( '%s: %s h (incluidas en el pago por horas).', $nombre, self::horasDecimal( $this->minutos[ $min ] ) );
		}

		if ( $this->minutos['ajuste'] ) {
			$lineas[] = sprintf( 'Ajustes de horas de gerencia: %s%s h (ya sumados arriba).', $this->minutos['ajuste'] > 0 ? '+' : '−', self::horasDecimal( abs( $this->minutos['ajuste'] ) ) );
		}

		if ( $this->montos['descuentos'] ) {
			$lineas[] = 'Descuentos: − ' . Sueldo::soles( $this->montos['descuentos'] ) . '.';
		}

		$lineas[] = 'Total: ' . Sueldo::soles( $this->montos['total'] ) . '.';

		if ( $this->diasFijo ) {
			$lineas[] = sprintf( 'Este mes cambió entre sueldo fijo y pago por horas: el honorario se cuenta solo por los %d de %d días con sueldo fijo (%s).', $this->diasFijo[0], $this->diasFijo[1], Sueldo::soles( $this->montos['honorario'] ) );
		}

		if ( $this->cambioDeSueldo ) {
			$lineas[] = 'Las tarifas cambiaron en el mes: cada día se calculó con la que estaba vigente.';
		}

		if ( $this->diasSinSueldo ) {
			$lineas[] = $this->diasSinSueldo . ' día(s) con horas adicionales no tenían tarifa vigente y no se cobraron.';
		}

		return $lineas;
	}

	/** 1.5 → "1,5". */
	public static function numero( float $n ): string {
		return rtrim( rtrim( number_format( $n, 2, ',', '' ), '0' ), ',' );
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
