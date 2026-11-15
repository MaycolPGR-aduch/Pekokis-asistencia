<?php
/**
 * Página de la app del trabajador. La dibuja Rutas::despachar().
 *
 * @var array<string, string> $config
 */

use Pekokis\Asistencia\WordPress\Rutas;

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="#F048A5">
	<title>Asistencia Pekokis</title>
	<link rel="manifest" href="<?php echo esc_url( home_url( '/manifest.webmanifest' ) ); ?>">
	<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( Rutas::asset( 'iconos/icono-192.png' ) ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( Rutas::asset( 'iconos/apple-touch-icon.png' ) ); ?>">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="Asistencia">
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
	<link rel="preload" href="<?php echo esc_url( Rutas::asset( 'fuentes/poligon-600.otf' ) ); ?>" as="font" type="font/otf" crossorigin>
	<link rel="stylesheet" href="<?php echo esc_url( Rutas::asset( 'app.css' ) ); ?>">
	<script type="application/json" id="pka-config"><?php echo wp_json_encode( $config ); ?></script>
	<script src="<?php echo esc_url( Rutas::asset( 'app.js' ) ); ?>" defer></script>
</head>
<body class="vista-<?php echo esc_attr( $config['vista'] ); ?>">
	<div class="app" id="app">
		<header class="barra">
			<img class="logo" src="<?php echo esc_url( Rutas::asset( 'logo-pekokis.png' ) ); ?>" alt="Pekokis" width="98" height="26">
			<span class="quien" id="quien" hidden></span>
		</header>
		<main class="cuerpo" id="contenido" aria-live="polite">
			<p class="cargando">Cargando…</p>
		</main>
		<?php if ( 'pantalla' !== $config['vista'] ) : ?>
			<p class="pie-legal"><a href="<?php echo esc_url( home_url( '/privacidad/' ) ); ?>">Aviso de datos personales</a></p>
		<?php endif; ?>
		<nav class="tabs" id="pestanas" aria-label="Secciones" hidden></nav>
	</div>
	<noscript>Activa JavaScript para usar la asistencia.</noscript>
</body>
</html>
