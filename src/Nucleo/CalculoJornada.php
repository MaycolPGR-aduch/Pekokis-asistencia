<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Cálculo de un día de un trabajador para horas y pagos.
 *
 * Criterio (contexto del sistema, secciones 6.2 y 8):
 * - Horas trabajadas = tiempo dentro del taller (más las salidas que cuentan
 *   como trabajadas). La comida no cuenta.
 * - La comida cuenta como mínimo lo de su horario (60 min): si comió menos,
 *   esos minutos no suman. Si no la marcó, se descuenta la de la regla.
 * - Madrugada: lo trabajado de 00:00 a 6:00 y, si llegó antes de las 6:00 (o
 *   venía de la noche anterior), hasta su hora de entrada. Se paga aparte
 *   (valor hora × multiplicador), cualquier día.
 * - Lunes a viernes laborable: primero se completa el horario (lo que faltó se
 *   cubre con lo trabajado después de la salida y luego con lo de antes de la
 *   entrada). Lo que sobra es hora extra: después de la salida, por minutos;
 *   antes de la entrada, solo horas completas (los minutos quedan anotados).
 * - Sábado: todas sus horas (la tarifa depende de si completó la semana, ver
 *   PrePlanilla). Domingo y feriado: con su tarifa.
 * - Un día de semana que no es laborable en su horario: todo es hora extra.
 * - Gerencia puede ajustar los minutos de un día (con motivo): suman como
 *   tiempo trabajado después de la salida, o restan.
 * - Tardanzas, salidas anticipadas y faltas se señalan; no se descuentan.
 */
final class CalculoJornada {
	public const COMPLETO    = 'completo';
	public const INCOMPLETO  = 'incompleto';
	public const A_REVISAR   = 'a_revisar';
	public const FALTA       = 'falta';
	public const JUSTIFICADO = 'justificado';
	public const DESCANSO    = 'descanso';

	public const ESTADOS = array(
		self::COMPLETO    => 'Completo',
		self::INCOMPLETO  => 'Incompleto',
		self::A_REVISAR   => 'Por revisar',
		self::FALTA       => 'Falta',
		self::JUSTIFICADO => 'Justificado',
		self::DESCANSO    => 'Descanso',
	);

	// Cómo se paga el día.
	public const DIA_LABORABLE = 'laborable';
	public const DIA_LIBRE     = 'libre';     // Día de semana fuera de su horario.
	public const DIA_SABADO    = 'sabado';
	public const DIA_DOMINGO   = 'domingo';
	public const DIA_FERIADO   = 'feriado';

	private function __construct(
		public readonly string $estado,
		public readonly bool $laborable,
		public readonly string $tipoDia,
		public readonly int $minutosEsperados,       // Jornada efectiva del horario (sin la comida).
		public readonly int $minutosTrabajados,      // Ya sin la comida descontada.
		public readonly int $minutosSalidasPagadas,
		public readonly int $minutosRefrigerio,
		public readonly int $minutosComidaDescontada, // Comida no marcada, o lo que faltó para el mínimo de su horario.
		public readonly int $minutosExcesoRefrigerio,
		public readonly int $minutosTardanza,
		public readonly int $minutosSalidaAnticipada,
		public readonly int $minutosNoTrabajados,    // Lo que faltó para la jornada (solo se informa).
		public readonly int $minutosExtra,           // Lunes a viernes, sobre la jornada (o día libre).
		public readonly int $minutosSabado,
		public readonly int $minutosDomingo,
		public readonly int $minutosFeriado,
		public readonly int $pendientesRevision,
		public readonly string $detalle = '',
		public readonly int $minutosMadrugada = 0,
		public readonly int $minutosTemprano = 0,   // Antes de la entrada (sin madrugada), aunque no cuenten como extra.
		public readonly int $minutosAjuste = 0,     // Ajuste de gerencia (+ o −).
		public readonly bool $conAusencia = false,  // Día con ausencia justificada (aunque tenga marcas).
	) {
	}

