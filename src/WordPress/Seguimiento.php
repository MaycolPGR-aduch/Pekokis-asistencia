<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\TotalesDia;

defined( 'ABSPATH' ) || exit;

/**
 * Días anteriores que necesitan atención: quedaron sin salida (incompletos) o
 * eran laborables y no tienen ninguna marca (posible falta). Los feriados y las
 * ausencias justificadas no cuentan como falta.
 *
 * Se calcula al momento a partir de las marcas, en vez de con un cron de cierre:
 * así nunca queda desactualizado cuando se aprueba una corrección.
 */
final class Seguimiento {
	public const INCOMPLETO = 'incompleto';
	public const FALTA      = 'falta';

	/**
	 * @param int[]|null $alcance
	 * @return array<int, array{trabajador: array<string, mixed>, dia: string, tipo: string}>
	 */
	public static function pendientes( ?array $alcance, string $hoy, int $dias = 7 ): array {
		$desde      = ( new DateTimeImmutable( $hoy ) )->modify( '-' . $dias . ' days' )->format( 'Y-m-d' );
		$hasta      = ( new DateTimeImmutable( $hoy ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$marcas     = Marcas::delRango( $alcance, $desde, $hasta );
		$ausencias  = Ausencias::delRango( $alcance, $desde, $hasta );
		$pendientes = array();

		foreach ( Trabajadores::listar() as $t ) {
			$id = (int) $t['id'];

			if ( is_array( $alcance ) && ! in_array( $id, $alcance, true ) ) {
				continue;
			}

			// No hay faltas antes de que existiera el perfil o de su fecha de ingreso.
			$inicio = max( (string) $t['fecha_ingreso'], Ajustes::local( (string) $t['creado_en'] ) );

			for ( $d = new DateTimeImmutable( $hasta ); $d->format( 'Y-m-d' ) >= $desde; $d = $d->modify( '-1 day' ) ) {
				$dia = $d->format( 'Y-m-d' );

				if ( $dia < $inicio ) {
					break;
				}

				$delDia = $marcas[ $id ][ $dia ] ?? array();

				if ( $delDia && TotalesDia::calcular( $delDia )->incompleto ) {
					$pendientes[] = array( 'trabajador' => $t, 'dia' => $dia, 'tipo' => self::INCOMPLETO );
				} elseif ( ! $delDia && ! isset( $ausencias[ $id ][ $dia ] ) && Horarios::delDia( (int) $t['horario_id'], $dia )->laborable ) {
					$pendientes[] = array( 'trabajador' => $t, 'dia' => $dia, 'tipo' => self::FALTA );
				}
			}
		}

		usort( $pendientes, static fn( $a, $b ) => array( $b['dia'], $a['trabajador']['apellidos'] ) <=> array( $a['dia'], $b['trabajador']['apellidos'] ) );

		return $pendientes;
	}
}
