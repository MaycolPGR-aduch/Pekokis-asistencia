<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use InvalidArgumentException;
use Pekokis\Asistencia\Nucleo\CodigoPantalla;
use Pekokis\Asistencia\Nucleo\ModoPresencia;
use Pekokis\Asistencia\Nucleo\RedAutorizada;
use Pekokis\Asistencia\Nucleo\ReglasAvisos;
use Pekokis\Asistencia\Nucleo\ReglasPago;
use Pekokis\Asistencia\Nucleo\RecordatorioCampana;
use Pekokis\Asistencia\WordPress\Notificaciones;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Aviso;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Diagnostico;
use Pekokis\Asistencia\WordPress\Pantallas;
use Pekokis\Asistencia\WordPress\Peticion;
use Pekokis\Asistencia\WordPress\Presencia;
use Pekokis\Asistencia\WordPress\Tablas;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Ajustes: modo de presencia y redes del WiFi del taller.
 */
final class PantallaAjustes {
	public const SLUG = 'pka-ajustes';

	public static function registrar(): void {
		add_action( 'admin_post_pka_guardar_modo', array( self::class, 'guardarModo' ) );
		add_action( 'admin_post_pka_agregar_red', array( self::class, 'agregarRed' ) );
		add_action( 'admin_post_pka_cambiar_red', array( self::class, 'cambiarRed' ) );
		add_action( 'admin_post_pka_cambiar_pantalla', array( self::class, 'cambiarPantalla' ) );
		add_action( 'admin_post_pka_guardar_empleador', array( self::class, 'guardarEmpleador' ) );
		add_action( 'admin_post_pka_guardar_aviso', array( self::class, 'guardarAviso' ) );
		add_action( 'admin_post_pka_guardar_reglas', array( self::class, 'guardarReglas' ) );
		add_action( 'admin_post_pka_guardar_avisos', array( self::class, 'guardarAvisos' ) );
		add_action( 'admin_post_pka_probar_avisos', array( self::class, 'probarAvisos' ) );
		add_action( 'admin_post_pka_guardar_campana', array( self::class, 'guardarCampana' ) );
	}

	/** Campos numéricos de las reglas de horas: clave => [etiqueta, mínimo, máximo, ayuda]. */
	private const REGLAS = array(
		'comida_no_marcada_min' => array( 'Comida descontada si no se marca (minutos)', 0, 180, 'Solo en días laborables, cuando la persona no marcó su salida a comer. Por confirmar (las maquetas proponen 60). Con 0 no se descuenta.' ),
		'jornada_larga_horas'   => array( 'Revisar jornadas de más de (horas)', 0, 48, 'La jornada no se corta a medianoche: sigue abierta hasta que marca su salida y cuenta en el día en que empezó. Si al cerrarla pasa de estas horas, se acepta igual, queda por revisar y se avisa a gerencia. Por confirmar (las maquetas proponen 16). Con 0 no se revisa.' ),
	);

