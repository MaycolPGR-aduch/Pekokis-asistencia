<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\PruebaPresencia;
use Pekokis\Asistencia\WordPress\Admin\PantallaDia;
use Shuchkin\SimpleXLSXGen;

defined( 'ABSPATH' ) || exit;

/**
 * Datos del dashboard y del informe por trabajador (fase 3). Todo se calcula a
 * partir de Jornadas: no hay tablas nuevas.
 */
final class Informes {
	/** Colores por estado del día: etiqueta, color del texto y fondo. */
	public const COLORES = array(
		'completo'          => array( 'Completo', '#00733a', '#e7f5ec' ),
		'con_extra'         => array( 'Con horas extra', '#0b6e69', '#dff6f5' ),
		'tarde'             => array( 'Tarde', '#8a4b00', '#fdf1dc' ),
		'falta'             => array( 'Falta', '#b32d2e', '#fbe7e7' ),
		'justificado'       => array( 'Justificado', '#1d5f99', '#e5f0fa' ),
		'descanso'          => array( 'Descanso', '#50575e', '#f0f0f1' ),
		'descanso_trabajado' => array( 'Sábado, domingo o feriado trabajado', '#6b3fa0', '#f1eafa' ),
		'revisar'           => array( 'Por revisar', '#8a4b00', '#fff4d6' ),
		'incompleto'        => array( 'Incompleto', '#b32d2e', '#fff4d6' ),
		'pendiente'         => array( '—', '#8c8f94', '#ffffff' ),
	);

	/** Clave de COLORES para un día calculado. */
	public static function estadoVisual( ?CalculoJornada $j ): string {
		if ( ! $j ) {
			return 'pendiente';
		}

		return match ( $j->estado ) {
			CalculoJornada::FALTA       => 'falta',
			CalculoJornada::JUSTIFICADO => 'justificado',
			CalculoJornada::DESCANSO    => 'descanso',
			CalculoJornada::INCOMPLETO  => 'incompleto',
			CalculoJornada::A_REVISAR   => 'revisar',
			default                     => match ( true ) {
				! $j->laborable              => 'descanso_trabajado',
				$j->minutosTardanza > 0      => 'tarde',
				$j->minutosExtra > 0         => 'con_extra',
				default                      => 'completo',
			},
		};
	}

	/**
	 * Trabajadores del mes que este usuario puede ver.
	 *
	 * @param int[]|null $alcance null = todos.
	 * @return array<int, array<string, mixed>>
	 */
	public static function personas( string $mes, ?array $alcance ): array {
		return array_values( array_filter( PrePlanillas::trabajadoresDelMes( $mes ), static fn( array $t ): bool => null === $alcance || in_array( (int) $t['id'], $alcance, true ) ) );
	}

	/**
	 * Filas por trabajador y total de un mes.
	 *
	 * @return array{filas: array<int, PrePlanilla>, total: PrePlanilla, personas: array<int, array<string, mixed>>}
	 */
	public static function mes( string $mes, ?array $alcance ): array {
		$personas = self::personas( $mes, $alcance );
		$filas    = $personas ? Jornadas::prePlanilla( $personas, $mes ) : array();

		return array( 'filas' => $filas, 'total' => PrePlanilla::sumar( $filas ), 'personas' => $personas );
	}

	/**
	 * Totales de los últimos meses, el más reciente primero.
	 *
	 * @return array<string, PrePlanilla> mes => total
	 */
	public static function comparacion( string $hasta, ?array $alcance, int $meses = 6 ): array {
		$base  = new DateTimeImmutable( $hasta . '-01' );
		$datos = array();

		for ( $i = 0; $i < $meses; $i++ ) {
			$mes           = $base->modify( '-' . $i . ' months' )->format( 'Y-m' );
			$datos[ $mes ] = self::mes( $mes, $alcance )['total'];
		}

		return $datos;
	}

	/**
	 * Días calculados de un trabajador en el mes (hasta ayer).
	 *
	 * @return array<string, CalculoJornada>
	 */
	public static function diasDe( array $t, string $mes ): array {
		$desde = $mes . '-01';
		$hasta = min( ( new DateTimeImmutable( $desde ) )->format( 'Y-m-t' ), Jornadas::ayer() );

		return $hasta >= $desde ? ( Jornadas::delRango( array( $t ), $desde, $hasta )[ (int) $t['id'] ] ?? array() ) : array();
	}

