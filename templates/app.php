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
	<meta name="theme-color" content="#e6007e">
	<title>Asistencia Pekokis</title>
	<link rel="manifest" href="<?php echo esc_url( home_url( '/manifest.webmanifest' ) ); ?>">
	<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( Rutas::asset( 'iconos/icono-192.png' ) ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( Rutas::asset( 'iconos/apple-touch-icon.png' ) ); ?>">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="Asistencia">
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
	<link rel="stylesheet" href="<?php echo esc_url( Rutas::asset( 'app.css' ) ); ?>">
	<script type="application/json" id="pka-config"><?php echo wp_json_encode( $config ); ?></script>
	<script src="<?php echo esc_url( Rutas::asset( 'app.js' ) ); ?>" defer></script>
</head>
<body class="vista-<?php echo esc_attr( $config['vista'] ); ?>">
	<main class="app" id="app" aria-live="polite">
		<header class="cabecera">
			<span class="marca">Pekokis</span>
			<span class="reloj" id="reloj"></span>
		</header>
		<section class="contenido" id="contenido">
			<p class="cargando">Cargando…</p>
		</section>
		<p class="presencia" id="presencia" hidden></p>
		<?php if ( 'pantalla' !== $config['vista'] ) : ?>
			<p class="pie"><a href="<?php echo esc_url( home_url( '/privacidad/' ) ); ?>">Aviso de datos personales</a></p>
		<?php endif; ?>
	</main>
	<noscript>Activa JavaScript para usar la asistencia.</noscript>
</body>
</html>
