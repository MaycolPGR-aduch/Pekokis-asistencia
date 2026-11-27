<?php

namespace Pekokis\Asistencia\WordPress\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\CalculoJornada;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use Pekokis\Asistencia\WordPress\Ajustes;
use Pekokis\Asistencia\WordPress\Auditoria;
use Pekokis\Asistencia\WordPress\Capacidades;
use Pekokis\Asistencia\WordPress\Celulares;
use Pekokis\Asistencia\WordPress\Exportes;
use Pekokis\Asistencia\WordPress\Historial;
use Pekokis\Asistencia\WordPress\Informes;
use Pekokis\Asistencia\WordPress\Jornadas;
use Pekokis\Asistencia\WordPress\Recibos;
use Pekokis\Asistencia\WordPress\Seguimiento;
use Pekokis\Asistencia\WordPress\Sueldos;
use Pekokis\Asistencia\WordPress\Tablas;
use Pekokis\Asistencia\WordPress\Trabajadores;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha del trabajador (maquetas G3 y G3B): quién es arriba, dos accesos
 * directos y pestañas Resumen, Asistencia, Pagos y recibos, Celular y Cambios.
 * Lo de dinero solo lo ve quien ve los pagos.
 */
final class PantallaFicha {
	public static function url( int $trabajadorId, string $pestana = '', array $args = array() ): string {
		return add_query_arg( array( 'page' => PantallaTrabajadores::SLUG, 'vista' => 'ficha', 'id' => $trabajadorId ) + ( '' !== $pestana ? array( 'pestana' => $pestana ) : array() ) + $args, admin_url( 'admin.php' ) );
	}

