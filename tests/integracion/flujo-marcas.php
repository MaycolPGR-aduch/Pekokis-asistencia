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
foreach ( array( 'trabajadores', 'marcas', 'auditoria', 'credenciales', 'dispositivos', 'activaciones', 'redes', 'correcciones', 'pantallas', 'revisiones', 'calendario', 'ausencias', 'sueldos', 'sobretiempo' ) as $tabla ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Tablas::nombre( $tabla ) ); // phpcs:ignore
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pka\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pka\\_%'" ); // phpcs:ignore
$_COOKIE = array();
$ajustesPrueba = get_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array() );
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'aviso_texto' => '', 'aviso_version' => 1, 'webhook_respaldo' => '' ) + ( is_array( $ajustesPrueba ) ? $ajustesPrueba : array() ) );

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

$comprobar( 'las opciones traen el aviso de datos con el empleador', str_contains( $op['aviso']['texto'] ?? '', 'FUSION ART SAC' ) && 1 === ( $op['aviso']['version'] ?? 0 ) );
[ $st, $r ] = $api( 'POST', 'activacion/completar', array( 'token' => $token, 'reto' => $op['reto'], 'credencial' => $celular->crear( $op['publicKey']['challenge'] ) ) );
$comprobar( 'sin aceptar el aviso no se activa', 422 === $st && 'pka_aviso' === $r['code'], wp_json_encode( $r ) );
[ , $op ] = $api( 'POST', 'activacion/opciones', array( 'token' => $token ) );
[ $st, $r ] = $api( 'POST', 'activacion/completar', array( 'token' => $token, 'reto' => $op['reto'], 'acepta_aviso' => $op['aviso']['version'], 'credencial' => $celular->crear( $op['publicKey']['challenge'] ) ) );
$comprobar( 'passkey registrada', 200 === $st && 'Ana' === $r['nombre'], wp_json_encode( $r ) );
$comprobar( 'queda registrado que aceptó el aviso', 1 === (int) Trabajadores::buscar( $id )['aviso_version'] && null !== Trabajadores::buscar( $id )['aviso_aceptado_en'] );
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
[ $st ]       = $api( 'POST', 'activacion/completar', array( 'token' => $tokenNuevo, 'reto' => $op['reto'], 'acepta_aviso' => $op['aviso']['version'], 'credencial' => $celular->crear( $op['publicKey']['challenge'] ) ) );
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

