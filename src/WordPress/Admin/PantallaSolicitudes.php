<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeZone;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Correcciones;
use Pekokis\Asistencia\WordPress\Revisiones;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Solicitudes (maquetas G6 y G4): la bandeja de lo que piden los
 * trabajadores ("olvidé marcar"), las marcas por revisar y los días que
 * quedaron incompletos. Antes estaba al final de "Asistencia de hoy".
 */
final class PantallaSolicitudes {
	public const SLUG = 'pka-solicitudes';

	public static function registrar(): void {
	}

	/** Cuántas cosas esperan una decisión de quien mira (solicitudes + marcas por revisar). */
	public static function pendientes(): int {
		if ( ! current_user_can( Capacidades::APROBAR ) ) {
			return 0;
		}

		$alcance = PantallaHoy::alcance();

		return count( Correcciones::pendientes( $alcance ) ) + count( Revisiones::pendientes( $alcance ) );
	}

	public static function titulo(): string {
		$n = self::pendientes();

		return 'Solicitudes' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_EQUIPO ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$alcance = PantallaHoy::alcance();
		$hoy     = Ajustes::diaLaboral()->de( new \DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Solicitudes y revisión</h1>
			<p class="description">Lo que piden los trabajadores y lo que necesita una decisión. Aprobar algo agrega o confirma la marca; todo queda en la auditoría con tu nombre. Para corregir un día a mano, ábrelo desde "Registros por persona".</p>
			<?php PantallaHoy::revisiones( $alcance, Ajustes::zona() ); ?>
			<?php PantallaDia::bandejas( $alcance, $hoy ); ?>
		</div>
		<?php
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}
}
