<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\WordPress\Rutas;

defined( 'ABSPATH' ) || exit;

/**
 * Menú de gerencia en tablet (maquetas TG0–TG8): una barra de íconos a la
 * izquierda, uno por pantalla, con el número de pendientes encima. El botón
 * del logo la abre para ver los nombres. Solo se ve entre 600 y 1024 px de
 * ancho (iPad); en la computadora sigue el menú de WordPress y en el
 * celular la versión /gerencia/.
 */
final class Riel {
	/** Ícono (dashicons) de cada pantalla del menú Asistencia. */
	private const ICONOS = array(
		'pka-dashboard'    => 'dashicons-admin-home',
		'pka-asistencia'   => 'dashicons-groups',
		'pka-solicitudes'  => 'dashicons-email-alt',
		'pka-registros'    => 'dashicons-list-view',
		'pka-informe'      => 'dashicons-id',
		'pka-horarios'     => 'dashicons-clock',
		'pka-calendario'   => 'dashicons-calendar-alt',
		'pka-ausencias'    => 'dashicons-palmtree',
		'pka-historial'    => 'dashicons-shield',
		'pka-sueldos'      => 'dashicons-money-alt',
		'pka-horas'        => 'dashicons-backup',
		'pka-pagos'        => 'dashicons-calculator',
		'pka-simulador'    => 'dashicons-chart-line',
		'pka-recibos'      => 'dashicons-media-text',
		'pka-trabajadores' => 'dashicons-admin-users',
		'pka-celulares'    => 'dashicons-smartphone',
		'pka-delegar'      => 'dashicons-admin-network',
		'pka-resumen'      => 'dashicons-chart-bar',
		'pka-exportar'     => 'dashicons-download',
		'pka-ajustes'      => 'dashicons-admin-generic',
	);

	public static function registrar(): void {
		add_action( 'in_admin_header', array( self::class, 'pintar' ) );
	}

	/**
	 * Entradas del riel en el orden del menú, con separadores entre grupos.
	 *
	 * @return array<int, array{separador: bool, slug?: string, titulo?: string, icono?: string, cuenta?: int, url?: string}>
	 */
	public static function entradas(): array {
		global $submenu;

		$salida = array();

		foreach ( $submenu[ PantallaDashboard::SLUG ] ?? array() as $item ) {
			$slug = (string) ( $item[2] ?? '' );

			if ( ! current_user_can( (string) ( $item[1] ?? 'do_not_allow' ) ) ) {
				continue;
			}

			if ( str_starts_with( $slug, 'pka-g-' ) ) {
				$salida[] = array( 'separador' => true );
				continue;
			}

			$html   = (string) ( $item[0] ?? '' );
			$cuenta = preg_match( '/<span[^>]*>\D*(\d+)/', $html, $m ) ? (int) $m[1] : 0;
			$titulo = trim( (string) preg_replace( '/\s*\d+\s*$/', '', wp_strip_all_tags( $html ) ) );

			$salida[] = array(
				'separador' => false,
				'slug'      => $slug,
				'titulo'    => '' !== $titulo ? $titulo : (string) ( $item[3] ?? $slug ),
				'icono'     => self::ICONOS[ $slug ] ?? 'dashicons-admin-page',
				'cuenta'    => $cuenta,
				'url'       => admin_url( 'admin.php?page=' . $slug ),
			);
		}

		return $salida;
	}

	public static function pintar(): void {
		if ( ! Tema::activo() ) {
			return;
		}

		global $pagenow;

		$actual   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$entradas = self::entradas();

		if ( ! $entradas ) {
			return;
		}

		// Sin separadores al principio ni repetidos.
		$limpias = array();
		foreach ( $entradas as $e ) {
			if ( $e['separador'] && ( ! $limpias || end( $limpias )['separador'] ) ) {
				continue;
			}
			$limpias[] = $e;
		}

		$extra = array(
			array( 'separador' => true ),
			array( 'separador' => false, 'slug' => 'perfil', 'titulo' => 'Mi perfil y huella o rostro', 'icono' => 'dashicons-businessperson', 'cuenta' => 0, 'url' => admin_url( 'profile.php' ) ),
		);

		if ( current_user_can( 'manage_options' ) ) {
			$extra[] = array( 'separador' => false, 'slug' => 'wordpress', 'titulo' => 'WordPress', 'icono' => 'dashicons-wordpress-alt', 'cuenta' => 0, 'url' => admin_url( 'index.php' ) );
		}
		?>
		<nav class="pk-riel" id="pk-riel" aria-label="Menú de asistencia">
			<button type="button" class="pk-riel-logo" id="pk-riel-abrir" aria-expanded="false" aria-controls="pk-riel" title="Ver los nombres">
				<img src="<?php echo esc_url( Rutas::asset( 'iconos/icono-192.png' ) ); ?>" alt="" width="34" height="34">
				<span class="pk-riel-nombre">Asistencia</span>
			</button>
			<?php foreach ( array_merge( $limpias, $extra ) as $e ) : ?>
				<?php if ( $e['separador'] ) : ?>
					<span class="pk-riel-sep" aria-hidden="true"></span>
				<?php else : ?>
					<?php $activo = $e['slug'] === $actual || ( 'perfil' === $e['slug'] && 'profile.php' === $pagenow ); ?>
					<a href="<?php echo esc_url( $e['url'] ); ?>" class="pk-riel-item<?php echo $activo ? ' activo' : ''; ?>" title="<?php echo esc_attr( $e['titulo'] ); ?>" <?php echo $activo ? 'aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $e['icono'] ); ?>" aria-hidden="true"></span>
						<span class="pk-riel-nombre"><?php echo esc_html( $e['titulo'] ); ?></span>
						<?php if ( $e['cuenta'] > 0 ) : ?>
							<span class="pk-riel-cuenta" aria-label="<?php echo esc_attr( $e['cuenta'] . ' pendientes' ); ?>"><?php echo (int) $e['cuenta']; ?></span>
						<?php endif; ?>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}
}