	private static function reglas(): void {
		$a = Ajustes::todos();
		?>
		<h2 style="margin-top:32px" id="reglas">Reglas de horas y pagos</h2>
		<p>Se paga por recibos por honorarios: <strong>honorario + horas extra, de sábado, de domingo y de feriado, cada una por la tarifa de la persona</strong> (se registran en la ficha de cada trabajador). De lunes a viernes es hora extra todo lo que pase de la jornada de su horario (9 h 30 min con el horario base), sin autorizar nada. El sábado, el domingo y los feriados, todas las horas van con su tarifa. Las faltas y tardanzas se señalan, pero no se descuentan.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_guardar_reglas">
			<?php wp_nonce_field( 'pka_guardar_reglas' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( self::REGLAS as $clave => [ $etiqueta, $min, $max, $ayuda ] ) : ?>
					<tr>
						<th scope="row"><label for="pka-r-<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></label></th>
						<td>
							<input type="number" id="pka-r-<?php echo esc_attr( $clave ); ?>" name="<?php echo esc_attr( $clave ); ?>" class="small-text" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" required value="<?php echo esc_attr( (string) $a[ $clave ] ); ?>">
							<p class="description"><?php echo esc_html( $ayuda ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row">La tardanza se cuenta</th>
					<td>
						<label><input type="radio" name="tardanza_desde" value="<?php echo esc_attr( ReglasPago::TARDANZA_DESDE_ENTRADA ); ?>" <?php checked( (string) $a['tardanza_desde'], ReglasPago::TARDANZA_DESDE_ENTRADA ); ?>> Desde la hora de entrada (llegó 9:14 → 14 min)</label><br>
						<label><input type="radio" name="tardanza_desde" value="<?php echo esc_attr( ReglasPago::TARDANZA_DESDE_TOLERANCIA ); ?>" <?php checked( (string) $a['tardanza_desde'], ReglasPago::TARDANZA_DESDE_TOLERANCIA ); ?>> Desde el fin de la tolerancia (llegó 9:14 con 5 min → 9 min)</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar reglas', 'secondary' ); ?>
		</form>
		<?php
	}

	public static function guardarReglas(): void {
		self::exigir( 'pka_guardar_reglas' );

		$antes   = Ajustes::todos();
		$ajustes = $antes;

		foreach ( self::REGLAS as $clave => [ , $min, $max ] ) {
			if ( isset( $_POST[ $clave ] ) ) {
				$ajustes[ $clave ] = max( $min, min( $max, (int) $_POST[ $clave ] ) );
			}
		}

		$ajustes['tardanza_desde'] = isset( $_POST['tardanza_desde'] ) && ReglasPago::TARDANZA_DESDE_TOLERANCIA === $_POST['tardanza_desde'] ? ReglasPago::TARDANZA_DESDE_TOLERANCIA : ReglasPago::TARDANZA_DESDE_ENTRADA;
		update_option( Ajustes::OPCION, $ajustes );

		$claves    = array_merge( array_keys( self::REGLAS ), array( 'tardanza_desde' ) );
		[ $a, $d ] = Auditoria::diferencias( array_intersect_key( $antes, array_flip( $claves ) ), array_intersect_key( $ajustes, array_flip( $claves ) ) );

		if ( $d ) {
			Auditoria::registrar( 'ajustes.reglas', 'ajustes', null, $a, $d );
		}

		Avisos::agregar( 'success', 'Reglas de horas guardadas.' );
		Menu::volverA( admin_url( 'admin.php?page=' . self::SLUG ) . '#reglas' );
	}

	private const ESTADOS_AVISO = array(
		'pendiente' => 'Pendiente',
		'enviado'   => 'Enviado',
		'fallido'   => 'Falló',
	);

	private static function avisos(): void {
		$a       = Ajustes::todos();
		$activos = Notificaciones::activos();
		$grupos  = array(
			ReglasAvisos::PARA_JEFE       => 'Al jefe del trabajador (o a RR.HH. si no tiene jefe)',
			ReglasAvisos::PARA_TRABAJADOR => 'Al trabajador (a su correo, si lo tiene, y al webhook con su teléfono)',
			ReglasAvisos::PARA_ADMIN      => 'A RR.HH. (el correo de Exportar)',
		);
		$ultimos = Notificaciones::ultimos( 25 );
		?>
		<h2 style="margin-top:32px" id="avisos">Avisos por correo y WhatsApp</h2>
		<p>El sistema revisa cada 5 minutos y agrupa: si a las 9:15 faltan tres personas del mismo jefe, le llega un solo correo con las tres. Cada aviso sale una sola vez. Todos empiezan apagados.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_guardar_avisos">
			<?php wp_nonce_field( 'pka_guardar_avisos' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $grupos as $para => $titulo ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $titulo ); ?></th>
						<td>
							<?php foreach ( ReglasAvisos::TIPOS as $tipo => $info ) : ?>
								<?php if ( $info[1] === $para ) : ?>
									<label style="display:block;margin-bottom:4px"><input type="checkbox" name="avisos_activos[]" value="<?php echo esc_attr( $tipo ); ?>" <?php checked( in_array( $tipo, $activos, true ) ); ?>> <?php echo esc_html( $info[0] ); ?></label>
								<?php endif; ?>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="pka-av-ausencia">Avisar ausencia después de</label></th>
					<td><input type="number" id="pka-av-ausencia" name="aviso_margen_ausencia" class="small-text" min="0" max="240" value="<?php echo esc_attr( (string) $a['aviso_margen_ausencia'] ); ?>"> minutos, pasada la tolerancia <span class="description">(con entrada 9:00 y 5 de tolerancia, 10 → 9:15)</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-av-refrigerio">Refrigerio largo: margen</label></th>
					<td><input type="number" id="pka-av-refrigerio" name="aviso_margen_refrigerio" class="small-text" min="0" max="240" value="<?php echo esc_attr( (string) $a['aviso_margen_refrigerio'] ); ?>"> minutos más que el refrigerio del horario</td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-av-salida">Salida temporal larga</label></th>
					<td><input type="number" id="pka-av-salida" name="aviso_limite_salida" class="small-text" min="15" max="600" value="<?php echo esc_attr( (string) $a['aviso_limite_salida'] ); ?>"> minutos fuera</td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-av-hora">"No marcaste tu salida" a las</label></th>
					<td><input type="time" id="pka-av-hora" name="aviso_hora_sin_salida" value="<?php echo esc_attr( (string) $a['aviso_hora_sin_salida'] ); ?>"> <span class="description">o 30 min después del fin de su horario, si termina más tarde</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-av-webhook">Webhook para WhatsApp (opcional)</label></th>
					<td>
						<input type="url" id="pka-av-webhook" name="webhook_avisos" class="large-text code" placeholder="https://hook.us1.make.com/…" value="<?php echo esc_attr( (string) $a['webhook_avisos'] ); ?>">
						<p class="description">Recibe cada aviso en JSON (<code>evento</code>, <code>para.nombre</code>, <code>para.telefono</code>, <code>mensaje</code>, <code>enlace</code>), firmado con HMAC en la cabecera <code>X-PKA-Firma</code>. Desde ahí, Make o el bot lo mandan por WhatsApp. Déjalo vacío para usar solo el correo.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar avisos', 'secondary' ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:-8px">
			<input type="hidden" name="action" value="pka_probar_avisos">
			<?php wp_nonce_field( 'pka_probar_avisos' ); ?>
			<button class="button">Enviarme un correo de prueba</button>
			<span class="description">Llega a <?php echo esc_html( wp_get_current_user()->user_email ); ?>. Sirve para comprobar el SMTP.</span>
		</form>

		<h3>Últimos avisos</h3>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th>Cuándo</th><th>Aviso</th><th>Para</th><th>Canal</th><th>Estado</th></tr></thead>
			<tbody>
			<?php if ( ! $ultimos ) : ?>
				<tr><td colspan="5">Todavía no se envió ningún aviso.</td></tr>
			<?php endif; ?>
			<?php foreach ( $ultimos as $u ) : ?>
				<tr>
					<td><?php echo esc_html( Ajustes::local( $u['creado_en'], 'd/m H:i' ) ); ?></td>
					<td><strong><?php echo esc_html( ReglasAvisos::titulo( $u['tipo'] ) ); ?></strong><br><span class="description"><?php echo esc_html( $u['linea'] ); ?></span></td>
					<td><?php echo esc_html( $u['nombre'] ); ?><br><span class="description"><?php echo esc_html( 'webhook' === $u['canal'] && '' !== $u['telefono'] ? $u['telefono'] : $u['destino'] ); ?></span></td>
					<td><?php echo 'correo' === $u['canal'] ? 'Correo' : 'WhatsApp (webhook)'; ?></td>
					<td><?php echo esc_html( self::ESTADOS_AVISO[ $u['estado'] ] ?? $u['estado'] ); ?><?php echo '' !== $u['error'] ? '<br><span class="description" style="color:#b32d2e">' . esc_html( $u['error'] ) . '</span>' : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function guardarAvisos(): void {
		self::exigir( 'pka_guardar_avisos' );

		$antes   = Ajustes::todos();
		$ajustes = $antes;
		$pedidos = isset( $_POST['avisos_activos'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['avisos_activos'] ) ) : array();
		$hora    = isset( $_POST['aviso_hora_sin_salida'] ) ? sanitize_text_field( wp_unslash( $_POST['aviso_hora_sin_salida'] ) ) : '20:00';
		$webhook = isset( $_POST['webhook_avisos'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['webhook_avisos'] ) ), array( 'https' ) ) : '';

		if ( '' !== $webhook && ! wp_http_validate_url( $webhook ) ) {
			Avisos::agregar( 'error', 'La dirección del webhook no es válida (debe empezar con https://).' );
			Menu::volverA( admin_url( 'admin.php?page=' . self::SLUG ) . '#avisos' );
		}

		$ajustes['avisos_activos']          = array_values( array_intersect( array_keys( ReglasAvisos::TIPOS ), $pedidos ) );
		$ajustes['aviso_hora_sin_salida']   = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $hora ) ? $hora : '20:00';
		$ajustes['aviso_margen_ausencia']   = isset( $_POST['aviso_margen_ausencia'] ) ? max( 0, min( 240, (int) $_POST['aviso_margen_ausencia'] ) ) : 10;
		$ajustes['aviso_margen_refrigerio'] = isset( $_POST['aviso_margen_refrigerio'] ) ? max( 0, min( 240, (int) $_POST['aviso_margen_refrigerio'] ) ) : 10;
		$ajustes['aviso_limite_salida']     = isset( $_POST['aviso_limite_salida'] ) ? max( 15, min( 600, (int) $_POST['aviso_limite_salida'] ) ) : 120;
		$ajustes['webhook_avisos']          = $webhook;
		update_option( Ajustes::OPCION, $ajustes );

		// En el historial: los avisos por nombre y, del webhook (un secreto), solo el dominio.
		$resumen = static fn( array $x ): array => array(
			'avisos'  => implode( ', ', array_map( array( ReglasAvisos::class, 'etiqueta' ), (array) $x['avisos_activos'] ) ),
			'horario' => $x['aviso_hora_sin_salida'] . ' · ' . $x['aviso_margen_ausencia'] . '/' . $x['aviso_margen_refrigerio'] . '/' . $x['aviso_limite_salida'] . ' min',
			'webhook' => '' === $x['webhook_avisos'] ? '—' : (string) wp_parse_url( $x['webhook_avisos'], PHP_URL_HOST ),
		);
		[ $a, $d ] = Auditoria::diferencias( $resumen( $antes ), $resumen( $ajustes ) );

		if ( $d ) {
			Auditoria::registrar( 'ajustes.avisos', 'ajustes', null, $a, $d );
		}

		Avisos::agregar( 'success', $ajustes['avisos_activos'] ? 'Avisos guardados. El sistema los revisa cada 5 minutos.' : 'Avisos guardados: están todos apagados.' );
		Menu::volverA( admin_url( 'admin.php?page=' . self::SLUG ) . '#avisos' );
	}

	public static function probarAvisos(): void {
		self::exigir( 'pka_probar_avisos' );

		$correo = wp_get_current_user()->user_email;
		$ok     = is_email( $correo ) && Notificaciones::prueba( $correo );

		Avisos::agregar( $ok ? 'success' : 'error', $ok ? 'Correo de prueba enviado a ' . esc_html( $correo ) . '. Revisa tu bandeja (y la de spam).' : 'No se pudo enviar el correo de prueba: revisa la configuración del SMTP.' );
		Menu::volverA( admin_url( 'admin.php?page=' . self::SLUG ) . '#avisos' );
	}

	/** Recordatorio de campaña (maquetas G15), con la vista previa de lo que ve el trabajador. */
	private static function campana(): void {
		$c   = RecordatorioCampana::desdeAjustes( Ajustes::todos() );
		$hoy = wp_date( 'Y-m-d', null, Ajustes::zona() );
		?>
		<h2 style="margin-top:32px" id="campana">Avisos de campaña</h2>
		<p>Un recordatorio dentro de la app, al abrirla, para quien a esa hora todavía no marcó su entrada. Solo en días laborables y dentro de las fechas de la campaña. Desaparece al marcar. No manda correos ni WhatsApp.</p>
		<p><strong><?php echo $c->enCampana( $hoy ) ? '✔ Hoy se está mostrando.' : ( $c->activo ? 'Activo, pero hoy está fuera de las fechas de la campaña.' : 'Apagado.' ); ?></strong></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_guardar_campana">
			<?php wp_nonce_field( 'pka_guardar_campana' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Recordatorio</th>
					<td><label><input type="checkbox" name="campana_activa" value="1" <?php checked( $c->activo ); ?>> Mostrar el recordatorio en la app</label></td>
				</tr>
				<tr>
					<th scope="row">Campaña</th>
					<td>
						<label>Del <input type="date" name="campana_desde" value="<?php echo esc_attr( $c->desde ); ?>" required></label>
						<label>al <input type="date" name="campana_hasta" value="<?php echo esc_attr( $c->hasta ); ?>" required></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-campana-hora">Avisar si no hay entrada a las</label></th>
					<td><input type="time" id="pka-campana-hora" name="campana_hora" value="<?php echo esc_attr( $c->hora ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="pka-campana-texto">Texto del aviso</label></th>
					<td>
						<textarea id="pka-campana-texto" name="campana_texto" rows="2" class="large-text" maxlength="200"><?php echo esc_textarea( $c->texto ); ?></textarea>
						<p class="description">Así lo verá el trabajador, arriba de "Marcar entrada":</p>
						<div style="max-width:360px;background:#FFF1D6;color:#9A5B00;border-radius:16px;padding:12px 14px;font-weight:600;margin-top:6px"><?php echo esc_html( $c->texto ); ?></div>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar avisos de campaña', 'secondary' ); ?>
		</form>
		<?php
	}

	public static function guardarCampana(): void {
		self::exigir( 'pka_guardar_campana' );

		$antes   = Ajustes::todos();
		$ajustes = $antes;
		$entrada = array(
			'campana_activa' => ! empty( $_POST['campana_activa'] ),
			'campana_desde'  => isset( $_POST['campana_desde'] ) ? sanitize_text_field( wp_unslash( $_POST['campana_desde'] ) ) : '',
			'campana_hasta'  => isset( $_POST['campana_hasta'] ) ? sanitize_text_field( wp_unslash( $_POST['campana_hasta'] ) ) : '',
			'campana_hora'   => isset( $_POST['campana_hora'] ) ? sanitize_text_field( wp_unslash( $_POST['campana_hora'] ) ) : '',
			'campana_texto'  => isset( $_POST['campana_texto'] ) ? sanitize_textarea_field( wp_unslash( $_POST['campana_texto'] ) ) : '',
		);
		$c       = RecordatorioCampana::desdeAjustes( $entrada );

		if ( $c->errores() ) {
			Avisos::agregar( 'error', esc_html( implode( ' ', $c->errores() ) ) );
			Menu::volverA( admin_url( 'admin.php?page=' . self::SLUG ) . '#campana' );
		}

		$ajustes['campana_activa'] = $c->activo;
		$ajustes['campana_desde']  = $c->desde;
		$ajustes['campana_hasta']  = $c->hasta;
		$ajustes['campana_hora']   = $c->hora;
		$ajustes['campana_texto']  = RecordatorioCampana::TEXTO === $c->texto ? '' : $c->texto;
		update_option( Ajustes::OPCION, $ajustes );

		$claves    = array_flip( array_keys( $entrada ) );
		[ $a, $d ] = Auditoria::diferencias( array_intersect_key( $antes, $claves ), array_intersect_key( $ajustes, $claves ) );

		if ( $d ) {
			Auditoria::registrar( 'ajustes.campana', 'ajustes', null, $a, $d );
		}

		Avisos::agregar( 'success', 'Avisos de campaña guardados.' );
		Menu::volverA( admin_url( 'admin.php?page=' . self::SLUG ) . '#campana' );
	}

	private static function empleadorYAviso(): void {
		$a      = Ajustes::todos();
		$conteo = Aviso::conteo();
		?>
		<h2 style="margin-top:32px">Datos del empleador</h2>
		<p>Aparecen en el registro de asistencia (Excel) y en el aviso de datos personales.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_guardar_empleador">
			<?php wp_nonce_field( 'pka_guardar_empleador' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="pka-e-razon">Razón social</label></th><td><input type="text" id="pka-e-razon" name="razon_social" class="regular-text" required value="<?php echo esc_attr( (string) $a['razon_social'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="pka-e-ruc">RUC</label></th><td><input type="text" id="pka-e-ruc" name="ruc" class="regular-text" required pattern="\d{11}" value="<?php echo esc_attr( (string) $a['ruc'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="pka-e-dir">Domicilio</label></th><td><input type="text" id="pka-e-dir" name="direccion_empleador" class="large-text" value="<?php echo esc_attr( (string) $a['direccion_empleador'] ); ?>"></td></tr>
			</table>
			<?php submit_button( 'Guardar datos del empleador', 'secondary' ); ?>
		</form>

		<h2 style="margin-top:32px" id="aviso">Aviso de datos personales</h2>
		<?php if ( Aviso::esBorrador() ) : ?>
			<div class="notice notice-warning inline"><p><strong>Este texto es un borrador.</strong> Revísalo con el abogado antes de activar a los trabajadores. Cuando lo guardes, queda como texto oficial.</p></div>
		<?php endif; ?>
		<p>
			Versión <?php echo (int) Aviso::version(); ?> · la aceptaron <strong><?php echo (int) $conteo['aceptaron']; ?> de <?php echo (int) $conteo['activos']; ?></strong> trabajadores activos.
			Se acepta al activar el celular; si cambias el texto, cada uno lo aceptará de nuevo la próxima vez que marque.
			<a href="<?php echo esc_url( home_url( '/privacidad/' ) ); ?>" target="_blank" rel="noopener">Ver como lo ve el trabajador ↗</a>
		</p>
		<p class="description">
			Puedes usar <code>{razon_social}</code>, <code>{ruc}</code>, <code>{direccion}</code> y <code>{correo_contacto}</code> (el correo de RR.HH. de Exportar).
			Separa los párrafos con una línea en blanco; las líneas que empiezan con "- " son viñetas.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="pka_guardar_aviso">
			<?php wp_nonce_field( 'pka_guardar_aviso' ); ?>
			<label class="screen-reader-text" for="pka-aviso">Texto del aviso</label>
			<textarea id="pka-aviso" name="texto" rows="22" class="large-text code"><?php echo esc_textarea( Aviso::textoEditable() ); ?></textarea>
			<p>
				<button class="button button-primary" name="accion" value="guardar">Guardar texto (se pedirá aceptarlo de nuevo)</button>
				<?php if ( ! Aviso::esBorrador() ) : ?>
					<button class="button" name="accion" value="borrador" onclick="return confirm('¿Volver al borrador original? Se pedirá aceptarlo de nuevo.')">Volver al borrador</button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	public static function guardarEmpleador(): void {
		self::exigir( 'pka_guardar_empleador' );

		$antes   = Ajustes::todos();
		$ajustes = $antes;
		$ruc     = isset( $_POST['ruc'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['ruc'] ) ) : '';

		if ( 11 !== strlen( $ruc ) ) {
			Avisos::agregar( 'error', 'El RUC debe tener 11 dígitos.' );
			Menu::volver( self::SLUG );
		}

		$ajustes['razon_social']        = isset( $_POST['razon_social'] ) ? sanitize_text_field( wp_unslash( $_POST['razon_social'] ) ) : $antes['razon_social'];
		$ajustes['ruc']                 = $ruc;
		$ajustes['direccion_empleador'] = isset( $_POST['direccion_empleador'] ) ? sanitize_text_field( wp_unslash( $_POST['direccion_empleador'] ) ) : $antes['direccion_empleador'];
		update_option( Ajustes::OPCION, $ajustes );

		[ $a, $d ] = Auditoria::diferencias(
			array_intersect_key( $antes, array_flip( array( 'razon_social', 'ruc', 'direccion_empleador' ) ) ),
			array_intersect_key( $ajustes, array_flip( array( 'razon_social', 'ruc', 'direccion_empleador' ) ) )
		);

		if ( $d ) {
			Auditoria::registrar( 'ajustes.empleador', 'ajustes', null, $a, $d );
		}

		Avisos::agregar( 'success', 'Datos del empleador guardados.' );
		Menu::volver( self::SLUG );
	}

	public static function guardarAviso(): void {
		self::exigir( 'pka_guardar_aviso' );

		$borrador = isset( $_POST['accion'] ) && 'borrador' === $_POST['accion'];
		$texto    = isset( $_POST['texto'] ) ? sanitize_textarea_field( wp_unslash( $_POST['texto'] ) ) : '';

		if ( ! $borrador && mb_strlen( trim( $texto ) ) < 100 ) {
			Avisos::agregar( 'error', 'El aviso es demasiado corto.' );
			Menu::volver( self::SLUG );
		}

		Aviso::guardar( $borrador ? '' : $texto );
		Avisos::agregar( 'success', 'Aviso guardado (versión ' . (int) Aviso::version() . '). Cada trabajador lo aceptará la próxima vez que marque.' );
		Menu::volver( self::SLUG );
	}

	/** Sugerencias de red nueva (la IP del taller pudo cambiar). Nunca se agregan solas. */
	private static function redesCandidatas(): void {
		foreach ( Presencia::redesCandidatas() as $c ) {
			?>
			<div class="notice notice-warning inline" style="padding:8px 12px">
				<p>
					<strong>¿Es la nueva IP del taller?</strong> Hoy llegaron <?php echo (int) $c['marcas']; ?> marcas por QR de <?php echo (int) $c['trabajadores']; ?> personas desde
					<code><?php echo esc_html( $c['red'] ); ?></code>, que no está autorizada. Suele pasar cuando el proveedor de internet cambia la IP del router.
				</p>
				<?php if ( $c['cgnat'] ) : ?>
					<p><strong>No la agregues:</strong> es una IP compartida del proveedor (CGNAT). Usa el modo "Solo QR" o pide una IP fija.</p>
				<?php else : ?>
					<p>Agrégala solo si estás seguro de que es el WiFi del taller (por ejemplo, si coincide con "Tu IP ahora" estando allí): podría ser la red de datos móviles de un operador.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pka_agregar_red">
						<input type="hidden" name="red" value="<?php echo esc_attr( $c['red'] ); ?>">
						<input type="hidden" name="nombre" value="WiFi del taller (IP nueva)">
						<?php wp_nonce_field( 'pka_agregar_red' ); ?>
						<button class="button button-primary">Sí, es la nueva IP del taller</button>
					</form>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/** Estado del sistema: sirve para comprobar el despliegue. */
	private static function diagnostico(): void {
		$puntos   = Diagnostico::revisar();
		$pendient = array_filter( $puntos, static fn( array $p ): bool => Diagnostico::BIEN !== $p['estado'] );
		$iconos   = array( Diagnostico::BIEN => '✔', Diagnostico::AVISO => '⚠', Diagnostico::PROBLEMA => '✘' );
		$colores  = array( Diagnostico::BIEN => '#00733a', Diagnostico::AVISO => '#b26200', Diagnostico::PROBLEMA => '#b32d2e' );
		?>
		<details <?php echo $pendient ? 'open' : ''; ?> style="background:#fff;border:1px solid #c3c4c7;padding:8px 16px;margin:12px 0 24px">
			<summary style="cursor:pointer;font-weight:600">
				Estado del sistema: <?php echo $pendient ? esc_html( count( $pendient ) . ' punto(s) por revisar' ) : '✔ todo en orden'; ?>
			</summary>
			<ul style="margin:12px 0">
				<?php foreach ( $puntos as $p ) : ?>
					<li>
						<span style="color:<?php echo esc_attr( $colores[ $p['estado'] ] ); ?>;font-weight:700" aria-hidden="true"><?php echo esc_html( $iconos[ $p['estado'] ] ); ?></span>
						<strong><?php echo esc_html( $p['tema'] ); ?></strong>
						<?php echo '' !== $p['detalle'] ? '— ' . esc_html( $p['detalle'] ) : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
		<?php
	}

	private static function pantallas(): void {
		$pantallas = Pantallas::listar();
		$limite    = gmdate( 'Y-m-d H:i:s', time() - Pantallas::SEGUNDOS_SIN_CONEXION );
		?>
		<h2>Pantallas del QR</h2>
		<p>
			Para activar una tablet: inicia sesión con tu usuario en esa tablet y abre
			<a href="<?php echo esc_url( home_url( '/pantalla/' ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( home_url( '/pantalla/' ) ); ?></code></a>.
			Después puedes cerrar la sesión: la pantalla sigue funcionando.
		</p>
		<table class="widefat striped">
			<thead><tr><th>Nombre</th><th>Última conexión</th><th>IP</th><th>Estado</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $pantallas ) : ?>
				<tr><td colspan="5">Todavía no hay pantallas. Sin ellas, la presencia solo se prueba con el WiFi del taller.</td></tr>
			<?php endif; ?>
			<?php foreach ( $pantallas as $p ) : ?>
				<?php $apagada = $p['activa'] && ( null === $p['ultima_conexion'] || $p['ultima_conexion'] < $limite ); ?>
				<tr>
					<td><?php echo esc_html( $p['nombre'] ); ?></td>
					<td><?php echo $p['ultima_conexion'] ? esc_html( Ajustes::local( $p['ultima_conexion'], 'd/m H:i' ) ) : '—'; ?><?php echo $apagada ? ' <strong style="color:#b32d2e">sin conexión</strong>' : ''; ?></td>
					<td><code><?php echo esc_html( $p['ultima_ip'] ); ?></code></td>
					<td><?php echo $p['activa'] ? 'Activa' : 'Desactivada'; ?></td>
					<td><a href="<?php echo esc_url( Menu::urlAccion( 'pka_cambiar_pantalla', array( 'id' => $p['id'] ) ) ); ?>"><?php echo $p['activa'] ? 'Desactivar' : 'Activar'; ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function cambiarPantalla(): void {
		self::exigir( 'pka_cambiar_pantalla' );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		foreach ( Pantallas::listar() as $p ) {
			if ( (int) $p['id'] === $id ) {
				Pantallas::cambiarEstado( $id, ! $p['activa'] );
			}
		}

		Menu::volver( self::SLUG );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::AJUSTES ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$modo     = Ajustes::modoPresencia();
		$ip       = Peticion::ip();
		$redes    = Presencia::filasRedes();
		$enLaRed  = Presencia::enRedDelTaller();
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Ajustes de asistencia</h1>

			<?php self::diagnostico(); ?>

			<h2>Cómo se prueba que están en el taller</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_guardar_modo">
				<?php wp_nonce_field( 'pka_guardar_modo' ); ?>
				<fieldset>
					<?php foreach ( ModoPresencia::cases() as $opcion ) : ?>
						<label style="display:block;margin:6px 0">
							<input type="radio" name="modo" value="<?php echo esc_attr( $opcion->value ); ?>" <?php checked( $modo->value, $opcion->value ); ?>>
							<?php echo esc_html( $opcion->etiqueta() ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description">Empieza con "WiFi del taller o QR". Si detectas trampas, cambia a "WiFi del taller y QR". Si la IP del taller cambia mucho o es compartida, usa "Solo QR".</p>
				<p>
					<label for="pka-qr-segundos"><strong>El QR de la pantalla cambia cada</strong></label>
					<select id="pka-qr-segundos" name="qr_segundos">
						<?php foreach ( CodigoPantalla::PERIODOS as $segundos => $texto ) : ?>
							<option value="<?php echo (int) $segundos; ?>" <?php selected( Ajustes::qrSegundos(), $segundos ); ?>><?php echo esc_html( $texto ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="description">Al escanearlo, el celular queda habilitado para marcar ese mismo tiempo ("Enlace vigente · 24 min"), o al menos <?php echo (int) Ajustes::todos()['pase_minutos']; ?> min. Las maquetas piden <strong>30 minutos</strong>. Ojo: un QR que dura mucho se puede fotografiar y reenviar por WhatsApp; con 15 o 30 minutos conviene "WiFi del taller y QR".</p>
				<?php submit_button( 'Guardar modo' ); ?>
			</form>

			<h2>Redes del WiFi del taller</h2>
			<?php self::redesCandidatas(); ?>
			<p>
				Tu IP ahora: <code><?php echo esc_html( '' !== $ip ? $ip : 'desconocida' ); ?></code>
				<?php echo $enLaRed ? '— ✔ está autorizada.' : '— no está autorizada.'; ?>
			</p>
			<?php if ( '' !== $ip && RedAutorizada::esCgnat( $ip ) ) : ?>
				<div class="notice notice-warning inline"><p>Esta IP es <strong>compartida por el proveedor (CGNAT)</strong>: otros clientes de internet pueden tenerla. No sirve como prueba de presencia; usa "Solo QR" o pide una IP fija.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pka_agregar_red">
				<?php wp_nonce_field( 'pka_agregar_red' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pka-red">IP o rango</label></th>
						<td>
							<input type="text" id="pka-red" name="red" class="regular-text code" value="<?php echo esc_attr( $ip ); ?>" required>
							<p class="description">Agrégala estando conectado al WiFi del taller. En IPv6 usa el prefijo de la red, por ejemplo <code>2800:200:e840:1a00::/56</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pka-nombre-red">Nombre</label></th>
						<td><input type="text" id="pka-nombre-red" name="nombre" class="regular-text" value="WiFi del taller"></td>
					</tr>
				</table>
				<?php submit_button( 'Agregar red', 'secondary' ); ?>
			</form>

			<table class="widefat striped">
				<thead><tr><th>Red</th><th>Nombre</th><th>Estado</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $redes ) : ?>
					<tr><td colspan="4">Todavía no hay redes. Sin ellas, la presencia solo se prueba con el QR.</td></tr>
				<?php endif; ?>
				<?php foreach ( $redes as $red ) : ?>
					<tr>
						<td><code><?php echo esc_html( $red['red'] ); ?></code></td>
						<td><?php echo esc_html( $red['nombre'] ); ?></td>
						<td><?php echo $red['activa'] ? 'Activa' : 'Desactivada'; ?></td>
						<td><a href="<?php echo esc_url( Menu::urlAccion( 'pka_cambiar_red', array( 'id' => $red['id'] ) ) ); ?>"><?php echo $red['activa'] ? 'Desactivar' : 'Activar'; ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php self::pantallas(); ?>
			<?php self::reglas(); ?>
			<?php self::avisos(); ?>
			<?php self::campana(); ?>
			<?php self::empleadorYAviso(); ?>
		</div>
		<?php
	}

	public static function guardarModo(): void {
		self::exigir( 'pka_guardar_modo' );

		$modo = ModoPresencia::tryFrom( isset( $_POST['modo'] ) ? sanitize_key( wp_unslash( $_POST['modo'] ) ) : '' );

		if ( null === $modo ) {
			Avisos::agregar( 'error', 'Modo desconocido.' );
			Menu::volver( self::SLUG );
		}

		$antes                    = Ajustes::todos();
		$ajustes                  = $antes;
		$ajustes['modo_presencia'] = $modo->value;
		$qr                       = isset( $_POST['qr_segundos'] ) ? absint( $_POST['qr_segundos'] ) : Ajustes::qrSegundos();
		$ajustes['qr_segundos']    = isset( CodigoPantalla::PERIODOS[ $qr ] ) ? $qr : CodigoPantalla::SEGUNDOS_VENTANA;
		update_option( Ajustes::OPCION, $ajustes );

		$claves    = array( 'modo_presencia' => true, 'qr_segundos' => true );
		[ $a, $d ] = Auditoria::diferencias( array_intersect_key( $antes, $claves ), array_intersect_key( $ajustes, $claves ) );

		if ( $d ) {
			Auditoria::registrar( 'ajustes.modo_presencia', 'ajustes', null, $a, $d );
		}
		Avisos::agregar( 'success', 'Modo guardado: ' . esc_html( $modo->etiqueta() ) . '.' );
		Menu::volver( self::SLUG );
	}

	public static function agregarRed(): void {
		global $wpdb;

		self::exigir( 'pka_agregar_red' );

		$texto  = isset( $_POST['red'] ) ? sanitize_text_field( wp_unslash( $_POST['red'] ) ) : '';
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';

		try {
			$red = RedAutorizada::desde( $texto );
		} catch ( InvalidArgumentException $e ) {
			Avisos::agregar( 'error', esc_html( $e->getMessage() ) );
			Menu::volver( self::SLUG );
		}

		$wpdb->insert(
			Tablas::nombre( 'redes' ),
			array(
				'red'        => $red->texto,
				'nombre'     => mb_substr( $nombre, 0, 80 ),
				'activa'     => 1,
				'creada_por' => get_current_user_id(),
				'creado_en'  => Peticion::ahoraUtc(),
			)
		);

		Auditoria::registrar( 'red.agregar', 'red', (int) $wpdb->insert_id, null, array( 'red' => $red->texto, 'nombre' => $nombre ) );
		Avisos::agregar( 'success', 'Red <code>' . esc_html( $red->texto ) . '</code> agregada.' . ( RedAutorizada::esCgnat( $texto ) ? ' Ojo: es una IP compartida (CGNAT).' : '' ) );
		Menu::volver( self::SLUG );
	}

	public static function cambiarRed(): void {
		global $wpdb;

		self::exigir( 'pka_cambiar_red' );

		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$tabla = Tablas::nombre( 'redes' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $fila ) {
			$nueva = $fila['activa'] ? 0 : 1;
			$wpdb->update( $tabla, array( 'activa' => $nueva ), array( 'id' => $id ) );
			Auditoria::registrar( 'red.' . ( $nueva ? 'activar' : 'desactivar' ), 'red', $id, array( 'activa' => (int) $fila['activa'] ), array( 'activa' => $nueva ) );
		}

		Menu::volver( self::SLUG );
	}

	private static function exigir( string $accion ): void {
		if ( ! current_user_can( Capacidades::AJUSTES ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( $accion );
	}
}
