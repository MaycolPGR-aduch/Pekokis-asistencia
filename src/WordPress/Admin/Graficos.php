<?php

namespace Pekokis\Asistencia\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Gráficos de barras en SVG para el Resumen del mes, sin librerías, con el
 * color de la marca (los estilos están en componentes.css, .pk-viz).
 *
 * Cada gráfico mide una sola cosa (un eje, una serie, un color), así que no
 * lleva leyenda: el título dice qué es. Barras finas con la punta redondeada,
 * rejilla tenue, etiqueta directa solo en el dato que importa y un tooltip al
 * pasar el mouse o el dedo. La tabla de abajo es la vista de datos.
 */
final class Graficos {
	/**
	 * Columnas verticales (una por mes, de la más antigua a la más nueva).
	 *
	 * @param array<int, array{etiqueta: string, valor: float, texto: string, detalle: string}> $datos
	 */
	public static function columnas( string $titulo, array $datos, string $resaltar = '' ): string {
		$ancho  = 340;
		$alto   = 190;
		$izq    = 34;
		$der    = 8;
		$arriba = 22;
		$abajo  = 24;
		$plotW  = $ancho - $izq - $der;
		$plotH  = $alto - $arriba - $abajo;
		$max    = self::tope( max( array_merge( array( 0.0 ), array_column( $datos, 'valor' ) ) ) );
		$n      = max( 1, count( $datos ) );
		$paso   = $plotW / $n;
		$barra  = min( 28, $paso * 0.5 );
		$base   = $arriba + $plotH;

		$svg = '<svg class="pk-viz-svg" viewBox="0 0 ' . $ancho . ' ' . $alto . '" role="img" aria-label="' . esc_attr( $titulo ) . '">';

		foreach ( array( 0, 0.5, 1 ) as $f ) {
			$y    = $base - $f * $plotH;
			$svg .= '<line class="pk-viz-rejilla" x1="' . $izq . '" x2="' . ( $ancho - $der ) . '" y1="' . round( $y, 1 ) . '" y2="' . round( $y, 1 ) . '"/>';
			$svg .= '<text class="pk-viz-eje" x="' . ( $izq - 6 ) . '" y="' . round( $y + 4, 1 ) . '" text-anchor="end">' . esc_html( self::numero( $max * $f ) ) . '</text>';
		}

		foreach ( array_values( $datos ) as $i => $d ) {
			$cx   = $izq + $paso * ( $i + 0.5 );
			$h    = $max > 0 ? $d['valor'] / $max * $plotH : 0;
			$x    = $cx - $barra / 2;
			$svg .= '<g class="pk-viz-marca" tabindex="0" data-tip="' . esc_attr( $d['detalle'] ) . '">';
			$svg .= '<rect class="pk-viz-golpe" x="' . round( $cx - $paso / 2, 1 ) . '" y="' . $arriba . '" width="' . round( $paso, 1 ) . '" height="' . $plotH . '"/>';

			if ( $h > 0 ) {
				$svg .= '<path class="pk-viz-barra" d="' . self::barraVertical( $x, $base, $barra, $h ) . '"/>';
			}

			$svg .= '</g>';
			$svg .= '<text class="pk-viz-eje" x="' . round( $cx, 1 ) . '" y="' . ( $alto - 6 ) . '" text-anchor="middle">' . esc_html( $d['etiqueta'] ) . '</text>';

			if ( $d['etiqueta'] === $resaltar ) {
				$svg .= '<text class="pk-viz-valor" x="' . round( $cx, 1 ) . '" y="' . round( $base - $h - 6, 1 ) . '" text-anchor="middle">' . esc_html( $d['texto'] ) . '</text>';
			}
		}

		$svg .= '<line class="pk-viz-base" x1="' . $izq . '" x2="' . ( $ancho - $der ) . '" y1="' . $base . '" y2="' . $base . '"/></svg>';

		return self::marco( $titulo, $svg );
	}

