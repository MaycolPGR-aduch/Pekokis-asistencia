<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\CatalogoMotivos;
use Pekokis\Asistencia\Nucleo\Marca;
use Pekokis\Asistencia\Nucleo\Motivo;
use Pekokis\Asistencia\Nucleo\TipoMarca;

/**
 * Atajos para armar datos de prueba.
 */
final class Fabrica {
	public const SECRETO = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** Hora de Lima ("2026-11-13 09:02") convertida a UTC. */
	public static function lima( string $fechaHora ): DateTimeImmutable {
		return ( new DateTimeImmutable( $fechaHora, new DateTimeZone( 'America/Lima' ) ) )
			->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	public static function motivo( string $clave ): Motivo {
		$motivo = CatalogoMotivos::porClave( $clave );

		if ( null === $motivo ) {
			throw new \LogicException( 'Motivo desconocido: ' . $clave );
		}

		return $motivo;
	}

	public static function entrada( string $horaLima, ?int $id = null ): Marca {
		return new Marca( TipoMarca::ENTRADA, self::lima( $horaLima ), null, $id );
	}

	public static function salida( string $horaLima, string $motivo, ?int $id = null ): Marca {
		return new Marca( TipoMarca::SALIDA, self::lima( $horaLima ), self::motivo( $motivo ), $id );
	}
}
