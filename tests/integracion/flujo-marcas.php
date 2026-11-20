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
foreach ( array( 'trabajadores', 'marcas', 'auditoria', 'credenciales', 'dispositivos', 'activaciones', 'redes', 'correcciones', 'pantallas', 'revisiones', 'calendario', 'ausencias', 'sueldos', 'avisos', 'pagos', 'recibos', 'solicitudes_celular', 'llaves_gerencia' ) as $tabla ) {
	$wpdb->query( 'TRUNCATE TABLE ' . Tablas::nombre( $tabla ) ); // phpcs:ignore
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pka\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pka\\_%'" ); // phpcs:ignore
$_COOKIE = array();
$ajustesPrueba = get_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array() );
// Las pruebas del QR parten de 30 s (el valor de fábrica ahora es 30 min).
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'aviso_texto' => '', 'aviso_version' => 1, 'webhook_respaldo' => '', 'qr_segundos' => 30, 'modo_presencia' => 'wifi_o_qr' ) + ( is_array( $ajustesPrueba ) ? $ajustesPrueba : array() ) );

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
$comprobar( 'la pantalla trae la fecha larga y si el modo pide WiFi (maqueta P1A); el QR de fábrica cambia cada 30 min', preg_match( '/^[A-ZÁÉÍÓÚ][a-záéíóú]+ \d{1,2} de [a-z]+$/u', (string) ( $cod['fecha'] ?? '' ) ) && is_bool( $cod['con_wifi'] ?? null ) && 1800 === \Pekokis\Asistencia\WordPress\Ajustes::porDefecto()['qr_segundos'] && 'wifi_y_qr' === \Pekokis\Asistencia\WordPress\Ajustes::porDefecto()['modo_presencia'], wp_json_encode( array( $cod['fecha'] ?? null, $cod['con_wifi'] ?? null ) ) );
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

// QR de 30 minutos (maquetas): el código dura media hora y el pase también.
$ajQr                = get_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array() );
$ajQr['qr_segundos'] = 1800;
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, $ajQr );
$filaPantalla = array_values( array_filter( \Pekokis\Asistencia\WordPress\Pantallas::listar(), static fn( $p ) => (int) $p['id'] === $pantalla ) )[0];
$largo        = \Pekokis\Asistencia\WordPress\Pantallas::codigoVigente( $filaPantalla, '' );
unset( $_COOKIE[ Presencia::COOKIE_PASE ] );
[ $st ] = $api( 'POST', 'presencia/codigo', array( 'token' => $largo['token'] ) );
$comprobar( 'QR de 30 min: dura media hora y deja un pase igual de largo', 1800 === $largo['total'] && $largo['segundos'] <= 1800 && 200 === $st && Presencia::segundosDelPase() > 1790, wp_json_encode( array( $largo['total'], $largo['segundos'], Presencia::segundosDelPase() ) ) );
[ $st ] = $api( 'POST', 'presencia/codigo', array( 'token' => $cod['token'] ) );
$comprobar( 'un token del QR de 30 s no sirve con el de 30 min', 422 === $st );
$ajQr['qr_segundos'] = 30;
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, $ajQr );
unset( $_COOKIE[ Presencia::COOKIE_PASE ] );
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

// ---- 3d-ter. Sesión de la app (T0): entra una vez con la huella; para marcar se pide otra vez.
WP_CLI::line( '3d-ter. Sesión de la app' );
unset( $_COOKIE[ \Pekokis\Asistencia\WordPress\SesionTrabajador::COOKIE ] );
[ $st, $r ] = $api( 'GET', 'yo' );
$comprobar( 'sin sesión, "yo" pide entrar', 401 === $st && 'pka_sesion' === $r['code'], wp_json_encode( $r ) );

$identificar();
[ $st, $yo ] = $api( 'GET', 'yo' );
$comprobar( 'con la sesión: estado, día, presencia y los 4 pasos de "Tu día"', 200 === $st && isset( $yo['presencia']['cumple'], $yo['dia']['texto'] ) && 4 === count( $yo['vista']['pasos'] ?? array() ) && '' !== $yo['iniciales'], wp_json_encode( $yo ) );

[ $st, $mesS ] = $api( 'POST', 'yo/mes', array( 'mes' => substr( $ayer, 0, 7 ) ) );
$detAyer       = $mesS['detalle'][ $ayer ] ?? array();
$comprobar( 'mis horas sin ticket: resumen y detalle de ayer (entrada 09:00, salida 19:35)', 200 === $st && isset( $mesS['resumen']['horas'] ) && '09:00' === ( $detAyer['pasos'][0]['hora'] ?? '' ) && '19:35' === ( $detAyer['pasos'][3]['hora'] ?? '' ), wp_json_encode( $detAyer ) );

$dniReal = (string) Trabajadores::buscar( $id )['dni'];
[ $st, $perfil ] = $api( 'GET', 'yo/perfil' );
$comprobar( 'el perfil muestra el DNI oculto', 200 === $st && '•••• ' . substr( $dniReal, -4 ) === $perfil['dni'] && ! str_contains( wp_json_encode( $perfil ), $dniReal ), wp_json_encode( $perfil ) );
[ $st, $dni ] = $api( 'POST', 'yo/dni' );
$comprobar( 'ver el DNI completo queda en el historial', 200 === $st && $dniReal === $dni['dni'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'auditoria' ) . " WHERE accion = 'trabajador.ver_dni' AND entidad_id = %d", $id ) ) ); // phpcs:ignore

[ $st, $r ] = $api( 'POST', 'marcas', array( 'accion' => 'entrada' ) );
$comprobar( 'la sesión no sirve para marcar: pide la huella', 401 === $st && 'pka_ticket' === $r['code'] );

[ $st ] = $api( 'POST', 'yo/salir' );
[ $st2, $r ] = $api( 'GET', 'yo' );
$comprobar( 'cerrar sesión', 200 === $st && 401 === $st2 );

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
		$correo = array( 'para' => $atts['to'], 'asunto' => $atts['subject'], 'adjunto' => isset( $atts['attachments'][0] ) && file_exists( $atts['attachments'][0] ) ? filesize( $atts['attachments'][0] ) : 0 );
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

// ---- 11. Horas y pagos por recibos por honorarios.
WP_CLI::line( '11. Horas y pagos (honorarios y tarifas)' );
$J  = \Pekokis\Asistencia\WordPress\Jornadas::class;
$SU = \Pekokis\Asistencia\WordPress\Sueldos::class;
$A  = \Pekokis\Asistencia\Nucleo\Accion::class;
$diaExtra = $antes[0];
$CM = \Pekokis\Asistencia\WordPress\Correcciones::class;
$CM::marcaManual( $id2, $diaExtra, '09:20', $A::ENTRADA, null, 'Prueba de pagos' );
$CM::marcaManual( $id2, $diaExtra, '13:00', $A::SALIDA_REFRIGERIO, 'refrigerio', 'Prueba de pagos' );
$CM::marcaManual( $id2, $diaExtra, '14:00', $A::REGRESO, null, 'Prueba de pagos' );
$CM::marcaManual( $id2, $diaExtra, '21:30', $A::FIN_JORNADA, 'fin_jornada', 'Prueba de pagos' );
$luis = Trabajadores::buscar( $id2 );
$j    = $J::delDia( $luis, $diaExtra );
$comprobar( 'el día: 11 h 10 trabajadas, 20 min de tardanza y 1 h 40 de extra automática', $j && 670 === $j->minutosTrabajados && 20 === $j->minutosTardanza && 100 === $j->minutosExtra, $j ? wp_json_encode( get_object_vars( $j ) ) : 'null' );

$tarifas = array( 'honorario' => '1.800,00', 'tarifa_extra' => '12', 'tarifa_sabado' => '14', 'tarifa_domingo' => '20', 'tarifa_feriado' => '24', 'vigente_desde' => '2020-01-01', 'nota' => 'Contrato' );
$comprobar( 'un honorario de 0 no se acepta', is_wp_error( $SU::agregar( $id2, array( 'honorario' => '0' ) + $tarifas ) ) );
$comprobar( 'una tarifa vacía no se acepta', is_wp_error( $SU::agregar( $id2, array( 'tarifa_sabado' => '' ) + $tarifas ) ) );
$s1 = $SU::agregar( $id2, $tarifas );
$comprobar( 'se registran el honorario y las 4 tarifas', is_int( $s1 ) && 180000 === $SU::vigente( $id2 )->honorarioCentimos && 1200 === $SU::vigente( $id2 )->tarifaExtra && 2400 === $SU::vigente( $id2 )->tarifaFeriado );
$comprobar( 'un cambio tiene que regir después del actual', is_wp_error( $SU::agregar( $id2, $tarifas ) ) );
$manana = ( new DateTimeImmutable( $hoy ) )->modify( '+1 day' )->format( 'Y-m-d' );
$s2     = $SU::agregar( $id2, array( 'honorario' => '2000', 'tarifa_extra' => '15', 'vigente_desde' => $manana, 'nota' => 'Aumento' ) + $tarifas );
$comprobar( 'un aumento cierra el registro anterior el día antes', is_int( $s2 ) && 180000 === $SU::vigente( $id2, $hoy )->honorarioCentimos && 200000 === $SU::vigente( $id2, $manana )->honorarioCentimos );
$comprobar( 'solo se anula el último', is_wp_error( $SU::anular( $s1, 'Prueba' ) ) );
$comprobar( 'al anular el aumento vuelve a regir el anterior', true === $SU::anular( $s2, 'Era de prueba' ) && 180000 === $SU::vigente( $id2, $manana )->honorarioCentimos );

