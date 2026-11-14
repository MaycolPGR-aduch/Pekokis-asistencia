<?php

namespace Pekokis\Asistencia\Nucleo;

use InvalidArgumentException;

/**
 * Red desde la que se acepta la presencia por WiFi: una IP exacta o un rango
 * (CIDR). En IPv6 se usa el prefijo de la red del taller (/56 o /64).
 */
final class RedAutorizada {
	private function __construct(
		private readonly string $binario,
		private readonly int $bits,
		public readonly string $texto,
	) {
	}

	/** @throws InvalidArgumentException Si el texto no es una IP o un rango válido. */
	public static function desde( string $red ): self {
		$red   = trim( $red );
		$bits  = null;
		$ip    = $red;
		$barra = strpos( $red, '/' );

		if ( false !== $barra ) {
			$ip   = substr( $red, 0, $barra );
			$bits = substr( $red, $barra + 1 );
		}

		$binario = self::binario( $ip );

		if ( null === $binario ) {
			throw new InvalidArgumentException( sprintf( '"%s" no es una IP válida.', $red ) );
		}

		$maximo = strlen( $binario ) * 8;

		if ( null === $bits ) {
			$bits = $maximo;
		} elseif ( ctype_digit( $bits ) && (int) $bits <= $maximo ) {
			$bits = (int) $bits;
		} else {
			throw new InvalidArgumentException( sprintf( '"%s" no es un rango válido.', $red ) );
		}

		// El texto guarda la dirección de red: "2800:200:e840:1a42:abcd::1/64" → "2800:200:e840:1a42::/64".
		$texto = inet_ntop( self::prefijo( $binario, $bits ) ) . ( $bits < $maximo ? '/' . $bits : '' );

		return new self( self::prefijo( $binario, $bits ), $bits, $texto );
	}

	public function contiene( string $ip ): bool {
		$binario = self::binario( $ip );

		if ( null === $binario || strlen( $binario ) !== strlen( $this->binario ) ) {
			return false;
		}

		return hash_equals( $this->binario, self::prefijo( $binario, $this->bits ) );
	}

	/** @param RedAutorizada[] $redes */
	public static function algunaContiene( array $redes, string $ip ): bool {
		foreach ( $redes as $red ) {
			if ( $red->contiene( $ip ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IP compartida por el proveedor (CGNAT, 100.64.0.0/10). Si la IP WAN del router
	 * está en este rango, el WiFi no sirve como prueba de presencia.
	 */
	public static function esCgnat( string $ip ): bool {
		return self::desde( '100.64.0.0/10' )->contiene( $ip );
	}

	/** Binario de 4 o 16 bytes; una IPv4 mapeada en IPv6 (::ffff:a.b.c.d) se trata como IPv4. */
	private static function binario( string $ip ): ?string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$binario = inet_pton( $ip );

		if ( false === $binario ) {
			return null;
		}

		if ( 16 === strlen( $binario ) && str_starts_with( $binario, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return substr( $binario, 12 );
		}

		return $binario;
	}

	/** Deja solo los primeros $bits bits y pone en cero el resto. */
	private static function prefijo( string $binario, int $bits ): string {
		$resultado = '';

		for ( $i = 0, $largo = strlen( $binario ); $i < $largo; $i++ ) {
			$bitsDelByte = max( 0, min( 8, $bits - ( $i * 8 ) ) );
			$mascara     = ( 0xFF << ( 8 - $bitsDelByte ) ) & 0xFF;
			$resultado  .= chr( ord( $binario[ $i ] ) & $mascara );
		}

		return $resultado;
	}
}
