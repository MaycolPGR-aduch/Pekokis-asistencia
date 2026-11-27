<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Estado;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Ausencias;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Celulares;
use Pekokis\Asistencia\WordPress\Correcciones;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\Horarios;
use Pekokis\Asistencia\WordPress\Informes;
use Pekokis\Asistencia\WordPress\Jornadas;
use Pekokis\Asistencia\WordPress\Marcas;
use Pekokis\Asistencia\WordPress\Pantallas;
use Pekokis\Asistencia\WordPress\Recibos;
use Pekokis\Asistencia\WordPress\Revisiones;
use Pekokis\Asistencia\WordPress\Seguimiento;
use Pekokis\Asistencia\WordPress\Trabajadores;

defined( 'ABSPATH' ) || exit;

/**
 * Asistencia → Dashboard (maquetas G1): lo urgente primero. Cifras de hoy y del
 * mes, horas por día y "Requiere tu atención" con un botón para resolver cada
 * cosa. Lo de dinero solo lo ve quien ve los pagos.
 */
final class PantallaDashboard {
	public const SLUG = 'pka-dashboard';

	private const MAX_ALERTAS_POR_TIPO = 4;

	public static function pintar(): void {
		if ( ! current_user_can( Capacidades::VER_EQUIPO ) ) {
			wp_die( 'No tienes permiso para ver esta página.' );
		}

		$ahora    = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$zona     = Ajustes::zona();
		$hoy      = Ajustes::diaLaboral()->de( $ahora );
		$alcance  = PantallaHoy::alcance();
		$personas = array_values( array_filter( Trabajadores::listar(), static fn( array $t ): bool => null === $alcance || in_array( (int) $t['id'], $alcance, true ) ) );
		$mes      = substr( $hoy, 0, 7 );
		$meses    = Exportes::meses();
		$mesPago  = $meses[1] ?? $mes;
		$hoyDatos = self::cifrasHoy( $personas, $hoy, $ahora, $zona );
		$porDia   = self::horasPorDia( $personas, $mes );
		$trabajo  = array_sum( array_column( $porDia, 'trabajado' ) );
		$extra    = array_sum( array_column( $porDia, 'extra' ) );
		$verPagos = current_user_can( Capacidades::VER_SUELDOS );
		$pago     = $verPagos ? Informes::mes( $mesPago, $alcance ) : null;
		$listos   = $pago ? count( array_filter( $pago['filas'], static fn( PrePlanilla $f ): bool => PrePlanilla::LISTO === $f->estado() ) ) : 0;
		$recibos  = current_user_can( Capacidades::RECIBOS ) ? Recibos::delMes( $mesPago ) : null;
		$nombreMes     = wp_date( 'F', ( new DateTimeImmutable( $mes . '-15 12:00', $zona ) )->getTimestamp(), $zona );
		$nombreMesPago = wp_date( 'F', ( new DateTimeImmutable( $mesPago . '-15 12:00', $zona ) )->getTimestamp(), $zona );
		$solicitudes   = PantallaSolicitudes::pendientes();
		?>
		<div class="wrap">
			<?php Avisos::mostrar(); ?>
			<h1>Dashboard <span class="pk-h1-der">Hoy · <?php echo esc_html( wp_date( 'l j \d\e F', $ahora->getTimestamp(), $zona ) ); ?></span></h1>

			<div class="pk-kpis">
				<a class="pk-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ); ?>">
					<span>En el taller hoy</span>
					<b><?php echo (int) $hoyDatos['en_taller']; ?> de <?php echo (int) $hoyDatos['esperados']; ?></b>
					<small><?php echo esc_html( wp_date( 'H:i', $ahora->getTimestamp(), $zona ) ); ?> · <?php echo (int) $hoyDatos['salieron']; ?> ya salieron</small>
				</a>
				<a class="pk-kpi turq" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ); ?>">
					<span>Llegaron tarde</span>
					<b><?php echo (int) $hoyDatos['tarde']; ?></b>
					<small><?php echo $hoyDatos['tarde'] ? 'Pasada la tolerancia' : 'Nadie, por ahora'; ?></small>
				</a>
				<a class="pk-kpi lima" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ); ?>">
					<span>Sin marcar</span>
					<b><?php echo count( $hoyDatos['ausentes'] ); ?></b>
					<small>Pasada su hora de entrada</small>
				</a>
				<a class="pk-kpi rosa" href="<?php echo esc_url( PantallaSolicitudes::url() ); ?>">
					<span>Por decidir</span>
					<b><?php echo (int) $solicitudes; ?></b>
					<small>Solicitudes y marcas por revisar</small>
				</a>
			</div>

			<div class="pk-kpis">
				<?php if ( $pago ) : ?>
					<a class="pk-kpi dest" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PantallaPagos::SLUG . '&mes=' . $mesPago ) ); ?>">
						<span>Por pagar de <?php echo esc_html( $nombreMesPago ); ?></span>
						<b><?php echo esc_html( Sueldo::soles( $pago['total']->montos['total'] ) ); ?></b>
						<small><?php echo (int) $listos; ?> de <?php echo count( $pago['filas'] ); ?> listos para pagar</small>
					</a>
				<?php endif; ?>
				<div class="pk-kpi turq">
					<span>Horas de <?php echo esc_html( $nombreMes ); ?></span>
					<b><?php echo esc_html( PrePlanilla::horasDecimal( $trabajo ) ); ?> h</b>
					<small>Hasta ayer · <?php echo count( $personas ); ?> personas</small>
				</div>
				<div class="pk-kpi lima">
					<span>Horas extra de <?php echo esc_html( $nombreMes ); ?></span>
					<b><?php echo esc_html( PrePlanilla::horasDecimal( $extra ) ); ?> h</b>
					<small>Incluye sábados, domingos y feriados</small>
				</div>
				<?php if ( null !== $recibos ) : ?>
					<?php
					$conRecibo  = count( array_filter( $personas, static fn( array $t ): bool => isset( $recibos[ (int) $t['id'] ] ) ) );
					$observados = count( array_filter( $recibos, static fn( array $r ): bool => 'observado' === $r['estado'] ) );
					?>
					<a class="pk-kpi rosa" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PantallaRecibos::SLUG . '&mes=' . $mesPago ) ); ?>">
						<span>Recibos de <?php echo esc_html( $nombreMesPago ); ?></span>
						<b><?php echo (int) $conRecibo; ?> de <?php echo count( $personas ); ?></b>
						<small>Faltan <?php echo count( $personas ) - $conRecibo; ?><?php echo $observados ? ' y ' . (int) $observados . ( 1 === $observados ? ' está observado' : ' están observados' ) : ''; ?></small>
					</a>
				<?php endif; ?>
			</div>

			<div class="pk-dos">
				<div class="pk-caja">
					<h2>Horas por día · <?php echo esc_html( $nombreMes ); ?></h2>
					<div class="pk-leyenda-graf"><i class="no"></i> Normales <i class="ex"></i> Extra</div>
					<?php self::grafico( $porDia ); ?>
				</div>
				<div class="pk-caja">
					<h2>Requiere tu atención</h2>
					<?php self::alertas( $hoyDatos['ausentes'], $alcance, $hoy, $zona, $recibos, $personas, $mesPago ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Cifras de hoy. Quien sigue en la jornada de ayer (pasó la medianoche) cuenta en el taller.
	 *
	 * @param array<int, array<string, mixed>> $personas
	 * @return array{en_taller: int, esperados: int, tarde: int, salieron: int, ausentes: array<int, array<string, mixed>>}
	 */
	public static function cifrasHoy( array $personas, string $hoy, DateTimeImmutable $ahora, DateTimeZone $zona ): array {
		$ids       = array_map( static fn( array $t ): int => (int) $t['id'], $personas );
		$ausencias = Ausencias::delRango( $ids, $hoy, $hoy );
		$datos     = array( 'en_taller' => 0, 'esperados' => 0, 'tarde' => 0, 'salieron' => 0, 'ausentes' => array() );

		foreach ( $personas as $t ) {
			$id  = (int) $t['id'];
			$dia = Jornadas::diaActual( $id, $ahora );
			$r   = ResumenDia::calcular( Marcas::delDia( $id, $dia ), Horarios::deTrabajador( $t, $dia ), $dia, $zona, $ahora, $dia === $hoy ? ( $ausencias[ $id ][ $hoy ] ?? null ) : null );

			if ( in_array( $r->situacion, array( ResumenDia::LIBRE, ResumenDia::JUSTIFICADO ), true ) && Estado::FUERA === $r->estado ) {
				continue;
			}

			++$datos['esperados'];
			$datos['en_taller'] += Estado::FUERA !== $r->estado ? 1 : 0;
			$datos['tarde']     += $r->minutosTardanza > 0 ? 1 : 0;
			$datos['salieron']  += ResumenDia::SALIO === $r->situacion ? 1 : 0;

			if ( ResumenDia::AUSENTE === $r->situacion ) {
				$h                   = Horarios::deTrabajador( $t, $dia );
				$datos['ausentes'][] = $t + array( 'desde' => $h->entrada ? substr( (string) $h->entrada, 0, 5 ) : '' );
			}
		}

		return $datos;
	}

	/**
	 * Horas trabajadas y extra de todo el equipo, por día del mes (hasta ayer).
	 *
	 * @param array<int, array<string, mixed>> $personas
	 * @return array<string, array{trabajado: int, extra: int}>
	 */
	private static function horasPorDia( array $personas, string $mes ): array {
		$desde = $mes . '-01';
		$hasta = min( ( new DateTimeImmutable( $desde ) )->format( 'Y-m-t' ), Jornadas::ayer() );
		$dias  = array();

		if ( ! $personas || $hasta < $desde ) {
			return $dias;
		}

		for ( $d = new DateTimeImmutable( $desde ); $d->format( 'Y-m-d' ) <= $hasta; $d = $d->modify( '+1 day' ) ) {
			$dias[ $d->format( 'Y-m-d' ) ] = array( 'trabajado' => 0, 'extra' => 0 );
		}

		foreach ( Jornadas::delRango( $personas, $desde, $hasta ) as $porDia ) {
			foreach ( $porDia as $dia => $j ) {
				if ( isset( $dias[ $dia ] ) ) {
					$dias[ $dia ]['trabajado'] += $j->minutosTrabajados;
					$dias[ $dia ]['extra']     += $j->minutosAdicionales();
				}
			}
		}

		return $dias;
	}

	/** Barras apiladas (normales y extra), como el gráfico de la maqueta. */
	private static function grafico( array $porDia ): void {
		if ( ! $porDia ) {
			echo '<p class="description">Todavía no hay días cerrados este mes.</p>';
			return;
		}

		$max = max( 60, max( array_column( $porDia, 'trabajado' ) ) );
		echo '<div class="pk-barras" role="img" aria-label="Horas trabajadas por día del mes">';

		foreach ( $porDia as $dia => $v ) {
			$extra   = min( $v['extra'], $v['trabajado'] );
			$normal  = $v['trabajado'] - $extra;
			$titulo  = wp_date( 'D j', strtotime( $dia . ' 12:00:00' ) ) . ': ' . PrePlanilla::horasDecimal( $v['trabajado'] ) . ' h' . ( $extra ? ' (' . PrePlanilla::horasDecimal( $extra ) . ' h extra)' : '' );
			printf(
				'<div class="col" title="%s"><div class="pila"><i class="ex" style="height:%s%%"></i><i class="no" style="height:%s%%"></i></div><span>%d</span></div>',
				esc_attr( $titulo ),
				esc_attr( (string) round( 100 * $extra / $max, 1 ) ),
				esc_attr( (string) round( 100 * $normal / $max, 1 ) ),
				(int) substr( $dia, 8, 2 )
			);
		}

		echo '</div>';
	}

	/**
	 * "Requiere tu atención": cada cosa con su botón.
	 *
	 * @param array<int, array<string, mixed>>      $ausentes
	 * @param array<int, array<string, mixed>>|null $recibos
	 * @param array<int, array<string, mixed>>      $personas
	 */
	private static function alertas( array $ausentes, ?array $alcance, string $hoy, DateTimeZone $zona, ?array $recibos, array $personas, string $mesPago ): void {
		$items = self::itemsAlerta( $ausentes, $alcance, $hoy, $recibos, $personas, $mesPago );

		if ( ! $items ) {
			echo '<p class="pk-alertas vacio">✔ Todo en orden.</p>';
			return;
		}

		echo '<ul class="pk-alertas">';

		foreach ( $items as [ $clase, $icono, $titulo, $detalle, $boton, $url ] ) {
			printf(
				'<li class="%s"><span class="dashicons dashicons-%s" aria-hidden="true"></span><div><b>%s</b><span>%s</span></div><a class="button" href="%s">%s</a></li>',
				esc_attr( $clase ),
				esc_attr( $icono ),
				esc_html( $titulo ),
				esc_html( $detalle ),
				esc_url( $url ),
				esc_html( $boton )
			);
		}

		echo '</ul>';
	}

	/**
	 * "Requiere tu atención": [tipo, ícono, título, detalle, botón, url]. En el
	 * celular ($movil) lo que tiene pantalla propia abre esa pantalla.
	 *
	 * @param array<int, array<string, mixed>> $ausentes
	 * @param array<int, array<string, mixed>> $personas
	 * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
	 */
	public static function itemsAlerta( array $ausentes, ?array $alcance, string $hoy, ?array $recibos, array $personas, string $mesPago, bool $movil = false ): array {
		$items = array();
		$dia   = static fn( int $id, string $d ): string => $movil ? \Pekokis\Asistencia\WordPress\GerenciaMovil::url( 'persona', array( 't' => $id, 'mes' => substr( $d, 0, 7 ), 'dia' => $d ) ) : PantallaDia::url( $id, $d );

		foreach ( array_slice( $ausentes, 0, self::MAX_ALERTAS_POR_TIPO ) as $t ) {
			$items[] = array( 'error', 'warning', Trabajadores::nombreCompleto( $t ) . ' no marcó entrada', ( '' !== $t['desde'] ? 'Desde las ' . $t['desde'] . ' · ' : '' ) . 'hoy', 'Ver', $dia( (int) $t['id'], $hoy ) );
		}

		if ( count( $ausentes ) > self::MAX_ALERTAS_POR_TIPO ) {
			$items[] = array( 'error', 'warning', 'Y ' . ( count( $ausentes ) - self::MAX_ALERTAS_POR_TIPO ) . ' personas más sin marcar', 'Todas en Asistencia de hoy', 'Ver', $movil ? \Pekokis\Asistencia\WordPress\GerenciaMovil::url( 'hoy', array( 'f' => 'ausente' ) ) : admin_url( 'admin.php?page=' . Menu::SLUG ) );
		}

		// Desde la 4.ª tardanza la persona tiene que conversar con gerencia.
		$conteosT = \Pekokis\Asistencia\WordPress\Tardanzas::conteos( $personas );
		foreach ( $personas as $t ) {
			$n = $conteosT[ (int) $t['id'] ] ?? 0;
			if ( $n >= \Pekokis\Asistencia\WordPress\Tardanzas::CONVERSAR ) {
				$items[] = array( 'error', 'clock', Trabajadores::nombreCompleto( $t ) . ' lleva ' . $n . ' tardanzas', 'Debe conversar con gerencia; luego reinicia el conteo', 'Ver', $movil ? \Pekokis\Asistencia\WordPress\GerenciaMovil::url( 'persona', array( 't' => (int) $t['id'] ) ) : PantallaFicha::url( (int) $t['id'], 'tardanzas' ) );
			}
		}

		$pendientesDias = array_values( array_filter( Seguimiento::pendientes( $alcance, $hoy ), static fn( array $p ): bool => Seguimiento::INCOMPLETO === $p['tipo'] ) );

		foreach ( array_slice( $pendientesDias, 0, self::MAX_ALERTAS_POR_TIPO ) as $p ) {
			$items[] = array( 'aviso', 'warning', Trabajadores::nombreCompleto( $p['trabajador'] ) . ' sin salida', 'Día incompleto · ' . wp_date( 'j \d\e F', strtotime( $p['dia'] . ' 12:00:00' ) ), 'Corregir', $dia( (int) $p['trabajador']['id'], $p['dia'] ) );
		}

		if ( current_user_can( Capacidades::APROBAR ) ) {
			$correcciones = Correcciones::pendientes( $alcance );

			if ( $correcciones ) {
				$dias    = max( 0, (int) floor( ( time() - strtotime( $correcciones[0]['creado_en'] . ' UTC' ) ) / DAY_IN_SECONDS ) );
				$items[] = array( 'aviso', 'email-alt', count( $correcciones ) . ( 1 === count( $correcciones ) ? ' solicitud de corrección' : ' solicitudes de corrección' ), 'La más antigua es ' . ( $dias ? 'de hace ' . $dias . ( 1 === $dias ? ' día' : ' días' ) : 'de hoy' ), 'Revisar', $movil ? \Pekokis\Asistencia\WordPress\GerenciaMovil::url( 'solicitudes' ) : PantallaSolicitudes::url() );
			}

			$revisar = Revisiones::pendientes( $alcance );

			if ( $revisar ) {
				$items[] = array( 'aviso', 'flag', count( $revisar ) . ( 1 === count( $revisar ) ? ' marca por revisar' : ' marcas por revisar' ), 'Emergencias, salidas fuera del taller o jornadas muy largas', 'Revisar', $movil ? \Pekokis\Asistencia\WordPress\GerenciaMovil::url( 'solicitudes' ) : PantallaSolicitudes::url() . '#revisar' );
			}
		}

		if ( current_user_can( Capacidades::GESTIONAR_PERSONAL ) && Celulares::contarPendientes() ) {
			$n       = Celulares::contarPendientes();
			$items[] = array( 'info', 'smartphone', $n . ( 1 === $n ? ' celular por aprobar' : ' celulares por aprobar' ), 'Registros nuevos, cambios o liberaciones', 'Ver', admin_url( 'admin.php?page=' . PantallaCelulares::SLUG ) );
		}

		if ( null !== $recibos ) {
			$faltan = array_filter( $personas, static fn( array $t ): bool => ! isset( $recibos[ (int) $t['id'] ] ) );

			if ( $faltan ) {
				$nombres = implode( ', ', array_map( static fn( array $t ): string => (string) $t['nombres'], array_slice( array_values( $faltan ), 0, 3 ) ) ) . ( count( $faltan ) > 3 ? '…' : '' );
				$items[] = array( 'info', 'media-document', count( $faltan ) . ( 1 === count( $faltan ) ? ' recibo por honorarios pendiente' : ' recibos por honorarios pendientes' ), $nombres, 'Ver', $movil ? \Pekokis\Asistencia\WordPress\GerenciaMovil::url( 'recibos', array( 'mes' => $mesPago ) ) : admin_url( 'admin.php?page=' . PantallaRecibos::SLUG . '&mes=' . $mesPago ) );
			}
		}

		if ( current_user_can( Capacidades::AJUSTES ) ) {
			foreach ( Pantallas::sinConexion() as $p ) {
				$items[] = array( 'error', 'desktop', 'La pantalla "' . $p['nombre'] . '" no se conecta', 'Sin ella solo se marca con el WiFi del taller', 'Ver', admin_url( 'admin.php?page=' . PantallaAjustes::SLUG ) );
			}
		}

		return $items;
	}
}
