<?php

namespace Pekokis\Asistencia\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\PasosDelDia;
use Pekokis\Asistencia\Nucleo\PrePlanilla;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use Pekokis\Asistencia\Nucleo\Sueldo;
use Pekokis\Asistencia\Nucleo\ValidacionRecibo;
use Pekokis\Asistencia\WordPress\Admin\Avisos;
use Pekokis\Asistencia\WordPress\Admin\Menu;
use Pekokis\Asistencia\WordPress\Admin\PantallaAjustes;
use Pekokis\Asistencia\WordPress\Admin\PantallaDashboard;
use Pekokis\Asistencia\WordPress\Admin\PantallaHoy;
use Pekokis\Asistencia\WordPress\Admin\PantallaRegistros;
use Pekokis\Asistencia\WordPress\Admin\PantallaResumen;
use Pekokis\Asistencia\WordPress\Admin\PantallaSolicitudes;

defined( 'ABSPATH' ) || exit;

/**
 * Gerencia en el celular (maquetas M1–M9), en /gerencia/.
 *
 * Páginas livianas dibujadas en el servidor, con una barra abajo: Inicio, Hoy,
 * Pagos, Solicitudes y Más. Usan los mismos datos que el panel de wp-admin
 * (PantallaHoy::datos, PantallaDashboard::itemsAlerta, Informes…) y los
 * formularios van a los mismos admin-post de siempre, que vuelven aquí con el
 * campo pk_volver (Menu::volverA). Lo que no tiene versión de celular se abre
 * en wp-admin desde "Más".
 */
final class GerenciaMovil {
	public const RUTA = 'gerencia';

	private const VISTAS = array(
		'inicio'      => 'Inicio',
		'hoy'         => 'Asistencia de hoy',
		'solicitudes' => 'Solicitudes',
		'pagos'       => 'Pagos',
		'recibos'     => 'Recibos',
		'persona'     => 'Asistencia de una persona',
		'mas'         => 'Más',
	);

	private const ICONOS = array(
		'inicio'      => '<path d="M4 11l8-7 8 7v9a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"/>',
		'hoy'         => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
		'pagos'       => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h3"/>',
		'solicitudes' => '<path d="M5 4h14v12H9l-4 4z"/><path d="M9 9h6M9 12h4"/>',
		'mas'         => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
		'flecha'      => '<path d="M9 5l7 7-7 7"/>',
		'alerta'      => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>',
		'luna'        => '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
	);

	public static function registrar(): void {
		add_action( 'template_redirect', array( self::class, 'despachar' ), 0 );
		add_action( 'admin_bar_menu', array( self::class, 'enlaceBarra' ), 80 );
	}

	/** URL de una vista: /gerencia/?v=hoy&f=ausente. */
	public static function url( string $vista = '', array $args = array() ): string {
		$base = home_url( '/' . self::RUTA . '/' );
		$q    = array_filter( array( 'v' => 'inicio' === $vista ? '' : $vista ) + $args, static fn( $v ): bool => null !== $v && '' !== $v );

		return $q ? add_query_arg( $q, $base ) : $base;
	}

	/** En la barra de wp-admin, en el celular: "Versión celular". */
	public static function enlaceBarra( \WP_Admin_Bar $barra ): void {
		if ( wp_is_mobile() && current_user_can( Capacidades::VER_EQUIPO ) ) {
			$barra->add_node( array( 'id' => 'pka-movil', 'title' => 'Versión celular', 'href' => self::url() ) );
		}
	}

	private static function rutaActual(): string {
		$ruta = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '' !== $base && str_starts_with( $ruta, $base ) ) {
			$ruta = substr( $ruta, strlen( $base ) );
		}