	/**
	 * Barras horizontales (una por persona), ordenadas de mayor a menor.
	 *
	 * @param array<int, array{etiqueta: string, valor: float, texto: string, detalle: string}> $datos
	 */
	public static function barras( string $titulo, array $datos ): string {
		usort( $datos, static fn( $a, $b ) => $b['valor'] <=> $a['valor'] );

		$fila   = 26;
		$izq    = 150;
		$der    = 56;
		$ancho  = 560;
		$alto   = max( 1, count( $datos ) ) * $fila + 8;
		$plotW  = $ancho - $izq - $der;
		$max    = self::tope( max( array_merge( array( 0.0 ), array_column( $datos, 'valor' ) ) ) );
		$grosor = 14;

		$svg = '<svg class="pk-viz-svg" viewBox="0 0 ' . $ancho . ' ' . $alto . '" role="img" aria-label="' . esc_attr( $titulo ) . '">';
		$svg .= '<line class="pk-viz-base" x1="' . $izq . '" x2="' . $izq . '" y1="0" y2="' . ( $alto - 4 ) . '"/>';

		foreach ( array_values( $datos ) as $i => $d ) {
			$y    = $i * $fila + 4;
			$w    = $max > 0 ? $d['valor'] / $max * $plotW : 0;
			$cy   = $y + $fila / 2;
			$svg .= '<g class="pk-viz-marca" tabindex="0" data-tip="' . esc_attr( $d['detalle'] ) . '">';
			$svg .= '<rect class="pk-viz-golpe" x="0" y="' . $y . '" width="' . $ancho . '" height="' . $fila . '"/>';
			$svg .= '<text class="pk-viz-etiqueta" x="' . ( $izq - 8 ) . '" y="' . round( $cy + 4, 1 ) . '" text-anchor="end">' . esc_html( self::corto( $d['etiqueta'], 22 ) ) . '</text>';

			if ( $w > 0 ) {
				$svg .= '<path class="pk-viz-barra" d="' . self::barraHorizontal( $izq + 1, $cy - $grosor / 2, $w, $grosor ) . '"/>';
			}

			$svg .= '<text class="pk-viz-valor" x="' . round( $izq + $w + 6, 1 ) . '" y="' . round( $cy + 4, 1 ) . '">' . esc_html( $d['texto'] ) . '</text>';
			$svg .= '</g>';
		}

		$svg .= '</svg>';

		return self::marco( $titulo, $svg, true );
	}

	private static function marco( string $titulo, string $svg, bool $ancho = false ): string {
		// Estilos en componentes.css (.pk-viz) y tooltip en admin.js.
		return '<figure class="pk-viz' . ( $ancho ? ' ancho' : '' ) . '"><figcaption>' . esc_html( $titulo ) . '</figcaption>' . $svg . '</figure>';
	}

	/** Barra vertical con las esquinas de arriba redondeadas (4 px) y apoyada en la base. */
	private static function barraVertical( float $x, float $base, float $w, float $h ): string {
		$r = min( 4, $h, $w / 2 );
		$t = $base - $h;

		return sprintf( 'M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ', $x, $base, $t + $r, $x, $t, $x + $r, $t, $x + $w - $r, $x + $w, $t, $x + $w, $t + $r, $base );
	}

	/** Barra horizontal con las esquinas del final redondeadas (4 px) y apoyada en el eje. */
	private static function barraHorizontal( float $x, float $y, float $w, float $h ): string {
		$r   = min( 4, $w, $h / 2 );
		$fin = $x + $w;

		return sprintf( 'M%.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fZ', $x, $y, $fin - $r, $fin, $y, $fin, $y + $r, $y + $h - $r, $fin, $y + $h, $fin - $r, $y + $h, $x );
	}

	/** Máximo "redondo" para el eje: 1, 2, 2,5 o 5 por potencia de 10. */
	private static function tope( float $valor ): float {
		if ( $valor <= 0 ) {
			return 1.0;
		}

		$potencia = 10 ** floor( log10( $valor ) );

		foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) {
			if ( $valor <= $m * $potencia ) {
				return $m * $potencia;
			}
		}

		return 10 * $potencia;
	}

	private static function numero( float $v ): string {
		return floor( $v ) === $v ? (string) (int) $v : number_format( $v, 1, ',', '' );
	}

	private static function corto( string $texto, int $max ): string {
		return mb_strlen( $texto ) > $max ? mb_substr( $texto, 0, $max - 1 ) . '…' : $texto;
	}

}
