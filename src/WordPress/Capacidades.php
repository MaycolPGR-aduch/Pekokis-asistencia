<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Roles y capacidades del panel.
 *
 * Los trabajadores NO son usuarios de WordPress: se identifican solo con su passkey.
 * Solo jefes, RR.HH. y administradores entran a wp-admin. Un trabajador que además
 * es jefe tiene las dos cosas, enlazadas por trabajadores.user_id.
 */
final class Capacidades {
	public const VER_EQUIPO         = 'pka_ver_equipo';
	public const VER_TODO           = 'pka_ver_todo';
	public const APROBAR            = 'pka_aprobar';
	public const MARCA_MANUAL       = 'pka_marca_manual';
	public const GESTIONAR_PERSONAL = 'pka_gestionar_personal';
	public const VER_SUELDOS        = 'pka_ver_sueldos';
	public const AJUSTES            = 'pka_ajustes';
	public const AUDITORIA          = 'pka_auditoria';
	public const EXPORTAR           = 'pka_exportar';
	// Se pueden delegar por separado (maquetas G17).
	public const HORARIOS           = 'pka_horarios';
	public const RECIBOS            = 'pka_recibos';

	public const ROL_JEFE     = 'pka_jefe';
	public const ROL_RRHH     = 'pka_rrhh';
	public const ROL_GERENCIA = 'pka_gerencia';
	// Trabajador con permisos delegados (G17): el rol no da nada; los permisos van en su usuario.
	public const ROL_DELEGADO = 'pka_delegado';

	/** @return string[] */
	public static function deJefe(): array {
		return array( self::VER_EQUIPO, self::APROBAR, self::MARCA_MANUAL );
	}

	/** @return string[] */
	public static function deRrhh(): array {
		return array(
			self::VER_EQUIPO,
			self::VER_TODO,
			self::APROBAR,
			self::MARCA_MANUAL,
			self::GESTIONAR_PERSONAL,
			self::VER_SUELDOS,
			self::AJUSTES,
			self::AUDITORIA,
			self::EXPORTAR,
			self::HORARIOS,
			self::RECIBOS,
		);
	}

	/** Gerencia: solo lectura. Ve el panel de todos, el historial y descarga el registro; no corrige nada. */
	public static function deGerencia(): array {
		return array( self::VER_EQUIPO, self::VER_TODO, self::AUDITORIA, self::EXPORTAR );
	}

	public static function registrarRoles(): void {
		self::asegurarRol( self::ROL_JEFE, 'Jefe de área (asistencia)', self::deJefe() );
		self::asegurarRol( self::ROL_RRHH, 'RR.HH. (asistencia)', self::deRrhh() );
		self::asegurarRol( self::ROL_GERENCIA, 'Gerencia (asistencia, solo lectura)', self::deGerencia() );
		self::asegurarRol( self::ROL_DELEGADO, 'Supervisor delegado (asistencia)', array() );

		$administrador = get_role( 'administrator' );

		if ( $administrador ) {
			foreach ( self::deRrhh() as $capacidad ) {
				$administrador->add_cap( $capacidad );
			}
		}
	}

	/** @param string[] $capacidades */
	private static function asegurarRol( string $rol, string $nombre, array $capacidades ): void {
		$objeto = get_role( $rol ) ?? add_role( $rol, $nombre, array( 'read' => true ) );

		if ( ! $objeto ) {
			return;
		}

		foreach ( $capacidades as $capacidad ) {
			$objeto->add_cap( $capacidad );
		}
	}
}
