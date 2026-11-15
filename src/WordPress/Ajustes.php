<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeZone;
use Exception;
use Pekokis\Asistencia\Nucleo\CodigoPantalla;
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
			// Cada cuánto cambia el QR de la pantalla (segundos). Las maquetas piden 30 min (1800).
			'qr_segundos'     => 30,
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
			// Reglas de horas (pago por recibos por honorarios). POR CONFIRMAR con el responsable.
			'comida_no_marcada_min'  => 60,
			'tardanza_desde'         => ReglasPago::TARDANZA_DESDE_ENTRADA,
			// Avisos (fase 4). Todos empiezan apagados; se encienden en Ajustes.
			'avisos_activos'          => array(),
			'aviso_hora_sin_salida'   => '20:00',
			'aviso_margen_ausencia'   => 10,
			'aviso_margen_refrigerio' => 10,
			'aviso_limite_salida'     => 120,
			// Webhook (Make, n8n o el bot) que recibe los avisos para mandarlos por WhatsApp.
			'webhook_avisos'          => '',
		);
	}

	/** @return array<string, mixed> */
	public static function todos(): array {
		$guardados = get_option( self::OPCION, array() );

		return wp_parse_args( is_array( $guardados ) ? $guardados : array(), self::porDefecto() );
	}

	/** Periodo del QR, siempre uno de los permitidos. */
	public static function qrSegundos(): int {
		$s = (int) self::todos()['qr_segundos'];

		return isset( CodigoPantalla::PERIODOS[ $s ] ) ? $s : CodigoPantalla::SEGUNDOS_VENTANA;
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

	/**
	 * Fecha u hora de Lima a partir de una fecha UTC de la base de datos. No usa
	 * get_date_from_gmt(), que depende de la zona horaria de WordPress (que puede
	 * no ser la del taller).
	 */
	public static function local( ?string $utc, string $formato = 'Y-m-d' ): string {
		if ( null === $utc || '' === $utc ) {
			return '';
		}

		// wp_date() traduce los meses y días ("14 de noviembre", no "November").
		return (string) wp_date( $formato, ( new \DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->getTimestamp(), self::zona() );
	}

	public static function reglasPago(): ReglasPago {
		return ReglasPago::desdeAjustes( self::todos() );
	}

	public static function diaLaboral(): DiaLaboral {
		return new DiaLaboral( self::zona(), (int) self::todos()['hora_corte'] );
	}
}
