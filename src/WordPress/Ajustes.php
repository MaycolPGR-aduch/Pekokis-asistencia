<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeZone;
use Exception;
use Pekokis\Asistencia\Nucleo\DiaLaboral;
use Pekokis\Asistencia\Nucleo\ModoPresencia;
use Pekokis\Asistencia\Nucleo\ReglasPago;

defined( 'ABSPATH' ) || exit;

/**
 * Ajustes generales del sistema, guardados en una sola opción.
 */
final class Ajustes {
	public const OPCION = 'pka_ajustes';

	/** @return array<string, mixed> */
	public static function porDefecto(): array {
		return array(
			'modo_presencia'  => ModoPresencia::WIFI_O_QR->value,
			'zona_horaria'    => 'America/Lima',
			'hora_corte'      => 4,
			'antirrebote_seg' => 120,
			'pase_minutos'    => 3,
			// Datos del empleador que exige el registro de asistencia (D.S. 004-2006-TR).
			// Tomados del Libro de Reclamaciones; RR.HH. puede corregirlos.
			'razon_social'    => 'FUSION ART SAC',
			'ruc'             => '20523257146',
			'direccion_empleador' => 'Calle José Domingo Choquehuanca 284, San Miguel, Lima',
			// Destino del respaldo mensual; vacío = correo del administrador.
			'correo_rrhh'     => '',
			// Webhook (Make, n8n…) que recibe el Excel del respaldo para guardarlo en la nube.
			'webhook_respaldo' => '',
			// Aviso de datos personales: vacío = borrador por defecto (Aviso::borrador()).
			'aviso_texto'     => '',
			'aviso_version'   => 1,
			// Reglas de horas y pre-planilla (fase 2). POR CONFIRMAR CON EL CONTADOR.
			'tasa_extra_1'           => 25,
			'tasa_extra_2'           => 35,
			'tramo_extra_1_min'      => 120,
			'tasa_descanso'          => 100,
			'horas_jornada_valor'    => 8,
			'descontar_no_trabajado' => 1,
			'tardanza_desde'         => ReglasPago::TARDANZA_DESDE_ENTRADA,
			'aviso_permanencia_min'  => 30,
		);
	}

	/** @return array<string, mixed> */
	public static function todos(): array {
		$guardados = get_option( self::OPCION, array() );

		return wp_parse_args( is_array( $guardados ) ? $guardados : array(), self::porDefecto() );
	}

	public static function modoPresencia(): ModoPresencia {
		return ModoPresencia::tryFrom( (string) self::todos()['modo_presencia'] ) ?? ModoPresencia::WIFI_O_QR;
	}

	public static function zona(): DateTimeZone {
		try {
			return new DateTimeZone( (string) self::todos()['zona_horaria'] );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'America/Lima' );
		}
	}

	public static function reglasPago(): ReglasPago {
		return ReglasPago::desdeAjustes( self::todos() );
	}

	public static function diaLaboral(): DiaLaboral {
		return new DiaLaboral( self::zona(), (int) self::todos()['hora_corte'] );
	}
}
