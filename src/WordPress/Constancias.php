<?php

namespace Pekokis\Asistencia\WordPress;

use Pekokis\Asistencia\Nucleo\ValidacionConstancia;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Constancias de retención de 4.ª o 5.ª categoría (pedido de gerencia,
 * 0.23.0). El trabajador la sube desde la app (en "Mi recibo"); gerencia la
 * marca como recibida u observada, igual que los recibos. Vale hasta una
 * fecha: el panel y la app avisan desde 30 días antes de que venza.
 *
 * Los PDF van a la misma carpeta privada de los recibos. Nada se borra: un
 * envío se anula (solo mientras no se revisó) y una corrección es un envío nuevo.
 */
final class Constancias {
	/**
	 * @param array<string, mixed>                $datos   tipo, numero, vigente_hasta.
	 * @param array{tmp_name: string, size?: int} $archivo
	 */
	public static function enviar( int $trabajadorId, array $datos, array $archivo ): int|WP_Error {
		global $wpdb;

		$t = Trabajadores::buscar( $trabajadorId );

		if ( ! $t || Trabajadores::ACTIVO !== $t['estado'] ) {
			return new WP_Error( 'pka_constancia', 'Tu perfil no está activo.', array( 'status' => 403 ) );
		}

		$actual = self::actual( $trabajadorId );

		if ( ! ValidacionConstancia::puedeEnviar( $actual['estado'] ?? null ) ) {
			return new WP_Error( 'pka_constancia', 'Ya enviaste una constancia y gerencia todavía no la revisa. Si te equivocaste, anula ese envío.', array( 'status' => 409 ) );
		}

		$c        = ValidacionConstancia::normalizar( $datos );
		$errores  = ValidacionConstancia::errores( $c, self::hoy() );
		$tmp      = (string) ( $archivo['tmp_name'] ?? '' );
		$bytes    = '' !== $tmp && is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
		$inicio   = $bytes > 0 ? (string) file_get_contents( $tmp, false, null, 0, 5 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$problema = ValidacionRecibo::problemaArchivo( $bytes, $inicio );

		if ( null !== $problema ) {
			$errores['archivo'] = str_replace( 'del recibo por honorarios', 'de la constancia', str_replace( 'del recibo', 'de la constancia', $problema ) );
		}

		if ( $errores ) {
			return new WP_Error( 'pka_constancia', 'Revisa la constancia.', array( 'status' => 400, 'errores' => $errores ) );
		}

		$nombre  = 'c-' . bin2hex( random_bytes( 16 ) ) . '.pdf';
		$destino = Recibos::carpeta() . '/' . $nombre;
		$movido  = is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $destino ) : copy( $tmp, $destino );

		if ( ! $movido ) {
			return new WP_Error( 'pka_constancia', 'No se pudo guardar el archivo. Inténtalo otra vez.', array( 'status' => 500 ) );
		}

		$wpdb->insert(
			Tablas::nombre( 'constancias' ),
			array(
				'trabajador_id'  => $trabajadorId,
				'tipo'           => $c['tipo'],
				'numero'         => $c['numero'],
				'vigente_hasta'  => $c['vigente_hasta'],
				'archivo'        => $nombre,
				'archivo_bytes'  => $bytes,
				'archivo_sha256' => (string) hash_file( 'sha256', $destino ),
				'estado'         => ValidacionRecibo::ENVIADO,
				'enviado_en'     => Peticion::ahoraUtc(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'constancia.enviar', 'trabajador', $trabajadorId, null, array( 'tipo' => ValidacionConstancia::TIPOS[ $c['tipo'] ], 'numero' => $c['numero'], 'hasta' => $c['vigente_hasta'] ), '', $trabajadorId );
		Notificaciones::constanciaEnviada( $id );

		return $id;
	}

	public static function anular( int $id, int $trabajadorId ): true|WP_Error {
		global $wpdb;

		$fila = self::buscar( $id );

		if ( ! $fila || (int) $fila['trabajador_id'] !== $trabajadorId ) {
			return new WP_Error( 'pka_constancia', 'Esa constancia no existe.', array( 'status' => 404 ) );
		}

		if ( ! ValidacionRecibo::puedeAnular( $fila['estado'] ) ) {
			return new WP_Error( 'pka_constancia', 'Gerencia ya revisó esta constancia: ya no se puede anular.', array( 'status' => 409 ) );
		}

		$wpdb->update( Tablas::nombre( 'constancias' ), array( 'estado' => ValidacionRecibo::ANULADO, 'anulado_en' => Peticion::ahoraUtc() ), array( 'id' => $id ) );
		Auditoria::registrar( 'constancia.anular', 'trabajador', $trabajadorId, array( 'tipo' => ValidacionConstancia::TIPOS[ $fila['tipo'] ] ?? $fila['tipo'], 'hasta' => $fila['vigente_hasta'] ), null, '', $trabajadorId );

		return true;
	}

	public static function recibir( int $id ): true|WP_Error {
		return self::revisar( $id, ValidacionRecibo::RECIBIDO, '' );
	}

	public static function observar( int $id, string $comentario ): true|WP_Error {
		if ( '' === trim( $comentario ) ) {
			return new WP_Error( 'pka_constancia', 'Escribe qué hay que corregir.' );
		}

		return self::revisar( $id, ValidacionRecibo::OBSERVADO, $comentario );
	}

	private static function revisar( int $id, string $estado, string $comentario ): true|WP_Error {
		global $wpdb;

		if ( ! current_user_can( Capacidades::RECIBOS ) ) {
			return new WP_Error( 'pka_permiso', 'No tienes permiso para revisar constancias.' );
		}

		$fila = self::buscar( $id );

		if ( ! $fila || ValidacionRecibo::ANULADO === $fila['estado'] ) {
			return new WP_Error( 'pka_constancia', 'Esa constancia no existe o fue anulada.' );
		}

		if ( Trabajadores::esPropio( (int) $fila['trabajador_id'] ) ) {
			return new WP_Error( 'pka_propio', 'No puedes revisar tu propia constancia.' );
		}

		$wpdb->update(
			Tablas::nombre( 'constancias' ),
			array( 'estado' => $estado, 'comentario' => mb_substr( trim( $comentario ), 0, 500 ), 'revisado_por' => get_current_user_id(), 'revisado_en' => Peticion::ahoraUtc() ),
			array( 'id' => $id )
		);

		Auditoria::registrar( 'constancia.' . ( ValidacionRecibo::RECIBIDO === $estado ? 'recibir' : 'observar' ), 'trabajador', (int) $fila['trabajador_id'], array( 'estado' => ValidacionRecibo::ESTADOS[ $fila['estado'] ] ), array( 'tipo' => ValidacionConstancia::TIPOS[ $fila['tipo'] ] ?? $fila['tipo'], 'hasta' => $fila['vigente_hasta'], 'estado' => ValidacionRecibo::ESTADOS[ $estado ] ), $comentario );

		if ( ValidacionRecibo::OBSERVADO === $estado ) {
			Notificaciones::constanciaObservada( $id );
		}

		return true;
	}

	/** @return array<string, mixed>|null */
	public static function buscar( int $id ): ?array {
		global $wpdb;

		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'constancias' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $fila ? $fila : null;
	}

	/** La última que cuenta (las anuladas no). */
	public static function actual( int $trabajadorId ): ?array {
		global $wpdb;

		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'constancias' ) . " WHERE trabajador_id = %d AND estado <> 'anulado' ORDER BY id DESC LIMIT 1", $trabajadorId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $fila ? $fila : null;
	}

	/** @return array<int, array<string, mixed>> trabajador_id => la última que cuenta. */
	public static function actuales(): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'constancias' );
		$out   = array();

		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$tabla} WHERE estado <> 'anulado' ORDER BY id", ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (int) $f['trabajador_id'] ] = $f; // La última gana.
		}

		return $out;
	}

	/** La que está en vigor: la última recibida (aunque después haya otra por revisar u observada). */
	public static function vigente( int $trabajadorId ): ?array {
		global $wpdb;

		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'constancias' ) . " WHERE trabajador_id = %d AND estado = 'recibido' ORDER BY id DESC LIMIT 1", $trabajadorId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $fila ? $fila : null;
	}

	/** @return array<int, array<string, mixed>> trabajador_id => la última recibida. */
	public static function vigentes(): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'constancias' );
		$out   = array();

		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$tabla} WHERE estado = 'recibido' ORDER BY id", ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (int) $f['trabajador_id'] ] = $f;
		}

		return $out;
	}

	/** @return array<int, array<string, mixed>> Todas las de una persona, de la más nueva a la más antigua. */
	public static function historial( int $trabajadorId ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'constancias' ) . ' WHERE trabajador_id = %d ORDER BY id DESC', $trabajadorId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Las recibidas que vencen en 30 días o menos (o ya vencieron) de personas
	 * activas, para los avisos del panel.
	 *
	 * @return array<int, array{t: array<string, mixed>, c: array<string, mixed>, vigencia: string, texto: string}>
	 */
	public static function porVencer(): array {
		$hoy = self::hoy();
		$out = array();

		foreach ( self::vigentes() as $id => $c ) {
			$t = Trabajadores::buscar( $id );

			if ( ! $t || Trabajadores::ACTIVO !== $t['estado'] ) {
				continue;
			}

			$vig = ValidacionConstancia::vigencia( (string) $c['vigente_hasta'], $hoy );

			if ( ValidacionConstancia::VIGENTE !== $vig ) {
				$out[] = array( 't' => $t, 'c' => $c, 'vigencia' => $vig, 'texto' => ValidacionConstancia::textoVigencia( (string) $c['vigente_hasta'], $hoy ) );
			}
		}

		return $out;
	}

	/** Cuántas esperan revisión. */
	public static function porRevisar(): int {
		return count( array_filter( self::actuales(), static fn( array $c ): bool => ValidacionRecibo::ENVIADO === $c['estado'] ) );
	}

	/**
	 * Lo que ve el trabajador en la app.
	 *
	 * @return array<string, mixed>
	 */
	public static function paraApp( array $t ): array {
		$hoy = self::hoy();
		$c   = self::actual( (int) $t['id'] );           // La última que cuenta (para el estado).
		$v   = self::vigente( (int) $t['id'] ) ?? $c;     // La recibida en vigor (para la vigencia).
		$ok  = null !== $c;
		$vig = $v && ValidacionRecibo::RECIBIDO === $v['estado'] ? ValidacionConstancia::vigencia( (string) $v['vigente_hasta'], $hoy ) : '';

		return array(
			'tipos'          => ValidacionConstancia::TIPOS,
			'id'             => $c ? (int) $c['id'] : 0,
			'estado'         => $ok ? $c['estado'] : 'falta',
			'texto'          => $ok ? ( ValidacionRecibo::RECIBIDO === $c['estado'] && ValidacionConstancia::VENCIDA === $vig ? 'Vencida' : ValidacionRecibo::ESTADOS[ $c['estado'] ] ) : 'Sin constancia',
			'vigente_texto'  => $v && ValidacionRecibo::RECIBIDO === $v['estado'] && $v !== $c ? 'La anterior, ya recibida: ' . mb_strtolower( ValidacionConstancia::textoVigencia( (string) $v['vigente_hasta'], $hoy ) ) . '.' : '',
			'tipo'           => $ok ? $c['tipo'] : '',
			'tipo_texto'     => $ok ? ( ValidacionConstancia::TIPOS[ $c['tipo'] ] ?? $c['tipo'] ) : '',
			'numero'         => $ok ? (string) $c['numero'] : '',
			'vigente_hasta'  => $ok ? (string) $c['vigente_hasta'] : '',
			'vigencia'       => $vig,
			'vigencia_texto' => $ok ? ValidacionConstancia::textoVigencia( (string) ( $v && ValidacionRecibo::RECIBIDO === $v['estado'] ? $v : $c )['vigente_hasta'], $hoy ) : '',
			'comentario'     => $ok && ValidacionRecibo::OBSERVADO === $c['estado'] ? (string) $c['comentario'] : '',
			'puede_enviar'   => ValidacionConstancia::puedeEnviar( $ok ? $c['estado'] : null ),
			'puede_anular'   => $ok && ValidacionRecibo::puedeAnular( $c['estado'] ),
			'sugerida'       => ValidacionConstancia::vigenciaPorDefecto( $hoy ),
		);
	}

	public static function ruta( array $fila ): string {
		return Recibos::carpeta() . '/' . basename( (string) $fila['archivo'] );
	}

	private static function hoy(): string {
		return wp_date( 'Y-m-d', null, Ajustes::zona() );
	}
}
