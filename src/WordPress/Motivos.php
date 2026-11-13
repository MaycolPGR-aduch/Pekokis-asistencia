<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\CategoriaMotivo;
use Pekokis\Asistencia\Nucleo\Motivo;

defined( 'ABSPATH' ) || exit;

/**
 * Motivos de salida guardados en la base de datos (RR.HH. puede ajustar sus reglas).
 */
final class Motivos {
	/** @var array<string, Motivo>|null */
	private static ?array $cache = null;

	/** @return array<string, Motivo> clave => motivo, incluidos los inactivos. */
	public static function todos(): array {
		global $wpdb;

		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$tabla       = Tablas::nombre( 'motivos' );
		self::$cache = array();

		foreach ( $wpdb->get_results( "SELECT * FROM {$tabla} ORDER BY orden, id", ARRAY_A ) as $fila ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$categoria = CategoriaMotivo::tryFrom( $fila['categoria'] );

			if ( null === $categoria ) {
				continue;
			}

			self::$cache[ $fila['clave'] ] = new Motivo(
				$fila['clave'],
				$fila['nombre'],
				$categoria,
				(bool) $fila['cuenta_como_trabajado'],
				(bool) $fila['requiere_aprobacion'],
				(bool) $fila['sin_presencia'],
				(bool) $fila['activo'],
				(int) $fila['orden']
			);
		}

		return self::$cache;
	}

	public static function porClave( ?string $clave ): ?Motivo {
		return null === $clave ? null : ( self::todos()[ $clave ] ?? null );
	}

	/** @return Motivo[] Motivos activos de una categoría, en orden. */
	public static function deCategoria( CategoriaMotivo $categoria ): array {
		return array_values(
			array_filter(
				self::todos(),
				static fn( Motivo $m ): bool => $m->activo && $m->categoria === $categoria
			)
		);
	}
}
