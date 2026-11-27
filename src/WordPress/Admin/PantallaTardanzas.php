<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Tardanzas;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha → pestaña "Tardanzas": el contador, las amonestaciones y el historial
 * completo. Gerencia justifica una tardanza o reinicia el conteo (con motivo).
 */
final class PantallaTardanzas {
	public static function registrar(): void {
		add_action( 'admin_post_pka_justificar_tardanza', array( self::class, 'justificar' ) );
		add_action( 'admin_post_pka_reiniciar_tardanzas', array( self::class, 'reiniciar' ) );
	}

	/** @param array<string, mixed> $t */
	public static function pestana( array $t ): void {
		$id         = (int) $t['id'];
		$conteo     = Tardanzas::conteo( $id );
		$historial  = Tardanzas::historial( $id );
		$amonest    = count( array_filter( $historial, static fn( $f ) => (int) $f['amonestacion'] && Tardanzas::VIGENTE === $f['estado'] ) );
		$puede      = current_user_can( Capacidades::JUSTIFICAR );
		$nivel      = $conteo >= Tardanzas::CONVERSAR ? 'error' : ( $conteo >= 2 ? 'aviso' : 'ok' );
		?>
		<div class="pk-dos">
			<div class="pk-caja">
				<h2>Conteo de tardanzas</h2>
				<div class="pk-kpis dos">
					<div class="pk-kpi <?php echo 'error' === $nivel ? 'rosa' : ''; ?>"><span>Tardanzas que cuentan</span><b><?php echo (int) $conteo; ?></b><small><?php echo $conteo >= Tardanzas::CONVERSAR ? 'Debe conversar con gerencia' : ( $conteo ? 'Desde el último reinicio' : 'Sin tardanzas pendientes' ); ?></small></div>
					<div class="pk-kpi"><span>Amonestaciones</span><b><?php echo (int) $amonest; ?></b><small>En todo su historial</small></div>
				</div>
				<p class="description pk-mt-s">Es tardanza si la entrada pasa la tolerancia; los minutos se cuentan desde su hora de entrada. En la 2.ª y la 3.ª se registra una amonestación; desde la 4.ª tiene que conversar con gerencia. El conteo no se reinicia solo.</p>
				<?php if ( $puede ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila pk-mt-s">
						<input type="hidden" name="action" value="pka_reiniciar_tardanzas">
						<input type="hidden" name="trabajador" value="<?php echo (int) $id; ?>">
						<?php wp_nonce_field( 'pka_reiniciar_tardanzas' ); ?>
						<label class="screen-reader-text" for="pka-reinicio">Motivo del reinicio</label>
						<input type="text" id="pka-reinicio" name="motivo" class="regular-text" required placeholder="Motivo (por ejemplo: conversó con gerencia)">
						<button class="button" <?php disabled( 0 === $conteo ); ?>>Reiniciar conteo</button>
					</form>
					<p class="description">El historial de abajo se conserva; solo vuelve a cero el conteo.</p>
				<?php endif; ?>
			</div>
			<div class="pk-caja">
				<h2>Cómo se le avisa</h2>
				<p>Al marcar una entrada tarde, la app le muestra un mensaje según cuántas lleva: la 1.ª, un recordatorio; la 2.ª y la 3.ª, que se registró una amonestación; desde la 4.ª, que se acerque a gerencia. Los textos se cambian en <a href="<?php echo esc_url( PantallaAjustes::url( 'reglas' ) ); ?>">Seguridad y reglas → Reglas de horas</a>.</p>
			</div>
		</div>

		<h2 class="pk-mt">Historial</h2>
		<div class="pk-desplaza">
			<table class="widefat striped">
				<thead><tr><th>Fecha</th><th>Llegó</th><th>Tarde</th><th>N.º</th><th>Estado</th><th>Justificación</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $historial ) : ?>
					<tr><td colspan="7">No tiene tardanzas registradas.</td></tr>
				<?php endif; ?>
				<?php foreach ( $historial as $f ) : ?>
					<tr>
						<td class="pk-nowrap"><?php echo esc_html( wp_date( 'D j M Y', strtotime( $f['dia'] . ' 12:00' ) ) ); ?></td>
						<td><?php echo esc_html( Tardanzas::horaEntrada( $f ) ); ?></td>
						<td><?php echo (int) $f['minutos']; ?> min</td>
						<td><?php echo esc_html( Tardanzas::ordinal( (int) $f['numero'] ) ); ?><?php echo (int) $f['amonestacion'] ? ' <span class="pk-chip error">Amonestación</span>' : ''; ?></td>
						<td><span class="pk-chip <?php echo esc_attr( Tardanzas::VIGENTE === $f['estado'] ? 'aviso' : ( Tardanzas::JUSTIFICADA === $f['estado'] ? 'ok' : 'gris' ) ); ?>"><?php echo esc_html( Tardanzas::ESTADOS[ $f['estado'] ] ?? $f['estado'] ); ?></span></td>
						<td><?php echo esc_html( (string) $f['justificacion'] ); ?></td>
						<td>
							<?php if ( $puede && Tardanzas::VIGENTE === $f['estado'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila">
									<input type="hidden" name="action" value="pka_justificar_tardanza">
									<input type="hidden" name="id" value="<?php echo (int) $f['id']; ?>">
									<?php wp_nonce_field( 'pka_justificar_tardanza' ); ?>
									<label class="screen-reader-text" for="pka-just-<?php echo (int) $f['id']; ?>">Motivo</label>
									<input type="text" id="pka-just-<?php echo (int) $f['id']; ?>" name="motivo" required placeholder="Motivo">
									<button class="button">Justificar</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function justificar(): void {
		check_admin_referer( 'pka_justificar_tardanza' );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$fila   = Tardanzas::porId( $id );
		$r      = Tardanzas::justificar( $id, $motivo );

		self::avisar( $r, 'Tardanza justificada: ya no cuenta.' );
		Menu::volverA( PantallaFicha::url( $fila ? (int) $fila['trabajador_id'] : 0, 'tardanzas' ) );
	}

	public static function reiniciar(): void {
		check_admin_referer( 'pka_reiniciar_tardanzas' );

		$id     = isset( $_POST['trabajador'] ) ? absint( $_POST['trabajador'] ) : 0;
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';

		self::avisar( Tardanzas::reiniciar( $id, $motivo ), 'Conteo reiniciado. El historial se conserva.' );
		Menu::volverA( PantallaFicha::url( $id, 'tardanzas' ) );
	}

	private static function avisar( true|WP_Error $r, string $exito ): void {
		Avisos::agregar( $r instanceof WP_Error ? 'error' : 'success', esc_html( $r instanceof WP_Error ? $r->get_error_message() : $exito ) );
	}
}
