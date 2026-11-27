<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\ValidacionTrabajador;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura del historial de cambios (tabla de auditoría) en lenguaje claro:
 * filtros, quién lo hizo, sobre qué y qué cambió.
 */
final class Historial {
	public const POR_PAGINA = 50;

	/** Grupos para el filtro: etiqueta => prefijos de acción. */
	public const GRUPOS = array(
		'trabajadores' => array( 'Trabajadores, celulares, ausencias y tardanzas', array( 'trabajador.', 'activacion.', 'passkey.', 'celular.', 'permiso.', 'ausencia.', 'aviso.', 'tardanza.' ) ),
		'marcas'       => array( 'Marcas y correcciones', array( 'marca.', 'correccion.' ) ),
		'pagos'        => array( 'Pagos, honorarios, descuentos y horas', array( 'sobretiempo.', 'sueldo.', 'preplanilla.', 'pago.', 'recibo.', 'simulador.', 'descuento.', 'horas.' ) ),
		'ajustes'      => array( 'Ajustes, horarios, calendario, redes y pantallas', array( 'ajustes.', 'horario.', 'calendario.', 'red.', 'pantalla.' ) ),
		'exportes'     => array( 'Descargas y respaldos', array( 'registro.', 'respaldo.', 'historial.', 'informe.', 'reporte.' ) ),
	);

