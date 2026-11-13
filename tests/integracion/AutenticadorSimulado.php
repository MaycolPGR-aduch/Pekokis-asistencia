<?php
/**
 * Autenticador WebAuthn por software (clave P-256) para las pruebas de integración.
 * Produce las mismas estructuras que un celular: attestationObject "none" y firmas ES256.
 */

final class AutenticadorSimulado {
	private OpenSSLAsymmetricKey $clave;
	private string $idCredencial;
	private int $contador = 0;

	public function __construct( private readonly string $rpId, private readonly string $origen ) {
		$opciones = array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' );
		// En Windows, OpenSSL necesita su openssl.cnf (PHP lo trae en extras/ssl).
		$cnf = dirname( PHP_BINARY ) . '/extras/ssl/openssl.cnf';

		if ( is_readable( $cnf ) ) {
			$opciones['config'] = $cnf;
		}

		$clave = openssl_pkey_new( $opciones );

		if ( false === $clave ) {
			throw new RuntimeException( 'No se pudo crear la clave de prueba: ' . openssl_error_string() );
		}

		$this->clave        = $clave;
		$this->idCredencial = random_bytes( 32 );
	}

	/** Respuesta a navigator.credentials.create() en base64url. */
	public function crear( string $desafioB64 ): array {
		$cliente  = $this->clientData( 'webauthn.create', $desafioB64 );
		$detalles = openssl_pkey_get_details( $this->clave );
		$cose     = self::cbor(
			array(
				1  => 2,
				3  => -7,
				-1 => 1,
				-2 => array( 'bytes' => str_pad( $detalles['ec']['x'], 32, "\0", STR_PAD_LEFT ) ),
				-3 => array( 'bytes' => str_pad( $detalles['ec']['y'], 32, "\0", STR_PAD_LEFT ) ),
			)
		);

		$authData = hash( 'sha256', $this->rpId, true )
			. chr( 0x45 ) // UP + UV + AT
			. pack( 'N', $this->contador )
			. str_repeat( "\0", 16 )
			. pack( 'n', strlen( $this->idCredencial ) ) . $this->idCredencial
			. $cose;

		$attestation = self::cbor( array( 'fmt' => 'none', 'attStmt' => array( 'mapa' => array() ), 'authData' => array( 'bytes' => $authData ) ) );

		return array(
			'id'                => self::b64( $this->idCredencial ),
			'clientDataJSON'    => self::b64( $cliente ),
			'attestationObject' => self::b64( $attestation ),
		);
	}

	/** Respuesta a navigator.credentials.get() en base64url. */
	public function firmar( string $desafioB64, bool $verificado = true ): array {
		$cliente  = $this->clientData( 'webauthn.get', $desafioB64 );
		$authData = hash( 'sha256', $this->rpId, true ) . chr( $verificado ? 0x05 : 0x01 ) . pack( 'N', $this->contador );

		openssl_sign( $authData . hash( 'sha256', $cliente, true ), $firma, $this->clave, OPENSSL_ALGO_SHA256 );

		return array(
			'id'                => self::b64( $this->idCredencial ),
			'clientDataJSON'    => self::b64( $cliente ),
			'authenticatorData' => self::b64( $authData ),
			'signature'         => self::b64( $firma ),
			'userHandle'        => '',
		);
	}

	private function clientData( string $tipo, string $desafioB64 ): string {
		return json_encode( array( 'type' => $tipo, 'challenge' => $desafioB64, 'origin' => $this->origen, 'crossOrigin' => false ), JSON_UNESCAPED_SLASHES );
	}

	private static function b64( string $binario ): string {
		return rtrim( strtr( base64_encode( $binario ), '+/', '-_' ), '=' );
	}

	/** CBOR mínimo: enteros, textos, bytes (array{bytes}) y mapas. */
	private static function cbor( mixed $valor ): string {
		if ( is_int( $valor ) ) {
			return $valor >= 0 ? self::cabecera( 0, $valor ) : self::cabecera( 1, -1 - $valor );
		}

		if ( is_string( $valor ) ) {
			return self::cabecera( 3, strlen( $valor ) ) . $valor;
		}

		if ( is_array( $valor ) && array_key_exists( 'bytes', $valor ) ) {
			return self::cabecera( 2, strlen( $valor['bytes'] ) ) . $valor['bytes'];
		}

		$mapa   = is_array( $valor ) && array_key_exists( 'mapa', $valor ) ? $valor['mapa'] : $valor;
		$salida = self::cabecera( 5, count( $mapa ) );

		foreach ( $mapa as $clave => $item ) {
			$salida .= self::cbor( $clave ) . self::cbor( $item );
		}

		return $salida;
	}

	private static function cabecera( int $tipo, int $largo ): string {
		$tipo <<= 5;

		return match ( true ) {
			$largo < 24     => chr( $tipo | $largo ),
			$largo < 256    => chr( $tipo | 24 ) . chr( $largo ),
			$largo < 65536  => chr( $tipo | 25 ) . pack( 'n', $largo ),
			default         => chr( $tipo | 26 ) . pack( 'N', $largo ),
		};
	}
}