$mesExtra = substr( $diaExtra, 0, 7 );
$pp       = $J::prePlanilla( array( $luis ), $mesExtra )[ $id2 ] ?? null;
$comprobar( 'pago: S/ 1,800 + 1 h 40 × S/ 12 = S/ 1,820', $pp && 100 === $pp->minutos['extra'] && 2000 === $pp->montos['extra'] && 182000 === $pp->montos['total'], $pp ? wp_json_encode( array( $pp->minutos, $pp->montos ) ) : 'null' );
$comprobar( 'las faltas y tardanzas se señalan pero no se descuentan', $pp && $pp->dias['faltas'] >= 1 && 20 === $pp->minutos['tardanza'] && 180000 === $pp->montos['honorario'] );

// Un sábado trabajado va con la tarifa de sábado.
$sabado = ( new DateTimeImmutable( $diaExtra ) )->modify( 'next saturday' )->format( 'Y-m-d' );
if ( $sabado <= $ayer ) {
	$CM::marcaManual( $id2, $sabado, '09:00', $A::ENTRADA, null, 'Sábado de prueba' );
	$CM::marcaManual( $id2, $sabado, '13:00', $A::FIN_JORNADA, 'fin_jornada', 'Sábado de prueba' );
	$js = $J::delDia( Trabajadores::buscar( $id2 ), $sabado );
	$comprobar( 'el sábado: 4 h con la tarifa de sábado', $js && 240 === $js->minutosSabado && 0 === $js->minutosExtra );
}

$xlsPP = \Pekokis\Asistencia\WordPress\PrePlanillas::excel( $mesExtra, 'Prueba' );
if ( class_exists( 'ZipArchive' ) ) {
	$tmp = wp_tempnam( 'pka3.xlsx' );
	file_put_contents( $tmp, $xlsPP['contenido'] ); // phpcs:ignore
	$zip = new ZipArchive();
	$zip->open( $tmp );
	$textos = (string) $zip->getFromName( 'xl/sharedStrings.xml' );
	$zip->close();
	wp_delete_file( $tmp );
	$comprobar( 'la planilla de pagos trae a Luis, el estado y la fórmula', str_contains( $textos, 'Pérez, Luis' ) && ( str_contains( $textos, 'Listo para pagar' ) || str_contains( $textos, 'Resolver incidencia' ) ) && str_contains( $textos, 'honorario + horas extra × tarifa extra' ) && 'pagos-' . $mesExtra . '.xlsx' === $xlsPP['nombre'] );
}

$gerente2 = wp_insert_user( array( 'user_login' => 'gerente2_prueba_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'role' => 'pka_gerencia' ) );
[ $histAdmin ] = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'trabajador' => $id2 ) );
wp_set_current_user( $gerente2 );
[ $histGerencia ] = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'trabajador' => $id2 ) );
wp_set_current_user( 1 );
$conSueldo = static fn( array $filas ): int => count( array_filter( $filas, static fn( $f ) => str_starts_with( $f['accion'], 'sueldo.' ) ) );
$comprobar( 'el historial muestra los honorarios a quien ve sueldos y no al rol de solo lectura', 3 === $conSueldo( $histAdmin ) && 0 === $conSueldo( $histGerencia ) && count( $histGerencia ) > 0 );

$jefe2 = wp_insert_user( array( 'user_login' => 'jefe2_prueba_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'role' => 'pka_rrhh' ) );
Trabajadores::guardar( array( 'user_id' => $jefe2, 'jefe_user_id' => 1 ) + Trabajadores::buscar( $id2 ), $id2, 'Luis en RR.HH.' );
wp_set_current_user( $jefe2 );
$propioSU = $SU::agregar( $id2, array( 'vigente_desde' => $manana ) + $tarifas );
wp_set_current_user( 1 );
$comprobar( 'nadie registra su propio honorario', is_wp_error( $propioSU ) && 'pka_propio' === $propioSU->get_error_code() );
$comprobar( 'la base de datos tiene las columnas de tarifas (versión 5 o más)', (int) get_option( 'pka_version_bd' ) >= 5 && null !== $wpdb->get_var( 'SHOW COLUMNS FROM ' . Tablas::nombre( 'sueldos' ) . " LIKE 'tarifa_feriado'" ) ); // phpcs:ignore
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

// ---- 13. Avisos por correo y webhook (fase 4). Nada sale de verdad: se capturan.
WP_CLI::line( '13. Avisos' );
$N  = \Pekokis\Asistencia\WordPress\Notificaciones::class;
$RA = \Pekokis\Asistencia\Nucleo\ReglasAvisos::class;
$correos  = array();
$llamadas = array();
$fallarCorreo = false;
add_filter( 'pre_wp_mail', static function ( $nulo, $atts ) use ( &$correos, &$fallarCorreo ) {
	if ( $fallarCorreo ) {
		return false;
	}
	$correos[] = $atts;
	return true;
}, 10, 2 );
add_filter( 'pre_http_request', static function ( $nulo, $args, $url ) use ( &$llamadas ) {
	if ( str_contains( $url, 'avisos.example.test' ) ) {
		$llamadas[] = array( 'url' => $url, 'args' => $args );
		return array( 'headers' => array(), 'body' => 'ok', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	return $nulo;
}, 10, 3 );
$wpdb->query( 'TRUNCATE TABLE ' . Tablas::nombre( 'avisos' ) ); // phpcs:ignore
$ajustesAvisos = \Pekokis\Asistencia\WordPress\Ajustes::todos();
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'avisos_activos' => array(), 'webhook_avisos' => '' ) + $ajustesAvisos );
$colaDe = static fn( string $tipo ): array => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'avisos' ) . ' WHERE tipo = %s ORDER BY id', $tipo ), ARRAY_A ); // phpcs:ignore

$comprobar( 'con los avisos apagados no se encola nada', 0 === $N::encolar( $RA::SOLICITUD, 'prueba|1', Trabajadores::buscar( $id2 ), 'x' ) && 0 === $N::revisar( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'avisos_activos' => array_keys( $RA::TIPOS ), 'webhook_avisos' => '' ) + $ajustesAvisos );
$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'correo' => 'luis@example.test', 'telefono' => '+51 987 654 321', 'jefe_user_id' => 1 ), array( 'id' => $id2 ) );

$sol = \Pekokis\Asistencia\WordPress\Correcciones::solicitar( $id2, $ayer, '19:00', \Pekokis\Asistencia\Nucleo\Accion::FIN_JORNADA, 'fin_jornada', 'Me olvidé de marcar' );
$avSol = $colaDe( $RA::SOLICITUD );
$comprobar( 'una solicitud nueva avisa al jefe', is_int( $sol ) && 1 === count( $avSol ) && get_userdata( 1 )->user_email === $avSol[0]['destino'] && str_contains( $avSol[0]['linea'], 'Me olvidé de marcar' ), wp_json_encode( $avSol ) );
\Pekokis\Asistencia\WordPress\Correcciones::rechazar( $sol, 'Ese día no viniste' );
$avRes = $colaDe( $RA::RESUELTA );
$comprobar( 'al rechazarla se avisa al trabajador, con la nota', 1 === count( $avRes ) && 'luis@example.test' === $avRes[0]['destino'] && str_contains( $avRes[0]['linea'], 'rechazada' ) && str_contains( $avRes[0]['linea'], 'Ese día no viniste' ) );

$marcaRev = (int) $wpdb->get_var( 'SELECT id FROM ' . Tablas::nombre( 'marcas' ) . ' WHERE requiere_revision = 1 ORDER BY id LIMIT 1' ); // phpcs:ignore
$N::revision( $marcaRev );
$N::revision( $marcaRev );
$comprobar( 'una marca por revisar avisa una sola vez', $marcaRev > 0 && 1 === count( $colaDe( $RA::REVISION ) ) );

// El próximo lunes a las 9:20: nadie marcó, todos "no llegaron".
// Un lunes sin nada el domingo anterior (si hoy es domingo, la jornada de hoy seguiría abierta el lunes).
$lunes   = ( new DateTimeImmutable( 'next monday 09:20', $zonaLima ) )->modify( '+7 days' )->setTimezone( new DateTimeZone( 'UTC' ) );
$nuevos  = $N::revisar( $lunes );
$ausentes = $colaDe( $RA::AUSENTE );
$comprobar( 'a las 9:20 se encola un aviso por cada ausente', $nuevos >= 1 && count( $ausentes ) === count( Trabajadores::listar() ) && str_contains( implode( ' ', array_column( $ausentes, 'linea' ) ), 'Luis Pérez entra a las 09:00' ), $nuevos . ' / ' . count( $ausentes ) );
$comprobar( 'si el cron vuelve a pasar no se repiten', 0 === $N::revisar( $lunes ) );