	private const ACCIONES = array(
		'trabajador.crear'       => 'Alta de trabajador',
		'trabajador.editar'      => 'Edición del perfil',
		'trabajador.cesar'       => 'Cese',
		'activacion.generar'     => 'Enlace de activación generado',
		'passkey.registrar'      => 'Celular activado',
		'trabajador.registrarse' => 'Se registró desde el taller',
		'trabajador.ver_dni'     => 'Vio su DNI completo',
		'trabajador.pin_crear'   => 'Creó su PIN',
		'trabajador.pin_cambiar' => 'Cambió su PIN',
		'trabajador.pin_quitar'  => 'PIN quitado',
		'trabajador.pin_bloqueado' => 'PIN bloqueado (5 errores)',
		'celular.solicitar'      => 'Pidió registro, cambio o liberación de celular',
		'celular.aprobar'        => 'Celular aprobado',
		'celular.rechazar'       => 'Celular rechazado',
		'celular.liberar'        => 'Celular liberado',
		'celular.identidad_bloqueada' => 'Cambio de celular bloqueado (5 errores)',
		'correccion.solicitar'   => '"Olvidé marcar" solicitado',
		'correccion.aprobar'     => 'Solicitud aprobada',
		'correccion.rechazar'    => 'Solicitud rechazada',
		'marca.manual'           => 'Marca manual',
		'marca.anular'           => 'Marca anulada',
		'marca.revisar'          => 'Marca revisada',
		'pantalla.activar'       => 'Pantalla activada',
		'pantalla.desactivar'    => 'Pantalla desactivada',
		'red.agregar'            => 'Red del WiFi agregada',
		'red.activar'            => 'Red del WiFi activada',
		'red.desactivar'         => 'Red del WiFi desactivada',
		'ausencia.crear'         => 'Ausencia registrada',
		'ausencia.anular'        => 'Ausencia anulada',
		'aviso.aceptar'          => 'Aviso de datos aceptado',
		'calendario.agregar'     => 'Día agregado al calendario',
		'calendario.quitar'      => 'Día quitado del calendario',
		'calendario.editar'      => 'Día del calendario modificado',
		'ajustes.aviso'          => 'Aviso de datos modificado',
		'ajustes.empleador'      => 'Cambio de los datos del empleador',
		'ajustes.webhook'        => 'Cambio del respaldo en la nube',
		'horario.crear'          => 'Horario creado',
		'horario.editar'         => 'Horario modificado',
		'horario.activar'        => 'Horario activado',
		'horario.desactivar'     => 'Horario desactivado',
		'horario.cambio'         => 'Cambio de horario por un día o un periodo',
		'horario.cambio_anular'  => 'Cambio de horario anulado',
		'ajustes.modo_presencia' => 'Cambio del modo de presencia',
		'ajustes.campana'        => 'Cambio de los avisos de campaña',
		'ajustes.pin'            => 'Cambio del PIN de ingreso',
		'permiso.delegar'        => 'Permisos delegados',
		'marca.editar_dia'       => 'Día editado (Registros y revisión)',
		'permiso.crear_acceso'   => 'Acceso al panel creado',
		'ajustes.correo_rrhh'    => 'Cambio del correo del respaldo',
		'registro.descargar'     => 'Excel del registro descargado',
		'respaldo.enviar'        => 'Respaldo enviado por correo',
		'historial.exportar'     => 'Historial descargado',
		'sobretiempo.autorizar'  => 'Horas extra autorizadas',
		'sobretiempo.anular'     => 'Horas extra anuladas',
		'sueldo.agregar'         => 'Sueldo registrado',
		'sueldo.anular'          => 'Sueldo anulado',
		'descuento.crear'        => 'Descuento registrado',
		'descuento.anular'       => 'Descuento anulado',
		'horas.ajustar'          => 'Horas de un día ajustadas',
		'horas.anular_ajuste'    => 'Ajuste de horas anulado',
		'ajustes.reglas'         => 'Cambio de las reglas de horas',
		'preplanilla.descargar'  => 'Planilla de pagos descargada',
		'informe.descargar'      => 'Informe por trabajador descargado',
		'reporte.descargar'      => 'Informe del mes descargado',
		'simulador.usar'         => 'Usó el simulador de pagos',
		'simulador.descargar'    => 'Simulación de pagos descargada',
		'ajustes.avisos'         => 'Cambio de los avisos',
		'pago.marcar'            => 'Pago marcado como hecho',
		'pago.desmarcar'         => 'Pago desmarcado',
		'recibo.enviar'          => 'Recibo por honorarios enviado',
		'recibo.anular'          => 'Envío de recibo anulado',
		'recibo.recibir'         => 'Recibo marcado como recibido',
		'recibo.observar'        => 'Recibo observado',
		'recibo.recordar'        => 'Recordatorio de recibo',
		'recibo.ver'             => 'PDF de recibo abierto',
		'tardanza.registrar'     => 'Tardanza registrada',
		'tardanza.amonestacion'  => 'Amonestación por tardanza',
		'tardanza.justificar'    => 'Tardanza justificada',
		'tardanza.reiniciar'     => 'Conteo de tardanzas reiniciado',
		'gerencia.llave_activada' => 'Huella o rostro activado para entrar al panel',
		'gerencia.llave_quitada'  => 'Huella o rostro quitado del panel',
	);

	/** Tipo de cambio (maqueta G5): prefijo de acción => etiqueta y color del chip. */
	public const TIPOS = array(
		'tardanza.'    => array( 'Tardanza', 'aviso' ),
		'correccion.'  => array( 'Solicitud', 'info' ),
		'marca.'       => array( 'Registro', 'info' ),
		'sueldo.'      => array( 'Tarifa', 'aviso' ),
		'sobretiempo.' => array( 'Tarifa', 'aviso' ),
		'pago.'        => array( 'Pago', 'aviso' ),
		'descuento.'   => array( 'Pago', 'aviso' ),
		'horas.'       => array( 'Horas', 'info' ),
		'preplanilla.' => array( 'Pago', 'aviso' ),
		'recibo.'      => array( 'Recibo', 'aviso' ),
		'horario.'     => array( 'Horario', 'ok' ),
		'calendario.'  => array( 'Feriado', 'ok' ),
		'ausencia.'    => array( 'Ausencia', 'ok' ),
		'permiso.'     => array( 'Permisos', 'error' ),
		'gerencia.'    => array( 'Acceso', 'error' ),
		'celular.'     => array( 'Celular', 'gris' ),
		'passkey.'     => array( 'Celular', 'gris' ),
		'activacion.'  => array( 'Celular', 'gris' ),
		'trabajador.'  => array( 'Perfil', 'gris' ),
		'aviso.'       => array( 'Perfil', 'gris' ),
		'ajustes.'     => array( 'Reglas', 'gris' ),
		'red.'         => array( 'Reglas', 'gris' ),
		'pantalla.'    => array( 'Reglas', 'gris' ),
		'registro.'    => array( 'Descarga', 'gris' ),
		'respaldo.'    => array( 'Descarga', 'gris' ),
		'historial.'   => array( 'Descarga', 'gris' ),
		'informe.'     => array( 'Descarga', 'gris' ),
		'reporte.'     => array( 'Descarga', 'gris' ),
		'simulador.'   => array( 'Simulación', 'aviso' ),
	);

