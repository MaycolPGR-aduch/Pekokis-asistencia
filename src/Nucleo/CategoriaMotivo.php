<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Agrupa los motivos de salida según el estado en que dejan al trabajador.
 */
enum CategoriaMotivo: string {
	case REFRIGERIO  = 'refrigerio';
	case TEMPORAL    = 'temporal';
	case FIN_JORNADA = 'fin_jornada';

	public function estadoResultante(): Estado {
		return match ( $this ) {
			self::REFRIGERIO  => Estado::REFRIGERIO,
			self::TEMPORAL    => Estado::FUERA_TEMPORAL,
			self::FIN_JORNADA => Estado::FUERA,
		};
	}
}
