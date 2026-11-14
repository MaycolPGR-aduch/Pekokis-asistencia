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
foreach ( array( 'trabajadores', 'marcas', 'auditoria', 'credenciales', 'dispositivos', 'activaciones', 'redes', 'correcciones', 'pantallas', 'revisiones' ) as $tabla ) {
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

// Cambio de celular: un enlace nuevo y otra llave. La anterior deja de servir sola.
$celularViejo = $celular;
$celular      = new AutenticadorSimulado( Passkeys::rpId(), Passkeys::origen() );
$tokenNuevo   = substr( Activaciones::generar( $id ), -32 );
[ , $op ]     = $api( 'POST', 'activacion/opciones', array( 'token' => $tokenNuevo ) );
[ $st ]       = $api( 'POST', 'activacion/completar', array( 'token' => $tokenNuevo, 'reto' => $op['reto'], 'credencial' => $celular->crear( $op['publicKey']['challenge'] ) ) );
$comprobar( 'cambio de celular: se activa la llave nueva', 200 === $st );
[ , $op ] = $api( 'POST', 'identificar/opciones' );
[ $st ]   = $api( 'POST', 'identificar', array( 'reto' => $op['reto'], 'credencial' => $celularViejo->firmar( $op['publicKey']['challenge'] ) ) );
$comprobar( 'la llave del celular anterior ya no entra', 401 === $st, (string) $st );
$comprobar( 'queda una sola llave activa', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'credenciales' ) . ' WHERE trabajador_id = %d AND revocada_en IS NULL', $id ) ) ); // phpcs:ignore

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

// ---- 3b. Pantalla QR (fuera del WiFi).
WP_CLI::line( '3b. Pantalla QR' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$cookiesCelular         = $_COOKIE;
$_COOKIE                = array(); // La tablet es otro navegador.
[ $st ] = $api( 'GET', 'pantalla/codigo' );
$comprobar( 'un equipo sin activar no recibe códigos', 401 === $st );
$pantalla = \Pekokis\Asistencia\WordPress\Pantallas::activarEsteEquipo( 'Entrada' );
[ $st, $cod ] = $api( 'GET', 'pantalla/codigo' );
$comprobar( 'la pantalla recibe token, código de 6 dígitos y QR', 200 === $st && preg_match( '/^\d{6}$/', $cod['codigo'] ) && str_starts_with( (string) $cod['qr'], 'data:image/svg+xml' ), wp_json_encode( array_diff_key( $cod, array( 'qr' => 1 ) ) ) );
$peticion = new WP_REST_Request( 'GET', '/asistencia/v1/pantalla/codigo' );
$peticion->set_param( 'actual', $cod['token'] );
$comprobar( 'si el token no cambió, no reenvía el QR', null === rest_do_request( $peticion )->get_data()['qr'] );
$_COOKIE = $cookiesCelular; // Vuelve el celular.

[ , $id5 ] = $identificar();
[ $st ] = $api( 'POST', 'presencia/codigo', array( 'codigo' => '000000' === $cod['codigo'] ? '111111' : '000000' ) );
$comprobar( 'código equivocado se rechaza', 422 === $st );
[ $st, $r ] = $api( 'POST', 'presencia/codigo', array( 'token' => $cod['token'] ) );
$comprobar( 'el QR escaneado deja un pase de presencia', 200 === $st && '' !== ( $_COOKIE[ Presencia::COOKIE_PASE ] ?? '' ), wp_json_encode( $r ) );
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id5['ticket'], 'accion' => 'regreso' ) );
$comprobar( 'regreso del refrigerio por QR, sin WiFi', 200 === $st && str_ends_with( $r['mensaje'], 'QR' ), wp_json_encode( $r ) );

unset( $_COOKIE[ Presencia::COOKIE_PASE ] );
[ , $id6 ] = $identificar();
[ $st ] = $api( 'POST', 'presencia/codigo', array( 'codigo' => $cod['codigo'] ) );
$comprobar( 'el código de 6 dígitos también sirve', 200 === $st );
\Pekokis\Asistencia\WordPress\Pantallas::cambiarEstado( $pantalla, false );
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id6['ticket'], 'accion' => 'salida_temporal', 'motivo' => 'personal' ) );
$comprobar( 'si se desactiva la pantalla, su pase deja de valer', 422 === $st && 'pka_sin_presencia' === $r['code'], wp_json_encode( $r ) );
\Pekokis\Asistencia\WordPress\Pantallas::cambiarEstado( $pantalla, true );
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $id6['ticket'], 'accion' => 'salida_temporal', 'motivo' => 'personal' ) );
$comprobar( 'salida personal por QR, queda para aprobación', 200 === $st && true === $r['revision'] );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

