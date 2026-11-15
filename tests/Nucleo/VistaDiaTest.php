<?php

namespace Pekokis\Asistencia\Pruebas\Nucleo;

use DateTimeImmutable;
use DateTimeZone;
use Pekokis\Asistencia\Nucleo\HorarioDelDia;
use Pekokis\Asistencia\Nucleo\VistaDia;
use PHPUnit\Framework\TestCase;

/**
 * Pantalla de marcar (T2): pasos de "Tu día", botón principal y horas.
 */
final class VistaDiaTest extends TestCase {
	private const DIA = '2027-01-15';

	private function vista( array $marcas, string $ahora ): array {
		$zona = new DateTimeZone( 'America/Lima' );

		return VistaDia::de( $marcas, new HorarioDelDia( true, '09:00', '19:30', 5, 60 ), self::DIA, $zona, new DateTimeImmutable( self::DIA . ' ' . $ahora, $zona ) );
	}

	private function h( string $hora ): string {
		return self::DIA . ' ' . $hora;
	}

	private function estados( array $v ): array {
		return array_column( $v['pasos'], 'estado', 'clave' );
	}

	public function test_antes_de_entrar(): void {
		$v = $this->vista( array(), '08:50' );

		$this->assertSame( 'entrada', $v['siguiente']['accion'] );
		$this->assertSame( 'Marcar entrada', $v['siguiente']['etiqueta'] );
		$this->assertSame( array( 'entrada' => 'sig', 'inicio_comida' => 'pend', 'fin_comida' => 'pend', 'salida' => 'pend' ), $this->estados( $v ) );
		$this->assertSame( 570, $v['esperados'], '9 h 30 de jornada' );
	}

	public function test_jornada_en_curso(): void {
		$v = $this->vista( array( Fabrica::entrada( $this->h( '09:02' ) ) ), '12:40' );

		$this->assertSame( 'salida_refrigerio', $v['siguiente']['accion'] );
		$this->assertSame( 'Salir a comer', $v['siguiente']['etiqueta'] );
		$this->assertSame( array( 'fin_jornada', 'salida_temporal' ), array_column( $v['otras'], 'accion' ) );
		$this->assertSame( 218, $v['trabajado'], '3 h 38 min' );
		$this->assertSame( 352, $v['faltan'], 'Te faltan 5 h 52 min' );
		$this->assertSame( '09:02', $v['pasos'][0]['hora'] );
		$this->assertSame( 'sig', $this->estados( $v )['inicio_comida'] );
		$this->assertSame( '3 h 38 min', VistaDia::duracion( 218 ) );
	}

	public function test_en_comida_y_de_vuelta(): void {
		$comiendo = $this->vista( array( Fabrica::entrada( $this->h( '09:02' ) ), Fabrica::salida( $this->h( '13:05' ), 'refrigerio' ) ), '13:27' );

		$this->assertSame( 'Volver de comer', $comiendo['siguiente']['etiqueta'] );
		$this->assertSame( array( 'desde' => '13:05', 'minutos' => 22 ), $comiendo['comida'] );
		$this->assertSame( 'sig', $this->estados( $comiendo )['fin_comida'] );

		$vuelta = $this->vista( array( Fabrica::entrada( $this->h( '09:02' ) ), Fabrica::salida( $this->h( '13:05' ), 'refrigerio' ), Fabrica::entrada( $this->h( '14:03' ) ) ), '15:00' );
		$this->assertSame( 'fin_jornada', $vuelta['siguiente']['accion'] );
		$this->assertSame( '14:03', $vuelta['pasos'][2]['hora'] );
		$this->assertSame( array( 'salida_temporal' ), array_column( $vuelta['otras'], 'accion' ) );
	}

	public function test_jornada_terminada(): void {
		$v = $this->vista(
			array(
				Fabrica::entrada( $this->h( '09:02' ) ),
				Fabrica::salida( $this->h( '13:05' ), 'refrigerio' ),
				Fabrica::entrada( $this->h( '14:03' ) ),
				Fabrica::salida( $this->h( '19:34' ), 'fin_jornada' ),
			),
			'19:40'
		);

		$this->assertTrue( $v['terminado'] );
		$this->assertNull( $v['siguiente'], 'Sin botón de marcar' );
		$this->assertSame( 574, $v['trabajado'], '9 h 34 min (la maqueta dice 9 h 26, pero sus horas dan 9 h 34)' );
		$this->assertSame( array( 'entrada' => 'hecho', 'inicio_comida' => 'hecho', 'fin_comida' => 'hecho', 'salida' => 'hecho' ), $this->estados( $v ) );
	}

	public function test_salida_temporal(): void {
		$v = $this->vista( array( Fabrica::entrada( $this->h( '09:00' ) ), Fabrica::salida( $this->h( '11:00' ), 'encargo' ) ), '11:20' );

		$this->assertSame( 'regreso', $v['siguiente']['accion'] );
		$this->assertSame( 'Volver al taller', $v['siguiente']['etiqueta'] );
		$this->assertSame( array( 'desde' => '11:00', 'minutos' => 20, 'motivo' => 'Encargo de Pekokis' ), $v['fuera'] );
	}

	public function test_sin_comida_la_salida_deja_la_comida_sin_marcar(): void {
		$v = $this->vista( array( Fabrica::entrada( $this->h( '09:00' ) ), Fabrica::salida( $this->h( '18:00' ), 'fin_jornada' ) ), '18:05' );

		$this->assertTrue( $v['terminado'] );
		$this->assertSame( 'omitido', $this->estados( $v )['inicio_comida'] );
		$this->assertSame( 'Volví a trabajar: marcar entrada', $v['otras'][0]['etiqueta'] );
	}
}
