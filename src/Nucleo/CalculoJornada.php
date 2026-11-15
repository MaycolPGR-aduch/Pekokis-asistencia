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
 * - Si en un día laborable no marcó la comida, se descuenta la comida de la
 *   regla (60 min por defecto, por confirmar).
 * - Lunes a viernes laborable: es hora extra todo lo que pase de la jornada
 *   efectiva del horario (9 h 30 min con el horario base). Es automático: no
 *   hace falta autorizarla.
 * - Sábado: todas sus horas van con la tarifa de sábado. Domingo: con la de
 *   domingo. Feriado (o día no laborable del calendario): con la de feriado.
 * - Un día de semana que no es laborable en su horario: todo es hora extra.
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
		public readonly int $minutosComidaDescontada, // Comida no marcada que se descontó.
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
	): self {
		$marcas  = MaquinaEstados::ordenar( $marcasDelDia );
		$ventana = self::ventana( $horario, $dia, $zona );
		$esperan = $ventana ? max( 0, intdiv( $ventana[1] - $ventana[0], 60 ) - $horario->refrigerioMin ) : 0;
		$tipo    = self::tipoDia( $horario, $dia, $zona );

		if ( ! $marcas ) {
			[ $estado, $noTrabajado ] = match ( true ) {
				null !== $ausencia    => array( self::JUSTIFICADO, 0 ),
				! $horario->laborable => array( self::DESCANSO, 0 ),
				default               => array( self::FALTA, $esperan ),
			};

			return new self( $estado, $horario->laborable, $tipo, $esperan, 0, 0, 0, 0, 0, 0, 0, $noTrabajado, 0, 0, 0, 0, 0, $ausencia ?? $horario->descanso );
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

		$bruto    = intdiv( self::suma( $dentro ) + self::suma( $pagadas ), 60 );
		$refriMin = intdiv( $refrigerio, 60 );
		$comida   = $horario->laborable && 0 === $refrigerio && null === $abierta ? min( $reglas->comidaNoMarcadaMin, $bruto ) : 0;
		$neto     = $bruto - $comida;

		[ $extra, $sabado, $domingo, $feriado ] = match ( $tipo ) {
			self::DIA_LABORABLE => array( max( 0, $neto - $esperan ), 0, 0, 0 ),
			self::DIA_SABADO    => array( 0, $neto, 0, 0 ),
			self::DIA_DOMINGO   => array( 0, 0, $neto, 0 ),
			self::DIA_FERIADO   => array( 0, 0, 0, $neto ),
			default             => array( $neto, 0, 0, 0 ),
		};

		$estado = match ( true ) {
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
			self::salidaAnticipada( $marcas, $ventana, $revisiones ),
			$horario->laborable && null === $ausencia && self::INCOMPLETO !== $estado ? max( 0, $esperan - $neto ) : 0,
			$extra,
			$sabado,
			$domingo,
			$feriado,
			$pendientes,
			$ausencia ?? $horario->descanso
		);
	}

	public function trabajoAlgo(): bool {
		return $this->minutosTrabajados > 0;
	}

	public function esFalta(): bool {
		return self::FALTA === $this->estado;
	}

	/** Minutos que se pagan aparte del honorario (extra, sábado, domingo y feriado). */
	public function minutosAdicionales(): int {
		return $this->minutosExtra + $this->minutosSabado + $this->minutosDomingo + $this->minutosFeriado;
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
