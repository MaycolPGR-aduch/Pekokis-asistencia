<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\CadenaHash;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Inserta filas en las tablas de solo inserción (marcas y auditoría) manteniendo
 * la cadena de hashes. Un candado de MySQL evita que dos inserciones simultáneas
 * lean el mismo hash anterior y abran dos ramas.
 */
final class InsercionEncadenada {
	private const COLUMNAS_FUERA_DEL_HASH = array( 'id', 'hash_anterior', 'hash' );

	/**
	 * @param array<string, mixed> $datos Campos de negocio, sin id ni hashes.
	 * @return int Id insertado.
	 * @throws RuntimeException Si no se obtiene el candado o falla la inserción.
	 */
	public static function insertar( string $tabla, array $datos ): int {
		global $wpdb;

		$nombre  = Tablas::nombre( $tabla );
		$candado = 'pka_cadena_' . $tabla;

		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $candado ) ) ) {
			throw new RuntimeException( 'No se pudo bloquear la tabla ' . $tabla . '.' );
		}

		try {
			$anterior = (string) $wpdb->get_var( "SELECT hash FROM {$nombre} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$anterior = '' === $anterior ? CadenaHash::GENESIS : $anterior;

			$fila = $datos + array(
				'hash_anterior' => $anterior,
				'hash'          => CadenaHash::calcular( $anterior, $datos ),
			);

			if ( false === $wpdb->insert( $nombre, $fila ) ) {
				throw new RuntimeException( 'No se pudo guardar en ' . $tabla . ': ' . $wpdb->last_error );
			}

			return (int) $wpdb->insert_id;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $candado ) );
		}
	}

	/**
	 * Revisa la cadena completa de una tabla.
	 *
	 * @return int|null Id de la primera fila rota, o null si está íntegra.
	 */
	public static function verificar( string $tabla ): ?int {
		global $wpdb;

		$nombre   = Tablas::nombre( $tabla );
		$esperado = CadenaHash::GENESIS;
		$desde    = 0;

		do {
			$filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$nombre} WHERE id > %d ORDER BY id LIMIT 500", $desde ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( $filas as $fila ) {
				$datos = array_diff_key( $fila, array_flip( self::COLUMNAS_FUERA_DEL_HASH ) );

				if ( null !== CadenaHash::primeraRota( array( array( 'hash_anterior' => $fila['hash_anterior'], 'hash' => $fila['hash'], 'datos' => $datos ) ), $esperado ) ) {
					return (int) $fila['id'];
				}

				$esperado = $fila['hash'];
				$desde    = (int) $fila['id'];
			}
		} while ( count( $filas ) === 500 );

		return null;
	}
}
