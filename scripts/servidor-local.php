<?php
/**
 * Router para servir el WordPress local con el servidor integrado de PHP
 * (solo para desarrollo; en Hostinger no se usa).
 *
 * Uso:
 *   php -S localhost:8080 -t C:/laragon/www/asistencia scripts/servidor-local.php
 *
 * Requiere en el wp-config.php LOCAL el bloque que fija WP_HOME/WP_SITEURL
 * a http://localhost:8080 cuando HTTP_HOST es "localhost:8080".
 * Variable de entorno opcional: PKA_WP_RAIZ (por defecto C:/laragon/www/asistencia).
 */

$raiz = rtrim( (string) ( getenv( 'PKA_WP_RAIZ' ) ?: 'C:/laragon/www/asistencia' ), '/\\' );
$ruta = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$real = realpath( $raiz . $ruta );

// Una carpeta (por ejemplo /wp-admin/) se sirve con su index.php, como haría Apache.
if ( $real && is_dir( $real ) && is_file( $real . DIRECTORY_SEPARATOR . 'index.php' ) ) {
	if ( ! str_ends_with( $ruta, '/' ) ) {
		header( 'Location: ' . $ruta . '/' . ( isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '' ), true, 301 );
		return true;
	}

	$real = $real . DIRECTORY_SEPARATOR . 'index.php';
	$ruta = rtrim( $ruta, '/' ) . '/index.php';
}

// Archivos estáticos (css, js, imágenes): los sirve PHP directamente.
if ( $real && is_file( $real ) && ! str_ends_with( $real, '.php' ) ) {
	return false;
}

// Un archivo PHP real (wp-login.php, wp-admin/admin.php, …).
if ( $real && is_file( $real ) ) {
	chdir( dirname( $real ) );
	$_SERVER['SCRIPT_NAME']     = $ruta;
	$_SERVER['SCRIPT_FILENAME'] = $real;
	$_SERVER['PHP_SELF']        = $ruta;
	require $real;
	return true;
}

// Todo lo demás pasa por WordPress (/, /activar/, /pantalla/, /wp-json/…).
chdir( $raiz );
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $raiz . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
require $raiz . '/index.php';
return true;
