<?php

namespace Pekokis\Asistencia\Nucleo;

/**
 * Motivos de salida con los que arranca el sistema (plan técnico, sección 6.1).
 */
final class CatalogoMotivos {
	/** @return Motivo[] */
	public static function porDefecto(): array {
		return array(
			new Motivo( 'refrigerio', 'Refrigerio', CategoriaMotivo::REFRIGERIO, false, false, false, true, 10 ),
			new Motivo( 'encargo', 'Encargo de Pekokis', CategoriaMotivo::TEMPORAL, true, false, false, true, 20 ),
			new Motivo( 'personal', 'Personal / trámite', CategoriaMotivo::TEMPORAL, false, true, false, true, 30 ),
			new Motivo( 'salud', 'Salud / cita médica', CategoriaMotivo::TEMPORAL, false, true, false, true, 40 ),
			new Motivo( 'lactancia', 'Lactancia', CategoriaMotivo::TEMPORAL, true, false, false, true, 50 ),
			// La emergencia es la única salida que se puede marcar sin estar en el taller; se justifica después.
			new Motivo( 'emergencia', 'Emergencia', CategoriaMotivo::TEMPORAL, false, true, true, true, 60 ),
			new Motivo( 'fin_jornada', 'Fin de jornada', CategoriaMotivo::FIN_JORNADA, false, false, false, true, 70 ),
		);
	}

	public static function porClave( string $clave ): ?Motivo {
		foreach ( self::porDefecto() as $motivo ) {
			if ( $motivo->clave === $clave ) {
				return $motivo;
			}
		}

		return null;
	}
}