// Otro ausente del mismo jefe: tienen que salir juntos en un solo correo.
$N::encolar( $RA::AUSENTE, 'ausente|prueba-otro', Trabajadores::buscar( $id2 ), 'Otra Persona entra a las 09:00 y todavía no marca.' );
$correos = array();
$envio   = $N::despachar();
$ausMail = array_values( array_filter( $correos, static fn( $c ) => str_contains( $c['subject'], 'llegaron' ) || str_contains( $c['subject'], 'llegó' ) ) );
$comprobar( 'se envían agrupados: un solo correo "No llegaron a su hora" con la lista', 0 === $envio['fallidos'] && 1 === count( $ausMail ) && str_contains( $ausMail[0]['subject'], 'No llegaron a su hora' ) && str_contains( $ausMail[0]['message'], '- Luis Pérez entra' ) && str_contains( $ausMail[0]['message'], '- Otra Persona' ), wp_json_encode( array_column( $correos, 'subject' ) ) );
$comprobar( 'después quedan como enviados', 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'avisos' ) . " WHERE estado = 'pendiente'" ) ); // phpcs:ignore

// Webhook para WhatsApp: JSON firmado con el teléfono del trabajador.
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'webhook_avisos' => 'https://avisos.example.test/hook' ) + \Pekokis\Asistencia\WordPress\Ajustes::todos() );
$N::encolar( $RA::SIN_SALIDA, 'sin_salida|prueba|' . $id2, Trabajadores::buscar( $id2 ), 'Hola, Luis: no has marcado tu salida de hoy.' );
$correos  = array();
$llamadas = array();
$N::despachar();
$cuerpo = $llamadas[0]['args']['body'] ?? '';
$json   = json_decode( $cuerpo, true );
$comprobar( 'el aviso al trabajador sale por correo y por el webhook con su teléfono', 1 === count( $correos ) && 1 === count( $llamadas ) && '+51987654321' === ( $json['para']['telefono'] ?? '' ) && 'sin_salida' === ( $json['evento'] ?? '' ), $cuerpo );
$comprobar( 'el webhook va firmado con HMAC', hash_hmac( 'sha256', $cuerpo, \Pekokis\Asistencia\WordPress\Secretos::clave() ) === ( $llamadas[0]['args']['headers']['X-PKA-Firma'] ?? '' ) );

// Si el correo falla, se reintenta y a la tercera queda como fallido.
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'webhook_avisos' => '' ) + \Pekokis\Asistencia\WordPress\Ajustes::todos() );
$N::encolar( $RA::PANTALLA, 'pantalla|prueba', null, 'La pantalla de prueba no se conecta.' );
$fallarCorreo = true;
$N::despachar();
$N::despachar();
$intentos = $wpdb->get_row( 'SELECT estado, intentos FROM ' . Tablas::nombre( 'avisos' ) . " WHERE clave = 'pantalla|prueba'", ARRAY_A ); // phpcs:ignore
$N::despachar();
$final = $wpdb->get_row( 'SELECT estado, intentos, error FROM ' . Tablas::nombre( 'avisos' ) . " WHERE clave = 'pantalla|prueba'", ARRAY_A ); // phpcs:ignore
$fallarCorreo = false;
$comprobar( 'un correo que falla se reintenta y a la tercera queda como fallido', 'pendiente' === $intentos['estado'] && 2 === (int) $intentos['intentos'] && 'fallido' === $final['estado'] && str_contains( $final['error'], 'SMTP' ), wp_json_encode( array( $intentos, $final ) ) );
$comprobar( 'el cron de avisos está programado cada 5 minutos', false !== wp_next_scheduled( $N::EVENTO ) && 'pka_cinco_minutos' === wp_get_schedule( $N::EVENTO ) );
$comprobar( 'la base de datos está al día', \Pekokis\Asistencia\WordPress\Instalador::VERSION_BD === get_option( 'pka_version_bd' ) );
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, $ajustesAvisos );

// ---- 14. Recibos por honorarios y calculadora de pagos.
WP_CLI::line( '14. Recibos y pagos' );
$RC = \Pekokis\Asistencia\WordPress\Recibos::class;
$PG = \Pekokis\Asistencia\WordPress\Pagos::class;
$VR = \Pekokis\Asistencia\Nucleo\ValidacionRecibo::class;
$wpdb->query( 'TRUNCATE TABLE ' . Tablas::nombre( 'recibos' ) ); // phpcs:ignore
$wpdb->query( 'TRUNCATE TABLE ' . Tablas::nombre( 'pagos' ) ); // phpcs:ignore
$pdf = static function ( string $contenido ): array {
	$ruta = wp_tempnam( 'recibo.pdf' );
	file_put_contents( $ruta, $contenido ); // phpcs:ignore
	return array( 'name' => 'recibo.pdf', 'type' => 'application/pdf', 'tmp_name' => $ruta, 'error' => 0, 'size' => strlen( $contenido ) );
};
$apiArchivo = static function ( string $ruta, array $campos, ?array $archivo ): array {
	$peticion = new WP_REST_Request( 'POST', '/asistencia/v1/' . $ruta );
	foreach ( $campos as $k => $v ) {
		$peticion->set_param( $k, $v );
	}
	if ( $archivo ) {
		$peticion->set_file_params( array( 'archivo' => $archivo ) );
	}
	$respuesta = rest_do_request( $peticion );
	return array( $respuesta->get_status(), json_decode( wp_json_encode( rest_get_server()->response_to_data( $respuesta, false ) ), true ) );
};

$ticketLuis = \Pekokis\Asistencia\WordPress\Temporales::guardar( 'ticket', array( 'trabajador_id' => $id2 ), 120 );
[ $st, $mios ] = $api( 'POST', 'yo/recibos', array( 'ticket' => $ticketLuis ) );
$mesR = $mios['meses'][0]['mes'] ?? '';
$comprobar( 'mi recibo: con la huella da un permiso de 15 min y la lista de meses', 200 === $st && 32 <= strlen( (string) ( $mios['token'] ?? '' ) ) && 900 === $mios['segundos'] && 'falta' === ( $mios['meses'][0]['estado'] ?? '' ) && true === $mios['meses'][0]['puede_enviar'], wp_json_encode( $mios ) );
$tok  = $mios['token'];
$base = array( 'token' => $tok, 'mes' => $mesR, 'numero' => 'e001-482', 'monto' => '1820.00' );

[ $st, $r ] = $apiArchivo( 'yo/recibos/enviar', $base, $pdf( '' ) );
$comprobar( 'un PDF vacío se rechaza', 400 === $st && isset( $r['data']['errores']['archivo'] ), wp_json_encode( $r ) );
[ $st, $r ] = $apiArchivo( 'yo/recibos/enviar', $base, $pdf( "\x89PNG imagen" ) );
$comprobar( 'un archivo que no es PDF se rechaza', 400 === $st && str_contains( (string) ( $r['data']['errores']['archivo'] ?? '' ), 'no es un PDF' ) );
[ $st, $r ] = $apiArchivo( 'yo/recibos/enviar', array( 'numero' => '482' ) + $base, $pdf( "%PDF-1.4\nrecibo de prueba" ) );
$comprobar( 'el número tiene que ser serie-número', 400 === $st && isset( $r['data']['errores']['numero'] ) );
[ $st, $r ] = $apiArchivo( 'yo/recibos/enviar', array( 'token' => 'falso' ) + $base, $pdf( "%PDF-1.4\nrecibo de prueba" ) );
$comprobar( 'sin el permiso de la huella no se envía', 401 === $st );

[ $st, $r ] = $apiArchivo( 'yo/recibos/enviar', $base, $pdf( "%PDF-1.4\nrecibo de prueba" ) );
$m0 = $r['meses'][0] ?? array();
$comprobar( 'se envía el recibo y queda "esperando revisión"', 200 === $st && 'enviado' === ( $m0['estado'] ?? '' ) && 'E001-482' === $m0['numero'] && true === $m0['puede_anular'] && false === $m0['puede_enviar'], wp_json_encode( $r ) );
$recibo = $RC::actual( $id2, $mesR );
$comprobar( 'el PDF queda en una carpeta privada (con .htaccess que niega el acceso)', $recibo && is_file( $RC::ruta( $recibo ) ) && str_contains( (string) file_get_contents( $RC::carpeta() . '/.htaccess' ), 'Require all denied' ) && 64 === strlen( $recibo['archivo_sha256'] ) );
[ $st ] = $apiArchivo( 'yo/recibos/enviar', $base, $pdf( "%PDF-1.4\notro" ) );
$comprobar( 'no se envían dos recibos del mismo mes', 409 === $st );

[ $st, $r ] = $api( 'POST', 'yo/recibos/anular', array( 'token' => $tok, 'id' => (int) $recibo['id'] ) );
$comprobar( 'se puede anular el envío mientras no se revise', 200 === $st && 'anulado' === ( $r['meses'][0]['estado'] ?? '' ) && true === $r['meses'][0]['puede_enviar'] && null === $RC::actual( $id2, $mesR ) );