	/** @param array<string, mixed> $t */
	public static function pintar( array $t ): void {
		$id      = (int) $t['id'];
		$pestana = isset( $_GET['pestana'] ) ? sanitize_key( $_GET['pestana'] ) : 'resumen'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs    = array( 'resumen' => 'Resumen', 'asistencia' => 'Asistencia' );

		if ( current_user_can( Capacidades::VER_SUELDOS ) || current_user_can( Capacidades::RECIBOS ) ) {
			$tabs['pagos'] = 'Pagos y recibos';
		}

		$tabs['tardanzas'] = 'Tardanzas';
		$tabs['celular']   = 'Celular';

		if ( current_user_can( Capacidades::AUDITORIA ) ) {
			$tabs['cambios'] = 'Cambios';
		}

		$pestana = isset( $tabs[ $pestana ] ) ? $pestana : 'resumen';
		$activo  = Trabajadores::ACTIVO === $t['estado'];
		$celular = self::celular( $id );
		$hoy     = Ajustes::diaLaboral()->de( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		$porVer  = count( array_filter( Seguimiento::pendientes( array( $id ), $hoy ), static fn( array $p ): bool => (int) $p['trabajador']['id'] === $id ) );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PantallaTrabajadores::SLUG ) ); ?>">← Perfiles</a></p>
		<h1>Ficha de <?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?>
			<?php if ( $activo ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( add_query_arg( array( 'page' => PantallaTrabajadores::SLUG, 'vista' => 'editar', 'id' => $id ), admin_url( 'admin.php' ) ) ); ?>">Editar datos</a>
			<?php endif; ?>
		</h1>

		<div class="pk-ficha-cab">
			<span class="pk-av xl"><?php echo esc_html( self::iniciales( $t ) ); ?></span>
			<div class="quien">
				<b><?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?></b>
				<span><?php echo esc_html( trim( implode( ' · ', array_filter( array( (string) $t['cargo'], (string) $t['area'] ) ) ) . ( $t['creado_en'] ? ' · en el sistema desde el ' . Ajustes::local( (string) $t['creado_en'], 'j \d\e F \d\e Y' ) : '' ), ' ·' ) ); ?></span>
				<p class="pk-fila junta pk-mt-s">
					<span class="pk-chip <?php echo $activo ? 'ok' : 'gris'; ?>"><?php echo esc_html( $activo ? 'Activo' : ucfirst( (string) $t['estado'] ) ); ?></span>
					<span class="pk-chip <?php echo $celular ? 'ok' : 'aviso'; ?>"><?php echo $celular ? 'Celular registrado' : 'Sin celular'; ?></span>
					<?php $conteoT = \Pekokis\Asistencia\WordPress\Tardanzas::conteo( $id ); ?>
					<?php if ( $conteoT ) : ?>
						<a class="pk-chip <?php echo $conteoT >= \Pekokis\Asistencia\WordPress\Tardanzas::CONVERSAR ? 'error' : 'aviso'; ?>" href="<?php echo esc_url( self::url( $id, 'tardanzas' ) ); ?>"><?php echo esc_html( $conteoT . ( 1 === $conteoT ? ' tardanza' : ' tardanzas' ) ); ?></a>
					<?php endif; ?>
					<span class="pk-chip <?php echo $porVer ? 'aviso' : 'gris'; ?>"><?php echo $porVer ? esc_html( $porVer . ( 1 === $porVer ? ' día por revisar' : ' días por revisar' ) ) : 'Sin incidencias pendientes'; ?></span>
				</p>
			</div>
			<div class="acciones">
				<a class="button button-primary" href="<?php echo esc_url( self::url( $id, 'asistencia' ) ); ?>">Ver su asistencia</a>
				<?php if ( current_user_can( Capacidades::VER_SUELDOS ) ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( $id, 'pagos' ) ); ?>">Ver cálculo de pago</a>
				<?php endif; ?>
			</div>
		</div>

		<nav class="pk-pestanas" aria-label="Secciones de la ficha">
			<?php foreach ( $tabs as $clave => $texto ) : ?>
				<a href="<?php echo esc_url( self::url( $id, $clave ) ); ?>" class="<?php echo $clave === $pestana ? 'activa' : ''; ?>" <?php echo $clave === $pestana ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $texto ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		match ( $pestana ) {
			'asistencia' => self::asistencia( $t ),
			'pagos'      => self::pagos( $t ),
			'celular'    => self::pestanaCelular( $t, $celular ),
			'tardanzas'  => PantallaTardanzas::pestana( $t ),
			'cambios'    => self::cambios( $t, 40 ),
			default      => self::resumen( $t, $celular ),
		};
	}

