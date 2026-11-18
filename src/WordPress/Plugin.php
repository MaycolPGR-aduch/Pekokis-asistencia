<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Arranque del plugin.
 */
final class Plugin {
	public static function iniciar(): void {
		// Con despliegue por git pull no se ejecuta el hook de activación:
		// el esquema se actualiza solo cuando cambia la versión de la base de datos.
		Instalador::actualizarSiHaceFalta();

		Endurecimiento::registrar();

		if ( ! Diagnostico::libreriasCargadas() ) {
			// Sin vendor/ no hay passkeys, QR ni Excel: mejor un aviso claro que un error fatal.
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>Pekokis Asistencia:</strong> faltan las librerías (carpeta vendor/). Instala el plugin desde el zip empaquetado o ejecuta <code>composer install --no-dev</code>.</p></div>';
				}
			);

			return;
		}

		Rutas::registrar();
		Api::registrar();
		AccesoGerencia::registrar();
		GerenciaMovil::registrar();
		Respaldo::registrar();
		Notificaciones::registrar();

		if ( is_admin() ) {
			Admin\Menu::registrar();
		}

		// Próximos bloques del MVP: pantalla QR, panel del día, correcciones y exportes.
	}
}
