<?php
/**
 * Tema vacío: el plugin Pekokis Asistencia dibuja todas las pantallas.
 * Si una petición llega hasta aquí, esa ruta no existe.
 */

defined( 'ABSPATH' ) || exit;

status_header( 404 );
nocache_headers();
?><!doctype html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Pekokis Asistencia</title>
</head>
<body></body>
</html>
