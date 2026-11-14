<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\ReglasAvisos;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use PHPUnit\Framework\TestCase;

final class ReglasAvisosTest extends TestCase {
	private const DIA = '2026-11-16';

	private function zona(): DateTimeZone {
		return new DateTimeZone( 'America/Lima' );
	}

	private function horario(): HorarioDelDia {
		return new HorarioDelDia( true, '09:00', '19:30', 5, 60 );
	}

	/** Avisos para estas marcas a esta hora de Lima. */
	private function avisos( array $marcas, string $hora, ?HorarioDelDia $h = null, ?ReglasAvisos $reglas = null ): array {
		$h     = $h ?? $this->horario();
		$ahora = new DateTimeImmutable( self::DIA . ' ' . $hora, $this->zona() );
		$r     = ResumenDia::calcular( $marcas, $h, self::DIA, $this->zona(), $ahora );

		return ( $reglas ?? new ReglasAvisos() )->evaluar( $r, $h, self::DIA, $this->zona(), $ahora );
	}

	private function h( string $hora ): string {
		return self::DIA . ' ' . $hora;
	}

	public function test_ausente_despues_de_tolerancia_y_margen(): void {
		$this->assertSame( array(), $this->avisos( array(), '09:14' ), '9:00 + 5 de tolerancia + 10 de margen' );
		$this->assertSame( array( ReglasAvisos::AUSENTE => 'ausente|' . self::DIA ), $this->avisos( array(), '09:15' ) );
		$this->assertSame( array(), $this->avisos( array( Fabrica::entrada( $this->h( '09:12' ) ) ), '09:20' ) );
		$this->assertSame( array(), $this->avisos( array(), '11:00', HorarioDelDia::libre( 'Feriado' ) ), 'En un feriado nadie falta' );
	}

	public function test_refrigerio_que_se_alarga(): void {
		$marcas = array( Fabrica::entrada( $this->h( '09:00' ) ), Fabrica::salida( $this->h( '13:00' ), 'refrigerio' ) );

		$this->assertSame( array(), $this->avisos( $marcas, '14:10' ), '60 de refrigerio + 10 de margen' );
		$avisos = $this->avisos( $marcas, '14:11' );
		$this->assertArrayHasKey( ReglasAvisos::REFRIGERIO_LARGO, $avisos );
		$this->assertSame( 'refrigerio_largo|' . Fabrica::lima( $this->h( '13:00' ) )->getTimestamp(), $avisos[ ReglasAvisos::REFRIGERIO_LARGO ], 'La clave es la salida: otro refrigerio sería otro aviso' );
	}

	public function test_salida_temporal_que_se_alarga(): void {
		$marcas = array( Fabrica::entrada( $this->h( '09:00' ) ), Fabrica::salida( $this->h( '10:00' ), 'salud' ) );

		$this->assertSame( array(), $this->avisos( $marcas, '12:00' ) );
		$this->assertArrayHasKey( ReglasAvisos::SALIDA_LARGA, $this->avisos( $marcas, '12:01' ) );
		$this->assertArrayHasKey( ReglasAvisos::SALIDA_LARGA, $this->avisos( $marcas, '11:01', reglas: new ReglasAvisos( limiteSalidaMin: 60 ) ) );
	}

	public function test_sin_salida_a_la_hora_limite(): void {
		$trabajando = array( Fabrica::entrada( $this->h( '09:00' ) ) );
		$termino    = array( Fabrica::entrada( $this->h( '09:00' ) ), Fabrica::salida( $this->h( '19:30' ), 'fin_jornada' ) );

		$this->assertSame( array(), $this->avisos( $trabajando, '19:59' ) );
		$this->assertSame( array( ReglasAvisos::SIN_SALIDA => 'sin_salida|' . self::DIA ), $this->avisos( $trabajando, '20:00' ) );
		$this->assertSame( array(), $this->avisos( $termino, '21:00' ) );
	}

	public function test_un_turno_largo_corre_la_hora_limite(): void {
		$tarde  = new HorarioDelDia( true, '14:00', '22:00', 5, 60 );
		$reglas = new ReglasAvisos();

		$this->assertSame( '22:30', $reglas->limiteSinSalida( $tarde, self::DIA, $this->zona() )->format( 'H:i' ) );
		$this->assertSame( '20:00', $reglas->limiteSinSalida( $this->horario(), self::DIA, $this->zona() )->format( 'H:i' ) );
		$this->assertSame( array(), $this->avisos( array( Fabrica::entrada( $this->h( '14:00' ) ) ), '21:00', $tarde ) );
	}

	public function test_ajustes_y_textos(): void {
		$r = ReglasAvisos::desdeAjustes( array( 'aviso_hora_sin_salida' => '25:00', 'aviso_limite_salida' => 5, 'aviso_margen_ausencia' => 20 ) );

		$this->assertSame( '20:00', $r->horaSinSalida, 'Una hora inválida vuelve a la de siempre' );
		$this->assertSame( 15, $r->limiteSalidaMin, 'Mínimo 15 minutos' );
		$this->assertSame( 20, $r->margenAusenciaMin );
		$this->assertSame( ReglasAvisos::PARA_JEFE, ReglasAvisos::destinatario( ReglasAvisos::AUSENTE ) );
		$this->assertSame( ReglasAvisos::PARA_TRABAJADOR, ReglasAvisos::destinatario( ReglasAvisos::SIN_SALIDA ) );
		$this->assertSame( 'No llegaron a su hora', ReglasAvisos::titulo( ReglasAvisos::AUSENTE, 3 ) );
	}
}