// ---- 3c-bis. Aviso de datos: si cambia el texto, se acepta de nuevo.
WP_CLI::line( '3c-bis. Aviso de datos' );
[ , $idAv ] = $identificar();
$comprobar( 'con el aviso al día no se pide nada', null === $idAv['aviso'] );
\Pekokis\Asistencia\WordPress\Aviso::guardar( \Pekokis\Asistencia\WordPress\Aviso::borrador() . "\n\nCambio de prueba." );
[ , $idAv ] = $identificar();
$comprobar( 'después de cambiar el texto se pide aceptar la versión 2', 2 === ( $idAv['aviso']['version'] ?? 0 ) && str_contains( $idAv['aviso']['texto'], 'Cambio de prueba' ) );
[ $st ] = $api( 'POST', 'aviso/aceptar', array( 'ticket' => $idAv['ticket'], 'version' => 1 ) );
$comprobar( 'aceptar una versión vieja se rechaza', 409 === $st );
[ $st, $r ] = $api( 'POST', 'aviso/aceptar', array( 'ticket' => $idAv['ticket'], 'version' => 2 ) );
$comprobar( 'aceptar la versión nueva devuelve el estado para seguir', 200 === $st && isset( $r['acciones'] ) );
[ , $idAv ] = $identificar();
$comprobar( 'ya no se vuelve a pedir', null === $idAv['aviso'] );
[ $st, $av ] = $api( 'GET', 'aviso' );
$comprobar( 'el aviso público responde con la versión vigente', 200 === $st && 2 === $av['version'] );

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
$cal    = $viejo['calendario'] ?? array();
$diaHoy = array_values( array_filter( $cal['dias'] ?? array(), static fn( $d ) => $hoy === $d['fecha'] ) )[0] ?? array();
$comprobar( 'mis marcas trae el calendario del mes, con hoy marcado', (int) ( new DateTimeImmutable( $hoy ) )->format( 't' ) === count( $cal['dias'] ?? array() ) && 'hoy' === ( $diaHoy['estado'] ?? '' ) && (int) ( new DateTimeImmutable( substr( $hoy, 0, 7 ) . '-01' ) )->format( 'N' ) - 1 === ( $cal['vacios'] ?? -1 ), wp_json_encode( $diaHoy ) );

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
	for ( $i = 1; $i <= 4; $i++ ) {
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
$comprobar( 'el respaldo se envía por correo con el Excel adjunto', true === $envio['correo'] && null === $envio['nube'] && $correo && $correo['adjunto'] > 1000, wp_json_encode( $correo ) );

$llamada = null;
add_filter(
	'pre_http_request',
	static function ( $nulo, $args, $url ) use ( &$llamada ) {
		$llamada = array( 'url' => $url, 'firma' => $args['headers']['X-PKA-Firma'] ?? '', 'cuerpo' => $args['body'] );
		return array( 'headers' => array(), 'body' => 'Accepted', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	},
	10,
	3
);
$aj = \Pekokis\Asistencia\WordPress\Ajustes::todos();
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'webhook_respaldo' => 'https://hook.example.test/respaldo' ) + $aj );
$envio = \Pekokis\Asistencia\WordPress\Respaldo::enviar( $mesAyer, 'Prueba' );
$comprobar(
	'con webhook, el Excel también se envía a la nube firmado',
	true === $envio['nube'] && 'https://hook.example.test/respaldo' === $llamada['url'] && 64 === strlen( $llamada['firma'] )
		&& str_contains( $llamada['cuerpo'], 'filename="registro-asistencia-' . $mesAyer . '.xlsx"' ) && str_contains( $llamada['cuerpo'], 'name="hash"' ),
	wp_json_encode( array( 'envio' => $envio, 'url' => $llamada['url'] ?? '' ) )
);
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'webhook_respaldo' => '' ) + $aj );
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
$comprobar( 'auditoría registra alta, edición, enlace, passkey y cese', array( 'trabajador.crear', 'trabajador.editar', 'activacion.generar', 'aviso.aceptar', 'passkey.registrar' ) === array_slice( $acciones, 0, 5 ) && in_array( 'trabajador.cesar', $acciones, true ), implode( ', ', $acciones ) );

$wpdb->query( $wpdb->prepare( 'UPDATE ' . Tablas::nombre( 'marcas' ) . " SET marcado_utc = DATE_SUB(marcado_utc, INTERVAL 30 MINUTE) WHERE id = %d", 1 ) ); // phpcs:ignore
$comprobar( 'si alguien edita una marca en la base, la cadena lo detecta', 1 === InsercionEncadenada::verificar( 'marcas' ) );

// ---- 6. Historial (visor de auditoría).
WP_CLI::line( '6. Historial' );
$H = \Pekokis\Asistencia\WordPress\Historial::class;
[ $deAna, $totalAna ] = $H::buscar( array( 'trabajador' => $id ), 1, 500 );
$accionesAna          = array_column( $deAna, 'accion' );
$comprobar( 'por trabajador: perfil, celular, marcas, solicitudes y cese', ! array_diff( array( 'trabajador.crear', 'passkey.registrar', 'marca.manual', 'marca.anular', 'correccion.solicitar', 'correccion.aprobar', 'trabajador.cesar' ), $accionesAna ), implode( ', ', array_unique( $accionesAna ) ) );
[ , $totalLuis ] = $H::buscar( array( 'trabajador' => $id2 ) );
$comprobar( 'no mezcla trabajadores', $totalLuis > 0 && $totalLuis < $totalAna, $totalLuis . ' / ' . $totalAna );

$legible = $H::describir( $deAna );
$porAccion = array();
foreach ( $legible as $i => $item ) {
	$porAccion[ $deAna[ $i ]['accion'] ][] = $item;
}
$comprobar( 'acciones en español', 'Marca anulada' === $porAccion['marca.anular'][0]['accion'] && 'Cese' === $porAccion['trabajador.cesar'][0]['accion'] );
$comprobar( 'la solicitud la hizo Ana desde su celular', str_contains( $porAccion['correccion.solicitar'][0]['quien'], '(desde su celular)' ), $porAccion['correccion.solicitar'][0]['quien'] );
$comprobar( 'la marca manual muestra la hora en Lima y el motivo', (bool) array_filter( $porAccion['marca.manual'], static fn( $x ) => in_array( 'Motivo: Fin de jornada', $x['cambios'], true ) && (bool) preg_grep( '/^Hora: \d\d\/\d\d 19:3[05]$/', $x['cambios'] ) ), wp_json_encode( $porAccion['marca.manual'][0]['cambios'] ) );
$comprobar( 'la edición muestra antes → después', in_array( 'Apellidos: Quispe → Quispe Rojas', $porAccion['trabajador.editar'][0]['cambios'], true ), wp_json_encode( $porAccion['trabajador.editar'][0]['cambios'] ) );
$comprobar( 'enlaza la marca con el día del trabajador', str_contains( $porAccion['marca.anular'][0]['url'], 'vista=dia' ) );

