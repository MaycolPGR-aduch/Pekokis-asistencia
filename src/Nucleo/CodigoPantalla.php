<?php

namespace Pekokis\Asistencia\Nucleo;

use InvalidArgumentException;

/**
 * Código de la pantalla del taller: cambia cada 30 segundos.
 *
 * - El QR lleva un token "pantalla.ventana.firma".
 * - Debajo se muestra un código de 6 dígitos por si la cámara no lee el QR.
 *
 * Se aceptan la ventana actual y la anterior (máximo 60 s), para que nadie
 * falle por escanear o escribir justo cuando cambia.
 */
final class CodigoPantalla {
	public const SEGUNDOS_VENTANA = 30;

	public function __construct( private readonly string $secreto ) {
		if ( strlen( $secreto ) < 32 ) {
			throw new InvalidArgumentException( 'El secreto del QR debe tener al menos 32 caracteres.' );
		}
	}

	public static function ventana( int $timestamp ): int {
		return intdiv( $timestamp, self::SEGUNDOS_VENTANA );
	}

	public static function segundosRestantes( int $timestamp ): int {
		return self::SEGUNDOS_VENTANA - ( $timestamp % self::SEGUNDOS_VENTANA );
	}

	public function token( int $pantalla, int $ventana ): string {
		return $pantalla . '.' . $ventana . '.' . $this->firma( $pantalla, $ventana );
	}

	public function codigo( int $pantalla, int $ventana ): string {
		$hmac   = hash_hmac( 'sha256', 'num|' . $pantalla . '|' . $ventana, $this->secreto, true );
		$inicio = ord( $hmac[31] ) & 0x0F;
		$numero = ( unpack( 'N', substr( $hmac, $inicio, 4 ) )[1] & 0x7FFFFFFF ) % 1000000;

		return str_pad( (string) $numero, 6, '0', STR_PAD_LEFT );
	}

	/** @return int|null Pantalla que generó el token, o null si no es válido o ya venció. */
	public function validarToken( string $token, int $ahora ): ?int {
		if ( ! preg_match( '/^(\d{1,10})\.(\d{1,12})\.([A-Za-z0-9_-]{22})$/', $token, $partes ) ) {
			return null;
		}

		$pantalla = (int) $partes[1];
		$ventana  = (int) $partes[2];

		if ( ! $this->ventanaVigente( $ventana, $ahora ) ) {
			return null;
		}

		return hash_equals( $this->firma( $pantalla, $ventana ), $partes[3] ) ? $pantalla : null;
	}

	/**
	 * @param int[] $pantallas Pantallas activas.
	 * @return int|null Pantalla que muestra ese código, o null si no coincide con ninguna.
	 */
	public function validarCodigo( string $codigo, array $pantallas, int $ahora ): ?int {
		if ( ! preg_match( '/^\d{6}$/', $codigo ) ) {
			return null;
		}

		$actual = self::ventana( $ahora );

		foreach ( $pantallas as $pantalla ) {
			foreach ( array( $actual, $actual - 1 ) as $ventana ) {
				if ( hash_equals( $this->codigo( (int) $pantalla, $ventana ), $codigo ) ) {
					return (int) $pantalla;
				}
			}
		}

		return null;
	}

	private function ventanaVigente( int $ventana, int $ahora ): bool {
		$actual = self::ventana( $ahora );

		return $ventana === $actual || $ventana === $actual - 1;
	}

	private function firma( int $pantalla, int $ventana ): string {
		$hmac = hash_hmac( 'sha256', 'qr|' . $pantalla . '|' . $ventana, $this->secreto, true );

		return Base64Url::codificar( substr( $hmac, 0, 16 ) );
	}
}
