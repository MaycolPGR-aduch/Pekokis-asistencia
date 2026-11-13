<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Asigna cada momento a un día laboral en hora de Lima, con una hora de corte:
 * lo marcado antes de las 04:00 pertenece al día anterior.
 */
final class DiaLaboral {
	public function __construct(
		private readonly DateTimeZone $zona,
		private readonly int $horaCorte = 4,
	) {
	}

	/** Día laboral (Y-m-d) al que pertenece un momento. */
	public function de( DateTimeImmutable $momento ): string {
		return $momento->setTimezone( $this->zona )
			->modify( sprintf( '-%d hours', $this->horaCorte ) )
			->format( 'Y-m-d' );
	}

	/**
	 * Rango UTC [inicio, fin) del día laboral.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 */
	public function rangoUtc( string $dia ): array {
		$utc    = new DateTimeZone( 'UTC' );
		$inicio = ( new DateTimeImmutable( $dia . ' 00:00:00', $this->zona ) )
			->modify( sprintf( '+%d hours', $this->horaCorte ) );

		return array( $inicio->setTimezone( $utc ), $inicio->modify( '+1 day' )->setTimezone( $utc ) );
	}
}
