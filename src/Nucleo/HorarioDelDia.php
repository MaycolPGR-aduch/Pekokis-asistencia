<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Lo que dice el horario de un trabajador para un día concreto.
 *
 * $descanso explica por qué no es laborable cuando el horario sí lo sería
 * (por ejemplo, "Feriado: Navidad"). $feriado distingue un feriado o día no
 * laborable del calendario (se paga con la tarifa de feriado) de un día libre
 * de su horario.
 */
final class HorarioDelDia {
	public function __construct(
		public readonly bool $laborable,
		public readonly ?string $entrada = null,   // "09:00" o "09:00:00", hora de Lima
		public readonly ?string $salida = null,
		public readonly int $toleranciaMin = 5,
		public readonly int $refrigerioMin = 60,
		public readonly string $descanso = '',
		public readonly bool $feriado = false,
	) {
	}

	public static function libre( string $descanso = '', bool $feriado = false ): self {
		return new self( false, null, null, 5, 60, $descanso, $feriado );
	}
}
