<?php
/**
 * Datos de demostración para ver el panel en un WordPress LOCAL (vacía las tablas).
 *
 *   php wp-cli.phar --path=C:/laragon/www/asistencia eval-file tests/integracion/datos-demo.php
 *
 * Las marcas se cargan como "manual" con la hora de hoy; sirven solo para ver pantallas.
 */

use Pekokis\Asistencia\Nucleo\PruebaPresencia;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Marcas;
use Pekokis\Asistencia\WordPress\Tablas;
use Pekokis\Asistencia\WordPress\Trabajadores;

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Solo en un WordPress local.' );
}

global $wpdb;

foreach ( array( 'trabajadores', 'marcas', 'auditoria', 'credenciales', 'dispositivos', 'activaciones', 'redes', 'correcciones', 'pantallas', 'revisiones', 'calendario', 'ausencias', 'sueldos', 'avisos', 'pagos', 'recibos', 'solicitudes_celular', 'llaves_gerencia', 'horario_cambios', 'tardanzas', 'descuentos', 'horas_ajustes' ) as $tabla ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Tablas::nombre( $tabla ) ); // phpcs:ignore
}

wp_set_current_user( 1 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// El aviso vuelve a su borrador (versión 1) y sin webhook de respaldo.
$ajustesDemo = \Pekokis\Asistencia\WordPress\Ajustes::todos();
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'aviso_texto' => '', 'aviso_version' => 1, 'webhook_respaldo' => '' ) + $ajustesDemo );

$wpdb->insert( Tablas::nombre( 'redes' ), array( 'red' => '127.0.0.1', 'nombre' => 'Local (pruebas)', 'activa' => 1, 'creada_por' => 1, 'creado_en' => gmdate( 'Y-m-d H:i:s' ) ) );
$wpdb->insert( Tablas::nombre( 'redes' ), array( 'red' => '::1', 'nombre' => 'Local IPv6 (pruebas)', 'activa' => 1, 'creada_por' => 1, 'creado_en' => gmdate( 'Y-m-d H:i:s' ) ) );

$horario = (int) array_key_first( Trabajadores::horarios() );
$zona    = Ajustes::zona();
$hoy     = wp_date( 'Y-m-d', null, $zona );

$personas = array(
	array( '41111111', 'Rosa', 'Huamán Torres', 'Costura', array( array( 'entrada', '08:56' ) ) ),
	array( '42222222', 'Carlos', 'Mendoza Ríos', 'Estampado', array( array( 'entrada', '09:14' ) ) ),
	array( '43333333', 'Lucía', 'Paredes Soto', 'Costura', array( array( 'entrada', '08:59' ), array( 'salida', '13:00', 'refrigerio' ) ) ),
	array( '44444444', 'Jorge', 'Quispe Lazo', 'Despacho', array( array( 'entrada', '09:01' ), array( 'salida', '11:20', 'emergencia', 'Mi hijo se enfermó' ) ) ),
	array( '45555555', 'Milagros', 'Vargas Díaz', 'Atención', array() ),
);

foreach ( $personas as [ $dni, $nombres, $apellidos, $area, $marcas ] ) {
	$id = Trabajadores::guardar( array( 'dni' => $dni, 'nombres' => $nombres, 'apellidos' => $apellidos, 'area' => $area, 'cargo' => 'Operario', 'horario_id' => $horario ) );

	foreach ( $marcas as $m ) {
		$momento = new DateTimeImmutable( $hoy . ' ' . $m[1], $zona );

		if ( $momento > new DateTimeImmutable( 'now', $zona ) ) {
			continue; // No inventar marcas en el futuro si se corre temprano.
		}

		Marcas::registrar(
			array(
				'trabajador_id'     => $id,
				'tipo'              => TipoMarca::from( $m[0] ),
				'motivo_clave'      => $m[2] ?? null,
				'nota'              => $m[3] ?? '',
				'marcado'           => $momento,
				'prueba'            => 'emergencia' === ( $m[2] ?? '' ) ? PruebaPresencia::SIN_PRESENCIA : PruebaPresencia::WIFI,
				'requiere_revision' => 'emergencia' === ( $m[2] ?? '' ),
				'dispositivo_id'    => null,
				'credencial_id'     => null,
				'pantalla_id'       => null,
				'origen'            => 'manual',
				'registrado_por'    => 1,
			)
		);
	}
}

