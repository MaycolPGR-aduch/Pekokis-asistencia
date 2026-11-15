<?php

namespace Pekokis\Asistencia\Nucleo;

use InvalidArgumentException;

/**
 * Código de la pantalla del taller: cambia cada cierto tiempo (30 segundos por
 * defecto; las maquetas piden 30 minutos, y se elige en Ajustes).
 *
 * - El QR lleva un token "pantalla.ventana.firma".
 * - Debajo se muestra un código de 6 dígitos por si la cámara no lee el QR.
 *
 * También se acepta la ventana anterior durante el primer minuto de la nueva
 * (con ventanas de 30 s, siempre), para que nadie falle por escanear o escribir
 * justo cuando cambia.
 */
final class CodigoPantalla {
	public const SEGUNDOS_VENTANA = 30;

	/** Periodos que se pueden elegir en Ajustes: segundos => texto. */
	public const PERIODOS = array(
		30   => '30 segundos',
		300  => '5 minutos',
		900  => '15 minutos',
		1800 => '30 minutos',
	);

	private const GRACIA = 60;

	public function __construct(
		private readonly string $secreto,
		private readonly int $segundos = self::SEGUNDOS_VENTANA,
	) {
		if ( strlen( $secreto ) < 32 ) {
			throw new InvalidArgumentException( 'El secreto del QR debe tener al menos 32 caracteres.' );
		}

		if ( $segundos < 10 ) {
			throw new InvalidArgumentException( 'El QR debe durar al menos 10 segundos.' );
		}
	}

	public static function ventana( int $timestamp, int $segundos = self::SEGUNDOS_VENTANA ): int {
		return intdiv( $timestamp, $segundos );
	}

	public static function segundosRestantes( int $timestamp, int $segundos = self::SEGUNDOS_VENTANA ): int {
		return $segundos - ( $timestamp % $segundos );
	}

	public function duracion(): int {
		return $this->segundos;
	}

	public function ventanaActual( int $ahora ): int {
		return self::ventana( $ahora, $this->segundos );
	}

	public function restantes( int $ahora ): int {
		return self::segundosRestantes( $ahora, $this->segundos );
	}

	public function token( int $pantalla, int $ventana ): string {
		return $pantalla . '.' . $ventana . '.' . $this->firma( $pantalla, $ventana );
	}

	public function codigo( int $pantalla, int $ventana ): string {
		$base   = self::SEGUNDOS_VENTANA === $this->segundos ? 'num|' . $pantalla . '|' . $ventana : 'num|' . $this->segundos . '|' . $pantalla . '|' . $ventana;
		$hmac   = hash_hmac( 'sha256', $base, $this->secreto, true );
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

		if ( ! in_array( $ventana, $this->ventanasVigentes( $ahora ), true ) ) {
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

		foreach ( $pantallas as $pantalla ) {
			foreach ( $this->ventanasVigentes( $ahora ) as $ventana ) {
				if ( hash_equals( $this->codigo( (int) $pantalla, $ventana ), $codigo ) ) {
					return (int) $pantalla;
				}
			}
		}

		return null;
	}

	/** @return int[] La ventana actual y, en su primer minuto, la anterior. */
	private function ventanasVigentes( int $ahora ): array {
		$actual       = $this->ventanaActual( $ahora );
		$transcurrido = $ahora - $actual * $this->segundos;

		return $transcurrido < min( $this->segundos, self::GRACIA ) ? array( $actual, $actual - 1 ) : array( $actual );
	}

	private function firma( int $pantalla, int $ventana ): string {
		// El periodo va en la firma: un token de 30 s no sirve como si fuera de 30 min.
		$base = self::SEGUNDOS_VENTANA === $this->segundos ? 'qr|' . $pantalla . '|' . $ventana : 'qr|' . $this->segundos . '|' . $pantalla . '|' . $ventana;
		$hmac = hash_hmac( 'sha256', $base, $this->secreto, true );

		return Base64Url::codificar( substr( $hmac, 0, 16 ) );
	}
}
