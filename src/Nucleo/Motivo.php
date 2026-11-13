<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Motivo de una salida y sus reglas. RR.HH. puede cambiarlas desde el panel.
 */
final class Motivo {
	public function __construct(
		public readonly string $clave,
		public readonly string $nombre,
		public readonly CategoriaMotivo $categoria,
		public readonly bool $cuentaComoTrabajado,
		public readonly bool $requiereAprobacion,
		public readonly bool $sinPresencia,
		public readonly bool $activo = true,
		public readonly int $orden = 0,
	) {
	}
}