[ $st, $r ] = $apiArchivo( 'yo/recibos/enviar', array( 'monto' => '1800' ) + $base, $pdf( "%PDF-1.4\nrecibo corregido" ) );
$recibo = $RC::actual( $id2, $mesR );
$comprobar( 'observar exige un comentario', is_wp_error( $RC::observar( (int) $recibo['id'], '' ) ) );
$comprobar( 'gerencia lo observa y el trabajador ve el comentario', true === $RC::observar( (int) $recibo['id'], 'El monto no coincide con el cálculo' ) && 'observado' === $RC::paraApp( Trabajadores::buscar( $id2 ) )[0]['estado'] && 'El monto no coincide con el cálculo' === $RC::paraApp( Trabajadores::buscar( $id2 ) )[0]['comentario'] );
$comprobar( '"Recordar" sale aunque los avisos estén apagados', 1 <= \Pekokis\Asistencia\WordPress\Notificaciones::recordarRecibo( $id2, $mesR ) );

[ $st, $r ] = $apiArchivo( 'yo/recibos/enviar', $base, $pdf( "%PDF-1.4\nrecibo bien" ) );
$recibo = $RC::actual( $id2, $mesR );
$comprobar( 'tras la observación se corrige enviando otro', 200 === $st && 'enviado' === $recibo['estado'] && 3 === count( $RC::historial( $id2 ) ) );
$comprobar( 'gerencia lo da por recibido y ya no se puede anular', true === $RC::recibir( (int) $recibo['id'] ) && is_wp_error( $RC::anular( (int) $recibo['id'], $id2 ) ) );

$luisAhora = Trabajadores::buscar( $id2 );
$fLuis     = $J::prePlanilla( array( $luisAhora ), $mesR )[ $id2 ] ?? null;
$total     = $fLuis ? $fLuis->montos['total'] : 0;
$comprobar( 'se compara el recibo con el cálculo del mes', $fLuis && ( $VR::comparar( 182000, $total )['coincide'] === ( abs( 182000 - $total ) <= 5 ) ) && $RC::delMes( $mesR )[ $id2 ]['enviados'] === 2 );

$comprobar( 'marcar pagado guarda el total del momento', true === $PG::marcar( $id2, $mesR, $total ) && $total === (int) $PG::delTrabajador( $id2, $mesR )['total_centimos'] );
$comprobar( 'no se marca dos veces', is_wp_error( $PG::marcar( $id2, $mesR, $total ) ) );
$comprobar( 'en la app sale como pagado', true === $RC::paraApp( Trabajadores::buscar( $id2 ) )[0]['pagado'] );
$comprobar( 'desmarcar no borra: anula', true === $PG::desmarcar( $id2, $mesR, 'Prueba' ) && null === $PG::delTrabajador( $id2, $mesR ) && 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'pagos' ) ) + 1 );
$xlsListos = \Pekokis\Asistencia\WordPress\PrePlanillas::excel( $mesR, 'Prueba', true );
$comprobar( 'la planilla "de las listas" tiene su propio nombre', 'pagos-' . $mesR . '-listos.xlsx' === $xlsListos['nombre'] );

$gerente3 = wp_insert_user( array( 'user_login' => 'gerente3_prueba_' . wp_rand(), 'user_pass' => wp_generate_password( 20 ), 'role' => 'pka_gerencia' ) );
wp_set_current_user( $gerente3 );
[ $histG ] = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'trabajador' => $id2 ) );
wp_set_current_user( 1 );
[ $histA ] = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'trabajador' => $id2 ) );
$conMontos = static fn( array $filas ): int => count( array_filter( $filas, static fn( $f ) => str_starts_with( $f['accion'], 'recibo.' ) || str_starts_with( $f['accion'], 'pago.' ) ) );
$comprobar( 'los recibos y pagos quedan en el historial, ocultos para el rol de solo lectura', $conMontos( $histA ) >= 8 && 0 === $conMontos( $histG ) );
$envio = array_values( array_filter( $histA, static fn( $f ) => 'recibo.enviar' === $f['accion'] ) );
$comprobar( 'el envío del recibo figura como hecho por el trabajador', $envio && $id2 === (int) $envio[0]['actor_trabajador_id'] && null === $envio[0]['actor_user_id'], wp_json_encode( $envio[0] ?? array() ) );
wp_delete_user( $gerente3 );

// ---- 3d-quater. Jornada que pasa la medianoche (maquetas T2F, T2G, T3E, T3F).
WP_CLI::line( '3d-quater. Jornada que pasa la medianoche' );
$J0     = \Pekokis\Asistencia\WordPress\Jornadas::class;
$ayerN  = $J0::ayer();
$hoyN   = \Pekokis\Asistencia\WordPress\Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
$entrar = static function ( int $tid, string $dia, string $hora ): void {
	\Pekokis\Asistencia\WordPress\Marcas::registrar( array( 'trabajador_id' => $tid, 'tipo' => \Pekokis\Asistencia\Nucleo\TipoMarca::ENTRADA, 'motivo_clave' => null, 'nota' => '', 'marcado' => new DateTimeImmutable( $dia . ' ' . $hora, new DateTimeZone( 'America/Lima' ) ), 'prueba' => \Pekokis\Asistencia\Nucleo\PruebaPresencia::WIFI, 'requiere_revision' => false, 'dispositivo_id' => null, 'credencial_id' => null, 'pantalla_id' => null, 'origen' => 'trabajador' ) );
};
$noche = Trabajadores::guardar( array( 'dni' => '70000001', 'nombres' => 'Nora', 'apellidos' => 'Noche', 'horario_id' => $horario ) );
$entrar( $noche, $ayerN, '05:00' ); // Entró ayer de madrugada y sigue sin salida.
$comprobar( 'la jornada de ayer sin salida sigue abierta: es el día de trabajo', $ayerN === $J0::diaActual( $noche ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$tkNoche     = \Pekokis\Asistencia\WordPress\Temporales::guardar( 'ticket', array( 'trabajador_id' => $noche, 'credencial_id' => 0, 'dispositivo_id' => null ), 120 );
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $tkNoche, 'accion' => 'fin_jornada' ) );
$filaNoche   = $wpdb->get_row( $wpdb->prepare( 'SELECT dia_laboral, requiere_revision FROM ' . Tablas::nombre( 'marcas' ) . ' WHERE id = %d', (int) ( $r['marca_id'] ?? 0 ) ), ARRAY_A ); // phpcs:ignore
$comprobar( 'la salida va a la jornada de ayer, aunque sea otro día', 200 === $st && $ayerN === ( $filaNoche['dia_laboral'] ?? '' ) && $ayerN === $r['jornada']['dia'], wp_json_encode( array( $st, $filaNoche, $r['jornada'] ?? null ) ) );
$comprobar( 'más de 16 h: se acepta, queda por revisar y lo dice', '' !== $r['larga'] && 1 === (int) $filaNoche['requiere_revision'], (string) $r['larga'] );
$comprobar( 'cerrada la de ayer, lo siguiente ya es hoy', $hoyN === $J0::diaActual( $noche ) );

$olvido = Trabajadores::guardar( array( 'dni' => '70000002', 'nombres' => 'Omar', 'apellidos' => 'Olvido', 'horario_id' => $horario ) );
$entrar( $olvido, $ayerN, '09:00' );
$tkOlv      = \Pekokis\Asistencia\WordPress\Temporales::guardar( 'ticket', array( 'trabajador_id' => $olvido, 'credencial_id' => 0, 'dispositivo_id' => null ), 120 );
[ $st, $r ] = $api( 'POST', 'identificar/opciones' ); // Solo para no dejar el ticket sin uso: el estado sale de "yo".
\Pekokis\Asistencia\WordPress\Correcciones::solicitar( $olvido, $ayerN, '19:30', \Pekokis\Asistencia\Nucleo\Accion::FIN_JORNADA, null, 'Salí ayer y olvidé marcar' );
$comprobar( '"Salí ayer y olvidé marcar" deja empezar el día de hoy', $hoyN === $J0::diaActual( $olvido ) );
[ $st, $r ] = $api( 'POST', 'marcas', array( 'ticket' => $tkOlv, 'accion' => 'entrada' ) );
$filaOlv    = $wpdb->get_row( $wpdb->prepare( 'SELECT dia_laboral FROM ' . Tablas::nombre( 'marcas' ) . ' WHERE id = %d', (int) ( $r['marca_id'] ?? 0 ) ), ARRAY_A ); // phpcs:ignore
$comprobar( 'y su entrada de hoy cuenta para hoy', 200 === $st && $hoyN === ( $filaOlv['dia_laboral'] ?? '' ), wp_json_encode( array( $st, $filaOlv ) ) );
// Recordatorio de campaña (T9A): a quien no marcó hoy, en día laborable, pasada la hora.
$ajCamp = get_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array() );
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, array( 'campana_activa' => true, 'campana_desde' => $ayerN, 'campana_hasta' => $hoyN, 'campana_hora' => '00:00', 'campana_texto' => '' ) + $ajCamp );
$estadoDe  = new ReflectionMethod( \Pekokis\Asistencia\WordPress\Api::class, 'estadoDe' );
$laborable = \Pekokis\Asistencia\WordPress\Horarios::delDia( $horario, $hoyN )->laborable && '' === \Pekokis\Asistencia\WordPress\Calendario::descripcion( $hoyN );
$recNoche  = $estadoDe->invoke( null, $noche )['recordatorio'];
$comprobar( 'en campaña, sin marcar hoy: "Aún no marcas tu entrada" (si hoy es laborable)', $laborable ? str_starts_with( $recNoche, 'Aún no marcas' ) : '' === $recNoche, $recNoche );
$comprobar( 'quien ya marcó no lo ve', '' === $estadoDe->invoke( null, $olvido )['recordatorio'] );
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, $ajCamp );

