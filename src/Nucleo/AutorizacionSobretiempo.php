<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Horas extra autorizadas por el jefe para un día: una franja y cómo se compensa
 * (pago con sobretasa o descanso). Solo el tiempo trabajado DENTRO de la franja
 * cuenta como sobretiempo; el resto fuera de horario queda "sin autorizar".
 */
final class AutorizacionSobretiempo {
	public const PAGO     = 'pago';
	public const DESCANSO = 'descanso';

	public const COMPENSACIONES = array(
		self::PAGO     => 'Pago con sobretasa',
		self::DESCANSO => 'Descanso compensatorio',
	);

	public function __construct(
		public readonly DateTimeImmutable $desde,
		public readonly DateTimeImmutable $hasta,
		public readonly string $compensacion = self::PAGO,
		public readonly ?int $id = null,
	) {
	}

	/** Franja en horas de Lima ("19:30" a "21:00") de un día; si "hasta" es menor, termina al día siguiente. */
	public static function deHoras( string $dia, string $desde, string $hasta, DateTimeZone $zona, string $compensacion = self::PAGO, ?int $id = null ): self {
		$inicio = new DateTimeImmutable( $dia . ' ' . substr( $desde, 0, 5 ), $zona );
		$fin    = new DateTimeImmutable( $dia . ' ' . substr( $hasta, 0, 5 ), $zona );

		if ( $fin <= $inicio ) {
			$fin = $fin->modify( '+1 day' );
		}

		return new self( $inicio, $fin, self::DESCANSO === $compensacion ? self::DESCANSO : self::PAGO, $id );
	}
}
