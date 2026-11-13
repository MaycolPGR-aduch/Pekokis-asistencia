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
	<link rel="stylesheet" href="<?php echo esc_url( Rutas::asset( 'app.css' ) ); ?>">
	<script type="application/json" id="pka-config"><?php echo wp_json_encode( $config ); ?></script>
	<script src="<?php echo esc_url( Rutas::asset( 'app.js' ) ); ?>" defer></script>
</head>
<body>
	<main class="app" id="app" aria-live="polite">
		<header class="cabecera">
			<span class="marca">Pekokis</span>
			<span class="reloj" id="reloj"></span>
		</header>
		<section class="contenido" id="contenido">
			<p class="cargando">Cargando…</p>
		</section>
		<p class="presencia" id="presencia" hidden></p>
	</main>
	<noscript>Activa JavaScript para usar la asistencia.</noscript>
</body>
</html>
