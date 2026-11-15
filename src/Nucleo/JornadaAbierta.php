<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;

/**
 * Jornadas que pasan la medianoche (maquetas T2F, T2G, T3E y T3F).
 *
 * La jornada no se corta a las 23:59 ni a la hora de corte: sigue abierta hasta
 * que la persona marca su salida, y sus horas cuentan en el día en que empezó.
 * Solo se continúa la jornada de AYER, y solo si hoy todavía no hay marcas y no
 * pidió una corrección para ayer ("Salí ayer y olvidé marcar").
 */
final class JornadaAbierta {
	/** @param Marca[] $marcas */
	public static function sigueAbierta( array $marcas ): bool {
		return [] !== $marcas && Estado::FUERA !== MaquinaEstados::estadoActual( MaquinaEstados::ordenar( $marcas ) );
	}

	/**
	 * Día laboral al que pertenece lo que se marque ahora.
	 *
	 * @param Marca[] $marcasAyer
	 * @param Marca[] $marcasHoy
	 */
	public static function diaDeTrabajo( string $hoy, array $marcasAyer, array $marcasHoy, bool $ayerConSolicitud ): string {
		if ( [] !== $marcasHoy || $ayerConSolicitud || ! self::sigueAbierta( $marcasAyer ) ) {
			return $hoy;
		}

		return ( new DateTimeImmutable( $hoy ) )->modify( '-1 day' )->format( 'Y-m-d' );
	}

	/**
	 * Minutos desde la primera entrada de la jornada hasta un momento (0 si no hay entrada).
	 *
	 * @param Marca[] $marcas
	 */
	public static function minutosDesdeLaEntrada( array $marcas, DateTimeImmutable $hasta ): int {
		foreach ( MaquinaEstados::ordenar( $marcas ) as $m ) {
			if ( TipoMarca::ENTRADA === $m->tipo ) {
				return max( 0, intdiv( $hasta->getTimestamp() - $m->marcadoUtc->getTimestamp(), 60 ) );
			}
		}

		return 0;
	}

	/** ¿Cerrar ahora la jornada la deja por encima del límite (16 h por defecto)? */
	public static function esLarga( array $marcas, DateTimeImmutable $salida, int $horasLimite ): bool {
		return $horasLimite > 0 && self::minutosDesdeLaEntrada( $marcas, $salida ) > $horasLimite * 60;
	}
}