[ , $deAjustes ] = $H::buscar( array( 'grupo' => 'ajustes' ) );
$comprobar( 'filtro por tipo (pantallas y redes)', $deAjustes >= 2, (string) $deAjustes );
$manana = ( new DateTimeImmutable( $hoy ) )->modify( '+1 day' )->format( 'Y-m-d' );
[ , $futuro ] = $H::buscar( array( 'desde' => $manana ) );
$comprobar( 'filtro por fecha', 0 === $futuro );
[ $conTexto ] = $H::buscar( array( 'texto' => 'Hora equivocada' ) );
$comprobar( 'búsqueda en motivos', 1 === count( $conTexto ) && 'marca.anular' === $conTexto[0]['accion'] );
[ $pag, $totalPag ] = $H::buscar( array(), 2, 3 );
$comprobar( 'paginación', 3 === count( $pag ) && $totalPag > 6 );

// ---- 7. IP nueva del taller.
WP_CLI::line( '7. IP nueva' );
$comprobar( 'con marcas por QR de una sola persona no se sugiere nada', array() === Presencia::redesCandidatas(), wp_json_encode( Presencia::redesCandidatas() ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7'; // La misma red desde la que Ana marcó por QR.
foreach ( array( 'entrada', 'salida' ) as $tipo ) {
	\Pekokis\Asistencia\WordPress\Marcas::registrar(
		array(
			'trabajador_id' => $id2, 'tipo' => \Pekokis\Asistencia\Nucleo\TipoMarca::from( $tipo ), 'motivo_clave' => 'salida' === $tipo ? 'refrigerio' : null, 'nota' => '',
			'marcado' => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ), 'prueba' => \Pekokis\Asistencia\Nucleo\PruebaPresencia::QR, 'requiere_revision' => false,
			'dispositivo_id' => null, 'credencial_id' => null, 'pantalla_id' => $pantalla, 'origen' => 'trabajador',
		)
	);
}
$candidatas = Presencia::redesCandidatas();
$comprobar( 'con 4 marcas por QR de 2 personas desde la misma red, se sugiere', 1 === count( $candidatas ) && '198.51.100.7' === $candidatas[0]['red'] && 2 === $candidatas[0]['trabajadores'], wp_json_encode( $candidatas ) );
$wpdb->insert( Tablas::nombre( 'redes' ), array( 'red' => '198.51.100.7', 'nombre' => 'IP nueva', 'activa' => 1, 'creada_por' => 1, 'creado_en' => gmdate( 'Y-m-d H:i:s' ) ) );
$comprobar( 'una vez autorizada, deja de sugerirse', array() === Presencia::redesCandidatas() );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

// ---- 8. Horarios.
WP_CLI::line( '8. Horarios' );
$H2   = \Pekokis\Asistencia\WordPress\Horarios::class;
$dias = array();
for ( $n = 1; $n <= 7; $n++ ) {
	$dias[ $n ] = 6 === $n ? array( 'laborable' => '1', 'entrada' => '13:00', 'salida' => '09:00' ) : array();
}
$mal = $H2::guardar( array( 'nombre' => 'Sábados', 'tolerancia_min' => 5, 'refrigerio_min' => 0, 'dias' => $dias ), null );
$comprobar( 'horario con salida antes de la entrada se rechaza', is_wp_error( $mal ) && isset( $mal->get_error_data()['errores']['dia_6'] ) );
$dias[6] = array( 'laborable' => '1', 'entrada' => '09:00', 'salida' => '13:00' );
$sab     = $H2::guardar( array( 'nombre' => 'Sábados', 'tolerancia_min' => 5, 'refrigerio_min' => 0, 'dias' => $dias ), null );
$comprobar( 'se crea un horario de sábados', is_int( $sab ) );
$sabado = ( new DateTimeImmutable( 'next saturday', $zonaLima ) )->format( 'Y-m-d' );
$comprobar( 'el sábado es laborable de 9 a 13', $H2::delDia( $sab, $sabado )->laborable && '09:00:00' === $H2::delDia( $sab, $sabado )->entrada );
$dias[6] = array( 'laborable' => '1', 'entrada' => '08:00', 'salida' => '13:00' );
$sinMotivo = $H2::guardar( array( 'nombre' => 'Sábados', 'tolerancia_min' => 10, 'refrigerio_min' => 0, 'dias' => $dias ), $sab );
$comprobar( 'editar exige motivo', is_wp_error( $sinMotivo ) );
$comprobar( 'se edita con motivo', $sab === $H2::guardar( array( 'nombre' => 'Sábados', 'tolerancia_min' => 10, 'refrigerio_min' => 0, 'dias' => $dias ), $sab, 'Entran más temprano' ) );
$comprobar( 'el cambio se aplica al momento', '08:00:00' === $H2::delDia( $sab, $sabado )->entrada );
[ $deHorario ] = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'grupo' => 'ajustes', 'texto' => 'Entran más temprano' ) );
$cambiosHorario = \Pekokis\Asistencia\WordPress\Historial::describir( $deHorario )[0]['cambios'] ?? array();
$comprobar( 'el historial muestra el cambio legible', in_array( 'Sábado: 09:00–13:00 → 08:00–13:00', $cambiosHorario, true ) && in_array( 'Tolerancia (min): 5 → 10', $cambiosHorario, true ), wp_json_encode( $cambiosHorario ) );
$comprobar( 'no se desactiva un horario en uso', is_wp_error( $H2::cambiarEstado( $horario, false ) ) );
$comprobar( 'sí se desactiva uno sin trabajadores', true === $H2::cambiarEstado( $sab, false ) );
// Limpieza: la tabla de horarios no se vacía al empezar, así que no se deja el de prueba.
$wpdb->delete( Tablas::nombre( 'horario_dias' ), array( 'horario_id' => $sab ) );
$wpdb->delete( Tablas::nombre( 'horarios' ), array( 'id' => $sab ) );

