<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Accion;
use Pekokis\Asistencia\Nucleo\Estado;
use Pekokis\Asistencia\Nucleo\JornadaAbierta;
use Pekokis\Asistencia\Nucleo\RecordatorioCampana;
use Pekokis\Asistencia\Nucleo\MaquinaEstados;
use Pekokis\Asistencia\Nucleo\Motivo;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use Pekokis\Asistencia\Nucleo\SolicitudMarca;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use Pekokis\Asistencia\Nucleo\TotalesDia;
use Pekokis\Asistencia\Nucleo\ValidadorMarca;
use Pekokis\Asistencia\Nucleo\VistaDia;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
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
	private const SEGUNDOS_RECIBO   = 15 * MINUTE_IN_SECONDS;
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
		register_rest_route( self::ESPACIO, '/yo', array( 'methods' => 'GET', 'callback' => array( self::class, 'miEstado' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/perfil', array( 'methods' => 'GET', 'callback' => array( self::class, 'miPerfil' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/dni', array( 'methods' => 'POST', 'callback' => array( self::class, 'miDni' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/salir', array( 'methods' => 'POST', 'callback' => array( self::class, 'salir' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/pin', array( 'methods' => 'POST', 'callback' => array( self::class, 'crearPin' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/pin/quitar', array( 'methods' => 'POST', 'callback' => array( self::class, 'quitarPin' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/pin/estado', array( 'methods' => 'GET', 'callback' => array( self::class, 'pinEstado' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/pin/entrar', array( 'methods' => 'POST', 'callback' => array( self::class, 'pinEntrar' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/registro/estado', array( 'methods' => 'GET', 'callback' => array( self::class, 'registroEstado' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/registro/nuevo', array( 'methods' => 'POST', 'callback' => array( self::class, 'registroNuevo' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/registro/buscar', array( 'methods' => 'POST', 'callback' => array( self::class, 'registroBuscar' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/registro/confirmar', array( 'methods' => 'POST', 'callback' => array( self::class, 'registroConfirmar' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/registro/completar', array( 'methods' => 'POST', 'callback' => array( self::class, 'registroCompletar' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/celular/liberar', array( 'methods' => 'POST', 'callback' => array( self::class, 'liberarCelular' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/mes', array( 'methods' => 'POST', 'callback' => array( self::class, 'miMes' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/recibos', array( 'methods' => 'POST', 'callback' => array( self::class, 'misRecibos' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/recibos/enviar', array( 'methods' => 'POST', 'callback' => array( self::class, 'enviarRecibo' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/recibos/anular', array( 'methods' => 'POST', 'callback' => array( self::class, 'anularRecibo' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/constancia/enviar', array( 'methods' => 'POST', 'callback' => array( self::class, 'enviarConstancia' ), 'permission_callback' => $publica ) );
		register_rest_route( self::ESPACIO, '/yo/constancia/anular', array( 'methods' => 'POST', 'callback' => array( self::class, 'anularConstancia' ), 'permission_callback' => $publica ) );
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
		Peticion::ponerCookie( Presencia::COOKIE_PASE, $pases->emitir( $pantalla, time() ), Presencia::segundosPase() );

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

		// Registro o cambio de celular esperando a gerencia: todavía no entra.
		if ( Trabajadores::PENDIENTE === $credencial['trabajador_estado'] ) {
			return new WP_Error( 'pka_registro_pendiente', 'Tu registro está esperando la aprobación de gerencia. Cuando lo aprueben, podrás entrar y marcar.', array( 'status' => 403 ) );
		}

		if ( (int) $credencial['pendiente'] ) {
			return new WP_Error( 'pka_cambio_pendiente', 'Tu cambio de celular está esperando la aprobación de gerencia. Mientras tanto, marca con tu celular anterior.', array( 'status' => 403 ) );
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

		// También deja la sesión iniciada para ver horas, recibo y perfil sin volver a poner la huella.
		SesionTrabajador::crear( $trabajadorId, (int) $credencial['id'], $dispositivoId );

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
			// Si la jornada de ayer sigue abierta (pasó la medianoche), la marca va a ese día.
			$dia       = Jornadas::diaActual( $trabajadorId, $ahora );
			$delDia    = Marcas::delDia( $trabajadorId, $dia );
			$contexto  = Presencia::contexto();
			$validador = new ValidadorMarca( Ajustes::modoPresencia(), (int) Ajustes::todos()['antirrebote_seg'] );
			$resultado = $validador->decidir( $delDia, new SolicitudMarca( $accion->tipo(), $motivo ), $contexto, $ahora );

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

			// Jornada muy larga (maquetas T3F): la salida se acepta, pero queda por revisar.
			$minutosJornada = Accion::FIN_JORNADA === $accion ? JornadaAbierta::minutosDesdeLaEntrada( $delDia, $ahora ) : 0;
			$larga          = Accion::FIN_JORNADA === $accion && JornadaAbierta::esLarga( $delDia, $ahora, (int) Ajustes::todos()['jornada_larga_horas'] );

			$id = Marcas::registrar(
				array(
					'trabajador_id'     => $trabajadorId,
					'dia_laboral'       => $dia,
					'tipo'              => $accion->tipo(),
					'motivo_clave'      => $motivo?->clave,
					'nota'              => sanitize_text_field( (string) $peticion->get_param( 'nota' ) ),
					'marcado'           => $ahora,
					'prueba'            => $resultado->prueba,
					'requiere_revision' => $resultado->requiereRevision || $larga,
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

		if ( $larga ) {
			Notificaciones::jornadaLarga( (int) $id, $trabajadorId, $dia, $minutosJornada );
		} elseif ( $resultado->requiereRevision ) {
			Notificaciones::revision( (int) $id );
		}

		$texto = ( $motivo ? $motivo->nombre : $accion->etiqueta() ) . ' · ' . Marcas::horaLocal( $ahora ) . ' · ' . Marcas::etiquetaPrueba( $resultado->prueba );

		// Llegó tarde con esta entrada: aviso según cuántas tardanzas lleva.
		$tarde = TipoMarca::ENTRADA === $accion->tipo() ? Tardanzas::delDia( $trabajadorId, $dia ) : null;
		$tarde = $tarde && Tardanzas::VIGENTE === $tarde['estado'] && (int) $tarde['marca_id'] === (int) $id ? Tardanzas::aviso( $tarde ) : null;

		return self::respuesta(
			array(
				'resultado' => 'aceptada',
				'marca_id'  => $id,
				'mensaje'   => $texto,
				'revision'  => $resultado->requiereRevision,
				// T3F: la jornada pasó el límite; T3E: a qué jornada pertenece una salida de madrugada.
				'larga'     => $larga ? VistaDia::duracion( $minutosJornada ) : '',
				'tardanza'  => $tarde,
				'jornada'   => self::jornadaDe( $dia, $ahora ),
			) + self::estadoDe( $trabajadorId, $dia )
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
				'modo_qr'   => $modo->value,
				'pase'      => Presencia::segundosDelPase(),
				'mensaje'   => null === $prueba ? $modo->mensajeSinPresencia( $contexto ) : 'Estás en el taller.',
			)
		);
	}

	/**
	 * Estado actual, botones válidos y marcas de hoy para la app.
	 *
	 * @return array<string, mixed>
	 */
	private static function estadoDe( int $trabajadorId, ?string $diaJornada = null ): array {
		$trabajador = Trabajadores::buscar( $trabajadorId );
		$ahoraUtc   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$dia        = $diaJornada ?? Jornadas::diaActual( $trabajadorId, $ahoraUtc );
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

		$zona    = Ajustes::zona();
		$horario = $trabajador ? Horarios::deTrabajador( $trabajador, $dia ) : \Pekokis\Asistencia\Nucleo\HorarioDelDia::libre();
		$vista   = VistaDia::de( $marcas, $horario, $dia, $zona, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );

		if ( $vista['terminado'] ) {
			$calculo        = CalculoJornada::calcular( $marcas, $horario, $dia, $zona, Ajustes::reglasPago() );
			$vista['extra'] = $calculo->minutosAdicionales();
		}

		// "Aún no marcas tu entrada" (T9A), solo en campaña.
		$campana      = RecordatorioCampana::desdeAjustes( Ajustes::todos() );
		$recordatorio = $campana->toca( $dia, $ahoraUtc->setTimezone( $zona )->format( 'H:i' ), $horario->laborable && '' === Calendario::descripcion( $dia ), (bool) $marcas ) ? $campana->texto : '';

		return array(
			'nombre'   => $trabajador ? $trabajador['nombres'] : '',
			'iniciales' => $trabajador ? self::iniciales( $trabajador ) : '',
			'dia'      => array(
				'texto'   => wp_date( 'l j \d\e F', ( new DateTimeImmutable( $dia . ' 12:00', $zona ) )->getTimestamp(), $zona ),
				'horario' => $horario->laborable && $horario->entrada ? self::horaCorta( $horario->entrada ) . ' a ' . self::horaCorta( (string) $horario->salida ) : '',
				'descanso' => $horario->descanso,
			),
			'vista'    => $vista,
			'recordatorio' => $recordatorio,
			// Aviso de la tardanza de hoy, mientras dure la jornada.
			'tardanza'     => ( static function () use ( $trabajadorId, $dia ) {
				$f = Tardanzas::delDia( $trabajadorId, $dia );

				return $f && Tardanzas::VIGENTE === $f['estado'] ? Tardanzas::aviso( $f ) : null;
			} )(),
			'jornada'  => self::jornadaDe( $dia, $ahoraUtc ),
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

		$trabajador = self::trabajadorActual( $peticion );

		if ( $trabajador instanceof WP_Error ) {
			return $trabajador;
		}

		$trabajadorId = (int) $trabajador['id'];
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
			$tardanza = $trabajador ? ResumenDia::minutosTardanza( $tot->primeraEntrada, Horarios::deTrabajador( $trabajador, $dia ), $dia, $zona ) : 0;

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
				'detalle'    => self::detalleDelMes( $trabajador, $desde, $hasta ),
				'resumen'    => self::resumenDelMes( $trabajador, $pedido->format( 'Y-m' ) ),
				'totales'    => array( 'tiempo' => $hm( $totales['minutos'] ) ) + $totales,
				'pendientes' => $pendientes,
			)
		);
	}

	private const CORTOS = array(
		'hoy'                => 'Hoy',
		'completo'           => 'OK',
		'con_extra'          => 'Extra',
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

	/**
	 * Quién es: con el ticket de la huella (recién puesta) o con la sesión de
	 * este navegador. Sirve para ver; para marcar se exige siempre el ticket.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function trabajadorActual( WP_REST_Request $peticion ): array|WP_Error {
		$ticket = Temporales::leer( 'ticket', (string) $peticion->get_param( 'ticket' ) );
		$t      = $ticket ? Trabajadores::buscar( (int) $ticket['trabajador_id'] ) : null;

		if ( $t && Trabajadores::ACTIVO === $t['estado'] ) {
			return $t;
		}

		$sesion = SesionTrabajador::actual();

		if ( $sesion ) {
			return $sesion['trabajador'];
		}

		return new WP_Error( 'pka_sesion', 'Tu sesión terminó. Entra otra vez con tu huella.', array( 'status' => 401 ) );
	}

	/** Pantalla de marcar con la sesión iniciada: el día, la presencia y el perfil corto. */
	public static function miEstado( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorActual( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$presencia = self::presencia()->get_data();

		return self::respuesta( self::estadoDe( (int) $t['id'] ) + array( 'presencia' => $presencia, 'aviso_pendiente' => Aviso::pendiente( $t ) ) );
	}

	/** Mi perfil (T7): el DNI va oculto. */
	public static function miPerfil( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		global $wpdb;

		$t = self::trabajadorActual( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$sesion  = SesionTrabajador::actual();
		$llave   = $sesion ? (int) $sesion['credencial_id'] : 0;
		$creada  = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT creado_en FROM ' . Tablas::nombre( 'credenciales' ) . ' WHERE trabajador_id = %d AND revocada_en IS NULL ORDER BY ( id = %d ) DESC, id DESC LIMIT 1', (int) $t['id'], $llave ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$dni     = (string) $t['dni'];

		return self::respuesta(
			array(
				'nombre'     => Trabajadores::nombreCompleto( $t ),
				'iniciales'  => self::iniciales( $t ),
				'cargo'      => trim( ( '' !== (string) $t['cargo'] ? $t['cargo'] : 'Trabajador' ) . ' · Taller Pekokis' ),
				'telefono'   => (string) $t['telefono'],
				'dni'        => '•••• ' . substr( $dni, -4 ),
				'celular'    => '' !== $creada ? 'Registrado el ' . Ajustes::local( $creada, 'j \d\e F' ) : 'Sin registrar',
				'estado'     => 'Activo',
				'pin'        => array(
					'activo' => Pines::activo(),
					'tiene'  => '' !== (string) $t['pin_hash'],
					'desde'  => $t['pin_creado_en'] ? Ajustes::local( (string) $t['pin_creado_en'], 'j \d\e F' ) : '',
					'largo'  => Pines::largo(),
				),
				'pendiente'  => Celulares::textoPendiente( (int) $t['id'] ),
				'liberar'    => ( Pines::celularDeEsteNavegador()['trabajador']['id'] ?? 0 ) == $t['id'], // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			)
		);
	}

	/** Mostrar el DNI completo queda en el historial. */
	public static function miDni( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorActual( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		Auditoria::registrar( 'trabajador.ver_dni', 'trabajador', (int) $t['id'], null, null, 'Lo pidió desde su perfil', (int) $t['id'] );

		return self::respuesta( array( 'dni' => (string) $t['dni'] ) );
	}

	public static function salir(): WP_REST_Response {
		SesionTrabajador::cerrar();

		return self::respuesta( array( 'ok' => true ) );
	}

	// ---- PIN de ingreso (T0B, T0C, T7C) ----

	/** Si en este celular se puede entrar con PIN, y si el celular está registrado (para T0A). */
	public static function pinEstado(): WP_REST_Response {
		$celular = Pines::celularDeEsteNavegador();

		return self::respuesta(
			array(
				'pin'        => Pines::disponible(),
				'largo'      => Pines::largo(),
				'registrado' => null !== $celular,
				'nombre'     => $celular ? (string) $celular['trabajador']['nombres'] : '',
			)
		);
	}

	public static function pinEntrar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		if ( $bloqueo = self::bloqueado() ) {
			return $bloqueo;
		}

		$t = Pines::entrar( (string) $peticion->get_param( 'pin' ) );

		if ( $t instanceof WP_Error ) {
			if ( 'pka_pin' === $t->get_error_code() ) {
				self::sumarFallo();
			}

			return $t;
		}

		return self::respuesta( array( 'ok' => true, 'nombre' => $t['nombres'] ) );
	}

	public static function crearPin( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorActual( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$r = Pines::crear( (int) $t['id'], (string) $peticion->get_param( 'pin' ) );

		return $r instanceof WP_Error ? $r : self::respuesta( array( 'ok' => true, 'mensaje' => 'Listo: ya puedes entrar con tu PIN en este celular. Para marcar se sigue usando tu huella.' ) );
	}

	public static function quitarPin( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorActual( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		Pines::quitar( (int) $t['id'] );

		return self::respuesta( array( 'ok' => true, 'mensaje' => 'Quitaste tu PIN. Ahora solo entras con tu huella.' ) );
	}

	// ---- Registro propio y cambio de celular (T1) ----

	/** ¿Está en el taller? Lo pide la bienvenida (T1A) antes de empezar. */
	public static function registroEstado(): WP_REST_Response {
		$p = self::presencia()->get_data();

		return self::respuesta( array( 'en_taller' => (bool) $p['cumple'], 'mensaje' => $p['mensaje'], 'acepta_qr' => $p['acepta_qr'], 'aviso' => Aviso::paraApp() ) );
	}

	public static function registroNuevo( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		if ( $bloqueo = self::bloqueado() ) {
			return $bloqueo;
		}

		$t = Celulares::iniciarRegistro( $peticion->get_params() );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		return self::respuesta( array( 'flujo' => Celulares::abrirFlujo( Celulares::REGISTRO, (int) $t['id'] ), 'nombre' => $t['nombres'] ) + Passkeys::opcionesDeRegistro( $t, 0 ) );
	}

	/** Buscar mi perfil (T1G): solo nombres, y solo desde el taller. */
	public static function registroBuscar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		if ( Temporales::bloqueado( 'buscar|' . Peticion::ip(), 40 ) ) {
			return new WP_Error( 'pka_bloqueado', 'Demasiadas búsquedas. Espera unos minutos.', array( 'status' => 429 ) );
		}

		if ( ! Celulares::enElTaller() ) {
			return new WP_Error( 'pka_sin_presencia', 'Para cambiar tu celular tienes que estar en el taller: conéctate al WiFi o escanea el QR de la pantalla.', array( 'status' => 403 ) );
		}

		Temporales::sumarFallo( 'buscar|' . Peticion::ip(), 15 * MINUTE_IN_SECONDS );

		return self::respuesta( array( 'perfiles' => Celulares::buscarPerfiles( (string) $peticion->get_param( 'texto' ) ) ) );
	}

	public static function registroConfirmar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = Celulares::confirmarIdentidad( (int) $peticion->get_param( 'trabajador_id' ), (string) $peticion->get_param( 'dni' ), $peticion->get_param( 'cumple_dia' ), $peticion->get_param( 'cumple_mes' ) );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		return self::respuesta( array( 'flujo' => Celulares::abrirFlujo( Celulares::CAMBIO, (int) $t['id'] ), 'nombre' => $t['nombres'] ) + Passkeys::opcionesDeRegistro( $t, 0 ) );
	}

	public static function registroCompletar( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$r = Celulares::completar( (string) $peticion->get_param( 'flujo' ), (string) $peticion->get_param( 'reto' ), (array) $peticion->get_param( 'credencial' ) );

		return $r instanceof WP_Error ? $r : self::respuesta( $r );
	}

	/** Solicitar liberar este celular (T0D), desde el celular registrado. */
	public static function liberarCelular(): WP_REST_Response|WP_Error {
		$celular = Pines::celularDeEsteNavegador();

		if ( null === $celular ) {
			return new WP_Error( 'pka_celular', 'Este celular no está registrado. Si cambiaste de celular, toca "Cambié de celular" estando en el taller.', array( 'status' => 404 ) );
		}

		Celulares::pedirLiberar( (int) $celular['trabajador']['id'], $celular['dispositivo_id'] );

		return self::respuesta( array( 'ok' => true, 'mensaje' => 'Solicitud enviada. Cuando gerencia la apruebe, este celular quedará sin acceso. Tus datos y tus horas se conservan.' ) );
	}

	/**
	 * Detalle de cada día del mes para "Mis horas" (T6): los cuatro pasos, las
	 * otras salidas, las solicitudes de corrección y qué marca falta pedir.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function detalleDelMes( array $t, string $desde, string $hasta ): array {
		global $wpdb;

		$id      = (int) $t['id'];
		$zona    = Ajustes::zona();
		$hoy     = Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$marcas  = Marcas::delRango( array( $id ), $desde, $hasta )[ $id ] ?? array();
		$calculo = Informes::diasDe( $t, substr( $desde, 0, 7 ) );
		$c       = Tablas::nombre( 'correcciones' );
		$filas   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$c} WHERE trabajador_id = %d AND marcado_utc BETWEEN %s AND %s ORDER BY id", $id, $desde . ' 00:00:00', ( new DateTimeImmutable( $hasta ) )->modify( '+2 days' )->format( 'Y-m-d' ) . ' 00:00:00' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$porDia  = array();

		foreach ( $filas as $f ) {
			$dia = Ajustes::diaLaboral()->de( new DateTimeImmutable( (string) $f['marcado_utc'], new DateTimeZone( 'UTC' ) ) );

			if ( 'reemplazar' === $f['accion'] || 'anular' === $f['accion'] ) {
				continue;
			}

			$porDia[ $dia ][] = array(
				'paso'      => 'entrada' === $f['tipo'] ? 'Entrada' : self::nombreDeSalida( (string) $f['motivo_clave'] ),
				'hora'      => Ajustes::local( (string) $f['marcado_utc'], 'H:i' ),
				'estado'    => $f['estado'],
				'pedida'    => $f['hora_pedida_utc'] ? Ajustes::local( (string) $f['hora_pedida_utc'], 'H:i' ) : '',
				'respuesta' => (string) $f['nota_resolucion'],
			);
		}

		$detalle = array();
		$dias    = array_unique( array_merge( array_keys( $marcas ), array_keys( $porDia ), array_keys( array_filter( $calculo, static fn( $j ) => $j->esFalta() || CalculoJornada::INCOMPLETO === $j->estado ) ) ) );

		foreach ( $dias as $dia ) {
			if ( $dia > $hasta || $dia < $desde ) {
				continue;
			}

			$delDia  = $marcas[ $dia ] ?? array();
			$horario = Horarios::deTrabajador( $t, $dia );
			$vista   = VistaDia::de( $delDia, $horario, $dia, $zona, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
			$abierto = $delDia && TotalesDia::calcular( $delDia )->incompleto && $dia < $hoy;
			$extras  = array();

			foreach ( MaquinaEstados::ordenar( $delDia ) as $m ) {
				if ( TipoMarca::SALIDA === $m->tipo && \Pekokis\Asistencia\Nucleo\CategoriaMotivo::TEMPORAL === $m->motivo?->categoria ) {
					$extras[] = array( 'hora' => Marcas::horaLocal( $m->marcadoUtc ), 'texto' => 'Salida · ' . $m->motivo->nombre );
				}
			}

			$pendientes = array_filter( $porDia[ $dia ] ?? array(), static fn( $s ) => 'pendiente' === $s['estado'] );
			$pedir      = array();

			if ( $abierto && ! $pendientes ) {
				$ultima  = MaquinaEstados::ordenar( $delDia );
				$pedir[] = array( 'accion' => 'fin_jornada', 'paso' => 'Salida', 'despues_de' => Marcas::horaLocal( $ultima[ count( $ultima ) - 1 ]->marcadoUtc ) );
			} elseif ( ! $delDia && ( $calculo[ $dia ] ?? null )?->esFalta() && ! $pendientes ) {
				$pedir[] = array( 'accion' => 'entrada', 'paso' => 'Entrada', 'despues_de' => '' );
			}

			$detalle[ $dia ] = array(
				'titulo'      => wp_date( 'l j \d\e F', ( new DateTimeImmutable( $dia . ' 12:00', $zona ) )->getTimestamp(), $zona ),
				'pasos'       => $vista['pasos'],
				'extras'      => $extras,
				'incompleto'  => $abierto,
				'falta'       => ! $delDia && ( $calculo[ $dia ] ?? null )?->esFalta(),
				'solicitudes' => $porDia[ $dia ] ?? array(),
				'pedir'       => $pedir,
				'horas'       => isset( $calculo[ $dia ] ) && $calculo[ $dia ]->minutosTrabajados ? VistaDia::duracion( $calculo[ $dia ]->minutosTrabajados ) : '',
				'extra'       => isset( $calculo[ $dia ] ) && $calculo[ $dia ]->minutosAdicionales() ? VistaDia::duracion( $calculo[ $dia ]->minutosAdicionales() ) : '',
			);
		}

		return $detalle;
	}

	/**
	 * Cifras de arriba de "Mis horas" (T5): horas trabajadas, horas extra y días
	 * completos. Sin ningún dato de dinero.
	 *
	 * @return array{horas: string, extra: string, completos: int, hay: bool}
	 */
	private static function resumenDelMes( array $t, string $mes ): array {
		$dias      = Informes::diasDe( $t, $mes );
		$trabajado = 0;
		$extra     = 0;
		$completos = 0;

		foreach ( $dias as $j ) {
			$trabajado += $j->minutosTrabajados;
			$extra     += $j->minutosAdicionales();
			$completos += CalculoJornada::COMPLETO === $j->estado && $j->trabajoAlgo() ? 1 : 0;
		}

		$h = static fn( int $min ): string => rtrim( rtrim( number_format( $min / 60, 1, ',', '' ), '0' ), ',' ) . ' h';

		return array( 'horas' => $h( $trabajado ), 'extra' => $h( $extra ), 'completos' => $completos, 'hay' => $trabajado > 0 );
	}

	private static function nombreDeSalida( string $motivo ): string {
		return match ( $motivo ) {
			'refrigerio'  => 'Inicio de comida',
			'fin_jornada' => 'Salida',
			default       => 'Salida · ' . ( Motivos::porClave( $motivo )?->nombre ?? $motivo ),
		};
	}

	private static function iniciales( array $t ): string {
		return mb_strtoupper( mb_substr( trim( (string) $t['nombres'] ), 0, 1 ) . mb_substr( trim( (string) $t['apellidos'] ), 0, 1 ) );
	}

	/**
	 * A qué jornada pertenece este momento: "de_ayer" si se continúa la de ayer
	 * pasada la hora de corte (T2G), "otro_dia" si ya es otra fecha en el reloj (T2F, T3E).
	 *
	 * @return array{dia: string, texto: string, de_ayer: bool, otro_dia: bool}
	 */
	private static function jornadaDe( string $dia, DateTimeImmutable $ahora ): array {
		$zona = Ajustes::zona();

		return array(
			'dia'      => $dia,
			'texto'    => wp_date( 'l j', ( new DateTimeImmutable( $dia . ' 12:00', $zona ) )->getTimestamp(), $zona ),
			'de_ayer'  => $dia < Ajustes::diaLaboral()->de( $ahora ),
			'otro_dia' => $dia !== $ahora->setTimezone( $zona )->format( 'Y-m-d' ),
		);
	}

	/** "09:00:00" → "9:00". */
	private static function horaCorta( string $hora ): string {
		return ltrim( substr( $hora, 0, 5 ), '0' );
	}

	/**
	 * "Mi recibo": con el ticket de la huella da un permiso de 15 minutos para
	 * ver y enviar sus recibos (elegir el PDF puede tomar más de 2 minutos).
	 */
	public static function misRecibos( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorActual( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$token = Temporales::guardar( 'recibo', array( 'trabajador_id' => (int) $t['id'] ), self::SEGUNDOS_RECIBO );

		return self::respuesta( array( 'token' => $token, 'segundos' => self::SEGUNDOS_RECIBO, 'meses' => Recibos::paraApp( $t ), 'constancia' => Constancias::paraApp( $t ) ) );
	}

	/** La constancia de retención de 4.ª o 5.ª (con el mismo permiso de 15 minutos de "Mi recibo"). */
	public static function enviarConstancia( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorDelRecibo( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$archivo = $peticion->get_file_params()['archivo'] ?? null;

		if ( ! is_array( $archivo ) || UPLOAD_ERR_OK !== (int) ( $archivo['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$errorSubida = is_array( $archivo ) ? (int) ( $archivo['error'] ?? 0 ) : UPLOAD_ERR_NO_FILE;
			$mensaje     = in_array( $errorSubida, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'El archivo pesa demasiado.' : 'Elige el PDF de la constancia.';

			return new WP_Error( 'pka_constancia', 'Revisa la constancia.', array( 'status' => 400, 'errores' => array( 'archivo' => $mensaje ) ) );
		}

		$id = Constancias::enviar( (int) $t['id'], array( 'tipo' => $peticion->get_param( 'tipo' ), 'numero' => $peticion->get_param( 'numero' ), 'vigente_hasta' => $peticion->get_param( 'vigente_hasta' ) ), $archivo );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return self::respuesta( array( 'mensaje' => 'Constancia enviada. Gerencia la revisará.', 'meses' => Recibos::paraApp( $t ), 'constancia' => Constancias::paraApp( $t ) ) );
	}

	public static function anularConstancia( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorDelRecibo( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$r = Constancias::anular( (int) $peticion->get_param( 'id' ), (int) $t['id'] );

		if ( $r instanceof WP_Error ) {
			return $r;
		}

		return self::respuesta( array( 'mensaje' => 'Envío anulado. Puedes subirla otra vez.', 'meses' => Recibos::paraApp( $t ), 'constancia' => Constancias::paraApp( $t ) ) );
	}

	public static function enviarRecibo( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorDelRecibo( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$archivo = $peticion->get_file_params()['archivo'] ?? null;

		if ( ! is_array( $archivo ) || UPLOAD_ERR_OK !== (int) ( $archivo['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$errorSubida = is_array( $archivo ) ? (int) ( $archivo['error'] ?? 0 ) : UPLOAD_ERR_NO_FILE;
			$mensaje     = in_array( $errorSubida, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'El archivo pesa demasiado.' : 'Elige el PDF del recibo.';

			return new WP_Error( 'pka_recibo', 'Revisa el recibo.', array( 'status' => 400, 'errores' => array( 'archivo' => $mensaje ) ) );
		}

		$id = Recibos::enviar( (int) $t['id'], (string) $peticion->get_param( 'mes' ), array( 'numero' => $peticion->get_param( 'numero' ), 'monto' => $peticion->get_param( 'monto' ) ), $archivo );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return self::respuesta( array( 'mensaje' => 'Recibo enviado. Gerencia lo revisará.', 'meses' => Recibos::paraApp( $t ) ) );
	}

	public static function anularRecibo( WP_REST_Request $peticion ): WP_REST_Response|WP_Error {
		$t = self::trabajadorDelRecibo( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$r = Recibos::anular( (int) $peticion->get_param( 'id' ), (int) $t['id'] );

		if ( $r instanceof WP_Error ) {
			return $r;
		}

		return self::respuesta( array( 'mensaje' => 'Envío anulado. Puedes enviar uno nuevo.', 'meses' => Recibos::paraApp( $t ) ) );
	}

	/** @return array<string, mixed>|WP_Error */
	private static function trabajadorDelRecibo( WP_REST_Request $peticion ): array|WP_Error {
		$permiso = Temporales::leer( 'recibo', (string) $peticion->get_param( 'token' ) );
		$t       = $permiso ? Trabajadores::buscar( (int) $permiso['trabajador_id'] ) : null;

		if ( ! $t ) {
			return new WP_Error( 'pka_ticket', 'Pasaron más de 15 minutos. Vuelve a poner tu huella.', array( 'status' => 401 ) );
		}

		return $t;
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
		$t = self::trabajadorActual( $peticion );

		if ( $t instanceof WP_Error ) {
			return $t;
		}

		$accion = Accion::tryFrom( (string) $peticion->get_param( 'accion' ) );

		if ( null === $accion ) {
			return new WP_Error( 'pka_accion', 'Elige qué marca olvidaste.', array( 'status' => 400 ) );
		}

		$resultado = Correcciones::solicitar(
			(int) $t['id'],
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

		return self::respuesta( array( 'ok' => true, 'mensaje' => 'Solicitud enviada. Gerencia la revisará.' ) );
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
