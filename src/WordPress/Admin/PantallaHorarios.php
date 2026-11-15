<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\ValidacionHorario;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Horarios;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Horarios: crear y editar horarios (días, horas, tolerancia y refrigerio).
 */
final class PantallaHorarios {
	public const SLUG = 'pka-horarios';

	public static function registrar(): void {
		add_action( 'admin_post_pka_guardar_horario', array( self::class, 'guardar' ) );
		add_action( 'admin_post_pka_estado_horario', array( self::class, 'estado' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::HORARIOS ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$vista = isset( $_GET['vista'] ) ? sanitize_key( $_GET['vista'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		Avisos::mostrar();

		if ( 'editar' === $vista ) {
			self::formulario( $id ? Horarios::completo( $id ) : null );
		} else {
			self::lista();
		}

		echo '</div>';
	}

	private static function lista(): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<h1 class="wp-heading-inline">Horarios</h1>
		<a href="<?php echo esc_url( add_query_arg( 'vista', 'editar', $base ) ); ?>" class="page-title-action">Agregar horario</a>
		<hr class="wp-header-end">
		<p>Cada trabajador tiene un horario (se elige en su perfil). Con él se calculan las tardanzas, los ausentes y los días laborables.</p>

		<table class="widefat striped">
			<thead><tr><th>Horario</th><th>Días y horas</th><th>Tolerancia</th><th>Refrigerio</th><th>Horas/semana</th><th>Trabajadores</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( Horarios::listar() as $fila ) : ?>
				<?php $h = Horarios::completo( (int) $fila['id'] ); ?>
				<tr <?php echo $fila['activo'] ? '' : 'style="opacity:.6"'; ?>>
					<td><strong><?php echo esc_html( $fila['nombre'] ); ?></strong><?php echo $fila['activo'] ? '' : ' (desactivado)'; ?></td>
					<td><?php echo esc_html( self::resumenDias( $h ) ); ?></td>
					<td><?php echo (int) $fila['tolerancia_min']; ?> min</td>
					<td><?php echo (int) $fila['refrigerio_min']; ?> min</td>
					<td><?php echo esc_html( number_format_i18n( ValidacionHorario::horasSemana( $h ), 1 ) ); ?> h</td>
					<td><?php echo (int) $fila['trabajadores']; ?></td>
					<td>
						<a href="<?php echo esc_url( add_query_arg( array( 'vista' => 'editar', 'id' => $fila['id'] ), $base ) ); ?>">Editar</a> |
						<a href="<?php echo esc_url( Menu::urlAccion( 'pka_estado_horario', array( 'id' => $fila['id'], 'activo' => $fila['activo'] ? 0 : 1 ) ) ); ?>"><?php echo $fila['activo'] ? 'Desactivar' : 'Activar'; ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string, mixed>|null $h */
	private static function formulario( ?array $h ): void {
		$previo  = get_transient( 'pka_form_horario_' . get_current_user_id() );
		$errores = is_array( $previo ) ? $previo['errores'] : array();
		$valores = is_array( $previo ) ? $previo['valores'] : ( $h ?? array(
			'nombre'         => '',
			'tolerancia_min' => 5,
			'refrigerio_min' => 60,
			'dias'           => array(),
		) );

		delete_transient( 'pka_form_horario_' . get_current_user_id() );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">← Volver a Horarios</a></p>
		<h1><?php echo $h ? 'Editar horario' : 'Agregar horario'; ?></h1>

		<?php if ( $h ) : ?>
			<div class="notice notice-info inline"><p>
				Cambiar un horario también cambia cómo se ven los días anteriores (tardanzas y ausentes) de quienes lo usan.
				Si el cambio es solo desde una fecha, mejor crea un horario nuevo y asígnalo a los trabajadores.
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_guardar_horario">
			<input type="hidden" name="id" value="<?php echo esc_attr( $h ? (string) $h['id'] : '' ); ?>">
			<?php wp_nonce_field( 'pka_guardar_horario' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pka-h-nombre">Nombre</label></th>
					<td><input type="text" id="pka-h-nombre" name="nombre" class="regular-text" required value="<?php echo esc_attr( (string) $valores['nombre'] ); ?>"><?php self::error( $errores, 'nombre' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-h-tolerancia">Tolerancia</label></th>
					<td><input type="number" id="pka-h-tolerancia" name="tolerancia_min" min="0" max="60" value="<?php echo esc_attr( (string) $valores['tolerancia_min'] ); ?>"> minutos
						<p class="description">Si llega después de la hora de entrada más la tolerancia, cuenta como tardanza.</p><?php self::error( $errores, 'tolerancia_min' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-h-refrigerio">Refrigerio</label></th>
					<td><input type="number" id="pka-h-refrigerio" name="refrigerio_min" min="0" max="180" value="<?php echo esc_attr( (string) $valores['refrigerio_min'] ); ?>"> minutos
						<p class="description">Mínimo legal: 45 minutos. Si se pasa de este tiempo, el panel lo avisa.</p><?php self::error( $errores, 'refrigerio_min' ); ?></td>
				</tr>
			</table>

			<h2>Días</h2>
			<?php self::error( $errores, 'dias' ); ?>
			<table class="widefat striped" style="max-width:640px">
				<thead><tr><th>Día</th><th>Laborable</th><th>Entrada</th><th>Salida</th></tr></thead>
				<tbody>
				<?php foreach ( ValidacionHorario::DIAS as $n => $nombreDia ) : ?>
					<?php $d = $valores['dias'][ $n ] ?? array( 'laborable' => false, 'entrada' => null, 'salida' => null ); ?>
					<tr>
						<td><label for="pka-h-lab-<?php echo (int) $n; ?>"><?php echo esc_html( $nombreDia ); ?></label></td>
						<td><input type="checkbox" id="pka-h-lab-<?php echo (int) $n; ?>" name="dias[<?php echo (int) $n; ?>][laborable]" value="1" <?php checked( ! empty( $d['laborable'] ) ); ?>></td>
						<td><label class="screen-reader-text" for="pka-h-ent-<?php echo (int) $n; ?>">Entrada del <?php echo esc_html( $nombreDia ); ?></label><input type="time" id="pka-h-ent-<?php echo (int) $n; ?>" name="dias[<?php echo (int) $n; ?>][entrada]" value="<?php echo esc_attr( substr( (string) ( $d['entrada'] ?? '' ), 0, 5 ) ); ?>"></td>
						<td><label class="screen-reader-text" for="pka-h-sal-<?php echo (int) $n; ?>">Salida del <?php echo esc_html( $nombreDia ); ?></label><input type="time" id="pka-h-sal-<?php echo (int) $n; ?>" name="dias[<?php echo (int) $n; ?>][salida]" value="<?php echo esc_attr( substr( (string) ( $d['salida'] ?? '' ), 0, 5 ) ); ?>">
							<?php self::error( $errores, 'dia_' . $n ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $h ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pka-h-motivo">Motivo del cambio</label></th>
						<td><input type="text" id="pka-h-motivo" name="motivo" class="regular-text" required><p class="description">Queda en el historial.</p><?php self::error( $errores, 'motivo' ); ?></td>
					</tr>
				</table>
			<?php endif; ?>

			<p class="description">De lunes a viernes, lo que pase de esta jornada (sin la comida) se paga como hora extra con la tarifa de cada persona.</p>
			<?php submit_button( $h ? 'Guardar cambios' : 'Agregar horario' ); ?>
		</form>
		<?php
	}

	public static function guardar(): void {
		self::exigir( 'pka_guardar_horario' );

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$entrada = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$motivo  = (string) ( $entrada['motivo'] ?? '' );
		$result  = Horarios::guardar( $entrada, $id ? $id : null, $motivo );

		if ( $result instanceof WP_Error ) {
			set_transient(
				'pka_form_horario_' . get_current_user_id(),
				array( 'errores' => $result->get_error_data()['errores'] ?? array(), 'valores' => ValidacionHorario::normalizar( $entrada ) ),
				5 * MINUTE_IN_SECONDS
			);
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
			Menu::volver( self::SLUG, array( 'vista' => 'editar' ) + ( $id ? array( 'id' => $id ) : array() ) );
		}

		Avisos::agregar( 'success', $id ? 'Horario actualizado.' : 'Horario creado. Asígnalo a los trabajadores desde su perfil.' );
		Menu::volver( self::SLUG );
	}

	public static function estado(): void {
		self::exigir( 'pka_estado_horario' );

		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$activo = ! empty( $_GET['activo'] );
		$result = Horarios::cambiarEstado( $id, $activo );

		if ( $result instanceof WP_Error ) {
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', $activo ? 'Horario activado.' : 'Horario desactivado.' );
		}

		Menu::volver( self::SLUG );
	}

	/** "Lun–Vie 09:00–19:30 · Sáb 09:00–13:00" */
	private static function resumenDias( ?array $h ): string {
		if ( ! $h ) {
			return '—';
		}

		$grupos = array();

		foreach ( ValidacionHorario::DIAS as $n => $nombre ) {
			$d = $h['dias'][ $n ];

			if ( ! $d['laborable'] ) {
				continue;
			}

			$horas  = substr( (string) $d['entrada'], 0, 5 ) . '–' . substr( (string) $d['salida'], 0, 5 );
			$ultimo = count( $grupos ) - 1;

			if ( $ultimo >= 0 && $grupos[ $ultimo ]['horas'] === $horas && $grupos[ $ultimo ]['hasta'] === $n - 1 ) {
				$grupos[ $ultimo ]['hasta'] = $n;
			} else {
				$grupos[] = array( 'desde' => $n, 'hasta' => $n, 'horas' => $horas );
			}
		}

		$corto = static fn( int $n ): string => mb_substr( ValidacionHorario::DIAS[ $n ], 0, 3 );

		return implode(
			' · ',
			array_map( static fn( $g ) => ( $g['desde'] === $g['hasta'] ? $corto( $g['desde'] ) : $corto( $g['desde'] ) . '–' . $corto( $g['hasta'] ) ) . ' ' . $g['horas'], $grupos )
		);
	}

	/** @param array<string, string> $errores */
	private static function error( array $errores, string $campo ): void {
		if ( isset( $errores[ $campo ] ) ) {
			echo '<p class="description" style="color:#b32d2e">' . esc_html( $errores[ $campo ] ) . '</p>';
		}
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::HORARIOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
