<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Celulares;
use Pekokis\Asistencia\WordPress\Pines;
use Pekokis\Asistencia\WordPress\Trabajadores;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Celulares (maquetas G13): arriba, los registros nuevos, cambios
 * y liberaciones que esperan aprobación; abajo, el celular de cada persona, que
 * se puede liberar. Todo queda en el historial.
 */
final class PantallaCelulares {
	public const SLUG = 'pka-celulares';

	public static function registrar(): void {
		add_action( 'admin_post_pka_resolver_celular', array( self::class, 'resolver' ) );
		add_action( 'admin_post_pka_liberar_celular', array( self::class, 'liberar' ) );
		add_action( 'admin_post_pka_quitar_pin', array( self::class, 'quitarPin' ) );
	}

	/** "Celulares (2)" en el menú si hay solicitudes esperando. */
	public static function titulo(): string {
		$n = Celulares::contarPendientes();

		return 'Celulares' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::GESTIONAR_PERSONAL ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$pendientes = Celulares::pendientes();
		$vigentes   = Celulares::vigentes();
		$fecha      = static fn( ?string $utc ): string => $utc ? Ajustes::local( $utc, 'j M, H:i' ) : '—';
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Celulares y llaves</h1>
			<p>Cada persona marca con <strong>un celular registrado</strong>. Las personas nuevas se registran solas desde el taller, y quien cambia de celular lo pide desde el celular nuevo (también en el taller). Nada de eso sirve hasta que lo apruebes aquí. El alta con enlace de activación (Trabajadores) sigue funcionando.</p>

			<h2>Esperando aprobación</h2>
			<table class="widefat striped">
				<thead><tr><th>Qué pide</th><th>Persona</th><th>Datos</th><th>Cuándo</th><th>Celular</th><th>Decisión</th></tr></thead>
				<tbody>
				<?php if ( ! $pendientes ) : ?>
					<tr><td colspan="6">No hay nada esperando.</td></tr>
				<?php endif; ?>
				<?php foreach ( $pendientes as $s ) : ?>
					<tr>
						<td><strong><?php echo esc_html( Celulares::TIPOS[ $s['tipo'] ] ?? $s['tipo'] ); ?></strong></td>
						<td><?php echo esc_html( $s['nombres'] . ' ' . $s['apellidos'] ); ?></td>
						<td>
							DNI <?php echo esc_html( self::dniOculto( (string) $s['dni'] ) ); ?><br>
							<?php echo esc_html( (string) $s['telefono'] ); ?>
							<?php if ( '' !== (string) $s['cumpleanos'] ) : ?>
								<br>Cumpleaños: <?php echo esc_html( wp_date( 'j \d\e F', strtotime( '2024-' . $s['cumpleanos'] . ' 12:00:00' ) ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $fecha( $s['creado_en'] ) ); ?></td>
						<td><span class="description"><?php echo esc_html( self::celularCorto( (string) $s['navegador'] ) ); ?></span></td>
						<td>
							<?php if ( Trabajadores::esPropio( (int) $s['trabajador_id'] ) ) : ?>
								<em>Es tu propio celular: que lo apruebe otra persona.</em>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila junta">
									<input type="hidden" name="action" value="pka_resolver_celular">
									<input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
									<?php wp_nonce_field( 'pka_resolver_celular' ); ?>
									<button class="button button-primary" name="decision" value="aprobar">Aprobar</button>
									<input class="pk-crece" type="text" name="respuesta" placeholder="Si rechazas, ¿por qué?">
									<button class="button" name="decision" value="rechazar">Rechazar</button>
								</form>
								<p class="description pk-mt-s">
									<?php
									echo esc_html(
										match ( $s['tipo'] ) {
											Celulares::REGISTRO => 'Al aprobar, la persona queda activa con el horario base: revisa su ficha (horario, área, jefe, honorario).',
											Celulares::CAMBIO   => 'Al aprobar, el celular anterior queda dado de baja. Sus datos y horas se conservan.',
											default             => 'Al aprobar, ese celular queda sin acceso. Sus datos y horas se conservan.',
										}
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h class="pk-mt"2>Celular de cada persona</h2>
			<table class="widefat striped">
				<thead><tr><th>Persona</th><th>Celular</th><th>Registrado</th><th>Último uso</th><th>PIN</th><th>Liberar</th></tr></thead>
				<tbody>
				<?php if ( ! $vigentes ) : ?>
					<tr><td colspan="6">Nadie tiene un celular registrado todavía.</td></tr>
				<?php endif; ?>
				<?php foreach ( $vigentes as $d ) : ?>
					<?php $t = Trabajadores::buscar( (int) $d['trabajador_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $d['apellidos'] . ', ' . $d['nombres'] ); ?></td>
						<td><span class="description"><?php echo esc_html( self::celularCorto( (string) $d['user_agent'] ) ); ?></span></td>
						<td><?php echo esc_html( $fecha( $d['creado_en'] ) ); ?></td>
						<td><?php echo esc_html( $fecha( $d['ultima_huella'] ?? $d['ultimo_uso'] ) ); ?></td>
						<td>
							<?php if ( $t && '' !== (string) $t['pin_hash'] ) : ?>
								Tiene · <a href="<?php echo esc_url( Menu::urlAccion( 'pka_quitar_pin', array( 'trabajador' => (int) $d['trabajador_id'] ) ) ); ?>" onclick="return confirm('¿Quitar el PIN de esta persona? Podrá crear otro desde su perfil.')">Quitar</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila junta">
								<input type="hidden" name="action" value="pka_liberar_celular">
								<input type="hidden" name="dispositivo" value="<?php echo (int) $d['id']; ?>">
								<?php wp_nonce_field( 'pka_liberar_celular' ); ?>
								<input class="pk-crece" type="text" name="motivo" placeholder="Motivo (perdió el celular…)" required>
								<button class="button" onclick="return confirm('Ese celular quedará sin acceso y la persona tendrá que registrar otro en el taller. Sus datos y horas se conservan. Queda en el historial. ¿Liberar?')">Liberar</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( ! Pines::activo() ) : ?>
				<p class="description">El PIN de ingreso está desactivado en Ajustes.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function resolver(): void {
		self::exigir( 'pka_resolver_celular' );

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$r        = 'aprobar' === $decision
			? Celulares::aprobar( $id )
			: Celulares::rechazar( $id, isset( $_POST['respuesta'] ) ? sanitize_text_field( wp_unslash( $_POST['respuesta'] ) ) : '' );

		if ( is_wp_error( $r ) ) {
			Avisos::agregar( 'error', esc_html( $r->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', 'aprobar' === $decision ? 'Aprobado.' : 'Rechazado. La persona verá el motivo.' );
		}

		Menu::volver( self::SLUG );
	}

	public static function liberar(): void {
		self::exigir( 'pka_liberar_celular' );

		$r = Celulares::liberar( isset( $_POST['dispositivo'] ) ? absint( $_POST['dispositivo'] ) : 0, isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '' );

		Avisos::agregar( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? esc_html( $r->get_error_message() ) : 'Celular liberado.' );
		Menu::volver( self::SLUG );
	}

	public static function quitarPin(): void {
		if ( ! current_user_can( Capacidades::GESTIONAR_PERSONAL ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_quitar_pin' );
		Pines::quitar( isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0, 'Lo quitó gerencia', false );
		Avisos::agregar( 'success', 'PIN quitado.' );
		Menu::volver( self::SLUG );
	}

	/** "•••• 8912". */
	public static function dniOculto( string $dni ): string {
		return "••••\u{00A0}" . substr( $dni, -4 ); // Espacio que no corta la línea.
	}

	/** "iPhone · Safari", "Android · Chrome"… a partir del navegador. */
	public static function celularCorto( string $ua ): string {
		$sistema = match ( true ) {
			str_contains( $ua, 'iPhone' )  => 'iPhone',
			str_contains( $ua, 'iPad' )    => 'iPad',
			str_contains( $ua, 'Android' ) => 'Android',
			str_contains( $ua, 'Windows' ) => 'Windows',
			str_contains( $ua, 'Mac OS' )  => 'Mac',
			default                         => 'Otro',
		};
		$navegador = match ( true ) {
			str_contains( $ua, 'Edg/' )                                    => 'Edge',
			str_contains( $ua, 'SamsungBrowser' )                          => 'Samsung Internet',
			str_contains( $ua, 'CriOS' ) || str_contains( $ua, 'Chrome' ) => 'Chrome',
			str_contains( $ua, 'Firefox' ) || str_contains( $ua, 'FxiOS' ) => 'Firefox',
			str_contains( $ua, 'Safari' )                                  => 'Safari',
			default                                                         => '',
		};

		return trim( $sistema . ( '' !== $navegador ? ' · ' . $navegador : '' ) );
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::GESTIONAR_PERSONAL ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
