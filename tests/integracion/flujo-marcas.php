<?php
/**
 * Prueba de punta a punta contra un WordPress LOCAL:
 *   alta del trabajador → enlace → passkey → identificación → marcas → auditoría.
 *
 * Uso (vacía las tablas del plugin, por eso solo corre con WP_ENVIRONMENT_TYPE = local):
 *   php wp-cli.phar --path=C:/laragon/www/asistencia eval-file tests/integracion/flujo-marcas.php
 */

use Pekokis\Asistencia\WordPress\Activaciones;
use Pekokis\Asistencia\WordPress\Dispositivos;
use Pekokis\Asistencia\WordPress\InsercionEncadenada;
use Pekokis\Asistencia\WordPress\Passkeys;
use Pekokis\Asistencia\WordPress\Presencia;
use Pekokis\Asistencia\WordPress\Tablas;
use Pekokis\Asistencia\WordPress\Trabajadores;

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Esta prueba vacía tablas: solo corre en un WordPress local.' );
}

require __DIR__ . '/AutenticadorSimulado.php';

global $wpdb;

$fallos = 0;

$comprobar = static function ( string $que, bool $ok, string $detalle = '' ) use ( &$fallos ): void {
	$fallos += $ok ? 0 : 1;
	WP_CLI::line( ( $ok ? '  ✔ ' : '  ✘ ' ) . $que . ( $ok || '' === $detalle ? '' : ' → ' . $detalle ) );
};

$api = static function ( string $metodo, string $ruta, array $cuerpo = array() ): array {
	$peticion = new WP_REST_Request( $metodo, '/asistencia/v1/' . $ruta );

	if ( $cuerpo ) {
		$peticion->set_header( 'Content-Type', 'application/json' );
		$peticion->set_body( wp_json_encode( $cuerpo ) );
	}

	$respuesta = rest_do_request( $peticion );

	return array( $respuesta->get_status(), json_decode( wp_json_encode( rest_get_server()->response_to_data( $respuesta, false ) ), true ) );
};

// ---- Preparación: tablas vacías, una red autorizada y la IP del "WiFi del taller".
foreach ( array( 'trabajadores', 'marcas', 'auditoria', 'credenciales', 'dispositivos', 'activaciones', 'redes', 'correcciones' ) as $tabla ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Tablas::nombre( $tabla ) ); // phpcs:ignore
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pka\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pka\\_%'" ); // phpcs:ignore
$_COOKIE = array();

$wpdb->insert( Tablas::nombre( 'redes' ), array( 'red' => '203.0.113.10', 'nombre' => 'WiFi de prueba', 'activa' => 1, 'creada_por' => 1, 'creado_en' => gmdate( 'Y-m-d H:i:s' ) ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
wp_set_current_user( 1 );

WP_CLI::line( 'Origen: ' . Passkeys::origen() . ' · rpId: ' . Passkeys::rpId() );

// ---- 1. Alta del trabajador.
WP_CLI::line( '1. Perfil' );
$horario = (int) array_key_first( Trabajadores::horarios() );
$id      = Trabajadores::guardar( array( 'dni' => '45678912', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'horario_id' => $horario, 'area' => 'Taller' ) );
$comprobar( 'se crea el trabajador', is_int( $id ), is_wp_error( $id ) ? $id->get_error_message() : '' );
$dup = Trabajadores::guardar( array( 'dni' => '45678912', 'nombres' => 'Otra', 'apellidos' => 'Persona', 'horario_id' => $horario ) );
$comprobar( 'no se repite el DNI', is_wp_error( $dup ) && isset( $dup->get_error_data()['errores']['dni'] ) );
Trabajadores::guardar( array( 'dni' => '45678912', 'nombres' => 'Ana', 'apellidos' => 'Quispe Rojas', 'horario_id' => $horario, 'area' => 'Taller' ), $id, 'Segundo apellido' );

// ---- 2. Activación con passkey.
WP_CLI::line( '2. Activación' );
$url   = Activaciones::generar( $id );
$token = substr( $url, strpos( $url, '#' ) + 1 );
$comprobar( 'el token va después de #', 32 === strlen( $token ) && str_contains( $url, '/activar/#' ) );

$celular = new AutenticadorSimulado( Passkeys::rpId(), Passkeys::origen() );
[ $st, $op ] = $api( 'POST', 'activacion/opciones', array( 'token' => $token ) );
$comprobar( 'opciones de registro', 200 === $st && 'required' === $op['publicKey']['authenticatorSelection']['userVerification'], wp_json_encode( $op ) );
$comprobar( 'el userHandle no lleva el DNI', ! str_contains( (string) base64_decode( strtr( $op['publicKey']['user']['id'], '-_', '+/' ) ), '45678912' ) );

[ $st, $r ] = $api( 'POST', 'activacion/completar', array( 'token' => $token, 'reto' => $op['reto'], 'credencial' => $celular->crear( $op['publicKey']['challenge'] ) ) );
$comprobar( 'passkey registrada', 200 === $st && 'Ana' === $r['nombre'], wp_json_encode( $r ) );
$comprobar( 'el celular queda vinculado', null !== Dispositivos::actual() );

[ $st ] = $api( 'POST', 'activacion/opciones', array( 'token' => $token ) );
$comprobar( 'el enlace no sirve dos veces', 404 === $st );

// Otro trabajador intenta activarse en el mismo celular.
$id2    = Trabajadores::guardar( array( 'dni' => '40404040', 'nombres' => 'Luis', 'apellidos' => 'Pérez', 'horario_id' => $horario ) );
$token2 = substr( Activaciones::generar( $id2 ), -32 );
[ $st, $r ] = $api( 'POST', 'activacion/opciones', array( 'token' => $token2 ) );
$comprobar( 'un celular = un trabajador', 409 === $st, (string) $st );

// ---- 3. Identificación y marcas.
WP_CLI::line( '3. Marcas' );
$identificar = static function ( bool $verificado = true ) use ( $api, $celular ): array {
	[ , $op ] = $api( 'POST', 'identificar/opciones' );

	return $api( 'POST', 'identificar', array( 'reto' => $op['reto'], 'credencial' => $celular->firmar( $op['publicKey']['challenge'], $verificado ) ) );
};

[ $st, $r ] = $identificar( false );
$comprobar( 'sin verificación del usuario (sin huella) no entra', 401 === $st, (string) $st );

[ $st, $id1 ] = $identificar();
$comprobar( 'identificación con huella', 200 === $st && 'fuera' === $id1['estado']['clave'] && 'entrada' === $id1['acciones'][0]['clave'], wp_json_encode( $id1 ) );

[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id1['ticket'], 'accion' => 'entrada' ) );
$comprobar( 'entrada aceptada por WiFi', 200 === $st && 'aceptada' === $r['resultado'] && str_contains( $r['mensaje'], 'WiFi' ), wp_json_encode( $r ) );
[ $st ] = $api( 'POST', 'marcas', array( 'ticket' => $id1['ticket'], 'accion' => 'entrada' ) );
$comprobar( 'el ticket es de un solo uso', 401 === $st );

[ , $id2r ] = $identificar();
$comprobar( 'ahora está trabajando con 3 botones', 'trabajando' === $id2r['estado']['clave'] && 3 === count( $id2r['acciones'] ) );

$_SERVER['REMOTE_ADDR'] = '198.51.100.7'; // Fuera del taller.
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id2r['ticket'], 'accion' => 'fin_jornada' ) );
$comprobar( 'fuera del WiFi se rechaza y ofrece el QR', 422 === $st && ! empty( $r['data']['acepta_qr'] ), wp_json_encode( $r ) );

