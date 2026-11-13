<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;

/**
 * Totales simples de un día para el registro mensual: primera entrada, última
 * salida, tiempo dentro del taller y tiempo de refrigerio.
 *
 * El cálculo de horas pagables (motivos que cuentan como trabajados, horas extra,
 * feriados) llega en la fase 2; aquí solo se mide lo que dicen las marcas.
 */
final class TotalesDia {
	private function __construct(
		public readonly ?DateTimeImmutable $primeraEntrada,
		public readonly ?DateTimeImmutable $ultimaSalida,
		public readonly int $minutosDentro,
		public readonly int $minutosRefrigerio,
		public readonly int $salidasTemporales,
		public readonly bool $incompleto,
	) {
	}

	/** @param Marca[] $marcasDelDia */
	public static function calcular( array $marcasDelDia ): self {
		$marcas     = MaquinaEstados::ordenar( $marcasDelDia );
		$primera    = null;
		$ultima     = null;
		$dentro     = 0;
		$refrigerio = 0;
		$temporales = 0;
		$abierta    = null; // Entrada que abrió el tramo actual.
		$salida     = null; // Última salida, para medir el refrigerio.

		foreach ( $marcas as $marca ) {
			if ( TipoMarca::ENTRADA === $marca->tipo ) {
				$primera ??= $marca->marcadoUtc;

				if ( $salida && CategoriaMotivo::REFRIGERIO === $salida->motivo?->categoria ) {
					$refrigerio += self::minutos( $salida->marcadoUtc, $marca->marcadoUtc );
				}

				$abierta = $marca;
				$salida  = null;
				continue;
			}

			if ( $abierta ) {
				$dentro += self::minutos( $abierta->marcadoUtc, $marca->marcadoUtc );
			}

			if ( CategoriaMotivo::TEMPORAL === $marca->motivo?->categoria ) {
				++$temporales;
			}

			$ultima  = $marca->marcadoUtc;
			$abierta = null;
			$salida  = $marca;
		}

		return new self( $primera, $ultima, $dentro, $refrigerio, $temporales, null !== $abierta );
	}

	private static function minutos( DateTimeImmutable $desde, DateTimeImmutable $hasta ): int {
		return max( 0, intdiv( $hasta->getTimestamp() - $desde->getTimestamp(), 60 ) );
	}
}