	/**
	 * @param Marca[]            $marcasDelDia Marcas efectivas del día laboral.
	 * @param array<int, string> $revisiones   marca_id => "aprobada" | "rechazada".
	 * @param string|null        $ausencia     Ausencia justificada del día ("Vacaciones"…).
	 */
	public static function calcular(
		array $marcasDelDia,
		HorarioDelDia $horario,
		string $dia,
		DateTimeZone $zona,
		ReglasPago $reglas,
		array $revisiones = array(),
		?string $ausencia = null,
		int $ajuste = 0,
	): self {
		$marcas  = MaquinaEstados::ordenar( $marcasDelDia );
		$ventana = self::ventana( $horario, $dia, $zona );
		$esperan = $ventana ? max( 0, intdiv( $ventana[1] - $ventana[0], 60 ) - $horario->refrigerioMin ) : 0;
		$tipo    = self::tipoDia( $horario, $dia, $zona );

		$estadoSinMarcas = null;

		if ( ! $marcas ) {
			[ $estado, $noTrabajado ] = match ( true ) {
				null !== $ausencia    => array( self::JUSTIFICADO, 0 ),
				! $horario->laborable => array( self::DESCANSO, 0 ),
				default               => array( self::FALTA, $esperan ),
			};

			if ( 0 === $ajuste ) {
				return new self( $estado, $horario->laborable, $tipo, $esperan, 0, 0, 0, 0, 0, 0, 0, $noTrabajado, 0, 0, 0, 0, 0, $ausencia ?? $horario->descanso, 0, 0, 0, null !== $ausencia );
			}

			// Sin marcas pero con un ajuste de gerencia: si le reconoce horas, el día no queda como falta.
			$estadoSinMarcas = $ajuste > 0 && null === $ausencia ? self::COMPLETO : $estado;
		}

		$dentro     = array(); // Tramos [inicio, fin] en segundos Unix.
		$pagadas    = array();
		$refrigerio = 0;
		$pendientes = 0;
		$abierta    = null;
		$salida     = null;

		foreach ( $marcas as $marca ) {
			$t = $marca->marcadoUtc->getTimestamp();

			if ( TipoMarca::SALIDA === $marca->tipo && $marca->motivo?->requiereAprobacion && null !== $marca->id && ! isset( $revisiones[ $marca->id ] ) ) {
				++$pendientes;
			}

			if ( TipoMarca::ENTRADA === $marca->tipo ) {
				if ( $salida ) {
					if ( CategoriaMotivo::REFRIGERIO === $salida->motivo?->categoria ) {
						$refrigerio += $t - $salida->marcadoUtc->getTimestamp();
					} elseif ( self::esPagada( $salida, $revisiones ) ) {
						$pagadas[] = array( $salida->marcadoUtc->getTimestamp(), $t );
					}
				}

				$abierta = $t;
				$salida  = null;
				continue;
			}

			if ( null !== $abierta ) {
				$dentro[] = array( $abierta, $t );
			}

			$abierta = null;
			$salida  = $marca;
		}

		// Salió a un encargo pagado y no volvió: cuenta hasta el fin del horario.
		if ( $salida && $ventana && CategoriaMotivo::TEMPORAL === $salida->motivo?->categoria && self::esPagada( $salida, $revisiones ) ) {
			$desde = $salida->marcadoUtc->getTimestamp();

			if ( $desde < $ventana[1] ) {
				$pagadas[] = array( $desde, $ventana[1] );
			}
		}

		$tramos   = array_merge( $dentro, $pagadas );
		$bruto    = intdiv( self::suma( $tramos ), 60 );
		$refriMin = intdiv( $refrigerio, 60 );
		$comida   = 0;

		// La comida cuenta como mínimo lo de su horario; sin marcarla, lo de la regla.
		if ( $horario->laborable && null === $abierta && $marcas ) {
			$comida = 0 === $refrigerio
				? min( $reglas->comidaNoMarcadaMin, $bruto )
				: min( max( 0, $horario->refrigerioMin - $refriMin ), $bruto );
		}

		[ $madrugada, $temprano, $enHorario, $despues, $resto ] = self::partes( $tramos, $dia, $zona, $ventana, $reglas->madrugadaHastaMin );

		// El descuento de la comida sale de lo trabajado en el horario (o de lo que sigue).
		$quita      = min( $comida, $enHorario );
		$enHorario -= $quita;
		$despues    = max( 0, $despues - ( $comida - $quita ) );
		$neto       = max( 0, $bruto - $comida + $ajuste );
		$faltan     = 0;
		$extra      = 0;
		$sabado     = 0;
		$domingo    = 0;
		$feriado    = 0;

		if ( self::DIA_LABORABLE === $tipo ) {
			// Ajuste de gerencia: suma como tiempo después de la salida; si resta, primero de ahí.
			$faltan = null !== $ausencia ? 0 : max( 0, $esperan - $enHorario );
			if ( $ajuste >= 0 ) {
				$despues += $ajuste;
			} else {
				// Si resta: primero lo de después de la salida, luego la madrugada; el resto es lo que faltó.
				$saca       = min( $despues, -$ajuste );
				$despues   -= $saca;
				$sacaM      = min( $madrugada, -$ajuste - $saca );
				$madrugada -= $sacaM;
				$faltan    += -$ajuste - $saca - $sacaM;
			}

			// Primero se completa el horario; lo que sobra es extra.
			$cubre    = min( $despues, $faltan );
			$despues -= $cubre;
			$faltan  -= $cubre;
			$cubre2   = min( $temprano, $faltan );
			$faltan  -= $cubre2;
			$extra    = $despues + intdiv( $temprano - $cubre2, 60 ) * 60;
		} else {
			$libre = $resto + $temprano + $enHorario + $despues + $ajuste;

			if ( $libre < 0 ) {
				$madrugada = max( 0, $madrugada + $libre ); // Un ajuste negativo que pasa lo trabajado quita madrugada.
				$libre     = 0;
			}
			match ( $tipo ) {
				self::DIA_SABADO  => $sabado = $libre,
				self::DIA_DOMINGO => $domingo = $libre,
				self::DIA_FERIADO => $feriado = $libre,
				default           => $extra = $libre,
			};
		}

		$estado = $estadoSinMarcas ?? match ( true ) {
			null !== $abierta || null !== MaquinaEstados::problemaEnSecuencia( $marcas ) => self::INCOMPLETO,
			$pendientes > 0 => self::A_REVISAR,
			default         => self::COMPLETO,
		};

		return new self(
			$estado,
			$horario->laborable,
			$tipo,
			$esperan,
			$neto,
			intdiv( self::suma( $pagadas ), 60 ),
			$refriMin,
			$comida,
			$horario->laborable ? max( 0, $refriMin - $horario->refrigerioMin ) : 0,
			self::tardanza( self::primeraEntrada( $marcas ), $horario, $ventana, $reglas ),
			$marcas ? self::salidaAnticipada( $marcas, $ventana, $revisiones ) : 0,
			$horario->laborable && null === $ausencia && self::INCOMPLETO !== $estado ? $faltan : 0,
			$extra,
			$sabado,
			$domingo,
			$feriado,
			$pendientes,
			$ausencia ?? $horario->descanso,
			$madrugada,
			$temprano,
			$ajuste,
			null !== $ausencia
		);
	}