// ---- 9. Feriados y ausencias.
WP_CLI::line( '9. Feriados y ausencias' );
$Cal = \Pekokis\Asistencia\WordPress\Calendario::class;
$Aus = \Pekokis\Asistencia\WordPress\Ausencias::class;
$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'creado_en' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ), array( 'id' => $id2 ) );
$faltasLuis = static fn() => array_values( array_map( static fn( $p ) => $p['dia'], array_filter( \Pekokis\Asistencia\WordPress\Seguimiento::pendientes( null, $hoy ), static fn( $p ) => (int) $p['trabajador']['id'] === $id2 && 'falta' === $p['tipo'] ) ) );
$antes = $faltasLuis();
$comprobar( 'Luis tiene días laborables sin marcas', count( $antes ) >= 2, wp_json_encode( $antes ) );
[ $diaFeriado, $diaVacaciones ] = array( $antes[0], $antes[1] );

$comprobar( 'se agrega un feriado', true === $Cal::agregar( $diaFeriado, 'Feriado de prueba', 'feriado' ) );
$comprobar( 'no se repite la fecha', is_wp_error( $Cal::agregar( $diaFeriado, 'Otro', 'feriado' ) ) );
$comprobar( 'el feriado deja de contar como falta', ! in_array( $diaFeriado, $faltasLuis(), true ) );
$comprobar( 'el horario de ese día no es laborable y dice por qué', ! \Pekokis\Asistencia\WordPress\Horarios::delDia( $horario, $diaFeriado )->laborable && 'Feriado: Feriado de prueba' === \Pekokis\Asistencia\WordPress\Horarios::delDia( $horario, $diaFeriado )->descanso );

$vac = $Aus::crear( array( 'trabajador_id' => $id2, 'tipo' => 'vacaciones', 'desde' => $diaVacaciones, 'hasta' => $diaVacaciones, 'nota' => 'Papeleta 12' ) );
$comprobar( 'se registran vacaciones', is_int( $vac ) );
$comprobar( 'no se cruzan dos ausencias', is_wp_error( $Aus::crear( array( 'trabajador_id' => $id2, 'tipo' => 'permiso', 'desde' => $diaVacaciones, 'hasta' => $diaVacaciones ) ) ) );
$comprobar( 'las vacaciones dejan de contar como falta', ! in_array( $diaVacaciones, $faltasLuis(), true ) && 'Vacaciones' === $Aus::delDia( $id2, $diaVacaciones ) );
$resLuis = \Pekokis\Asistencia\Nucleo\ResumenDia::calcular( array(), \Pekokis\Asistencia\WordPress\Horarios::delDia( $horario, $diaVacaciones ), $diaVacaciones, $zonaLima, new DateTimeImmutable( $diaVacaciones . ' 23:00', $zonaLima ), $Aus::delDia( $id2, $diaVacaciones ) );
$comprobar( 'el panel lo muestra como ausencia justificada', 'justificado' === $resLuis->situacion && 'Vacaciones' === $resLuis->detalle );

