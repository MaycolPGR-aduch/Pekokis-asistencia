<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Estado;
use Pekokis\Asistencia\Nucleo\PruebaPresencia;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Ausencias;
use Pekokis\Asistencia\WordPress\Calendario;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Horarios;
use Pekokis\Asistencia\WordPress\Marcas;
use Pekokis\Asistencia\WordPress\Pantallas;
use Pekokis\Asistencia\WordPress\Presencia;
use Pekokis\Asistencia\WordPress\Revisiones;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Hoy: quién está, quién llegó tarde, quién falta y qué hay que revisar.
 */
final class PantallaHoy {
	private const SITUACIONES = array(
		ResumenDia::AUSENTE    => array( 'Ausente', '#b32d2e' ),
		ResumenDia::TARDE      => array( 'Llegó tarde', '#b26200' ),
		ResumenDia::REFRIGERIO => array( 'En refrigerio', '#2271b1' ),
		ResumenDia::FUERA      => array( 'Fuera', '#2271b1' ),
		ResumenDia::PRESENTE   => array( 'Presente', '#00733a' ),
		ResumenDia::JUSTIFICADO => array( 'Ausencia justificada', '#2271b1' ),
		ResumenDia::POR_LLEGAR => array( 'Por llegar', '#50575e' ),
		ResumenDia::SALIO      => array( 'Terminó', '#50575e' ),
		ResumenDia::LIBRE      => array( 'Día libre', '#50575e' ),
	);

	public static function registrar(): void {
		add_action( 'admin_post_pka_revisar_marca', array( self::class, 'revisar' ) );
	}

