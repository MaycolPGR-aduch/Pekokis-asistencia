<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Estado del trabajador dentro de su día laboral.
 */
enum Estado: string {
	case FUERA          = 'fuera';
	case TRABAJANDO     = 'trabajando';
	case REFRIGERIO     = 'refrigerio';
	case FUERA_TEMPORAL = 'fuera_temporal';

	public function etiqueta(): string {
		return match ( $this ) {
			self::FUERA          => 'Fuera',
			self::TRABAJANDO     => 'Trabajando',
			self::REFRIGERIO     => 'En refrigerio',
			self::FUERA_TEMPORAL => 'Fuera temporalmente',
		};
	}

	/** Frase para mensajes: "Esa marca no corresponde: estás en refrigerio." */
	public function frase(): string {
		return match ( $this ) {
			self::FUERA          => 'estás fuera',
			self::TRABAJANDO     => 'estás trabajando',
			self::REFRIGERIO     => 'estás en refrigerio',
			self::FUERA_TEMPORAL => 'estás fuera temporalmente',
		};
	}
}