		return trim( $ruta, '/' );
	}

	public static function despachar(): void {
		if ( self::RUTA !== self::rutaActual() ) {
			return;
		}

		nocache_headers();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}

		if ( ! current_user_can( Capacidades::VER_EQUIPO ) ) {
			wp_die( 'Esta página es para gerencia y jefes de área.', 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$vista = isset( $_GET['v'] ) ? sanitize_key( wp_unslash( $_GET['v'] ) ) : 'inicio';
		$vista = isset( self::VISTAS[ $vista ] ) ? $vista : 'inicio';

		if ( 'pagos' === $vista && ! current_user_can( Capacidades::VER_SUELDOS ) || 'recibos' === $vista && ! current_user_can( Capacidades::RECIBOS ) ) {
			$vista = 'inicio';
		}

		status_header( 200 );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		header( "Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'" );

		[ $titulo, $cuerpo ] = self::vista( $vista );

		self::documento( $vista, $titulo, $cuerpo );
		exit;
	}

	/**
	 * Título y HTML del contenido de una vista (sin el armazón). Lee sus
	 * filtros de $_GET. Público para las pruebas de punta a punta.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function vista( string $vista ): array {
		ob_start();
		$titulo = match ( $vista ) {
			'hoy'         => self::hoy(),
			'solicitudes' => self::solicitudes(),
			'pagos'       => self::pagos(),
			'recibos'     => self::recibos(),
			'persona'     => self::persona(),
			'mas'         => self::mas(),
			default       => self::inicio(),
		};

		return array( $titulo, (string) ob_get_clean() );
	}

	// ---------- armazón ----------

	private static function icono( string $nombre ): string {
		return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( self::ICONOS[ $nombre ] ?? '' ) . '</svg>';
	}

	private static function iniciales( string $nombres, string $apellidos = '' ): string {
		return mb_strtoupper( mb_substr( trim( $nombres ), 0, 1 ) . mb_substr( trim( $apellidos ), 0, 1 ) );
	}

	private static function documento( string $vista, string $titulo, string $cuerpo ): void {
		$usuario     = wp_get_current_user();
		$pendientes  = PantallaSolicitudes::pendientes();
		$enBarra     = in_array( $vista, array( 'persona', 'recibos' ), true ) ? ( 'recibos' === $vista ? 'pagos' : 'hoy' ) : $vista;
		$recargar    = in_array( $vista, array( 'inicio', 'hoy' ), true ) && ! isset( $_GET['p'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pestanas    = array( 'inicio' => 'Inicio', 'hoy' => 'Hoy' );

		if ( current_user_can( Capacidades::VER_SUELDOS ) ) {
			$pestanas['pagos'] = 'Pagos';
		}

		$pestanas['solicitudes'] = 'Solicitudes';
		$pestanas['mas']         = 'Más';
		?>
<!doctype html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="#FFF8FB">
	<meta name="robots" content="noindex, nofollow">
	<?php if ( $recargar ) : ?>
		<meta http-equiv="refresh" content="60">
	<?php endif; ?>
	<title><?php echo esc_html( $titulo ); ?> · Gerencia · Pekokis</title>
	<link rel="icon" href="<?php echo esc_url( PKA_URL . 'assets/iconos/icono-192.png' ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( PKA_URL . 'assets/iconos/apple-touch-icon.png' ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( Rutas::asset( 'tokens.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( Rutas::asset( 'componentes.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( Rutas::asset( 'gerencia.css' ) ); ?>">
</head>
<body class="gm gm-v-<?php echo esc_attr( $vista ); ?>">
	<a class="gm-saltar" href="#gm-main">Ir al contenido</a>
	<header class="gm-cab">
		<span class="gm-av-yo" aria-hidden="true"><?php echo esc_html( self::iniciales( (string) $usuario->first_name ? (string) $usuario->first_name : $usuario->display_name, (string) $usuario->last_name ) ); ?></span>
		<h1><?php echo esc_html( $titulo ); ?></h1>
	</header>
	<main class="gm-main" id="gm-main">
		<?php Avisos::mostrar(); ?>
		<?php echo $cuerpo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cada vista escapa lo suyo. ?>
	</main>
	<nav class="gm-barra" aria-label="Secciones">
		<?php foreach ( $pestanas as $clave => $texto ) : ?>
			<a href="<?php echo esc_url( self::url( $clave ) ); ?>" class="<?php echo $clave === $enBarra ? 'on' : ''; ?>" <?php echo $clave === $enBarra ? 'aria-current="page"' : ''; ?>>
				<?php echo self::icono( $clave ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php echo esc_html( $texto ); ?></span>
				<?php if ( 'solicitudes' === $clave && $pendientes ) : ?>
					<em><?php echo (int) $pendientes; ?><span class="screen-reader-text"> por decidir</span></em>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>
</body>
</html>
		<?php
	}

	/** Hoja que sube desde abajo (M4, M6). Se cierra volviendo a la lista. */
	private static function hoja( string $titulo, string $subtitulo, string $cerrar, callable $contenido ): void {
		?>
		<div class="gm-hoja-fondo">
			<a class="gm-hoja-cerrar" href="<?php echo esc_url( $cerrar ); ?>" aria-label="Cerrar"></a>
			<section class="gm-hoja" role="dialog" aria-modal="true" aria-labelledby="gm-hoja-t">
				<span class="gm-agarre" aria-hidden="true"></span>
				<h2 id="gm-hoja-t"><?php echo esc_html( $titulo ); ?></h2>
				<?php if ( '' !== $subtitulo ) : ?>
					<p class="gm-sub"><?php echo esc_html( $subtitulo ); ?></p>
				<?php endif; ?>
				<?php $contenido(); ?>
			</section>
		</div>
		<?php
	}

	private static function volver( string $url ): void {
		echo '<input type="hidden" name="pk_volver" value="' . esc_attr( $url ) . '">';
	}

	// ---------- M1 · Inicio ----------

	private static function inicio(): string {
		$ahora    = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$zona     = Ajustes::zona();
		$hoy      = Ajustes::diaLaboral()->de( $ahora );
		$alcance  = PantallaHoy::alcance();
		$personas = array_values( array_filter( Trabajadores::listar(), static fn( array $t ): bool => null === $alcance || in_array( (int) $t['id'], $alcance, true ) ) );
		$cifras   = PantallaDashboard::cifrasHoy( $personas, $hoy, $ahora, $zona );
		$meses    = Exportes::meses();
		$mesPago  = $meses[1] ?? $meses[0];
		$pago     = current_user_can( Capacidades::VER_SUELDOS ) ? Informes::mes( $mesPago, $alcance ) : null;
		$recibos  = current_user_can( Capacidades::RECIBOS ) ? Recibos::delMes( $mesPago ) : null;
		$items    = PantallaDashboard::itemsAlerta( $cifras['ausentes'], $alcance, $hoy, $recibos, $personas, $mesPago, true );
		$decidir  = PantallaSolicitudes::pendientes();
		?>
		<p class="gm-fecha"><?php echo esc_html( ucfirst( wp_date( 'l j \d\e F · H:i', $ahora->getTimestamp(), $zona ) ) ); ?></p>

		<div class="gm-kpis">
			<a class="gm-kpi" href="<?php echo esc_url( self::url( 'hoy' ) ); ?>"><span>En el taller hoy</span><b><?php echo (int) $cifras['en_taller']; ?> de <?php echo (int) $cifras['esperados']; ?></b><small><?php echo (int) $cifras['salieron']; ?> ya salieron</small></a>
			<a class="gm-kpi" href="<?php echo esc_url( self::url( 'hoy', array( 'f' => ResumenDia::TARDE ) ) ); ?>"><span>Llegaron tarde</span><b><?php echo (int) $cifras['tarde']; ?></b><small>Pasada la tolerancia</small></a>
			<a class="gm-kpi" href="<?php echo esc_url( self::url( 'hoy', array( 'f' => ResumenDia::AUSENTE ) ) ); ?>"><span>Sin marcar</span><b><?php echo count( $cifras['ausentes'] ); ?></b><small>Pasada su hora de entrada</small></a>
			<a class="gm-kpi" href="<?php echo esc_url( self::url( 'solicitudes' ) ); ?>"><span>Solicitudes</span><b><?php echo (int) $decidir; ?></b><small>Por decidir</small></a>
		</div>

		<?php if ( $pago ) : ?>
			<?php $listos = count( array_filter( $pago['filas'], static fn( PrePlanilla $f ): bool => PrePlanilla::LISTO === $f->estado() ) ); ?>
			<a class="gm-dest" href="<?php echo esc_url( self::url( 'pagos' ) ); ?>">
				<span>Por pagar de <?php echo esc_html( PantallaResumen::nombreMes( $mesPago ) ); ?></span>
				<b><?php echo esc_html( Sueldo::soles( $pago['total']->montos['total'] ) ); ?></b>
				<small><?php echo (int) $listos; ?> de <?php echo count( $pago['filas'] ); ?> listos para pagar</small>
				<?php echo self::icono( 'flecha' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
		<?php endif; ?>

		<h2 class="gm-h2">Requiere tu atención</h2>
		<?php if ( ! $items ) : ?>
			<p class="gm-vacio">✔ Todo en orden.</p>
		<?php else : ?>
			<ul class="gm-lista">
				<?php foreach ( $items as [ $clase, , $titulo, $detalle, , $url ] ) : ?>
					<li>
						<a class="gm-item <?php echo esc_attr( $clase ); ?>" href="<?php echo esc_url( $url ); ?>">
							<div><b><?php echo esc_html( $titulo ); ?></b><span><?php echo esc_html( $detalle ); ?></span></div>
							<?php echo self::icono( 'flecha' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
		return 'Inicio';
	}

	// ---------- M2 · Asistencia de hoy ----------

	private static function hoy(): string {
		[ 'dia' => $dia, 'alcance' => $alcance, 'filas' => $filas, 'cuentas' => $cuentas ] = PantallaHoy::datos();

		$zona   = Ajustes::zona();
		$total  = count( $filas );
		$filtro = isset( $_GET['f'] ) ? sanitize_key( wp_unslash( $_GET['f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filtro = isset( $cuentas[ $filtro ] ) ? $filtro : '';
		$hora   = static fn( ?DateTimeImmutable $m ): string => $m ? $m->setTimezone( $zona )->format( 'H:i' ) : '';
		$noche  = array_values( array_filter( $filas, static fn( array $f ): bool => $f['dia'] !== $dia ) );

		if ( '' !== $filtro ) {
			$filas = array_values( array_filter( $filas, static fn( array $f ): bool => $f['r']->situacion === $filtro ) );
		}
		?>
		<nav class="gm-chips" aria-label="Filtrar por situación">
			<a href="<?php echo esc_url( self::url( 'hoy' ) ); ?>" class="<?php echo '' === $filtro ? 'on' : ''; ?>">Todos <b><?php echo (int) $total; ?></b></a>
			<?php foreach ( $cuentas as $clave => $n ) : ?>
				<?php if ( $n ) : ?>
					<?php [ $texto, $tipo ] = PantallaHoy::situacion( $clave ); ?>
					<a href="<?php echo esc_url( self::url( 'hoy', array( 'f' => $clave ) ) ); ?>" class="t-<?php echo esc_attr( $tipo ); ?> <?php echo $clave === $filtro ? 'on' : ''; ?>"><?php echo esc_html( $texto ); ?> <b><?php echo (int) $n; ?></b></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>

		<?php if ( $noche ) : ?>
			<div class="gm-aviso info">
				<?php echo self::icono( 'luna' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div><b><?php echo count( $noche ) === 1 ? 'Una jornada pasó de medianoche.' : count( $noche ) . ' jornadas pasaron de medianoche.'; ?></b>
				<span><?php echo esc_html( implode( ', ', array_map( static fn( array $f ): string => Trabajadores::nombreCompleto( $f['t'] ), $noche ) ) ); ?> sigue<?php echo count( $noche ) > 1 ? 'n' : ''; ?> en la jornada de ayer: sus horas cuentan ese día.</span></div>
			</div>
		<?php endif; ?>

		<ul class="gm-lista">
			<?php if ( ! $filas ) : ?>
				<li class="gm-vacio"><?php echo '' !== $filtro ? 'Nadie en esta situación ahora.' : ( is_array( $alcance ) ? 'No tienes trabajadores a tu cargo.' : 'No hay trabajadores activos.' ); ?></li>
			<?php endif; ?>
			<?php foreach ( $filas as [ 't' => $t, 'r' => $r, 'dia' => $diaT ] ) : ?>
				<?php
				[ $texto, $tipo ] = PantallaHoy::situacion( $r->situacion );
				$detalle          = match ( true ) {
					ResumenDia::AUSENTE === $r->situacion                  => 'Sin marcar' . ( $r->desde ? ' desde las ' . $hora( $r->desde ) : '' ),
					null !== $r->primeraEntrada                            => 'Entrada ' . $hora( $r->primeraEntrada ) . ( $r->minutosTardanza ? ' (+' . $r->minutosTardanza . ' min)' : '' ) . ( ResumenDia::REFRIGERIO === $r->situacion || ResumenDia::FUERA === $r->situacion ? ' · hace ' . $r->minutosEnEstado . ' min' : '' ),
					'' !== $r->detalle                                     => $r->detalle,
					default                                                => (string) $t['area'],
				};
				?>
				<li>
					<a class="gm-persona <?php echo ResumenDia::AUSENTE === $r->situacion ? 'rojo' : ''; ?>" href="<?php echo esc_url( self::url( 'persona', array( 't' => (int) $t['id'], 'dia' => $diaT ) ) ); ?>">
						<span class="gm-av"><?php echo esc_html( self::iniciales( (string) $t['nombres'], (string) $t['apellidos'] ) ); ?></span>
						<div><b><?php echo esc_html( Trabajadores::nombreCompleto( $t ) ); ?></b><span><?php echo esc_html( $detalle ); ?><?php echo $diaT !== $dia ? ' · desde ayer' : ''; ?></span></div>
						<span class="pk-chip <?php echo esc_attr( $tipo ); ?>"><?php echo esc_html( $texto ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return 'Asistencia de hoy';
	}

	// ---------- M3 / M4 · Solicitudes ----------

	private static function solicitudes(): string {
		$alcance      = PantallaHoy::alcance();
		$zona         = Ajustes::zona();
		$aprobar      = current_user_can( Capacidades::APROBAR );
		$correcciones = $aprobar ? Correcciones::pendientes( $alcance ) : array();
		$revisiones   = $aprobar ? Revisiones::pendientes( $alcance ) : array();
		$abrir        = isset( $_GET['abrir'] ) ? sanitize_text_field( wp_unslash( $_GET['abrir'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lista        = self::url( 'solicitudes' );
		$momento      = static fn( string $utc ): DateTimeImmutable => ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $zona );

		if ( ! $aprobar ) {
			echo '<p class="gm-vacio">Las solicitudes las decide quien tiene permiso para aprobar.</p>';
			return 'Solicitudes';
		}
		?>
		<p class="gm-sub">Lo que piden los trabajadores. Aprobar agrega o confirma la marca y queda en la auditoría con tu nombre.</p>
		<ul class="gm-lista">
			<?php if ( ! $correcciones && ! $revisiones ) : ?>
				<li class="gm-vacio">✔ No hay nada por decidir.</li>
			<?php endif; ?>
			<?php foreach ( $correcciones as $c ) : ?>
				<?php
				$m      = $momento( $c['marcado_utc'] );
				$motivo = Motivos::porClave( (string) $c['motivo_clave'] );
				$pide   = ( 'entrada' === $c['tipo'] ? 'Entrada' : ( $motivo ? $motivo->nombre : 'Salida' ) ) . ' a las ' . $m->format( 'H:i' );
				?>
				<li class="gm-sol">
					<div class="gm-sol-cab">
						<span class="gm-av"><?php echo esc_html( self::iniciales( (string) $c['nombres'], (string) $c['apellidos'] ) ); ?></span>
						<div><b><?php echo esc_html( $c['nombres'] . ' ' . $c['apellidos'] ); ?></b><span>Día <?php echo esc_html( wp_date( 'j M', $m->getTimestamp(), $zona ) ); ?></span></div>
						<span class="pk-chip aviso">Pendiente</span>
					</div>
					<p><strong><?php echo esc_html( $pide ); ?></strong><br><span class="gm-gris"><?php echo esc_html( (string) $c['justificacion'] ); ?></span></p>
					<a class="gm-btn sec" href="<?php echo esc_url( self::url( 'solicitudes', array( 'abrir' => 'c' . (int) $c['id'] ) ) ); ?>">Abrir</a>
				</li>
			<?php endforeach; ?>
			<?php foreach ( $revisiones as $rv ) : ?>
				<?php
				$m      = $momento( $rv['marcado_utc'] );
				$motivo = Motivos::porClave( (string) $rv['motivo_clave'] );
				?>
				<li class="gm-sol">
					<div class="gm-sol-cab">
						<span class="gm-av"><?php echo esc_html( self::iniciales( (string) $rv['nombres'], (string) $rv['apellidos'] ) ); ?></span>
						<div><b><?php echo esc_html( $rv['nombres'] . ' ' . $rv['apellidos'] ); ?></b><span><?php echo esc_html( wp_date( 'j M · H:i', $m->getTimestamp(), $zona ) ); ?></span></div>
						<span class="pk-chip info">Por revisar</span>
					</div>
					<p><strong><?php echo esc_html( $motivo ? $motivo->nombre : (string) $rv['motivo_clave'] ); ?></strong><?php echo '' !== (string) $rv['nota'] ? '<br><span class="gm-gris">' . esc_html( (string) $rv['nota'] ) . '</span>' : ''; ?></p>
					<a class="gm-btn sec" href="<?php echo esc_url( self::url( 'solicitudes', array( 'abrir' => 'r' . (int) $rv['id'] ) ) ); ?>">Abrir</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php

		if ( preg_match( '/^c(\d+)$/', $abrir, $x ) ) {
			$c = array_values( array_filter( $correcciones, static fn( array $f ): bool => (int) $f['id'] === (int) $x[1] ) )[0] ?? null;

			if ( $c ) {
				self::resolverCorreccion( $c, $momento( $c['marcado_utc'] ), $lista );
			}
		} elseif ( preg_match( '/^r(\d+)$/', $abrir, $x ) ) {
			$rv = array_values( array_filter( $revisiones, static fn( array $f ): bool => (int) $f['id'] === (int) $x[1] ) )[0] ?? null;

			if ( $rv ) {
				self::resolverRevision( $rv, $momento( $rv['marcado_utc'] ), $lista );
			}
		}

		return 'Solicitudes';
	}

	/** M4: el día como está, lo que pide y la decisión entre tres opciones. */
	private static function resolverCorreccion( array $c, DateTimeImmutable $m, string $lista ): void {
		$dia    = Ajustes::diaLaboral()->de( $m );
		$pasos  = PasosDelDia::de( Marcas::delDia( (int) $c['trabajador_id'], $dia ) );
		$h      = static fn( ?object $p ): string => $p ? Marcas::horaLocal( $p->marcadoUtc ) : 'Sin marcar';
		$motivo = Motivos::porClave( (string) $c['motivo_clave'] );
		$pide   = ( 'entrada' === $c['tipo'] ? 'Entrada' : ( $motivo ? $motivo->nombre : 'Salida' ) ) . ' ' . $m->format( 'H:i' );
		$propia = Trabajadores::esPropio( (int) $c['trabajador_id'] );

		self::hoja(
			'Solicitud de ' . $c['nombres'] . ' ' . $c['apellidos'],
			'Día ' . wp_date( 'j \d\e F', strtotime( $dia . ' 12:00:00' ) ) . ' · pedida el ' . Ajustes::local( (string) $c['creado_en'], 'j/m' ),
			$lista,
			static function () use ( $c, $m, $pasos, $h, $pide, $propia, $lista ): void {
				?>
				<dl class="gm-dia">
					<div><dt>Entrada</dt><dd><?php echo esc_html( $h( $pasos[ PasosDelDia::ENTRADA ] ) ); ?></dd></div>
					<div><dt>Comida</dt><dd><?php echo esc_html( $pasos[ PasosDelDia::INICIO_COMIDA ] ? $h( $pasos[ PasosDelDia::INICIO_COMIDA ] ) . ' – ' . $h( $pasos[ PasosDelDia::FIN_COMIDA ] ) : 'Sin marcar' ); ?></dd></div>
					<div><dt>Salida</dt><dd><?php echo esc_html( $h( $pasos[ PasosDelDia::SALIDA ] ) ); ?></dd></div>
				</dl>
				<div class="gm-pide"><span>Pide</span><b><?php echo esc_html( $pide ); ?></b><small><?php echo esc_html( (string) $c['justificacion'] ); ?></small></div>
				<?php if ( $propia ) : ?>
					<p class="gm-aviso">Es tu propia solicitud: la decide otra persona con permiso.</p>
					<?php return; ?>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gm-form">
					<input type="hidden" name="action" value="pka_resolver_correccion">
					<input type="hidden" name="correccion" value="<?php echo (int) $c['id']; ?>">
					<?php wp_nonce_field( 'pka_resolver_correccion' ); ?>
					<?php self::volver( $lista ); ?>
					<fieldset>
						<legend>Tu decisión</legend>
						<label class="gm-op"><input type="radio" name="decision" value="aprobar" checked><span><b>Aprobar tal como se pidió</b><small><?php echo esc_html( $pide ); ?></small></span></label>
						<label class="gm-op"><input type="radio" name="decision" value="otra"><span><b>Aprobar con otra hora</b><small>Tú eliges la hora correcta</small><input type="time" name="hora_otra" value="<?php echo esc_attr( $m->format( 'H:i' ) ); ?>" aria-label="Hora correcta"></span></label>
						<label class="gm-op"><input type="radio" name="decision" value="rechazar"><span><b>Rechazar</b><small>El día queda como está</small></span></label>
					</fieldset>
					<label class="gm-campo">Respuesta (obligatoria si rechazas)<textarea name="nota" rows="2" placeholder="Confirmado, gracias por avisar."></textarea></label>
					<div class="gm-fila"><button class="gm-btn">Confirmar</button><a class="gm-btn sec" href="<?php echo esc_url( $lista ); ?>">Cancelar</a></div>
				</form>
				<?php
			}
		);
	}

	/** Marca por revisar (emergencia, salida fuera del taller, jornada larga). */
	private static function resolverRevision( array $rv, DateTimeImmutable $m, string $lista ): void {
		$motivo = Motivos::porClave( (string) $rv['motivo_clave'] );
		$propia = Trabajadores::esPropio( (int) $rv['trabajador_id'] );

		self::hoja(
			'Marca de ' . $rv['nombres'] . ' ' . $rv['apellidos'],
			wp_date( 'l j \d\e F · H:i', $m->getTimestamp(), Ajustes::zona() ),
			$lista,
			static function () use ( $rv, $motivo, $propia, $lista ): void {
				?>
				<div class="gm-pide"><span>Motivo</span><b><?php echo esc_html( $motivo ? $motivo->nombre : (string) $rv['motivo_clave'] ); ?></b><small><?php echo esc_html( (string) $rv['nota'] ); ?></small></div>
				<?php if ( $propia ) : ?>
					<p class="gm-aviso">Es tu propia marca: la revisa otra persona con permiso.</p>
					<?php return; ?>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gm-form">
					<input type="hidden" name="action" value="pka_revisar_marca">
					<input type="hidden" name="marca" value="<?php echo (int) $rv['id']; ?>">
					<?php wp_nonce_field( 'pka_revisar_marca' ); ?>
					<?php self::volver( $lista ); ?>
					<label class="gm-campo">Nota (obligatoria si rechazas)<textarea name="nota" rows="2"></textarea></label>
					<div class="gm-fila"><button class="gm-btn" name="decision" value="<?php echo esc_attr( Revisiones::APROBADA ); ?>">Aprobar</button><button class="gm-btn sec" name="decision" value="<?php echo esc_attr( Revisiones::RECHAZADA ); ?>">Rechazar</button></div>
				</form>
				<?php
			}
		);
	}

	// ---------- M5 / M6 · Pagos ----------

	private static function mesPedido(): string {
		$meses = Exportes::meses();
		$mes   = isset( $_GET['mes'] ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $mes, $meses, true ) ? $mes : ( $meses[1] ?? $meses[0] );
	}

	private static function pagos(): string {
		$mes      = self::mesPedido();
		$datos    = Informes::mes( $mes, null );
		$pagos    = Pagos::delMes( $mes );
		$recibos  = Recibos::delMes( $mes );
		$personas = $datos['personas'];
		$abrir    = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aqui     = self::url( 'pagos', array( 'mes' => $mes ) );
		$listos   = array();
		$incid    = array();
		$sinTar   = array();

		usort( $personas, static fn( $a, $b ) => strcmp( $a['apellidos'] . $a['nombres'], $b['apellidos'] . $b['nombres'] ) );

		foreach ( $personas as $p ) {
			$f = $datos['filas'][ (int) $p['id'] ] ?? new PrePlanilla();

			match ( $f->estado() ) {
				PrePlanilla::LISTO      => $listos[] = $f,
				PrePlanilla::INCIDENCIA => $incid[]  = $p,
				default                 => $sinTar[] = $p,
			};
		}

		$conRecibo = count( array_filter( $recibos, static fn( array $r ): bool => ValidacionRecibo::RECIBIDO === $r['estado'] ) );
		$montoList = array_sum( array_map( static fn( PrePlanilla $f ): int => $f->montos['total'], $listos ) );
		?>
		<nav class="gm-chips" aria-label="Mes">
			<?php foreach ( array_slice( Exportes::meses(), 0, 4 ) as $m ) : ?>
				<a href="<?php echo esc_url( self::url( 'pagos', array( 'mes' => $m ) ) ); ?>" class="<?php echo $m === $mes ? 'on' : ''; ?>"><?php echo esc_html( PantallaResumen::nombreMes( $m ) ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="gm-dest sin-flecha">
			<span>Por pagar de <?php echo esc_html( PantallaResumen::nombreMes( $mes ) ); ?></span>
			<b><?php echo esc_html( Sueldo::soles( $datos['total']->montos['total'] ) ); ?></b>
			<small><?php echo count( $listos ); ?> de <?php echo count( $personas ); ?> listos para pagar</small>
		</div>

		<h2 class="gm-h2">Antes de pagar</h2>
		<ul class="gm-antes">
			<li class="<?php echo $incid ? 'aviso' : 'ok'; ?>"><b>Asistencia</b><span><?php echo $incid ? esc_html( PantallaResumen::plural( count( $incid ), 'persona con días por resolver', 'personas con días por resolver' ) . ': ' . implode( ', ', array_map( static fn( array $p ): string => $p['nombres'] . ' ' . $p['apellidos'], $incid ) ) ) : 'Sin días incompletos ni marcas por revisar'; ?></span></li>
			<li class="<?php echo $sinTar ? 'aviso' : 'ok'; ?>"><b>Tarifas</b><span><?php echo $sinTar ? esc_html( PantallaResumen::plural( count( $sinTar ), 'persona sin honorario', 'personas sin honorario' ) ) : 'Todas tienen sus precios'; ?></span></li>
			<li class="info"><b>Recibos</b><span><?php echo (int) $conRecibo; ?> de <?php echo count( $personas ); ?> · no frenan el pago</span><?php if ( current_user_can( Capacidades::RECIBOS ) ) : ?> <a href="<?php echo esc_url( self::url( 'recibos', array( 'mes' => $mes ) ) ); ?>">Ver</a><?php endif; ?></li>
		</ul>

		<h2 class="gm-h2">Personas</h2>
		<ul class="gm-lista">
			<?php foreach ( $personas as $p ) : ?>
				<?php
				$id     = (int) $p['id'];
				$f      = $datos['filas'][ $id ] ?? new PrePlanilla();
				$estado = $f->estado();
				$r      = $recibos[ $id ] ?? null;
				?>
				<li>
					<a class="gm-persona" href="<?php echo esc_url( self::url( 'pagos', array( 'mes' => $mes, 'p' => $id ) ) ); ?>">
						<span class="gm-av"><?php echo esc_html( self::iniciales( (string) $p['nombres'], (string) $p['apellidos'] ) ); ?></span>
						<div>
							<b><?php echo esc_html( $p['nombres'] . ' ' . $p['apellidos'] ); ?></b>
							<span><?php echo $f->sueldoFinal ? esc_html( Sueldo::soles( $f->montos['total'] ) ) : 'Sin honorario'; ?> · <?php echo esc_html( self::textoRecibo( $r ) ); ?></span>
						</div>
						<?php if ( isset( $pagos[ $id ] ) ) : ?>
							<span class="pk-chip ok">Pagado</span>
						<?php else : ?>
							<span class="pk-chip <?php echo PrePlanilla::LISTO === $estado ? 'ok' : 'error'; ?>"><?php echo esc_html( PrePlanilla::LISTO === $estado ? 'Listo para pagar' : PrePlanilla::ESTADOS[ $estado ] ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $listos ) : ?>
			<a class="gm-btn gm-pegado" href="<?php echo esc_url( Menu::urlAccion( 'pka_planilla_listos', array( 'mes' => $mes, 'solo' => 'listos' ) ) ); ?>">Planilla de las <?php echo count( $listos ); ?> listas · <?php echo esc_html( Sueldo::soles( $montoList ) ); ?></a>
		<?php endif; ?>
		<?php

		if ( $abrir ) {
			$p = array_values( array_filter( $personas, static fn( array $x ): bool => (int) $x['id'] === $abrir ) )[0] ?? null;

			if ( $p ) {
				self::calculo( $p, $datos['filas'][ $abrir ] ?? new PrePlanilla(), $recibos[ $abrir ] ?? null, $pagos[ $abrir ] ?? null, $mes, $aqui );
			}
		}

		return 'Pago de ' . PantallaResumen::nombreMes( $mes );
	}

	private static function textoRecibo( ?array $r ): string {
		if ( ! $r ) {
			return 'Falta recibo';
		}

		return match ( $r['estado'] ) {
			ValidacionRecibo::RECIBIDO  => 'Recibo ✓',
			ValidacionRecibo::ENVIADO   => 'Recibo por revisar',
			ValidacionRecibo::OBSERVADO => 'Recibo observado',
			default                     => (string) $r['estado'],
		};
	}

	/** M6: cómo se calculó, en palabras, y si el recibo coincide. */
	private static function calculo( array $p, PrePlanilla $f, ?array $r, ?array $pago, string $mes, string $aqui ): void {
		self::hoja(
			'Cómo se calculó',
			$p['nombres'] . ' ' . $p['apellidos'] . ' · ' . PantallaResumen::nombreMes( $mes ),
			$aqui,
			static function () use ( $p, $f, $r, $pago, $mes, $aqui ): void {
				$h = static fn( int $min, string $tipo ): string => PrePlanilla::horasDecimal( $min ) . ' h' . ( $min && $f->sueldoFinal ? ' × ' . Sueldo::soles( $f->sueldoFinal->tarifa( $tipo ) ) : '' );
				?>
				<?php if ( ! $f->sueldoFinal ) : ?>
					<p class="gm-aviso">Todavía no tiene honorario: regístralo en Sueldos y tarifas.</p>
				<?php else : ?>
					<ul class="gm-calc">
						<?php if ( $f->porHoras() ) : ?>
							<li><span>Pago por horas<small><?php echo esc_html( PrePlanilla::horasDecimal( $f->minutos['horas'] ) . ' h × ' . Sueldo::soles( $f->sueldoFinal->valorHora() ) ); ?></small></span><b><?php echo esc_html( Sueldo::soles( $f->montos['horas'] ) ); ?></b></li>
						<?php else : ?>
							<li><span>Honorario mensual<small><?php echo esc_html( PantallaResumen::plural( (int) $f->dias['trabajados'], 'día', 'días' ) ); ?> · horas normales incluidas · valor hora <?php echo esc_html( Sueldo::soles( $f->sueldoFinal->valorHora() ) ); ?></small></span><b><?php echo esc_html( Sueldo::soles( $f->montos['honorario'] ) ); ?></b></li>
						<?php endif; ?>
						<li><span>Horas extra<small><?php echo esc_html( $h( $f->minutos['extra'], 'extra' ) ); ?></small></span><b><?php echo esc_html( Sueldo::soles( $f->montos['extra'] ?? 0 ) ); ?></b></li>
						<li><span>Sábados<small><?php echo esc_html( PrePlanilla::horasDecimal( $f->minutos['sabado'] ) . ' h' . ( $f->minutos['sabado_extra'] ? ' · ' . PrePlanilla::horasDecimal( $f->minutos['sabado_extra'] ) . ' h con semana completa' : '' ) ); ?></small></span><b><?php echo esc_html( Sueldo::soles( $f->montos['sabado'] ?? 0 ) ); ?></b></li>
						<li><span>Domingos<small><?php echo esc_html( $h( $f->minutos['domingo'], 'domingo' ) ); ?></small></span><b><?php echo esc_html( Sueldo::soles( $f->montos['domingo'] ?? 0 ) ); ?></b></li>
						<li><span>Feriados<small><?php echo esc_html( $h( $f->minutos['feriado'], 'feriado' ) ); ?></small></span><b><?php echo esc_html( Sueldo::soles( $f->montos['feriado'] ?? 0 ) ); ?></b></li>
						<?php if ( $f->minutos['madrugada'] ) : ?>
							<li><span>Madrugada<small><?php echo esc_html( PrePlanilla::horasDecimal( $f->minutos['madrugada'] ) . ' h' ); ?></small></span><b><?php echo esc_html( Sueldo::soles( $f->montos['madrugada'] ) ); ?></b></li>
						<?php endif; ?>
						<?php if ( $f->montos['descuentos'] ) : ?>
							<li><span>Descuentos</span><b><?php echo esc_html( '− ' . Sueldo::soles( $f->montos['descuentos'] ) ); ?></b></li>
						<?php endif; ?>
						<li class="tot"><span>Total a pagar</span><b><?php echo esc_html( Sueldo::soles( $f->montos['total'] ) ); ?></b></li>
					</ul>
				<?php endif; ?>
				<?php if ( $r && $f->sueldoFinal ) : ?>
					<?php $comp = ValidacionRecibo::textoComparacion( (int) $r['monto_centimos'], $f->montos['total'] ); ?>
					<p class="gm-recibo"><?php echo esc_html( $r['numero'] . ' · ' . Sueldo::soles( (int) $r['monto_centimos'] ) ); ?> <span class="pk-chip <?php echo 'Coincide' === $comp ? 'ok' : 'aviso'; ?>"><?php echo esc_html( $comp ); ?></span></p>
				<?php elseif ( ! $r ) : ?>
					<p class="gm-gris">Su recibo todavía no llega (no frena el pago).</p>
				<?php endif; ?>

				<?php if ( $pago ) : ?>
					<p class="gm-ok">✔ Pagado el <?php echo esc_html( Ajustes::local( (string) $pago['marcado_en'], 'd/m' ) . ' · ' . Sueldo::soles( (int) $pago['total_centimos'] ) ); ?></p>
				<?php elseif ( PrePlanilla::LISTO === $f->estado() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pka_pago_marcar">
						<input type="hidden" name="trabajador" value="<?php echo (int) $p['id']; ?>">
						<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
						<?php wp_nonce_field( 'pka_pago_marcar' ); ?>
						<?php self::volver( $aqui ); ?>
						<button class="gm-btn">Marcar como pagado</button>
					</form>
				<?php else : ?>
					<p class="gm-aviso"><?php echo esc_html( PrePlanilla::ESTADOS[ $f->estado() ] ); ?>: resuélvelo antes de pagar.</p>
				<?php endif; ?>
				<a class="gm-btn sec" href="<?php echo esc_url( self::url( 'persona', array( 't' => (int) $p['id'], 'mes' => $mes ) ) ); ?>">Ver sus días</a>
				<?php
			}
		);
	}

	// ---------- M7 · Recibos ----------

	private static function recibos(): string {
		$meses    = array_slice( Exportes::meses(), 0, 4 );
		$mes      = self::mesPedido();
		$datos    = Informes::mes( $mes, null );
		$recibos  = Recibos::delMes( $mes );
		$personas = $datos['personas'];
		$filtro   = isset( $_GET['f'] ) ? sanitize_key( wp_unslash( $_GET['f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aqui     = self::url( 'recibos', array( 'mes' => $mes, 'f' => $filtro ) );
		$sueldos  = current_user_can( Capacidades::VER_SUELDOS );
		$estadoDe = static fn( int $id ): string => isset( $recibos[ $id ] ) ? (string) $recibos[ $id ]['estado'] : 'falta';
		$cuenta   = array( '' => count( $personas ), 'falta' => 0, ValidacionRecibo::ENVIADO => 0, ValidacionRecibo::OBSERVADO => 0 );

		usort( $personas, static fn( $a, $b ) => strcmp( $a['apellidos'] . $a['nombres'], $b['apellidos'] . $b['nombres'] ) );

		foreach ( $personas as $p ) {
			$e = $estadoDe( (int) $p['id'] );

			if ( isset( $cuenta[ $e ] ) && '' !== $e ) {
				++$cuenta[ $e ];
			}
		}

		$filtros = array( '' => 'Todos', 'falta' => 'Pendientes', ValidacionRecibo::ENVIADO => 'Por revisar', ValidacionRecibo::OBSERVADO => 'Observados' );
		$filtro  = isset( $filtros[ $filtro ] ) ? $filtro : '';
		?>
		<nav class="gm-chips" aria-label="Mes">
			<?php foreach ( $meses as $m ) : ?>
				<a href="<?php echo esc_url( self::url( 'recibos', array( 'mes' => $m ) ) ); ?>" class="<?php echo $m === $mes ? 'on' : ''; ?>"><?php echo esc_html( PantallaResumen::nombreMes( $m ) ); ?></a>
			<?php endforeach; ?>
		</nav>
		<nav class="gm-chips chico" aria-label="Filtrar">
			<?php foreach ( $filtros as $clave => $texto ) : ?>
				<a href="<?php echo esc_url( self::url( 'recibos', array( 'mes' => $mes, 'f' => $clave ) ) ); ?>" class="<?php echo $clave === $filtro ? 'on' : ''; ?>"><?php echo esc_html( $texto ); ?> <b><?php echo (int) $cuenta[ $clave ]; ?></b></a>
			<?php endforeach; ?>
		</nav>

		<ul class="gm-lista">
			<?php
			$hay = false;
			foreach ( $personas as $p ) :
				$id     = (int) $p['id'];
				$r      = $recibos[ $id ] ?? null;
				$estado = $estadoDe( $id );

				if ( '' !== $filtro && $filtro !== $estado ) {
					continue;
				}

				$hay = true;
				$f   = $datos['filas'][ $id ] ?? new PrePlanilla();
				$chip = match ( $estado ) {
					ValidacionRecibo::RECIBIDO  => array( 'ok', 'Recibido' ),
					ValidacionRecibo::ENVIADO   => array( 'info', 'Por revisar' ),
					ValidacionRecibo::OBSERVADO => array( 'aviso', 'Observado' ),
					default                     => array( 'gris', 'Pendiente' ),
				};
				?>
				<li class="gm-sol">
					<div class="gm-sol-cab">
						<span class="gm-av"><?php echo esc_html( self::iniciales( (string) $p['nombres'], (string) $p['apellidos'] ) ); ?></span>
						<div>
							<b><?php echo esc_html( $p['nombres'] . ' ' . $p['apellidos'] ); ?></b>
							<span><?php echo $r ? esc_html( $r['numero'] . ' · ' . Sueldo::soles( (int) $r['monto_centimos'] ) ) : 'Sin enviar'; ?><?php echo $r && $sueldos && $f->sueldoFinal ? ' · ' . esc_html( ValidacionRecibo::textoComparacion( (int) $r['monto_centimos'], $f->montos['total'] ) ) : ''; ?></span>
						</div>
						<span class="pk-chip <?php echo esc_attr( $chip[0] ); ?>"><?php echo esc_html( $chip[1] ); ?></span>
					</div>
					<?php if ( $r && ValidacionRecibo::OBSERVADO === $estado && '' !== (string) $r['comentario'] ) : ?>
						<p class="gm-gris"><?php echo esc_html( (string) $r['comentario'] ); ?></p>
					<?php endif; ?>
					<?php if ( $r ) : ?>
						<a class="gm-enlace" href="<?php echo esc_url( Menu::urlAccion( 'pka_recibo_pdf', array( 'id' => (int) $r['id'] ) ) ); ?>" target="_blank" rel="noopener">Ver PDF</a>
					<?php endif; ?>
					<?php if ( $r && ValidacionRecibo::ENVIADO === $estado && ! Trabajadores::esPropio( $id ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gm-form">
							<input type="hidden" name="action" value="pka_recibo_revisar">
							<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
							<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
							<?php wp_nonce_field( 'pka_recibo_revisar' ); ?>
							<?php self::volver( $aqui ); ?>
							<label class="gm-campo">Si algo está mal, qué hay que corregir<input type="text" name="comentario"></label>
							<div class="gm-fila"><button class="gm-btn" name="decision" value="recibir">Recibido</button><button class="gm-btn sec" name="decision" value="observar">Observar</button></div>
						</form>
					<?php elseif ( ! $r || ValidacionRecibo::OBSERVADO === $estado ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pka_recibo_recordar">
							<input type="hidden" name="trabajador" value="<?php echo (int) $id; ?>">
							<input type="hidden" name="mes" value="<?php echo esc_attr( $mes ); ?>">
							<?php wp_nonce_field( 'pka_recibo_recordar' ); ?>
							<?php self::volver( $aqui ); ?>
							<button class="gm-btn sec chico">Recordar</button>
						</form>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
			<?php if ( ! $hay ) : ?>
				<li class="gm-vacio">No hay recibos en este filtro.</li>
			<?php endif; ?>
		</ul>
		<?php
		return 'Recibos';
	}

	// ---------- M8 · Asistencia de una persona ----------

	private static function persona(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id  = isset( $_GET['t'] ) ? absint( $_GET['t'] ) : 0;
		$mes = isset( $_GET['mes'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $_GET['mes'] ) ? sanitize_text_field( wp_unslash( $_GET['mes'] ) ) : '';
		$dia = isset( $_GET['dia'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['dia'] ) ? sanitize_text_field( wp_unslash( $_GET['dia'] ) ) : '';
		// phpcs:enable
		$t       = $id ? Trabajadores::buscar( $id ) : null;
		$alcance = PantallaHoy::alcance();

		if ( ! $t || ( is_array( $alcance ) && ! in_array( $id, $alcance, true ) ) ) {
			echo '<p class="gm-vacio">Esa persona no está en tu equipo.</p>';
			return 'Asistencia de una persona';
		}

		$zona  = Ajustes::zona();
		$hoy   = wp_date( 'Y-m-d', null, $zona );
		$dia   = '' !== $dia ? $dia : $hoy;
		$mes   = '' !== $mes ? $mes : substr( $dia, 0, 7 );
		$dias  = Informes::diasDe( $t, $mes );
		$fila  = Jornadas::prePlanilla( array( $t ), $mes )[ $id ] ?? new PrePlanilla();
		$ini   = new DateTimeImmutable( $mes . '-01' );
		$antes = $ini->modify( '-1 month' )->format( 'Y-m' );
		$luego = $ini->modify( '+1 month' )->format( 'Y-m' );
		$pasos = PasosDelDia::de( Marcas::delDia( $id, $dia ) );
		$j     = $dias[ $dia ] ?? null;
		$h     = static fn( ?object $p ): string => $p ? Marcas::horaLocal( $p->marcadoUtc ) : '—';
		?>
		<p class="gm-sub"><?php echo esc_html( trim( (string) $t['cargo'] . ' · ' . (string) $t['area'], ' ·' ) ); ?></p>

		<div class="gm-kpis tres">
			<div class="gm-kpi"><b><?php echo (int) $fila->dias['trabajados']; ?></b><span>días</span></div>
			<div class="gm-kpi"><b><?php echo esc_html( PrePlanilla::horasDecimal( $fila->minutos['trabajados'] ) ); ?> h</b><span>trabajadas</span></div>
			<div class="gm-kpi"><b><?php echo esc_html( PrePlanilla::horasDecimal( $fila->minutosAdicionales() ) ); ?> h</b><span>extra</span></div>
		</div>

		<div class="gm-caja">
			<div class="gm-mes">
				<a href="<?php echo esc_url( self::url( 'persona', array( 't' => $id, 'mes' => $antes ) ) ); ?>" aria-label="Mes anterior">‹</a>
				<b><?php echo esc_html( ucfirst( PantallaResumen::nombreMes( $mes ) ) ); ?></b>
				<a href="<?php echo esc_url( self::url( 'persona', array( 't' => $id, 'mes' => $luego ) ) ); ?>" aria-label="Mes siguiente">›</a>
			</div>
			<div class="pk-cal" role="list" aria-label="Calendario del mes">
				<?php
				foreach ( array( 'L', 'M', 'M', 'J', 'V', 'S', 'D' ) as $cab ) {
					echo '<span class="pk-cal-cab" aria-hidden="true">' . esc_html( $cab ) . '</span>';
				}

				for ( $i = 1; $i < (int) $ini->format( 'N' ); $i++ ) {
					echo '<span class="pk-cal-vacio" aria-hidden="true"></span>';
				}

				for ( $d = 1; $d <= (int) $ini->format( 't' ); $d++ ) {
					$fecha = $ini->format( 'Y-m-' ) . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
					$clave = $fecha === $hoy ? 'hoy' : Informes::estadoVisual( $dias[ $fecha ] ?? null );
					printf(
						'<a class="pk-cal-dia pk-est-%s%s" role="listitem" href="%s" aria-label="%s"><strong>%d</strong></a>',
						esc_attr( $clave ),
						$fecha === $dia ? ' gm-elegido' : '',
						esc_url( self::url( 'persona', array( 't' => $id, 'mes' => $mes, 'dia' => $fecha ) ) ),
						esc_attr( wp_date( 'j \d\e F', strtotime( $fecha . ' 12:00:00' ) ) . ': ' . Informes::COLORES[ 'hoy' === $clave ? 'pendiente' : $clave ][0] ),
						(int) $d
					);
				}
				?>
			</div>
		</div>

		<div class="gm-caja">
			<h2 class="gm-h2"><?php echo esc_html( ucfirst( wp_date( 'l j \d\e F', strtotime( $dia . ' 12:00:00' ) ) ) ); ?></h2>
			<dl class="gm-dia cuatro">
				<?php foreach ( PasosDelDia::NOMBRES as $paso => $nombre ) : ?>
					<div><dt><?php echo esc_html( $nombre ); ?></dt><dd><?php echo esc_html( $h( $pasos[ $paso ] ) ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
			<?php if ( $j && $j->minutosTrabajados ) : ?>
				<p class="gm-total"><?php echo esc_html( PrePlanilla::horas( $j->minutosTrabajados ) ); ?> h trabajadas<?php echo $j->minutosAdicionales() ? ' · ' . esc_html( PrePlanilla::horas( $j->minutosAdicionales() ) ) . ' h de extra' : ''; ?><?php echo $j->minutosTardanza ? ' · +' . (int) $j->minutosTardanza . ' min tarde' : ''; ?></p>
			<?php elseif ( $j && '' !== $j->detalle ) : ?>
				<p class="gm-total"><?php echo esc_html( $j->detalle ); ?></p>
			<?php endif; ?>
			<?php if ( current_user_can( Capacidades::MARCA_MANUAL ) && ! Trabajadores::esPropio( $id ) && $dia <= $hoy ) : ?>
				<a class="gm-btn sec" href="<?php echo esc_url( PantallaRegistros::url( array( 'persona' => $id, 'desde' => $dia, 'hasta' => $dia, 'editar' => $id, 'dia' => $dia ) ) ); ?>">Editar este día</a>
			<?php endif; ?>
		</div>
		<?php
		return Trabajadores::nombreCompleto( $t );
	}

	// ---------- M9 · Más ----------

	private static function mas(): string {
		$a      = static fn( string $slug ): string => admin_url( 'admin.php?page=' . $slug );
		$grupos = array(
			'Control'  => array(
				array( 'Registros y revisión', $a( 'pka-registros' ), Capacidades::VER_EQUIPO ),
				array( 'Horarios', $a( 'pka-horarios' ), Capacidades::HORARIOS ),
				array( 'Feriados', $a( 'pka-calendario' ), Capacidades::HORARIOS ),
				array( 'Ausencias', $a( 'pka-ausencias' ), Capacidades::GESTIONAR_PERSONAL ),
				array( 'Auditoría', $a( 'pka-historial' ), Capacidades::AUDITORIA ),
			),
			'Pagos'    => array(
				array( 'Recibos por honorarios', self::url( 'recibos' ), Capacidades::RECIBOS ),
				array( 'Sueldos y tarifas', $a( 'pka-sueldos' ), Capacidades::VER_SUELDOS ),
			),
			'Personas' => array(
				array( 'Perfiles', $a( 'pka-trabajadores' ), Capacidades::GESTIONAR_PERSONAL ),
				array( 'Celulares y passkeys', $a( 'pka-celulares' ), Capacidades::GESTIONAR_PERSONAL ),
				array( 'Delegar permisos', $a( 'pka-delegar' ), Capacidades::AJUSTES ),
			),
			'Sistema'  => array(
				array( 'Avisos de campaña', PantallaAjustes::url( 'campana' ), Capacidades::AJUSTES ),
				array( 'Seguridad y reglas', PantallaAjustes::url(), Capacidades::AJUSTES ),
				array( 'Informes', $a( 'pka-exportar' ), Capacidades::EXPORTAR ),
				array( 'Entrar con huella o rostro', admin_url( 'profile.php#pk-llaves' ), Capacidades::VER_EQUIPO ),
				array( 'Versión de computadora', $a( 'pka-dashboard' ), Capacidades::VER_EQUIPO ),
			),
		);

		foreach ( $grupos as $titulo => $enlaces ) {
			$enlaces = array_filter( $enlaces, static fn( array $e ): bool => current_user_can( $e[2] ) );

			if ( ! $enlaces ) {
				continue;
			}

			echo '<h2 class="gm-h2">' . esc_html( $titulo ) . '</h2><ul class="gm-menu">';

			foreach ( $enlaces as [ $texto, $url ] ) {
				echo '<li><a href="' . esc_url( $url ) . '"><span>' . esc_html( $texto ) . '</span>' . self::icono( 'flecha' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</ul>';
		}

		echo '<a class="gm-btn sec gm-mt" href="' . esc_url( wp_logout_url( wp_login_url( self::url() ) ) ) . '">Cerrar sesión</a>';

		return 'Más';
	}
}
