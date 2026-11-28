<?php

namespace Pekokis\Asistencia\Nucleo;

use DateTimeImmutable;

/**
 * Limpieza y validación de los datos del perfil de un trabajador.
 */
final class ValidacionTrabajador {
	/**
	 * @param array<string, mixed> $datos
	 * @return array<string, mixed>
	 */
	public static function normalizar( array $datos ): array {
		$texto = static fn( string $campo ): string => trim( preg_replace( '/\s+/u', ' ', (string) ( $datos[ $campo ] ?? '' ) ) );

		return array(
			'dni'           => strtoupper( preg_replace( '/[\s.-]+/', '', (string) ( $datos['dni'] ?? '' ) ) ),
			'nombres'       => $texto( 'nombres' ),
			'apellidos'     => $texto( 'apellidos' ),
			'telefono'      => preg_replace( '/[^\d+]/', '', (string) ( $datos['telefono'] ?? '' ) ),
			'correo'        => strtolower( trim( (string) ( $datos['correo'] ?? '' ) ) ),
			'cargo'         => $texto( 'cargo' ),
			'area'          => self::unirAreas( $datos ),
			'fecha_ingreso' => trim( (string) ( $datos['fecha_ingreso'] ?? '' ) ),
			'horario_id'    => (int) ( $datos['horario_id'] ?? 0 ),
			'jefe_user_id'  => (int) ( $datos['jefe_user_id'] ?? 0 ),
			'user_id'       => (int) ( $datos['user_id'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $datos Datos ya normalizados.
	 * @return array<string, string> Mensaje de error por campo; vacío si todo está bien.
	 */
	public static function errores( array $datos ): array {
		$errores = array();

		if ( ! preg_match( '/^\d{8}$/', $datos['dni'] ) && ! preg_match( '/^[A-Z0-9]{9,12}$/', $datos['dni'] ) ) {
			$errores['dni'] = 'El DNI debe tener 8 dígitos (o de 9 a 12 caracteres si es carné de extranjería).';
		}

		foreach ( array( 'nombres' => 'los nombres', 'apellidos' => 'los apellidos' ) as $campo => $nombre ) {
			if ( '' === $datos[ $campo ] ) {
				$errores[ $campo ] = 'Escribe ' . $nombre . '.';
			} elseif ( mb_strlen( $datos[ $campo ] ) > 120 ) {
				$errores[ $campo ] = 'Máximo 120 caracteres.';
			}
		}

		if ( '' !== $datos['correo'] && false === filter_var( $datos['correo'], FILTER_VALIDATE_EMAIL ) ) {
			$errores['correo'] = 'El correo no es válido.';
		}

		if ( '' !== $datos['telefono'] && ! preg_match( '/^\+?\d{6,15}$/', $datos['telefono'] ) ) {
			$errores['telefono'] = 'El teléfono debe tener entre 6 y 15 dígitos.';
		}

		if ( '' !== $datos['fecha_ingreso'] && ! self::esFecha( $datos['fecha_ingreso'] ) ) {
			$errores['fecha_ingreso'] = 'La fecha de ingreso no es válida.';
		}

		if ( $datos['horario_id'] <= 0 ) {
			$errores['horario_id'] = 'Elige un horario.';
		}

		if ( mb_strlen( $datos['cargo'] ) > 120 ) {
			$errores['cargo'] = 'Máximo 120 caracteres.';
		}

		if ( mb_strlen( $datos['area'] ) > 255 ) {
			$errores['area'] = 'Demasiadas áreas: máximo 255 caracteres en total.';
		}

		return $errores;
	}

	/**
	 * Varias áreas (pedido de gerencia, 0.23.0): las casillas ("areas") más las
	 * nuevas escritas en "area_otra", o el texto "area" separado por comas. Se
	 * guardan juntas, sin repetir, como "Costura, Estampado".
	 *
	 * @param array<string, mixed> $datos
	 */
	public static function unirAreas( array $datos ): string {
		$lista = isset( $datos['areas'] ) && is_array( $datos['areas'] ) ? $datos['areas'] : explode( ',', (string) ( $datos['area'] ?? '' ) );
		$lista = array_merge( $lista, explode( ',', (string) ( $datos['area_otra'] ?? '' ) ) );
		$vistas = array();
		$areas  = array();
		$conocidas = array();

		// Si ya existe con otra forma ("produccion" y "Producción"), se usa la que ya existe.
		foreach ( (array) ( $datos['areas_conocidas'] ?? array() ) as $c ) {
			$conocidas[ self::claveArea( (string) $c ) ] ??= (string) $c;
		}

		foreach ( $lista as $a ) {
			$a     = trim( (string) preg_replace( '/\s+/u', ' ', (string) $a ) );
			$clave = self::claveArea( $a );

			if ( '' === $a || isset( $vistas[ $clave ] ) ) {
				continue;
			}

			$vistas[ $clave ] = true;
			$areas[]          = $conocidas[ $clave ] ?? $a;
		}

		return implode( ', ', $areas );
	}

	/** "Producción" y "produccion" son la misma área. */
	public static function claveArea( string $area ): string {
		return strtr( mb_strtolower( trim( $area ) ), array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' ) );
	}

	/** "Costura, Estampado" → ["Costura", "Estampado"]. @return string[] */
	public static function areas( string $area ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $area ) ), static fn( string $a ): bool => '' !== $a ) );
	}

	public static function esFecha( string $fecha ): bool {
		$valor = DateTimeImmutable::createFromFormat( '!Y-m-d', $fecha );

		return false !== $valor && $valor->format( 'Y-m-d' ) === $fecha;
	}
}