	/**
	 * Reparte lo trabajado: madrugada, antes de la entrada, dentro del horario,
	 * después de la salida y (sin horario) el resto. En minutos.
	 *
	 * @param array<int, array{0: int, 1: int}> $tramos
	 * @param array{0: int, 1: int}|null       $ventana
	 * @return array{0: int, 1: int, 2: int, 3: int, 4: int}
	 */
	private static function partes( array $tramos, string $dia, DateTimeZone $zona, ?array $ventana, int $madrugadaHasta ): array {
		$cero   = ( new DateTimeImmutable( $dia . ' 00:00', $zona ) )->getTimestamp();
		$fin6   = $cero + $madrugadaHasta * 60;
		$mad    = array( array( $cero, $fin6 ), array( $cero + 86400, $fin6 + 86400 ) );
		$t      = array( 0, 0, 0, 0, 0 );

		foreach ( $tramos as [ $a, $b ] ) {
			$piezas = array( array( $a, $b ) );
			$madTramo = $mad;

			// Llegó en la madrugada y siguió: hasta su entrada también es madrugada.
			if ( $ventana && $a < $fin6 && $b > $fin6 && $ventana[0] > $fin6 ) {
				$madTramo[0] = array( $cero, $ventana[0] );
			}

			foreach ( $madTramo as [ $m0, $m1 ] ) {
				$siguen = array();
				foreach ( $piezas as [ $p0, $p1 ] ) {
					$i0 = max( $p0, $m0 );
					$i1 = min( $p1, $m1 );
					if ( $i1 > $i0 ) {
						$t[0] += $i1 - $i0;
						if ( $i0 > $p0 ) {
							$siguen[] = array( $p0, $i0 );
						}
						if ( $p1 > $i1 ) {
							$siguen[] = array( $i1, $p1 );
						}
					} else {
						$siguen[] = array( $p0, $p1 );
					}
				}
				$piezas = $siguen;
			}

			foreach ( $piezas as [ $p0, $p1 ] ) {
				if ( ! $ventana ) {
					$t[4] += $p1 - $p0;
					continue;
				}
				$t[1] += max( 0, min( $p1, $ventana[0] ) - $p0 );
				$t[2] += max( 0, min( $p1, $ventana[1] ) - max( $p0, $ventana[0] ) );
				$t[3] += max( 0, $p1 - max( $p0, $ventana[1] ) );
			}
		}

		return array_map( static fn( int $seg ): int => intdiv( $seg, 60 ), $t );
	}

