<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Capacidades;

defined( 'ABSPATH' ) || exit;

/**
 * Menú "Asistencia" en wp-admin.
 */
final class Menu {
	public const SLUG = 'pka-asistencia';

	public static function registrar(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		PantallaHoy::registrar();
		PantallaDia::registrar();
		PantallaExportar::registrar();
		PantallaHistorial::registrar();
		PantallaHorarios::registrar();
		PantallaAusencias::registrar();
		PantallaHorasExtra::registrar();
		PantallaCalendario::registrar();
		PantallaTrabajadores::registrar();
		PantallaAjustes::registrar();
	}

	public static function menu(): void {
		add_menu_page( 'Asistencia de hoy', 'Asistencia', Capacidades::VER_EQUIPO, self::SLUG, array( PantallaHoy::class, 'pintar' ), 'dashicons-clock', 3 );

		add_submenu_page( self::SLUG, 'Asistencia de hoy', 'Hoy', Capacidades::VER_EQUIPO, self::SLUG, array( PantallaHoy::class, 'pintar' ) );
		add_submenu_page( self::SLUG, 'Trabajadores', 'Trabajadores', Capacidades::GESTIONAR_PERSONAL, PantallaTrabajadores::SLUG, array( PantallaTrabajadores::class, 'pintar' ) );
		add_submenu_page( self::SLUG, 'Ausencias justificadas', 'Ausencias', Capacidades::GESTIONAR_PERSONAL, PantallaAusencias::SLUG, array( PantallaAusencias::class, 'pintar' ) );
		add_submenu_page( self::SLUG, 'Horas extra', 'Horas extra', Capacidades::APROBAR, PantallaHorasExtra::SLUG, array( PantallaHorasExtra::class, 'pintar' ) );
		add_submenu_page( self::SLUG, 'Exportar registro', 'Exportar', Capacidades::EXPORTAR, PantallaExportar::SLUG, array( PantallaExportar::class, 'pintar' ) );
		add_submenu_page( self::SLUG, 'Horarios', 'Horarios', Capacidades::AJUSTES, PantallaHorarios::SLUG, array( PantallaHorarios::class, 'pintar' ) );
		add_submenu_page( self::SLUG, 'Calendario', 'Calendario', Capacidades::AJUSTES, PantallaCalendario::SLUG, array( PantallaCalendario::class, 'pintar' ) );
		add_submenu_page( self::SLUG, 'Historial de cambios', 'Historial', Capacidades::AUDITORIA, PantallaHistorial::SLUG, array( PantallaHistorial::class, 'pintar' ) );
		add_submenu_page( self::SLUG, 'Ajustes de asistencia', 'Ajustes', Capacidades::AJUSTES, PantallaAjustes::SLUG, array( PantallaAjustes::class, 'pintar' ) );
	}

	/** URL de una acción admin-post con nonce. */
	public static function urlAccion( string $accion, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => $accion ) + $args, admin_url( 'admin-post.php' ) ), $accion );
	}

	public static function volver( string $pagina, array $args = array() ): never {
		self::volverA( add_query_arg( array( 'page' => $pagina ) + $args, admin_url( 'admin.php' ) ) );
	}

	public static function volverA( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
