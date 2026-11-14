<?php

namespace Pekokis\Asistencia\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Gráficos de barras en SVG para el Resumen del mes, sin librerías.
 *
 * Cada gráfico mide una sola cosa (un eje, una serie, un color), así que no
 * lleva leyenda: el título dice qué es. Barras finas con la punta redondeada,
 * rejilla tenue, etiqueta directa solo en el dato que importa y un tooltip al
 * pasar el mouse o el dedo. La tabla de abajo es la vista de datos.
 */
final class Graficos {
	private static bool $estilosPintados = false;

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

		$svg = '<svg class="pka-viz-svg" viewBox="0 0 ' . $ancho . ' ' . $alto . '" role="img" aria-label="' . esc_attr( $titulo ) . '">';

		foreach ( array( 0, 0.5, 1 ) as $f ) {
			$y    = $base - $f * $plotH;
			$svg .= '<line class="pka-viz-rejilla" x1="' . $izq . '" x2="' . ( $ancho - $der ) . '" y1="' . round( $y, 1 ) . '" y2="' . round( $y, 1 ) . '"/>';
			$svg .= '<text class="pka-viz-eje" x="' . ( $izq - 6 ) . '" y="' . round( $y + 4, 1 ) . '" text-anchor="end">' . esc_html( self::numero( $max * $f ) ) . '</text>';
		}

		foreach ( array_values( $datos ) as $i => $d ) {
			$cx   = $izq + $paso * ( $i + 0.5 );
			$h    = $max > 0 ? $d['valor'] / $max * $plotH : 0;
			$x    = $cx - $barra / 2;
			$svg .= '<g class="pka-viz-marca" tabindex="0" data-tip="' . esc_attr( $d['detalle'] ) . '">';
			$svg .= '<rect class="pka-viz-golpe" x="' . round( $cx - $paso / 2, 1 ) . '" y="' . $arriba . '" width="' . round( $paso, 1 ) . '" height="' . $plotH . '"/>';

			if ( $h > 0 ) {
				$svg .= '<path class="pka-viz-barra" d="' . self::barraVertical( $x, $base, $barra, $h ) . '"/>';
			}

			$svg .= '</g>';
			$svg .= '<text class="pka-viz-eje" x="' . round( $cx, 1 ) . '" y="' . ( $alto - 6 ) . '" text-anchor="middle">' . esc_html( $d['etiqueta'] ) . '</text>';

			if ( $d['etiqueta'] === $resaltar ) {
				$svg .= '<text class="pka-viz-valor" x="' . round( $cx, 1 ) . '" y="' . round( $base - $h - 6, 1 ) . '" text-anchor="middle">' . esc_html( $d['texto'] ) . '</text>';
			}
		}

		$svg .= '<line class="pka-viz-base" x1="' . $izq . '" x2="' . ( $ancho - $der ) . '" y1="' . $base . '" y2="' . $base . '"/></svg>';

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

		$svg = '<svg class="pka-viz-svg" viewBox="0 0 ' . $ancho . ' ' . $alto . '" role="img" aria-label="' . esc_attr( $titulo ) . '">';
		$svg .= '<line class="pka-viz-base" x1="' . $izq . '" x2="' . $izq . '" y1="0" y2="' . ( $alto - 4 ) . '"/>';

		foreach ( array_values( $datos ) as $i => $d ) {
			$y    = $i * $fila + 4;
			$w    = $max > 0 ? $d['valor'] / $max * $plotW : 0;
			$cy   = $y + $fila / 2;
			$svg .= '<g class="pka-viz-marca" tabindex="0" data-tip="' . esc_attr( $d['detalle'] ) . '">';
			$svg .= '<rect class="pka-viz-golpe" x="0" y="' . $y . '" width="' . $ancho . '" height="' . $fila . '"/>';
			$svg .= '<text class="pka-viz-etiqueta" x="' . ( $izq - 8 ) . '" y="' . round( $cy + 4, 1 ) . '" text-anchor="end">' . esc_html( self::corto( $d['etiqueta'], 22 ) ) . '</text>';

			if ( $w > 0 ) {
				$svg .= '<path class="pka-viz-barra" d="' . self::barraHorizontal( $izq + 1, $cy - $grosor / 2, $w, $grosor ) . '"/>';
			}

			$svg .= '<text class="pka-viz-valor" x="' . round( $izq + $w + 6, 1 ) . '" y="' . round( $cy + 4, 1 ) . '">' . esc_html( $d['texto'] ) . '</text>';
			$svg .= '</g>';
		}

