<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * PIN de ingreso, cumpleaños y confirmación de identidad (maquetas T0B, T1B,
 * T1H y T7C).
 *
 * - El PIN solo sirve para iniciar sesión, nunca para marcar. Se guarda con hash.
 * - El cumpleaños se guarda como "MM-DD", sin el año: basta para confirmar la
 *   identidad junto con el DNI y es menos dato personal.
 */
final class ValidacionIdentidad {
	public const PIN_LARGO = 6;

	public static function normalizarPin( mixed $pin ): string {
		return preg_replace( '/\D/', '', (string) $pin );
	}

	/** Problema del PIN nuevo, o null si sirve. */
	public static function errorPin( string $pin, int $largo = self::PIN_LARGO ): ?string {
		if ( ! preg_match( '/^\d{' . $largo . '}$/', $pin ) ) {
			return 'El PIN debe tener ' . $largo . ' números.';
		}

		if ( 1 === count( array_unique( str_split( $pin ) ) ) ) {
			return 'Elige un PIN que no repita el mismo número.';
		}

		if ( str_contains( '01234567890', $pin ) || str_contains( '09876543210', $pin ) ) {
			return 'Elige un PIN que no sea una escalera (123456).';
		}

		return null;
	}

	/** "MM-DD", o "" si la fecha no existe (el 29 de febrero sí vale). */
	public static function cumpleanos( mixed $dia, mixed $mes ): string {
		$d = (int) $dia;
		$m = (int) $mes;

		if ( $m < 1 || $m > 12 || $d < 1 || ! checkdate( $m, $d, 2024 ) ) {
			return '';
		}

		return sprintf( '%02d-%02d', $m, $d );
	}

	/** ¿Coinciden el DNI y el cumpleaños con los del perfil? Compara en tiempo constante. */
	public static function esLaPersona( string $dniPerfil, string $cumplePerfil, string $dniDado, string $cumpleDado ): bool {
		$dniDado = strtoupper( preg_replace( '/[\s.-]+/', '', $dniDado ) );

		if ( '' === $cumplePerfil || '' === $cumpleDado || '' === $dniDado ) {
			return false;
		}

		// Las dos comparaciones siempre se hacen, para no revelar cuál falló.
		$dni    = hash_equals( strtoupper( $dniPerfil ), $dniDado );
		$cumple = hash_equals( $cumplePerfil, $cumpleDado );

		return $dni && $cumple;
	}
}
