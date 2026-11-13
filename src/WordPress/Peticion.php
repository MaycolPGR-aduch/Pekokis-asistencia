<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Datos de la petición actual: IP, navegador y cookies.
 */
final class Peticion {
	/**
	 * IP del cliente. El subdominio va en Cloudflare "solo DNS", así que REMOTE_ADDR
	 * es la IP real. Si algún día se pone detrás de un proxy, se ajusta con el filtro.
	 */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ip = (string) apply_filters( 'pka_ip_cliente', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function navegador(): string {
		$agente = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		return mb_substr( sanitize_text_field( $agente ), 0, 255 );
	}

	public static function cookie( string $nombre ): string {
		return isset( $_COOKIE[ $nombre ] ) && is_string( $_COOKIE[ $nombre ] ) ? (string) wp_unslash( $_COOKIE[ $nombre ] ) : '';
	}

	public static function ponerCookie( string $nombre, string $valor, int $segundos ): void {
		// También en $_COOKIE, para que el resto de esta misma petición la vea.
		$_COOKIE[ $nombre ] = $valor;

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			$nombre,
			$valor,
			array(
				'expires'  => time() + $segundos,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	public static function borrarCookie( string $nombre ): void {
		unset( $_COOKIE[ $nombre ] );

		if ( ! headers_sent() ) {
			setcookie( $nombre, '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
	}

	public static function ahoraUtc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
