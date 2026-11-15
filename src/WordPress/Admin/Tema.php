<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Rutas;

defined( 'ABSPATH' ) || exit;

/**
 * Viste wp-admin con el estilo de las maquetas de gerencia (assets/admin.css).
 *
 * - En las pantallas de Asistencia, para todos.
 * - En todo wp-admin para quien solo usa Asistencia (RR.HH., gerencia, jefes,
 *   delegados): además se le esconde lo de WordPress que no usa y entra
 *   directo al Dashboard.
 */
final class Tema {
	public static function registrar(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'estilos' ) );
		add_filter( 'admin_body_class', array( self::class, 'clases' ) );
		add_action( 'admin_menu', array( self::class, 'limpiarMenu' ), 999 );
		add_action( 'admin_bar_menu', array( self::class, 'limpiarBarra' ), 999 );
		add_action( 'load-index.php', array( self::class, 'alEscritorio' ) );
		add_filter( 'login_redirect', array( self::class, 'alEntrar' ), 10, 3 );
		add_filter( 'admin_footer_text', array( self::class, 'pie' ) );
	}

	/** ¿Es una pantalla del plugin? */
	public static function esPagina(): bool {
		$pagina = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return str_starts_with( $pagina, 'pka-' );
	}

	/** Quien entra a wp-admin solo para la asistencia (no administra WordPress). */
	public static function soloAsistencia( ?\WP_User $usuario = null ): bool {
		$usuario ??= wp_get_current_user();

		return $usuario->exists() && ! user_can( $usuario, 'manage_options' ) && self::usaAsistencia( $usuario );
	}

	public static function activo(): bool {
		return self::esPagina() || self::soloAsistencia();
	}

	public static function estilos(): void {
		if ( self::activo() ) {
			wp_enqueue_style( 'pka-admin', Rutas::asset( 'admin.css' ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- la URL ya lleva la versión.
		}
	}

	public static function clases( string $clases ): string {
		if ( self::activo() ) {
			$clases .= ' pka-tema';
		}

		if ( self::esPagina() ) {
			$clases .= ' pka-pagina';
		}

		return $clases;
	}

	public static function limpiarMenu(): void {
		if ( ! self::soloAsistencia() ) {
			return;
		}

		foreach ( array( 'index.php', 'edit.php', 'upload.php', 'edit-comments.php', 'tools.php' ) as $pagina ) {
			remove_menu_page( $pagina );
		}
	}

	public static function limpiarBarra( \WP_Admin_Bar $barra ): void {
		if ( ! self::soloAsistencia() ) {
			return;
		}

		foreach ( array( 'wp-logo', 'comments', 'new-content', 'updates', 'site-name' ) as $nodo ) {
			$barra->remove_node( $nodo );
		}
	}

	/** El Escritorio de WordPress no les sirve: van directo al Dashboard de asistencia. */
	public static function alEscritorio(): void {
		if ( self::soloAsistencia() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . PantallaDashboard::SLUG ) );
			exit;
		}
	}

	/**
	 * @param string             $destino
	 * @param string             $pedido
	 * @param \WP_User|\WP_Error $usuario
	 */
	public static function alEntrar( $destino, $pedido, $usuario ): string {
		if ( $usuario instanceof \WP_User && self::soloAsistencia( $usuario ) && ( '' === (string) $pedido || str_contains( (string) $destino, 'wp-admin/' ) && ! str_contains( (string) $destino, 'page=' ) ) ) {
			return admin_url( 'admin.php?page=' . PantallaDashboard::SLUG );
		}

		return (string) $destino;
	}

	public static function pie( string $texto ): string {
		return self::esPagina() ? 'Asistencia Pekokis · ' . esc_html( PKA_VERSION ) : $texto;
	}

	private static function usaAsistencia( \WP_User $usuario ): bool {
		foreach ( array( Capacidades::VER_EQUIPO, Capacidades::APROBAR, Capacidades::GESTIONAR_PERSONAL, Capacidades::VER_SUELDOS, Capacidades::RECIBOS, Capacidades::HORARIOS, Capacidades::AJUSTES ) as $cap ) {
			if ( user_can( $usuario, $cap ) ) {
				return true;
			}
		}

		return false;
	}
}
