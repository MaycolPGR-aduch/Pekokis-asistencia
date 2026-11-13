<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Lo que dice el horario de un trabajador para un día concreto.
 */
final class HorarioDelDia {
	public function __construct(
		public readonly bool $laborable,
		public readonly ?string $entrada = null,   // "09:00" o "09:00:00", hora de Lima
		public readonly ?string $salida = null,
		public readonly int $toleranciaMin = 5,
		public readonly int $refrigerioMin = 60,
	) {
	}

	public static function libre(): self {
		return new self( false );
	}
}
