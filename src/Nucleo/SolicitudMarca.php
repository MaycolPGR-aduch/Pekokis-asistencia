<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Lo que el trabajador pide marcar. El motivo solo va en las salidas.
 */
final class SolicitudMarca {
	public function __construct(
		public readonly TipoMarca $tipo,
		public readonly ?Motivo $motivo = null,
	) {
	}
}
