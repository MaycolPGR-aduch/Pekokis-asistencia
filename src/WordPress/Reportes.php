<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use Shuchkin\SimpleXLSXGen;

defined( 'ABSPATH' ) || exit;

/**
 * Informes del mes para descargar en Excel o PDF (maqueta G16): resumen por
 * persona, detalle diario, horas extra, incidencias y recibos por honorarios.
 * Cada informe es una tabla (columnas y filas) que sirve para los dos formatos.
 */
final class Reportes {
	/** clave => título y descripción corta. */
	public const TIPOS = array(
		'resumen'     => array( 'Resumen mensual por persona', 'Horas, extra y total por persona.' ),
		'diario'      => array( 'Detalle diario', 'Cada marcaje de cada día.' ),
		'extra'       => array( 'Horas extra', 'Extra, sábados, domingos y feriados.' ),
		'incidencias' => array( 'Incidencias', 'Días incompletos, tardanzas y correcciones.' ),
		'recibos'     => array( 'Recibos por honorarios', 'Estado de cada recibo del mes.' ),
	);

	/** Informes que este usuario puede bajar (los recibos tienen montos). @return string[] */
	public static function permitidos(): array {
		$tipos = array_keys( self::TIPOS );

		if ( ! current_user_can( Capacidades::RECIBOS ) && ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			$tipos = array_values( array_diff( $tipos, array( 'recibos' ) ) );
		}

		return $tipos;
	}

	/**
	 * @param int[]|null $alcance null = todos.
	 * @return array{titulo: string, columnas: string[], filas: array<int, array<int, string|int|float>>}
	 */
	public static function datos( string $tipo, string $mes, ?array $alcance ): array {
		$personas = Informes::personas( $mes, $alcance );
		$nombre   = static fn( array $t ): string => $t['apellidos'] . ', ' . $t['nombres'];
		$h        = static fn( int $min ): float => round( $min / 60, 2 );
		$titulo   = self::TIPOS[ $tipo ][0] . ' · ' . self::mesLargo( $mes );

		switch ( $tipo ) {
			case 'resumen':
				$filas = $personas ? Jornadas::prePlanilla( $personas, $mes ) : array();
				$salida = array();

				foreach ( $personas as $t ) {
					$f        = $filas[ (int) $t['id'] ] ?? new PrePlanilla();
					$salida[] = array(
						$nombre( $t ),
						(string) $t['dni'],
						$f->dias['trabajados'],
						$f->dias['faltas'],
						$f->dias['justificados'],
						$f->dias['con_tardanza'],
						$h( $f->minutos['trabajados'] ),
						$h( $f->minutos['extra'] ),
						$h( $f->minutos['sabado'] + $f->minutos['domingo'] + $f->minutos['feriado'] ),
						$h( $f->minutos['extra'] + $f->minutos['sabado'] + $f->minutos['domingo'] + $f->minutos['feriado'] ),
					);
				}

				return array(
					'titulo'   => $titulo,
					'columnas' => array( 'Persona', 'DNI', 'Días trabajados', 'Faltas', 'Justificados', 'Días con tardanza', 'Horas trabajadas', 'Extra (h)', 'Sáb., dom. y feriado (h)', 'Total adicional (h)' ),
					'filas'    => $salida,
				);

			case 'diario':
			case 'extra':
			case 'incidencias':
				$salida = array();

				foreach ( $personas as $t ) {
					$dias   = Informes::diasDe( $t, $mes );
					$marcas = 'diario' === $tipo ? Informes::marcasDelMes( (int) $t['id'], $mes ) : array();

					foreach ( $dias as $dia => $j ) {
						$fila = self::filaDia( $tipo, $dia, $j, $nombre( $t ), $marcas[ $dia ] ?? array() );

						if ( null !== $fila ) {
							$salida[] = $fila;
						}
					}
				}

				if ( 'incidencias' === $tipo ) {
					$salida = array_merge( $salida, self::correcciones( $mes, $personas ) );
				}

				usort( $salida, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );

				$columnas = match ( $tipo ) {
					'diario'      => array( 'Fecha', 'Persona', 'Estado', 'Marcas', 'Trabajado (h)', 'Tardanza (min)', 'Extra (min)' ),
					'extra'       => array( 'Fecha', 'Persona', 'Día', 'Trabajado (h)', 'Extra (h)', 'Sáb., dom. o feriado (h)' ),
					default       => array( 'Fecha', 'Persona', 'Incidencia', 'Detalle' ),
				};

				return array( 'titulo' => $titulo, 'columnas' => $columnas, 'filas' => $salida );

			case 'recibos':
				$recibos = Recibos::delMes( $mes );
				$salida  = array();

				foreach ( $personas as $t ) {
					$r        = $recibos[ (int) $t['id'] ] ?? null;
					$salida[] = array(
						$nombre( $t ),
						$r ? (string) $r['numero'] : '—',
						$r ? round( (int) $r['monto_centimos'] / 100, 2 ) : '—',
						$r ? ( ValidacionRecibo::ESTADOS[ $r['estado'] ] ?? $r['estado'] ) : 'Falta enviar',
						$r ? self::fechaLocal( (string) $r['enviado_en'] ) : '—',
					);
				}

				return array(
					'titulo'   => $titulo,
					'columnas' => array( 'Persona', 'Número', 'Monto (S/)', 'Estado', 'Enviado el' ),
					'filas'    => $salida,
				);
		}

		return array( 'titulo' => $titulo, 'columnas' => array(), 'filas' => array() );
	}

