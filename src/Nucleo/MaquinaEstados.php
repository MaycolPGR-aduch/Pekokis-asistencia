<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Reglas de transición entre estados. El estado sale siempre de la última marca
 * efectiva del día laboral: el día termina en la última salida registrada.
 */
final class MaquinaEstados {
	/**
	 * @param Marca[] $marcasDelDia Marcas efectivas del día laboral (ya sin las anuladas por correcciones).
	 */
	public static function estadoActual( array $marcasDelDia ): Estado {
		$marcas = self::ordenar( $marcasDelDia );
		$ultima = $marcas ? $marcas[ count( $marcas ) - 1 ] : null;

		return $ultima ? $ultima->estadoResultante() : Estado::FUERA;
	}

	public static function permite( Estado $estado, TipoMarca $tipo ): bool {
		return match ( $tipo ) {
			TipoMarca::ENTRADA => Estado::TRABAJANDO !== $estado,
			TipoMarca::SALIDA  => Estado::TRABAJANDO === $estado,
		};
	}

	/** @return Accion[] Botones que la app muestra en ese estado. */
	public static function acciones( Estado $estado ): array {
		return match ( $estado ) {
			Estado::FUERA                              => array( Accion::ENTRADA ),
			Estado::REFRIGERIO, Estado::FUERA_TEMPORAL => array( Accion::REGRESO ),
			Estado::TRABAJANDO                         => array( Accion::SALIDA_REFRIGERIO, Accion::SALIDA_TEMPORAL, Accion::FIN_JORNADA ),
		};
	}

	/**
	 * Orden cronológico; a igual hora, por id de inserción.
	 *
	 * @param Marca[] $marcas
	 * @return Marca[]
	 */
	public static function ordenar( array $marcas ): array {
		$marcas = array_values( $marcas );

		usort(
			$marcas,
			static function ( Marca $a, Marca $b ): int {
				return array( $a->marcadoUtc->getTimestamp(), $a->id ?? PHP_INT_MAX )
					<=> array( $b->marcadoUtc->getTimestamp(), $b->id ?? PHP_INT_MAX );
			}
		);

		return $marcas;
	}
}