Trabajadores::cesar( $noche, gmdate( 'Y-m-d' ), 'Prueba' );
Trabajadores::cesar( $olvido, gmdate( 'Y-m-d' ), 'Prueba' );

// ---- 15. Registro propio, PIN, cambio y liberar celular (maquetas T0, T1, T7C, G13).
WP_CLI::line( '15. Registro propio, PIN y celulares' );
$CL       = \Pekokis\Asistencia\WordPress\Celulares::class;
$avisoV   = \Pekokis\Asistencia\WordPress\Aviso::version();
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pka\\_lim\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pka\\_lim\\_%'" ); // phpcs:ignore
$entrarCon = static function ( AutenticadorSimulado $cel ) use ( $api ): array {
	[ , $op ] = $api( 'POST', 'identificar/opciones' );

	return $api( 'POST', 'identificar', array( 'reto' => $op['reto'], 'credencial' => $cel->firmar( $op['publicKey']['challenge'] ) ) );
};
$datosRita = array( 'nombres' => 'Rita', 'apellidos' => 'Nueva', 'telefono' => '987 111 222', 'dni' => '71234567', 'cumple_dia' => 7, 'cumple_mes' => 3, 'acepta_aviso' => $avisoV );

$_COOKIE                = array(); // Celular nuevo, sin nada.
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
[ $st, $r ] = $api( 'POST', 'registro/nuevo', $datosRita );
$comprobar( 'registrarse fuera del taller no se puede', 403 === $st && 'pka_sin_presencia' === $r['code'], wp_json_encode( $r ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
[ $st, $r ] = $api( 'POST', 'registro/nuevo', array( 'dni' => '123', 'nombres' => 'Rita', 'apellidos' => 'Nueva', 'acepta_aviso' => $avisoV ) );
$comprobar( 'datos con error: se dicen por campo', 422 === $st && isset( $r['data']['errores']['dni'], $r['data']['errores']['cumpleanos'], $r['data']['errores']['telefono'] ), wp_json_encode( $r['data'] ?? $r ) );
[ $st, $r ] = $api( 'POST', 'registro/nuevo', array( 'dni' => '40404040' ) + $datosRita );
$comprobar( 'un DNI que ya existe manda a "Cambié de celular"', 422 === $st && str_contains( (string) ( $r['data']['errores']['dni'] ?? '' ), 'Cambié de celular' ) );

[ $st, $op ] = $api( 'POST', 'registro/nuevo', $datosRita );
$rita        = (int) $wpdb->get_var( "SELECT id FROM " . Tablas::nombre( 'trabajadores' ) . " WHERE dni = '71234567'" ); // phpcs:ignore
$comprobar( 'persona nueva: queda pendiente, con su cumpleaños y el aviso aceptado', 200 === $st && '' !== $op['flujo'] && 'pendiente' === Trabajadores::buscar( $rita )['estado'] && '03-07' === Trabajadores::buscar( $rita )['cumpleanos'] && $avisoV === (int) Trabajadores::buscar( $rita )['aviso_version'], wp_json_encode( array( $st, $op['code'] ?? '' ) ) );
$celRita    = new AutenticadorSimulado( Passkeys::rpId(), Passkeys::origen() );
[ $st, $r ] = $api( 'POST', 'registro/completar', array( 'flujo' => $op['flujo'], 'reto' => $op['reto'], 'credencial' => $celRita->crear( $op['publicKey']['challenge'] ) ) );
$solRita    = $CL::pendienteDe( $rita );
$comprobar( 'crea su llave y la solicitud queda para gerencia', 200 === $st && 'registro' === $r['tipo'] && $solRita && 'registro' === $solRita['tipo'], wp_json_encode( $r ) );
[ $st, $r ] = $entrarCon( $celRita );
$comprobar( 'hasta que gerencia apruebe, no entra', 403 === $st && 'pka_registro_pendiente' === $r['code'], wp_json_encode( $r ) );
$comprobar( 'gerencia aprueba y queda activa', true === $CL::aprobar( (int) $solRita['id'] ) && 'activo' === Trabajadores::buscar( $rita )['estado'] );
[ $st ] = $entrarCon( $celRita );
$comprobar( 'ya entra con su huella', 200 === $st );

// PIN (solo para entrar, en su celular).
[ $st, $r ] = $api( 'POST', 'yo/pin', array( 'pin' => '123456' ) );
$comprobar( 'un PIN en escalera no sirve', 422 === $st, wp_json_encode( $r ) );
[ $st, $r ] = $api( 'POST', 'yo/pin', array( 'pin' => '482915' ) );
$comprobar( 'crea su PIN (guardado con hash)', 200 === $st && password_verify( '482915', (string) Trabajadores::buscar( $rita )['pin_hash'] ) );
$api( 'POST', 'yo/salir' );
[ , $pe ]   = $api( 'GET', 'pin/estado' );
$comprobar( 'en su celular se ofrece entrar con PIN', true === $pe['pin'] && true === $pe['registrado'] && 'Rita' === $pe['nombre'], wp_json_encode( $pe ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.20'; // El PIN sirve desde cualquier lugar.
[ $st, $r ] = $api( 'POST', 'pin/entrar', array( 'pin' => '482916' ) );
$comprobar( 'PIN equivocado: dice cuántos intentos quedan', 401 === $st && 4 === ( $r['data']['intentos'] ?? -1 ), wp_json_encode( $r ) );
[ $st ]  = $api( 'POST', 'pin/entrar', array( 'pin' => '482915' ) );
[ $st2 ] = $api( 'GET', 'yo' );
$comprobar( 'con el PIN correcto entra (desde fuera del taller)', 200 === $st && 200 === $st2 );
[ $st, $r ] = $api( 'POST', 'marcas', array( 'accion' => 'entrada' ) );
$comprobar( 'el PIN no sirve para marcar', 401 === $st && 'pka_ticket' === $r['code'] );
$cookiesRita = $_COOKIE;
$_COOKIE     = array();
[ , $pe ]   = $api( 'GET', 'pin/estado' );
[ $st ]     = $api( 'POST', 'pin/entrar', array( 'pin' => '482915' ) );
$comprobar( 'en otro celular el PIN no sirve', false === $pe['pin'] && 403 === $st );

// Cambio de celular, desde el celular nuevo y en el taller.
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
[ $st, $r ] = $api( 'POST', 'registro/buscar', array( 'texto' => 'Rit' ) );
$comprobar( 'buscar mi perfil: solo nombres', 200 === $st && in_array( 'Rita Nueva', array_column( $r['perfiles'], 'nombre' ), true ) && ! str_contains( wp_json_encode( $r ), '71234567' ), wp_json_encode( $r ) );
[ $st, $r ] = $api( 'POST', 'registro/confirmar', array( 'trabajador_id' => $rita, 'dni' => '71234567', 'cumple_dia' => 8, 'cumple_mes' => 3 ) );
$comprobar( 'DNI o cumpleaños equivocado: quedan 4 intentos', 401 === $st && 4 === ( $r['data']['intentos'] ?? -1 ), wp_json_encode( $r ) );
[ $st, $r ] = $api( 'POST', 'registro/confirmar', array( 'trabajador_id' => $id2, 'dni' => '40404040', 'cumple_dia' => 1, 'cumple_mes' => 1 ) );
$comprobar( 'sin cumpleaños en el perfil no se puede confirmar', 409 === $st && 'pka_sin_cumple' === $r['code'] );
[ $st, $op ] = $api( 'POST', 'registro/confirmar', array( 'trabajador_id' => $rita, 'dni' => '71.234.567', 'cumple_dia' => 7, 'cumple_mes' => 3 ) );
$celRita2    = new AutenticadorSimulado( Passkeys::rpId(), Passkeys::origen() );
[ $st, $r ]  = $api( 'POST', 'registro/completar', array( 'flujo' => $op['flujo'], 'reto' => $op['reto'], 'credencial' => $celRita2->crear( $op['publicKey']['challenge'] ) ) );
$solCambio   = $CL::pendienteDe( $rita );
$comprobar( 'confirma y registra el celular nuevo: cambio pedido', 200 === $st && 'cambio' === $r['tipo'] && 'cambio' === ( $solCambio['tipo'] ?? '' ) );
[ $st, $r ] = $entrarCon( $celRita2 );
[ $stV ]    = $entrarCon( $celRita );
$comprobar( 'mientras gerencia decide: el nuevo espera y el anterior sigue', 403 === $st && 'pka_cambio_pendiente' === $r['code'] && 200 === $stV, wp_json_encode( $r ) );
$CL::aprobar( (int) $solCambio['id'] );
[ $stV ] = $entrarCon( $celRita );
[ $st ]  = $entrarCon( $celRita2 );
$comprobar( 'al aprobar: el nuevo entra y el anterior queda de baja', 200 === $st && 401 === $stV );
$comprobar( 'sus datos y su PIN se conservan', '' !== (string) Trabajadores::buscar( $rita )['pin_hash'] && 'activo' === Trabajadores::buscar( $rita )['estado'] );

// Liberar este celular (T0D) y aprobación de gerencia.
[ $st, $r ] = $api( 'POST', 'celular/liberar' );
$solLib     = $CL::pendienteDe( $rita );
$comprobar( 'pide liberar este celular', 200 === $st && 'liberar' === ( $solLib['tipo'] ?? '' ), wp_json_encode( $r ) );
$CL::aprobar( (int) $solLib['id'] );
[ $st ] = $entrarCon( $celRita2 );
$comprobar( 'liberado: ese celular ya no entra', 401 === $st );

// Registro rechazado: se puede rehacer.
$_COOKIE     = array();
$datosRo     = array( 'nombres' => 'Rocío', 'apellidos' => 'Rechazo', 'dni' => '72345678', 'telefono' => '987222333' ) + $datosRita;
[ , $op ]    = $api( 'POST', 'registro/nuevo', $datosRo );
$celRo       = new AutenticadorSimulado( Passkeys::rpId(), Passkeys::origen() );
$api( 'POST', 'registro/completar', array( 'flujo' => $op['flujo'], 'reto' => $op['reto'], 'credencial' => $celRo->crear( $op['publicKey']['challenge'] ) ) );
$rocio       = (int) $wpdb->get_var( "SELECT id FROM " . Tablas::nombre( 'trabajadores' ) . " WHERE dni = '72345678'" ); // phpcs:ignore
$sinMotivo   = $CL::rechazar( (int) $CL::pendienteDe( $rocio )['id'], '' );
$CL::rechazar( (int) $CL::pendienteDe( $rocio )['id'], 'No trabaja aquí' );
[ $st ]      = $entrarCon( $celRo );
$estadoRo   = Trabajadores::buscar( $rocio )['estado'];
$_COOKIE     = array();
[ $st2 ]     = $api( 'POST', 'registro/nuevo', $datosRo );
$comprobar( 'rechazar pide motivo; rechazado no entra y puede volver a registrarse', is_wp_error( $sinMotivo ) && 'rechazado' === $estadoRo && 401 === $st && 200 === $st2 && 'pendiente' === Trabajadores::buscar( $rocio )['estado'] );
$hist = \Pekokis\Asistencia\WordPress\Historial::buscar( array( 'trabajador' => $rita ) )[0];
$comprobar( 'todo queda en el historial', 6 <= count( array_filter( $hist, static fn( $f ) => str_starts_with( $f['accion'], 'celular.' ) || str_starts_with( $f['accion'], 'trabajador.pin' ) || 'trabajador.registrarse' === $f['accion'] ) ) );
Trabajadores::cesar( $rita, gmdate( 'Y-m-d' ), 'Prueba' );

// ---- 16. Panel de gerencia (maquetas G1, G3, G6B, G17).
WP_CLI::line( '16. Panel de gerencia' );
wp_set_current_user( 1 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$CO = \Pekokis\Asistencia\WordPress\Correcciones::class;

// G6B: aprobar con otra hora.
$g6  = Trabajadores::guardar( array( 'dni' => '70000016', 'nombres' => 'Gema', 'apellidos' => 'Seis', 'horario_id' => $horario, 'correo' => 'gema@example.test' ) );
$entrar( $g6, $ayerN, '09:00' );
$sol = $CO::solicitar( $g6, $ayerN, '19:30', \Pekokis\Asistencia\Nucleo\Accion::FIN_JORNADA, null, 'Olvidé marcar' );
$r   = $CO::aprobar( (int) $sol, '', '18:45' );
$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT estado, marcado_utc, hora_pedida_utc, nota_resolucion FROM ' . Tablas::nombre( 'correcciones' ) . ' WHERE id = %d', (int) $sol ), ARRAY_A ); // phpcs:ignore
$comprobar( 'aprobar con otra hora: guarda la final y la pedida', true === $r && 'aprobada' === $fila['estado'] && '18:45' === \Pekokis\Asistencia\WordPress\Ajustes::local( $fila['marcado_utc'], 'H:i' ) && '19:30' === \Pekokis\Asistencia\WordPress\Ajustes::local( $fila['hora_pedida_utc'], 'H:i' ) && str_contains( $fila['nota_resolucion'], 'pediste 19:30' ), wp_json_encode( $fila ) );
$salidaG6 = array_values( array_filter( \Pekokis\Asistencia\WordPress\Marcas::delDia( $g6, $ayerN ), static fn( $m ) => \Pekokis\Asistencia\Nucleo\TipoMarca::SALIDA === $m->tipo ) );
$comprobar( 'y la marca queda con la hora final', 1 === count( $salidaG6 ) && '18:45' === \Pekokis\Asistencia\WordPress\Marcas::horaLocal( $salidaG6[0]->marcadoUtc ) );

// G17: delegar permisos (crea su acceso al panel con un correo para elegir contraseña).
$DE      = \Pekokis\Asistencia\WordPress\Admin\PantallaDelegar::class;
$C       = \Pekokis\Asistencia\WordPress\Capacidades::class;
$antesCorreos = count( $correos );
$sinCorreo    = $DE::delegar( $g6, array( 'corregir' ), 'no-es-correo' );
$r            = $DE::delegar( $g6, array( 'corregir', 'sueldos' ), 'gema@example.test' );
$uG6          = get_userdata( (int) Trabajadores::buscar( $g6 )['user_id'] );
$comprobar( 'delegar crea su acceso (rol delegado) y le manda el correo para elegir contraseña', is_wp_error( $sinCorreo ) && true === $r && $uG6 && in_array( $C::ROL_DELEGADO, $uG6->roles, true ) && count( $correos ) > $antesCorreos );
$comprobar( 'tiene solo lo que se encendió', user_can( $uG6, $C::APROBAR ) && user_can( $uG6, $C::MARCA_MANUAL ) && user_can( $uG6, $C::VER_SUELDOS ) && ! user_can( $uG6, $C::VER_TODO ) && ! user_can( $uG6, $C::AJUSTES ) && ! user_can( $uG6, $C::RECIBOS ) );
$comprobar( 'entra al panel con el tema y sin lo de WordPress', \Pekokis\Asistencia\WordPress\Admin\Tema::soloAsistencia( $uG6 ) && $DE::esDelegado( $uG6->ID ) );
$DE::delegar( $g6, array( 'asistencia', 'horarios' ) );
$uG6 = get_userdata( $uG6->ID );
$comprobar( 'cambiar permisos quita los que se apagan', user_can( $uG6, $C::VER_TODO ) && user_can( $uG6, $C::HORARIOS ) && ! user_can( $uG6, $C::APROBAR ) && ! user_can( $uG6, $C::VER_SUELDOS ) );
$DE::delegar( $g6, array() );
$uG6 = get_userdata( $uG6->ID );
$comprobar( 'quitar todos lo deja sin permisos', ! user_can( $uG6, $C::VER_EQUIPO ) && ! user_can( $uG6, $C::HORARIOS ) && ! $DE::esDelegado( $uG6->ID ) && 3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'auditoria' ) . " WHERE accion = 'permiso.delegar' AND entidad_id = %d", $g6 ) ) ); // phpcs:ignore
$comprobar( 'RR.HH. tiene los permisos nuevos (horarios y recibos)', get_role( $C::ROL_RRHH )->has_cap( $C::HORARIOS ) && get_role( $C::ROL_RRHH )->has_cap( $C::RECIBOS ) );

// G1, G6, G3: las pantallas se dibujan sin errores.
$dibujar = static function ( callable $f ): string {
	ob_start();
	$f();
	return (string) ob_get_clean();
};
$htmlDash = $dibujar( array( \Pekokis\Asistencia\WordPress\Admin\PantallaDashboard::class, 'pintar' ) );
$comprobar( 'Dashboard: cifras, gráfico y "Requiere tu atención"', str_contains( $htmlDash, 'En el taller hoy' ) && str_contains( $htmlDash, 'Horas por día' ) && str_contains( $htmlDash, 'Requiere tu atención' ) && str_contains( $htmlDash, 'Por pagar de' ) );
$htmlSol = $dibujar( array( \Pekokis\Asistencia\WordPress\Admin\PantallaSolicitudes::class, 'pintar' ) );
$comprobar( 'Solicitudes: bandeja con la hora final para aprobar con otra hora', str_contains( $htmlSol, 'Solicitudes "olvidé marcar"' ) && str_contains( $htmlSol, 'Marcas por revisar' ) );
$fichaOk = true;
foreach ( array( 'resumen', 'asistencia', 'pagos', 'celular', 'cambios' ) as $pest ) {
	$_GET['pestana'] = $pest;
	$html            = $dibujar( static fn() => \Pekokis\Asistencia\WordPress\Admin\PantallaFicha::pintar( Trabajadores::buscar( $id2 ) ) );
	$fichaOk         = $fichaOk && str_contains( $html, 'Ficha de Luis' ) && str_contains( $html, 'pk-pestanas' );
}
unset( $_GET['pestana'] );
$comprobar( 'Ficha: las 5 pestañas se dibujan', $fichaOk );
$comprobar( 'la ficha oculta el DNI', ! str_contains( $dibujar( static fn() => \Pekokis\Asistencia\WordPress\Admin\PantallaFicha::pintar( Trabajadores::buscar( $id2 ) ) ), '40404040' ) );
Trabajadores::cesar( $g6, gmdate( 'Y-m-d' ), 'Prueba' );
wp_delete_user( $uG6->ID );

// ---- 17. Registros y revisión (G4, G4B) y Sueldos y tarifas (G8).
WP_CLI::line( '17. Registros y sueldos' );
wp_set_current_user( 1 );
$PD  = \Pekokis\Asistencia\Nucleo\PasosDelDia::class;
$reg = Trabajadores::guardar( array( 'dni' => '70000017', 'nombres' => 'Rebeca', 'apellidos' => 'Registro', 'horario_id' => $horario, 'fecha_ingreso' => '2026-01-05' ) );
$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'creado_en' => '2026-01-05 12:00:00' ), array( 'id' => $reg ) ); // Ya trabajaba ayer.
$entrar( $reg, $ayerN, '09:00' );
$horaPaso = static fn( string $paso ): string => ( $p = $PD::de( \Pekokis\Asistencia\WordPress\Marcas::delDia( $reg, $ayerN ) )[ $paso ] ) ? \Pekokis\Asistencia\WordPress\Marcas::horaLocal( $p->marcadoUtc ) : '';