	/** @return array{nombre: string, contenido: string, hash: string} */
	public static function excel( string $tipo, string $mes, ?array $alcance, string $generadoPor ): array {
		$d    = self::datos( $tipo, $mes, $alcance );
		$hoja = array( array_map( static fn( string $c ): string => '<b>' . $c . '</b>', $d['columnas'] ) );

		foreach ( $d['filas'] as $f ) {
			$hoja[] = $f;
		}

		$hoja[] = array();
		$hoja[] = array( 'Generado por ' . $generadoPor . ' el ' . wp_date( 'Y-m-d H:i', null, Ajustes::zona() ) );

		$contenido = (string) SimpleXLSXGen::fromArray( $hoja, mb_substr( self::TIPOS[ $tipo ][0], 0, 31 ) )
			->setTitle( $d['titulo'] )
			->setCompany( (string) Ajustes::todos()['razon_social'] );

		return array(
			'nombre'    => sanitize_file_name( strtolower( remove_accents( self::TIPOS[ $tipo ][0] ) ) ) . '-' . $mes . '.xlsx',
			'contenido' => $contenido,
			'hash'      => hash( 'sha256', $contenido ),
		);
	}

	/**
	 * Últimas descargas de informes (maqueta G16, "Descargas recientes").
	 *
	 * @return array<int, array{titulo: string, detalle: string}>
	 */
	public static function recientes( int $cuantas = 5 ): array {
		global $wpdb;

		$a     = Tablas::nombre( 'auditoria' );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT accion, despues, creado_utc FROM {$a} WHERE accion IN ('reporte.descargar','registro.descargar','preplanilla.descargar','informe.descargar','historial.exportar') ORDER BY id DESC LIMIT %d", $cuantas ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();

		foreach ( $filas as $f ) {
			$d      = json_decode( (string) $f['despues'], true );
			$d      = is_array( $d ) ? $d : array();
			$nombre = match ( $f['accion'] ) {
				'reporte.descargar'     => (string) ( $d['reporte'] ?? 'Informe' ),
				'registro.descargar'    => 'Registro de asistencia',
				'preplanilla.descargar' => 'Planilla de pagos',
				'informe.descargar'     => 'Informe por persona',
				default                 => 'Auditoría',
			};
			$mes    = isset( $d['mes'] ) ? ' · ' . self::mesLargo( (string) $d['mes'] ) : '';
			$out[]  = array(
				'titulo'  => $nombre . $mes,
				'detalle' => self::fechaLocal( (string) $f['creado_utc'], 'j \d\e F' ) . ' · ' . (string) ( $d['formato'] ?? ( 'historial.exportar' === $f['accion'] ? 'CSV' : 'Excel' ) ),
			);
		}

		return $out;
	}

	public static function mesLargo( string $mes ): string {
		return wp_date( 'F Y', ( new DateTimeImmutable( $mes . '-15 12:00', Ajustes::zona() ) )->getTimestamp(), Ajustes::zona() );
	}

