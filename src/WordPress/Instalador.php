<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\CatalogoMotivos;

defined( 'ABSPATH' ) || exit;

/**
 * Crea y actualiza las tablas, los roles y los datos iniciales.
 *
 * Todo es idempotente: se puede ejecutar varias veces sin duplicar ni pisar
 * lo que RR.HH. haya cambiado.
 */
final class Instalador {
	public const VERSION_BD = '10';

	private const OPCION_VERSION = 'pka_version_bd';

	public static function activar(): void {
		self::crearTablas();
		Capacidades::registrarRoles();
		self::sembrarMotivos();
		self::sembrarHorarioBase();
		add_option( Ajustes::OPCION, Ajustes::porDefecto() );
		Secretos::clave();

		update_option( self::OPCION_VERSION, self::VERSION_BD );
	}

	public static function actualizarSiHaceFalta(): void {
		$anterior = get_option( self::OPCION_VERSION );

		if ( $anterior !== self::VERSION_BD ) {
			self::activar();

			// Versión 10: se acordó que el QR de la pantalla cambie cada 30 minutos
			// (maquetas P1A y T4B). Solo se mueve el valor de fábrica anterior (30 s);
			// si alguien eligió otro periodo, se respeta.
			if ( false !== $anterior && (int) $anterior < 10 ) {
				$ajustes = get_option( Ajustes::OPCION, array() );
				if ( is_array( $ajustes ) && 30 === (int) ( $ajustes['qr_segundos'] ?? 30 ) ) {
					$ajustes['qr_segundos'] = 1800;
					update_option( Ajustes::OPCION, $ajustes );
				}
			}
		}
	}

	private static function crearTablas(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::esquema() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Tablas del MVP (plan técnico, sección 7). Las de sueldos, permisos, sobretiempo,
	 * días calculados y notificaciones llegan con las fases 2 a 4.
	 *
	 * Formato pensado para dbDelta: un campo por línea y dos espacios después de PRIMARY KEY.
	 *
	 * @return string[]
	 */
	public static function esquema(): array {
		global $wpdb;

		$c = $wpdb->get_charset_collate();
		$t = array();

		foreach ( array( 'trabajadores', 'horarios', 'horario_dias', 'motivos', 'marcas', 'revisiones', 'correcciones', 'credenciales', 'dispositivos', 'activaciones', 'redes', 'pantallas', 'auditoria', 'calendario', 'ausencias', 'sueldos', 'sobretiempo', 'avisos', 'pagos', 'recibos', 'solicitudes_celular', 'llaves_gerencia' ) as $tabla ) {
			$t[ $tabla ] = Tablas::nombre( $tabla );
		}

		return array(
			"CREATE TABLE {$t['trabajadores']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NULL,
				dni varchar(15) NOT NULL,
				nombres varchar(120) NOT NULL,
				apellidos varchar(120) NOT NULL,
				telefono varchar(32) NOT NULL DEFAULT '',
				correo varchar(190) NOT NULL DEFAULT '',
				cargo varchar(120) NOT NULL DEFAULT '',
				area varchar(120) NOT NULL DEFAULT '',
				jefe_user_id bigint(20) unsigned NULL,
				horario_id bigint(20) unsigned NOT NULL,
				fecha_ingreso date NULL,
				fecha_cese date NULL,
				estado varchar(16) NOT NULL DEFAULT 'activo',
				aviso_version smallint(5) unsigned NOT NULL DEFAULT 0,
				aviso_aceptado_en datetime NULL,
				cumpleanos char(5) NOT NULL DEFAULT '',
				pin_hash varchar(255) NOT NULL DEFAULT '',
				pin_creado_en datetime NULL,
				origen varchar(16) NOT NULL DEFAULT 'panel',
				creado_en datetime NOT NULL,
				actualizado_en datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dni (dni),
				KEY user_id (user_id),
				KEY jefe_estado (jefe_user_id,estado)
			) {$c};",

			"CREATE TABLE {$t['horarios']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nombre varchar(80) NOT NULL,
				tolerancia_min smallint(5) unsigned NOT NULL DEFAULT 5,
				refrigerio_min smallint(5) unsigned NOT NULL DEFAULT 60,
				activo tinyint(1) NOT NULL DEFAULT 1,
				creado_en datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$c};",

			"CREATE TABLE {$t['horario_dias']} (
				horario_id bigint(20) unsigned NOT NULL,
				dia_semana tinyint(1) unsigned NOT NULL,
				laborable tinyint(1) NOT NULL DEFAULT 0,
				entrada time NULL,
				salida time NULL,
				PRIMARY KEY  (horario_id,dia_semana)
			) {$c};",

