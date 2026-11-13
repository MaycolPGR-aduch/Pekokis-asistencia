<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Marca;
use Pekokis\Asistencia\Nucleo\PruebaPresencia;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use Pekokis\Asistencia\Nucleo\TotalesDia;
use Shuchkin\SimpleXLSXGen;

defined( 'ABSPATH' ) || exit;

/**
 * Registro de asistencia mensual en Excel (D.S. 004-2006-TR):
 *
 * - "Registro": un renglón por trabajador y día, con ingreso y salida.
 * - "Marcas": todas las marcas, incluidas las anuladas, con su prueba y origen.
 * - "Integridad": quién lo generó, cuándo y el estado de la cadena de hashes.
 */
final class Exportes {
	/** @return array{nombre: string, contenido: string, hash: string, marcas: int} */
	public static function registroMensual( string $mes, string $generadoPor ): array {
		global $wpdb;

		$zona   = Ajustes::zona();
		$inicio = new DateTimeImmutable( $mes . '-01', $zona );
		$desde  = $inicio->format( 'Y-m-d' );
		$hasta  = $inicio->modify( 'last day of this month' )->format( 'Y-m-d' );
		$ajust  = Ajustes::todos();

		$m = Tablas::nombre( 'marcas' );
		$c = Tablas::nombre( 'correcciones' );
		$r = Tablas::nombre( 'revisiones' );
		$t = Tablas::nombre( 'trabajadores' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$filas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, t.dni, t.nombres, t.apellidos, rv.decision AS revision,
					( SELECT c.justificacion FROM {$c} c WHERE c.marca_id = m.id AND c.estado = 'aprobada' AND c.accion IN ('anular','reemplazar') LIMIT 1 ) AS anulada
				FROM {$m} m
				JOIN {$t} t ON t.id = m.trabajador_id
				LEFT JOIN {$r} rv ON rv.marca_id = m.id
				WHERE m.dia_laboral BETWEEN %s AND %s
				ORDER BY t.apellidos, t.nombres, m.marcado_utc, m.id",
				$desde,
				$hasta
			),
			ARRAY_A
		);

		$hora    = static fn( ?DateTimeImmutable $x ): string => $x ? $x->setTimezone( $zona )->format( 'H:i' ) : '';
		$detalle = array( array( '<b>DNI</b>', '<b>Apellidos y nombres</b>', '<b>Fecha</b>', '<b>Hora</b>', '<b>Marca</b>', '<b>Motivo</b>', '<b>Prueba</b>', '<b>Origen</b>', '<b>Nota</b>', '<b>Estado</b>', '<b>Revisión</b>', '<b>Id</b>', '<b>Hash</b>' ) );
		$porDia  = array();

		foreach ( $filas as $f ) {
			$momento = new DateTimeImmutable( $f['marcado_utc'], new DateTimeZone( 'UTC' ) );
			$motivo  = Motivos::porClave( $f['motivo_clave'] );
			$nombre  = $f['apellidos'] . ', ' . $f['nombres'];

			$detalle[] = array(
				$f['dni'],
				$nombre,
				$f['dia_laboral'],
				$hora( $momento ),
				'entrada' === $f['tipo'] ? 'Entrada' : 'Salida',
				$motivo ? $motivo->nombre : (string) $f['motivo_clave'],
				Marcas::etiquetaPrueba( PruebaPresencia::from( $f['prueba'] ) ),
				$f['origen'],
				$f['nota'],
				null === $f['anulada'] ? 'Válida' : 'Anulada: ' . $f['anulada'],
				(string) $f['revision'],
				(int) $f['id'],
				$f['hash'],
			);

			if ( null === $f['anulada'] ) {
				$porDia[ $f['dni'] . '|' . $f['dia_laboral'] ]['nombre']   = $nombre;
				$porDia[ $f['dni'] . '|' . $f['dia_laboral'] ]['marcas'][] = new Marca( TipoMarca::from( $f['tipo'] ), $momento, $motivo, (int) $f['id'] );
			}
		}

		$registro = array(
			array( '<b>Registro de control de asistencia</b>' ),
			array( 'Empleador', $ajust['razon_social'] ),
			array( 'RUC', $ajust['ruc'] ),
			array( 'Periodo', wp_date( 'F Y', $inicio->getTimestamp(), $zona ) ),
			array(),
			array( '<b>DNI</b>', '<b>Apellidos y nombres</b>', '<b>Fecha</b>', '<b>Ingreso</b>', '<b>Salida</b>', '<b>Refrigerio (min)</b>', '<b>Salidas temporales</b>', '<b>Tiempo en el taller</b>', '<b>Estado</b>' ),
		);

		foreach ( $porDia as $clave => $dia ) {
			[ $dni, $fecha ] = explode( '|', $clave );
			$tot             = TotalesDia::calcular( $dia['marcas'] );

			$registro[] = array(
				$dni,
				$dia['nombre'],
				$fecha,
				$hora( $tot->primeraEntrada ),
				$hora( $tot->ultimaSalida ),
				$tot->minutosRefrigerio,
				$tot->salidasTemporales,
				sprintf( '%d:%02d', intdiv( $tot->minutosDentro, 60 ), $tot->minutosDentro % 60 ),
				$tot->incompleto ? 'Sin salida' : 'Completo',
			);
		}

		$rota       = InsercionEncadenada::verificar( 'marcas' );
		$ultimoHash = (string) $wpdb->get_var( "SELECT hash FROM {$m} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$integridad = array(
			array( '<b>Integridad del registro</b>' ),
			array( 'Generado', wp_date( 'Y-m-d H:i', null, $zona ) . ' (hora de Lima)' ),
			array( 'Generado por', $generadoPor ),
			array( 'Marcas del periodo (incluidas anuladas)', count( $filas ) ),
			array( 'Cadena de marcas', null === $rota ? 'Íntegra' : 'ROTA desde la marca ' . $rota ),
			array( 'Último hash de la cadena', $ultimoHash ),
			array(),
			array( 'Cada marca guarda el hash de la anterior. Si alguien edita o borra una marca directamente en la base de datos, la cadena se rompe.' ),
		);

		$xlsx = SimpleXLSXGen::fromArray( $registro, 'Registro' )
			->addSheet( $detalle, 'Marcas' )
			->addSheet( $integridad, 'Integridad' )
			->setTitle( 'Registro de asistencia ' . $mes )
			->setCompany( (string) $ajust['razon_social'] );

		$contenido = (string) $xlsx;

		return array(
			'nombre'    => 'registro-asistencia-' . $mes . '.xlsx',
			'contenido' => $contenido,
			'hash'      => hash( 'sha256', $contenido ),
			'marcas'    => count( $filas ),
		);
	}

	/** @return string[] Últimos meses ("Y-m"), el actual primero. */
	public static function meses( int $cuantos = 12 ): array {
		$mes   = new DateTimeImmutable( 'first day of this month', Ajustes::zona() );
		$meses = array();

		for ( $i = 0; $i < $cuantos; $i++ ) {
			$meses[] = $mes->modify( '-' . $i . ' months' )->format( 'Y-m' );
		}

		return $meses;
	}
}
