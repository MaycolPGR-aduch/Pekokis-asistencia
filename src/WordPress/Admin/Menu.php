<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Capacidades;

defined( 'ABSPATH' ) || exit;

/**
 * Menú "Asistencia" en wp-admin, agrupado como el panel de gerencia de las maquetas.
 */
final class Menu {
	public const SLUG = 'pka-asistencia';

	public static function registrar(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		Tema::registrar();
		Riel::registrar();
		PantallaSolicitudes::registrar();
		PantallaDelegar::registrar();
		PantallaRegistros::registrar();
		PantallaSueldos::registrar();
		PantallaHoy::registrar();
		PantallaDia::registrar();
		PantallaExportar::registrar();
		PantallaHistorial::registrar();
		PantallaHorarios::registrar();
		PantallaAusencias::registrar();
		PantallaInforme::registrar();
		PantallaPagos::registrar();
		PantallaSimulador::registrar();
		PantallaTardanzas::registrar();
		PantallaHoras::registrar();
		PantallaDescuentos::registrar();
		PantallaRecibos::registrar();
		PantallaCalendario::registrar();
		PantallaTrabajadores::registrar();
		PantallaCelulares::registrar();
		PantallaAjustes::registrar();
	}

	/**
	 * Menú agrupado como en las maquetas (Resumen, Control, Pagos, Personas,
	 * Sistema). Los títulos de grupo son entradas que no se pueden tocar
	 * ("pka-g-…"); el CSS del tema las muestra como encabezados.
	 */
	public static function menu(): void {
		add_menu_page( 'Dashboard de asistencia', 'Asistencia', Capacidades::VER_EQUIPO, PantallaDashboard::SLUG, array( PantallaDashboard::class, 'pintar' ), 'dashicons-clock', 3 );

		$p = PantallaDashboard::SLUG;

		// Resumen (el primer grupo no lleva título: el menú arranca con el Dashboard).
		add_submenu_page( $p, 'Dashboard de asistencia', 'Dashboard', Capacidades::VER_EQUIPO, $p, array( PantallaDashboard::class, 'pintar' ) );
		add_submenu_page( $p, 'Asistencia de hoy', 'Asistencia de hoy', Capacidades::VER_EQUIPO, self::SLUG, array( PantallaHoy::class, 'pintar' ) );

		self::grupo( 'control', 'Control', Capacidades::VER_EQUIPO );
		add_submenu_page( $p, 'Solicitudes y revisión', PantallaSolicitudes::titulo(), Capacidades::VER_EQUIPO, PantallaSolicitudes::SLUG, array( PantallaSolicitudes::class, 'pintar' ) );
		add_submenu_page( $p, 'Registros y revisión', 'Registros y revisión', Capacidades::VER_EQUIPO, PantallaRegistros::SLUG, array( PantallaRegistros::class, 'pintar' ) );
		add_submenu_page( $p, 'Informe por persona', 'Informe por persona', Capacidades::VER_EQUIPO, PantallaInforme::SLUG, array( PantallaInforme::class, 'pintar' ) );
		add_submenu_page( $p, 'Horarios', 'Horarios', Capacidades::HORARIOS, PantallaHorarios::SLUG, array( PantallaHorarios::class, 'pintar' ) );
		add_submenu_page( $p, 'Feriados y calendario', 'Feriados', Capacidades::HORARIOS, PantallaCalendario::SLUG, array( PantallaCalendario::class, 'pintar' ) );
		add_submenu_page( $p, 'Ausencias justificadas', 'Ausencias', Capacidades::JUSTIFICAR, PantallaAusencias::SLUG, array( PantallaAusencias::class, 'pintar' ) );
		add_submenu_page( $p, 'Auditoría de cambios', 'Auditoría', Capacidades::AUDITORIA, PantallaHistorial::SLUG, array( PantallaHistorial::class, 'pintar' ) );

		self::grupo( 'pagos', 'Pagos', current_user_can( Capacidades::VER_SUELDOS ) ? Capacidades::VER_SUELDOS : Capacidades::RECIBOS );
		add_submenu_page( $p, 'Sueldos y tarifas', 'Sueldos y tarifas', Capacidades::VER_SUELDOS, PantallaSueldos::SLUG, array( PantallaSueldos::class, 'pintar' ) );
		add_submenu_page( $p, 'Contabilidad de horas', 'Contabilidad de horas', Capacidades::JUSTIFICAR, PantallaHoras::SLUG, array( PantallaHoras::class, 'pintar' ) );
		add_submenu_page( $p, 'Calculadora de pagos', 'Calculadora de pagos', Capacidades::VER_SUELDOS, PantallaPagos::SLUG, array( PantallaPagos::class, 'pintar' ) );
		add_submenu_page( $p, 'Simulador de pagos', 'Simulador de pagos', Capacidades::VER_SUELDOS, PantallaSimulador::SLUG, array( PantallaSimulador::class, 'pintar' ) );
		add_submenu_page( $p, 'Recibos por honorarios', 'Recibos por honorarios', Capacidades::RECIBOS, PantallaRecibos::SLUG, array( PantallaRecibos::class, 'pintar' ) );

		self::grupo( 'personas', 'Personas', Capacidades::GESTIONAR_PERSONAL );
		add_submenu_page( $p, 'Perfiles', 'Perfiles', Capacidades::GESTIONAR_PERSONAL, PantallaTrabajadores::SLUG, array( PantallaTrabajadores::class, 'pintar' ) );
		add_submenu_page( $p, 'Celulares y llaves', PantallaCelulares::titulo(), Capacidades::GESTIONAR_PERSONAL, PantallaCelulares::SLUG, array( PantallaCelulares::class, 'pintar' ) );
		add_submenu_page( $p, 'Delegar permisos', 'Delegar permisos', Capacidades::AJUSTES, PantallaDelegar::SLUG, array( PantallaDelegar::class, 'pintar' ) );

		self::grupo( 'sistema', 'Sistema', Capacidades::VER_EQUIPO );
		add_submenu_page( $p, 'Resumen del mes', 'Resumen del mes', Capacidades::VER_EQUIPO, PantallaResumen::SLUG, array( PantallaResumen::class, 'pintar' ) );
		add_submenu_page( $p, 'Informes y exportación', 'Informes', Capacidades::EXPORTAR, PantallaExportar::SLUG, array( PantallaExportar::class, 'pintar' ) );
		add_submenu_page( $p, 'Seguridad y reglas', 'Seguridad y reglas', Capacidades::AJUSTES, PantallaAjustes::SLUG, array( PantallaAjustes::class, 'pintar' ) );
	}

