<?php

namespace Pekokis\Asistencia\Nucleo;

use InvalidArgumentException;

/**
 * Detecta que la IP del taller probablemente cambió: varias marcas con QR válido
 * (la persona estaba frente a la pantalla) llegan desde una misma red que no está
 * autorizada, de más de un trabajador.
 *
 * Nunca agrega la red sola: solo la sugiere. Podría ser la red de datos móviles de
 * un operador, que comparten miles de personas.
 *
 * En IPv6 cada celular usa su propia dirección, pero todas comparten el prefijo /64
 * de la red del WiFi; por eso se agrupa por ese prefijo.
 */
final class DeteccionRedNueva {
	/**
	 * @param array<int, array{ip: string, trabajador: int}> $marcasQr Marcas aceptadas por QR.
	 * @param RedAutorizada[]                               $redes    Redes ya autorizadas.
	 * @return array<int, array{red: string, marcas: int, trabajadores: int, cgnat: bool}>
	 */
	public static function candidatas( array $marcasQr, array $redes, int $minMarcas = 3, int $minTrabajadores = 2 ): array {
		$grupos = array();

		foreach ( $marcasQr as $marca ) {
			$red = self::claveDeRed( $marca['ip'] );

			if ( null === $red || RedAutorizada::algunaContiene( $redes, $marca['ip'] ) ) {
				continue;
			}

			$grupos[ $red ]['marcas']                               = ( $grupos[ $red ]['marcas'] ?? 0 ) + 1;
			$grupos[ $red ]['trabajadores'][ $marca['trabajador'] ] = true;
			$grupos[ $red ]['ip']                                   = $marca['ip'];
		}

		$candidatas = array();

		foreach ( $grupos as $red => $g ) {
			if ( $g['marcas'] >= $minMarcas && count( $g['trabajadores'] ) >= $minTrabajadores ) {
				$candidatas[] = array(
					'red'          => $red,
					'marcas'       => $g['marcas'],
					'trabajadores' => count( $g['trabajadores'] ),
					'cgnat'        => RedAutorizada::esCgnat( $g['ip'] ),
				);
			}
		}

		usort( $candidatas, static fn( $a, $b ) => $b['marcas'] <=> $a['marcas'] );

		return $candidatas;
	}

	/** IPv4 tal cual; IPv6 como su prefijo /64. Null si no es una IP. */
	public static function claveDeRed( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		try {
			return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && ! str_starts_with( strtolower( $ip ), '::ffff:' )
				? RedAutorizada::desde( $ip . '/64' )->texto
				: RedAutorizada::desde( $ip )->texto;
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}