	public function trabajoAlgo(): bool {
		return $this->minutosTrabajados > 0;
	}

	public function esFalta(): bool {
		return self::FALTA === $this->estado;
	}

	/** Minutos que se pagan aparte del honorario (extra, sábado, domingo, feriado y madrugada). */
	public function minutosAdicionales(): int {
		return $this->minutosExtra + $this->minutosSabado + $this->minutosDomingo + $this->minutosFeriado + $this->minutosMadrugada;
	}

	/** Feriado del calendario, sábado, domingo, o laborable / libre según su horario. */
	public static function tipoDia( HorarioDelDia $horario, string $dia, DateTimeZone $zona ): string {
		if ( $horario->feriado ) {
			return self::DIA_FERIADO;
		}

		$n = (int) ( new DateTimeImmutable( $dia . ' 12:00', $zona ) )->format( 'N' );

		return match ( true ) {
			6 === $n            => self::DIA_SABADO,
			7 === $n            => self::DIA_DOMINGO,
			$horario->laborable => self::DIA_LABORABLE,
			default             => self::DIA_LIBRE,
		};
	}

	/** @param array<int, string> $revisiones */
	private static function esPagada( Marca $salida, array $revisiones ): bool {
		$motivo = $salida->motivo;

		if ( ! $motivo || ! $motivo->cuentaComoTrabajado ) {
			return false;
		}

		return ! $motivo->requiereAprobacion || ( null !== $salida->id && 'aprobada' === ( $revisiones[ $salida->id ] ?? '' ) );
	}

	/** @return array{0: int, 1: int}|null Inicio y fin del horario en segundos Unix. */
	private static function ventana( HorarioDelDia $horario, string $dia, DateTimeZone $zona ): ?array {
		if ( ! $horario->laborable || ! $horario->entrada || ! $horario->salida ) {
			return null;
		}

		$inicio = ( new DateTimeImmutable( $dia . ' ' . substr( $horario->entrada, 0, 5 ), $zona ) )->getTimestamp();
		$fin    = ( new DateTimeImmutable( $dia . ' ' . substr( $horario->salida, 0, 5 ), $zona ) )->getTimestamp();

		if ( $fin <= $inicio ) {
			$fin += 86400; // Turno que termina pasada la medianoche.
		}

		return array( $inicio, $fin );
	}

	/** @param Marca[] $marcas */
	private static function primeraEntrada( array $marcas ): ?int {
		foreach ( $marcas as $marca ) {
			if ( TipoMarca::ENTRADA === $marca->tipo ) {
				return $marca->marcadoUtc->getTimestamp();
			}
		}

		return null;
	}

	private static function tardanza( ?int $primera, HorarioDelDia $horario, ?array $ventana, ReglasPago $reglas ): int {
		if ( null === $primera || ! $ventana ) {
			return 0;
		}

		$minutos = intdiv( $primera - $ventana[0], 60 );

		if ( $minutos <= $horario->toleranciaMin ) {
			return 0;
		}

		return ReglasPago::TARDANZA_DESDE_TOLERANCIA === $reglas->tardanzaDesde ? $minutos - $horario->toleranciaMin : $minutos;
	}

	/**
	 * Minutos entre la última salida y el fin del horario, si se fue antes y sin
	 * una salida aprobada (permiso, salud o emergencia) o un encargo pagado.
	 *
	 * @param Marca[]            $marcas
	 * @param array<int, string> $revisiones
	 */
	private static function salidaAnticipada( array $marcas, ?array $ventana, array $revisiones ): int {
		$ultima = $marcas[ count( $marcas ) - 1 ];

		if ( ! $ventana || TipoMarca::SALIDA !== $ultima->tipo || CategoriaMotivo::REFRIGERIO === $ultima->motivo?->categoria ) {
			return 0;
		}

		$motivo = $ultima->motivo;

		if ( self::esPagada( $ultima, $revisiones ) ) {
			return 0;
		}

		if ( $motivo && $motivo->requiereAprobacion && null !== $ultima->id && 'aprobada' === ( $revisiones[ $ultima->id ] ?? '' ) ) {
			return 0;
		}

		return max( 0, intdiv( $ventana[1] - $ultima->marcadoUtc->getTimestamp(), 60 ) );
	}

	/** @param array<int, array{0: int, 1: int}> $tramos */
	private static function suma( array $tramos ): int {
		$total = 0;

		foreach ( $tramos as [ $a, $b ] ) {
			$total += max( 0, $b - $a );
		}

		return $total;
	}
}
