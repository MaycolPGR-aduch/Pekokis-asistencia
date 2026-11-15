<?php

namespace Pekokis\Asistencia\WordPress;

use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Pekokis\Asistencia\Nucleo\Base64Url;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Passkeys (WebAuthn) de los trabajadores, con la librería lbuchs/webauthn.
 *
 * - Siempre se exige verificación del usuario (huella, rostro o PIN del celular).
 * - Las passkeys son "descubribles": para identificarse no hace falta escribir nada.
 * - No se pide certificado del fabricante (attestation "none"): no hace falta y protege la privacidad.
 */
final class Passkeys {
	private const SEGUNDOS_RETO = 120;

	public static function rpId(): string {
		return (string) apply_filters( 'pka_rp_id', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/** Origen exacto aceptado ("https://asistencia.pekokis.com"). */
	public static function origen(): string {
		$partes = wp_parse_url( home_url() );
		$puerto = isset( $partes['port'] ) ? ':' . $partes['port'] : '';

		return $partes['scheme'] . '://' . $partes['host'] . $puerto;
	}

	/**
	 * Opciones para crear la passkey del trabajador.
	 *
	 * @param array<string, mixed> $trabajador
	 * @return array{reto: string, publicKey: stdClass}
	 */
	public static function opcionesDeRegistro( array $trabajador, int $activacionId ): array {
		$servidor = self::servidor();
		$args     = $servidor->getCreateArgs(
			self::userHandle( (int) $trabajador['id'] ),
			$trabajador['dni'],
			Trabajadores::nombreCompleto( $trabajador ),
			60,
			true,
			true,
			false
		);

		$args->publicKey->attestation = 'none';
		unset( $args->publicKey->extensions );

		$reto = Temporales::guardar(
			'reto',
			array(
				'uso'           => 'registro',
				'desafio'       => Base64Url::codificar( $servidor->getChallenge()->getBinaryString() ), // texto: los transients no aceptan binario
				'trabajador_id' => (int) $trabajador['id'],
				'activacion_id' => $activacionId,
			),
			self::SEGUNDOS_RETO
		);

		return array( 'reto' => $reto, 'publicKey' => $args->publicKey );
	}

	/**
	 * Verifica la passkey recién creada y la guarda.
	 *
	 * @param array<string, mixed> $credencial Respuesta del navegador (base64url).
	 * @return int|WP_Error Id de la credencial guardada.
	 */
	public static function completarRegistro( string $reto, array $credencial, int $trabajadorId, int $dispositivoId, bool $pendiente = false ): int|WP_Error {
		global $wpdb;

		$datos = Temporales::tomar( 'reto', $reto );

		if ( null === $datos || 'registro' !== $datos['uso'] || $trabajadorId !== $datos['trabajador_id'] ) {
			return new WP_Error( 'pka_reto', 'La solicitud venció. Vuelve a intentarlo.', array( 'status' => 400 ) );
		}

		$cliente      = Base64Url::decodificar( (string) ( $credencial['clientDataJSON'] ?? '' ) );
		$attestation  = Base64Url::decodificar( (string) ( $credencial['attestationObject'] ?? '' ) );

		if ( null === $cliente || null === $attestation || ! self::origenValido( $cliente ) ) {
			return new WP_Error( 'pka_passkey', 'No se pudo verificar la llave.', array( 'status' => 400 ) );
		}

		try {
			$resultado = self::servidor()->processCreate( $cliente, $attestation, (string) Base64Url::decodificar( $datos['desafio'] ), true, true, false );
		} catch ( WebAuthnException $e ) {
			return new WP_Error( 'pka_passkey', 'No se pudo verificar la llave: ' . $e->getMessage(), array( 'status' => 400 ) );
		}

		$id = $resultado->credentialId;

		$guardada = $wpdb->insert(
			Tablas::nombre( 'credenciales' ),
			array(
				'trabajador_id'   => $trabajadorId,
				'credential_id'   => Base64Url::codificar( $id ),
				'credential_hash' => hash( 'sha256', $id ),
				'public_key'      => $resultado->credentialPublicKey,
				'sign_count'      => (int) $resultado->signatureCounter,
				'dispositivo_id'  => $dispositivoId,
				'nombre'          => mb_substr( Peticion::navegador(), 0, 120 ),
				'creado_en'       => Peticion::ahoraUtc(),
				// Cambio de celular: la llave nueva no sirve hasta que gerencia lo apruebe.
				'pendiente'       => (int) $pendiente,
			)
		);

		if ( false === $guardada ) {
			return new WP_Error( 'pka_bd', 'Esa llave ya estaba registrada.', array( 'status' => 409 ) );
		}

		return (int) $wpdb->insert_id;
	}

	/** @return array{reto: string, publicKey: stdClass} */
	public static function opcionesDeIdentificacion(): array {
		$servidor = self::servidor();
		$args     = $servidor->getGetArgs( array(), 60, false, false, false, true, true, true );

		$reto = Temporales::guardar(
			'reto',
			array(
				'uso'     => 'identificacion',
				'desafio' => Base64Url::codificar( $servidor->getChallenge()->getBinaryString() ),
			),
			self::SEGUNDOS_RETO
		);

		return array( 'reto' => $reto, 'publicKey' => $args->publicKey );
	}

	/**
	 * Verifica la huella y devuelve la credencial con su trabajador activo.
	 *
	 * @param array<string, mixed> $credencial
	 * @return array<string, mixed>|WP_Error Fila de la credencial.
	 */
	public static function identificar( string $reto, array $credencial ): array|WP_Error {
		global $wpdb;

		$datos = Temporales::tomar( 'reto', $reto );
		$error = new WP_Error( 'pka_passkey', 'No reconocimos tu llave. Si cambiaste de celular, toca "Cambié de celular".', array( 'status' => 401 ) );

		if ( null === $datos || 'identificacion' !== $datos['uso'] ) {
			return new WP_Error( 'pka_reto', 'La solicitud venció. Vuelve a intentarlo.', array( 'status' => 400 ) );
		}

		$idBinario = Base64Url::decodificar( (string) ( $credencial['id'] ?? '' ) );
		$cliente   = Base64Url::decodificar( (string) ( $credencial['clientDataJSON'] ?? '' ) );
		$autentic  = Base64Url::decodificar( (string) ( $credencial['authenticatorData'] ?? '' ) );
		$firma     = Base64Url::decodificar( (string) ( $credencial['signature'] ?? '' ) );

		if ( null === $idBinario || null === $cliente || null === $autentic || null === $firma || ! self::origenValido( $cliente ) ) {
			return $error;
		}

		$c    = Tablas::nombre( 'credenciales' );
		$t    = Tablas::nombre( 'trabajadores' );
		$fila = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT c.*, t.estado AS trabajador_estado FROM {$c} c JOIN {$t} t ON t.id = c.trabajador_id
				WHERE c.credential_hash = %s AND c.revocada_en IS NULL AND t.estado IN (%s, %s)",
				hash( 'sha256', $idBinario ),
				Trabajadores::ACTIVO,
				Trabajadores::PENDIENTE
			),
			ARRAY_A
		);

		if ( ! $fila ) {
			return $error;
		}

		$servidor = self::servidor();

		try {
			$servidor->processGet( $cliente, $autentic, $firma, $fila['public_key'], (string) Base64Url::decodificar( $datos['desafio'] ), (int) $fila['sign_count'], true, true );
		} catch ( WebAuthnException $e ) {
			return $error;
		}

		$wpdb->update(
			$c,
			array(
				'sign_count' => (int) ( $servidor->getSignatureCounter() ?? $fila['sign_count'] ),
				'ultimo_uso' => Peticion::ahoraUtc(),
			),
			array( 'id' => (int) $fila['id'] )
		);

		return $fila;
	}

	private static function servidor(): WebAuthn {
		return new WebAuthn( 'Pekokis Asistencia', self::rpId(), null, true );
	}

	/** Identificador opaco del usuario dentro de la passkey (no lleva el DNI). */
	private static function userHandle( int $trabajadorId ): string {
		return hash_hmac( 'sha256', 'usuario|' . $trabajadorId, Secretos::clave(), true );
	}

	/** Comprobación propia y exacta del origen, además de la de la librería. */
	private static function origenValido( string $clientDataJSON ): bool {
		$datos = json_decode( $clientDataJSON, true );

		return is_array( $datos ) && isset( $datos['origin'] ) && hash_equals( self::origen(), (string) $datos['origin'] );
	}
}