		$svg .= '</svg>';

		return self::marco( $titulo, $svg, true );
	}

	private static function marco( string $titulo, string $svg, bool $ancho = false ): string {
		$html = '';

		if ( ! self::$estilosPintados ) {
			self::$estilosPintados = true;
			$html .= self::estilos();
		}

		return $html . '<figure class="pka-viz' . ( $ancho ? ' pka-viz-ancho' : '' ) . '"><figcaption>' . esc_html( $titulo ) . '</figcaption>' . $svg . '</figure>';
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

	private static function estilos(): string {
		return '<style>
			.pka-graficos{display:flex;flex-wrap:wrap;gap:12px;max-width:1100px;margin:8px 0 24px}
			.pka-viz{--pka-serie:#2a78d6;--pka-superficie:#fcfcfb;--pka-tinta:#0b0b0b;--pka-tinta-2:#52514e;--pka-rejilla:#e4e3df;
				margin:0;background:var(--pka-superficie);border:1px solid #dcdcde;border-radius:8px;padding:10px 12px 6px;flex:1 1 320px;max-width:360px;position:relative}
			.pka-viz.pka-viz-ancho{max-width:600px;flex-basis:560px}
			.pka-viz figcaption{font-weight:600;color:var(--pka-tinta);margin-bottom:4px}
			.pka-viz-svg{display:block;width:100%;height:auto;overflow:visible}
			.pka-viz-rejilla{stroke:var(--pka-rejilla);stroke-width:1}
			.pka-viz-base{stroke:#8c8f94;stroke-width:1}
			.pka-viz-eje{fill:var(--pka-tinta-2);font-size:11px}
			.pka-viz-etiqueta{fill:var(--pka-tinta);font-size:12px}
			.pka-viz-valor{fill:var(--pka-tinta);font-size:12px;font-weight:600;font-variant-numeric:tabular-nums}
			.pka-viz-barra{fill:var(--pka-serie)}
			.pka-viz-golpe{fill:transparent}
			.pka-viz-marca{cursor:default;outline:none}
			.pka-viz-marca:hover .pka-viz-golpe,.pka-viz-marca:focus .pka-viz-golpe{fill:rgba(0,0,0,.04)}
			.pka-viz-tip{position:absolute;pointer-events:none;background:#1d2327;color:#fff;font-size:12px;line-height:1.35;padding:6px 8px;border-radius:6px;white-space:nowrap;transform:translate(-50%,calc(-100% - 8px));z-index:10}
		</style>
		<script>
			document.addEventListener("DOMContentLoaded",function(){
				document.querySelectorAll(".pka-viz").forEach(function(fig){
					var tip=document.createElement("div");tip.className="pka-viz-tip";tip.hidden=true;fig.appendChild(tip);
					function mostrar(g,x,y){tip.textContent=g.getAttribute("data-tip");tip.hidden=false;tip.style.left=x+"px";tip.style.top=y+"px";}
					fig.querySelectorAll(".pka-viz-marca").forEach(function(g){
						g.addEventListener("mousemove",function(e){var r=fig.getBoundingClientRect();mostrar(g,e.clientX-r.left,e.clientY-r.top);});
						g.addEventListener("focus",function(){var r=fig.getBoundingClientRect(),b=g.getBoundingClientRect();mostrar(g,b.left-r.left+b.width/2,b.top-r.top);});
						g.addEventListener("mouseleave",function(){tip.hidden=true;});
						g.addEventListener("blur",function(){tip.hidden=true;});
					});
				});
			});
		</script>';
	}
}
