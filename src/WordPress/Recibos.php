<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Recibos por honorarios: uno por persona y mes, con su PDF.
 *
 * El trabajador lo envía desde la app; gerencia lo marca como recibido u
 * observado. Nada se borra: un envío se anula (solo mientras no se revisó) y
 * una corrección es un envío nuevo. Los PDF van a una carpeta privada de
 * uploads (con .htaccess que niega el acceso directo) y solo se descargan
 * desde wp-admin, con permiso para ver sueldos.
 */
final class Recibos {
	private const CARPETA = 'pka-recibos';

	/** Ruta de la carpeta privada; la crea con sus protecciones la primera vez. */
	public static function carpeta(): string {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . self::CARPETA;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.php', "<?php // Silencio.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Meses para los que la persona puede enviar recibo: el actual y los 11
	 * anteriores, desde su ingreso.
	 *
	 * @return string[] Del más reciente al más antiguo.
	 */
	public static function mesesPermitidos( array $t ): array {
		$actual = new DateTimeImmutable( 'first day of this month', Ajustes::zona() );
		$desde  = substr( max( (string) ( $t['fecha_ingreso'] ?? '' ), Ajustes::local( (string) $t['creado_en'] ) ), 0, 7 );
		$meses  = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$mes = $actual->modify( '-' . $i . ' months' )->format( 'Y-m' );

			if ( $mes < $desde ) {
				break;
			}

			$meses[] = $mes;
		}

		return $meses;
	}

