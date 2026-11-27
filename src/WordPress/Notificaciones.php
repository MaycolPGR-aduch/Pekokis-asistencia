<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Estado;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\ReglasAvisos;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use Pekokis\Asistencia\Nucleo\TipoMarca;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Avisos por correo y webhook (fase 4).
 *
 * Todo pasa por una cola (tabla avisos): lo que ocurre (una solicitud, una
 * marca por revisar) solo encola, y el cron de cada 5 minutos revisa lo que
 * depende de la hora (ausentes, refrigerios largos…) y despacha. Así marcar
 * nunca espera a un correo, y un fallo de envío no rompe nada.
 *
 * - Cada aviso tiene una clave única: no se repite aunque el cron pase varias veces.
 * - Al enviar se agrupan los avisos del mismo tipo para la misma persona: un
 *   solo correo "No llegaron a su hora" con la lista, no uno por trabajador.
 * - El webhook (Make, n8n o el bot) recibe JSON firmado con HMAC, para
 *   reenviarlo por WhatsApp.
 * - Todos los avisos empiezan apagados: se encienden en Asistencia → Ajustes.
 */
final class Notificaciones {
	public const EVENTO = 'pka_avisos';

	private const CADA          = 'pka_cinco_minutos';
	private const MAX_INTENTOS  = 3;
	private const DIAS_GUARDADO = 90;

