<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Botones que ve el trabajador en la app según su estado.
 */
enum Accion: string {
	case ENTRADA           = 'entrada';
	case REGRESO           = 'regreso';
	case SALIDA_REFRIGERIO = 'salida_refrigerio';
	case SALIDA_TEMPORAL   = 'salida_temporal';
	case FIN_JORNADA       = 'fin_jornada';

	public function etiqueta(): string {
		return match ( $this ) {
			self::ENTRADA           => 'Marcar entrada',
			self::REGRESO           => 'Volver',
			self::SALIDA_REFRIGERIO => 'Salir a refrigerio',
			self::SALIDA_TEMPORAL   => 'Salida temporal',
			self::FIN_JORNADA       => 'Terminar jornada',
		};
	}

	public function tipo(): TipoMarca {
		return match ( $this ) {
			self::ENTRADA, self::REGRESO => TipoMarca::ENTRADA,
			default                      => TipoMarca::SALIDA,
		};
	}

	/** Categoría de motivo que corresponde a la acción; null en las entradas. */
	public function categoria(): ?CategoriaMotivo {
		return match ( $this ) {
			self::SALIDA_REFRIGERIO => CategoriaMotivo::REFRIGERIO,
			self::SALIDA_TEMPORAL   => CategoriaMotivo::TEMPORAL,
			self::FIN_JORNADA       => CategoriaMotivo::FIN_JORNADA,
			default                 => null,
		};
	}
}
