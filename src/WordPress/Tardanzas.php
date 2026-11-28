<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registro de tardanzas con contador acumulativo (pedido de gerencia, 27-11).
 *
 * - Cada día que la primera entrada pasa la tolerancia queda una fila con los
 *   minutos desde la hora de entrada. El historial no se borra nunca.
 * - El contador suma las tardanzas vigentes (no justificadas) desde el último
 *   "reiniciar conteo" de gerencia. En la 2.ª y la 3.ª se registra una
 *   amonestación; desde la 4.ª, la persona tiene que conversar con gerencia.
 * - Solo gerencia (permiso pka_justificar) justifica una tardanza o reinicia
 *   el conteo, siempre con motivo y en la auditoría.
 */
final class Tardanzas {
	public const VIGENTE     = 'vigente';
	public const JUSTIFICADA = 'justificada';
	public const ANULADA     = 'anulada';   // La entrada se corrigió y ya no fue tarde.

	public const ESTADOS = array(
		self::VIGENTE     => 'Cuenta',
		self::JUSTIFICADA => 'Justificada',
		self::ANULADA     => 'Ya no fue tarde',
	);

	/** Desde esta tardanza en adelante (contador), la persona debe conversar con gerencia. */
	public const CONVERSAR = 4;

	/**
	 * Revisa el día después de registrar, anular o corregir marcas: crea, actualiza
	 * o anula la tardanza según la primera entrada que quedó.
	 *
	 * @return array<string, mixed>|null La fila del día, si hay.
	 */
	public static function sincronizar( int $trabajadorId, string $dia ): ?array {
		global $wpdb;

		$t = Trabajadores::buscar( $trabajadorId );

		if ( null === $t ) {
			return null;
		}

		$tabla   = Tablas::nombre( 'tardanzas' );
		$fila    = self::delDia( $trabajadorId, $dia );
		$entrada = null;

		foreach ( Marcas::delDia( $trabajadorId, $dia ) as $m ) {
			if ( TipoMarca::ENTRADA === $m->tipo && ( null === $entrada || $m->marcadoUtc < $entrada->marcadoUtc ) ) {
				$entrada = $m;
			}
		}

		$horario = Horarios::deTrabajador( $t, $dia );
		$minutos = $entrada ? ResumenDia::minutosTardanza( $entrada->marcadoUtc, $horario, $dia, Ajustes::zona() ) : 0;

		if ( $minutos <= 0 ) {
			if ( $fila && self::VIGENTE === $fila['estado'] ) {
				$wpdb->update( $tabla, array( 'estado' => self::ANULADA ), array( 'id' => (int) $fila['id'] ) );
			}

			return $fila ? self::delDia( $trabajadorId, $dia ) : null;
		}

		if ( $fila ) {
			$cambios = array( 'minutos' => $minutos, 'marca_id' => $entrada->id );
			if ( self::ANULADA === $fila['estado'] ) {
				$cambios['estado'] = self::VIGENTE;
			}
			$wpdb->update( $tabla, $cambios, array( 'id' => (int) $fila['id'] ) );

			return self::delDia( $trabajadorId, $dia );
		}

		$numero       = self::conteo( $trabajadorId ) + 1;
		$amonestacion = in_array( $numero, array( 2, 3 ), true ) ? 1 : 0;

		$wpdb->insert(
			$tabla,
			array(
				'trabajador_id' => $trabajadorId,
				'dia'           => $dia,
				'minutos'       => $minutos,
				'marca_id'      => $entrada->id,
				'numero'        => $numero,
				'amonestacion'  => $amonestacion,
				'estado'        => self::VIGENTE,
				'creado_en'     => Peticion::ahoraUtc(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'tardanza.registrar', 'trabajador', $trabajadorId, null, array( 'fecha' => $dia, 'minutos' => $minutos, 'numero' => $numero ) );

		if ( $amonestacion ) {
			Auditoria::registrar( 'tardanza.amonestacion', 'trabajador', $trabajadorId, null, array( 'fecha' => $dia, 'numero' => $numero ), 'Amonestación automática por la ' . $numero . '.ª tardanza' );
		}

		$fila = self::porId( $id );

		if ( $fila && $numero >= self::CONVERSAR ) {
			Notificaciones::tardanza( $fila );
		}

		return $fila;
	}

	/** Tardanzas que cuentan hoy para la persona (desde el último reinicio). */
	public static function conteo( int $trabajadorId ): int {
		global $wpdb;

		$t     = Trabajadores::buscar( $trabajadorId );
		$desde = $t ? (int) ( $t['tardanzas_desde_id'] ?? 0 ) : 0;
		$tabla = Tablas::nombre( 'tardanzas' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabla} WHERE trabajador_id = %d AND estado = %s AND id > %d", $trabajadorId, self::VIGENTE, $desde ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Contador de varias personas en una consulta (Hoy, Dashboard).
	 *
	 * @param array<int, array<string, mixed>> $trabajadores
	 * @return array<int, int> trabajador_id => conteo
	 */
	public static function conteos( array $trabajadores ): array {
		global $wpdb;

		if ( ! $trabajadores ) {
			return array();
		}

		$tabla = Tablas::nombre( 'tardanzas' );
		$ids   = implode( ',', array_map( static fn( $t ) => (int) $t['id'], $trabajadores ) );
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT trabajador_id, id FROM {$tabla} WHERE estado = %s AND trabajador_id IN ({$ids})", self::VIGENTE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$desde = array_column( array_map( static fn( $t ) => array( (int) $t['id'], (int) ( $t['tardanzas_desde_id'] ?? 0 ) ), $trabajadores ), 1, 0 );
		$out   = array_fill_keys( array_keys( $desde ), 0 );

		foreach ( $filas as $f ) {
			if ( (int) $f['id'] > ( $desde[ (int) $f['trabajador_id'] ] ?? 0 ) ) {
				++$out[ (int) $f['trabajador_id'] ];
			}
		}

		return $out;
	}

	/** @return array<string, mixed>|null */
	public static function delDia( int $trabajadorId, string $dia ): ?array {
		global $wpdb;

		$tabla = Tablas::nombre( 'tardanzas' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE trabajador_id = %d AND dia = %s", $trabajadorId, $dia ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $fila ?: null;
	}

	/** @return array<string, mixed>|null */
	public static function porId( int $id ): ?array {
		global $wpdb;

		$tabla = Tablas::nombre( 'tardanzas' );
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $fila ?: null;
	}

	/**
	 * Historial completo de una persona, la más reciente primero.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function historial( int $trabajadorId ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'tardanzas' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE trabajador_id = %d ORDER BY dia DESC, id DESC", $trabajadorId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function justificar( int $id, string $motivo ): true|WP_Error {
		global $wpdb;

		if ( ! current_user_can( Capacidades::JUSTIFICAR ) ) {
			return new WP_Error( 'pka_permiso', 'Solo gerencia puede justificar una tardanza.' );
		}

		$fila = self::porId( $id );

		if ( ! $fila || self::VIGENTE !== $fila['estado'] ) {
			return new WP_Error( 'pka_no_existe', 'Esa tardanza no existe o ya no cuenta.' );
		}

		if ( Trabajadores::esPropio( (int) $fila['trabajador_id'] ) ) {
			return new WP_Error( 'pka_propio', 'No puedes justificar tus propias tardanzas: lo hace otra persona de gerencia.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe el motivo de la justificación.' );
		}

		$wpdb->update(
			Tablas::nombre( 'tardanzas' ),
			array( 'estado' => self::JUSTIFICADA, 'justificacion' => mb_substr( $motivo, 0, 255 ), 'justificado_por' => get_current_user_id(), 'justificado_en' => Peticion::ahoraUtc() ),
			array( 'id' => $id )
		);

		Auditoria::registrar( 'tardanza.justificar', 'trabajador', (int) $fila['trabajador_id'], array( 'estado' => 'Cuenta' ), array( 'fecha' => $fila['dia'], 'minutos' => (int) $fila['minutos'], 'estado' => 'Justificada' ), $motivo );

		return true;
	}

	/**
	 * Vuelve a revisar las tardanzas de un periodo (hasta hoy y como mucho 120
	 * días atrás): cuando cambia el horario de una persona o el calendario.
	 *
	 * @param int[]|null $ids null = todas las personas activas.
	 */
	public static function resincronizar( ?array $ids, string $desde, string $hasta ): void {
		$hoy   = wp_date( 'Y-m-d', null, Ajustes::zona() );
		$desde = max( $desde, ( new \DateTimeImmutable( $hoy ) )->modify( '-120 days' )->format( 'Y-m-d' ) );
		$hasta = min( $hasta, $hoy );
		$ids ??= array_map( static fn( array $t ): int => (int) $t['id'], Trabajadores::listar() );

		if ( $desde > $hasta ) {
			return;
		}

		foreach ( $ids as $id ) {
			for ( $d = new \DateTimeImmutable( $desde ); $d->format( 'Y-m-d' ) <= $hasta; $d = $d->modify( '+1 day' ) ) {
				self::sincronizar( (int) $id, $d->format( 'Y-m-d' ) );
			}
		}
	}

	/** Vuelve el contador a cero. El historial se conserva. */
	public static function reiniciar( int $trabajadorId, string $motivo ): true|WP_Error {
		global $wpdb;

		if ( ! current_user_can( Capacidades::JUSTIFICAR ) ) {
			return new WP_Error( 'pka_permiso', 'Solo gerencia puede reiniciar el conteo.' );
		}

		if ( Trabajadores::esPropio( $trabajadorId ) ) {
			return new WP_Error( 'pka_propio', 'No puedes reiniciar tu propio conteo: lo hace otra persona de gerencia.' );
		}

		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'pka_motivo', 'Escribe el motivo (por ejemplo, "conversó con gerencia el 27/11").' );
		}

		$antes = self::conteo( $trabajadorId );
		$tabla = Tablas::nombre( 'tardanzas' );
		$ultimo = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$tabla}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->update( Tablas::nombre( 'trabajadores' ), array( 'tardanzas_desde_id' => $ultimo ), array( 'id' => $trabajadorId ) );
		Auditoria::registrar( 'tardanza.reiniciar', 'trabajador', $trabajadorId, array( 'conteo' => $antes ), array( 'conteo' => 0 ), $motivo );

		return true;
	}

	/**
	 * Aviso para el trabajador según su tardanza (texto editable en Seguridad y reglas).
	 *
	 * @param array<string, mixed> $fila
	 * @return array{nivel: int, texto: string, minutos: int, numero: int}
	 */
	public static function aviso( array $fila ): array {
		$numero = (int) $fila['numero'];
		$nivel  = min( self::CONVERSAR, max( 1, $numero ) );
		$textos = (array) ( Ajustes::todos()['tardanza_textos'] ?? array() );
		$texto  = (string) ( $textos[ $nivel ] ?? ( Ajustes::porDefecto()['tardanza_textos'][ $nivel ] ?? '' ) );

		return array(
			'nivel'   => $nivel,
			'minutos' => (int) $fila['minutos'],
			'numero'  => $numero,
			'texto'   => strtr( $texto, array( '{minutos}' => (string) (int) $fila['minutos'], '{numero}' => (string) $numero ) ),
		);
	}

	/** "3.ª" */
	public static function ordinal( int $n ): string {
		return $n . '.ª';
	}

	/** Hora local de la entrada de una tardanza ("09:12"). */
	public static function horaEntrada( array $fila ): string {
		global $wpdb;

		$m    = Tablas::nombre( 'marcas' );
		$utc  = $fila['marca_id'] ? $wpdb->get_var( $wpdb->prepare( "SELECT marcado_utc FROM {$m} WHERE id = %d", (int) $fila['marca_id'] ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $utc ? ( new DateTimeImmutable( (string) $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( Ajustes::zona() )->format( 'H:i' ) : '';
	}
}