	public static function registrar(): void {
		add_filter( 'cron_schedules', array( self::class, 'intervalo' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::EVENTO, array( self::class, 'cron' ) );

		if ( ! wp_next_scheduled( self::EVENTO ) ) {
			wp_schedule_event( time() + 60, self::CADA, self::EVENTO );
		}
	}

	/** @param array<string, array<string, mixed>> $intervalos */
	public static function intervalo( array $intervalos ): array {
		$intervalos[ self::CADA ] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Cada 5 minutos (avisos de asistencia)' );

		return $intervalos;
	}

	public static function desprogramar(): void {
		wp_clear_scheduled_hook( self::EVENTO );
	}

	public static function cron(): void {
		try {
			self::revisar( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		} catch ( Throwable $e ) {
			error_log( 'Pekokis Asistencia, avisos: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		self::despachar();

		if ( 0 === wp_rand( 0, 50 ) ) {
			self::limpiar();
		}
	}

	/** @return string[] Tipos encendidos en Ajustes. */
	public static function activos(): array {
		$activos = Ajustes::todos()['avisos_activos'];

		return is_array( $activos ) ? array_values( array_intersect( $activos, array_keys( ReglasAvisos::TIPOS ) ) ) : array();
	}

	public static function activo( string $tipo ): bool {
		return in_array( $tipo, self::activos(), true );
	}

	// ------------------------------------------------------------------
	// Lo que depende de la hora (cron).
	// ------------------------------------------------------------------

	/** Revisa a todos los trabajadores activos y encola lo que toque ahora. */
	public static function revisar( DateTimeImmutable $ahora ): int {
		if ( ! self::activos() ) {
			return 0;
		}

		$zona      = Ajustes::zona();
		$dia       = Ajustes::diaLaboral()->de( $ahora );
		$reglas    = ReglasAvisos::desdeAjustes( Ajustes::todos() );
		$personas  = Trabajadores::listar();
		$ids       = array_map( static fn( array $t ): int => (int) $t['id'], $personas );
		$marcas    = Marcas::delRango( $ids, $dia, $dia );
		$ausencias = Ausencias::delRango( $ids, $dia, $dia );
		$antes     = self::contar();

		foreach ( $personas as $t ) {
			$id      = (int) $t['id'];
			$horario = Horarios::deTrabajador( $t, $dia );
			$r       = ResumenDia::calcular( $marcas[ $id ][ $dia ] ?? array(), $horario, $dia, $zona, $ahora, $ausencias[ $id ][ $dia ] ?? null );

			// Alguien que se dio de alta hoy no "faltó" esta mañana.
			if ( Ajustes::local( (string) $t['creado_en'] ) > $dia ) {
				continue;
			}

			// Sigue en la jornada de ayer (pasó la medianoche): hoy todavía no empieza.
			if ( ! ( $marcas[ $id ][ $dia ] ?? array() ) && Jornadas::diaActual( $id, $ahora ) !== $dia ) {
				continue;
			}

			foreach ( $reglas->evaluar( $r, $horario, $dia, $zona, $ahora ) as $tipo => $clave ) {
				self::encolar( $tipo, $clave . '|' . $id, $t, self::lineaDelDia( $tipo, $t, $r, $horario, $zona ) );
			}
		}

		self::revisarTaller( $ahora, $zona, $dia );

		return self::contar() - $antes;
	}

	/** Pantallas sin conexión e IP nueva, solo en horario de taller (lunes a sábado, 7 a 20 h, sin feriado). */
	private static function revisarTaller( DateTimeImmutable $ahora, DateTimeZone $zona, string $dia ): void {
		$local = $ahora->setTimezone( $zona );
		$hora  = (int) $local->format( 'G' );

		if ( 7 === (int) $local->format( 'N' ) || $hora < 7 || $hora >= 20 || '' !== Calendario::descripcion( $dia ) ) {
			return;
		}

		if ( self::activo( ReglasAvisos::PANTALLA ) ) {
			foreach ( Pantallas::sinConexion() as $p ) {
				$desde = $p['ultima_conexion'] ? ( new DateTimeImmutable( $p['ultima_conexion'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $zona )->format( 'H:i' ) : 'que se activó';
				self::encolar( ReglasAvisos::PANTALLA, 'pantalla|' . $p['id'] . '|' . $dia, null, sprintf( 'La pantalla "%s" no se conecta desde %s. ¿Se apagó o se quedó sin internet?', $p['nombre'], $desde ), admin_url( 'admin.php?page=pka-ajustes' ) );
			}
		}

		if ( self::activo( ReglasAvisos::RED_NUEVA ) ) {
			foreach ( Presencia::redesCandidatas() as $c ) {
				self::encolar( ReglasAvisos::RED_NUEVA, 'red|' . $c['red'] . '|' . $dia, null, sprintf( 'Hoy llegaron %d marcas por QR de %d personas desde %s, que no está autorizada. Puede que haya cambiado la IP del taller.', (int) $c['marcas'], (int) $c['trabajadores'], $c['red'] ), admin_url( 'admin.php?page=pka-ajustes' ) );
			}
		}
	}

	private static function lineaDelDia( string $tipo, array $t, ResumenDia $r, \Pekokis\Asistencia\Nucleo\HorarioDelDia $h, DateTimeZone $zona ): string {
		$nombre = Trabajadores::nombreCompleto( $t );
		$desde  = $r->desde ? $r->desde->setTimezone( $zona )->format( 'H:i' ) : '';

		return match ( $tipo ) {
			ReglasAvisos::AUSENTE          => sprintf( '%s entra a las %s y todavía no marca.', $nombre, substr( (string) $h->entrada, 0, 5 ) ),
			ReglasAvisos::REFRIGERIO_LARGO => sprintf( '%s salió a refrigerio a las %s y lleva %d min (le tocan %d).', $nombre, $desde, $r->minutosEnEstado, $h->refrigerioMin ),
			ReglasAvisos::SALIDA_LARGA     => sprintf( '%s salió (%s) a las %s y lleva %d min fuera.', $nombre, $r->motivoActual ? $r->motivoActual->nombre : 'salida temporal', $desde, $r->minutosEnEstado ),
			ReglasAvisos::SIN_SALIDA       => sprintf(
				'Hola, %s: no has marcado tu salida de hoy (figuras %s). Si ya te fuiste, entra a la app y usa "Olvidé marcar" para que tu jefe lo corrija.',
				$t['nombres'],
				Estado::REFRIGERIO === $r->estado ? 'en refrigerio' : ( Estado::FUERA_TEMPORAL === $r->estado ? 'en una salida temporal' : 'trabajando' )
			),
			default => $nombre,
		};
	}

	// ------------------------------------------------------------------
	// Lo que dispara un hecho (se llama desde el código que lo registra).
	// ------------------------------------------------------------------

	/** El trabajador pidió una corrección ("olvidé marcar"). */
	public static function solicitud( int $correccionId ): void {
		self::seguro(
			static function () use ( $correccionId ): void {
				$c = self::correccion( $correccionId );

				if ( ! $c ) {
					return;
				}

				$t = Trabajadores::buscar( (int) $c['trabajador_id'] );
				self::encolar(
					ReglasAvisos::SOLICITUD,
					'solicitud|' . $correccionId,
					$t,
					sprintf( '%s pide registrar %s del %s a las %s: "%s".', Trabajadores::nombreCompleto( $t ), self::queMarca( $c ), self::fecha( self::diaLocal( $c['marcado_utc'] ) ), self::horaLocal( $c['marcado_utc'] ), $c['justificacion'] ),
					admin_url( 'admin.php?page=pka-solicitudes' )
				);
			}
		);
	}

	/** El jefe aprobó o rechazó la corrección: se avisa al trabajador. */
	public static function resuelta( int $correccionId ): void {
		self::seguro(
			static function () use ( $correccionId ): void {
				$c = self::correccion( $correccionId );

				if ( ! $c || 'pendiente' === $c['estado'] ) {
					return;
				}

				$t       = Trabajadores::buscar( (int) $c['trabajador_id'] );
				$aprobo  = Correcciones::APROBADA === $c['estado'];
				$detalle = '' !== trim( (string) $c['nota_resolucion'] ) ? ' Nota: ' . $c['nota_resolucion'] : '';

				self::encolar(
					ReglasAvisos::RESUELTA,
					'resuelta|' . $correccionId,
					$t,
					sprintf( 'Tu solicitud de %s del %s a las %s fue %s.%s', self::queMarca( $c ), self::fecha( self::diaLocal( $c['marcado_utc'] ) ), self::horaLocal( $c['marcado_utc'] ), $aprobo ? 'aprobada' : 'rechazada', $detalle ),
					home_url( '/' )
				);
			}
		);
	}

	/** Una marca que el jefe tiene que revisar (emergencia, permiso, salud). */
	public static function revision( int $marcaId ): void {
		self::seguro(
			static function () use ( $marcaId ): void {
				global $wpdb;

				$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'marcas' ) . ' WHERE id = %d', $marcaId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				if ( ! $m || ! (int) $m['requiere_revision'] ) {
					return;
				}

				$t      = Trabajadores::buscar( (int) $m['trabajador_id'] );
				$motivo = Motivos::porClave( $m['motivo_clave'] );
				$fuera  = 'sin_presencia' === $m['prueba'] ? ' fuera del taller' : '';

				self::encolar(
					ReglasAvisos::REVISION,
					'revision|' . $marcaId,
					$t,
					sprintf( '%s marcó %s a las %s%s.%s', Trabajadores::nombreCompleto( $t ), $motivo ? '"' . $motivo->nombre . '"' : 'una salida', self::horaLocal( $m['marcado_utc'] ), $fuera, '' !== $m['nota'] ? ' Nota: "' . $m['nota'] . '".' : '' ),
					admin_url( 'admin.php?page=pka-solicitudes' )
				);
			}
		);
	}

	/** Alguien se registró, cambió de celular o pidió liberarlo: gerencia lo aprueba (G13). */
	public static function celular( int $solicitudId ): void {
		self::seguro(
			static function () use ( $solicitudId ): void {
				$s = Celulares::buscar( $solicitudId );
				$t = $s ? Trabajadores::buscar( (int) $s['trabajador_id'] ) : null;

				if ( $t ) {
					$que = array(
						Celulares::REGISTRO => 'se registró como persona nueva desde el taller',
						Celulares::CAMBIO   => 'registró un celular nuevo y pide dar de baja el anterior',
						Celulares::LIBERAR  => 'pide liberar su celular',
					)[ $s['tipo'] ] ?? 'pidió un cambio de celular';
					self::encolar( ReglasAvisos::CELULAR, 'celular|' . $solicitudId, $t, sprintf( '%s %s. Apruébalo o recházalo en Asistencia → Celulares.', Trabajadores::nombreCompleto( $t ), $que ), admin_url( 'admin.php?page=pka-celulares' ) );
				}
			}
		);
	}

	/** Se aprobó o rechazó: se avisa a la persona (al webhook con su teléfono, o a su correo). */
	public static function celularResuelto( int $solicitudId ): void {
		self::seguro(
			static function () use ( $solicitudId ): void {
				$s = Celulares::buscar( $solicitudId );
				$t = $s ? Trabajadores::buscar( (int) $s['trabajador_id'] ) : null;

				if ( $t && Celulares::LIBERAR !== $s['tipo'] ) {
					$que   = Celulares::REGISTRO === $s['tipo'] ? 'Tu registro' : 'Tu cambio de celular';
					$linea = Celulares::APROBADA === $s['estado'] ? $que . ' fue aprobado. Ya puedes entrar y marcar.' : $que . ' fue rechazado: ' . $s['respuesta'];
					self::encolar( ReglasAvisos::CELULAR_RESUELTO, 'celular_resuelto|' . $solicitudId, $t, $linea );
				}
			}
		);
	}

	/** Una jornada pasó el límite de horas (maquetas T3F): se acepta, pero gerencia la revisa. */
	public static function jornadaLarga( int $marcaId, int $trabajadorId, string $dia, int $minutos ): void {
		self::seguro(
			static function () use ( $marcaId, $trabajadorId, $dia, $minutos ): void {
				$t = Trabajadores::buscar( $trabajadorId );

				if ( $t ) {
					self::encolar(
						ReglasAvisos::JORNADA_LARGA,
						'jornada_larga|' . $marcaId,
						$t,
						sprintf( '%s cerró su jornada del %s con %d h %02d min desde su entrada. Revisa si la salida es correcta.', Trabajadores::nombreCompleto( $t ), wp_date( 'l j', strtotime( $dia . ' 12:00:00' ) ), intdiv( $minutos, 60 ), $minutos % 60 ),
						admin_url( 'admin.php?page=pka-asistencia&vista=dia&trabajador=' . $trabajadorId . '&dia=' . $dia )
					);
				}
			}
		);
	}

	/** Un trabajador envió su recibo por honorarios: se avisa a gerencia. */
	public static function reciboEnviado( int $reciboId ): void {
		self::seguro(
			static function () use ( $reciboId ): void {
				$r = Recibos::buscar( $reciboId );

				if ( $r ) {
					$t = Trabajadores::buscar( (int) $r['trabajador_id'] );
					self::encolar( ReglasAvisos::RECIBO_ENVIADO, 'recibo_enviado|' . $reciboId, $t, sprintf( '%s envió su recibo %s de %s por %s.', Trabajadores::nombreCompleto( $t ), $r['numero'], wp_date( 'F Y', strtotime( $r['mes'] . '-15 12:00:00' ) ), \Pekokis\Asistencia\Nucleo\Sueldo::soles( (int) $r['monto_centimos'] ) ), admin_url( 'admin.php?page=pka-recibos&mes=' . $r['mes'] ) );
				}
			}
		);
	}

	/** Llegó una constancia de retención (mismo interruptor que "Llegó un recibo"). */
	public static function constanciaEnviada( int $id ): void {
		self::seguro(
			static function () use ( $id ): void {
				$c = Constancias::buscar( $id );

				if ( $c ) {
					$t = Trabajadores::buscar( (int) $c['trabajador_id'] );
					self::encolar( ReglasAvisos::RECIBO_ENVIADO, 'constancia_enviada|' . $id, $t, sprintf( '%s envió su constancia de retención (%s), válida hasta el %s.', Trabajadores::nombreCompleto( $t ), \Pekokis\Asistencia\Nucleo\ValidacionConstancia::TIPOS[ $c['tipo'] ] ?? $c['tipo'], wp_date( 'd/m/Y', strtotime( $c['vigente_hasta'] . ' 12:00:00' ) ) ), admin_url( 'admin.php?page=pka-recibos&vista=constancias' ) );
				}
			}
		);
	}

	/** Gerencia observó una constancia (mismo interruptor que los recibos observados). */
	public static function constanciaObservada( int $id ): void {
		self::seguro(
			static function () use ( $id ): void {
				$c = Constancias::buscar( $id );

				if ( $c ) {
					self::encolar( ReglasAvisos::RECIBO_OBSERVADO, 'constancia_observada|' . $id, Trabajadores::buscar( (int) $c['trabajador_id'] ), sprintf( 'Tu constancia de retención tiene una observación: "%s". Entra a la app, en "Mi recibo", para subirla otra vez.', $c['comentario'] ), home_url( '/' ) );
				}
			}
		);
	}

	/** Gerencia observó un recibo: se avisa al trabajador con el comentario. */
	public static function reciboObservado( int $reciboId ): void {
		self::seguro(
			static function () use ( $reciboId ): void {
				$r = Recibos::buscar( $reciboId );

				if ( $r ) {
					self::encolar( ReglasAvisos::RECIBO_OBSERVADO, 'recibo_observado|' . $reciboId, Trabajadores::buscar( (int) $r['trabajador_id'] ), sprintf( 'Tu recibo %s de %s tiene una observación: "%s". Entra a la app, en "Mi recibo", para corregirlo.', $r['numero'], wp_date( 'F Y', strtotime( $r['mes'] . '-15 12:00:00' ) ), $r['comentario'] ), home_url( '/' ) );
				}
			}
		);
	}

	/**
	 * Botón "Recordar" de gerencia: sale aunque el aviso esté apagado, porque lo
	 * pidió una persona. Devuelve cuántos destinos se encolaron.
	 */
	public static function recordarRecibo( int $trabajadorId, string $mes ): int {
		$t = Trabajadores::buscar( $trabajadorId );

		if ( ! $t ) {
			return 0;
		}

		$n = self::encolar( ReglasAvisos::RECIBO_PENDIENTE, 'recibo_pendiente|' . $trabajadorId . '|' . $mes . '|' . gmdate( 'YmdHi' ), $t, sprintf( 'Hola, %s: falta tu recibo por honorarios de %s. Entra a la app, en "Mi recibo", y súbelo con su número, su monto y el PDF.', $t['nombres'], wp_date( 'F Y', strtotime( $mes . '-15 12:00:00' ) ) ), home_url( '/' ), true );

		if ( $n ) {
			Auditoria::registrar( 'recibo.recordar', 'trabajador', $trabajadorId, null, array( 'mes' => $mes ) );
		}

		return $n;
	}

	// ------------------------------------------------------------------
	// La cola.
	// ------------------------------------------------------------------

	/**
	 * Encola un aviso para cada destino (correo y, si hay, webhook). Si la clave
	 * ya existe para ese destino, no hace nada.
	 *
	 * @param array<string, mixed>|null $t Trabajador del que trata el aviso.
	 */
	public static function encolar( string $tipo, string $clave, ?array $t, string $linea, string $enlace = '', bool $forzar = false ): int {
		global $wpdb;

		if ( ! $forzar && ! self::activo( $tipo ) ) {
			return 0;
		}

		$tabla  = Tablas::nombre( 'avisos' );
		$nuevos = 0;

		foreach ( self::destinos( $tipo, $t ) as $d ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$nuevos += (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$tabla} (tipo, clave, canal, destino, nombre, telefono, trabajador_id, linea, enlace, estado, intentos, creado_en)
					VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s, 'pendiente', 0, %s)",
					$tipo,
					mb_substr( $clave, 0, 120 ),
					$d['canal'],
					mb_substr( $d['destino'], 0, 190 ),
					mb_substr( $d['nombre'], 0, 120 ),
					$d['telefono'],
					$t ? (int) $t['id'] : 0,
					mb_substr( $linea, 0, 1000 ),
					'' !== $enlace ? $enlace : ( ReglasAvisos::PARA_TRABAJADOR === ReglasAvisos::destinatario( $tipo ) ? home_url( '/' ) : admin_url( 'admin.php?page=pka-asistencia' ) ),
					Peticion::ahoraUtc()
				)
			);
		}

		return $nuevos;
	}

	/**
	 * A quién y por dónde: el jefe del trabajador (o RR.HH. si no tiene), el
	 * propio trabajador, o RR.HH. para los avisos del sistema.
	 *
	 * @return array<int, array{canal: string, destino: string, nombre: string, telefono: string}>
	 */
	public static function destinos( string $tipo, ?array $t ): array {
		$para     = ReglasAvisos::destinatario( $tipo );
		$correo   = '';
		$nombre   = '';
		$telefono = '';

		if ( ReglasAvisos::PARA_TRABAJADOR === $para ) {
			if ( ! $t ) {
				return array();
			}

			$correo   = is_email( (string) $t['correo'] ) ? (string) $t['correo'] : '';
			$nombre   = Trabajadores::nombreCompleto( $t );
			$telefono = preg_replace( '/[^\d+]/', '', (string) $t['telefono'] );
		} else {
			$jefe = ReglasAvisos::PARA_JEFE === $para && $t && (int) $t['jefe_user_id'] ? get_userdata( (int) $t['jefe_user_id'] ) : false;

			if ( $jefe && is_email( $jefe->user_email ) ) {
				$correo   = $jefe->user_email;
				$nombre   = $jefe->display_name;
				$telefono = self::telefonoDeUsuario( (int) $jefe->ID );
			} else {
				$correo = Respaldo::destinatario();
				$nombre = 'RR.HH.';
			}
		}

		$destinos = array();

		if ( '' !== $correo ) {
			$destinos[] = array( 'canal' => 'correo', 'destino' => $correo, 'nombre' => $nombre, 'telefono' => $telefono );
		}

		if ( '' !== self::webhook() && ( '' !== $telefono || '' !== $correo ) ) {
			$destinos[] = array( 'canal' => 'webhook', 'destino' => '' !== $telefono ? $telefono : $correo, 'nombre' => $nombre, 'telefono' => $telefono );
		}

		return $destinos;
	}

	/**
	 * Envía lo pendiente, agrupado por persona, canal y tipo.
	 *
	 * @return array{enviados: int, fallidos: int}
	 */
	public static function despachar(): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'avisos' );
		$filas = $wpdb->get_results( "SELECT * FROM {$tabla} WHERE estado = 'pendiente' ORDER BY id LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$grupos = array();
		$cuenta = array( 'enviados' => 0, 'fallidos' => 0 );

		foreach ( $filas as $f ) {
			$grupos[ $f['canal'] . '|' . $f['destino'] . '|' . $f['tipo'] ][] = $f;
		}

		foreach ( $grupos as $grupo ) {
			$primera = $grupo[0];
			$error   = 'correo' === $primera['canal'] ? self::porCorreo( $grupo ) : self::porWebhook( $grupo );
			$ids     = implode( ',', array_map( static fn( $f ) => (int) $f['id'], $grupo ) );

			if ( '' === $error ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$tabla} SET estado = 'enviado', enviado_en = %s, intentos = intentos + 1, error = '' WHERE id IN ({$ids})", Peticion::ahoraUtc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cuenta['enviados'] += count( $grupo );
			} else {
				$wpdb->query( $wpdb->prepare( "UPDATE {$tabla} SET intentos = intentos + 1, error = %s, estado = IF(intentos >= %d, 'fallido', 'pendiente') WHERE id IN ({$ids})", mb_substr( $error, 0, 255 ), self::MAX_INTENTOS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cuenta['fallidos'] += count( $grupo );
			}
		}

		return $cuenta;
	}

	/** @param array<int, array<string, mixed>> $grupo */
	private static function porCorreo( array $grupo ): string {
		$p      = $grupo[0];
		$titulo = ReglasAvisos::titulo( $p['tipo'], count( $grupo ) );
		$cuerpo = self::texto( $grupo );

		$enviado = wp_mail( $p['destino'], '[Asistencia] ' . $titulo, $cuerpo );

		return $enviado ? '' : 'wp_mail no pudo enviar el correo (revisa el SMTP).';
	}

	/** @param array<int, array<string, mixed>> $grupo */
	private static function porWebhook( array $grupo ): string {
		$url = self::webhook();

		if ( '' === $url ) {
			return 'No hay webhook de avisos configurado.';
		}

		$p     = $grupo[0];
		$datos = array(
			'evento'  => $p['tipo'],
			'titulo'  => ReglasAvisos::titulo( $p['tipo'], count( $grupo ) ),
			'para'    => array( 'tipo' => ReglasAvisos::destinatario( $p['tipo'] ), 'nombre' => $p['nombre'], 'telefono' => $p['telefono'], 'correo' => str_contains( $p['destino'], '@' ) ? $p['destino'] : '' ),
			'lineas'  => array_map( static fn( $f ) => $f['linea'], $grupo ),
			'mensaje' => self::texto( $grupo ),
			'enlace'  => $p['enlace'],
			'empresa' => (string) Ajustes::todos()['razon_social'],
		);
		$cuerpo = (string) wp_json_encode( $datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$respuesta = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'X-PKA-Firma'  => hash_hmac( 'sha256', $cuerpo, Secretos::clave() ),
				),
				'body'    => $cuerpo,
			)
		);

		if ( is_wp_error( $respuesta ) ) {
			return 'Webhook: ' . $respuesta->get_error_message();
		}

		$codigo = (int) wp_remote_retrieve_response_code( $respuesta );

		return $codigo >= 200 && $codigo < 300 ? '' : 'Webhook respondió ' . $codigo . '.';
	}

