<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Las marcas que ocupan los cuatro pasos del día (entrada, inicio de comida,
 * fin de comida y salida), para mostrarlos y editarlos en "Registros y
 * revisión" (maquetas G4 y G4B). Las salidas temporales (encargo, salud…) no
 * son pasos: quedan aparte.
 */
final class PasosDelDia {
	public const ENTRADA       = 'entrada';
	public const INICIO_COMIDA = 'inicio_comida';
	public const FIN_COMIDA    = 'fin_comida';
	public const SALIDA        = 'salida';

	/** paso => [tipo de marca, motivo de la salida o null]. */
	public const TIPOS = array(
		self::ENTRADA       => array( TipoMarca::ENTRADA, null ),
		self::INICIO_COMIDA => array( TipoMarca::SALIDA, 'refrigerio' ),
		self::FIN_COMIDA    => array( TipoMarca::ENTRADA, null ),
		self::SALIDA        => array( TipoMarca::SALIDA, 'fin_jornada' ),
	);

	public const NOMBRES = array(
		self::ENTRADA       => 'Entrada',
		self::INICIO_COMIDA => 'Salida a comer',
		self::FIN_COMIDA    => 'Vuelta de comer',
		self::SALIDA        => 'Salida',
	);

	/**
	 * @param Marca[] $marcas Marcas efectivas del día.
	 * @return array<string, ?Marca> paso => marca (o null si falta).
	 */
	public static function de( array $marcas ): array {
		$pasos    = array_fill_keys( array_keys( self::TIPOS ), null );
		$enComida = false;

		foreach ( MaquinaEstados::ordenar( $marcas ) as $m ) {
			if ( TipoMarca::ENTRADA === $m->tipo ) {
				if ( null === $pasos[ self::ENTRADA ] ) {
					$pasos[ self::ENTRADA ] = $m;
				} elseif ( $enComida && null === $pasos[ self::FIN_COMIDA ] ) {
					$pasos[ self::FIN_COMIDA ] = $m;
				}

				$enComida = false;
				continue;
			}

			$categoria = $m->motivo?->categoria;

			if ( CategoriaMotivo::REFRIGERIO === $categoria && null === $pasos[ self::INICIO_COMIDA ] ) {
				$pasos[ self::INICIO_COMIDA ] = $m;
				$enComida                     = true;
			} elseif ( CategoriaMotivo::FIN_JORNADA === $categoria ) {
				$pasos[ self::SALIDA ] = $m; // La última salida de fin de jornada.
			}
		}

		return $pasos;
	}

	/**
	 * Marcas que no son pasos (salidas temporales y sus regresos).
	 *
	 * @param Marca[] $marcas
	 * @return Marca[]
	 */
	public static function otras( array $marcas ): array {
		$pasos = array_filter( self::de( $marcas ) );

		return array_values( array_filter( MaquinaEstados::ordenar( $marcas ), static fn( Marca $m ): bool => ! in_array( $m, $pasos, true ) ) );
	}
}