	private const CAMPOS = array(
		'dni'                         => 'DNI',
		'nombres'                     => 'Nombres',
		'apellidos'                   => 'Apellidos',
		'telefono'                    => 'Teléfono',
		'correo'                      => 'Correo',
		'cargo'                       => 'Cargo',
		'area'                        => 'Área',
		'jefe_user_id'                => 'Jefe',
		'horario_id'                  => 'Horario',
		'fecha_ingreso'               => 'Ingreso',
		'fecha_cese'                  => 'Fecha de cese',
		'estado'                      => 'Estado',
		'tipo'                        => 'Marca',
		'motivo_clave'                => 'Motivo',
		'marcado_utc'                 => 'Hora',
		'decision'                    => 'Decisión',
		'modo_presencia'              => 'Modo',
		'correo_rrhh'                 => 'Correo',
		'red'                         => 'Red',
		'nombre'                      => 'Nombre',
		'activa'                      => 'Activa',
		'mes'                         => 'Mes',
		'hash'                        => 'SHA-256',
		'enviado'                     => 'Enviado',
		'a'                           => 'Para',
		'llaves_anteriores_revocadas' => 'Llaves anteriores revocadas',
		'desde'                       => 'Desde',
		'hasta'                       => 'Hasta',
		'nota'                        => 'Nota',
		'fecha'                       => 'Fecha',
		'version'                     => 'Versión',
		'user_id'                     => 'Usuario de wp-admin',
		'razon_social'                => 'Razón social',
		'ruc'                         => 'RUC',
		'direccion_empleador'         => 'Domicilio',
		'webhook'                     => 'Webhook',
		'tolerancia_min'              => 'Tolerancia (min)',
		'refrigerio_min'              => 'Refrigerio (min)',
		'marca_id'                    => 'Marca',
		'correccion_id'               => 'Corrección',
		'credencial_id'               => 'Llave',
		'dispositivo_id'              => 'Celular',
		'ip'                          => 'IP',
		'filas'                       => 'Filas',
		'franja'                      => 'Franja',
		'compensacion'                => 'Compensación',
		'sueldo'                      => 'Sueldo',
		'vigente_desde'               => 'Rige desde',
		'trabajadores'                => 'Trabajadores',
		'comida_no_marcada_min'       => 'Comida descontada si no se marca (min)',
		'tasa_extra_1'                => 'Sobretasa primeras horas extra (%)',
		'tasa_extra_2'                => 'Sobretasa siguientes horas extra (%)',
		'tramo_extra_1_min'           => 'Minutos a la primera sobretasa',
		'tasa_descanso'               => 'Sobretasa descanso o feriado (%)',
		'horas_jornada_valor'         => 'Horas para el valor hora',
		'descontar_no_trabajado'      => 'Descontar lo no trabajado',
		'tardanza_desde'              => 'Tardanza desde',
		'madrugada_multiplicador'     => 'Multiplicador de madrugada',
		'minutos'                     => 'Horas',
		'dia'                         => 'Día',
		'aviso_permanencia_min'       => 'Aviso de permanencia (min)',
		'formato'                     => 'Formato',
		'numero'                      => 'Número',
		'monto'                       => 'Monto',
		'total'                       => 'Total',
		'avisos'                      => 'Avisos encendidos',
		'horario'                     => 'Horas y márgenes',
		'equipo'                      => 'Equipo',
		'horario_dia'                 => 'Horario',
		'numero'                      => 'N.º de tardanza',
		'minutos'                     => 'Minutos',
		'conteo'                      => 'Conteo',
		'honorario'                   => 'Honorario y tarifas',
		'llave_id'                    => 'Llave',
	);