$xlsAus = \Pekokis\Asistencia\WordPress\Exportes::registroMensual( substr( $diaVacaciones, 0, 7 ), 'Prueba' );
if ( class_exists( 'ZipArchive' ) ) {
	$tmp = wp_tempnam( 'pka2.xlsx' );
	file_put_contents( $tmp, $xlsAus['contenido'] ); // phpcs:ignore
	$zip = new ZipArchive();
	$zip->open( $tmp );
	$textos = (string) $zip->getFromName( 'xl/sharedStrings.xml' ) . (string) $zip->getFromName( 'xl/worksheets/sheet3.xml' );
	$zip->close();
	wp_delete_file( $tmp );
	$comprobar( 'el Excel trae la hoja de ausencias y feriados', str_contains( $textos, 'Papeleta 12' ) && str_contains( $textos, 'Feriado de prueba' ) );
}

$comprobar( 'anular sin motivo no se permite', is_wp_error( $Aus::anular( $vac, '' ) ) );
$comprobar( 'al anular las vacaciones vuelve a figurar la falta', true === $Aus::anular( $vac, 'Se canceló' ) && in_array( $diaVacaciones, $faltasLuis(), true ) );
$comprobar( 'al quitar el feriado vuelve a figurar la falta', true === $Cal::quitar( $diaFeriado ) && in_array( $diaFeriado, $faltasLuis(), true ) );
$comprobar( 'carga los 16 feriados nacionales sugeridos de un año', 16 === $Cal::cargarFeriadosPeru( 2030 ) && 0 === $Cal::cargarFeriadosPeru( 2030 ) );
[ $histAus ] = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'trabajador' => $id2, 'texto' => 'Se canceló' ) );
$comprobar( 'el historial del trabajador incluye sus ausencias', 1 === count( $histAus ) && 'ausencia.anular' === $histAus[0]['accion'] );

