<?php

namespace Pekokis\Asistencia\WordPress;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Pekokis\Asistencia\Nucleo\Base64Url;
use Pekokis\Asistencia\Nucleo\CodigoPantalla;

defined( 'ABSPATH' ) || exit;

/**
 * Tablets de la entrada que muestran el QR (cambia cada 30 s o lo que diga Ajustes).
 *
 * Cada pantalla se activa una vez desde el propio equipo, con un administrador
 * con sesión iniciada. Queda una cookie HttpOnly con un token largo; en la base
 * solo se guarda su hash y se puede desactivar desde Ajustes.
 */
final class Pantallas {
	public const COOKIE = 'pka_pantalla';

	/** Sin pedir código por más de este tiempo, la pantalla se considera apagada. */
	public const SEGUNDOS_SIN_CONEXION = 5 * MINUTE_IN_SECONDS;

	public static function activarEsteEquipo( string $nombre ): int {
		global $wpdb;

		$token = Base64Url::aleatorio( 32 );

		$wpdb->insert(
			Tablas::nombre( 'pantallas' ),
			array(
				'nombre'     => mb_substr( '' !== $nombre ? $nombre : 'Pantalla de la entrada', 0, 80 ),
				'token_hash' => hash( 'sha256', $token ),
				'activa'     => 1,
				'ultima_ip'  => Peticion::ip(),
				'creado_por' => get_current_user_id(),
				'creado_en'  => Peticion::ahoraUtc(),
			)
		);

		$id = (int) $wpdb->insert_id;
		Peticion::ponerCookie( self::COOKIE, $token, 5 * YEAR_IN_SECONDS );
		Auditoria::registrar( 'pantalla.activar', 'pantalla', $id, null, array( 'nombre' => $nombre, 'ip' => Peticion::ip() ) );

		return $id;
	}

	/** @return array<string, mixed>|null Pantalla activa de este navegador. */
	public static function deEsteEquipo(): ?array {
		global $wpdb;

		$token = Peticion::cookie( self::COOKIE );

		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
			return null;
		}

		$tabla = Tablas::nombre( 'pantallas' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE token_hash = %s AND activa = 1", hash( 'sha256', $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $fila ? $fila : null;
	}

	/**
	 * Código vigente para mostrar. El SVG del QR solo se manda cuando cambia el token.
	 *
	 * @param array<string, mixed> $pantalla
	 * @return array<string, mixed>
	 */
	public static function codigoVigente( array $pantalla, string $tokenQueYaTiene ): array {
		global $wpdb;

		$ahora   = time();
		$codigos = self::codigos();
		$ventana = $codigos->ventanaActual( $ahora );
		$token   = $codigos->token( (int) $pantalla['id'], $ventana );
		$url     = home_url( '/m?q=' . $token );

		$wpdb->update(
			Tablas::nombre( 'pantallas' ),
			array( 'ultima_conexion' => Peticion::ahoraUtc(), 'ultima_ip' => Peticion::ip() ),
			array( 'id' => (int) $pantalla['id'] )
		);

		return array(
			'token'    => $token,
			'codigo'   => $codigos->codigo( (int) $pantalla['id'], $ventana ),
			'segundos' => $codigos->restantes( $ahora ),
			'total'    => $codigos->duracion(),
			'hora'     => wp_date( 'H:i', $ahora, Ajustes::zona() ),
			'fecha'    => self::fechaLarga( $ahora ),
			'con_wifi' => \Pekokis\Asistencia\Nucleo\ModoPresencia::WIFI_Y_QR === Ajustes::modoPresencia(),
			'qr'       => hash_equals( $token, $tokenQueYaTiene ) ? null : self::qr( $url ),
		);
	}

	/** "Viernes 15 de enero" para el pie de la pantalla. */
	private static function fechaLarga( int $ahora ): string {
		$dias  = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );
		$meses = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$dia   = $dias[ (int) wp_date( 'w', $ahora, Ajustes::zona() ) ];

		return mb_strtoupper( mb_substr( $dia, 0, 1 ) ) . mb_substr( $dia, 1 ) . ' ' . wp_date( 'j', $ahora, Ajustes::zona() ) . ' de ' . $meses[ (int) wp_date( 'n', $ahora, Ajustes::zona() ) ];
	}

	/** @return int[] Ids de las pantallas activas. */
	public static function activas(): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'pantallas' );

		return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$tabla} WHERE activa = 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<int, array<string, mixed>> */
	public static function listar(): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'pantallas' );

		return $wpdb->get_results( "SELECT * FROM {$tabla} ORDER BY activa DESC, id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Pantallas activas que no piden código hace más de 5 minutos.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function sinConexion(): array {
		$limite = gmdate( 'Y-m-d H:i:s', time() - self::SEGUNDOS_SIN_CONEXION );

		return array_values(
			array_filter(
				self::listar(),
				static fn( array $p ): bool => (bool) $p['activa'] && ( null === $p['ultima_conexion'] || $p['ultima_conexion'] < $limite )
			)
		);
	}

	public static function cambiarEstado( int $id, bool $activa ): void {
		global $wpdb;

		$wpdb->update( Tablas::nombre( 'pantallas' ), array( 'activa' => (int) $activa ), array( 'id' => $id ) );
		Auditoria::registrar( $activa ? 'pantalla.activar' : 'pantalla.desactivar', 'pantalla', $id );
	}

	public static function codigos(): CodigoPantalla {
		return new CodigoPantalla( Secretos::clave(), Ajustes::qrSegundos() );
	}

	private static function qr( string $url ): string {
		$opciones = new QROptions(
			array(
				'eccLevel'     => EccLevel::M,
				'addQuietzone' => true,
			)
		);

		return ( new QRCode( $opciones ) )->render( $url );
	}
}
