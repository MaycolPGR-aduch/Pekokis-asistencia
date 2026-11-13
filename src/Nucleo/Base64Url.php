<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Base64 apto para URLs y cookies (sin +, / ni =).
 */
final class Base64Url {
	public static function codificar( string $binario ): string {
		return rtrim( strtr( base64_encode( $binario ), '+/', '-_' ), '=' );
	}
}
