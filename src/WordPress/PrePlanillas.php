<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Shuchkin\SimpleXLSXGen;

defined( 'ABSPATH' ) || exit;

/**
 * Planilla de pagos del mes en Excel (pago por recibos por honorarios). Solo la
 * descarga quien puede ver sueldos.
 *
 * - "Pagos": un renglón por persona con honorario, horas por tipo, tarifa,
 *   monto, total y si ya se puede pagar.
 * - "Detalle por día": cómo se calculó cada día.
 * - "Reglas": cómo se calcula, para revisarlo.
 */
final class PrePlanillas {
	public const TIPOS_DIA = array(
		CalculoJornada::DIA_LABORABLE => 'Laborable',
		CalculoJornada::DIA_LIBRE     => 'Libre en su horario',
		CalculoJornada::DIA_SABADO    => 'Sábado',
		CalculoJornada::DIA_DOMINGO   => 'Domingo',
		CalculoJornada::DIA_FERIADO   => 'Feriado',
	);

	/** Trabajadores que estuvieron activos en algún día del mes. */
	public static function trabajadoresDelMes( string $mes ): array {
		$desde = $mes . '-01';
		$hasta = ( new DateTimeImmutable( $desde ) )->format( 'Y-m-t' );
		$todos = array_merge( Trabajadores::listar( Trabajadores::ACTIVO ), Trabajadores::listar( Trabajadores::CESADO ) );

		return array_values(
			array_filter(
				$todos,
				static function ( array $t ) use ( $desde, $hasta ): bool {
					[ $inicio, $fin ] = Jornadas::periodoDe( $t, $desde, $hasta );

					return $inicio <= $fin;
				}
			)
		);
	}

