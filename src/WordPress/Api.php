<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Accion;
use Pekokis\Asistencia\Nucleo\Estado;
use Pekokis\Asistencia\Nucleo\MaquinaEstados;
use Pekokis\Asistencia\Nucleo\Motivo;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use Pekokis\Asistencia\Nucleo\SolicitudMarca;
use Pekokis\Asistencia\Nucleo\TotalesDia;
use Pekokis\Asistencia\Nucleo\ValidadorMarca;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * API de la app del trabajador (/wp-json/asistencia/v1/...).
 *
 * Los trabajadores no inician sesión en WordPress: cada ruta valida su propio
 * token (enlace de activación, reto de passkey o ticket de marca).
 */
final class Api {
	public const ESPACIO = 'asistencia/v1';

	private const SEGUNDOS_TICKET   = 120;
	private const MAX_FALLOS        = 10;
	private const VENTANA_FALLOS    = 15 * MINUTE_IN_SECONDS;
	private const MAX_FALLOS_QR     = 5;

	public static function registrar(): void {
		add_action( 'rest_api_init', array( self::class, 'rutas' ) );
	}

	public static function rutas(): void {
		$publica = '__return_true';

		register_rest_route( self::ESPACIO, '/activacion/opciones', array( 'methods' => 'POST', 'callback' => array( self::class, 'activacionOpciones' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/activacion/completar', array( 'methods' => 'POST', 'callback' => array( self::class, 'activacionCompletar' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/identificar/opciones', array( 'methods' => 'POST', 'callback' => array( self::class, 'identificarOpciones' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/identificar', array( 'methods' => 'POST', 'callback' => array( self::class, 'identificar' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/marcas', array( 'methods' => 'POST', 'callback' => array( self::class, 'marcar' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/presencia', array( 'methods' => 'GET', 'callback' => array( self::class, 'presencia' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/aviso', array( 'methods' => 'GET', 'callback' => array( self::class, 'aviso' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/aviso/aceptar', array( 'methods' => 'POST', 'callback' => array( self::class, 'aceptarAviso' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/mes', array( 'methods' => 'POST', 'callback' => array( self::class, 'miMes' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/correcciones', array( 'methods' => 'POST', 'callback' => array( self::class, 'solicitarCorreccion' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/presencia/codigo', array( 'methods' => 'POST', 'callback' => array( self::class, 'presenciaCodigo' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/pantalla/codigo', array( 'methods' => 'GET', 'callback' => array( self::class, 'pantallaCodigo' ), 'permission_callback' => $publica ) );
		register_rest_route(
			self::ESPACIO,
			'/pantalla/activar',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'pantallaActivar' ),
				'permission_callback' => static fn(): bool => current_user_can( Capacidades::AJUSTES ),
			)
		);
	}

	/**
	 * Valida el QR escaneado (token) o el código de 6 dígitos de la pantalla
	 * y deja en este navegador un pase de presencia de unos minutos.
	 */
	public static function presenciaCodigo( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		if ( Temporales::bloqueado( 'qr|' . Peticion::ip(), self::MAX_FALLOS_QR ) ) {
			return new WP_Error( 'pka_bloqueado', 'Demasiados códigos equivocados. Espera 15 minutos o conéctate al WiFi del taller.', array( 'status' => 429 ) );
		}

		$codigos  = Pantallas::codigos();
		$token    = (string) $peticion->get_param( 'token' );
		$codigo   = preg_replace( '/\D/', '', (string) $peticion->get_param( 'codigo' ) );
		$activas  = Pantallas::activas();
		$pantalla = '' !== $token ? $codigos->validarToken( $token, time() ) : $codigos->validarCodigo( $codigo, $activas, time() );

		if ( null === $pantalla || ! in_array( $pantalla, $activas, true ) ) {
			Temporales::sumarFallo( 'qr|' . Peticion::ip(), self::VENTANA_FALLOS );

			return new WP_Error( 'pka_codigo', 'Ese código ya cambió o no es correcto. Escanea el QR de nuevo.', array( 'status' => 422 ) );
		}

		$pases = Presencia::pases();
		Peticion::ponerCookie( Presencia::COOKIE_PASE, $pases->emitir( $pantalla, time() ), max( 60, (int) Ajustes::todos()['pase_minutos'] * MINUTE_IN_SECONDS ) );

		return self::respuesta( array( 'ok' => true, 'mensaje' => 'QR leído. Ya puedes marcar.' ) );
	}

	/** La tablet pide el código vigente cada pocos segundos. */
	public static function pantallaCodigo( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$pantalla = Pantallas::deEsteEquipo();

		if ( null === $pantalla ) {
			return new WP_Error( 'pka_pantalla', 'Este equipo no está activado como pantalla.', array( 'status' => 401 ) );
		}

		return self::respuesta( Pantallas::codigoVigente( $pantalla, (string) $peticion->get_param( 'actual' ) ) );
	}

	public static function pantallaActivar( WP_REST_Request $peticion ): WP_REST_Response {
		$id = Pantallas::activarEsteEquipo( sanitize_text_field( (string) $peticion->get_param( 'nombre' ) ) );

		return self::respuesta( array( 'id' => $id ) );
	}

	public static function activacionOpciones( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		if ( $bloqueo = self::bloqueado() ) {
			return $bloqueo;
		}

		$activacion = Activaciones::vigente( (string) $peticion->get_param( 'token' ) );

		if ( null === $activacion ) {
			self::sumarFallo();

			return new WP_Error( 'pka_enlace', 'Este enlace ya se usó o venció. Pide uno nuevo a RR.HH.', array( 'status' => 404 ) );
		}

		if ( Dispositivos::perteneceAOtro( (int) $activacion['id'] ) ) {
			return new WP_Error( 'pka_celular_ocupado', 'Este celular ya está activado para otra persona. Cada trabajador usa su propio celular.', array( 'status' => 409 ) );
		}

		$opciones = Passkeys::opcionesDeRegistro( $activacion, (int) $activacion['activacion_id'] );

		return self::respuesta(
			array(
				'nombre'    => $activacion['nombres'],
				'reto'      => $opciones['reto'],
				'publicKey' => $opciones['publicKey'],
				'aviso'     => Aviso::paraApp(),
			)
		);
	}

	public static function activacionCompletar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$activacion = Activaciones::vigente( (string) $peticion->get_param( 'token' ) );

		if ( null === $activacion ) {
			return new WP_Error( 'pka_enlace', 'Este enlace ya se usó o venció. Pide uno nuevo a RR.HH.', array( 'status' => 404 ) );
		}

		$trabajadorId = (int) $activacion['id'];

		if ( Dispositivos::perteneceAOtro( $trabajadorId ) ) {
			return new WP_Error( 'pka_celular_ocupado', 'Este celular ya está activado para otra persona. Cada trabajador usa su propio celular.', array( 'status' => 409 ) );
		}

		if ( (int) $peticion->get_param( 'acepta_aviso' ) !== Aviso::version() ) {
			return new WP_Error( 'pka_aviso', 'Lee el aviso de datos personales y marca la casilla para continuar.', array( 'status' => 422 ) );
		}

		$dispositivo = Dispositivos::vincular( $trabajadorId );
		$credencial  = Passkeys::completarRegistro( (string) $peticion->get_param( 'reto' ), (array) $peticion->get_param( 'credencial' ), $trabajadorId, $dispositivo );

		if ( $credencial instanceof WP_Error ) {
			return $credencial;
		}

		Activaciones::consumir( (int) $activacion['activacion_id'] );
		Aviso::aceptar( $trabajadorId );

		// Cambio de celular: la llave y el celular anteriores dejan de servir en el acto.
		$revocadas = Dispositivos::revocarOtros( $trabajadorId, $dispositivo, $credencial );

		Auditoria::registrar(
			'passkey.registrar',
			'trabajador',
			$trabajadorId,
			null,
			array( 'credencial_id' => $credencial, 'dispositivo_id' => $dispositivo, 'llaves_anteriores_revocadas' => $revocadas ),
			'',
			$trabajadorId
		);

		return self::respuesta( array( 'nombre' => $activacion['nombres'] ) );
	}

	public static function identificarOpciones(): WP_REST_Response|WP_Error {
		if ( $bloqueo = self::bloqueado() ) {
			return $bloqueo;
		}

		return self::respuesta( Passkeys::opcionesDeIdentificacion() );
	}

	public static function identificar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		if ( $bloqueo = self::bloqueado() ) {
			return $bloqueo;
		}

		$credencial = Passkeys::identificar( (string) $peticion->get_param( 'reto' ), (array) $peticion->get_param( 'credencial' ) );

		if ( $credencial instanceof WP_Error ) {
			self::sumarFallo();

			return $credencial;
		}

		$trabajadorId  = (int) $credencial['trabajador_id'];
		$dispositivo   = Dispositivos::actual();
		$dispositivoId = $dispositivo && (int) $dispositivo['trabajador_id'] === $trabajadorId ? (int) $dispositivo['id'] : null;

		if ( $dispositivoId ) {
			Dispositivos::tocar( $dispositivoId );
		}

		$ticket = Temporales::guardar(
			'ticket',
			array(
				'trabajador_id'  => $trabajadorId,
				'credencial_id'  => (int) $credencial['id'],
				'dispositivo_id' => $dispositivoId,
			),
			self::SEGUNDOS_TICKET
		);

		$trabajador = Trabajadores::buscar( $trabajadorId );
		$aviso      = $trabajador && Aviso::pendiente( $trabajador ) ? Aviso::paraApp() : null;

		return self::respuesta( array( 'ticket' => $ticket, 'segundos' => self::SEGUNDOS_TICKET, 'aviso' => $aviso ) + self::estadoDe( $trabajadorId ) );
	}

	public static function marcar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$clave  = (string) $peticion->get_param( 'ticket' );
		$ticket = Temporales::leer( 'ticket', $clave );

		if ( null === $ticket ) {
			return new WP_Error( 'pka_ticket', 'Pasaron más de 2 minutos. Vuelve a poner tu huella.', array( 'status' => 401 ) );
		}

		$trabajador = Trabajadores::buscar( $ticket['trabajador_id'] );

		if ( null === $trabajador || Trabajadores::ACTIVO !== $trabajador['estado'] ) {
			return new WP_Error( 'pka_trabajador', 'Tu usuario no está activo. Habla con RR.HH.', array( 'status' => 403 ) );
		}

		$accion = Accion::tryFrom( (string) $peticion->get_param( 'accion' ) );

		if ( null === $accion ) {
			return new WP_Error( 'pka_accion', 'Acción desconocida.', array( 'status' => 400 ) );
		}

		$motivo       = self::motivoDe( $accion, $peticion->get_param( 'motivo' ) );
		$trabajadorId = (int) $trabajador['id'];
		$candado      = 'pka_marca_' . $trabajadorId;
		global $wpdb;

		// Un trabajador a la vez: decidir y guardar no se pueden cruzar con un doble toque.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $candado ) ) ) {
			return new WP_Error( 'pka_ocupado', 'El sistema está ocupado. Intenta de nuevo.', array( 'status' => 503 ) );
		}

		try {
			$ahora     = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$dia       = Ajustes::diaLaboral()->de( $ahora );
			$contexto  = Presencia::contexto();
			$validador = new ValidadorMarca( Ajustes::modoPresencia(), (int) Ajustes::todos()['antirrebote_seg'] );
			$resultado = $validador->decidir( Marcas::delDia( $trabajadorId, $dia ), new SolicitudMarca( $accion->tipo(), $motivo ), $contexto, $ahora );

			if ( $resultado->esRechazada() ) {
				// El ticket sigue vivo: puede escanear el QR y reintentar sin volver a poner la huella.
				return new WP_Error(
					'pka_' . $resultado->codigo,
					$resultado->mensaje,
					array( 'status' => 422, 'acepta_qr' => 'sin_presencia' === $resultado->codigo && Ajustes::modoPresencia()->aceptaQr() )
				);
			}

			if ( $resultado->esRebote() ) {
				$marca = $resultado->marcaExistente;
				Temporales::borrar( 'ticket', $clave );

				return self::respuesta(
					array(
						'resultado' => 'rebote',
						'mensaje'   => 'Ya estaba registrada a las ' . Marcas::horaLocal( $marca->marcadoUtc ) . '.',
					) + self::estadoDe( $trabajadorId )
				);
			}

			$id = Marcas::registrar(
				array(
					'trabajador_id'     => $trabajadorId,
					'tipo'              => $accion->tipo(),
					'motivo_clave'      => $motivo?->clave,
					'nota'              => sanitize_text_field( (string) $peticion->get_param( 'nota' ) ),
					'marcado'           => $ahora,
					'prueba'            => $resultado->prueba,
					'requiere_revision' => $resultado->requiereRevision,
					'dispositivo_id'    => $ticket['dispositivo_id'],
					'credencial_id'     => $ticket['credencial_id'],
					'pantalla_id'       => $contexto->pantallaQr,
					'origen'            => 'trabajador',
				)
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $candado ) );
		}

		Temporales::borrar( 'ticket', $clave );

		$texto = ( $motivo ? $motivo->nombre : $accion->etiqueta() ) . ' · ' . Marcas::horaLocal( $ahora ) . ' · ' . Marcas::etiquetaPrueba( $resultado->prueba );

		return self::respuesta(
			array(
				'resultado' => 'aceptada',
				'marca_id'  => $id,
				'mensaje'   => $texto,
				'revision'  => $resultado->requiereRevision,
			) + self::estadoDe( $trabajadorId )
		);
	}

	public static function presencia(): WP_REST_Response {
		$modo     = Ajustes::modoPresencia();
		$contexto = Presencia::contexto();
		$prueba   = $modo->evaluar( $contexto );

		return self::respuesta(
			array(
				'en_red'    => $contexto->enRedDelTaller,
				'qr'        => $contexto->tieneQr(),
				'cumple'    => null !== $prueba,
				'modo'      => $modo->value,
				'acepta_qr' => $modo->aceptaQr(),
				'mensaje'   => null === $prueba ? $modo->mensajeSinPresencia( $contexto ) : 'Estás en el taller.',
			)
		);
	}

	/**
	 * Estado actual, botones válidos y marcas de hoy para la app.
	 *
	 * @return array<string, mixed>
	 */
	private static function estadoDe( int $trabajadorId ): array {
		$trabajador = Trabajadores::buscar( $trabajadorId );
		$dia        = Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$marcas     = MaquinaEstados::ordenar( Marcas::delDia( $trabajadorId, $dia ) );
		$estado     = MaquinaEstados::estadoActual( $marcas );
		$ultima     = $marcas ? $marcas[ count( $marcas ) - 1 ] : null;

		$acciones = array();

		foreach ( MaquinaEstados::acciones( $estado ) as $accion ) {
			$categoria  = $accion->categoria();
			$acciones[] = array(
				'clave'    => $accion->value,
				'etiqueta' => $accion->etiqueta(),
				'motivos'  => null === $categoria ? array() : array_map(
					static fn( Motivo $m ): array => array( 'clave' => $m->clave, 'nombre' => $m->nombre ),
					Motivos::deCategoria( $categoria )
				),
			);
		}

		return array(
			'nombre'   => $trabajador ? $trabajador['nombres'] : '',
			'estado'   => array(
				'clave'    => $estado->value,
				'etiqueta' => $estado->etiqueta(),
				'desde'    => $ultima && Estado::FUERA !== $estado ? Marcas::horaLocal( $ultima->marcadoUtc ) : null,
			),
			'acciones' => $acciones,
			'fecha'    => $dia,
			'ayer'     => self::pendienteAnterior( $trabajadorId, $dia ),
			'olvido'   => self::opcionesCorreccion(),
			'hoy'      => array_map(
				static fn( $m ): array => array(
					'hora'  => Marcas::horaLocal( $m->marcadoUtc ),
					'texto' => $m->motivo ? 'Salida · ' . $m->motivo->nombre : 'Entrada',
				),
				$marcas
			),
		);
	}

	/**
	 * "Mis marcas del mes": días, ingreso, salida, tardanzas y tiempo en el taller.
	 * Solo lee: el ticket sigue sirviendo para marcar después.
	 */
	public static function miMes( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		global $wpdb;

		$ticket = Temporales::leer( 'ticket', (string) $peticion->get_param( 'ticket' ) );

		if ( null === $ticket ) {
			return new WP_Error( 'pka_ticket', 'Pasaron más de 2 minutos. Vuelve a poner tu huella.', array( 'status' => 401 ) );
		}

		$trabajadorId = (int) $ticket['trabajador_id'];
		$trabajador   = Trabajadores::buscar( $trabajadorId );
		$zona         = Ajustes::zona();
		$actual       = new DateTimeImmutable( 'first day of this month', $zona );
		$anterior     = $actual->modify( '-1 month' );

		// Solo el mes actual y el anterior: lo demás lo ve RR.HH.
		$pedido = (string) $peticion->get_param( 'mes' ) === $anterior->format( 'Y-m' ) ? $anterior : $actual;
		$desde  = $pedido->format( 'Y-m-d' );
		$hasta  = min( $pedido->modify( 'last day of this month' )->format( 'Y-m-d' ), Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) );
		$marcas = Marcas::delRango( array( $trabajadorId ), $desde, $hasta )[ $trabajadorId ] ?? array();
		$hora   = static fn( ?DateTimeImmutable $x ): ?string => $x ? $x->setTimezone( $zona )->format( 'H:i' ) : null;
		$hm     = static fn( int $min ): string => sprintf( '%d:%02d', intdiv( $min, 60 ), $min % 60 );

		$dias    = array();
		$totales = array( 'dias' => 0, 'tardanzas' => 0, 'minutos_tardanza' => 0, 'incompletos' => 0, 'minutos' => 0 );

		krsort( $marcas );

		foreach ( $marcas as $dia => $delDia ) {
			$tot      = TotalesDia::calcular( $delDia );
			$tardanza = $trabajador ? ResumenDia::minutosTardanza( $tot->primeraEntrada, Horarios::delDia( (int) $trabajador['horario_id'], $dia ), $dia, $zona ) : 0;

			$dias[] = array(
				'fecha'      => $dia,
				'etiqueta'   => wp_date( 'D j', ( new DateTimeImmutable( $dia . ' 12:00', $zona ) )->getTimestamp(), $zona ),
				'ingreso'    => $hora( $tot->primeraEntrada ),
				'salida'     => $hora( $tot->ultimaSalida ),
				'tardanza'   => $tardanza,
				'tiempo'     => $hm( $tot->minutosDentro ),
				'incompleto' => $tot->incompleto,
				'nota'       => '',
			);

			++$totales['dias'];
			$totales['tardanzas']        += $tardanza > 0 ? 1 : 0;
			$totales['minutos_tardanza'] += $tardanza;
			$totales['incompletos']      += $tot->incompleto ? 1 : 0;
			$totales['minutos']          += $tot->minutosDentro;
		}

		// Días de ausencia justificada sin marcas (vacaciones, descanso médico…).
		foreach ( Ausencias::delRango( array( $trabajadorId ), $desde, $hasta )[ $trabajadorId ] ?? array() as $dia => $etiqueta ) {
			if ( isset( $marcas[ $dia ] ) ) {
				continue;
			}

			$dias[] = array(
				'fecha'      => $dia,
				'etiqueta'   => wp_date( 'D j', ( new DateTimeImmutable( $dia . ' 12:00', $zona ) )->getTimestamp(), $zona ),
				'ingreso'    => null,
				'salida'     => null,
				'tardanza'   => 0,
				'tiempo'     => '0:00',
				'incompleto' => false,
				'nota'       => $etiqueta,
			);
		}

		usort( $dias, static fn( $a, $b ) => strcmp( $b['fecha'], $a['fecha'] ) );

		$c          = Tablas::nombre( 'correcciones' );
		$pendientes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE trabajador_id = %d AND estado = 'pendiente'", $trabajadorId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::respuesta(
			array(
				'mes'        => $pedido->format( 'Y-m' ),
				'etiqueta'   => wp_date( 'F Y', $pedido->getTimestamp(), $zona ),
				'otro'       => $pedido == $actual ? array( 'mes' => $anterior->format( 'Y-m' ), 'etiqueta' => 'Mes anterior' ) : array( 'mes' => $actual->format( 'Y-m' ), 'etiqueta' => 'Este mes' ), // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				'dias'       => $dias,
				'calendario' => self::calendarioDelMes( $trabajador, $pedido->format( 'Y-m' ) ),
				'totales'    => array( 'tiempo' => $hm( $totales['minutos'] ) ) + $totales,
				'pendientes' => $pendientes,
			)
		);
	}

	private const CORTOS = array(
		'hoy'                => 'Hoy',
		'completo'           => 'OK',
		'tarde'              => 'Tarde',
		'falta'              => 'Falta',
		'justificado'        => 'Just.',
		'descanso'           => 'Desc.',
		'descanso_trabajado' => 'Desc.+',
		'revisar'            => 'Revisar',
		'incompleto'         => 'S/salida',
	);

	/**
	 * Calendario del mes para la app: cada día con su estado (los mismos colores
	 * que el informe del panel), las horas y la tardanza. Hoy y los días futuros
	 * todavía no tienen estado.
	 *
	 * @param array<string, mixed>|null $trabajador
	 * @return array{vacios: int, dias: array<int, array<string, mixed>>, leyenda: array<int, array{estado: string, texto: string}>}
	 */
	private static function calendarioDelMes( ?array $trabajador, string $mes ): array {
		$primero = new DateTimeImmutable( $mes . '-01' );
		$hoy     = Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$calculo = $trabajador ? Informes::diasDe( $trabajador, $mes ) : array();
		$dias    = array();
		$usados  = array();

		for ( $d = 1; $d <= (int) $primero->format( 't' ); $d++ ) {
			$fecha = $primero->format( 'Y-m-' ) . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			$j     = $calculo[ $fecha ] ?? null;
			$clave = $fecha === $hoy ? 'hoy' : Informes::estadoVisual( $j );
			$texto = 'hoy' === $clave ? 'Hoy' : ( 'pendiente' === $clave ? '' : Informes::COLORES[ $clave ][0] );

			if ( $j && in_array( $clave, array( 'justificado', 'descanso' ), true ) && '' !== $j->detalle ) {
				$texto = preg_replace( '/^(Feriado|Día no laborable de la empresa): /u', '', $j->detalle );
			}

			if ( ! in_array( $clave, array( 'hoy', 'pendiente' ), true ) ) {
				$usados[ $clave ] = true;
			}

			$dias[] = array(
				'fecha'    => $fecha,
				'n'        => $d,
				'estado'   => $clave,
				'texto'    => $texto,
				// En el celular la celda es angosta: un rótulo corto, el texto completo va en la etiqueta accesible.
				'corto'    => self::CORTOS[ $clave ] ?? '',
				'horas'    => $j && $j->minutosTrabajados ? \Pekokis\Asistencia\Nucleo\PrePlanilla::horas( $j->minutosTrabajados ) : '',
				'tardanza' => $j ? $j->minutosTardanza : 0,
			);
		}

		$leyenda = array();

		foreach ( Informes::COLORES as $clave => [ $etiqueta ] ) {
			if ( isset( $usados[ $clave ] ) ) {
				$corto     = self::CORTOS[ $clave ] ?? $etiqueta;
				$leyenda[] = array( 'estado' => $clave, 'texto' => $corto === $etiqueta ? $etiqueta : $corto . ' = ' . $etiqueta );
			}
		}

		return array( 'vacios' => (int) $primero->format( 'N' ) - 1, 'dias' => $dias, 'leyenda' => $leyenda );
	}

	public static function aviso(): WP_REST_Response {
		return self::respuesta( Aviso::paraApp() );
	}

	/** Aceptar una versión nueva del aviso (cuando RR.HH. cambió el texto). */
	public static function aceptarAviso( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$ticket = Temporales::leer( 'ticket', (string) $peticion->get_param( 'ticket' ) );

		if ( null === $ticket ) {
			return new WP_Error( 'pka_ticket', 'Pasaron más de 2 minutos. Vuelve a poner tu huella.', array( 'status' => 401 ) );
		}

		if ( (int) $peticion->get_param( 'version' ) !== Aviso::version() ) {
			return new WP_Error( 'pka_aviso', 'El aviso cambió mientras lo leías. Vuelve a abrirlo.', array( 'status' => 409 ) );
		}

		Aviso::aceptar( (int) $ticket['trabajador_id'] );

		return self::respuesta( array( 'ok' => true ) + self::estadoDe( (int) $ticket['trabajador_id'] ) );
	}

	/** El trabajador avisa que olvidó marcar; queda pendiente para su jefe. */
	public static function solicitarCorreccion( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$clave  = (string) $peticion->get_param( 'ticket' );
		$ticket = Temporales::leer( 'ticket', $clave );

		if ( null === $ticket ) {
			return new WP_Error( 'pka_ticket', 'Pasaron más de 2 minutos. Vuelve a poner tu huella.', array( 'status' => 401 ) );
		}

		$accion = Accion::tryFrom( (string) $peticion->get_param( 'accion' ) );

		if ( null === $accion ) {
			return new WP_Error( 'pka_accion', 'Elige qué marca olvidaste.', array( 'status' => 400 ) );
		}

		$resultado = Correcciones::solicitar(
			(int) $ticket['trabajador_id'],
			(string) $peticion->get_param( 'fecha' ),
			(string) $peticion->get_param( 'hora' ),
			$accion,
			(string) $peticion->get_param( 'motivo' ),
			(string) $peticion->get_param( 'justificacion' )
		);

		if ( $resultado instanceof WP_Error ) {
			$resultado->add_data( array( 'status' => 422 ) );

			return $resultado;
		}

		Temporales::borrar( 'ticket', $clave );

		return self::respuesta( array( 'ok' => true, 'mensaje' => 'Solicitud enviada. Tu jefe la revisará.' ) );
	}

	private static function motivoDe( Accion $accion, mixed $clave ): ?Motivo {
		// Si falta o no corresponde, el validador responde "Elige el motivo de la salida".
		return Motivos::paraAccion( $accion, $clave );
	}

	/**
	 * Aviso si el día laboral anterior quedó sin salida y no hay una solicitud pendiente.
	 *
	 * @return array<string, string>|null
	 */
	private static function pendienteAnterior( int $trabajadorId, string $hoy ): ?array {
		global $wpdb;

		$ayer   = ( new DateTimeImmutable( $hoy ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$marcas = Marcas::delDia( $trabajadorId, $ayer );

		if ( ! $marcas || ! TotalesDia::calcular( $marcas )->incompleto ) {
			return null;
		}

		$c = Tablas::nombre( 'correcciones' );

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$c} WHERE trabajador_id = %d AND estado = 'pendiente'", $trabajadorId ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return null;
		}

		return array( 'fecha' => $ayer, 'mensaje' => 'Ayer no marcaste tu salida. Avísalo con "Olvidé marcar".' );
	}

	/** @return array<int, array<string, mixed>> Todas las acciones posibles, para el formulario "Olvidé marcar". */
	private static function opcionesCorreccion(): array {
		$opciones = array();

		foreach ( array( Accion::ENTRADA, Accion::SALIDA_REFRIGERIO, Accion::SALIDA_TEMPORAL, Accion::FIN_JORNADA ) as $accion ) {
			$opciones[] = array(
				'clave'    => $accion->value,
				'etiqueta' => Accion::ENTRADA === $accion ? 'Entrada o regreso' : $accion->etiqueta(),
				'motivos'  => null === $accion->categoria() ? array() : array_map(
					static fn( Motivo $m ): array => array( 'clave' => $m->clave, 'nombre' => $m->nombre ),
					Motivos::deCategoria( $accion->categoria() )
				),
			);
		}

		return $opciones;
	}

	private static function bloqueado(): ?WP_Error {
		if ( Temporales::bloqueado( 'ip|' . Peticion::ip(), self::MAX_FALLOS ) ) {
			return new WP_Error( 'pka_bloqueado', 'Demasiados intentos fallidos. Espera 15 minutos.', array( 'status' => 429 ) );
		}

		return null;
	}

	private static function sumarFallo(): void {
		Temporales::sumarFallo( 'ip|' . Peticion::ip(), self::VENTANA_FALLOS );
	}

	/** @param array<string, mixed> $datos */
	private static function respuesta( array $datos ): WP_REST_Response {
		$respuesta = new WP_REST_Response( $datos );
		$respuesta->header( 'Cache-Control', 'no-store' );

		return $respuesta;
	}
}