	/** Título de grupo del menú (no lleva a ninguna parte). */
	private static function grupo( string $clave, string $titulo, string $capacidad ): void {
		add_submenu_page(
			PantallaDashboard::SLUG,
			$titulo,
			$titulo,
			$capacidad,
			'pka-g-' . $clave,
			static function (): void {
				echo '<div class="wrap"><p>Elige una opción del menú.</p></div>';
			}
		);
	}

	/** URL de una acción admin-post con nonce. */
	public static function urlAccion( string $accion, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => $accion ) + $args, admin_url( 'admin-post.php' ) ), $accion );
	}

	public static function volver( string $pagina, array $args = array() ): never {
		self::volverA( add_query_arg( array( 'page' => $pagina ) + $args, admin_url( 'admin.php' ) ) );
	}

	/**
	 * Redirige después de guardar. Si el formulario vino de la versión para
	 * celular (campo pk_volver), vuelve allí; solo se aceptan sus propias URL.
	 */
	public static function volverA( string $url ): never {
		$volver = isset( $_REQUEST['pk_volver'] ) ? esc_url_raw( wp_unslash( $_REQUEST['pk_volver'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $volver && str_starts_with( $volver, \Pekokis\Asistencia\WordPress\GerenciaMovil::url() ) ) {
			$url = $volver;
		}

		wp_safe_redirect( $url );
		exit;
	}
}