	/** @return array{nombre: string, contenido: string, hash: string, trabajadores: int} */
	public static function excel( string $mes, string $generadoPor, bool $soloListos = false ): array {
		$ajustes  = Ajustes::todos();
		$reglas   = Ajustes::reglasPago();
		$personas = self::trabajadoresDelMes( $mes );
		$desde    = $mes . '-01';
		$hasta    = min( ( new DateTimeImmutable( $desde ) )->format( 'Y-m-t' ), Jornadas::ayer() );
		$filas    = Jornadas::prePlanilla( $personas, $mes );
		$pagados  = Pagos::delMes( $mes );

		if ( $soloListos ) {
			// "Planilla de las listas": quienes ya se pueden pagar y todavía no se marcaron como pagados.
			$personas = array_values( array_filter( $personas, static fn( array $t ): bool => isset( $filas[ (int) $t['id'] ] ) && PrePlanilla::LISTO === $filas[ (int) $t['id'] ]->estado() && ! isset( $pagados[ (int) $t['id'] ] ) ) );
			$filas    = array_intersect_key( $filas, array_flip( array_map( static fn( array $t ): int => (int) $t['id'], $personas ) ) );
		}
		$jornadas = $hasta >= $desde ? Jornadas::delRango( $personas, $desde, $hasta ) : array();
		$horas    = static fn( int $min ): float => round( $min / 60, 2 );
		$soles    = static fn( int $cts ): float => round( $cts / 100, 2 );
		$total    = PrePlanilla::sumar( $filas );

		$pagos = array(
			array( '<b>Pagos de ' . $mes . ' — ' . $ajustes['razon_social'] . ' (RUC ' . $ajustes['ruc'] . ')</b>' ),
			array( 'Recibos por honorarios: honorario + horas extra, de sábado, de domingo y de feriado por la tarifa de cada persona. Días calculados hasta el ' . $hasta . '.' ),
			array(),
			array(
				'<b>DNI</b>', '<b>Apellidos y nombres</b>', '<b>Honorario (S/)</b>',
				'<b>Horas extra</b>', '<b>Tarifa extra</b>', '<b>Extra (S/)</b>',
				'<b>Horas sábado</b>', '<b>Tarifa sábado</b>', '<b>Sábado (S/)</b>',
				'<b>Horas domingo</b>', '<b>Tarifa domingo</b>', '<b>Domingo (S/)</b>',
				'<b>Horas feriado</b>', '<b>Tarifa feriado</b>', '<b>Feriado (S/)</b>',
				'<b>Total (S/)</b>', '<b>Estado</b>', '<b>Pagado</b>',
				'<b>Días trabajados</b>', '<b>Faltas</b>', '<b>Justificados</b>', '<b>Tardanzas</b>', '<b>Incompletos</b>', '<b>Por revisar</b>', '<b>Observaciones</b>',
			),
		);

		$detalle = array(
			array(
				'<b>DNI</b>', '<b>Apellidos y nombres</b>', '<b>Fecha</b>', '<b>Tipo de día</b>', '<b>Estado</b>', '<b>Jornada (min)</b>', '<b>Trabajado (min)</b>',
				'<b>Comida descontada (min)</b>', '<b>Tardanza (min)</b>', '<b>Salida anticipada (min)</b>',
				'<b>Extra (min)</b>', '<b>Sábado (min)</b>', '<b>Domingo (min)</b>', '<b>Feriado (min)</b>', '<b>Detalle</b>',
			),
		);

		foreach ( $personas as $t ) {
			$id     = (int) $t['id'];
			$nombre = $t['apellidos'] . ', ' . $t['nombres'];
			$f      = $filas[ $id ] ?? PrePlanilla::calcular( array(), array() );
			$s      = $f->sueldoFinal;
			$obs    = array();

			if ( $f->cambioDeSueldo ) {
				$obs[] = 'cambió la tarifa en el mes';
			}

			if ( $f->diasSinSueldo ) {
				$obs[] = $f->diasSinSueldo . ' día(s) con horas adicionales sin tarifa vigente';
			}

			if ( Trabajadores::CESADO === $t['estado'] ) {
				$obs[] = 'cesó el ' . $t['fecha_cese'];
			}

			$tarifa = static fn( string $tipo ): float|string => $s ? round( $s->tarifa( $tipo ) / 100, 2 ) : '';

			$pagos[] = array(
				$t['dni'], $nombre, $soles( $f->montos['honorario'] ),
				$horas( $f->minutos['extra'] ), $tarifa( 'extra' ), $soles( $f->montos['extra'] ),
				$horas( $f->minutos['sabado'] ), $tarifa( 'sabado' ), $soles( $f->montos['sabado'] ),
				$horas( $f->minutos['domingo'] ), $tarifa( 'domingo' ), $soles( $f->montos['domingo'] ),
				$horas( $f->minutos['feriado'] ), $tarifa( 'feriado' ), $soles( $f->montos['feriado'] ),
				$soles( $f->montos['total'] ), PrePlanilla::ESTADOS[ $f->estado() ], isset( $pagados[ $id ] ) ? 'Sí, ' . Ajustes::local( $pagados[ $id ]['marcado_en'], 'd/m/Y' ) : 'No',
				$f->dias['trabajados'], $f->dias['faltas'], $f->dias['justificados'], $f->dias['con_tardanza'], $f->dias['incompletos'], $f->dias['por_revisar'],
				implode( '; ', $obs ),
			);

			foreach ( $jornadas[ $id ] ?? array() as $dia => $j ) {
				/** @var CalculoJornada $j */
				$detalle[] = array(
					$t['dni'], $nombre, $dia, self::TIPOS_DIA[ $j->tipoDia ] ?? $j->tipoDia, CalculoJornada::ESTADOS[ $j->estado ] ?? $j->estado,
					$j->minutosEsperados, $j->minutosTrabajados, $j->minutosComidaDescontada, $j->minutosTardanza, $j->minutosSalidaAnticipada,
					$j->minutosExtra, $j->minutosSabado, $j->minutosDomingo, $j->minutosFeriado,
					$j->detalle,
				);
			}
		}

		$pagos[] = array();
		$pagos[] = array(
			'', '<b>Total</b>', $soles( $total->montos['honorario'] ),
			$horas( $total->minutos['extra'] ), '', $soles( $total->montos['extra'] ),
			$horas( $total->minutos['sabado'] ), '', $soles( $total->montos['sabado'] ),
			$horas( $total->minutos['domingo'] ), '', $soles( $total->montos['domingo'] ),
			$horas( $total->minutos['feriado'] ), '', $soles( $total->montos['feriado'] ),
			$soles( $total->montos['total'] ),
		);

		$reglasHoja = array(
			array( '<b>Regla</b>', '<b>Cómo se aplica</b>' ),
			array( 'Total del mes', 'honorario + horas extra × tarifa extra + horas de sábado × tarifa sábado + horas de domingo × tarifa domingo + horas de feriado × tarifa feriado' ),
			array( 'Honorario', 'incluye las horas normales; es el vigente al final del mes' ),
			array( 'Horas extra (lunes a viernes)', 'lo trabajado por encima de la jornada efectiva del horario (9 h 30 min con el horario base); es automático' ),
			array( 'Sábado, domingo y feriado', 'todas las horas del día van con su tarifa' ),
			array( 'Día libre en su horario', 'todas las horas van con la tarifa extra' ),
			array( 'Comida no marcada', $reglas->comidaNoMarcadaMin . ' min descontados en días laborables (por confirmar)' ),
			array( 'Tardanza contada desde', 'tolerancia' === $reglas->tardanzaDesde ? 'el fin de la tolerancia' : 'la hora de entrada' ),
			array( 'Faltas, tardanzas y salidas anticipadas', 'se señalan, no se descuentan' ),
			array( 'Estado', '"Resolver incidencia" si hay días incompletos o marcas por revisar; el recibo por honorarios no frena el pago' ),
			array( 'Tarifas', 'cada día usa la tarifa vigente ese día' ),
			array(),
			array( 'Generado por', $generadoPor ),
			array( 'Generado el', wp_date( 'Y-m-d H:i', null, Ajustes::zona() ) ),
		);

		$contenido = (string) SimpleXLSXGen::fromArray( $pagos, 'Pagos' )
			->addSheet( $detalle, 'Detalle por día' )
			->addSheet( $reglasHoja, 'Reglas' )
			->setTitle( 'Pagos ' . $mes )
			->setCompany( (string) $ajustes['razon_social'] );

		return array(
			'nombre'       => 'pagos-' . $mes . ( $soloListos ? '-listos' : '' ) . '.xlsx',
			'contenido'    => $contenido,
			'hash'         => hash( 'sha256', $contenido ),
			'trabajadores' => count( $personas ),
		);
	}
}

