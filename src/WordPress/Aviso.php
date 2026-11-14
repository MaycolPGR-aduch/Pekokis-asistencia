<?php

namespace Pekokis\Asistencia\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Aviso de datos personales (Ley 29733) que el trabajador lee y acepta al activar
 * su celular. Si RR.HH. cambia el texto, sube la versión y se pide aceptar de nuevo.
 *
 * El texto por defecto es un BORRADOR: debe revisarlo el abogado antes de usarlo.
 */
final class Aviso {
	public static function version(): int {
		return max( 1, (int) Ajustes::todos()['aviso_version'] );
	}

	/** Texto para mostrar, con los datos del empleador ya puestos. */
	public static function texto(): string {
		$a     = Ajustes::todos();
		$texto = '' !== trim( (string) $a['aviso_texto'] ) ? (string) $a['aviso_texto'] : self::borrador();

		return strtr(
			$texto,
			array(
				'{razon_social}'    => (string) $a['razon_social'],
				'{ruc}'             => (string) $a['ruc'],
				'{direccion}'       => (string) $a['direccion_empleador'],
				'{correo_contacto}' => Respaldo::destinatario(),
			)
		);
	}

	/** Texto sin reemplazar, para editarlo en Ajustes. */
	public static function textoEditable(): string {
		$guardado = (string) Ajustes::todos()['aviso_texto'];

		return '' !== trim( $guardado ) ? $guardado : self::borrador();
	}

	public static function esBorrador(): bool {
		return '' === trim( (string) Ajustes::todos()['aviso_texto'] );
	}

	/** Guarda un texto nuevo: sube la versión, así que todos deben aceptarlo otra vez. */
	public static function guardar( string $texto ): void {
		$ajustes                  = Ajustes::todos();
		$ajustes['aviso_texto']   = trim( $texto );
		$ajustes['aviso_version'] = self::version() + 1;
		update_option( Ajustes::OPCION, $ajustes );

		Auditoria::registrar( 'ajustes.aviso', 'ajustes', null, null, array( 'version' => $ajustes['aviso_version'] ) );
	}

	/** @param array<string, mixed> $trabajador */
	public static function pendiente( array $trabajador ): bool {
		return (int) $trabajador['aviso_version'] < self::version();
	}

	public static function aceptar( int $trabajadorId ): void {
		global $wpdb;

		$wpdb->update(
			Tablas::nombre( 'trabajadores' ),
			array( 'aviso_version' => self::version(), 'aviso_aceptado_en' => Peticion::ahoraUtc() ),
			array( 'id' => $trabajadorId )
		);

		Auditoria::registrar( 'aviso.aceptar', 'trabajador', $trabajadorId, null, array( 'version' => self::version() ), '', $trabajadorId );
	}

	/** @return array{texto: string, version: int} */
	public static function paraApp(): array {
		return array( 'texto' => self::texto(), 'version' => self::version() );
	}

	/** @return array{aceptaron: int, activos: int} */
	public static function conteo(): array {
		global $wpdb;

		$t = Tablas::nombre( 'trabajadores' );

		return array(
			'aceptaron' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE estado = 'activo' AND aviso_version >= %d", self::version() ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'activos'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE estado = 'activo'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Borrador para revisar con el abogado. Formato: párrafos separados por una línea
	 * en blanco; las líneas que empiezan con "- " son viñetas; una línea que termina
	 * sin punto y va sola es un subtítulo.
	 */
	public static function borrador(): string {
		return <<<'TXT'
Aviso de datos personales

{razon_social} (RUC {ruc}), con domicilio en {direccion}, es responsable de los datos personales que se registran en este sistema de control de asistencia.

Qué datos guardamos
- Tus datos de trabajador: nombres, apellidos, DNI, teléfono, correo, cargo, área y horario.
- Tus marcas: fecha y hora de entrada y salida, el motivo de cada salida y las notas que escribas.
- Datos técnicos de cada marca: la dirección IP desde la que marcas y un identificador de tu celular.
- Tu llave de acceso (passkey). Tu huella, tu rostro y el PIN de tu celular nunca salen de tu teléfono: el sistema no los recibe ni los guarda.
- Las solicitudes, correcciones y ausencias justificadas registradas sobre tu asistencia.

No guardamos tu ubicación ni fotos tuyas.

Para qué los usamos
- Llevar el registro de control de asistencia que exige la ley (D.S. N.° 004-2006-TR).
- Calcular tu jornada, tardanzas, ausencias y horas extra, y cumplir nuestras obligaciones laborales contigo.
- Comprobar que cada marca la hiciste tú y desde el taller.

Quién puede verlos
- El área de Recursos Humanos y tu jefe directo. La gerencia, solo para consulta.
- El proveedor de alojamiento web donde funciona el sistema, que solo los guarda por encargo nuestro. Sus servidores pueden estar fuera del Perú.

No vendemos tus datos ni los usamos para otros fines.

Cuánto tiempo
Conservamos el registro de asistencia durante 5 años, como exige la norma laboral. Después se elimina.

Tus derechos
Puedes pedir acceso a tus datos, su corrección, su eliminación (salvo los que la ley nos obliga a conservar) u oponerte a su uso, escribiendo a {correo_contacto}. Si no te respondemos, puedes acudir a la Autoridad Nacional de Protección de Datos Personales.
TXT;
	}
}