	/**
	 * @param array{desde?: string, hasta?: string, grupo?: string, trabajador?: int, usuario?: int, texto?: string} $filtros
	 * @return array{0: array<int, array<string, mixed>>, 1: int} Filas de la página y total.
	 */
	public static function buscar( array $filtros, int $pagina = 1, int $porPagina = self::POR_PAGINA ): array {
		global $wpdb;

		$a     = Tablas::nombre( 'auditoria' );
		$where = self::condiciones( $filtros );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$desde = max( 0, ( $pagina - 1 ) * $porPagina );

		$filas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$a} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $porPagina, $desde ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array( $filas, $total );
	}

	/**
	 * Convierte filas crudas en texto para personas.
	 *
	 * @param array<int, array<string, mixed>> $filas
	 * @return array<int, array{id: int, fecha: string, cuando: string, quien: string, accion: string, tipo: array{0: string, 1: string}, sobre: string, url: string, cambios: string[], detalle: array<int, array{campo: string, antes: ?string, despues: ?string}>, motivo: string, ip: string}>
	 */
	public static function describir( array $filas ): array {
		$contexto = self::contexto( $filas );
		$zona     = Ajustes::zona();
		$salida   = array();

		foreach ( $filas as $f ) {
			[ $sobre, $url ] = self::sobre( $f, $contexto );
			$cuando          = ( new DateTimeImmutable( $f['creado_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zona );
			$detalle         = self::detalle( $f, $contexto );

			$salida[] = array(
				'id'      => (int) $f['id'],
				'fecha'   => $cuando->format( 'd/m/Y H:i:s' ),
				'cuando'  => wp_date( 'j M H:i', $cuando->getTimestamp(), $zona ),
				'quien'   => self::quien( $f, $contexto ),
				'accion'  => self::ACCIONES[ $f['accion'] ] ?? $f['accion'],
				'tipo'    => self::tipo( (string) $f['accion'] ),
				'sobre'   => $sobre,
				'url'     => $url,
				'cambios' => self::lineas( $detalle ),
				'detalle' => $detalle,
				'motivo'  => (string) $f['motivo'],
				'ip'      => (string) $f['ip'],
			);
		}

		return $salida;
	}

	/** @return array<int, string> user_id => nombre, de quienes aparecen en el historial. */
	public static function usuarios(): array {
		global $wpdb;

		$a   = Tablas::nombre( 'auditoria' );
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT actor_user_id FROM {$a} WHERE actor_user_id IS NOT NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$usuarios = array();

		foreach ( $ids as $id ) {
			$u               = get_userdata( $id );
			$usuarios[ $id ] = $u ? $u->display_name : 'Usuario #' . $id;
		}

		asort( $usuarios );

		return $usuarios;
	}

	/** @param array<string, mixed> $filtros */
	private static function condiciones( array $filtros ): string {
		global $wpdb;

		$c    = array( '1=1' );
		$zona = Ajustes::zona();

		// Los sueldos solo los ve quien puede ver sueldos (Gerencia y jefes, no).
		if ( ! current_user_can( Capacidades::VER_SUELDOS ) ) {
			$c[] = $wpdb->prepare( 'accion NOT LIKE %s AND accion NOT LIKE %s AND accion NOT LIKE %s AND accion NOT LIKE %s AND accion NOT LIKE %s', 'sueldo.%', 'pago.%', 'recibo.%', 'simulador.%', 'descuento.%' );
		}
		$utc  = new DateTimeZone( 'UTC' );

		if ( ! empty( $filtros['desde'] ) && ValidacionTrabajador::esFecha( $filtros['desde'] ) ) {
			$c[] = $wpdb->prepare( 'creado_utc >= %s', ( new DateTimeImmutable( $filtros['desde'] . ' 00:00:00', $zona ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
		}

		if ( ! empty( $filtros['hasta'] ) && ValidacionTrabajador::esFecha( $filtros['hasta'] ) ) {
			$c[] = $wpdb->prepare( 'creado_utc < %s', ( new DateTimeImmutable( $filtros['hasta'] . ' 00:00:00', $zona ) )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
		}

		if ( ! empty( $filtros['grupo'] ) && isset( self::GRUPOS[ $filtros['grupo'] ] ) ) {
			$partes = array();

			foreach ( self::GRUPOS[ $filtros['grupo'] ][1] as $prefijo ) {
				$partes[] = $wpdb->prepare( 'accion LIKE %s', $wpdb->esc_like( $prefijo ) . '%' );
			}

			$c[] = '(' . implode( ' OR ', $partes ) . ')';
		}

		if ( ! empty( $filtros['trabajador'] ) ) {
			// Lo que hizo el trabajador, lo que se hizo sobre su perfil y sobre sus marcas y solicitudes.
			$id  = (int) $filtros['trabajador'];
			$m   = Tablas::nombre( 'marcas' );
			$co  = Tablas::nombre( 'correcciones' );
			$au  = Tablas::nombre( 'ausencias' );
			$c[] = $wpdb->prepare(
				"( actor_trabajador_id = %d
				OR ( entidad = 'trabajador' AND entidad_id = %d )
				OR ( entidad = 'marca' AND entidad_id IN ( SELECT id FROM {$m} WHERE trabajador_id = %d ) )
				OR ( entidad = 'correccion' AND entidad_id IN ( SELECT id FROM {$co} WHERE trabajador_id = %d ) )
				OR ( entidad = 'ausencia' AND entidad_id IN ( SELECT id FROM {$au} WHERE trabajador_id = %d ) ) )",
				$id,
				$id,
				$id,
				$id,
				$id
			);
		}

		if ( ! empty( $filtros['usuario'] ) ) {
			$c[] = $wpdb->prepare( 'actor_user_id = %d', (int) $filtros['usuario'] );
		}

		if ( ! empty( $filtros['texto'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $filtros['texto'] ) . '%';
			$c[]  = $wpdb->prepare( '( motivo LIKE %s OR antes LIKE %s OR despues LIKE %s )', $like, $like, $like );
		}

		return implode( ' AND ', $c );
	}

	/**
	 * Datos relacionados de la página en pocas consultas: trabajadores, marcas y correcciones.
	 *
	 * @param array<int, array<string, mixed>> $filas
	 * @return array{trabajadores: array<int, array<string, mixed>>, marcas: array<int, array<string, mixed>>, correcciones: array<int, array<string, mixed>>, horarios: array<int, string>}
	 */
	private static function contexto( array $filas ): array {
		global $wpdb;

		$ids = array( 'trabajador' => array(), 'marca' => array(), 'correccion' => array(), 'ausencia' => array() );

		foreach ( $filas as $f ) {
			if ( isset( $ids[ $f['entidad'] ] ) && $f['entidad_id'] ) {
				$ids[ $f['entidad'] ][] = (int) $f['entidad_id'];
			}

			if ( $f['actor_trabajador_id'] ) {
				$ids['trabajador'][] = (int) $f['actor_trabajador_id'];
			}
		}

		$traer = static function ( string $tabla, array $lista, string $columnas ) use ( $wpdb ): array {
			if ( ! $lista ) {
				return array();
			}

			$nombre = Tablas::nombre( $tabla );
			$filas  = $wpdb->get_results( "SELECT {$columnas} FROM {$nombre} WHERE id IN (" . implode( ',', array_unique( array_map( 'intval', $lista ) ) ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return array_column( $filas, null, 'id' );
		};

		$marcas       = $traer( 'marcas', $ids['marca'], 'id, trabajador_id, dia_laboral, tipo, motivo_clave, marcado_utc' );
		$correcciones = $traer( 'correcciones', $ids['correccion'], 'id, trabajador_id, marcado_utc' );
		$ausencias    = $traer( 'ausencias', $ids['ausencia'], 'id, trabajador_id' );

		foreach ( array_merge( $marcas, $correcciones, $ausencias ) as $relacionada ) {
			$ids['trabajador'][] = (int) $relacionada['trabajador_id'];
		}

		return array(
			'trabajadores' => $traer( 'trabajadores', $ids['trabajador'], 'id, nombres, apellidos, dni' ),
			'marcas'       => $marcas,
			'correcciones' => $correcciones,
			'ausencias'    => $ausencias,
			'horarios'     => Trabajadores::horarios(),
		);
	}

	/** @param array<string, mixed> $f */
	private static function quien( array $f, array $contexto ): string {
		if ( $f['actor_user_id'] ) {
			$u = get_userdata( (int) $f['actor_user_id'] );

			return $u ? $u->display_name . ( \Pekokis\Asistencia\WordPress\Admin\PantallaDelegar::esDelegado( (int) $u->ID ) ? ' (supervisor delegado)' : '' ) : 'Usuario #' . $f['actor_user_id'];
		}

		if ( $f['actor_trabajador_id'] && isset( $contexto['trabajadores'][ (int) $f['actor_trabajador_id'] ] ) ) {
			$t = $contexto['trabajadores'][ (int) $f['actor_trabajador_id'] ];

			return $t['nombres'] . ' ' . $t['apellidos'] . ' (desde su celular)';
		}

		return 'Sistema';
	}

	/**
	 * @param array<string, mixed> $f
	 * @return array{0: string, 1: string} Texto y enlace (vacío si no hay a dónde ir).
	 */
	private static function sobre( array $f, array $contexto ): array {
		$id     = (int) $f['entidad_id'];
		$nombre = static fn( int $t ): string => isset( $contexto['trabajadores'][ $t ] )
			? $contexto['trabajadores'][ $t ]['apellidos'] . ', ' . $contexto['trabajadores'][ $t ]['nombres']
			: 'Trabajador #' . $t;

		switch ( $f['entidad'] ) {
			case 'trabajador':
				return array( $nombre( $id ), admin_url( 'admin.php?page=pka-trabajadores&vista=editar&id=' . $id ) );

			case 'marca':
				if ( isset( $contexto['marcas'][ $id ] ) ) {
					$m = $contexto['marcas'][ $id ];

					return array(
						$nombre( (int) $m['trabajador_id'] ) . ' · ' . $m['dia_laboral'],
						add_query_arg( array( 'page' => 'pka-asistencia', 'vista' => 'dia', 'trabajador' => $m['trabajador_id'], 'dia' => $m['dia_laboral'] ), admin_url( 'admin.php' ) ),
					);
				}

				return array( 'Marca #' . $id, '' );

			case 'correccion':
				if ( isset( $contexto['correcciones'][ $id ] ) ) {
					return array( $nombre( (int) $contexto['correcciones'][ $id ]['trabajador_id'] ), '' );
				}

				return array( 'Solicitud #' . $id, '' );

			case 'ausencia':
				if ( isset( $contexto['ausencias'][ $id ] ) ) {
					$tid = (int) $contexto['ausencias'][ $id ]['trabajador_id'];

					return array( $nombre( $tid ), admin_url( 'admin.php?page=pka-ausencias&trabajador=' . $tid ) );
				}

				return array( 'Ausencia #' . $id, '' );

			case 'calendario':
				return array( 'Calendario', admin_url( 'admin.php?page=pka-calendario' ) );

			case 'horario':
				return array( $contexto['horarios'][ $id ] ?? 'Horario #' . $id, admin_url( 'admin.php?page=pka-horarios&vista=editar&id=' . $id ) );

			case 'pantalla':
				return array( 'Pantalla #' . $id, admin_url( 'admin.php?page=pka-ajustes' ) );

			case 'red':
				return array( 'Red #' . $id, admin_url( 'admin.php?page=pka-ajustes' ) );

			case 'ajustes':
				return array( 'Ajustes', admin_url( 'admin.php?page=pka-ajustes' ) );

			default:
				return array( ucfirst( (string) $f['entidad'] ), '' );
		}
	}

	/** Chip del tipo de cambio (maqueta G5). @return array{0: string, 1: string} */
	public static function tipo( string $accion ): array {
		foreach ( self::TIPOS as $prefijo => $tipo ) {
			if ( str_starts_with( $accion, $prefijo ) ) {
				return $tipo;
			}
		}

		return array( 'Otro', 'gris' );
	}

	/**
	 * Cada campo con su valor anterior y el nuevo, en texto legible. Un campo que
	 * solo existía antes queda con "despues" en null, y al revés.
	 *
	 * @param array<string, mixed> $f
	 * @return array<int, array{campo: string, antes: ?string, despues: ?string}>
	 */
	private static function detalle( array $f, array $contexto ): array {
		$antes   = json_decode( (string) $f['antes'], true );
		$despues = json_decode( (string) $f['despues'], true );
		$antes   = is_array( $antes ) ? $antes : array();
		$despues = is_array( $despues ) ? $despues : array();
		$salida  = array();

		foreach ( array_unique( array_merge( array_keys( $antes ), array_keys( $despues ) ) ) as $campo ) {
			$a = array_key_exists( $campo, $antes ) ? self::valor( $campo, $antes[ $campo ], $contexto ) : null;
			$d = array_key_exists( $campo, $despues ) ? self::valor( $campo, $despues[ $campo ], $contexto ) : null;

			// Un campo vacío que no cambió nada (por ejemplo, "Motivo" en una entrada) no aporta.
			if ( ( null === $a || '—' === $a ) && '—' === $d ) {
				continue;
			}

			$salida[] = array( 'campo' => self::CAMPOS[ $campo ] ?? $campo, 'antes' => $a, 'despues' => $d );
		}

		return $salida;
	}

	/**
	 * "Campo: antes → después" en una línea (CSV y ficha).
	 *
	 * @param array<int, array{campo: string, antes: ?string, despues: ?string}> $detalle
	 * @return string[]
	 */
	private static function lineas( array $detalle ): array {
		return array_map(
			static fn( array $c ): string => match ( true ) {
				null !== $c['antes'] && null !== $c['despues'] => $c['campo'] . ': ' . $c['antes'] . ' → ' . $c['despues'],
				null !== $c['despues'] => $c['campo'] . ': ' . $c['despues'],
				default => $c['campo'] . ': ' . $c['antes'] . ' (antes)',
			},
			$detalle
		);
	}

	private static function valor( string $campo, mixed $valor, array $contexto ): string {
		if ( null === $valor || '' === $valor ) {
			return '—';
		}

		if ( is_bool( $valor ) ) {
			return $valor ? 'sí' : 'no';
		}

		return match ( $campo ) {
			'marcado_utc'    => ( new DateTimeImmutable( (string) $valor, new DateTimeZone( 'UTC' ) ) )->setTimezone( Ajustes::zona() )->format( 'd/m H:i' ),
			'motivo_clave'   => ( $m = Motivos::porClave( (string) $valor ) ) ? $m->nombre : (string) $valor,
			'tipo'           => 'entrada' === $valor ? 'Entrada' : ( 'salida' === $valor ? 'Salida' : (string) $valor ),
			'jefe_user_id'   => ( $u = get_userdata( (int) $valor ) ) ? $u->display_name : '#' . $valor,
			'horario_id'     => $contexto['horarios'][ (int) $valor ] ?? '#' . $valor,
			'activa', 'enviado' => $valor ? 'sí' : 'no',
			'hash'           => substr( (string) $valor, 0, 12 ) . '…',
			default          => is_scalar( $valor ) ? (string) $valor : (string) wp_json_encode( $valor ),
		};
	}
}
