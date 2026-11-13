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

		// Próximos bloques del MVP: API REST, app del trabajador, pantalla QR y panel de administración.
	}
}