	/** Texto del aviso (o de varios juntos). */
	public static function texto( array $grupo ): string {
		$p      = $grupo[0];
		$saludo = ReglasAvisos::PARA_TRABAJADOR === ReglasAvisos::destinatario( $p['tipo'] ) ? '' : 'Hola' . ( 'RR.HH.' !== $p['nombre'] && '' !== $p['nombre'] ? ', ' . $p['nombre'] : '' ) . ":\n\n";
		$lineas = count( $grupo ) > 1
			? implode( "\n", array_map( static fn( $f ) => '- ' . $f['linea'], $grupo ) )
			: $grupo[0]['linea'];

		return $saludo . $lineas . "\n\n" . $p['enlace'] . "\n\n— Asistencia " . Ajustes::todos()['razon_social'] . ' (aviso automático).';
	}

	/** Correo de prueba para revisar que el SMTP funciona. */
	public static function prueba( string $correo ): bool {
		return wp_mail(
			$correo,
			'[Asistencia] Prueba de avisos',
			"Si te llegó este correo, los avisos del sistema de asistencia pueden salir por correo.\n\n" . admin_url( 'admin.php?page=pka-ajustes' ) . "\n\n— Asistencia " . Ajustes::todos()['razon_social']
		);
	}

	/** @return array<int, array<string, mixed>> */
	public static function ultimos( int $cuantos = 30 ): array {
		global $wpdb;

		$tabla = Tablas::nombre( 'avisos' );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} ORDER BY id DESC LIMIT %d", $cuantos ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function limpiar(): void {
		global $wpdb;