	/** @param array<string, mixed> $t */
	private static function resumen( array $t, ?array $celular ): void {
		$id      = (int) $t['id'];
		$zona    = Ajustes::zona();
		$mes     = wp_date( 'Y-m', null, $zona );
		$dias    = Informes::diasDe( $t, $mes );
		$hechos  = count( array_filter( $dias, static fn( CalculoJornada $j ): bool => CalculoJornada::COMPLETO === $j->estado && $j->trabajoAlgo() ) );
		$abiertos = count( array_filter( $dias, static fn( CalculoJornada $j ): bool => CalculoJornada::INCOMPLETO === $j->estado ) );
		$trabajo = array_sum( array_map( static fn( CalculoJornada $j ): int => $j->minutosTrabajados, $dias ) );
		$extra   = array_sum( array_map( static fn( CalculoJornada $j ): int => $j->minutosAdicionales(), $dias ) );
		$horario = Trabajadores::horarios()[ (int) $t['horario_id'] ] ?? '—';
		$verDni  = isset( $_GET['ver_dni'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'pka_ver_dni_' . $id );

		if ( $verDni ) {
			Auditoria::registrar( 'trabajador.ver_dni', 'trabajador', $id, null, null, 'Desde la ficha' );
		}
		?>
		<div class="pk-tres">
			<div class="pk-caja">
				<h2>Datos personales</h2>
				<dl class="pk-datos">
					<div><dt>Celular o WhatsApp</dt><dd><?php echo esc_html( '' !== (string) $t['telefono'] ? (string) $t['telefono'] : '—' ); ?></dd></div>
					<div><dt>DNI</dt><dd><?php echo esc_html( $verDni ? (string) $t['dni'] : PantallaCelulares::dniOculto( (string) $t['dni'] ) ); ?>
						<?php if ( ! $verDni ) : ?>
							<a href="<?php echo esc_url( wp_nonce_url( self::url( $id, 'resumen', array( 'ver_dni' => 1 ) ), 'pka_ver_dni_' . $id ) ); ?>">Mostrar</a>
						<?php endif; ?></dd></div>
					<div><dt>Cumpleaños</dt><dd><?php echo '' !== (string) $t['cumpleanos'] ? esc_html( wp_date( 'j \d\e F', strtotime( '2024-' . $t['cumpleanos'] . ' 12:00:00' ) ) ) : '<span class="description">Sin registrar</span>'; ?></dd></div>
					<div><dt>Horario</dt><dd><?php echo esc_html( $horario ); ?></dd></div>
					<div><dt>Ingreso</dt><dd><?php echo esc_html( $t['fecha_ingreso'] ? wp_date( 'j \d\e F \d\e Y', strtotime( $t['fecha_ingreso'] . ' 12:00:00' ) ) : '—' ); ?></dd></div>
				</dl>
				<p class="description pk-mt-s">Mostrar el DNI queda registrado en la auditoría.</p>
			</div>

			<div class="pk-caja">
				<h2>Este mes · <?php echo esc_html( wp_date( 'F', null, $zona ) ); ?></h2>
				<div class="pk-kpis dos pk-m0">
					<div class="pk-kpi turq"><b><?php echo (int) $hechos; ?></b><small>días completos</small></div>
					<div class="pk-kpi lima"><b><?php echo esc_html( PrePlanilla::horasDecimal( $trabajo ) ); ?> h</b><small>trabajadas</small></div>
					<div class="pk-kpi rosa"><b><?php echo esc_html( PrePlanilla::horasDecimal( $extra ) ); ?> h</b><small>extra</small></div>
					<div class="pk-kpi <?php echo $abiertos ? 'rosa' : ''; ?>"><b><?php echo (int) $abiertos; ?></b><small><?php echo 1 === $abiertos ? 'día incompleto' : 'días incompletos'; ?></small></div>
				</div>
				<p class="description pk-mt-s">Hasta ayer. <a href="<?php echo esc_url( self::url( $id, 'asistencia' ) ); ?>">Ver día por día</a></p>
			</div>

			<div class="pk-caja">
				<h2>Celular para marcar</h2>
				<?php self::datosCelular( $t, $celular ); ?>
			</div>

			<?php if ( current_user_can( Capacidades::VER_SUELDOS ) ) : ?>
				<?php $s = Sueldos::vigente( $id ); ?>
				<div class="pk-caja">
					<h2>Pago <span class="pk-chip solo">Solo gerencia</span></h2>
					<?php if ( ! $s ) : ?>
						<p>Todavía no tiene honorario ni tarifas: no se puede calcular su pago.</p>
					<?php else : ?>
						<dl class="pk-datos">
							<div><dt>Honorario mensual</dt><dd><?php echo esc_html( Sueldo::soles( $s->honorarioCentimos ) ); ?></dd></div>
							<div><dt>Hora extra</dt><dd><?php echo esc_html( Sueldo::soles( $s->tarifaExtra ) ); ?></dd></div>
							<div><dt>Sábado · domingo</dt><dd><?php echo esc_html( Sueldo::soles( $s->tarifaSabado ) . ' · ' . Sueldo::soles( $s->tarifaDomingo ) ); ?></dd></div>
							<div><dt>Feriado</dt><dd><?php echo esc_html( Sueldo::soles( $s->tarifaFeriado ) ); ?></dd></div>
						</dl>
					<?php endif; ?>
					<p class="pk-mt-s"><a class="button" href="<?php echo esc_url( self::url( $id, 'pagos' ) ); ?>">Ver cálculo</a></p>
				</div>
			<?php endif; ?>

			<?php if ( current_user_can( Capacidades::RECIBOS ) ) : ?>
				<div class="pk-caja">
					<h2>Recibos por honorarios</h2>
					<?php self::recibosCortos( $id, 3 ); ?>
				</div>
			<?php endif; ?>

			<?php if ( current_user_can( Capacidades::AUDITORIA ) ) : ?>
				<div class="pk-caja">
					<h2>Últimos cambios</h2>
					<?php self::cambios( $t, 4, true ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Su asistencia día por día (G3B): el mismo informe del panel, con selector de mes. */
	private static function asistencia( array $t ): void {
		$meses = array_slice( Exportes::meses(), 0, 6 );
		$mes   = isset( $_GET['mes'] ) && in_array( $_GET['mes'], Exportes::meses(), true ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : $meses[0]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<nav class="pk-pestanas" aria-label="Mes">
			<?php foreach ( $meses as $m ) : ?>
				<a href="<?php echo esc_url( self::url( (int) $t['id'], 'asistencia', array( 'mes' => $m ) ) ); ?>" class="<?php echo $m === $mes ? 'activa' : ''; ?>"><?php echo esc_html( PantallaResumen::nombreMes( $m ) ); ?></a>
			<?php endforeach; ?>
		</nav>
		<p class="description">Toca un día para ver sus marcas y corregirlo. <a href="<?php echo esc_url( PantallaInforme::url( (int) $t['id'], $mes ) ); ?>">Abrir en Registros por persona (Excel e imprimir)</a></p>
		<?php
		PantallaInforme::cuerpo( $t, $mes, false );
	}

	/** Pagos y recibos: el cálculo del mes en palabras y su historial de recibos. */
	private static function pagos( array $t ): void {
		$id = (int) $t['id'];

		if ( current_user_can( Capacidades::VER_SUELDOS ) ) {
			$meses = Exportes::meses();
			$mes   = isset( $_GET['mes'] ) && in_array( $_GET['mes'], $meses, true ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : ( $meses[1] ?? $meses[0] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$fila  = Jornadas::prePlanilla( array( $t ), $mes )[ $id ] ?? new PrePlanilla();
			?>
			<nav class="pk-pestanas" aria-label="Mes">
				<?php foreach ( array_slice( $meses, 0, 6 ) as $m ) : ?>
					<a href="<?php echo esc_url( self::url( $id, 'pagos', array( 'mes' => $m ) ) ); ?>" class="<?php echo $m === $mes ? 'activa' : ''; ?>"><?php echo esc_html( PantallaResumen::nombreMes( $m ) ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="pk-dos">
				<div class="pk-caja">
					<h2>Cómo se calcula su pago de <?php echo esc_html( PantallaResumen::nombreMes( $mes ) ); ?></h2>
					<ul class="pk-sin-lista">
						<?php foreach ( $fila->explicacion() as $linea ) : ?>
							<li><?php echo esc_html( $linea ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="pk-fila pk-mt-s">
						<a class="button" href="<?php echo esc_url( PantallaPagos::url( $mes ) ); ?>">Calculadora de pagos</a>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => PantallaTrabajadores::SLUG, 'vista' => 'editar', 'id' => $id ), admin_url( 'admin.php' ) ) . '#remuneracion' ); ?>">Cambiar honorario y tarifas</a>
						<?php if ( current_user_can( Capacidades::JUSTIFICAR ) ) : ?>
							<a class="button" href="<?php echo esc_url( PantallaHoras::url( $mes, $id ) ); ?>">Contabilidad de horas</a>
						<?php endif; ?>
					</p>
				</div>
				<div class="pk-caja">
					<?php PantallaDescuentos::caja( $t, $mes ); ?>
				</div>
			</div>
			<div class="pk-dos pk-mt-s">
				<div class="pk-caja">
					<?php PantallaDescuentos::historialPagos( $t ); ?>
				</div>
				<?php if ( current_user_can( Capacidades::RECIBOS ) ) : ?>
					<div class="pk-caja">
						<h2>Recibos por honorarios</h2>
						<?php self::recibosCortos( $id, 12 ); ?>
					</div>
				<?php endif; ?>
			</div>
			<?php
			return;
		}
		?>
		<div class="pk-caja pk-max-s">
			<h2>Recibos por honorarios</h2>
			<?php self::recibosCortos( $id, 12 ); ?>
		</div>
		<?php
	}

	private static function pestanaCelular( array $t, ?array $celular ): void {
		$pendiente = Celulares::pendienteDe( (int) $t['id'] );
		?>
		<div class="pk-dos">
			<div class="pk-caja">
				<h2>Celular para marcar</h2>
				<?php self::datosCelular( $t, $celular ); ?>
			</div>
			<div class="pk-caja">
				<h2>Acceso</h2>
				<dl class="pk-datos">
					<div><dt>PIN de ingreso</dt><dd><?php echo '' !== (string) $t['pin_hash'] ? 'Creado el ' . esc_html( Ajustes::local( (string) $t['pin_creado_en'], 'j \d\e F' ) ) : 'Sin PIN'; ?></dd></div>
					<div><dt>Solicitud pendiente</dt><dd><?php echo $pendiente ? esc_html( Celulares::TIPOS[ $pendiente['tipo'] ] ?? $pendiente['tipo'] ) . ' · <a href="' . esc_url( admin_url( 'admin.php?page=' . PantallaCelulares::SLUG ) ) . '">Resolver</a>' : 'Ninguna'; ?></dd></div>
				</dl>
				<?php if ( Trabajadores::ACTIVO === $t['estado'] ) : ?>
					<p class="description pk-mt-s">Si no puede registrarse solo en el taller (por ejemplo, porque no tiene su cumpleaños registrado), genera un enlace de activación y entrégaselo.</p>
					<a class="button" href="<?php echo esc_url( Menu::urlAccion( 'pka_enlace_activacion', array( 'id' => (int) $t['id'], 'volver' => 'ficha' ) ) ); ?>" <?php echo $celular ? 'onclick="return confirm(\'Ya tiene un celular registrado. Un enlace nuevo sirve para cambiar de celular: el anterior dejará de funcionar cuando active el nuevo. ¿Continuar?\')"' : ''; ?>>Enlace de activación</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Historial de cambios de la persona (G5 filtrado). */
	private static function cambios( array $t, int $cuantos, bool $corto = false ): void {
		$filas = Historial::describir( Historial::buscar( array( 'trabajador' => (int) $t['id'] ), 1, $cuantos )[0] );

		if ( ! $filas ) {
			echo '<p class="description">Sin cambios registrados.</p>';
			return;
		}

		if ( $corto ) {
			echo '<dl class="pk-datos">';
			foreach ( $filas as $f ) {
				printf( '<div><dt>%s<br><small>%s</small></dt><dd class="pk-normal">%s</dd></div>', esc_html( substr( $f['fecha'], 0, 10 ) ), esc_html( $f['quien'] ), esc_html( $f['accion'] ) );
			}
			echo '</dl><p class="pk-mt-s"><a href="' . esc_url( self::url( (int) $t['id'], 'cambios' ) ) . '">Ver todos</a></p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead><tr><th>Cuándo</th><th>Quién</th><th>Qué</th><th>Cambios</th><th>Motivo</th></tr></thead>
			<tbody>
			<?php foreach ( $filas as $f ) : ?>
				<tr>
					<td><?php echo esc_html( $f['fecha'] ); ?></td>
					<td><?php echo esc_html( $f['quien'] ); ?></td>
					<td><?php echo esc_html( $f['accion'] ); ?><?php echo '' !== $f['sobre'] ? '<br><span class="description">' . esc_html( $f['sobre'] ) . '</span>' : ''; ?></td>
					<td><?php echo wp_kses( implode( '<br>', $f['cambios'] ), array( 'br' => array(), 'del' => array(), 'ins' => array(), 'strong' => array() ) ); ?></td>
					<td><?php echo esc_html( $f['motivo'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><a href="<?php echo esc_url( PantallaHistorial::url( array( 'trabajador' => (int) $t['id'] ) ) ); ?>">Abrir en Auditoría (filtros y CSV)</a></p>
		<?php
	}

	private static function recibosCortos( int $id, int $cuantos ): void {
		$filas = array_slice( array_values( array_filter( Recibos::historial( $id ), static fn( array $r ): bool => ValidacionRecibo::ANULADO !== $r['estado'] ) ), 0, $cuantos );

		if ( ! $filas ) {
			echo '<p class="description">Todavía no envió ningún recibo.</p>';
			return;
		}

		$chip = array( ValidacionRecibo::RECIBIDO => 'ok', ValidacionRecibo::OBSERVADO => 'error', ValidacionRecibo::ENVIADO => 'aviso' );
		echo '<dl class="pk-datos">';

		foreach ( $filas as $r ) {
			printf(
				'<div><dt>%s<br><small>%s · %s</small></dt><dd><span class="pk-chip %s">%s</span></dd></div>',
				esc_html( PantallaResumen::nombreMes( $r['mes'] ) ),
				esc_html( $r['numero'] ),
				esc_html( Sueldo::soles( (int) $r['monto_centimos'] ) ),
				esc_attr( $chip[ $r['estado'] ] ?? 'gris' ),
				esc_html( ValidacionRecibo::ENVIADO === $r['estado'] ? 'Por revisar' : ( ValidacionRecibo::ESTADOS[ $r['estado'] ] ?? $r['estado'] ) )
			);
		}

		echo '</dl><p class="pk-mt-s"><a href="' . esc_url( PantallaRecibos::url( '', array( 'trabajador' => $id ) ) ) . '">Ver todos sus recibos</a></p>';
	}

	private static function datosCelular( array $t, ?array $celular ): void {
		if ( ! $celular ) {
			echo '<p>No tiene un celular registrado. Puede registrarse solo en el taller ("Es mi primera vez" o "Cambié de celular"), o con un enlace de activación.</p>';
			return;
		}
		?>
		<dl class="pk-datos">
			<div><dt>Equipo</dt><dd><?php echo esc_html( PantallaCelulares::celularCorto( (string) $celular['user_agent'] ) ); ?></dd></div>
			<div><dt>Registrado</dt><dd><?php echo esc_html( Ajustes::local( (string) $celular['creado_en'], 'j \d\e F' ) ); ?></dd></div>
			<div><dt>Llave</dt><dd><span class="pk-chip ok">Activa</span></dd></div>
			<div><dt>Último uso</dt><dd><?php echo esc_html( $celular['ultima_huella'] ? Ajustes::local( (string) $celular['ultima_huella'], 'd/m H:i' ) : '—' ); ?></dd></div>
		</dl>
		<p class="pk-mt-s"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . PantallaCelulares::SLUG ) ); ?>">Liberar celular</a></p>
		<?php
	}

	/** @return array<string, mixed>|null El celular vigente de la persona. */
	private static function celular( int $id ): ?array {
		foreach ( Celulares::vigentes() as $d ) {
			if ( (int) $d['trabajador_id'] === $id ) {
				return $d;
			}
		}

		return null;
	}

	private static function iniciales( array $t ): string {
		return mb_strtoupper( mb_substr( trim( (string) $t['nombres'] ), 0, 1 ) . mb_substr( trim( (string) $t['apellidos'] ), 0, 1 ) );
	}
}