	/** @param array<int, array<string, mixed>> $marcas */
	private static function filaDia( string $tipo, string $dia, CalculoJornada $j, string $persona, array $marcas ): ?array {
		$h         = static fn( int $min ): float => round( $min / 60, 2 );
		$adicional = $j->minutosSabado + $j->minutosDomingo + $j->minutosFeriado;

		if ( 'diario' === $tipo ) {
			$vigentes = array_filter( $marcas, static fn( $m ) => null === $m['anulada'] );

			if ( ! $vigentes && in_array( $j->estado, array( CalculoJornada::DESCANSO ), true ) ) {
				return null;
			}

			return array( $dia, $persona, Informes::COLORES[ Informes::estadoVisual( $j ) ][0], implode( ' · ', array_map( array( Informes::class, 'textoMarca' ), $vigentes ) ), $h( $j->minutosTrabajados ), $j->minutosTardanza, $j->minutosExtra );
		}

		if ( 'extra' === $tipo ) {
			if ( 0 === $j->minutosExtra && 0 === $adicional ) {
				return null;
			}

			$tipoDia = match ( $j->tipoDia ) {
				CalculoJornada::DIA_SABADO  => 'Sábado',
				CalculoJornada::DIA_DOMINGO => 'Domingo',
				CalculoJornada::DIA_FERIADO => 'Feriado',
				CalculoJornada::DIA_LIBRE   => 'Día libre',
				default                     => 'Laborable',
			};

			return array( $dia, $persona, $tipoDia, $h( $j->minutosTrabajados ), $h( $j->minutosExtra ), $h( $adicional ) );
		}

		$problemas = array();

		if ( CalculoJornada::FALTA === $j->estado ) {
			$problemas[] = array( 'Falta', 'No marcó en un día laborable.' );
		} elseif ( CalculoJornada::INCOMPLETO === $j->estado ) {
			$problemas[] = array( 'Día incompleto', 'Falta una marca (entrada, comida o salida).' );
		} elseif ( CalculoJornada::A_REVISAR === $j->estado ) {
			$problemas[] = array( 'Por revisar', '' !== $j->detalle ? $j->detalle : 'Tiene marcas por revisar.' );
		}

		if ( $j->minutosTardanza > 0 ) {
			$problemas[] = array( 'Tardanza', $j->minutosTardanza . ' min' );
		}

		if ( $j->minutosSalidaAnticipada > 0 ) {
			$problemas[] = array( 'Salida anticipada', $j->minutosSalidaAnticipada . ' min' );
		}

		if ( ! $problemas ) {
			return null;
		}

		return array( $dia, $persona, implode( ' · ', array_column( $problemas, 0 ) ), implode( ' · ', array_column( $problemas, 1 ) ) );
	}

	/**
	 * Correcciones pedidas en el mes, como incidencias.
	 *
	 * @param array<int, array<string, mixed>> $personas
	 * @return array<int, array<int, string>>
	 */
	private static function correcciones( string $mes, array $personas ): array {
		global $wpdb;

		if ( ! $personas ) {
			return array();
		}

		$ids    = implode( ',', array_map( static fn( $t ) => (int) $t['id'], $personas ) );
		$nombre = array_column( array_map( static fn( $t ) => array( (int) $t['id'], $t['apellidos'] . ', ' . $t['nombres'] ), $personas ), 1, 0 );
		$c      = Tablas::nombre( 'correcciones' );
		$zona   = Ajustes::zona();
		$inicio = ( new DateTimeImmutable( $mes . '-01 00:00', $zona ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$fin    = ( new DateTimeImmutable( $mes . '-01 00:00', $zona ) )->modify( '+1 month' )->setTimezone( new DateTimeZone( 'UTC' ) );
		$filas  = $wpdb->get_results( $wpdb->prepare( "SELECT trabajador_id, marcado_utc, tipo, estado, justificacion FROM {$c} WHERE trabajador_id IN ({$ids}) AND marcado_utc >= %s AND marcado_utc < %s", $inicio->format( 'Y-m-d H:i:s' ), $fin->format( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$estado = array( 'pendiente' => 'pendiente', 'aprobada' => 'aprobada', 'rechazada' => 'rechazada' );
		$out    = array();

		foreach ( $filas as $f ) {
			$local = ( new DateTimeImmutable( (string) $f['marcado_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zona );
			$out[] = array(
				$local->format( 'Y-m-d' ),
				$nombre[ (int) $f['trabajador_id'] ] ?? '',
				'Corrección ' . ( $estado[ $f['estado'] ] ?? $f['estado'] ),
				( 'entrada' === $f['tipo'] ? 'Entrada' : 'Salida' ) . ' a las ' . $local->format( 'H:i' ) . ( '' !== (string) $f['justificacion'] ? ': ' . $f['justificacion'] : '' ),
			);
		}

		return $out;
	}

	private static function fechaLocal( string $utc, string $formato = 'Y-m-d H:i' ): string {
		return '' === $utc ? '' : wp_date( $formato, ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->getTimestamp(), Ajustes::zona() );
	}
}
