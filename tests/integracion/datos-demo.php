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

foreach ( array( 'trabajadores', 'marcas', 'auditoria', 'credenciales', 'dispositivos', 'activaciones', 'redes', 'correcciones', 'pantallas', 'revisiones' ) as $tabla ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Tablas::nombre( $tabla ) ); // phpcs:ignore
}

wp_set_current_user( 1 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

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

WP_CLI::success( 'Datos de demostración cargados para ' . $hoy . '.' );