// ---- 3c. Revisión del jefe.
WP_CLI::line( '3c. Revisión' );
$pendientes = \Pekokis\Asistencia\WordPress\Revisiones::pendientes( null );
$comprobar( 'hay 2 marcas por revisar (emergencia y personal)', 2 === count( $pendientes ), (string) count( $pendientes ) );
$comprobar( 'un jefe sin equipo no ve nada', array() === \Pekokis\Asistencia\WordPress\Revisiones::pendientes( array() ) );
$comprobar( 'rechazar sin nota no se permite', is_wp_error( \Pekokis\Asistencia\WordPress\Revisiones::resolver( (int) $pendientes[0]['id'], 'rechazada', '' ) ) );
$comprobar( 'aprobar', true === \Pekokis\Asistencia\WordPress\Revisiones::resolver( (int) $pendientes[0]['id'], 'aprobada', 'Avisó por WhatsApp' ) );
$comprobar( 'no se revisa dos veces', is_wp_error( \Pekokis\Asistencia\WordPress\Revisiones::resolver( (int) $pendientes[0]['id'], 'aprobada', '' ) ) );
$comprobar( 'queda 1 pendiente', 1 === count( \Pekokis\Asistencia\WordPress\Revisiones::pendientes( null ) ) );

$hoy     = \Pekokis\Asistencia\WordPress\Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
$resumen = \Pekokis\Asistencia\Nucleo\ResumenDia::calcular(
	\Pekokis\Asistencia\WordPress\Marcas::delDia( $id, $hoy ),
	\Pekokis\Asistencia\WordPress\Horarios::delDia( $horario, $hoy ),
	$hoy,
	\Pekokis\Asistencia\WordPress\Ajustes::zona(),
	new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
);
$comprobar( 'el panel del día la ve fuera por motivo personal', 'fuera' === $resumen->situacion && 'personal' === $resumen->motivoActual?->clave, $resumen->situacion );

// ---- 3d. Correcciones: "olvidé marcar", marca manual y anulación.
WP_CLI::line( '3d. Correcciones' );
$zonaLima = \Pekokis\Asistencia\WordPress\Ajustes::zona();
$ayer     = ( new DateTimeImmutable( $hoy ) )->modify( '-1 day' )->format( 'Y-m-d' );
// El perfil "existe" desde hace 10 días, para que haya días anteriores que revisar.
$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'creado_en' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );

[ , $id7 ] = $identificar();
[ $st, $r ] = $api( 'POST', 'correcciones', array( 'ticket' => $id7['ticket'], 'fecha' => $ayer, 'hora' => '25:00', 'accion' => 'entrada', 'justificacion' => 'x' ) );
$comprobar( 'hora inválida se rechaza', 422 === $st, wp_json_encode( $r ) );
[ $st, $r ] = $api( 'POST', 'correcciones', array( 'ticket' => $id7['ticket'], 'fecha' => $ayer, 'hora' => '09:00', 'accion' => 'entrada', 'justificacion' => 'Se me descargó el celular' ) );
$comprobar( 'el trabajador pide agregar la entrada de ayer', 200 === $st, wp_json_encode( $r ) );
$sol = \Pekokis\Asistencia\WordPress\Correcciones::pendientes( null );
$comprobar( 'queda pendiente para el jefe', 1 === count( $sol ) );
$comprobar( 'rechazar sin nota no se permite', is_wp_error( \Pekokis\Asistencia\WordPress\Correcciones::rechazar( (int) $sol[0]['id'], '' ) ) );
$comprobar( 'el jefe la aprueba', true === \Pekokis\Asistencia\WordPress\Correcciones::aprobar( (int) $sol[0]['id'], 'Ok' ) );

