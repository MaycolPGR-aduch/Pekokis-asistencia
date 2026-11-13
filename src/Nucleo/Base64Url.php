<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Base64 apto para URLs y cookies (sin +, / ni =).
 */
final class Base64Url {
	public static function codificar( string $binario ): string {
		return rtrim( strtr( base64_encode( $binario ), '+/', '-_' ), '=' );
	}

	/** @return string|null Binario, o null si el texto no es base64url válido. */
	public static function decodificar( string $texto ): ?string {
		if ( ! preg_match( '/^[A-Za-z0-9_-]*$/', $texto ) || 1 === strlen( $texto ) % 4 ) {
			return null;
		}

		$binario = base64_decode( strtr( $texto, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $texto ) % 4 ) % 4 ), true );

		return false === $binario ? null : $binario;
	}

	/** Token aleatorio apto para URLs. */
	public static function aleatorio( int $bytes = 24 ): string {
		return self::codificar( random_bytes( $bytes ) );
	}
}