// ---- 10. Gerencia y "nadie se aprueba a sí mismo".
WP_CLI::line( '10. Gerencia y permisos' );
$gerente = wp_insert_user( array( 'user_login' => 'gerente_prueba_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'role' => 'pka_gerencia' ) );
$jefe    = wp_insert_user( array( 'user_login' => 'jefe_prueba_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'role' => 'pka_jefe' ) );
$C       = \Pekokis\Asistencia\WordPress\Capacidades::class;
$comprobar( 'gerencia ve todo, el historial y descarga', user_can( $gerente, $C::VER_TODO ) && user_can( $gerente, $C::AUDITORIA ) && user_can( $gerente, $C::EXPORTAR ) );
$comprobar( 'gerencia no aprueba, no corrige ni gestiona personal', ! user_can( $gerente, $C::APROBAR ) && ! user_can( $gerente, $C::MARCA_MANUAL ) && ! user_can( $gerente, $C::GESTIONAR_PERSONAL ) && ! user_can( $gerente, $C::AJUSTES ) );
$datosLuis = Trabajadores::buscar( $id2 );
$mismo     = Trabajadores::guardar( array( 'user_id' => $jefe, 'jefe_user_id' => $jefe ) + $datosLuis, $id2, 'Prueba' );
$comprobar( 'nadie puede ser su propio jefe', is_wp_error( $mismo ) && isset( $mismo->get_error_data()['errores']['jefe_user_id'] ) );
$comprobar( 'se enlaza el perfil con su usuario de wp-admin', $id2 === Trabajadores::guardar( array( 'user_id' => $jefe, 'jefe_user_id' => 1 ) + $datosLuis, $id2, 'Luis también es jefe' ) );
$otro = Trabajadores::guardar( array( 'dni' => '47474747', 'nombres' => 'Eva', 'apellidos' => 'Ruiz', 'horario_id' => $horario, 'user_id' => $jefe ) );
$comprobar( 'un usuario solo se enlaza con un trabajador', is_wp_error( $otro ) && isset( $otro->get_error_data()['errores']['user_id'] ) );
wp_set_current_user( $jefe );
$comprobar( 'para Luis, su propio perfil es "propio"', Trabajadores::esPropio( $id2 ) && ! Trabajadores::esPropio( $id ) );
wp_set_current_user( 1 );
$comprobar( 'para el administrador no lo es', ! Trabajadores::esPropio( $id2 ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $gerente );
wp_delete_user( $jefe );

// ---- 11. Horas extra, sueldos y pre-planilla (fase 2).
WP_CLI::line( '11. Horas extra, sueldos y pre-planilla' );
$J  = \Pekokis\Asistencia\WordPress\Jornadas::class;
$ST = \Pekokis\Asistencia\WordPress\Sobretiempo::class;
$SU = \Pekokis\Asistencia\WordPress\Sueldos::class;
$A  = \Pekokis\Asistencia\Nucleo\Accion::class;
$diaExtra = $antes[0];
$CM = \Pekokis\Asistencia\WordPress\Correcciones::class;
$CM::marcaManual( $id2, $diaExtra, '09:20', $A::ENTRADA, null, 'Prueba fase 2' );
$CM::marcaManual( $id2, $diaExtra, '13:00', $A::SALIDA_REFRIGERIO, 'refrigerio', 'Prueba fase 2' );
$CM::marcaManual( $id2, $diaExtra, '14:00', $A::REGRESO, null, 'Prueba fase 2' );
$CM::marcaManual( $id2, $diaExtra, '21:30', $A::FIN_JORNADA, 'fin_jornada', 'Prueba fase 2' );
$luis = Trabajadores::buscar( $id2 );
$j    = $J::delDia( $luis, $diaExtra );
$comprobar( 'el día se calcula: 20 min de tardanza y 2 h fuera de horario sin autorizar', $j && 20 === $j->minutosTardanza && 120 === $j->minutosSinAutorizar && 0 === $j->minutosExtra1 && 20 === $j->minutosNoTrabajados, $j ? wp_json_encode( get_object_vars( $j ) ) : 'null' );
$pend = array_filter( \Pekokis\Asistencia\WordPress\Admin\PantallaHorasExtra::sinAutorizar( 15 ), static fn( $p ) => (int) $p['t']['id'] === $id2 && $p['dia'] === $diaExtra );
$comprobar( 'aparece en "tiempo fuera de horario sin autorizar"', 1 === count( $pend ) );

$sinMotivo = $ST::crear( array( 'trabajador_id' => $id2, 'fecha' => $diaExtra, 'desde' => '19:30', 'hasta' => '21:00', 'compensacion' => 'pago', 'motivo' => '' ) );
$comprobar( 'autorizar horas extra exige motivo', is_wp_error( $sinMotivo ) && isset( $sinMotivo->get_error_data()['errores']['motivo'] ) );
$aut = $ST::crear( array( 'trabajador_id' => $id2, 'fecha' => $diaExtra, 'desde' => '19:30', 'hasta' => '21:00', 'compensacion' => 'pago', 'motivo' => 'Pedido urgente de prueba' ) );
$comprobar( 'se autorizan horas extra (después del día)', is_int( $aut ) );
$comprobar( 'no se cruzan dos franjas del mismo día', is_wp_error( $ST::crear( array( 'trabajador_id' => $id2, 'fecha' => $diaExtra, 'desde' => '20:00', 'hasta' => '22:00', 'compensacion' => 'pago', 'motivo' => 'Otra franja' ) ) ) );
$j = $J::delDia( $luis, $diaExtra );
$comprobar( 'dentro de la franja es sobretiempo; lo demás sigue sin autorizar', 90 === $j->minutosExtra1 && 30 === $j->minutosSinAutorizar, wp_json_encode( array( $j->minutosExtra1, $j->minutosSinAutorizar ) ) );

$comprobar( 'un sueldo de 0 no se acepta', is_wp_error( $SU::agregar( $id2, array( 'modalidad' => 'mensual', 'monto' => '0', 'vigente_desde' => '2020-01-01' ) ) ) );
$s1 = $SU::agregar( $id2, array( 'modalidad' => 'mensual', 'monto' => '3.000,00', 'vigente_desde' => '2020-01-01', 'nota' => 'Contrato' ) );
$comprobar( 'se registra el sueldo', is_int( $s1 ) && 300000 === $SU::vigente( $id2 )->montoCentimos );
$comprobar( 'un cambio tiene que regir después del actual', is_wp_error( $SU::agregar( $id2, array( 'modalidad' => 'mensual', 'monto' => '3300', 'vigente_desde' => '2020-01-01' ) ) ) );
$manana = ( new DateTimeImmutable( $hoy ) )->modify( '+1 day' )->format( 'Y-m-d' );
$s2     = $SU::agregar( $id2, array( 'modalidad' => 'mensual', 'monto' => '3300', 'vigente_desde' => $manana, 'nota' => 'Aumento' ) );
$comprobar( 'un aumento cierra el sueldo anterior el día antes', is_int( $s2 ) && 300000 === $SU::vigente( $id2, $hoy )->montoCentimos && 330000 === $SU::vigente( $id2, $manana )->montoCentimos );
$comprobar( 'solo se anula el último sueldo', is_wp_error( $SU::anular( $s1, 'Prueba' ) ) );
$comprobar( 'al anular el aumento vuelve a regir el anterior', true === $SU::anular( $s2, 'Era de prueba' ) && 300000 === $SU::vigente( $id2, $manana )->montoCentimos );

$mesExtra = substr( $diaExtra, 0, 7 );
$pp       = $J::prePlanilla( array( $luis ), $mesExtra )[ $id2 ] ?? null;
$comprobar( 'pre-planilla: 1,5 h al 25 % = S/ 23,44 (valor hora 12,50)', $pp && 90 === $pp->minutos['extra_1'] && 2344 === $pp->montos['extra_1'], $pp ? wp_json_encode( array( $pp->minutos, $pp->montos ) ) : 'null' );
$comprobar( 'pre-planilla: cuenta faltas y tardanzas', $pp && $pp->dias['faltas'] >= 1 && 20 === $pp->minutos['tardanza'] && 30 === $pp->minutos['sin_autorizar'] );
$ajustesFase2 = \Pekokis\Asistencia\WordPress\Ajustes::todos();
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'tasa_extra_1' => 50 ) + $ajustesFase2 );
$pp50 = $J::prePlanilla( array( $luis ), $mesExtra )[ $id2 ];
$comprobar( 'las reglas de Ajustes cambian el cálculo (50 % → S/ 28,13)', 2813 === $pp50->montos['extra_1'] );
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, $ajustesFase2 );

$xlsPP = \Pekokis\Asistencia\WordPress\PrePlanillas::excel( $mesExtra, 'Prueba' );
if ( class_exists( 'ZipArchive' ) ) {
	$tmp = wp_tempnam( 'pka3.xlsx' );
	file_put_contents( $tmp, $xlsPP['contenido'] ); // phpcs:ignore
	$zip = new ZipArchive();
	$zip->open( $tmp );
	$textos = (string) $zip->getFromName( 'xl/sharedStrings.xml' );
	$hojas  = $zip->numFiles;
	$zip->close();
	wp_delete_file( $tmp );
	$comprobar( 'el Excel de pre-planilla trae a Luis, el aviso de estimado y las reglas', str_contains( $textos, 'Pérez, Luis' ) && str_contains( $textos, 'ESTIMADOS' ) && str_contains( $textos, 'sueldo ÷ 30 ÷ 8 h' ) );
}

$gerente2 = wp_insert_user( array( 'user_login' => 'gerente2_prueba_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'role' => 'pka_gerencia' ) );
[ $histAdmin ] = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'trabajador' => $id2 ) );
wp_set_current_user( $gerente2 );
[ $histGerencia ] = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'trabajador' => $id2 ) );
wp_set_current_user( 1 );
$conSueldo = static fn( array $filas ): int => count( array_filter( $filas, static fn( $f ) => str_starts_with( $f['accion'], 'sueldo.' ) ) );
$comprobar( 'el historial muestra los sueldos a RR.HH. pero no a Gerencia', 3 === $conSueldo( $histAdmin ) && 0 === $conSueldo( $histGerencia ) && count( $histGerencia ) > 0 );
$comprobar( 'gerencia no ve sueldos', ! user_can( $gerente2, \Pekokis\Asistencia\WordPress\Capacidades::VER_SUELDOS ) );