$tipos = static fn( string $d ) => array_column( array_filter( \Pekokis\Asistencia\WordPress\Seguimiento::pendientes( null, $hoy ), static fn( $p ) => $p['dia'] === $d && (int) $p['trabajador']['id'] === $id ), 'tipo' );
$comprobar( 'ayer aparece "sin marca de salida"', array( 'incompleto' ) === $tipos( $ayer ), wp_json_encode( $tipos( $ayer ) ) );
[ , $id8 ] = $identificar();
$comprobar( 'la app avisa que ayer no marcó salida', isset( $id8['ayer']['fecha'] ) && $ayer === $id8['ayer']['fecha'] );

[ $st ] = $api( 'POST', 'correcciones', array( 'ticket' => $id8['ticket'], 'fecha' => $ayer, 'hora' => '10:00', 'accion' => 'entrada', 'justificacion' => 'Otra entrada' ) );
$mal = \Pekokis\Asistencia\WordPress\Correcciones::pendientes( null );
$res = \Pekokis\Asistencia\WordPress\Correcciones::aprobar( (int) $mal[0]['id'], '' );
$comprobar( 'no se aprueba una segunda entrada seguida', is_wp_error( $res ) && str_contains( $res->get_error_message(), 'dos entradas seguidas' ), is_wp_error( $res ) ? $res->get_error_message() : 'se aprobó' );
$comprobar( 'se rechaza con nota', true === \Pekokis\Asistencia\WordPress\Correcciones::rechazar( (int) $mal[0]['id'], 'Ya tenía entrada' ) );

$fin = \Pekokis\Asistencia\WordPress\Correcciones::marcaManual( $id, $ayer, '19:30', \Pekokis\Asistencia\Nucleo\Accion::FIN_JORNADA, null, 'Olvidó marcar la salida' );
$comprobar( 'el jefe registra la salida manual', is_int( $fin ), is_wp_error( $fin ) ? $fin->get_error_message() : '' );
$comprobar( 'ayer ya no está pendiente', array() === $tipos( $ayer ) );
$doble = \Pekokis\Asistencia\WordPress\Correcciones::marcaManual( $id, $ayer, '13:00', \Pekokis\Asistencia\Nucleo\Accion::SALIDA_REFRIGERIO, null, 'Refrigerio' );
$comprobar( 'una salida que deja dos salidas seguidas se rechaza', is_wp_error( $doble ) );
$futuro = \Pekokis\Asistencia\WordPress\Correcciones::marcaManual( $id, ( new DateTimeImmutable( $hoy ) )->modify( '+1 day' )->format( 'Y-m-d' ), '09:00', \Pekokis\Asistencia\Nucleo\Accion::ENTRADA, null, 'x' );
$comprobar( 'no se registran horas futuras', is_wp_error( $futuro ) );

$entradaAyer = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Tablas::nombre( 'marcas' ) . " WHERE trabajador_id = %d AND dia_laboral = %s AND tipo = 'entrada'", $id, $ayer ) ); // phpcs:ignore
$comprobar( 'anular la entrada dejaría la salida sola: se rechaza', is_wp_error( \Pekokis\Asistencia\WordPress\Correcciones::anular( $entradaAyer, 'Error' ) ) );
$comprobar( 'anular la salida manual sí se puede', true === \Pekokis\Asistencia\WordPress\Correcciones::anular( $fin, 'Hora equivocada' ) );
$comprobar( 'la marca anulada sigue en la tabla', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'marcas' ) . ' WHERE id = %d', $fin ) ) ); // phpcs:ignore
$comprobar( 'y ayer vuelve a estar sin salida', array( 'incompleto' ) === $tipos( $ayer ) );
$fin2 = \Pekokis\Asistencia\WordPress\Correcciones::marcaManual( $id, $ayer, '19:35', \Pekokis\Asistencia\Nucleo\Accion::FIN_JORNADA, null, 'Hora correcta' );
$comprobar( 'se registra la salida con la hora correcta', is_int( $fin2 ) );

