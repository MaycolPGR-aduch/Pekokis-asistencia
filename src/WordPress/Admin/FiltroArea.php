<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;
use Pekokis\Asistencia\WordPress\Trabajadores;

defined( 'ABSPATH' ) || exit;

/**
 * Filtro por área (pedido de gerencia, 0.23.0). Una persona puede estar en
 * varias áreas; ninguna tiene jefe. Se usa en Perfiles, Asistencia de hoy, la
 * Calculadora de pagos y la Contabilidad de horas, con el parámetro ?area=.
 */
final class FiltroArea {
	/** El área pedida en la dirección, si existe; '' = todas. */
	public static function actual(): string {
		$area = isset( $_GET['area'] ) ? sanitize_text_field( wp_unslash( $_GET['area'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		foreach ( Trabajadores::areasEnUso() as $a ) {
			if ( ValidacionTrabajador::claveArea( $a ) === ValidacionTrabajador::claveArea( $area ) ) {
				return $a;
			}
		}

		return '';
	}

	/**
	 * @param array<int, array<string, mixed>> $personas
	 * @return array<int, array<string, mixed>>
	 */
	public static function aplicar( array $personas, string $area ): array {
		if ( '' === $area ) {
			return $personas;
		}

		$clave = ValidacionTrabajador::claveArea( $area );

		return array_values( array_filter( $personas, static fn( array $t ): bool => in_array( $clave, array_map( array( ValidacionTrabajador::class, 'claveArea' ), ValidacionTrabajador::areas( (string) $t['area'] ) ), true ) ) );
	}

	/** @return int[]|null Ids de quienes están en el área (null = todas). */
	public static function ids( string $area ): ?array {
		if ( '' === $area ) {
			return null;
		}

		// También quienes cesaron: el mes puede incluirlos.
		return array_map( static fn( array $t ): int => (int) $t['id'], self::aplicar( array_merge( Trabajadores::listar(), Trabajadores::listar( Trabajadores::CESADO ) ), $area ) );
	}

	/** El selector (dentro de un formulario GET); no pinta nada si no hay áreas. */
	public static function selector( string $actual, string $id = 'pk-f-area' ): void {
		$areas = Trabajadores::areasEnUso();

		if ( ! $areas ) {
			return;
		}
		?>
		<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">Área</label>
		<select id="<?php echo esc_attr( $id ); ?>" name="area" onchange="this.form.submit()">
			<option value="">Todas las áreas</option>
			<?php foreach ( $areas as $a ) : ?>
				<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $actual, $a ); ?>><?php echo esc_html( $a ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/** Chips con las áreas de una persona. */
	public static function chips( string $area ): string {
		return implode( ' ', array_map( static fn( string $a ): string => '<span class="pk-chip gris">' . esc_html( $a ) . '</span>', ValidacionTrabajador::areas( $area ) ) );
	}
}