// Días anteriores: perfiles "creados" hace 10 días, Rosa sin salida ayer y una solicitud de Carlos.
$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tablas::nombre( 'trabajadores' ) . ' SET creado_en = %s', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
$ayer = ( new DateTimeImmutable( $hoy ) )->modify( '-1 day' )->format( 'Y-m-d' );
$ids  = $wpdb->get_col( 'SELECT id FROM ' . Tablas::nombre( 'trabajadores' ) . ' ORDER BY id' ); // phpcs:ignore

foreach ( $ids as $i => $tid ) {
	if ( 4 === $i ) {
		continue; // Milagros no marcó ayer: aparece como posible falta.
	}

	$entrada = 0 === $i ? '08:55' : '08:58';
	\Pekokis\Asistencia\WordPress\Correcciones::marcaManual( (int) $tid, $ayer, $entrada, \Pekokis\Asistencia\Nucleo\Accion::ENTRADA, null, 'Demo' );

	if ( 0 !== $i ) { // Rosa queda sin salida.
		// Carlos se quedó hasta las 21:15 sin horas extra autorizadas: aparece en "Horas extra".
		\Pekokis\Asistencia\WordPress\Correcciones::marcaManual( (int) $tid, $ayer, 1 === $i ? '21:15' : '19:31', \Pekokis\Asistencia\Nucleo\Accion::FIN_JORNADA, null, 'Demo' );
	}
}

\Pekokis\Asistencia\WordPress\Correcciones::solicitar( (int) $ids[0], $ayer, '19:30', \Pekokis\Asistencia\Nucleo\Accion::FIN_JORNADA, null, 'Me fui apurada y no marqué la salida' );

// Feriados del año y Milagros de vacaciones esta semana: así se ven "Ausencia justificada" y el calendario.
\Pekokis\Asistencia\WordPress\Calendario::cargarFeriadosPeru( (int) substr( $hoy, 0, 4 ) );
\Pekokis\Asistencia\WordPress\Ausencias::crear(
	array(
		'trabajador_id' => (int) $ids[4],
		'tipo'          => 'vacaciones',
		'desde'         => ( new DateTimeImmutable( $hoy ) )->modify( '-1 day' )->format( 'Y-m-d' ),
		'hasta'         => ( new DateTimeImmutable( $hoy ) )->modify( '+5 days' )->format( 'Y-m-d' ),
		'nota'          => 'Papeleta 2026-031',
	)
);

// Honorarios y tarifas de ejemplo (pago por recibos por honorarios).
foreach ( $ids as $i => $tid ) {
	\Pekokis\Asistencia\WordPress\Sueldos::agregar(
		(int) $tid,
		array(
			'honorario'      => (string) ( 1500 + 100 * $i ),
			'tarifa_extra'   => (string) ( 10 + $i ),
			'tarifa_domingo' => (string) ( 18 + $i ),
			'tarifa_feriado' => (string) ( 20 + $i ),
			'vigente_desde'  => '2026-01-01',
			'nota'           => 'Demo',
		)
	);
}

// Recibos de ejemplo: Carlos envió el suyo y a Lucía se lo observaron.
$mesDemo = substr( $hoy, 0, 7 );
foreach ( array( 1 => array( 'E001-120', '1619.62' ), 2 => array( 'E001-077', '1650' ) ) as $i => [ $numero, $monto ] ) {
	$tmp = wp_tempnam( 'recibo-demo.pdf' );
	file_put_contents( $tmp, "%PDF-1.4\n% Recibo de demostración\n" ); // phpcs:ignore
	$idRecibo = \Pekokis\Asistencia\WordPress\Recibos::enviar( (int) $ids[ $i ], $mesDemo, array( 'numero' => $numero, 'monto' => $monto ), array( 'tmp_name' => $tmp ) );
	wp_delete_file( $tmp );

	if ( 2 === $i && is_int( $idRecibo ) ) {
		\Pekokis\Asistencia\WordPress\Recibos::observar( $idRecibo, 'El monto no coincide con el cálculo del mes' );
	}
}

WP_CLI::success( 'Datos de demostración cargados para ' . $hoy . '.' );
