<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;

/**
 * Una marca ya registrada, con la hora del servidor en UTC.
 */
final class Marca {
	public function __construct(
		public readonly TipoMarca $tipo,
		public readonly DateTimeImmutable $marcadoUtc,
		public readonly ?Motivo $motivo = null,
		public readonly ?int $id = null,
	) {
	}

	public function estadoResultante(): Estado {
		if ( TipoMarca::ENTRADA === $this->tipo ) {
			return Estado::TRABAJANDO;
		}

		// Una salida sin motivo conocido (dato inconsistente) se trata como fin de jornada.
		return $this->motivo ? $this->motivo->categoria->estadoResultante() : Estado::FUERA;
	}
}