			"CREATE TABLE {$t['motivos']} (
				id smallint(5) unsigned NOT NULL AUTO_INCREMENT,
				clave varchar(32) NOT NULL,
				nombre varchar(80) NOT NULL,
				categoria varchar(16) NOT NULL,
				cuenta_como_trabajado tinyint(1) NOT NULL DEFAULT 0,
				requiere_aprobacion tinyint(1) NOT NULL DEFAULT 0,
				sin_presencia tinyint(1) NOT NULL DEFAULT 0,
				orden smallint(5) unsigned NOT NULL DEFAULT 0,
				activo tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				UNIQUE KEY clave (clave)
			) {$c};",

			// Solo inserciones: las correcciones agregan o anulan, nunca editan.
			"CREATE TABLE {$t['marcas']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				dia_laboral date NOT NULL,
				tipo varchar(8) NOT NULL,
				motivo_clave varchar(32) NULL,
				nota varchar(255) NOT NULL DEFAULT '',
				marcado_utc datetime NOT NULL,
				prueba varchar(16) NOT NULL,
				requiere_revision tinyint(1) NOT NULL DEFAULT 0,
				ip varchar(45) NOT NULL DEFAULT '',
				dispositivo_id bigint(20) unsigned NULL,
				credencial_id bigint(20) unsigned NULL,
				pantalla_id bigint(20) unsigned NULL,
				origen varchar(16) NOT NULL,
				correccion_id bigint(20) unsigned NULL,
				registrado_por bigint(20) unsigned NULL,
				hash_anterior char(64) NOT NULL,
				hash char(64) NOT NULL,
				PRIMARY KEY  (id),
				KEY trabajador_dia (trabajador_id,dia_laboral,marcado_utc),
				KEY dia_laboral (dia_laboral),
				KEY revision (requiere_revision,dia_laboral)
			) {$c};",

			"CREATE TABLE {$t['revisiones']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				marca_id bigint(20) unsigned NOT NULL,
				decision varchar(12) NOT NULL,
				nota varchar(500) NOT NULL DEFAULT '',
				revisado_por bigint(20) unsigned NOT NULL,
				revisado_en datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY marca_id (marca_id)
			) {$c};",

			"CREATE TABLE {$t['correcciones']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				accion varchar(12) NOT NULL,
				marca_id bigint(20) unsigned NULL,
				tipo varchar(8) NULL,
				motivo_clave varchar(32) NULL,
				marcado_utc datetime NULL,
				justificacion varchar(500) NOT NULL,
				solicitado_por_trabajador bigint(20) unsigned NULL,
				solicitado_por_user bigint(20) unsigned NULL,
				estado varchar(12) NOT NULL DEFAULT 'pendiente',
				resuelto_por bigint(20) unsigned NULL,
				resuelto_en datetime NULL,
				nota_resolucion varchar(500) NOT NULL DEFAULT '',
				hora_pedida_utc datetime NULL,
				creado_en datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY estado (estado,creado_en),
				KEY trabajador (trabajador_id,creado_en),
				KEY marca_id (marca_id)
			) {$c};",

			"CREATE TABLE {$t['credenciales']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				credential_id varchar(255) NOT NULL,
				credential_hash char(64) NOT NULL,
				public_key text NOT NULL,
				sign_count int(10) unsigned NOT NULL DEFAULT 0,
				dispositivo_id bigint(20) unsigned NULL,
				nombre varchar(120) NOT NULL DEFAULT '',
				creado_en datetime NOT NULL,
				ultimo_uso datetime NULL,
				revocada_en datetime NULL,
				pendiente tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY credential_hash (credential_hash),
				KEY trabajador (trabajador_id)
			) {$c};",

			"CREATE TABLE {$t['llaves_gerencia']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				credential_id varchar(255) NOT NULL,
				credential_hash char(64) NOT NULL,
				public_key text NOT NULL,
				sign_count int(10) unsigned NOT NULL DEFAULT 0,
				nombre varchar(120) NOT NULL DEFAULT '',
				creado_en datetime NOT NULL,
				ultimo_uso datetime NULL,
				revocada_en datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY credential_hash (credential_hash),
				KEY usuario (user_id)
			) {$c};",

			"CREATE TABLE {$t['dispositivos']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				token_hash char(64) NOT NULL,
				user_agent varchar(255) NOT NULL DEFAULT '',
				creado_en datetime NOT NULL,
				ultimo_uso datetime NULL,
				revocado_en datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY trabajador (trabajador_id)
			) {$c};",

			"CREATE TABLE {$t['solicitudes_celular']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				tipo varchar(16) NOT NULL,
				estado varchar(16) NOT NULL DEFAULT 'pendiente',
				credencial_id bigint(20) unsigned NULL,
				dispositivo_id bigint(20) unsigned NULL,
				navegador varchar(255) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				creado_en datetime NOT NULL,
				resuelto_por bigint(20) unsigned NULL,
				resuelto_en datetime NULL,
				respuesta varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY estado (estado),
				KEY trabajador (trabajador_id)
			) {$c};",

			"CREATE TABLE {$t['activaciones']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				token_hash char(64) NOT NULL,
				expira_en datetime NOT NULL,
				usado_en datetime NULL,
				creado_por bigint(20) unsigned NOT NULL,
				creado_en datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY trabajador (trabajador_id)
			) {$c};",

			"CREATE TABLE {$t['redes']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				red varchar(64) NOT NULL,
				nombre varchar(80) NOT NULL DEFAULT '',
				activa tinyint(1) NOT NULL DEFAULT 1,
				creada_por bigint(20) unsigned NOT NULL,
				creado_en datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$c};",

			"CREATE TABLE {$t['pantallas']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nombre varchar(80) NOT NULL,
				token_hash char(64) NOT NULL,
				activa tinyint(1) NOT NULL DEFAULT 1,
				ultima_conexion datetime NULL,
				ultima_ip varchar(45) NOT NULL DEFAULT '',
				creado_por bigint(20) unsigned NOT NULL,
				creado_en datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash)
			) {$c};",

			// Feriados y días no laborables de la empresa (versión 2).
			"CREATE TABLE {$t['calendario']} (
				fecha date NOT NULL,
				nombre varchar(120) NOT NULL,
				tipo varchar(16) NOT NULL DEFAULT 'feriado',
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				creado_en datetime NOT NULL,
				PRIMARY KEY  (fecha)
			) {$c};",

			// Vacaciones, descanso médico, licencias y permisos de días completos (versión 2).
			"CREATE TABLE {$t['ausencias']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				tipo varchar(20) NOT NULL,
				desde date NOT NULL,
				hasta date NOT NULL,
				nota varchar(255) NOT NULL DEFAULT '',
				estado varchar(12) NOT NULL DEFAULT 'vigente',
				creado_por bigint(20) unsigned NOT NULL,
				creado_en datetime NOT NULL,
				anulado_por bigint(20) unsigned NULL,
				anulado_en datetime NULL,
				motivo_anulacion varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY trabajador_periodo (trabajador_id,estado,desde,hasta),
				KEY periodo (desde,hasta)
			) {$c};",

			// Honorario y tarifas por persona, con historial (versión 3; tarifas desde la 5).
			// monto_centimos es el honorario mensual. Un cambio cierra el anterior; un error se anula, no se edita.
			"CREATE TABLE {$t['sueldos']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				modalidad varchar(12) NOT NULL DEFAULT 'honorario',
				monto_centimos int(10) unsigned NOT NULL,
				tarifa_extra int(10) unsigned NOT NULL DEFAULT 0,
				tarifa_sabado int(10) unsigned NOT NULL DEFAULT 0,
				tarifa_domingo int(10) unsigned NOT NULL DEFAULT 0,
				tarifa_feriado int(10) unsigned NOT NULL DEFAULT 0,
				vigente_desde date NOT NULL,
				vigente_hasta date NULL,
				nota varchar(255) NOT NULL DEFAULT '',
				estado varchar(12) NOT NULL DEFAULT 'vigente',
				creado_por bigint(20) unsigned NOT NULL,
				creado_en datetime NOT NULL,
				anulado_por bigint(20) unsigned NULL,
				anulado_en datetime NULL,
				motivo_anulacion varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY trabajador (trabajador_id,estado,vigente_desde)
			) {$c};",

			// En desuso desde la versión 5: las horas extra ya no se autorizan (son automáticas).
			// La tabla se conserva por si tiene datos de la 0.7.0.
			"CREATE TABLE {$t['sobretiempo']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				fecha date NOT NULL,
				desde time NOT NULL,
				hasta time NOT NULL,
				compensacion varchar(12) NOT NULL DEFAULT 'pago',
				motivo varchar(255) NOT NULL DEFAULT '',
				estado varchar(12) NOT NULL DEFAULT 'vigente',
				autorizado_por bigint(20) unsigned NOT NULL,
				autorizado_en datetime NOT NULL,
				anulado_por bigint(20) unsigned NULL,
				anulado_en datetime NULL,
				motivo_anulacion varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY trabajador_fecha (trabajador_id,estado,fecha),
				KEY fecha (fecha)
			) {$c};",

			// Pago del mes marcado como hecho (versión 6). Desmarcar no borra: anula.
			"CREATE TABLE {$t['pagos']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				mes char(7) NOT NULL,
				total_centimos int(10) unsigned NOT NULL,
				estado varchar(12) NOT NULL DEFAULT 'pagado',
				marcado_por bigint(20) unsigned NOT NULL,
				marcado_en datetime NOT NULL,
				anulado_por bigint(20) unsigned NULL,
				anulado_en datetime NULL,
				PRIMARY KEY  (id),
				KEY mes (mes,estado,trabajador_id)
			) {$c};",

			// Recibos por honorarios que envía cada trabajador (versión 6). El PDF va a una carpeta privada.
			"CREATE TABLE {$t['recibos']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				trabajador_id bigint(20) unsigned NOT NULL,
				mes char(7) NOT NULL,
				numero varchar(20) NOT NULL,
				monto_centimos int(10) unsigned NOT NULL,
				archivo varchar(80) NOT NULL,
				archivo_bytes int(10) unsigned NOT NULL DEFAULT 0,
				archivo_sha256 char(64) NOT NULL DEFAULT '',
				estado varchar(12) NOT NULL DEFAULT 'enviado',
				comentario varchar(500) NOT NULL DEFAULT '',
				enviado_en datetime NOT NULL,
				revisado_por bigint(20) unsigned NULL,
				revisado_en datetime NULL,
				anulado_en datetime NULL,
				PRIMARY KEY  (id),
				KEY trabajador_mes (trabajador_id,mes),
				KEY mes_estado (mes,estado)
			) {$c};",

			// Cola de avisos por correo y webhook (versión 4). La clave evita repetir un aviso.
			"CREATE TABLE {$t['avisos']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				tipo varchar(24) NOT NULL,
				clave varchar(120) NOT NULL,
				canal varchar(10) NOT NULL,
				destino varchar(190) NOT NULL,
				nombre varchar(120) NOT NULL DEFAULT '',
				telefono varchar(32) NOT NULL DEFAULT '',
				trabajador_id bigint(20) unsigned NOT NULL DEFAULT 0,
				linea text NOT NULL,
				enlace varchar(255) NOT NULL DEFAULT '',
				estado varchar(12) NOT NULL DEFAULT 'pendiente',
				intentos tinyint(3) unsigned NOT NULL DEFAULT 0,
				error varchar(255) NOT NULL DEFAULT '',
				creado_en datetime NOT NULL,
				enviado_en datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY unico (clave,canal,destino),
				KEY estado (estado,id),
				KEY creado_en (creado_en)
			) {$c};",

			// Solo inserciones, con cadena de hashes igual que las marcas.
			"CREATE TABLE {$t['auditoria']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				actor_user_id bigint(20) unsigned NULL,
				actor_trabajador_id bigint(20) unsigned NULL,
				accion varchar(64) NOT NULL,
				entidad varchar(32) NOT NULL,
				entidad_id bigint(20) unsigned NULL,
				antes longtext NULL,
				despues longtext NULL,
				motivo varchar(500) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				creado_utc datetime NOT NULL,
				hash_anterior char(64) NOT NULL,
				hash char(64) NOT NULL,
				PRIMARY KEY  (id),
				KEY entidad (entidad,entidad_id),
				KEY creado_utc (creado_utc)
			) {$c};",
		);
	}

	/** Agrega los motivos que falten; no toca los que RR.HH. ya editó. */
	private static function sembrarMotivos(): void {
		global $wpdb;

		$tabla = Tablas::nombre( 'motivos' );

		foreach ( CatalogoMotivos::porDefecto() as $motivo ) {
			$existe = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tabla} WHERE clave = %s", $motivo->clave ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $existe ) {
				continue;
			}

			$wpdb->insert(
				$tabla,
				array(
					'clave'                 => $motivo->clave,
					'nombre'                => $motivo->nombre,
					'categoria'             => $motivo->categoria->value,
					'cuenta_como_trabajado' => (int) $motivo->cuentaComoTrabajado,
					'requiere_aprobacion'   => (int) $motivo->requiereAprobacion,
					'sin_presencia'         => (int) $motivo->sinPresencia,
					'orden'                 => $motivo->orden,
					'activo'                => (int) $motivo->activo,
				),
				array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
			);
		}
	}

	/** Horario base de Pekokis: lunes a viernes de 9:00 a 19:30 (por confirmar con gerencia). */
	private static function sembrarHorarioBase(): void {
		global $wpdb;

		$horarios = Tablas::nombre( 'horarios' );

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$horarios}" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		$wpdb->insert(
			$horarios,
			array(
				'nombre'         => 'Horario base',
				'tolerancia_min' => 5,
				'refrigerio_min' => 60,
				'activo'         => 1,
				'creado_en'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%d', '%s' )
		);

		$horario_id = (int) $wpdb->insert_id;

		if ( ! $horario_id ) {
			return;
		}

		// 1 = lunes … 7 = domingo (ISO-8601).
		for ( $dia = 1; $dia <= 7; $dia++ ) {
			$laborable = $dia <= 5;

			$wpdb->insert(
				Tablas::nombre( 'horario_dias' ),
				array(
					'horario_id' => $horario_id,
					'dia_semana' => $dia,
					'laborable'  => (int) $laborable,
					'entrada'    => $laborable ? '09:00:00' : null,
					'salida'     => $laborable ? '19:30:00' : null,
				),
				array( '%d', '%d', '%d', '%s', '%s' )
			);
		}
	}
}
