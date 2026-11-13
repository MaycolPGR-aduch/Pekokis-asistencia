<?php
/**
 * Arma el zip del plugin listo para subir a WordPress (Plugins → Añadir nuevo → Subir plugin):
 * archivos del repositorio + librerías de producción (composer install --no-dev),
 * sin pruebas, documentación ni herramientas de desarrollo.
 *
 * Uso, desde la carpeta del proyecto:
 *   php scripts/empaquetar.php
 * Resultado: dist/pekokis-asistencia-<versión>.zip
 */

$raiz = dirname( __DIR__ );
chdir( $raiz );

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "Falta la extensión zip de PHP.\n" );
	exit( 1 );
}

// Solo lo que está en git, para no colar archivos locales (.env.local, vendor de desarrollo…).
exec( 'git ls-files', $archivos, $codigo );

if ( 0 !== $codigo ) {
	fwrite( STDERR, "No se pudo listar los archivos con git.\n" );
	exit( 1 );
}

$excluir = array( 'tests/', 'docs/', 'scripts/', 'phpunit.xml.dist', 'TAREAS.md', '.gitignore', '.gitattributes' );
$archivos = array_values(
	array_filter(
		$archivos,
		static function ( string $archivo ) use ( $excluir ): bool {
			foreach ( $excluir as $prefijo ) {
				if ( str_starts_with( $archivo, $prefijo ) ) {
					return false;
				}
			}
			return true;
		}
	)
);

preg_match( "/define\( 'PKA_VERSION', '([^']+)' \)/", (string) file_get_contents( 'pekokis-asistencia.php' ), $m );
$version = $m[1] ?? 'dev';

$build = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pka-build-' . bin2hex( random_bytes( 4 ) );
$plugin = $build . DIRECTORY_SEPARATOR . 'pekokis-asistencia';

foreach ( $archivos as $archivo ) {
	$destino = $plugin . DIRECTORY_SEPARATOR . $archivo;
	if ( ! is_dir( dirname( $destino ) ) ) {
		mkdir( dirname( $destino ), 0777, true );
	}
	copy( $raiz . DIRECTORY_SEPARATOR . $archivo, $destino );
}

echo "Instalando librerías de producción…\n";
passthru( 'composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet --working-dir=' . escapeshellarg( $plugin ), $codigo );

if ( 0 !== $codigo || ! is_file( $plugin . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Falló composer install.\n" );
	exit( 1 );
}

// composer.json y composer.lock no hacen falta en el servidor.
unlink( $plugin . '/composer.json' );
unlink( $plugin . '/composer.lock' );

if ( ! is_dir( $raiz . '/dist' ) ) {
	mkdir( $raiz . '/dist' );
}

$zipRuta = $raiz . '/dist/pekokis-asistencia-' . $version . '.zip';
@unlink( $zipRuta );

$zip = new ZipArchive();
$zip->open( $zipRuta, ZipArchive::CREATE );
$total = 0;

$iterador = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $build, FilesystemIterator::SKIP_DOTS ) );

foreach ( $iterador as $archivo ) {
	$relativo = str_replace( '\\', '/', substr( $archivo->getPathname(), strlen( $build ) + 1 ) );

	// Documentación y pruebas de las librerías: no hacen falta en producción.
	if ( preg_match( '#^pekokis-asistencia/vendor/[^/]+/[^/]+/(tests?|docs?|examples?)/#i', $relativo ) ) {
		continue;
	}

	$zip->addFile( $archivo->getPathname(), $relativo );
	++$total;
}

$zip->close();

// Limpieza del directorio temporal.
$borrar = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $build, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $borrar as $item ) {
	$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
}
rmdir( $build );

printf( "Listo: %s (%d archivos, %s KB)\nSHA-256: %s\n", $zipRuta, $total, number_format( filesize( $zipRuta ) / 1024, 0 ), hash_file( 'sha256', $zipRuta ) );
