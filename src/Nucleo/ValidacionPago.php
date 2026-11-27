<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Validación del honorario y las tarifas de una persona.
 */
final class ValidacionPago {
	/** Topes de sentido común para atrapar errores de tipeo: S/ 100 000 al mes, S/ 1 000 la hora. */
	private const MAX_HONORARIO = 10000000;
	private const MAX_TARIFA    = 100000;

	/**
	 * @param array<string, mixed> $datos
	 * @return array{honorario: ?int, tarifas: array<string, ?int>, vigente_desde: string, nota: string}
	 */
	public static function normalizarSueldo( array $datos ): array {
		$tarifas = array();

		foreach ( array_keys( Sueldo::TARIFAS ) as $tipo ) {
			$tarifas[ $tipo ] = Sueldo::aCentimos( (string) ( $datos[ 'tarifa_' . $tipo ] ?? '' ) );
		}

		return array(
			'honorario'     => Sueldo::aCentimos( (string) ( $datos['honorario'] ?? '' ) ),
			'tarifas'       => $tarifas,
			'modo'          => Sueldo::HORAS === ( $datos['modo'] ?? '' ) ? Sueldo::HORAS : Sueldo::FIJO,
			'aparte'        => Sueldo::HORAS === ( $datos['modo'] ?? '' ) ? array_values( array_intersect( array_keys( Sueldo::APARTE ), array_map( 'strval', (array) ( $datos['aparte'] ?? array() ) ) ) ) : array(),
			'vigente_desde' => trim( (string) ( $datos['vigente_desde'] ?? '' ) ),
			'nota'          => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $datos['nota'] ?? '' ) ) ), 0, 255 ),
		);
	}

	/**
	 * @param array<string, mixed> $s           Normalizado.
	 * @param string|null          $ultimoDesde Inicio del registro vigente actual, si hay.
	 * @return array<string, string>
	 */
	public static function erroresSueldo( array $s, ?string $ultimoDesde = null ): array {
		$errores = array();

		if ( null === $s['honorario'] || $s['honorario'] <= 0 ) {
			$errores['honorario'] = 'Escribe el honorario mensual en soles, por ejemplo 1800 o 1800.50.';
		} elseif ( $s['honorario'] > self::MAX_HONORARIO ) {
			$errores['honorario'] = 'El honorario parece demasiado alto. Revísalo.';
		}

		foreach ( Sueldo::TARIFAS as $tipo => $nombre ) {
			$valor = $s['tarifas'][ $tipo ];

			if ( null === $valor ) {
				$errores[ 'tarifa_' . $tipo ] = sprintf( 'Escribe la tarifa de "%s" en soles (puede ser 0).', mb_strtolower( $nombre ) );
			} elseif ( $valor > self::MAX_TARIFA ) {
				$errores[ 'tarifa_' . $tipo ] = 'La tarifa parece demasiado alta. Revísala.';
			}
		}

		if ( ! ValidacionTrabajador::esFecha( $s['vigente_desde'] ) ) {
			$errores['vigente_desde'] = 'Indica desde qué fecha rige.';
		} elseif ( null !== $ultimoDesde && $s['vigente_desde'] <= $ultimoDesde ) {
			$errores['vigente_desde'] = sprintf( 'Tiene que regir después del actual (desde el %s). Para corregir un error, anúlalo.', $ultimoDesde );
		}

		return $errores;
	}
}
