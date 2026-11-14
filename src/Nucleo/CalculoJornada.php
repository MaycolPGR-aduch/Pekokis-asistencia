<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Cálculo de un día de un trabajador para horas y pre-planilla (plan, sección 6.5).
 *
 * Parte de las marcas efectivas, el horario del día, las decisiones de los jefes
 * sobre las salidas que requieren aprobación y el sobretiempo autorizado.
 * Todo se mide en minutos; los montos los calcula PrePlanilla.
 *
 * Criterios (por confirmar con el contador):
 * - Las salidas temporales cuentan como trabajadas solo si su motivo lo dice
 *   (encargo, lactancia) y, si requiere aprobación, cuando está aprobada.
 * - El tiempo trabajado fuera del horario solo es sobretiempo dentro de una
 *   franja autorizada; lo demás queda como "permanencia sin autorizar" para que
 *   el jefe lo revise (la norma presume sobretiempo si no se aclara).
 * - En un día no laborable (descanso o feriado) todo lo trabajado y autorizado
 *   va a su propia bolsa, con su propia sobretasa.
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

	private function __construct(
		public readonly string $estado,
		public readonly bool $laborable,
		public readonly int $minutosEsperados,
		public readonly int $minutosTrabajados,     // Todo lo trabajado: dentro + salidas pagadas.
		public readonly int $minutosOrdinarios,     // Lo trabajado dentro del horario.
		public readonly int $minutosSalidasPagadas,
		public readonly int $minutosRefrigerio,
		public readonly int $minutosExcesoRefrigerio,
		public readonly int $minutosTardanza,
		public readonly int $minutosSalidaAnticipada,
		public readonly int $minutosNoTrabajados,   // Lo que faltó para la jornada (base del descuento).
		public readonly int $minutosExtra1,         // Sobretiempo pagado a la primera tasa.
		public readonly int $minutosExtra2,         // Sobretiempo pagado a la segunda tasa.
		public readonly int $minutosDescansoTrabajado,
		public readonly int $minutosCompensables,   // Sobretiempo autorizado a cambio de descanso.
		public readonly int $minutosSinAutorizar,
		public readonly int $pendientesRevision,
		public readonly string $detalle = '',
	) {
	}

	/**
	 * @param Marca[]                   $marcasDelDia  Marcas efectivas del día laboral.
	 * @param array<int, string>        $revisiones    marca_id => "aprobada" | "rechazada".
	 * @param AutorizacionSobretiempo[] $autorizaciones
	 * @param string|null               $ausencia      Ausencia justificada del día ("Vacaciones"…).
	 */
	public static function calcular(
		array $marcasDelDia,
		HorarioDelDia $horario,
		string $dia,
		DateTimeZone $zona,
		ReglasPago $reglas,
		array $revisiones = array(),
		array $autorizaciones = array(),
		?string $ausencia = null,
	): self {
		$marcas  = MaquinaEstados::ordenar( $marcasDelDia );
		$ventana = self::ventana( $horario, $dia, $zona );
		$esperan = $ventana ? max( 0, intdiv( $ventana[1] - $ventana[0], 60 ) - $horario->refrigerioMin ) : 0;

		if ( ! $marcas ) {
			[ $estado, $noTrabajado ] = match ( true ) {
				null !== $ausencia    => array( self::JUSTIFICADO, 0 ),
				! $horario->laborable => array( self::DESCANSO, 0 ),
				default               => array( self::FALTA, $esperan ),
			};

			return new self( $estado, $horario->laborable, $esperan, 0, 0, 0, 0, 0, 0, 0, $noTrabajado, 0, 0, 0, 0, 0, 0, $ausencia ?? $horario->descanso );
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

		$trabajado  = array_merge( $dentro, $pagadas );
		$total      = self::suma( $trabajado );
		$ordinario  = $horario->laborable && $ventana ? self::sumaCruce( $trabajado, array( $ventana ) ) : 0;
		$fueraHor   = self::fueraDe( $trabajado, $horario->laborable ? $ventana : null );
		$franjas    = array(
			AutorizacionSobretiempo::PAGO     => array(),
			AutorizacionSobretiempo::DESCANSO => array(),
		);

		foreach ( $autorizaciones as $a ) {
			$franjas[ $a->compensacion ][] = array( $a->desde->getTimestamp(), $a->hasta->getTimestamp() );
		}

		$autPago     = self::sumaCruce( $fueraHor, $franjas[ AutorizacionSobretiempo::PAGO ] );
		$autDescanso = self::sumaCruce( $fueraHor, $franjas[ AutorizacionSobretiempo::DESCANSO ] );
		$sinAut      = max( 0, self::suma( $fueraHor ) - $autPago - $autDescanso );

		$extra1 = 0;
		$extra2 = 0;
		$enDesc = 0;

		if ( $horario->laborable ) {
			$minPago = intdiv( $autPago, 60 );
			$extra1  = min( $minPago, $reglas->tramoExtra1Min );
			$extra2  = $minPago - $extra1;
		} else {
			$enDesc = intdiv( $autPago, 60 );
		}

		$primera  = self::primeraEntrada( $marcas );
		$tardanza = self::tardanza( $primera, $horario, $ventana, $reglas );
		$anticip  = self::salidaAnticipada( $marcas, $ventana, $horario, $revisiones );
		$refriMin = intdiv( $refrigerio, 60 );
		$ordMin   = intdiv( $ordinario, 60 );
		$noTrab   = $horario->laborable && null === $ausencia ? max( 0, $esperan - $ordMin ) : 0;

		$estado = match ( true ) {
			null !== $abierta || null !== MaquinaEstados::problemaEnSecuencia( $marcas ) => self::INCOMPLETO,
			$pendientes > 0 => self::A_REVISAR,
			default         => self::COMPLETO,
		};

		return new self(
			$estado,
			$horario->laborable,
			$esperan,
			intdiv( $total, 60 ),
			$ordMin,
			intdiv( self::suma( $pagadas ), 60 ),
			$refriMin,
			$horario->laborable ? max( 0, $refriMin - $horario->refrigerioMin ) : 0,
			$tardanza,
			$anticip,
			$noTrab,
			$extra1,
			$extra2,
			$enDesc,
			intdiv( $autDescanso, 60 ),
			intdiv( $sinAut, 60 ),
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
	private static function salidaAnticipada( array $marcas, ?array $ventana, HorarioDelDia $horario, array $revisiones ): int {
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

	/**
	 * Segundos de $tramos que caen dentro de $franjas (las franjas no se solapan entre sí).
	 *
	 * @param array<int, array{0: int, 1: int}> $tramos
	 * @param array<int, array{0: int, 1: int}> $franjas
	 */
	private static function sumaCruce( array $tramos, array $franjas ): int {
		$total = 0;

		foreach ( $tramos as [ $a, $b ] ) {
			foreach ( $franjas as [ $c, $d ] ) {
				$total += max( 0, min( $b, $d ) - max( $a, $c ) );
			}
		}

		return $total;
	}

	/**
	 * Partes de los tramos fuera de la ventana del horario (todo, si no hay ventana).
	 *
	 * @param array<int, array{0: int, 1: int}> $tramos
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function fueraDe( array $tramos, ?array $ventana ): array {
		if ( ! $ventana ) {
			return $tramos;
		}

		$fuera = array();

		foreach ( $tramos as [ $a, $b ] ) {
			if ( $a < $ventana[0] ) {
				$fuera[] = array( $a, min( $b, $ventana[0] ) );
			}

			if ( $b > $ventana[1] ) {
				$fuera[] = array( max( $a, $ventana[1] ), $b );
			}
		}

		return $fuera;
	}
}