[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id2r['ticket'], 'accion' => 'salida_temporal', 'motivo' => 'emergencia', 'nota' => 'Mi hijo se enfermó' ) );
$comprobar( 'emergencia sin presencia se acepta (mismo ticket) y queda para revisión', 200 === $st && true === $r['revision'] && str_contains( $r['mensaje'], 'fuera del taller' ), wp_json_encode( $r ) );

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
[ , $id3 ] = $identificar();
$comprobar( 'después de la emergencia: fuera temporalmente → Volver', 'fuera_temporal' === $id3['estado']['clave'] && 'regreso' === $id3['acciones'][0]['clave'] );
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id3['ticket'], 'accion' => 'salida_temporal', 'motivo' => 'personal' ) );
$comprobar( 'acción que no corresponde al estado se rechaza', 422 === $st && 'pka_accion_invalida' === $r['code'], wp_json_encode( $r ) );
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id3['ticket'], 'accion' => 'regreso' ) );
$comprobar( 'regreso aceptado', 200 === $st && 2 === count( array_filter( $r['hoy'], static fn( $m ) => 'Entrada' === $m['texto'] ) ) );

[ , $id4 ] = $identificar();
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id4['ticket'], 'accion' => 'salida_refrigerio' ) );
$comprobar( 'refrigerio sin elegir motivo (tiene uno solo)', 200 === $st && str_starts_with( $r['mensaje'], 'Refrigerio' ), wp_json_encode( $r ) );

// ---- 4. Cese.
WP_CLI::line( '4. Cese' );
$comprobar( 'cese sin motivo se rechaza', is_wp_error( Trabajadores::cesar( $id, gmdate( 'Y-m-d' ), '' ) ) );
$comprobar( 'cese con motivo', true === Trabajadores::cesar( $id, gmdate( 'Y-m-d' ), 'Fin de contrato' ) );
[ $st ] = $identificar();
$comprobar( 'ya no puede identificarse', 401 === $st, (string) $st );

// ---- 5. Integridad.
WP_CLI::line( '5. Integridad' );
$marcas = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'marcas' ) ); // phpcs:ignore
$comprobar( '4 marcas guardadas', 4 === $marcas, (string) $marcas );
$comprobar( 'cadena de marcas íntegra', null === InsercionEncadenada::verificar( 'marcas' ) );
$comprobar( 'cadena de auditoría íntegra', null === InsercionEncadenada::verificar( 'auditoria' ) );
$acciones = $wpdb->get_col( 'SELECT accion FROM ' . Tablas::nombre( 'auditoria' ) . ' ORDER BY id' ); // phpcs:ignore
$comprobar( 'auditoría registra alta, edición, enlace, passkey y cese', array( 'trabajador.crear', 'trabajador.editar', 'activacion.generar', 'passkey.registrar' ) === array_slice( $acciones, 0, 4 ) && in_array( 'trabajador.cesar', $acciones, true ), implode( ', ', $acciones ) );

$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tablas::nombre( 'marcas' ) . " SET marcado_utc = DATE_SUB(marcado_utc, INTERVAL 30 MINUTE) WHERE id = %d", 1 ) ); // phpcs:ignore
$comprobar( 'si alguien edita una marca en la base, la cadena lo detecta', 1 === InsercionEncadenada::verificar( 'marcas' ) );

$comprobar( 'presencia por IP del taller', ( $_SERVER['REMOTE_ADDR'] = '203.0.113.10' ) && Presencia::enRedDelTaller() );

if ( $fallos ) {
	WP_CLI::error( $fallos . ' comprobaciones fallaron.' );
}

WP_CLI::success( 'Todo el flujo funciona.' );