	/** Trabajadores que este usuario puede ver: todos, o solo su equipo. */
	public static function alcance(): ?array {
		if ( current_user_can( Capacidades::VER_TODO ) ) {
			return null;
		}

		return array_map(
			static fn( array $t ): int => (int) $t['id'],
			array_filter( Trabajadores::listar(), static fn( array $t ): bool => (int) $t['jefe_user_id'] === get_current_user_id() )
		);
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_EQUIPO ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		if ( isset( $_GET['vista'] ) && 'dia' === $_GET['vista'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			PantallaDia::pintar();
			return;
		}

		$ahora   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$zona    = Ajustes::zona();
		$dia     = Ajustes::diaLaboral()->de( $ahora );
		$alcance = self::alcance();
		$filas   = array();
		$cuentas = array_fill_keys( array_keys( self::SITUACIONES ), 0 );

		$ausencias = Ausencias::delRango( $alcance, $dia, $dia );

		foreach ( Trabajadores::listar() as $t ) {
			if ( is_array( $alcance ) && ! in_array( (int) $t['id'], $alcance, true ) ) {
				continue;
			}

			// Quien sigue en la jornada de ayer (pasó la medianoche) se muestra con esa jornada.
			$diaT    = \Pekokis\Asistencia\WordPress\Jornadas::diaActual( (int) $t['id'], $ahora );
			$resumen = ResumenDia::calcular( Marcas::delDia( (int) $t['id'], $diaT ), Horarios::delDia( (int) $t['horario_id'], $diaT ), $diaT, $zona, $ahora, $diaT === $dia ? ( $ausencias[ (int) $t['id'] ][ $dia ] ?? null ) : null );
			$cuentas[ $resumen->situacion ]++;
			$filas[] = array( 't' => $t, 'r' => $resumen, 'dia' => $diaT );
		}

		// Primero lo que requiere atención.
		$orden = array_flip( array_keys( self::SITUACIONES ) );
		usort( $filas, static fn( $a, $b ) => array( $orden[ $a['r']->situacion ], $a['t']['apellidos'] ) <=> array( $orden[ $b['r']->situacion ], $b['t']['apellidos'] ) );

		$hora = static fn( ?DateTimeImmutable $m ): string => $m ? $m->setTimezone( $zona )->format( 'H:i' ) : '—';
		?>
		<div class="wrap">
			<meta http-equiv="refresh" content="60">
			<?php Avisos::mostrar(); ?>
			<h1>Hoy · <?php echo esc_html( wp_date( 'l j \d\e F', $ahora->getTimestamp(), $zona ) ); ?></h1>
			<p class="description">Se actualiza cada minuto. Hora del servidor: <?php echo esc_html( $hora( $ahora ) ); ?>.</p>
			<?php if ( '' !== Calendario::descripcion( $dia ) ) : ?>
				<div class="notice notice-info inline"><p><strong><?php echo esc_html( Calendario::descripcion( $dia ) ); ?>.</strong> Hoy no es laborable: no se cuentan ausencias ni tardanzas.</p></div>
			<?php endif; ?>

			<?php self::alertas(); ?>

			<p style="display:flex;flex-wrap:wrap;gap:8px;margin:16px 0">
				<?php foreach ( self::SITUACIONES as $clave => [ $etiqueta, $color ] ) : ?>
					<?php if ( $cuentas[ $clave ] ) : ?>
						<span style="border:2px solid <?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $color ); ?>;border-radius:999px;padding:4px 12px;font-weight:600">
							<?php echo esc_html( $etiqueta . ': ' . $cuentas[ $clave ] ); ?>
						</span>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>

			<table class="widefat striped">
				<thead><tr><th>Trabajador</th><th>Situación</th><th>Llegó</th><th>Desde</th><th>Detalle</th></tr></thead>
				<tbody>
				<?php if ( ! $filas ) : ?>
					<tr><td colspan="5"><?php echo is_array( $alcance ) ? 'No tienes trabajadores a tu cargo.' : 'No hay trabajadores activos.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $filas as [ 't' => $t, 'r' => $r, 'dia' => $diaT ] ) : ?>
					<?php [ $etiqueta, $color ] = self::SITUACIONES[ $r->situacion ]; ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( PantallaDia::url( (int) $t['id'], $diaT ) ); ?>"><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></a></strong><br><span class="description"><?php echo esc_html( $t['area'] ); ?></span></td>
						<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600"><?php echo esc_html( $etiqueta ); ?></span></td>
						<td><?php echo esc_html( $hora( $r->primeraEntrada ) ); ?><?php echo $r->minutosTardanza ? ' <span style="color:#b26200">(+' . (int) $r->minutosTardanza . ' min)</span>' : ''; ?></td>
						<td><?php echo Estado::FUERA !== $r->estado || $r->desde ? esc_html( $hora( $r->desde ) ) : '—'; ?></td>
						<td>
							<?php
							if ( $diaT !== $dia ) {
								echo '<strong style="color:#6b3fa0">Sigue en la jornada de ayer</strong> (pasó la medianoche). ';
							}

							if ( '' !== $r->detalle && in_array( $r->situacion, array( ResumenDia::JUSTIFICADO, ResumenDia::LIBRE ), true ) ) {
								echo esc_html( $r->detalle );
							} elseif ( ResumenDia::REFRIGERIO === $r->situacion ) {
								echo esc_html( 'Hace ' . $r->minutosEnEstado . ' min' );
								echo $r->excedeRefrigerio ? ' <strong style="color:#b32d2e">pasó el tiempo de refrigerio</strong>' : '';
							} elseif ( ResumenDia::FUERA === $r->situacion ) {
								echo esc_html( ( $r->motivoActual ? $r->motivoActual->nombre : 'Salida' ) . ' · hace ' . $r->minutosEnEstado . ' min' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php $porDecidir = PantallaSolicitudes::pendientes(); ?>
			<?php if ( $porDecidir ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php echo (int) $porDecidir; ?> <?php echo 1 === $porDecidir ? 'cosa espera' : 'cosas esperan'; ?> tu decisión</strong> (solicitudes "olvidé marcar" y marcas por revisar). <a href="<?php echo esc_url( PantallaSolicitudes::url() ); ?>">Ir a Solicitudes</a></p></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function alertas(): void {
		$avisos = array();

		foreach ( Pantallas::sinConexion() as $p ) {
			$avisos[] = 'La pantalla <strong>' . esc_html( $p['nombre'] ) . '</strong> no se conecta hace más de 5 minutos (¿apagada o sin internet?).';
		}

		if ( current_user_can( Capacidades::AJUSTES ) && ! Presencia::filasRedes( true ) && ! Pantallas::activas() ) {
			$avisos[] = 'No hay redes del WiFi ni pantallas activas: nadie puede marcar. Configúralas en <strong>Asistencia → Ajustes</strong>.';
		}

		if ( current_user_can( Capacidades::AJUSTES ) ) {
			foreach ( Presencia::redesCandidatas() as $c ) {
				$avisos[] = '¿Cambió la IP del taller? Hoy llegaron <strong>' . (int) $c['marcas'] . ' marcas por QR</strong> de ' . (int) $c['trabajadores'] . ' personas desde <strong>' . esc_html( $c['red'] ) . '</strong>, que no está autorizada. '
					. 'Revísalo en <a href="' . esc_url( admin_url( 'admin.php?page=' . PantallaAjustes::SLUG ) ) . '">Asistencia → Ajustes</a>.';
			}
		}

		foreach ( $avisos as $aviso ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses( $aviso, array( 'strong' => array(), 'a' => array( 'href' => array() ) ) ) . '</p></div>';
		}
	}

	public static function revisiones( ?array $alcance, DateTimeZone $zona ): void {
		if ( ! current_user_can( Capacidades::APROBAR ) ) {
			return;
		}

		$pendientes = Revisiones::pendientes( $alcance );
		?>
		<h2 style="margin-top:32px" id="revisar">Marcas por revisar (<?php echo count( $pendientes ); ?>)</h2>
		<p class="description">Emergencias marcadas fuera del taller y salidas que requieren aprobación.</p>
		<?php if ( ! $pendientes ) : ?>
			<p>No hay nada pendiente.</p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped">
			<thead><tr><th>Trabajador</th><th>Cuándo</th><th>Motivo</th><th>Nota</th><th>Prueba</th><th>Decisión</th></tr></thead>
			<tbody>
			<?php foreach ( $pendientes as $m ) : ?>
				<?php $motivo = \Pekokis\Asistencia\WordPress\Motivos::porClave( $m['motivo_clave'] ); ?>
				<tr>
					<td><?php echo esc_html( $m['apellidos'] . ', ' . $m['nombres'] ); ?></td>
					<td><?php echo esc_html( ( new DateTimeImmutable( $m['marcado_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zona )->format( 'd/m H:i' ) ); ?></td>
					<td><?php echo esc_html( $motivo ? $motivo->nombre : (string) $m['motivo_clave'] ); ?></td>
					<td><?php echo esc_html( $m['nota'] ); ?></td>
					<td><?php echo esc_html( Marcas::etiquetaPrueba( PruebaPresencia::from( $m['prueba'] ) ) ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;flex-wrap:wrap">
							<input type="hidden" name="action" value="pka_revisar_marca">
							<input type="hidden" name="marca" value="<?php echo esc_attr( $m['id'] ); ?>">
							<?php wp_nonce_field( 'pka_revisar_marca' ); ?>
							<label class="screen-reader-text" for="pka-nota-<?php echo esc_attr( $m['id'] ); ?>">Nota</label>
							<input type="text" id="pka-nota-<?php echo esc_attr( $m['id'] ); ?>" name="nota" placeholder="Nota (obligatoria si rechazas)">
							<button class="button button-primary" name="decision" value="<?php echo esc_attr( Revisiones::APROBADA ); ?>">Aprobar</button>
							<button class="button" name="decision" value="<?php echo esc_attr( Revisiones::RECHAZADA ); ?>">Rechazar</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function revisar(): void {
		if ( ! current_user_can( Capacidades::APROBAR ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_revisar_marca' );

		$marca    = isset( $_POST['marca'] ) ? absint( $_POST['marca'] ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$nota     = isset( $_POST['nota'] ) ? sanitize_text_field( wp_unslash( $_POST['nota'] ) ) : '';
		$alcance  = self::alcance();

		// Un jefe solo revisa marcas de su equipo.
		$propia = array_values( array_filter( Revisiones::pendientes( $alcance ), static fn( array $m ): bool => (int) $m['id'] === $marca ) );

		if ( ! $propia ) {
			$result = new WP_Error( 'pka_alcance', 'Esa marca no está pendiente o no es de tu equipo.' );
		} elseif ( Trabajadores::esPropio( (int) $propia[0]['trabajador_id'] ) ) {
			$result = new WP_Error( 'pka_propia', 'No puedes revisar tus propias marcas: lo hace otra persona con permiso.' );
		} else {
			$result = Revisiones::resolver( $marca, $decision, $nota );
		}

		if ( $result instanceof WP_Error ) {
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', Revisiones::APROBADA === $decision ? 'Marca aprobada.' : 'Marca rechazada.' );
		}

		Menu::volver( PantallaSolicitudes::SLUG );
	}
}
