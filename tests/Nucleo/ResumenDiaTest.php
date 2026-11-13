<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeZone;
use Pekokis\Asistencia\Nucleo\Estado;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\ResumenDia;
use PHPUnit\Framework\TestCase;

final class ResumenDiaTest extends TestCase {
	private const DIA = '2026-11-13';

	private function horario(): HorarioDelDia {
		return new HorarioDelDia( true, '09:00:00', '19:30:00', 5, 60 );
	}

	private function resumen( array $marcas, string $ahoraLima, ?HorarioDelDia $horario = null ): ResumenDia {
		return ResumenDia::calcular( $marcas, $horario ?? $this->horario(), self::DIA, new DateTimeZone( 'America/Lima' ), Fabrica::lima( self::DIA . ' ' . $ahoraLima ) );
	}

	public function test_sin_marcas_antes_del_limite_esta_por_llegar(): void {
		$this->assertSame( ResumenDia::POR_LLEGAR, $this->resumen( array(), '09:04' )->situacion );
	}

	public function test_sin_marcas_pasada_la_tolerancia_esta_ausente(): void {
		$this->assertSame( ResumenDia::AUSENTE, $this->resumen( array(), '09:06' )->situacion );
	}

	public function test_dia_no_laborable_sin_marcas_es_libre(): void {
		$this->assertSame( ResumenDia::LIBRE, $this->resumen( array(), '11:00', HorarioDelDia::libre() )->situacion );
	}

	public function test_llego_dentro_de_la_tolerancia(): void {
		$r = $this->resumen( array( Fabrica::entrada( self::DIA . ' 09:05' ) ), '10:00' );

		$this->assertSame( ResumenDia::PRESENTE, $r->situacion );
		$this->assertSame( 0, $r->minutosTardanza );
		$this->assertSame( 55, $r->minutosEnEstado );
	}

	public function test_tardanza_se_cuenta_desde_la_hora_de_entrada(): void {
		$r = $this->resumen( array( Fabrica::entrada( self::DIA . ' 09:12' ) ), '10:00' );

		$this->assertSame( ResumenDia::TARDE, $r->situacion );
		$this->assertSame( 12, $r->minutosTardanza );
	}

	public function test_refrigerio_excedido(): void {
		$marcas = array( Fabrica::entrada( self::DIA . ' 08:58' ), Fabrica::salida( self::DIA . ' 13:00', 'refrigerio' ) );

		$this->assertFalse( $this->resumen( $marcas, '13:59' )->excedeRefrigerio );

		$r = $this->resumen( $marcas, '14:10' );
		$this->assertSame( ResumenDia::REFRIGERIO, $r->situacion );
		$this->assertTrue( $r->excedeRefrigerio );
		$this->assertSame( 70, $r->minutosEnEstado );
	}

	public function test_fuera_temporal_muestra_el_motivo(): void {
		$r = $this->resumen( array( Fabrica::entrada( self::DIA . ' 09:00' ), Fabrica::salida( self::DIA . ' 11:20', 'emergencia' ) ), '12:00' );

		$this->assertSame( ResumenDia::FUERA, $r->situacion );
		$this->assertSame( 'emergencia', $r->motivoActual?->clave );
	}

	public function test_termino_la_jornada(): void {
		$r = $this->resumen( array( Fabrica::entrada( self::DIA . ' 09:00' ), Fabrica::salida( self::DIA . ' 19:31', 'fin_jornada' ) ), '19:40' );

		$this->assertSame( ResumenDia::SALIO, $r->situacion );
		$this->assertSame( Estado::FUERA, $r->estado );
	}

	public function test_la_tardanza_es_de_la_primera_entrada_aunque_vuelva_tarde_del_refrigerio(): void {
		$marcas = array(
			Fabrica::entrada( self::DIA . ' 08:59' ),
			Fabrica::salida( self::DIA . ' 13:00', 'refrigerio' ),
			Fabrica::entrada( self::DIA . ' 14:30' ),
		);

		$this->assertSame( ResumenDia::PRESENTE, $this->resumen( $marcas, '15:00' )->situacion );
	}
}
