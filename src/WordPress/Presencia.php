<?php

namespace Pekokis\Asistencia\WordPress;

use InvalidArgumentException;
use Pekokis\Asistencia\Nucleo\ContextoPresencia;
use Pekokis\Asistencia\Nucleo\PasePresencia;
use Pekokis\Asistencia\Nucleo\RedAutorizada;

defined( 'ABSPATH' ) || exit;

/**
 * Arma el contexto de presencia de la petición: WiFi del taller (IP) y pase del QR.
 */
final class Presencia {
	public const COOKIE_PASE = 'pka_presencia';

	public static function contexto(): ContextoPresencia {
		return new ContextoPresencia( self::enRedDelTaller(), self::pantallaDelPase() );
	}

	public static function enRedDelTaller(): bool {
		$ip = Peticion::ip();

		return '' !== $ip && RedAutorizada::algunaContiene( self::redes(), $ip );
	}

	/** @return RedAutorizada[] */
	public static function redes(): array {
		$redes = array();

		foreach ( self::filasRedes( true ) as $fila ) {
			try {
				$redes[] = RedAutorizada::desde( $fila['red'] );
			} catch ( InvalidArgumentException $e ) {
				continue; // Una fila inválida no debe tumbar la validación de las demás.
			}
		}

		return $redes;
	}

	/** @return array<int, array<string, mixed>> */
	public static function filasRedes( bool $soloActivas = false ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'redes' );
		$where = $soloActivas ? 'WHERE activa = 1' : '';

		return $wpdb->get_results( "SELECT * FROM {$tabla} {$where} ORDER BY activa DESC, id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function pases(): PasePresencia {
		return new PasePresencia( Secretos::clave(), max( 60, (int) Ajustes::todos()['pase_minutos'] * MINUTE_IN_SECONDS ) );
	}

	/** Pantalla activa del pase de presencia vigente en este navegador, o null. */
	private static function pantallaDelPase(): ?int {
		global $wpdb;

		$pase = Peticion::cookie( self::COOKIE_PASE );

		if ( '' === $pase ) {
			return null;
		}

		$pantalla = self::pases()->validar( $pase, time() );

		if ( null === $pantalla ) {
			return null;
		}

		$tabla  = Tablas::nombre( 'pantallas' );
		$activa = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tabla} WHERE id = %d AND activa = 1", $pantalla ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $activa ? $pantalla : null;
	}
}
