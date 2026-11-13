<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Cadena de hashes para las tablas que solo admiten inserciones (marcas y auditoría).
 *
 * Cada fila guarda hash = SHA-256(hash_anterior | datos). Si alguien edita o borra
 * una fila directamente en la base de datos, la cadena se rompe desde ese punto.
 */
final class CadenaHash {
	public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

	/** @param array<string, mixed> $datos Campos de negocio de la fila (sin id, hash_anterior ni hash). */
	public static function calcular( string $hashAnterior, array $datos ): string {
		return hash( 'sha256', $hashAnterior . '|' . self::canonico( $datos ) );
	}

	/**
	 * JSON estable: claves ordenadas y escalares como texto, para que una fila
	 * recién armada en PHP y la misma fila leída de MySQL den el mismo hash.
	 *
	 * @param array<string, mixed> $datos
	 */
	public static function canonico( array $datos ): string {
		return json_encode( self::normalizar( $datos ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	}

	/**
	 * Revisa una cadena completa en orden de inserción.
	 *
	 * @param array<int, array{hash_anterior: string, hash: string, datos: array<string, mixed>}> $filas
	 * @return int|null Posición de la primera fila rota, o null si la cadena está íntegra.
	 */
	public static function primeraRota( array $filas, string $inicio = self::GENESIS ): ?int {
		$esperado = $inicio;

		foreach ( array_values( $filas ) as $posicion => $fila ) {
			if ( ! hash_equals( $esperado, $fila['hash_anterior'] )
				|| ! hash_equals( self::calcular( $fila['hash_anterior'], $fila['datos'] ), $fila['hash'] ) ) {
				return $posicion;
			}

			$esperado = $fila['hash'];
		}

		return null;
	}

	private static function normalizar( mixed $valor ): mixed {
		if ( is_array( $valor ) ) {
			ksort( $valor );

			return array_map( array( self::class, 'normalizar' ), $valor );
		}

		return match ( true ) {
			null === $valor   => null,
			is_bool( $valor ) => $valor ? '1' : '0',
			default           => (string) $valor,
		};
	}
}
