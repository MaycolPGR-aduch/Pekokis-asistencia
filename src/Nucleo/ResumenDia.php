<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Situación de un trabajador en el día, para el panel del jefe.
 *
 * Por ahora la tardanza se cuenta desde la hora de entrada del horario cuando
 * se pasa la tolerancia (pendiente de confirmar con gerencia: sección 16 del plan).
 */
final class ResumenDia {
	public const POR_LLEGAR  = 'por_llegar';
	public const AUSENTE     = 'ausente';
	public const PRESENTE    = 'presente';
	public const TARDE       = 'tarde';
	public const REFRIGERIO  = 'refrigerio';
	public const FUERA       = 'fuera';
	public const SALIO       = 'salio';
	public const LIBRE       = 'libre';

	private function __construct(
		public readonly string $situacion,
		public readonly Estado $estado,
		public readonly ?DateTimeImmutable $primeraEntrada,
		public readonly int $minutosTardanza,
		public readonly ?DateTimeImmutable $desde,
		public readonly ?Motivo $motivoActual,
		public readonly int $minutosEnEstado,
		public readonly bool $excedeRefrigerio,
	) {
	}

	/**
	 * @param Marca[] $marcasDelDia Marcas efectivas del día laboral.
	 */
	public static function calcular( array $marcasDelDia, HorarioDelDia $horario, string $dia, DateTimeZone $zona, DateTimeImmutable $ahora ): self {
		$marcas  = MaquinaEstados::ordenar( $marcasDelDia );
		$estado  = MaquinaEstados::estadoActual( $marcas );
		$ultima  = $marcas ? $marcas[ count( $marcas ) - 1 ] : null;
		$primera = null;

		foreach ( $marcas as $marca ) {
			if ( TipoMarca::ENTRADA === $marca->tipo ) {
				$primera = $marca->marcadoUtc;
				break;
			}
		}

		$tardanza = 0;
		$inicio   = self::momento( $dia, $horario->entrada, $zona );

		if ( $primera && $horario->laborable && $inicio ) {
			$minutos  = intdiv( $primera->getTimestamp() - $inicio->getTimestamp(), 60 );
			$tardanza = $minutos > $horario->toleranciaMin ? $minutos : 0;
		}

		$enEstado = $ultima ? max( 0, intdiv( $ahora->getTimestamp() - $ultima->marcadoUtc->getTimestamp(), 60 ) ) : 0;

		if ( ! $marcas ) {
			$limite    = $inicio ? $inicio->modify( '+' . $horario->toleranciaMin . ' minutes' ) : null;
			$situacion = match ( true ) {
				! $horario->laborable => self::LIBRE,
				$limite && $ahora > $limite => self::AUSENTE,
				default => self::POR_LLEGAR,
			};
		} else {
			$situacion = match ( $estado ) {
				Estado::TRABAJANDO     => $tardanza > 0 ? self::TARDE : self::PRESENTE,
				Estado::REFRIGERIO     => self::REFRIGERIO,
				Estado::FUERA_TEMPORAL => self::FUERA,
				Estado::FUERA          => self::SALIO,
			};
		}

		return new self(
			$situacion,
			$estado,
			$primera,
			$tardanza,
			$ultima?->marcadoUtc,
			$ultima && TipoMarca::SALIDA === $ultima->tipo ? $ultima->motivo : null,
			$enEstado,
			Estado::REFRIGERIO === $estado && $enEstado > $horario->refrigerioMin
		);
	}

	private static function momento( string $dia, ?string $hora, DateTimeZone $zona ): ?DateTimeImmutable {
		if ( null === $hora || '' === $hora ) {
			return null;
		}

		$valor = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $dia . ' ' . ( 5 === strlen( $hora ) ? $hora . ':00' : $hora ), $zona );

		return false === $valor ? null : $valor;
	}
}
