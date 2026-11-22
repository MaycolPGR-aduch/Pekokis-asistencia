<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Historial;
use Pekokis\Asistencia\WordPress\InsercionEncadenada;
use Pekokis\Asistencia\WordPress\Trabajadores;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Auditoría (maqueta G5): quién cambió qué, cuándo, con el valor
 * anterior tachado, el nuevo en verde y el motivo. Solo lectura.
 */
final class PantallaHistorial {
	public const SLUG = 'pka-historial';

	private const MAX_CSV = 20000;

	public static function registrar(): void {
		add_action( 'admin_post_pka_exportar_historial', array( self::class, 'exportar' ) );
	}

	/** URL del historial con filtros (por ejemplo, de un trabajador). */
	public static function url( array $filtros = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $filtros, admin_url( 'admin.php' ) );
	}

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::AUDITORIA ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$filtros = self::filtros( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura.
		$pagina  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		[ $filas, $total ] = Historial::buscar( $filtros, $pagina );

		$items        = Historial::describir( $filas );
		$rota         = InsercionEncadenada::verificar( 'auditoria' );
		$trabajadores = array_merge( Trabajadores::listar(), Trabajadores::listar( Trabajadores::CESADO ) );
		$usuarios     = Historial::usuarios();
		$paginas      = (int) ceil( $total / Historial::POR_PAGINA );
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Auditoría de cambios <span class="pk-h1-der"><?php echo esc_html( number_format_i18n( $total ) . ( 1 === $total ? ' cambio' : ' cambios' ) ); ?></span></h1>

			<?php if ( null !== $rota ) : ?>
				<div class="notice notice-error inline"><p><strong>La auditoría fue alterada desde el registro <?php echo (int) $rota; ?>.</strong> Alguien la editó o borró directamente en la base de datos.</p></div>
			<?php endif; ?>

			<form class="pk-fila espacio" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label class="screen-reader-text" for="pka-h-usuario">Quién</label>
				<select id="pka-h-usuario" name="usuario">
					<option value="0">Quién: todos</option>
					<?php foreach ( $usuarios as $uid => $nombre ) : ?>
						<option value="<?php echo esc_attr( (string) $uid ); ?>" <?php selected( $filtros['usuario'], $uid ); ?>><?php echo esc_html( $nombre ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="pka-h-grupo">Tipo de cambio</label>
				<select id="pka-h-grupo" name="grupo">
					<option value="">Tipo de cambio: todos</option>
					<?php foreach ( Historial::GRUPOS as $clave => [ $etiqueta ] ) : ?>
						<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $filtros['grupo'], $clave ); ?>><?php echo esc_html( $etiqueta ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="pka-h-trabajador">Persona</label>
				<select id="pka-h-trabajador" name="trabajador">
					<option value="0">Persona: todas</option>
					<?php foreach ( $trabajadores as $t ) : ?>
						<option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( $filtros['trabajador'], (int) $t['id'] ); ?>><?php echo esc_html( $t['apellidos'] . ', ' . $t['nombres'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="pka-h-desde">Del</label>
				<input type="date" id="pka-h-desde" name="desde" value="<?php echo esc_attr( $filtros['desde'] ); ?>">
				<label for="pka-h-hasta">al</label>
				<input type="date" id="pka-h-hasta" name="hasta" value="<?php echo esc_attr( $filtros['hasta'] ); ?>">
				<label class="screen-reader-text" for="pka-h-texto">Buscar en motivos y datos</label>
				<input type="search" id="pka-h-texto" name="texto" placeholder="Buscar en motivos y datos" value="<?php echo esc_attr( $filtros['texto'] ); ?>">
				<button class="button">Filtrar</button>
				<?php if ( array_filter( $filtros ) ) : ?>
					<a class="button-link" href="<?php echo esc_url( self::url() ); ?>">Limpiar</a>
				<?php endif; ?>
				<?php if ( $total ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'pka_exportar_historial' ) + array_filter( $filtros ), admin_url( 'admin-post.php' ) ), 'pka_exportar_historial' ) ); ?>">Exportar</a>
				<?php endif; ?>
			</form>

			<div class="pk-desplaza">
				<table class="widefat striped pk-auditoria">
					<thead><tr><th>Cuándo</th><th>Quién</th><th>Tipo</th><th>Qué cambió</th><th>Antes</th><th>Después</th><th>Motivo</th></tr></thead>
					<tbody>
					<?php if ( ! $items ) : ?>
						<tr><td colspan="7">No hay cambios con estos filtros.</td></tr>
					<?php endif; ?>
					<?php foreach ( $items as $i ) : ?>
						<tr>
							<td class="pk-nowrap" title="<?php echo esc_attr( $i['fecha'] . ' · IP ' . $i['ip'] ); ?>"><?php echo esc_html( $i['cuando'] ); ?></td>
							<td><?php echo esc_html( $i['quien'] ); ?></td>
							<td><span class="pk-chip <?php echo esc_attr( $i['tipo'][1] ); ?>"><?php echo esc_html( $i['tipo'][0] ); ?></span></td>
							<td class="pk-ancho">
								<?php echo esc_html( $i['accion'] ); ?>
								<?php if ( '' !== $i['sobre'] ) : ?>
									· <?php echo '' !== $i['url'] ? '<a href="' . esc_url( $i['url'] ) . '">' . esc_html( $i['sobre'] ) . '</a>' : esc_html( $i['sobre'] ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php $conAntes = array_filter( $i['detalle'], static fn( $c ) => null !== $c['antes'] && '—' !== $c['antes'] ); ?>
								<?php foreach ( $conAntes as $c ) : ?>
									<span class="pk-cambio"><span class="pk-campo-de"><?php echo esc_html( $c['campo'] ); ?></span> <s class="pk-antes-t"><?php echo esc_html( $c['antes'] ); ?></s></span>
								<?php endforeach; ?>
								<?php echo $conAntes ? '' : '<span class="description">—</span>'; ?>
							</td>
							<td>
								<?php foreach ( $i['detalle'] as $c ) : ?>
									<span class="pk-cambio"><span class="pk-campo-de"><?php echo esc_html( $c['campo'] ); ?></span> <?php echo null !== $c['despues'] ? '<span class="pk-despues-t">' . esc_html( $c['despues'] ) . '</span>' : '<span class="description">quitado</span>'; ?></span>
								<?php endforeach; ?>
								<?php echo $i['detalle'] ? '' : '<span class="description">—</span>'; ?>
							</td>
							<td class="pk-ancho"><?php echo '' !== $i['motivo'] ? esc_html( $i['motivo'] ) : '<span class="description">—</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $paginas > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $pagina,
								'total'     => $paginas,
								'prev_text' => '‹ Anterior',
								'next_text' => 'Siguiente ›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>

			<p class="description pk-mt-s">
				<?php echo null === $rota ? '✔ Íntegra. ' : ''; ?>Nadie puede borrar ni editar esta lista. Cada cambio guarda quién, cuándo, el valor anterior y el nuevo, y el motivo.
			</p>
		</div>
		<?php
	}

	/** CSV con los mismos filtros (UTF-8 con BOM, separado por punto y coma, para Excel en español). */
	public static function exportar(): void {
		if ( ! current_user_can( Capacidades::AUDITORIA ) ) {
			wp_die( 'No tienes permiso para hacer esto.', 403 );
		}

		check_admin_referer( 'pka_exportar_historial' );

		$filtros           = self::filtros( $_GET );
		[ $filas, $total ] = Historial::buscar( $filtros, 1, self::MAX_CSV );

		Auditoria::registrar( 'historial.exportar', 'historial', null, null, array( 'filas' => count( $filas ) ) + array_filter( $filtros ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="historial-asistencia-' . wp_date( 'Y-m-d' ) . '.csv"' );

		$salida = fopen( 'php://output', 'w' );
		fwrite( $salida, "\xEF\xBB\xBF" );
		fputcsv( $salida, array( 'Id', 'Fecha (Lima)', 'Quién', 'Qué', 'Sobre', 'Cambios', 'Motivo', 'IP' ), ';' );

		foreach ( Historial::describir( $filas ) as $i ) {
			fputcsv( $salida, array( $i['id'], $i['fecha'], $i['quien'], $i['accion'], $i['sobre'], implode( ' | ', $i['cambios'] ), $i['motivo'], $i['ip'] ), ';' );
		}

		if ( $total > self::MAX_CSV ) {
			fputcsv( $salida, array( '', '', '', 'Se muestran los ' . self::MAX_CSV . ' más recientes de ' . $total . '. Usa los filtros de fecha.' ), ';' );
		}

		fclose( $salida );
		exit;
	}

	/**
	 * @param array<string, mixed> $origen
	 * @return array{desde: string, hasta: string, grupo: string, trabajador: int, usuario: int, texto: string}
	 */
	private static function filtros( array $origen ): array {
		$texto = static fn( string $k ): string => isset( $origen[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $origen[ $k ] ) ) : '';

		return array(
			'desde'      => $texto( 'desde' ),
			'hasta'      => $texto( 'hasta' ),
			'grupo'      => isset( Historial::GRUPOS[ $texto( 'grupo' ) ] ) ? $texto( 'grupo' ) : '',
			'trabajador' => isset( $origen['trabajador'] ) ? absint( $origen['trabajador'] ) : 0,
			'usuario'    => isset( $origen['usuario'] ) ? absint( $origen['usuario'] ) : 0,
			'texto'      => $texto( 'texto' ),
		);
	}
}