		$tabla = Tablas::nombre( 'avisos' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tabla} WHERE creado_en < %s", gmdate( 'Y-m-d H:i:s', time() - self::DIAS_GUARDADO * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function webhook(): string {
		$url = (string) Ajustes::todos()['webhook_avisos'];

		return str_starts_with( $url, 'https://' ) ? $url : '';
	}

	// ------------------------------------------------------------------

	/** Un aviso nunca debe romper lo que lo disparó (marcar, aprobar…). */
	private static function seguro( callable $hacer ): void {
		if ( ! self::activos() ) {
			return;
		}

		try {
			$hacer();
		} catch ( Throwable $e ) {
			error_log( 'Pekokis Asistencia, aviso: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private static function contar(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tablas::nombre( 'avisos' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array<string, mixed>|null */
	private static function correccion( int $id ): ?array {
		global $wpdb;

		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tablas::nombre( 'correcciones' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $fila ? $fila : null;
	}

	private static function queMarca( array $c ): string {
		if ( TipoMarca::ENTRADA->value === $c['tipo'] ) {
			return 'una entrada';
		}

		$motivo = Motivos::porClave( $c['motivo_clave'] );

		return 'una salida' . ( $motivo ? ' (' . $motivo->nombre . ')' : '' );
	}

	private static function telefonoDeUsuario( int $userId ): string {
		global $wpdb;

		$tel = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT telefono FROM ' . Tablas::nombre( 'trabajadores' ) . ' WHERE user_id = %d LIMIT 1', $userId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (string) preg_replace( '/[^\d+]/', '', $tel );
	}

	private static function buscarEn( array $personas, int $id ): array {
		foreach ( $personas as $p ) {
			if ( (int) $p['id'] === $id ) {
				return $p;
			}
		}

		return Trabajadores::buscar( $id ) ?? array( 'id' => $id, 'nombres' => '', 'apellidos' => '#' . $id, 'jefe_user_id' => 0, 'correo' => '', 'telefono' => '' );
	}

	private static function horaLocal( ?string $utc ): string {
		return $utc ? ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( Ajustes::zona() )->format( 'H:i' ) : '';
	}

	private static function diaLocal( ?string $utc ): string {
		return $utc ? ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( Ajustes::zona() )->format( 'Y-m-d' ) : '';
	}

	private static function fecha( string $dia ): string {
		return '' === $dia ? '' : wp_date( 'l j \d\e F', strtotime( $dia . ' 12:00:00' ) );
	}
}