$sinMotivo = $CO::editarDia( $reg, $ayerN, array( 'salida' => '19:30' ), '' );
$r         = $CO::editarDia( $reg, $ayerN, array( 'inicio_comida' => '13:00', 'fin_comida' => '14:00', 'salida' => '19:30' ), 'Olvidó marcar; lo confirmó su jefe' );
$comprobar( 'editar el día: sin motivo no; con motivo completa los 4 pasos de una vez', is_wp_error( $sinMotivo ) && true === $r && '13:00' === $horaPaso( 'inicio_comida' ) && '14:00' === $horaPaso( 'fin_comida' ) && '19:30' === $horaPaso( 'salida' ) && 4 === count( \Pekokis\Asistencia\WordPress\Marcas::delDia( $reg, $ayerN ) ) );
$audDia = $wpdb->get_row( $wpdb->prepare( 'SELECT antes, despues, motivo FROM ' . Tablas::nombre( 'auditoria' ) . " WHERE accion = 'marca.editar_dia' AND entidad_id = %d ORDER BY id DESC LIMIT 1", $reg ), ARRAY_A ); // phpcs:ignore
$comprobar( 'queda una sola entrada en la auditoría con antes, después y motivo', $audDia && str_contains( $audDia['antes'], 'sin marca' ) && str_contains( $audDia['despues'], '19:30' ) && str_contains( $audDia['motivo'], 'Olvidó' ), wp_json_encode( $audDia ) );
$r = $CO::editarDia( $reg, $ayerN, array( 'entrada' => '09:10' ), 'Llegó 09:10 según la cámara' );
$comprobar( 'cambiar una hora anula la marca vieja y pone la nueva', true === $r && '09:10' === $horaPaso( 'entrada' ) && 4 === count( \Pekokis\Asistencia\WordPress\Marcas::delDia( $reg, $ayerN ) ) && 1 <= (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'correcciones' ) . " WHERE trabajador_id = %d AND accion = 'anular'", $reg ) ) ); // phpcs:ignore
$malo = $CO::editarDia( $reg, $ayerN, array( 'fin_comida' => '' ), 'Prueba' );
$comprobar( 'si el día queda mal, no se toca nada', is_wp_error( $malo ) && 'pka_secuencia' === $malo->get_error_code() && '14:00' === $horaPaso( 'fin_comida' ), is_wp_error( $malo ) ? $malo->get_error_message() : '' );
$igual = $CO::editarDia( $reg, $ayerN, array( 'entrada' => '09:10' ), 'Nada' );
$comprobar( 'sin cambios avisa', is_wp_error( $igual ) && 'pka_sin_cambios' === $igual->get_error_code() );
$r = $CO::editarDia( $reg, $ayerN, array( 'salida' => '01:12' ), 'Se quedó de madrugada' );
$salidaReg = $PD::de( \Pekokis\Asistencia\WordPress\Marcas::delDia( $reg, $ayerN ) )['salida'];
$comprobar( 'una hora menor que la entrada es del día siguiente y sigue en su jornada', true === $r && $salidaReg && $salidaReg->marcadoUtc->setTimezone( new DateTimeZone( 'America/Lima' ) )->format( 'Y-m-d H:i' ) === ( new DateTimeImmutable( $ayerN ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 01:12' );

$_GET     = array( 'page' => 'pka-registros', 'persona' => $reg, 'desde' => $ayerN, 'hasta' => $ayerN );
$htmlReg  = $dibujar( array( \Pekokis\Asistencia\WordPress\Admin\PantallaRegistros::class, 'pintar' ) );
$_GET     = array( 'page' => 'pka-registros', 'persona' => $reg, 'desde' => $ayerN, 'hasta' => $ayerN, 'editar' => $reg, 'dia' => $ayerN );
$htmlPan  = $dibujar( array( \Pekokis\Asistencia\WordPress\Admin\PantallaRegistros::class, 'pintar' ) );
$_GET     = array();
$comprobar( 'Registros: la fila con "Pasó de medianoche" y "Editado", y el panel con el valor anterior', str_contains( $htmlReg, 'Rebeca Registro' ) && str_contains( $htmlReg, 'Pasó de medianoche' ) && str_contains( $htmlReg, 'Editado' ) && str_contains( $htmlPan, 'Editar registro' ) && str_contains( $htmlPan, 'Antes: 09:10' ), wp_json_encode( array( str_contains( $htmlReg, 'Rebeca Registro' ), str_contains( $htmlReg, 'Pasó de medianoche' ), str_contains( $htmlReg, 'Editado' ), str_contains( $htmlPan, 'Editar registro' ), str_contains( $htmlPan, 'Antes: 09:10' ), mb_substr( wp_strip_all_tags( $htmlReg ), 0, 600 ) ) ) );

// G8: varias personas a la vez; solo las que cambian.
$SU  = \Pekokis\Asistencia\WordPress\Admin\PantallaSueldos::class;
$hoyL = wp_date( 'Y-m-d', null, new DateTimeZone( 'America/Lima' ) );
$vac  = array( 'honorario' => '', 'tarifa_extra' => '', 'tarifa_sabado' => '', 'tarifa_domingo' => '', 'tarifa_feriado' => '' );
$nuevo = array( 'honorario' => '1500', 'tarifa_extra' => '10', 'tarifa_sabado' => '12', 'tarifa_domingo' => '20', 'tarifa_feriado' => '20' );
$r1 = $SU::aplicar( array( $reg => $nuevo, $g6 ?? 0 => $vac ), array( $reg => $vac, $g6 ?? 0 => $vac ), $hoyL, 'Alta' );
$comprobar( 'Sueldos en lote: guarda solo las filas que cambian', 1 === $r1['guardados'] && ! $r1['errores'] && 150000 === \Pekokis\Asistencia\WordPress\Sueldos::vigente( $reg )->honorarioCentimos, wp_json_encode( $r1 ) );
$r2 = $SU::aplicar( array( $reg => array( 'honorario' => '1600' ) + $nuevo ), array( $reg => $nuevo ), $hoyL, 'Aumento' );
$comprobar( 'el error se dice por persona (un cambio el mismo día del vigente no vale)', 0 === $r2['guardados'] && isset( $r2['errores'][ $reg ] ), wp_json_encode( $r2 ) );
$r3 = $SU::aplicar( array( $reg => array( 'honorario' => '1,600.00' ) + $nuevo ), array( $reg => $nuevo ), ( new DateTimeImmutable( $hoyL ) )->modify( '+1 day' )->format( 'Y-m-d' ), 'Aumento' );
$comprobar( 'y con una fecha posterior se guarda (el anterior queda en el historial)', 1 === $r3['guardados'] && 2 === count( \Pekokis\Asistencia\WordPress\Sueldos::historial( $reg ) ), wp_json_encode( $r3 ) );
$htmlSu = $dibujar( array( $SU, 'pintar' ) );
$comprobar( 'Sueldos y tarifas se dibuja con el contador de cambios', str_contains( $htmlSu, 'Rebeca Registro' ) && str_contains( $htmlSu, 'pk-sin-guardar' ) && str_contains( $htmlSu, 'data-antes="1500.00"' ) );
Trabajadores::cesar( $reg, gmdate( 'Y-m-d' ), 'Prueba' );

// ---- 18. G2, G14, G0 y gerencia en el celular (M1–M9).
WP_CLI::line( '18. Filtros de hoy, ajustes en pestañas, acceso de gerencia y celular' );
wp_set_current_user( 1 );
$_GET    = array( 'page' => 'pka-asistencia', 'filtro' => 'ausente' );
$htmlHoy = $dibujar( array( \Pekokis\Asistencia\WordPress\Admin\PantallaHoy::class, 'pintar' ) );
$_GET    = array();
$comprobar( 'G2: Asistencia de hoy con pestañas de filtro (Todos y el filtro elegido)', str_contains( $htmlHoy, 'pk-filtros' ) && str_contains( $htmlHoy, 'Todos <span class="pk-cuenta">' ) && ( str_contains( $htmlHoy, 'aria-current="page">Sin marcar' ) || str_contains( $htmlHoy, 'Nadie en esta situación' ) ) );
$comprobar( 'G2: datos() trae filas y cuentas por situación', is_array( \Pekokis\Asistencia\WordPress\Admin\PantallaHoy::datos()['cuentas'] ) );

$_GET     = array( 'page' => 'pka-ajustes', 'pestana' => 'reglas' );
$htmlAju  = $dibujar( array( \Pekokis\Asistencia\WordPress\Admin\PantallaAjustes::class, 'pintar' ) );
$_GET     = array( 'page' => 'pka-ajustes' );
$htmlAju2 = $dibujar( array( \Pekokis\Asistencia\WordPress\Admin\PantallaAjustes::class, 'pintar' ) );
$_GET     = array();
$comprobar( 'G14: Seguridad y reglas en pestañas, cada una con lo suyo y los cambios contados', str_contains( $htmlAju, 'Reglas de horas y pagos' ) && ! str_contains( $htmlAju, 'Redes del WiFi del taller' ) && str_contains( $htmlAju, 'class="pk-sigue"' ) && str_contains( $htmlAju, 'pk-sin-guardar' ) && str_contains( $htmlAju2, 'Redes del WiFi del taller' ) && ! str_contains( $htmlAju2, 'Reglas de horas y pagos' ) && ! str_contains( $htmlAju . $htmlAju2, '<h class=' ) );

// G0: llave de gerencia con el autenticador simulado.
$AG     = \Pekokis\Asistencia\WordPress\AccesoGerencia::class;
$laptop = new AutenticadorSimulado( Passkeys::rpId(), Passkeys::origen() );
[ $s1, $op ] = $api( 'POST', 'gerencia/llave/opciones' );
[ $s2, $ok ] = $api( 'POST', 'gerencia/llave', array( 'reto' => $op['reto'] ?? '', 'credencial' => $laptop->crear( (string) ( $op['publicKey']['challenge'] ?? '' ) ) ) );
$comprobar( 'G0: activar huella o rostro desde el perfil (queda una llave del usuario)', 200 === $s1 && 200 === $s2 && 1 === count( $AG::llavesDe( 1 ) ) && 'none' === ( $op['publicKey']['attestation'] ?? '' ), wp_json_encode( array( $s1, $s2, $ok ) ) );
wp_set_current_user( 0 );
[ $s3 ]      = $api( 'POST', 'gerencia/llave/opciones' );
[ , $oe ]    = $api( 'POST', 'gerencia/entrar/opciones' );
[ $s4, $en ] = $api( 'POST', 'gerencia/entrar', array( 'reto' => $oe['reto'] ?? '', 'redirigir' => admin_url( 'admin.php?page=pka-registros' ), 'credencial' => $laptop->firmar( (string) ( $oe['publicKey']['challenge'] ?? '' ) ) ) );
$comprobar( 'G0: sin sesión no se activan llaves; con la llave se entra y va a lo pedido', 401 === $s3 && 200 === $s4 && 1 === get_current_user_id() && str_contains( (string) ( $en['ir'] ?? '' ), 'page=pka-registros' ), wp_json_encode( array( $s3, $s4, $en, get_current_user_id() ) ) );
wp_set_current_user( 0 );
[ , $oe2 ]   = $api( 'POST', 'gerencia/entrar/opciones' );
[ $s5, $e5 ] = $api( 'POST', 'gerencia/entrar', array( 'reto' => $oe2['reto'] ?? '', 'credencial' => $celular->firmar( (string) ( $oe2['publicKey']['challenge'] ?? '' ) ) ) );
$comprobar( 'G0: la llave de un trabajador no abre el panel', 401 === $s5 && 'pka_llave' === ( $e5['code'] ?? '' ) && 0 === get_current_user_id(), wp_json_encode( $e5 ) );
\Pekokis\Asistencia\WordPress\Temporales::limpiarFallos( 'login|' . \Pekokis\Asistencia\WordPress\Peticion::ip() );
wp_set_current_user( 1 );
[ $s6 ]    = $api( 'POST', 'gerencia/llave/quitar', array( 'id' => (int) ( $AG::llavesDe( 1 )[0]['id'] ?? 0 ) ) );
wp_set_current_user( 0 );
[ , $oe3 ] = $api( 'POST', 'gerencia/entrar/opciones' );
[ $s7 ]    = $api( 'POST', 'gerencia/entrar', array( 'reto' => $oe3['reto'] ?? '', 'credencial' => $laptop->firmar( (string) ( $oe3['publicKey']['challenge'] ?? '' ) ) ) );
$comprobar( 'G0: una llave quitada ya no entra', 200 === $s6 && 401 === $s7 && ! $AG::llavesDe( 1 ) );
\Pekokis\Asistencia\WordPress\Temporales::limpiarFallos( 'login|' . \Pekokis\Asistencia\WordPress\Peticion::ip() );
wp_set_current_user( 1 );
$comprobar( 'G0: a dónde va gerencia al entrar (lo pedido si es del sitio; si no, el panel)', admin_url() === $AG::destino( get_userdata( 1 ), 'https://otro.ejemplo/x' ) && admin_url( 'admin.php?page=pka-pagos' ) === $AG::destino( get_userdata( 1 ), admin_url( 'admin.php?page=pka-pagos' ) ) );

// M1–M9: cada vista del celular se dibuja.
$GM      = \Pekokis\Asistencia\WordPress\GerenciaMovil::class;
$vistaGM = static function ( string $v, array $get = array() ) use ( $GM ): array {
	$_GET = $get;
	$r    = $GM::vista( $v );
	$_GET = array();
	return $r;
};
[ $tIni, $hIni ] = $vistaGM( 'inicio' );
[ , $hHoyM ]     = $vistaGM( 'hoy' );
[ , $hSolM ]     = $vistaGM( 'solicitudes' );
[ $tPag, $hPag ] = $vistaGM( 'pagos' );
[ , $hRec ]      = $vistaGM( 'recibos' );
[ , $hMas ]      = $vistaGM( 'mas' );
$unaPersona      = (int) ( Trabajadores::listar()[0]['id'] ?? 0 );
[ $tPer, $hPer ] = $vistaGM( 'persona', array( 't' => $unaPersona ) );
$comprobar( 'M1: Inicio con las cuatro cifras, el pago y "Requiere tu atención"', 'Inicio' === $tIni && str_contains( $hIni, 'En el taller hoy' ) && str_contains( $hIni, 'Por pagar de' ) && str_contains( $hIni, 'Requiere tu atención' ) );
$comprobar( 'M2: Asistencia de hoy con filtros y una tarjeta por persona', str_contains( $hHoyM, 'gm-chips' ) && ( str_contains( $hHoyM, 'gm-persona' ) || str_contains( $hHoyM, 'gm-vacio' ) ) );
$comprobar( 'M3: Solicitudes (o "nada por decidir")', str_contains( $hSolM, 'gm-sol' ) || str_contains( $hSolM, 'No hay nada por decidir' ) );
$comprobar( 'M5/M7/M9: Pagos, Recibos y Más', str_starts_with( $tPag, 'Pago de ' ) && str_contains( $hPag, 'Antes de pagar' ) && str_contains( $hRec, 'gm-chips' ) && str_contains( $hMas, 'Seguridad y reglas' ) && str_contains( $hMas, 'Cerrar sesión' ) );
$comprobar( 'M8: Asistencia de una persona con calendario y detalle del día', '' !== $tPer && str_contains( $hPer, 'pk-cal' ) && str_contains( $hPer, 'gm-dia' ) );
$pend = \Pekokis\Asistencia\WordPress\Correcciones::pendientes( null );
if ( $pend ) {
	[ , $hM4 ] = $vistaGM( 'solicitudes', array( 'abrir' => 'c' . (int) $pend[0]['id'] ) );
	$comprobar( 'M4: Resolver una solicitud con las tres opciones y vuelta al celular', str_contains( $hM4, 'Aprobar tal como se pidió' ) && str_contains( $hM4, 'value="otra"' ) && str_contains( $hM4, 'name="pk_volver"' ) );
}
$comprobar( 'El celular tiene su propia URL (/gerencia/?v=…)', str_ends_with( $GM::url(), '/gerencia/' ) && str_contains( $GM::url( 'hoy', array( 'f' => 'ausente' ) ), 'v=hoy' ) );

$comprobar( 'presencia por IP del taller', ( $_SERVER['REMOTE_ADDR'] = '203.0.113.10' ) && Presencia::enRedDelTaller() );

// Deja los ajustes como estaban (la prueba cambia el aviso y el webhook).
update_option( \Pekokis\Asistencia\WordPress\Ajustes::OPCION, is_array( $ajustesPrueba ) ? $ajustesPrueba : array() );

if ( $fallos ) {
	WP_CLI::error( $fallos . ' comprobaciones fallaron.' );
}

WP_CLI::success( 'Todo el flujo funciona.' );