	/**
	 * Todas las marcas del mes, incluidas las anuladas, agrupadas por día.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function marcasDelMes( int $trabajadorId, string $mes ): array {
		global $wpdb;

		$m     = Tablas::nombre( 'marcas' );
		$c     = Tablas::nombre( 'correcciones' );
		$desde = $mes . '-01';
		$hasta = ( new DateTimeImmutable( $desde ) )->format( 'Y-m-t' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, (
					SELECT c.justificacion FROM {$c} c WHERE c.marca_id = m.id AND c.estado = 'aprobada' AND c.accion IN ('anular', 'reemplazar') LIMIT 1
				) AS anulada
				FROM {$m} m WHERE m.trabajador_id = %d AND m.dia_laboral BETWEEN %s AND %s ORDER BY m.marcado_utc, m.id",
				$trabajadorId,
				$desde,
				$hasta
			),
			ARRAY_A
		);

		$porDia = array();

		foreach ( $filas as $f ) {
			$porDia[ $f['dia_laboral'] ][] = $f;
		}

		return $porDia;
	}

	/** "09:02 Entrada (WiFi)" o "13:00 Salida · Refrigerio (QR)". */
	public static function textoMarca( array $m ): string {
		$hora   = ( new DateTimeImmutable( $m['marcado_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( Ajustes::zona() )->format( 'H:i' );
		$motivo = Motivos::porClave( $m['motivo_clave'] );
		$prueba = PruebaPresencia::tryFrom( (string) $m['prueba'] );

		return $hora . ' ' . ( 'entrada' === $m['tipo'] ? 'Entrada' : 'Salida · ' . ( $motivo ? $motivo->nombre : $m['motivo_clave'] ) )
			. ( $prueba ? ' (' . Marcas::etiquetaPrueba( $prueba ) . ')' : '' );
	}

	/** @return array{nombre: string, contenido: string, hash: string} */
	public static function excelTrabajador( array $t, string $mes, bool $conSueldos, string $generadoPor ): array {
		$dias   = self::diasDe( $t, $mes );
		$marcas = self::marcasDelMes( (int) $t['id'], $mes );
		$fila   = Jornadas::prePlanilla( array( $t ), $mes )[ (int) $t['id'] ] ?? new PrePlanilla();
		$h      = static fn( int $min ): float => round( $min / 60, 2 );
		$nombre = Trabajadores::nombreCompleto( $t );

		$resumen = array(
			array( '<b>Informe de asistencia ' . $mes . '</b>' ),
			array( 'Trabajador', $nombre ),
			array( 'DNI', $t['dni'] ),
			array( 'Área y cargo', trim( $t['area'] . ' · ' . $t['cargo'], ' ·' ) ),
			array(),
			array( '<b>Totales</b>', '' ),
			array( 'Días laborables', $fila->dias['laborables'] ),
			array( 'Días trabajados', $fila->dias['trabajados'] ),
			array( 'Faltas', $fila->dias['faltas'] ),
			array( 'Días justificados', $fila->dias['justificados'] ),
			array( 'Días con tardanza', $fila->dias['con_tardanza'] ),
			array( 'Tardanza (h)', $h( $fila->minutos['tardanza'] ) ),
			array( 'Horas trabajadas', $h( $fila->minutos['trabajados'] ) ),
			array( 'Horas extra (lunes a viernes)', $h( $fila->minutos['extra'] ) ),
			array( 'Horas de sábado', $h( $fila->minutos['sabado'] ) ),
			array( 'Horas de domingo', $h( $fila->minutos['domingo'] ) ),
			array( 'Horas de feriado', $h( $fila->minutos['feriado'] ) ),
			array( 'Horas de madrugada', $h( $fila->minutos['madrugada'] ) ),
			array( 'Puntualidad', null === $fila->puntualidad() ? '—' : $fila->puntualidad() . ' %' ),
		);

		if ( $conSueldos ) {
			$resumen[] = array( 'Honorario (S/)', round( $fila->montos['honorario'] / 100, 2 ) );
			$resumen[] = array( $fila->porHoras() ? 'Horas trabajadas y adicionales (S/)' : 'Horas adicionales (S/)', round( $fila->costoExtra() / 100, 2 ) );
			$resumen[] = array( 'Descuentos (S/)', round( $fila->montos['descuentos'] / 100, 2 ) );
			$resumen[] = array( 'Total del mes (S/)', round( $fila->montos['total'] / 100, 2 ) );
		}

		$resumen[] = array();
		$resumen[] = array( 'Generado por', $generadoPor );
		$resumen[] = array( 'Generado el', wp_date( 'Y-m-d H:i', null, Ajustes::zona() ) );

		$hojaDias = array( array( '<b>Fecha</b>', '<b>Estado</b>', '<b>Marcas</b>', '<b>Trabajado (h)</b>', '<b>Tardanza (min)</b>', '<b>Salida anticipada (min)</b>', '<b>Extra (min)</b>', '<b>Sábado, domingo o feriado (min)</b>', '<b>Detalle</b>' ) );

		foreach ( $dias as $dia => $j ) {
			$vigentes   = array_filter( $marcas[ $dia ] ?? array(), static fn( $m ) => null === $m['anulada'] );
			$hojaDias[] = array(
				$dia,
				self::COLORES[ self::estadoVisual( $j ) ][0],
				implode( ' · ', array_map( array( self::class, 'textoMarca' ), $vigentes ) ),
				$h( $j->minutosTrabajados ),
				$j->minutosTardanza,
				$j->minutosSalidaAnticipada,
				$j->minutosExtra,
				$j->minutosSabado + $j->minutosDomingo + $j->minutosFeriado,
				$j->detalle,
			);
		}

		$hojaMarcas = array( array( '<b>Fecha</b>', '<b>Marca</b>', '<b>Origen</b>', '<b>Nota</b>', '<b>Estado</b>' ) );

		foreach ( $marcas as $dia => $lista ) {
			foreach ( $lista as $m ) {
				$hojaMarcas[] = array( $dia, self::textoMarca( $m ), PantallaDia::ORIGENES[ $m['origen'] ] ?? $m['origen'], $m['nota'], null === $m['anulada'] ? 'Vigente' : 'Anulada: ' . $m['anulada'] );
			}
		}

		$contenido = (string) SimpleXLSXGen::fromArray( $resumen, 'Resumen' )
			->addSheet( $hojaDias, 'Días' )
			->addSheet( $hojaMarcas, 'Marcas' )
			->setTitle( 'Informe de asistencia ' . $mes . ' — ' . $nombre )
			->setCompany( (string) Ajustes::todos()['razon_social'] );

		return array(
			'nombre'    => 'informe-' . sanitize_file_name( strtolower( remove_accents( $t['apellidos'] . '-' . $t['nombres'] ) ) ) . '-' . $mes . '.xlsx',
			'contenido' => $contenido,
			'hash'      => hash( 'sha256', $contenido ),
		);
	}
}