// ---- 3d-bis. "Mis marcas del mes".
[ , $id9 ] = $identificar();
[ $st, $mes ] = $api( 'POST', 'yo/mes', array( 'ticket' => $id9['ticket'], 'mes' => substr( $ayer, 0, 7 ) ) );
$diaAyer      = array_values( array_filter( $mes['dias'] ?? array(), static fn( $d ) => $ayer === $d['fecha'] ) )[0] ?? array();
$comprobar( 'mis marcas: ayer con ingreso 09:00, salida 19:35 y sin tardanza', 200 === $st && '09:00' === ( $diaAyer['ingreso'] ?? '' ) && '19:35' === ( $diaAyer['salida'] ?? '' ) && 0 === ( $diaAyer['tardanza'] ?? -1 ), wp_json_encode( $diaAyer ) );
$comprobar( 'mis marcas: 10 h 35 min en el taller ayer', '10:35' === ( $diaAyer['tiempo'] ?? '' ), (string) ( $diaAyer['tiempo'] ?? '' ) );
$comprobar( 'mis marcas no gasta el ticket', null !== \Pekokis\Asistencia\WordPress\Temporales::leer( 'ticket', $id9['ticket'] ) );
[ $st, $viejo ] = $api( 'POST', 'yo/mes', array( 'ticket' => $id9['ticket'], 'mes' => '2020-01' ) );
$comprobar( 'un mes muy antiguo devuelve el mes actual', 200 === $st && substr( $hoy, 0, 7 ) === $viejo['mes'], (string) ( $viejo['mes'] ?? '' ) );

// ---- 3e. Export y respaldo.
WP_CLI::line( '3e. Export y respaldo' );
$mesAyer = substr( $ayer, 0, 7 );
$xls     = \Pekokis\Asistencia\WordPress\Exportes::registroMensual( $mesAyer, 'Prueba' );
$comprobar( 'el Excel se genera', str_starts_with( $xls['contenido'], 'PK' ) && 64 === strlen( $xls['hash'] ) );

if ( class_exists( 'ZipArchive' ) ) {
	$tmp = wp_tempnam( 'pka.xlsx' );
	file_put_contents( $tmp, $xls['contenido'] ); // phpcs:ignore
	$zip = new ZipArchive();
	$zip->open( $tmp );
	$textos = (string) $zip->getFromName( 'xl/sharedStrings.xml' );
	for ( $i = 1; $i <= 3; $i++ ) {
		$textos .= (string) $zip->getFromName( 'xl/worksheets/sheet' . $i . '.xml' );
	}
	$zip->close();
	wp_delete_file( $tmp );
	$comprobar( 'trae empleador, RUC, la marca anulada y la integridad', str_contains( $textos, 'FUSION ART SAC' ) && str_contains( $textos, '20523257146' ) && str_contains( $textos, 'Anulada: Hora equivocada' ) && str_contains( $textos, 'Íntegra' ) );
	$comprobar( 'el registro de ayer muestra ingreso 09:00 y salida 19:35', str_contains( $textos, '09:00' ) && str_contains( $textos, '19:35' ) );
}

$correo = null;
add_filter(
	'pre_wp_mail',
	static function ( $nulo, $atts ) use ( &$correo ) {
		$correo = array( 'para' => $atts['to'], 'asunto' => $atts['subject'], 'adjunto' => file_exists( $atts['attachments'][0] ) ? filesize( $atts['attachments'][0] ) : 0 );
		return true;
	},
	10,
	2
);
$envio = \Pekokis\Asistencia\WordPress\Respaldo::enviar( $mesAyer, 'Prueba' );
$comprobar( 'el respaldo se envía por correo con el Excel adjunto', true === $envio && $correo && $correo['adjunto'] > 1000, wp_json_encode( $correo ) );
$comprobar( 'y el archivo temporal se borra', ! glob( wp_upload_dir()['basedir'] . '/pka-respaldos/*.xlsx' ) );

// ---- 4. Cese.
WP_CLI::line( '4. Cese' );
$comprobar( 'cese sin motivo se rechaza', is_wp_error( Trabajadores::cesar( $id, gmdate( 'Y-m-d' ), '' ) ) );
$comprobar( 'cese con motivo', true === Trabajadores::cesar( $id, gmdate( 'Y-m-d' ), 'Fin de contrato' ) );
[ $st ] = $identificar();
$comprobar( 'ya no puede identificarse', 401 === $st, (string) $st );

// ---- 5. Integridad.
WP_CLI::line( '5. Integridad' );
$marcas = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'marcas' ) ); // phpcs:ignore
$comprobar( '9 marcas guardadas (6 de hoy, 3 de ayer con una anulada)', 9 === $marcas, (string) $marcas );
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
