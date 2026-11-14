<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Historial de cambios: quién hizo qué, cuándo, desde dónde y por qué.
 * Solo admite inserciones y va encadenado con hashes.
 */
final class Auditoria {
	/**
	 * @param array<string, mixed>|null $antes
	 * @param array<string, mixed>|null $despues
	 */
	public static function registrar(
		string $accion,
		string $entidad,
		?int $entidadId,
		?array $antes = null,
		?array $despues = null,
		string $motivo = '',
		?int $actorTrabajador = null
	): void {
		// Lo que hace el trabajador desde su celular es suyo, aunque en ese navegador
		// haya una sesión de wp-admin abierta (por ejemplo, un jefe probando).
		$usuario = null === $actorTrabajador ? get_current_user_id() : 0;

		InsercionEncadenada::insertar(
			'auditoria',
			array(
				'actor_user_id'       => $usuario ? $usuario : null,
				'actor_trabajador_id' => $actorTrabajador,
				'accion'              => $accion,
				'entidad'             => $entidad,
				'entidad_id'          => $entidadId,
				'antes'               => null === $antes ? null : wp_json_encode( $antes, JSON_UNESCAPED_UNICODE ),
				'despues'             => null === $despues ? null : wp_json_encode( $despues, JSON_UNESCAPED_UNICODE ),
				'motivo'              => mb_substr( $motivo, 0, 500 ),
				'ip'                  => Peticion::ip(),
				'creado_utc'          => Peticion::ahoraUtc(),
			)
		);
	}

	/**
	 * Solo los campos que cambiaron, para que el historial sea legible.
	 *
	 * @param array<string, mixed> $antes
	 * @param array<string, mixed> $despues
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	public static function diferencias( array $antes, array $despues ): array {
		$a = array();
		$d = array();

		foreach ( $despues as $campo => $valor ) {
			if ( array_key_exists( $campo, $antes ) && (string) $antes[ $campo ] !== (string) $valor ) {
				$a[ $campo ] = $antes[ $campo ];
				$d[ $campo ] = $valor;
			}
		}

		return array( $a, $d );
	}
}
