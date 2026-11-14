<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Shuchkin\SimpleXLSXGen;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-planilla mensual en Excel para el contador (fase 2). Solo la descarga
 * quien puede ver sueldos. Los montos son ESTIMADOS.
 *
 * - "Pre-planilla": un renglón por trabajador con días, horas y montos.
 * - "Detalle por día": cómo se calculó cada día.
 * - "Reglas": las reglas con que se calculó, para que el contador las revise.
 */
final class PrePlanillas {
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
	public static function excel( string $mes, string $generadoPor ): array {
		$ajustes  = Ajustes::todos();
		$reglas   = Ajustes::reglasPago();
		$personas = self::trabajadoresDelMes( $mes );
		$desde    = $mes . '-01';
		$hasta    = min( ( new DateTimeImmutable( $desde ) )->format( 'Y-m-t' ), Jornadas::ayer() );
		$filas    = Jornadas::prePlanilla( $personas, $mes );
		$jornadas = $hasta >= $desde ? Jornadas::delRango( $personas, $desde, $hasta ) : array();
		$horas    = static fn( int $min ): float => round( $min / 60, 2 );
		$soles    = static fn( int $cts ): float => round( $cts / 100, 2 );

		$t1 = $reglas->tasaExtra1;
		$t2 = $reglas->tasaExtra2;
		$td = $reglas->tasaDescanso;

		$resumen = array(
			array( '<b>Pre-planilla ' . $mes . ' — ' . $ajustes['razon_social'] . ' (RUC ' . $ajustes['ruc'] . ')</b>' ),
			array( 'MONTOS ESTIMADOS: reglas por confirmar con el contador. Días calculados hasta el ' . $hasta . '.' ),
			array(),
			array(
				'<b>DNI</b>', '<b>Apellidos y nombres</b>', '<b>Modalidad</b>', '<b>Sueldo (S/)</b>', '<b>Valor hora (S/)</b>',
				'<b>Días laborables</b>', '<b>Días trabajados</b>', '<b>Faltas</b>', '<b>Justificados</b>', '<b>Incompletos</b>', '<b>Por revisar</b>',
				'<b>Tardanza (h)</b>', '<b>No trabajado (h)</b>',
				'<b>Extra ' . $t1 . ' % (h)</b>', '<b>Extra ' . $t2 . ' % (h)</b>', '<b>Descanso/feriado (h)</b>', '<b>A compensar con descanso (h)</b>', '<b>Fuera de horario sin autorizar (h)</b>',
				'<b>Base (S/)</b>', '<b>Descuento (S/)</b>', '<b>Extra ' . $t1 . ' % (S/)</b>', '<b>Extra ' . $t2 . ' % (S/)</b>', '<b>Descanso/feriado (S/)</b>', '<b>Total estimado (S/)</b>',
				'<b>Observaciones</b>',
			),
		);

		$detalle = array(
			array(
				'<b>DNI</b>', '<b>Apellidos y nombres</b>', '<b>Fecha</b>', '<b>Estado</b>', '<b>Esperado (min)</b>', '<b>Trabajado (min)</b>', '<b>En horario (min)</b>',
				'<b>Salidas pagadas (min)</b>', '<b>Refrigerio (min)</b>', '<b>Tardanza (min)</b>', '<b>Salida anticipada (min)</b>', '<b>Exceso refrigerio (min)</b>',
				'<b>No trabajado (min)</b>', '<b>Extra ' . $t1 . ' % (min)</b>', '<b>Extra ' . $t2 . ' % (min)</b>', '<b>Descanso/feriado (min)</b>', '<b>A compensar (min)</b>', '<b>Sin autorizar (min)</b>', '<b>Detalle</b>',
			),
		);

		foreach ( $personas as $t ) {
			$id     = (int) $t['id'];
			$nombre = $t['apellidos'] . ', ' . $t['nombres'];
			$f      = $filas[ $id ] ?? PrePlanilla::calcular( array(), array(), $reglas );
			$s      = $f->sueldoFinal;
			$obs    = array();

			if ( $f->diasSinSueldo ) {
				$obs[] = $f->diasSinSueldo . ' días sin sueldo registrado';
			}

			if ( $f->cambioDeSueldo ) {
				$obs[] = 'cambió el sueldo en el mes (base prorrateada)';
			}

			if ( $f->dias['por_revisar'] || $f->dias['incompletos'] ) {
				$obs[] = 'hay días por revisar o incompletos';
			}

			if ( Trabajadores::CESADO === $t['estado'] ) {
				$obs[] = 'cesó el ' . $t['fecha_cese'];
			}

			$resumen[] = array(
				$t['dni'], $nombre,
				$s ? Sueldo::MODALIDADES[ $s->modalidad ] : 'Sin sueldo',
				$s ? $soles( $s->montoCentimos ) : '',
				$s ? round( $s->valorHora( $reglas ) / 100, 2 ) : '',
				$f->dias['laborables'], $f->dias['trabajados'], $f->dias['faltas'], $f->dias['justificados'], $f->dias['incompletos'], $f->dias['por_revisar'],
				$horas( $f->minutos['tardanza'] ), $horas( $f->minutos['no_trabajados'] ),
				$horas( $f->minutos['extra_1'] ), $horas( $f->minutos['extra_2'] ), $horas( $f->minutos['descanso'] ), $horas( $f->minutos['compensables'] ), $horas( $f->minutos['sin_autorizar'] ),
				$soles( $f->montos['base'] ), $soles( $f->montos['descuento'] ), $soles( $f->montos['extra_1'] ), $soles( $f->montos['extra_2'] ), $soles( $f->montos['descanso'] ), $soles( $f->montos['total'] ),
				implode( '; ', $obs ),
			);

			foreach ( $jornadas[ $id ] ?? array() as $dia => $j ) {
				/** @var CalculoJornada $j */
				$detalle[] = array(
					$t['dni'], $nombre, $dia, CalculoJornada::ESTADOS[ $j->estado ] ?? $j->estado,
					$j->minutosEsperados, $j->minutosTrabajados, $j->minutosOrdinarios, $j->minutosSalidasPagadas, $j->minutosRefrigerio,
					$j->minutosTardanza, $j->minutosSalidaAnticipada, $j->minutosExcesoRefrigerio, $j->minutosNoTrabajados,
					$j->minutosExtra1, $j->minutosExtra2, $j->minutosDescansoTrabajado, $j->minutosCompensables, $j->minutosSinAutorizar,
					$j->detalle,
				);
			}
		}

		$reglasHoja = array(
			array( '<b>Regla</b>', '<b>Valor</b>' ),
			array( 'Sobretasa de las primeras horas extra', $t1 . ' %' ),
			array( 'Minutos del día a esa sobretasa', $reglas->tramoExtra1Min ),
			array( 'Sobretasa de las siguientes horas extra', $t2 . ' %' ),
			array( 'Sobretasa por descanso o feriado trabajado', $td . ' %' ),
			array( 'Valor hora (sueldo mensual)', 'sueldo ÷ 30 ÷ ' . $reglas->horasJornadaValor . ' h' ),
			array( 'Tardanza contada desde', 'tolerancia' === $reglas->tardanzaDesde ? 'el fin de la tolerancia' : 'la hora de entrada' ),
			array( 'Se descuenta lo no trabajado', $reglas->descontarNoTrabajado ? 'Sí (solo sueldo mensual; una falta = ' . $reglas->horasJornadaValor . ' h; los días incompletos no se descuentan hasta corregirlos)' : 'No' ),
			array( 'Base del sueldo mensual', 'sueldo vigente de cada día ÷ días del mes; si alguien ingresó o cesó en el mes, solo sus días' ),
			array( 'Pago por hora', 'horas trabajadas dentro del horario × precio de la hora' ),
			array( 'Horas extra', 'solo lo trabajado dentro de una franja autorizada; valor hora × (1 + sobretasa)' ),
			array( 'Salidas que cuentan como trabajadas', 'las de motivo pagado (encargo, lactancia); si requieren aprobación, solo aprobadas' ),
			array(),
			array( 'Generado por', $generadoPor ),
			array( 'Generado el', wp_date( 'Y-m-d H:i', null, Ajustes::zona() ) ),
			array( 'Aviso', 'Montos estimados para el contador. No reemplazan la boleta ni el cálculo de planilla.' ),
		);

		$contenido = (string) SimpleXLSXGen::fromArray( $resumen, 'Pre-planilla' )
			->addSheet( $detalle, 'Detalle por día' )
			->addSheet( $reglasHoja, 'Reglas' )
			->setTitle( 'Pre-planilla ' . $mes )
			->setCompany( (string) $ajustes['razon_social'] );

		return array(
			'nombre'       => 'pre-planilla-' . $mes . '.xlsx',
			'contenido'    => $contenido,
			'hash'         => hash( 'sha256', $contenido ),
			'trabajadores' => count( $personas ),
		);
	}
}