$jefe2 = wp_insert_user( array( 'user_login' => 'jefe2_prueba_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'role' => 'pka_rrhh' ) );
Trabajadores::guardar( array( 'user_id' => $jefe2, 'jefe_user_id' => 1 ) + Trabajadores::buscar( $id2 ), $id2, 'Luis en RR.HH.' );
wp_set_current_user( $jefe2 );
$propioST = $ST::crear( array( 'trabajador_id' => $id2, 'fecha' => $diaExtra, 'desde' => '06:00', 'hasta' => '08:00', 'compensacion' => 'pago', 'motivo' => 'Me autorizo yo' ) );
$propioSU = $SU::agregar( $id2, array( 'modalidad' => 'mensual', 'monto' => '9000', 'vigente_desde' => $manana ) );
wp_set_current_user( 1 );
$comprobar( 'nadie se autoriza horas extra ni se registra su sueldo', is_wp_error( $propioST ) && 'pka_propio' === $propioST->get_error_code() && is_wp_error( $propioSU ) && 'pka_propio' === $propioSU->get_error_code() );

$comprobar( 'anular la autorización exige motivo', is_wp_error( $ST::anular( $aut, '' ) ) );
$comprobar( 'al anularla vuelve a quedar sin autorizar', true === $ST::anular( $aut, 'Se autorizó por error' ) && 120 === $J::delDia( $luis, $diaExtra )->minutosSinAutorizar );
$comprobar( 'la base de datos está en la versión 3', '3' === get_option( 'pka_version_bd' ) );
wp_delete_user( $gerente2 );
wp_delete_user( $jefe2 );

// ---- 12. Dashboard e informe por trabajador (fase 3).
WP_CLI::line( '12. Dashboard e informes' );
$INF   = \Pekokis\Asistencia\WordPress\Informes::class;
$datos = $INF::mes( $mesExtra, null );
$comprobar( 'el resumen del mes incluye a Luis y suma al equipo', isset( $datos['filas'][ $id2 ] ) && $datos['total']->dias['faltas'] >= $datos['filas'][ $id2 ]->dias['faltas'] && $datos['total']->minutos['trabajados'] >= 600 );
$comprobar( 'cuenta los días con tardanza y la puntualidad', 1 === $datos['filas'][ $id2 ]->dias['con_tardanza'] && 0 === $datos['filas'][ $id2 ]->puntualidad() );
$comprobar( 'compara 6 meses, el pedido primero', 6 === count( $INF::comparacion( $mesExtra, null ) ) && array_key_first( $INF::comparacion( $mesExtra, null ) ) === $mesExtra );
$comprobar( 'un jefe solo ve a su equipo', array( $id2 ) === array_map( static fn( $p ) => (int) $p['id'], $INF::personas( $mesExtra, array( $id2 ) ) ) && array() === $INF::personas( $mesExtra, array() ) );
$diasLuis = $INF::diasDe( $luis, $mesExtra );
$comprobar( 'el calendario pinta cada día según su estado', 'tarde' === $INF::estadoVisual( $diasLuis[ $diaExtra ] ?? null ) && 'falta' === $INF::estadoVisual( $diasLuis[ $antes[1] ] ?? null ) && 'pendiente' === $INF::estadoVisual( null ) );
$comprobar( 'las marcas del mes incluyen las anuladas', count( $INF::marcasDelMes( $id2, $mesExtra )[ $diaExtra ] ?? array() ) >= 4 );
$xlsInf = $INF::excelTrabajador( $luis, $mesExtra, false, 'Prueba' );
if ( class_exists( 'ZipArchive' ) ) {
	$tmp = wp_tempnam( 'pka4.xlsx' );
	file_put_contents( $tmp, $xlsInf['contenido'] ); // phpcs:ignore
	$zip = new ZipArchive();
	$zip->open( $tmp );
	$textos = (string) $zip->getFromName( 'xl/sharedStrings.xml' );
	$zip->close();
	wp_delete_file( $tmp );
	$comprobar( 'el Excel del informe trae resumen, días y marcas, sin montos si no ve sueldos', str_contains( $textos, 'Informe de asistencia' ) && str_contains( $textos, 'Entrada (manual)' ) && str_contains( $textos, 'Tarde' ) && ! str_contains( $textos, 'Costo estimado' ) );
}
$comprobar( 'el nombre del archivo no lleva tildes', str_starts_with( $xlsInf['nombre'], 'informe-perez-luis-' ), $xlsInf['nombre'] );

$comprobar( 'presencia por IP del taller', ( $_SERVER['REMOTE_ADDR'] = '203.0.113.10' ) && Presencia::enRedDelTaller() );

// Deja los ajustes como estaban (la prueba cambia el aviso y el webhook).
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, is_array( $ajustesPrueba ) ? $ajustesPrueba : array() );

if ( $fallos ) {
	WP_CLI::error( $fallos . ' comprobaciones fallaron.' );
}

WP_CLI::success( 'Todo el flujo funciona.' );
