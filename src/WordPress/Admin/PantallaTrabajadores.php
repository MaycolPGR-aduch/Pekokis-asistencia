<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Activaciones;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Sueldos;
use Pekokis\Asistencia\WordPress\Trabajadores;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Trabajadores: alta, edición, enlace de activación y cese.
 */
final class PantallaTrabajadores {
	public const SLUG = 'pka-trabajadores';

	private const CAMPOS = array(
		'dni'           => 'DNI',
		'nombres'       => 'Nombres',
		'apellidos'     => 'Apellidos',
		'cargo'         => 'Cargo',
		'area'          => 'Área',
		'telefono'      => 'Teléfono',
		'correo'        => 'Correo',
		'fecha_ingreso' => 'Fecha de ingreso',
	);

	public static function registrar(): void {
		add_action( 'admin_post_pka_guardar_trabajador', array( self::class, 'guardar' ) );
		add_action( 'admin_post_pka_enlace_activacion', array( self::class, 'enlace' ) );
		add_action( 'admin_post_pka_cesar_trabajador', array( self::class, 'cesar' ) );
		add_action( 'admin_post_pka_sueldo_agregar', array( self::class, 'agregarSueldo' ) );
		add_action( 'admin_post_pka_sueldo_anular', array( self::class, 'anularSueldo' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::GESTIONAR_PERSONAL ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$vista = isset( $_GET['vista'] ) ? sanitize_key( $_GET['vista'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		Avisos::mostrar();

		if ( 'ficha' === $vista && $id && Trabajadores::buscar( $id ) ) {
			PantallaFicha::pintar( Trabajadores::buscar( $id ) );
		} elseif ( 'editar' === $vista ) {
			self::formulario( $id ? Trabajadores::buscar( $id ) : null );
		} elseif ( 'cesar' === $vista && $id ) {
			self::formularioCese( Trabajadores::buscar( $id ) );
		} else {
			self::lista();
		}

		echo '</div>';
	}

	private static function lista(): void {
		$estado   = isset( $_GET['estado'] ) && Trabajadores::CESADO === $_GET['estado'] ? Trabajadores::CESADO : Trabajadores::ACTIVO; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$busqueda = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filas    = Trabajadores::listar( $estado, $busqueda );
		$base     = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<h1>Perfiles de trabajadores <a href="<?php echo esc_url( add_query_arg( 'vista', 'editar', $base ) ); ?>" class="page-title-action">+ Nuevo trabajador</a></h1>
		<hr class="wp-header-end">

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( $base ); ?>" <?php echo Trabajadores::ACTIVO === $estado ? 'class="current"' : ''; ?>>Activos</a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'estado', Trabajadores::CESADO, $base ) ); ?>" <?php echo Trabajadores::CESADO === $estado ? 'class="current"' : ''; ?>>Cesados</a></li>
		</ul>

		<form method="get" class="search-form pk-derecha">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="estado" value="<?php echo esc_attr( $estado ); ?>">
			<label class="screen-reader-text" for="pka-buscar">Buscar trabajador</label>
			<input type="search" id="pka-buscar" name="s" value="<?php echo esc_attr( $busqueda ); ?>" placeholder="Nombre, DNI o área">
			<button class="button">Buscar</button>
		</form>

		<table class="widefat striped pk-limpia">
			<thead>
				<tr><th>Nombre</th><th>Celular o WhatsApp</th><th>DNI</th><th>Cumpleaños</th><th>Celular para marcar</th><th>Acciones</th></tr>
			</thead>
			<tbody>
			<?php if ( ! $filas ) : ?>
				<tr><td colspan="6">No hay trabajadores <?php echo Trabajadores::ACTIVO === $estado ? 'activos' : 'cesados'; ?>.</td></tr>
			<?php endif; ?>
			<?php foreach ( $filas as $t ) : ?>
				<tr>
					<td><span class="pk-av"><?php echo esc_html( mb_strtoupper( mb_substr( (string) $t['nombres'], 0, 1 ) . mb_substr( (string) $t['apellidos'], 0, 1 ) ) ); ?></span><a href="<?php echo esc_url( PantallaFicha::url( (int) $t['id'] ) ); ?>"><strong><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></strong></a><?php echo '' !== trim( $t['cargo'] . $t['area'] ) ? '<br><span class="description">' . esc_html( trim( $t['cargo'] . ' · ' . $t['area'], ' ·' ) ) . '</span>' : ''; ?></td>
					<td><?php echo esc_html( '' !== (string) $t['telefono'] ? (string) $t['telefono'] : '—' ); ?></td>
					<td><?php echo esc_html( PantallaCelulares::dniOculto( (string) $t['dni'] ) ); ?></td>
					<td><?php echo '' !== (string) $t['cumpleanos'] ? esc_html( wp_date( 'j M', strtotime( '2024-' . $t['cumpleanos'] . ' 12:00:00' ) ) ) : '<span class="description">—</span>'; ?></td>
					<td><?php echo (int) $t['celulares'] > 0 ? '<span class="pk-chip ok">Registrado</span>' : '<span class="pk-chip aviso">Sin celular</span>'; ?></td>
					<td>
						<a href="<?php echo esc_url( PantallaFicha::url( (int) $t['id'] ) ); ?>">Ver ficha</a> |
						<?php if ( Trabajadores::ACTIVO === $t['estado'] ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'vista' => 'editar', 'id' => $t['id'] ), $base ) ); ?>">Editar</a> |
							<a href="<?php echo esc_url( Menu::urlAccion( 'pka_enlace_activacion', array( 'id' => $t['id'] ) ) ); ?>"
								<?php echo (int) $t['celulares'] > 0 ? 'onclick="return confirm(\'Ya tiene un celular activado. Un enlace nuevo sirve para cambiar de celular: el anterior dejará de funcionar cuando active el nuevo. ¿Continuar?\')"' : ''; ?>>
								Enlace de activación</a> |
							<a href="<?php echo esc_url( add_query_arg( array( 'vista' => 'cesar', 'id' => $t['id'] ), $base ) ); ?>" class="pk-txt-error">Cesar</a>
						<?php else : ?>
							Cesado el <?php echo esc_html( (string) $t['fecha_cese'] ); ?>
						<?php endif; ?>
						| <a href="<?php echo esc_url( PantallaAusencias::url( array( 'trabajador' => $t['id'] ) ) ); ?>">Ausencias</a>
						<?php if ( current_user_can( Capacidades::AUDITORIA ) ) : ?>
							| <a href="<?php echo esc_url( PantallaHistorial::url( array( 'trabajador' => $t['id'] ) ) ); ?>">Historial</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string, mixed>|null $t */
	private static function formulario( ?array $t ): void {
		$previo   = get_transient( 'pka_form_' . get_current_user_id() );
		$errores  = is_array( $previo ) ? $previo['errores'] : array();
		$valores  = is_array( $previo ) ? $previo['valores'] : ( $t ?? array() );
		$horarios = Trabajadores::horarios();
		$jefes    = get_users( array( 'role__in' => array( Capacidades::ROL_JEFE, Capacidades::ROL_RRHH, Capacidades::ROL_GERENCIA, 'administrator' ), 'orderby' => 'display_name' ) );

		delete_transient( 'pka_form_' . get_current_user_id() );
		?>
		<h1><?php echo $t ? 'Editar trabajador' : 'Agregar trabajador'; ?></h1>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_guardar_trabajador">
			<input type="hidden" name="id" value="<?php echo esc_attr( $t ? $t['id'] : '' ); ?>">
			<?php wp_nonce_field( 'pka_guardar_trabajador' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( self::CAMPOS as $campo => $etiqueta ) : ?>
					<tr>
						<th scope="row"><label for="pka-<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $etiqueta ); ?></label></th>
						<td>
							<input type="<?php echo 'fecha_ingreso' === $campo ? 'date' : ( 'correo' === $campo ? 'email' : 'text' ); ?>"
								id="pka-<?php echo esc_attr( $campo ); ?>" name="<?php echo esc_attr( $campo ); ?>" class="regular-text"
								value="<?php echo esc_attr( (string) ( $valores[ $campo ] ?? '' ) ); ?>"
								<?php echo in_array( $campo, array( 'dni', 'nombres', 'apellidos' ), true ) ? 'required' : ''; ?>>
							<?php self::error( $errores, $campo ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<?php $cumple = (string) ( $valores['cumpleanos'] ?? '' ); ?>
					<th scope="row"><label for="pka-cumple-dia">Cumpleaños</label></th>
					<td>
						<select id="pka-cumple-dia" name="cumple_dia" aria-label="Día">
							<option value="">Día</option>
							<?php for ( $dd = 1; $dd <= 31; $dd++ ) : ?>
								<option value="<?php echo (int) $dd; ?>" <?php selected( (int) ( $valores['cumple_dia'] ?? ( '' !== $cumple ? substr( $cumple, 3, 2 ) : 0 ) ), $dd ); ?>><?php echo (int) $dd; ?></option>
							<?php endfor; ?>
						</select>
						<select name="cumple_mes" aria-label="Mes">
							<option value="">Mes</option>
							<?php for ( $mm = 1; $mm <= 12; $mm++ ) : ?>
								<option value="<?php echo (int) $mm; ?>" <?php selected( (int) ( $valores['cumple_mes'] ?? ( '' !== $cumple ? substr( $cumple, 0, 2 ) : 0 ) ), $mm ); ?>><?php echo esc_html( wp_date( 'F', mktime( 12, 0, 0, $mm, 1, 2024 ) ) ); ?></option>
							<?php endfor; ?>
						</select>
						<?php self::error( $errores, 'cumpleanos' ); ?>
						<p class="description">Sin el año. Con el DNI sirve para que la persona confirme que es ella si cambia de celular desde el taller.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-horario">Horario</label></th>
					<td>
						<select id="pka-horario" name="horario_id">
							<?php foreach ( $horarios as $hid => $nombre ) : ?>
								<option value="<?php echo esc_attr( (string) $hid ); ?>" <?php selected( (int) ( $valores['horario_id'] ?? 0 ), (int) $hid ); ?>><?php echo esc_html( $nombre ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php self::error( $errores, 'horario_id' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-jefe">Jefe que aprueba</label></th>
					<td>
						<select id="pka-jefe" name="jefe_user_id">
							<option value="0">— Sin asignar —</option>
							<?php foreach ( $jefes as $jefe ) : ?>
								<option value="<?php echo esc_attr( (string) $jefe->ID ); ?>" <?php selected( (int) ( $valores['jefe_user_id'] ?? 0 ), $jefe->ID ); ?>><?php echo esc_html( $jefe->display_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php self::error( $errores, 'jefe_user_id' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-usuario">Usuario de wp-admin</label></th>
					<td>
						<select id="pka-usuario" name="user_id">
							<option value="0">— No entra al panel —</option>
							<?php foreach ( $jefes as $u ) : ?>
								<option value="<?php echo esc_attr( (string) $u->ID ); ?>" <?php selected( (int) ( $valores['user_id'] ?? 0 ), $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Solo si este trabajador también es jefe, RR.HH. o gerencia. Así no puede aprobar ni corregir sus propias marcas.</p>
						<?php self::error( $errores, 'user_id' ); ?>
					</td>
				</tr>
				<?php if ( $t ) : ?>
					<tr>
						<th scope="row"><label for="pka-motivo">Motivo del cambio</label></th>
						<td><input type="text" id="pka-motivo" name="motivo" class="regular-text"><p class="description">Queda en el historial de cambios.</p></td>
					</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( $t ? 'Guardar cambios' : 'Agregar trabajador' ); ?>
		</form>
		<?php
		if ( $t && current_user_can( Capacidades::VER_SUELDOS ) ) {
			self::remuneracion( $t );
		}
	}

	/** Honorario y tarifas por hora, con su historial. Solo para quien tiene pka_ver_sueldos. */
	private static function remuneracion( array $t ): void {
		$historial = Sueldos::historial( (int) $t['id'] );
		$previo    = get_transient( 'pka_form_sueldo_' . get_current_user_id() );
		$errores   = is_array( $previo ) ? $previo['errores'] : array();
		$actual    = Sueldos::vigente( (int) $t['id'] );
		$propio    = Trabajadores::esPropio( (int) $t['id'] );
		$monto     = static fn( int $c ): string => number_format( $c / 100, 2, '.', '' );
		$valores   = is_array( $previo ) ? $previo['valores'] : (
			$actual
				? array(
					'honorario'      => $monto( $actual->honorarioCentimos ),
					'tarifa_extra'   => $monto( $actual->tarifaExtra ),
					'tarifa_sabado'  => $monto( $actual->tarifaSabado ),
					'tarifa_domingo' => $monto( $actual->tarifaDomingo ),
					'tarifa_feriado' => $monto( $actual->tarifaFeriado ),
				)
				: array()
		);

		delete_transient( 'pka_form_sueldo_' . get_current_user_id() );
		?>
		<h class="pk-mt"2 id="remuneracion">Honorario y tarifas</h2>
		<p class="description">Se paga por recibos por honorarios: el honorario mensual incluye las horas normales y cada hora adicional va con su tarifa. Solo lo ve gerencia; el trabajador nunca ve estos montos.</p>
		<table class="widefat striped pk-max">
			<thead><tr><th>Honorario y tarifas por hora</th><th>Desde</th><th>Hasta</th><th>Nota</th><th>Registró</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $historial ) : ?>
				<tr><td colspan="6">Todavía no tiene honorario registrado: en la calculadora de pagos saldrá como "Falta su honorario".</td></tr>
			<?php endif; ?>
			<?php foreach ( $historial as $s ) : ?>
				<?php $quien = get_userdata( (int) $s['creado_por'] ); ?>
				<tr<?php echo 'anulado' === $s['estado'] ? ' class="pk-atenuado"' : ''; ?>>
					<td><strong><?php echo esc_html( Sueldos::etiqueta( $s ) ); ?></strong></td>
					<td><?php echo esc_html( $s['vigente_desde'] ); ?></td>
					<td><?php echo esc_html( $s['vigente_hasta'] ? $s['vigente_hasta'] : ( 'anulado' === $s['estado'] ? '—' : 'Vigente' ) ); ?></td>
					<td><?php echo esc_html( $s['nota'] ); ?><?php echo 'anulado' === $s['estado'] ? '<br><em>Anulado: ' . esc_html( $s['motivo_anulacion'] ) . '</em>' : ''; ?></td>
					<td><?php echo esc_html( $quien ? $quien->display_name : '—' ); ?></td>
					<td>
						<?php if ( 'vigente' === $s['estado'] && null === $s['vigente_hasta'] && ! $propio ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-fila junta">
								<input type="hidden" name="action" value="pka_sueldo_anular">
								<input type="hidden" name="id" value="<?php echo esc_attr( $s['id'] ); ?>">
								<input type="hidden" name="trabajador_id" value="<?php echo esc_attr( $t['id'] ); ?>">
								<?php wp_nonce_field( 'pka_sueldo_anular' ); ?>
								<label class="screen-reader-text" for="pka-sueldo-anular-<?php echo esc_attr( $s['id'] ); ?>">Motivo de la anulación</label>
								<input type="text" id="pka-sueldo-anular-<?php echo esc_attr( $s['id'] ); ?>" name="motivo" placeholder="Por qué se anula (error de tipeo…)" required>
								<button class="button">Anular</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $propio ) : ?>
			<p><em>Es tu propio perfil: otra persona de gerencia o un administrador tiene que registrar tu honorario.</em></p>
			<?php
			return;
		endif;
		?>
		<h3><?php echo $historial ? 'Registrar un cambio de honorario o tarifas' : 'Registrar el honorario y las tarifas'; ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_sueldo_agregar">
			<input type="hidden" name="trabajador_id" value="<?php echo esc_attr( $t['id'] ); ?>">
			<?php wp_nonce_field( 'pka_sueldo_agregar' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pka-sueldo-honorario">Honorario mensual (S/)</label></th>
					<td>
						<input type="text" inputmode="decimal" id="pka-sueldo-honorario" name="honorario" required value="<?php echo esc_attr( (string) ( $valores['honorario'] ?? '' ) ); ?>" placeholder="1800.00">
						<p class="description">Incluye las horas normales de su horario.</p>
						<?php self::error( $errores, 'honorario' ); ?>
					</td>
				</tr>
				<?php foreach ( \Pekokis\Asistencia\Nucleo\Sueldo::TARIFAS as $tipo => $nombre ) : ?>
					<tr>
						<th scope="row"><label for="pka-sueldo-<?php echo esc_attr( $tipo ); ?>"><?php echo esc_html( $nombre ); ?> (S/)</label></th>
						<td>
							<input type="text" inputmode="decimal" id="pka-sueldo-<?php echo esc_attr( $tipo ); ?>" name="tarifa_<?php echo esc_attr( $tipo ); ?>" required value="<?php echo esc_attr( (string) ( $valores[ 'tarifa_' . $tipo ] ?? '' ) ); ?>" placeholder="0.00">
							<?php self::error( $errores, 'tarifa_' . $tipo ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="pka-sueldo-desde">Rige desde</label></th>
					<td><input type="date" id="pka-sueldo-desde" name="vigente_desde" required value="<?php echo esc_attr( (string) ( $valores['vigente_desde'] ?? ( $historial ? '' : ( $t['fecha_ingreso'] ?? '' ) ) ) ); ?>"><?php self::error( $errores, 'vigente_desde' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-sueldo-nota">Nota</label></th>
					<td><input type="text" id="pka-sueldo-nota" name="nota" class="regular-text" maxlength="255" value="<?php echo esc_attr( (string) ( $valores['nota'] ?? '' ) ); ?>" placeholder="Aumento, campaña escolar…"></td>
				</tr>
			</table>
			<?php submit_button( 'Guardar honorario y tarifas', 'secondary' ); ?>
		</form>
		<?php
	}

	public static function agregarSueldo(): void {
		self::exigirSueldos( 'pka_sueldo_agregar' );

		$id      = isset( $_POST['trabajador_id'] ) ? absint( $_POST['trabajador_id'] ) : 0;
		$entrada = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		$result  = Sueldos::agregar( $id, $entrada );

		if ( $result instanceof WP_Error ) {
			set_transient( 'pka_form_sueldo_' . get_current_user_id(), array( 'errores' => $result->get_error_data()['errores'] ?? array(), 'valores' => $entrada ), 5 * MINUTE_IN_SECONDS );
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
		} else {
			Avisos::agregar( 'success', 'Honorario y tarifas guardados.' );
		}

		Menu::volverA( add_query_arg( array( 'page' => self::SLUG, 'vista' => 'editar', 'id' => $id ), admin_url( 'admin.php' ) ) . '#remuneracion' );
	}

	public static function anularSueldo(): void {
		self::exigirSueldos( 'pka_sueldo_anular' );

		$id     = isset( $_POST['trabajador_id'] ) ? absint( $_POST['trabajador_id'] ) : 0;
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$result = Sueldos::anular( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0, $motivo );

		Avisos::agregar( $result instanceof WP_Error ? 'error' : 'success', esc_html( $result instanceof WP_Error ? $result->get_error_message() : 'Registro anulado. Rige otra vez el anterior, si lo había.' ) );
		Menu::volverA( add_query_arg( array( 'page' => self::SLUG, 'vista' => 'editar', 'id' => $id ), admin_url( 'admin.php' ) ) . '#remuneracion' );
	}

	private static function exigirSueldos( string $accion ): void {
		if ( ! current_user_can( Capacidades::GESTIONAR_PERSONAL ) || ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}

	/** @param array<string, mixed>|null $t */
	private static function formularioCese( ?array $t ): void {
		if ( ! $t || Trabajadores::ACTIVO !== $t['estado'] ) {
			echo '<p>Ese trabajador no existe o ya está cesado.</p>';
			return;
		}
		?>
		<h1>Cesar a <?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?></h1>
		<p>Ya no podrá marcar, y se desactivan su celular y su llave. Sus marcas se conservan.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_cesar_trabajador">
			<input type="hidden" name="id" value="<?php echo esc_attr( $t['id'] ); ?>">
			<?php wp_nonce_field( 'pka_cesar_trabajador' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="pka-fecha">Fecha de cese</label></th><td><input type="date" id="pka-fecha" name="fecha" required value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></td></tr>
				<tr><th scope="row"><label for="pka-motivo">Motivo</label></th><td><input type="text" id="pka-motivo" name="motivo" class="regular-text" required></td></tr>
			</table>
			<?php submit_button( 'Cesar', 'delete' ); ?>
		</form>
		<?php
	}

	public static function guardar(): void {
		self::exigir( 'pka_guardar_trabajador' );

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$entrada = wp_unslash( $_POST ); // Se normaliza y valida en Trabajadores::guardar().
		$motivo  = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$result  = Trabajadores::guardar( $entrada, $id ? $id : null, $motivo );

		if ( $result instanceof WP_Error ) {
			set_transient(
				'pka_form_' . get_current_user_id(),
				array( 'errores' => $result->get_error_data()['errores'] ?? array(), 'valores' => array_map( 'sanitize_text_field', $entrada ) ),
				5 * MINUTE_IN_SECONDS
			);
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
			Menu::volver( self::SLUG, array( 'vista' => 'editar' ) + ( $id ? array( 'id' => $id ) : array() ) );
		}

		Avisos::agregar( 'success', $id ? 'Cambios guardados.' : 'Trabajador agregado. Ahora genera su <strong>enlace de activación</strong> (pestaña Celular), o que se registre en el taller con "Cambié de celular" si tiene su cumpleaños.' );
		Menu::volverA( PantallaFicha::url( (int) $result, $id ? '' : 'celular' ) );
	}

	public static function enlace(): void {
		self::exigir( 'pka_enlace_activacion' );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$t  = Trabajadores::buscar( $id );

		if ( ! $t || Trabajadores::ACTIVO !== $t['estado'] ) {
			Avisos::agregar( 'error', 'Ese trabajador no existe o está cesado.' );
			Menu::volver( self::SLUG );
		}

		$url = Activaciones::generar( $id );

		Avisos::agregar(
			'success',
			'Enlace de activación de <strong>' . esc_html( Trabajadores::nombreCompleto( $t ) ) . '</strong> (vence en ' . Activaciones::HORAS_VIGENCIA . ' h y sirve una sola vez):<br>'
			. '<input type="text" readonly class="large-text code" value="' . esc_attr( $url ) . '"><br>'
			. 'Entrégaselo en persona o por WhatsApp; debe abrirlo en su propio celular. Este enlace no se vuelve a mostrar.'
		);
		Menu::volver( self::SLUG );
	}

	public static function cesar(): void {
		self::exigir( 'pka_cesar_trabajador' );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$fecha  = isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : '';
		$motivo = isset( $_POST['motivo'] ) ? sanitize_text_field( wp_unslash( $_POST['motivo'] ) ) : '';
		$result = Trabajadores::cesar( $id, $fecha, $motivo );

		if ( $result instanceof WP_Error ) {
			Avisos::agregar( 'error', esc_html( $result->get_error_message() ) );
			Menu::volver( self::SLUG, array( 'vista' => 'cesar', 'id' => $id ) );
		}

		Avisos::agregar( 'success', 'Trabajador cesado. Su celular y su llave quedaron desactivados.' );
		Menu::volver( self::SLUG );
	}

	/** @param array<string, string> $errores */
	private static function error( array $errores, string $campo ): void {
		if ( isset( $errores[ $campo ] ) ) {
			echo '<p class="description pk-txt-error">' . esc_html( $errores[ $campo ] ) . '</p>';
		}
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::GESTIONAR_PERSONAL ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
