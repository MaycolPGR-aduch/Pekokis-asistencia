<?php
/**
 * Genera los íconos de la app (PWA) en assets/iconos/ con GD:
 * fondo rosa Pekokis y un reloj blanco centrado.
 *
 * El reloj ocupa el 56 % del lienzo, dentro de la "zona segura" de los íconos
 * adaptables de Android (círculo del 80 %), así que el mismo PNG sirve como
 * ícono normal y como "maskable".
 *
 *   php scripts/generar-iconos.php
 */

$destino = dirname( __DIR__ ) . '/assets/iconos';

if ( ! is_dir( $destino ) ) {
	mkdir( $destino, 0777, true );
}

foreach ( array( 'icono-512.png' => 512, 'icono-192.png' => 192, 'apple-touch-icon.png' => 180 ) as $archivo => $lado ) {
	// Se dibuja 4 veces más grande y se reduce: así los bordes quedan suaves.
	$escala = 4;
	$g      = $lado * $escala;
	$img    = imagecreatetruecolor( $g, $g );

	$rosa   = imagecolorallocate( $img, 0xE6, 0x00, 0x7E );
	$blanco = imagecolorallocate( $img, 0xFF, 0xFF, 0xFF );
	$tinta  = imagecolorallocate( $img, 0x2B, 0x21, 0x40 );

	imagefill( $img, 0, 0, $rosa );

	$c       = $g / 2;
	$radio   = (int) round( $g * 0.28 );
	$borde   = (int) round( $g * 0.035 );

	// Esfera: círculo blanco con un aro de tinta.
	imagefilledellipse( $img, (int) $c, (int) $c, 2 * $radio, 2 * $radio, $tinta );
	imagefilledellipse( $img, (int) $c, (int) $c, 2 * ( $radio - $borde ), 2 * ( $radio - $borde ), $blanco );

	// Marcas de las 12, 3, 6 y 9.
	foreach ( array( 0, 90, 180, 270 ) as $angulo ) {
		$a  = deg2rad( $angulo - 90 );
		$r1 = $radio - $borde * 2.6;
		$r2 = $radio - $borde * 1.5;
		linea( $img, $c + cos( $a ) * $r1, $c + sin( $a ) * $r1, $c + cos( $a ) * $r2, $c + sin( $a ) * $r2, (int) round( $borde * 0.7 ), $tinta );
	}

	// Agujas: las 9:00, la hora de entrada.
	linea( $img, $c, $c, $c, $c - $radio * 0.62, (int) round( $borde * 0.9 ), $tinta ); // minutero en las 12
	linea( $img, $c, $c, $c - $radio * 0.45, $c, (int) round( $borde * 1.1 ), $rosa );  // horario en las 9
	imagefilledellipse( $img, (int) $c, (int) $c, (int) ( $borde * 2.2 ), (int) ( $borde * 2.2 ), $tinta );

	$final = imagecreatetruecolor( $lado, $lado );
	imagecopyresampled( $final, $img, 0, 0, 0, 0, $lado, $lado, $g, $g );
	imagepng( $final, $destino . '/' . $archivo, 9 );

	echo $archivo . ' (' . $lado . ' px)' . PHP_EOL;
}

/** Línea gruesa con puntas redondas. */
function linea( GdImage $img, float $x1, float $y1, float $x2, float $y2, int $grosor, int $color ): void {
	imagesetthickness( $img, $grosor );
	imageline( $img, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $color );
	imagesetthickness( $img, 1 );
	imagefilledellipse( $img, (int) $x1, (int) $y1, $grosor, $grosor, $color );
	imagefilledellipse( $img, (int) $x2, (int) $y2, $grosor, $grosor, $color );
}