	/**
	 * El trabajador envía su recibo del mes.
	 *
	 * @param array<string, mixed>                   $datos   numero, monto.
	 * @param array{tmp_name: string, size?: int}    $archivo Archivo subido (o una copia local en las pruebas).
	 * @return int|WP_Error Id del recibo.
	 */
	public static function enviar( int $trabajadorId, string $mes, array $datos, array $archivo ): int|WP_Error {
		global $wpdb;

		$t = Trabajadores::buscar( $trabajadorId );

		if ( ! $t || Trabajadores::ACTIVO !== $t['estado'] ) {
			return new WP_Error( 'pka_recibo', 'Tu perfil no está activo.', array( 'status' => 403 ) );
		}

		if ( ! in_array( $mes, self::mesesPermitidos( $t ), true ) ) {
			return new WP_Error( 'pka_recibo', 'Ese mes no está disponible para enviar recibos.', array( 'status' => 400 ) );
		}

		$actual = self::actual( $trabajadorId, $mes );

		if ( ! ValidacionRecibo::puedeEnviar( $actual['estado'] ?? null ) ) {
			return new WP_Error( 'pka_recibo', 'Ya enviaste el recibo de ese mes. Si te equivocaste, anula el envío mientras gerencia no lo revise.', array( 'status' => 409 ) );
		}

		$r       = ValidacionRecibo::normalizar( $datos );
		$errores = ValidacionRecibo::errores( $r );
		$tmp     = (string) ( $archivo['tmp_name'] ?? '' );
		$bytes   = '' !== $tmp && is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
		$inicio  = $bytes > 0 ? (string) file_get_contents( $tmp, false, null, 0, 5 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$problema = ValidacionRecibo::problemaArchivo( $bytes, $inicio );

		if ( null !== $problema ) {
			$errores['archivo'] = $problema;
		}

		if ( $errores ) {
			return new WP_Error( 'pka_recibo', 'Revisa el recibo.', array( 'status' => 400, 'errores' => $errores ) );
		}

		$nombre  = bin2hex( random_bytes( 16 ) ) . '.pdf';
		$destino = self::carpeta() . '/' . $nombre;
		$movido  = is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $destino ) : copy( $tmp, $destino );

		if ( ! $movido ) {
			return new WP_Error( 'pka_recibo', 'No se pudo guardar el archivo. Inténtalo otra vez.', array( 'status' => 500 ) );
		}

		$wpdb->insert(
			Tablas::nombre( 'recibos' ),
			array(
				'trabajador_id'  => $trabajadorId,
				'mes'            => $mes,
				'numero'         => $r['numero'],
				'monto_centimos' => $r['monto'],
				'archivo'        => $nombre,
				'archivo_bytes'  => $bytes,
				'archivo_sha256' => (string) hash_file( 'sha256', $destino ),
				'estado'         => ValidacionRecibo::ENVIADO,
				'enviado_en'     => Peticion::ahoraUtc(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Auditoria::registrar( 'recibo.enviar', 'trabajador', $trabajadorId, null, array( 'mes' => $mes, 'numero' => $r['numero'], 'monto' => \Pekokis\Asistencia\Nucleo\Sueldo::soles( (int) $r['monto'] ) ), '', $trabajadorId );
		Notificaciones::reciboEnviado( $id );

		return $id;
	}

	/** El trabajador retira su envío (solo mientras gerencia no lo revisó). */
	public static function anular( int $id, int $trabajadorId ): true|WP_Error {
		global $wpdb;

		$fila = self::buscar( $id );

		if ( ! $fila || (int) $fila['trabajador_id'] !== $trabajadorId ) {
			return new WP_Error( 'pka_recibo', 'Ese recibo no existe.', array( 'status' => 404 ) );
		}

		if ( ! ValidacionRecibo::puedeAnular( $fila['estado'] ) ) {
			return new WP_Error( 'pka_recibo', 'Gerencia ya revisó este recibo: ya no se puede anular.', array( 'status' => 409 ) );
		}

		$wpdb->update( Tablas::nombre( 'recibos' ), array( 'estado' => ValidacionRecibo::ANULADO, 'anulado_en' => Peticion::ahoraUtc() ), array( 'id' => $id ) );
		Auditoria::registrar( 'recibo.anular', 'trabajador', $trabajadorId, array( 'mes' => $fila['mes'], 'numero' => $fila['numero'] ), null, '', $trabajadorId );

		return true;
	}

	/** Gerencia lo da por recibido. */
	public static function recibir( int $id ): true|WP_Error {
		return self::revisar( $id, ValidacionRecibo::RECIBIDO, '' );
	}

	/** Gerencia lo observa: el comentario es obligatorio y el trabajador puede enviar otro. */
	public static function observar( int $id, string $comentario ): true|WP_Error {
		if ( '' === trim( $comentario ) ) {
			return new WP_Error( 'pka_recibo', 'Escribe qué hay que corregir.' );
		}

		return self::revisar( $id, ValidacionRecibo::OBSERVADO, $comentario );
	}

	private static function revisar( int $id, string $estado, string $comentario ): true|WP_Error {
		global $wpdb;

		$fila = self::buscar( $id );

		if ( ! $fila || ValidacionRecibo::ANULADO === $fila['estado'] ) {
			return new WP_Error( 'pka_recibo', 'Ese recibo no existe o fue anulado.' );
		}

		if ( Trabajadores::esPropio( (int) $fila['trabajador_id'] ) ) {
			return new WP_Error( 'pka_propio', 'No puedes revisar tu propio recibo.' );
		}

		$wpdb->update(
			Tablas::nombre( 'recibos' ),
			array( 'estado' => $estado, 'comentario' => mb_substr( trim( $comentario ), 0, 500 ), 'revisado_por' => get_current_user_id(), 'revisado_en' => Peticion::ahoraUtc() ),
			array( 'id' => $id )
		);

		Auditoria::registrar( 'recibo.' . ( ValidacionRecibo::RECIBIDO === $estado ? 'recibir' : 'observar' ), 'trabajador', (int) $fila['trabajador_id'], array( 'estado' => ValidacionRecibo::ESTADOS[ $fila['estado'] ] ), array( 'mes' => $fila['mes'], 'numero' => $fila['numero'], 'estado' => ValidacionRecibo::ESTADOS[ $estado ] ), $comentario );

		if ( ValidacionRecibo::OBSERVADO === $estado ) {
			Notificaciones::reciboObservado( $id );
		}

		return true;
	}

	/** @return array<string, mixed>|null */
	public static function buscar( int $id ): ?array {
		global $wpdb;

		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'recibos' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $fila ? $fila : null;
	}

	/** El último envío del mes que cuenta (los anulados no). */
	public static function actual( int $trabajadorId, string $mes ): ?array {
		global $wpdb;

		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'recibos' ) . " WHERE trabajador_id = %d AND mes = %s AND estado <> 'anulado' ORDER BY id DESC LIMIT 1", $trabajadorId, $mes ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $fila ? $fila : null;
	}

	/**
	 * El último envío que cuenta de cada persona en un mes.
	 *
	 * @return array<int, array<string, mixed>> trabajador_id => fila
	 */
	public static function delMes( string $mes ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'recibos' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE mes = %s AND estado <> 'anulado' ORDER BY id", $mes ), ARRAY_A );
		$porId = array();

		foreach ( $filas as $f ) {
			$porId[ (int) $f['trabajador_id'] ] = $f; // El último gana.
		}

		// Cuántos recibos ha enviado cada uno en total (sin los anulados), para "Enviados (n)".
		$cuenta = $wpdb->get_results( "SELECT trabajador_id, COUNT(*) AS n FROM {$tabla} WHERE estado <> 'anulado' GROUP BY trabajador_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $cuenta as $c ) {
			if ( isset( $porId[ (int) $c['trabajador_id'] ] ) ) {
				$porId[ (int) $c['trabajador_id'] ]['enviados'] = (int) $c['n'];
			}
		}

		return $porId;
	}

	/** @return array<int, array<string, mixed>> Todos los envíos de una persona, del más nuevo al más antiguo. */
	public static function historial( int $trabajadorId ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'recibos' ) . ' WHERE trabajador_id = %d ORDER BY mes DESC, id DESC', $trabajadorId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Lo que ve el trabajador en la app: un renglón por mes permitido, con el
	 * último envío (aunque esté anulado) y si ya se pagó.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function paraApp( array $t ): array {
		$historial = self::historial( (int) $t['id'] );
		$ultimos   = array();

		foreach ( array_reverse( $historial ) as $f ) {
			$ultimos[ $f['mes'] ] = $f; // El más reciente de cada mes.
		}

		$meses = array();

		foreach ( self::mesesPermitidos( $t ) as $mes ) {
			$f      = $ultimos[ $mes ] ?? null;
			$pagado = null !== Pagos::delTrabajador( (int) $t['id'], $mes );
			$estado = $f ? $f['estado'] : 'falta';

			$meses[] = array(
				'mes'        => $mes,
				'etiqueta'   => wp_date( 'F Y', strtotime( $mes . '-15 12:00:00' ) ),
				'estado'     => $estado,
				'texto'      => $f ? ValidacionRecibo::ESTADOS[ $estado ] : 'Falta enviar',
				'pagado'     => $pagado,
				'id'         => $f ? (int) $f['id'] : 0,
				'numero'     => $f ? $f['numero'] : '',
				'monto'      => $f ? number_format( (int) $f['monto_centimos'] / 100, 2, '.', ',' ) : '',
				'comentario' => $f && ValidacionRecibo::OBSERVADO === $estado ? $f['comentario'] : '',
				'fecha'      => $f ? Ajustes::local( ValidacionRecibo::RECIBIDO === $estado ? (string) $f['revisado_en'] : (string) $f['enviado_en'], 'd/m/Y' ) : '',
				'puede_enviar' => ValidacionRecibo::puedeEnviar( $f && ValidacionRecibo::ANULADO !== $estado ? $estado : null ),
				'puede_anular' => $f && ValidacionRecibo::puedeAnular( $estado ),
			);
		}

		return $meses;
	}

	/** Ruta del PDF guardado. */
	public static function ruta( array $fila ): string {
		return self::carpeta() . '/' . basename( (string) $fila['archivo'] );
	}
}
