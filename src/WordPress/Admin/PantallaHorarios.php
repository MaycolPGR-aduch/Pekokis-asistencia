<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\ValidacionHorario;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Horarios;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Horarios (maqueta G7): el horario de cada persona por día de la
 * semana, sus cambios por un día o un periodo, y los horarios base (días, horas,
 * tolerancia y refrigerio).
 */
final class PantallaHorarios {
	public const SLUG = 'pka-horarios';

	public static function registrar(): void {
		add_action( 'admin_post_pka_guardar_horario', array( self::class, 'guardar' ) );
		add_action( 'admin_post_pka_estado_horario', array( self::class, 'estado' ) );
		add_action( 'admin_post_pka_horarios_personas', array( self::class, 'guardarPersonas' ) );
		add_action( 'admin_post_pka_cambio_horario', array( self::class, 'guardarCambio' ) );
		add_action( 'admin_post_pka_anular_cambio_horario', array( self::class, 'anularCambio' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::HORARIOS ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$vista = isset( $_GET['vista'] ) ? sanitize_key( $_GET['vista'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		Avisos::mostrar();

		match ( $vista ) {
			'editar' => self::formulario( $id ? Horarios::completo( $id ) : null ),
			'base'   => self::lista(),
			'cambio' => self::formularioCambio(),
			default  => self::personas(),
		};

		echo '</div>';
	}

	private static function lista(): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<h1>Horarios</h1>
		<?php self::pestanas( 'base' ); ?>
		<p class="pk-fila espacio pk-mt-s">
			<span class="description">Cada persona usa un horario base (se elige en "Por persona" o en su perfil). Con él se calculan las tardanzas, los ausentes y los días laborables.</span>
			<a href="<?php echo esc_url( add_query_arg( 'vista', 'editar', $base ) ); ?>" class="button">+ Agregar horario base</a>
		</p>

		<table class="widefat striped">
			<thead><tr><th>Horario</th><th>Días y horas</th><th>Tolerancia</th><th>Refrigerio</th><th>Horas/semana</th><th>Trabajadores</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( Horarios::listar() as $fila ) : ?>
				<?php $h = Horarios::completo( (int) $fila['id'] ); ?>
				<tr <?php echo $fila['activo'] ? '' : 'class="pk-atenuado"'; ?>>
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
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&vista=base' ) ); ?>">← Volver a Horarios base</a></p>
		<h1><?php echo $h ? 'Editar horario base' : 'Agregar horario base'; ?></h1>

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
			<table class="widefat striped pk-max-s">
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

	/** Pestañas de la pantalla (maqueta G7). */
	private static function pestanas( string $activa ): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<nav class="pk-pestanas pk-mt-s" aria-label="Horarios">
			<a href="<?php echo esc_url( $base ); ?>" class="<?php echo 'personas' === $activa ? 'activa' : ''; ?>" <?php echo 'personas' === $activa ? 'aria-current="page"' : ''; ?>>Por persona</a>
			<a href="<?php echo esc_url( add_query_arg( 'vista', 'base', $base ) ); ?>" class="<?php echo 'base' === $activa ? 'activa' : ''; ?>" <?php echo 'base' === $activa ? 'aria-current="page"' : ''; ?>>Horarios base</a>
		</nav>
		<?php
	}

	/**
	 * Maqueta G7: horario de cada persona por día de la semana elegida. Lo que
	 * viene de un cambio por un día o un periodo va en azul; el horario base de
	 * cada persona se cambia en la misma tabla (lo cambiado se resalta).
	 */
	private static function personas(): void {
		$zona    = \Pekokis\Asistencia\WordPress\Ajustes::zona();
		$hoy     = new \DateTimeImmutable( 'now', $zona );
		$pedida  = isset( $_GET['semana'] ) ? sanitize_text_field( wp_unslash( $_GET['semana'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$inicio  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pedida ) ? new \DateTimeImmutable( $pedida, $zona ) : $hoy;
		$lunes   = $inicio->modify( '-' . ( (int) $inicio->format( 'N' ) - 1 ) . ' days' )->setTime( 0, 0 );
		$dias    = array();
		$base    = admin_url( 'admin.php?page=' . self::SLUG );
		$plant   = \Pekokis\Asistencia\WordPress\Trabajadores::horarios();
		$reglas  = \Pekokis\Asistencia\WordPress\Ajustes::todos();
		$cambios = Horarios::cambiosProximos( $hoy->format( 'Y-m-d' ) );

		for ( $i = 0; $i < 7; $i++ ) {
			$dias[] = $lunes->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
		}

		$etiqueta = static fn( string $d ): string => wp_date( 'j M', ( new \DateTimeImmutable( $d . ' 12:00', $zona ) )->getTimestamp(), $zona );
		?>
		<h1>Horarios <span class="pk-h1-der" id="pk-sin-guardar" hidden>0 cambios sin guardar</span></h1>
		<?php self::pestanas( 'personas' ); ?>

		<p class="description pk-mt-s">Horario de cada persona por día de la semana. La comida se descuenta según las reglas (<?php echo (int) $reglas['comida_no_marcada_min']; ?> min si no la marcan). Un cambio por un día o por un periodo se crea con el botón de abajo.</p>

		<p class="pk-fila espacio">
			<a class="button" href="<?php echo esc_url( add_query_arg( 'semana', $lunes->modify( '-7 days' )->format( 'Y-m-d' ), $base ) ); ?>" aria-label="Semana anterior">‹</a>
			<strong>Semana del <?php echo esc_html( $etiqueta( $dias[0] ) . ' al ' . $etiqueta( $dias[6] ) ); ?></strong>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'semana', $lunes->modify( '+7 days' )->format( 'Y-m-d' ), $base ) ); ?>" aria-label="Semana siguiente">›</a>
			<?php if ( $lunes->format( 'Y-m-d' ) !== $hoy->modify( '-' . ( (int) $hoy->format( 'N' ) - 1 ) . ' days' )->format( 'Y-m-d' ) ) : ?>
				<a class="button-link" href="<?php echo esc_url( $base ); ?>">Esta semana</a>
			<?php endif; ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-sigue">
			<input type="hidden" name="action" value="pka_horarios_personas">
			<?php wp_nonce_field( 'pka_horarios_personas' ); ?>
			<div class="pk-desplaza">
				<table class="widefat striped pk-horario-semana">
					<thead><tr>
						<th>Persona</th><th>Horario base</th>
						<?php foreach ( $dias as $d ) : ?>
							<th><?php echo esc_html( mb_substr( ValidacionHorario::DIAS[ (int) ( new \DateTimeImmutable( $d ) )->format( 'N' ) ], 0, 3 ) ); ?> <span class="pk-dia-num"><?php echo esc_html( (string) (int) substr( $d, 8, 2 ) ); ?></span></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ( \Pekokis\Asistencia\WordPress\Trabajadores::listar() as $t ) : ?>
						<tr>
							<td class="pk-nowrap"><span class="pk-av"><?php echo esc_html( mb_strtoupper( mb_substr( trim( (string) $t['nombres'] ), 0, 1 ) . mb_substr( trim( (string) $t['apellidos'] ), 0, 1 ) ) ); ?></span><?php echo esc_html( $t['nombres'] . ' ' . $t['apellidos'] ); ?></td>
							<td>
								<label class="screen-reader-text" for="pka-hb-<?php echo (int) $t['id']; ?>">Horario base de <?php echo esc_html( $t['nombres'] ); ?></label>
								<select id="pka-hb-<?php echo (int) $t['id']; ?>" name="horario[<?php echo (int) $t['id']; ?>]">
									<?php foreach ( $plant as $hid => $nombre ) : ?>
										<option value="<?php echo (int) $hid; ?>" <?php selected( (int) $t['horario_id'], (int) $hid ); ?>><?php echo esc_html( $nombre ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<?php foreach ( $dias as $d ) : ?>
								<?php
								$h      = Horarios::deTrabajador( $t, $d );
								$cambio = Horarios::cambioDe( (int) $t['id'], $d );
								?>
								<td>
									<?php if ( $h->laborable ) : ?>
										<span class="pk-hora<?php echo $cambio ? ' nuevo' : ''; ?>" <?php echo $cambio ? 'title="' . esc_attr( 'Cambio: ' . $cambio['motivo'] ) . '"' : ''; ?>><?php echo esc_html( substr( (string) $h->entrada, 0, 5 ) . '–' . substr( (string) $h->salida, 0, 5 ) ); ?></span>
									<?php elseif ( $h->feriado ) : ?>
										<span class="pk-chip gris" title="<?php echo esc_attr( $h->descanso ); ?>">Feriado</span>
									<?php elseif ( $cambio ) : ?>
										<span class="pk-hora nuevo" title="<?php echo esc_attr( 'Cambio: ' . $cambio['motivo'] ); ?>">Libre</span>
									<?php else : ?>
										<span class="pk-libre">—</span>
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="pk-fila espacio pk-mt-s">
				<label for="pka-hb-motivo">Motivo del cambio de horario base</label>
				<input type="text" id="pka-hb-motivo" name="motivo" class="regular-text" placeholder="Obligatorio si cambias un horario base">
				<button class="button button-primary">Guardar</button>
			</p>
		</form>

		<p class="pk-fila espacio">
			<a class="button" href="<?php echo esc_url( add_query_arg( 'vista', 'cambio', $base ) ); ?>">+ Cambio por un día o un periodo</a>
			<span class="description">En azul, un cambio ya cargado. En cada horario base se edita el horario de todas las personas que lo usan.</span>
		</p>

		<?php if ( $cambios ) : ?>
			<h2 class="pk-mt">Cambios cargados</h2>
			<table class="widefat striped pk-max-m">
				<thead><tr><th>Persona</th><th>Días</th><th>Horario</th><th>Motivo</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $cambios as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c['nombres'] . ' ' . $c['apellidos'] ); ?></td>
						<td class="pk-nowrap"><?php echo esc_html( $c['desde'] === $c['hasta'] ? $etiqueta( $c['desde'] ) : $etiqueta( $c['desde'] ) . ' al ' . $etiqueta( $c['hasta'] ) ); ?></td>
						<td><span class="pk-hora nuevo"><?php echo esc_html( (int) $c['laborable'] ? substr( (string) $c['entrada'], 0, 5 ) . '–' . substr( (string) $c['salida'], 0, 5 ) : 'Libre' ); ?></span></td>
						<td><?php echo esc_html( (string) $c['motivo'] ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila">
								<input type="hidden" name="action" value="pka_anular_cambio_horario">
								<input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
								<?php wp_nonce_field( 'pka_anular_cambio_horario' ); ?>
								<label class="screen-reader-text" for="pka-anular-<?php echo (int) $c['id']; ?>">Motivo para anular</label>
								<input type="text" id="pka-anular-<?php echo (int) $c['id']; ?>" name="motivo" placeholder="Motivo para anular" required>
								<button class="button-link pk-txt-error">Anular</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/** Formulario "+ Cambio por un día o un periodo" (maqueta G7). */
	private static function formularioCambio(): void {
		$previo  = get_transient( 'pka_form_cambio_horario_' . get_current_user_id() );
		$errores = is_array( $previo ) ? $previo['errores'] : array();
		$v       = is_array( $previo ) ? $previo['valores'] : array();
		$v      += array( 'trabajador_id' => isset( $_GET['trabajador'] ) ? absint( $_GET['trabajador'] ) : 0, 'desde' => '', 'hasta' => '', 'libre' => '', 'entrada' => '09:00', 'salida' => '14:00', 'motivo' => '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		delete_transient( 'pka_form_cambio_horario_' . get_current_user_id() );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">← Volver a Horarios</a></p>
		<h1>Cambio de horario por un día o un periodo</h1>
		<p class="description">Manda sobre el horario base de la persona solo esos días. Sirve para un sábado de refuerzo, un turno distinto en campaña o un día libre. Queda en la auditoría.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-caja pk-max-s pk-mt-s">
			<input type="hidden" name="action" value="pka_cambio_horario">
			<?php wp_nonce_field( 'pka_cambio_horario' ); ?>
			<p class="pk-campo">
				<label for="pka-c-persona">Persona</label>
				<select id="pka-c-persona" name="trabajador_id" required>
					<option value="">Elige…</option>
					<?php foreach ( \Pekokis\Asistencia\WordPress\Trabajadores::listar() as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>" <?php selected( (int) $v['trabajador_id'], (int) $t['id'] ); ?>><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php self::error( $errores, 'trabajador_id' ); ?>
			</p>
			<p class="pk-fila">
				<span class="pk-campo"><label for="pka-c-desde">Desde</label><input type="date" id="pka-c-desde" name="desde" required value="<?php echo esc_attr( (string) $v['desde'] ); ?>"></span>
				<span class="pk-campo"><label for="pka-c-hasta">Hasta (inclusive)</label><input type="date" id="pka-c-hasta" name="hasta" value="<?php echo esc_attr( (string) $v['hasta'] ); ?>"></span>
			</p>
			<?php self::error( $errores, 'desde' ); ?>
			<?php self::error( $errores, 'hasta' ); ?>
			<p class="description">Si es un solo día, deja "Hasta" vacío.</p>
			<fieldset class="pk-mt-s">
				<legend class="screen-reader-text">Qué cambia</legend>
				<p><label><input type="radio" name="libre" value="" <?php checked( '' === (string) $v['libre'] ); ?>> Trabaja con este horario</label></p>
				<p class="pk-fila">
					<span class="pk-campo"><label for="pka-c-entrada">Entrada</label><input type="time" id="pka-c-entrada" name="entrada" value="<?php echo esc_attr( (string) $v['entrada'] ); ?>"></span>
					<span class="pk-campo"><label for="pka-c-salida">Salida</label><input type="time" id="pka-c-salida" name="salida" value="<?php echo esc_attr( (string) $v['salida'] ); ?>"></span>
				</p>
				<?php self::error( $errores, 'entrada' ); ?>
				<p><label><input type="radio" name="libre" value="1" <?php checked( '1' === (string) $v['libre'] ); ?>> No trabaja (día libre)</label></p>
			</fieldset>
			<p class="pk-campo">
				<label for="pka-c-motivo">Motivo</label>
				<input type="text" id="pka-c-motivo" name="motivo" class="regular-text" required placeholder="Ej.: refuerzo de campaña" value="<?php echo esc_attr( (string) $v['motivo'] ); ?>">
				<?php self::error( $errores, 'motivo' ); ?>
			</p>
			<p class="pk-nota-rosa"><span class="dashicons dashicons-shield" aria-hidden="true"></span> Este cambio queda en la auditoría y cambia cómo se calculan las tardanzas, las ausencias y las horas de esos días.</p>
			<?php submit_button( 'Guardar cambio', 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function guardarPersonas(): void {
		self::exigir( 'pka_horarios_personas' );

		$pedidos = isset( $_POST['horario'] ) && is_array( $_POST['horario'] ) ? array_map( 'absint', wp_unslash( $_POST['horario'] ) ) : array();
		$motivo  = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$cambia  = array();

		foreach ( \Pekokis\Asistencia\WordPress\Trabajadores::listar() as $t ) {
			$nuevo = $pedidos[ (int) $t['id'] ] ?? 0;
			if ( $nuevo && $nuevo !== (int) $t['horario_id'] ) {
				$cambia[ (int) $t['id'] ] = $nuevo;
			}
		}

		if ( ! $cambia ) {
			Avisos::agregar( 'info', 'No cambiaste ningún horario base.' );
			Menu::volver( self::SLUG );
		}

		if ( '' === trim( $motivo ) ) {
			Avisos::agregar( 'error', 'Escribe el motivo del cambio de horario base: queda en la auditoría. No se guardó nada.' );
			Menu::volver( self::SLUG );
		}

		foreach ( $cambia as $id => $horario ) {
			Horarios::asignar( $id, $horario, $motivo );
		}

		Avisos::agregar( 'success', 1 === count( $cambia ) ? 'Horario base cambiado a 1 persona.' : 'Horario base cambiado a ' . count( $cambia ) . ' personas.' );
		Menu::volver( self::SLUG );
	}

	public static function guardarCambio(): void {
		self::exigir( 'pka_cambio_horario' );

		$entrada = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$result  = Horarios::crearCambio( $entrada );

		if ( $result instanceof WP_Error ) {
			set_transient( 'pka_form_cambio_horario_' . get_current_user_id(), array( 'errores' => $result->get_error_data()['errores'] ?? array(), 'valores' => $entrada ), 5 * MINUTE_IN_SECONDS );
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
			Menu::volver( self::SLUG, array( 'vista' => 'cambio' ) );
		}

		Avisos::agregar( 'success', 'Cambio de horario guardado.' );
		Menu::volver( self::SLUG, array( 'semana' => (string) ( $entrada['desde'] ?? '' ) ) );
	}

	public static function anularCambio(): void {
		self::exigir( 'pka_anular_cambio_horario' );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$result = Horarios::anularCambio( $id, $motivo );

		if ( $result instanceof WP_Error ) {
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', 'Cambio de horario anulado.' );
		}

		Menu::volver( self::SLUG );
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

		Avisos::agregar( 'success', $id ? 'Horario actualizado.' : 'Horario creado. Asígnalo en "Por persona" o desde el perfil.' );
		Menu::volver( self::SLUG, array( 'vista' => 'base' ) );
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

		Menu::volver( self::SLUG, array( 'vista' => 'base' ) );
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
			echo '<p class="description pk-txt-error">' . esc_html( $errores[ $campo ] ) . '</p>';
		}
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::HORARIOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
